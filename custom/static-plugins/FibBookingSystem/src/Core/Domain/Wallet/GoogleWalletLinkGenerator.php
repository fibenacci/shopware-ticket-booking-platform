<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Wallet;

use JsonException;
use RuntimeException;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Generates "Save to Google Wallet" links.
 *
 * The link embeds an RS256-signed JWT (service-account key) that contains the
 * event ticket class and object inline — no Wallet API round-trip is needed at
 * download time, and nothing about the ticket is sent to Google until the user
 * actually clicks the link.
 *
 * Configuration (plugin config / admin):
 * - service account JSON (the full key file content)
 * - issuer id
 */
class GoogleWalletLinkGenerator
{
    private const CONFIG_PREFIX = 'FibBookingSystem.config.';
    private const SAVE_URL = 'https://pay.google.com/gp/v/save/';

    public function __construct(private readonly SystemConfigService $systemConfig)
    {
    }

    public function isConfigured(): bool
    {
        return $this->getIssuerId() !== '' && $this->getServiceAccount() !== null;
    }

    public function generateSaveLink(TicketWalletData $data): string
    {
        $serviceAccount = $this->getServiceAccount();
        $issuerId = $this->getIssuerId();

        if ($serviceAccount === null || $issuerId === '') {
            throw new RuntimeException('Google Wallet is not configured.');
        }

        $classId = sprintf('%s.fib_booking_event', $issuerId);
        $objectId = sprintf('%s.%s', $issuerId, preg_replace('/[^A-Za-z0-9_-]/', '_', $data->ticketNumber));
        $organization = $this->getString('walletOrganizationName') ?: 'FIB Booking';

        $claims = [
            'iss' => (string) $serviceAccount['client_email'],
            'aud' => 'google',
            'typ' => 'savetowallet',
            'iat' => time(),
            'origins' => [],
            'payload' => [
                'eventTicketClasses' => [
                    [
                        'id' => $classId,
                        'issuerName' => $organization,
                        'reviewStatus' => 'UNDER_REVIEW',
                        'eventName' => [
                            'defaultValue' => ['language' => 'en-US', 'value' => $data->resourceName],
                        ],
                    ],
                ],
                'eventTicketObjects' => [
                    [
                        'id' => $objectId,
                        'classId' => $classId,
                        'state' => 'ACTIVE',
                        'ticketNumber' => $data->ticketNumber,
                        'barcode' => [
                            'type' => 'QR_CODE',
                            'value' => $data->qrPayload,
                        ],
                        'eventName' => [
                            'defaultValue' => ['language' => 'en-US', 'value' => $data->resourceName],
                        ],
                        'validTimeInterval' => [
                            'start' => ['date' => $data->startsAt->format('c')],
                            'end' => ['date' => $data->endsAt->modify('+1 day')->format('c')],
                        ],
                        'textModulesData' => [
                            ['header' => 'Booking', 'body' => $data->bookingNumber, 'id' => 'booking'],
                            ['header' => 'Guests', 'body' => (string) $data->quantity, 'id' => 'guests'],
                        ],
                    ],
                ],
            ],
        ];

        return self::SAVE_URL . $this->signJwt($claims, (string) $serviceAccount['private_key']);
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function signJwt(array $claims, string $privateKeyPem): string
    {
        $segments = [
            $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR)),
            $this->base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        ];

        $signingInput = implode('.', $segments);
        $signature = '';

        $key = openssl_pkey_get_private($privateKeyPem);
        if ($key === false || !openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Signing the Google Wallet JWT failed.');
        }

        $segments[] = $this->base64UrlEncode($signature);

        return implode('.', $segments);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getServiceAccount(): ?array
    {
        $raw = $this->getString('googleWalletServiceAccountJson');

        if ($raw === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($decoded) || !is_string($decoded['client_email'] ?? null) || !is_string($decoded['private_key'] ?? null)) {
            return null;
        }

        return $decoded;
    }

    private function getIssuerId(): string
    {
        return $this->getString('googleWalletIssuerId');
    }

    private function getString(string $key): string
    {
        $value = $this->systemConfig->get(self::CONFIG_PREFIX . $key);

        return is_string($value) ? trim($value) : '';
    }
}
