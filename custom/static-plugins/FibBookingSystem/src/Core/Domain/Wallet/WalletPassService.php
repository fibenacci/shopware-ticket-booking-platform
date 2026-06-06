<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Wallet;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use FibBookingSystem\Core\Domain\Security\TokenCipher;
use RuntimeException;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Facade for wallet pass generation: loads ticket data (rebuilding the QR
 * payload from the encrypted scan token) and delegates to the providers.
 */
class WalletPassService
{
    public const PROVIDER_APPLE = 'apple';
    public const PROVIDER_GOOGLE = 'google';
    public const DEFAULT_LINK_TTL_DAYS = 90;

    public function __construct(
        private readonly Connection $connection,
        private readonly TokenCipher $tokenCipher,
        private readonly AppleWalletPassGenerator $appleGenerator,
        private readonly GoogleWalletLinkGenerator $googleGenerator,
        private readonly WalletLinkSigner $linkSigner,
    ) {
    }

    /**
     * Signed query parameters for a wallet download URL (used in mails and the
     * account area). The link is valid for $ttlDays.
     *
     * @return array{exp: int, sig: string}
     */
    public function createSignedParams(string $provider, string $ticketId, int $ttlDays = self::DEFAULT_LINK_TTL_DAYS): array
    {
        $expiresAt = time() + ($ttlDays * 86400);

        return [
            'exp' => $expiresAt,
            'sig' => $this->linkSigner->sign($provider, $ticketId, $expiresAt),
        ];
    }

    public function verifySignature(string $provider, string $ticketId, int $expiresAt, string $signature): bool
    {
        return $this->linkSigner->verify($provider, $ticketId, $expiresAt, $signature);
    }

    public function isAppleAvailable(): bool
    {
        return $this->appleGenerator->isConfigured();
    }

    public function isGoogleAvailable(): bool
    {
        return $this->googleGenerator->isConfigured();
    }

    public function generateApplePass(TicketWalletData $data): string
    {
        return $this->appleGenerator->generate($data);
    }

    public function generateGoogleSaveLink(TicketWalletData $data): string
    {
        return $this->googleGenerator->generateSaveLink($data);
    }

    /**
     * Loads everything a wallet pass needs. Returns null when the ticket does
     * not exist, is not in a pass-worthy state, or predates wallet support
     * (no encrypted token stored).
     */
    public function loadTicketData(string $ticketId): ?TicketWalletData
    {
        if (!Uuid::isValid($ticketId)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT LOWER(HEX(ticket.id)) AS ticket_id, ticket.ticket_number, ticket.status, ticket.scan_token_cipher,
                reservation.booking_number, reservation.starts_at, reservation.ends_at, reservation.quantity,
                reservation.customer_id, resource.name AS resource_name,
                customer.first_name, customer.last_name
                FROM fib_booking_ticket ticket
                INNER JOIN fib_booking_reservation reservation ON reservation.id = ticket.reservation_id
                INNER JOIN fib_booking_resource resource ON resource.id = reservation.resource_id
                LEFT JOIN customer ON customer.id = reservation.customer_id
                WHERE ticket.id = :ticketId
            SQL,
            ['ticketId' => Uuid::fromHexToBytes($ticketId)],
        );

        if ($row === false || !is_string($row['scan_token_cipher']) || $row['scan_token_cipher'] === '') {
            return null;
        }

        if (!in_array((string) $row['status'], ['issued', 'sent', 'scanned'], true)) {
            return null;
        }

        try {
            $scanToken = $this->tokenCipher->decrypt($row['scan_token_cipher']);
        } catch (RuntimeException) {
            return null;
        }

        $qrPayload = json_encode([
            'type' => 'fib_booking_ticket',
            'ticketNumber' => (string) $row['ticket_number'],
            'scanToken' => $scanToken,
        ], JSON_THROW_ON_ERROR);

        $customerName = trim(sprintf('%s %s', (string) ($row['first_name'] ?? ''), (string) ($row['last_name'] ?? '')));

        return new TicketWalletData(
            ticketId: (string) $row['ticket_id'],
            ticketNumber: (string) $row['ticket_number'],
            bookingNumber: (string) $row['booking_number'],
            status: (string) $row['status'],
            qrPayload: $qrPayload,
            resourceName: (string) $row['resource_name'],
            startsAt: new DateTimeImmutable((string) $row['starts_at']),
            endsAt: new DateTimeImmutable((string) $row['ends_at']),
            quantity: (int) $row['quantity'],
            customerName: $customerName === '' ? null : $customerName,
        );
    }

    /**
     * Whether the given customer owns the ticket — used by the account area.
     */
    public function isOwnedByCustomer(string $ticketId, string $customerId): bool
    {
        if (!Uuid::isValid($ticketId) || !Uuid::isValid($customerId)) {
            return false;
        }

        return (bool) $this->connection->fetchOne(
            <<<'SQL'
                SELECT 1
                FROM fib_booking_ticket ticket
                INNER JOIN fib_booking_reservation reservation ON reservation.id = ticket.reservation_id
                WHERE ticket.id = :ticketId AND reservation.customer_id = :customerId
            SQL,
            [
                'ticketId' => Uuid::fromHexToBytes($ticketId),
                'customerId' => Uuid::fromHexToBytes($customerId),
            ],
        );
    }
}
