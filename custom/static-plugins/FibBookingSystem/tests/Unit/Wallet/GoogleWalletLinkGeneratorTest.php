<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Unit\Wallet;

use DateTimeImmutable;
use FibBookingSystem\Core\Domain\Wallet\BookingWindow;
use FibBookingSystem\Core\Domain\Wallet\GoogleWalletLinkGenerator;
use FibBookingSystem\Core\Domain\Wallet\TicketWalletData;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class GoogleWalletLinkGeneratorTest extends TestCase
{
    public function testIsNotConfiguredWithoutSettings(): void
    {
        $generator = new GoogleWalletLinkGenerator($this->createConfigStub([]));

        static::assertFalse($generator->isConfigured());
    }

    public function testIsNotConfiguredWithMalformedServiceAccount(): void
    {
        $generator = new GoogleWalletLinkGenerator($this->createConfigStub([
            'FibBookingSystem.config.googleWalletIssuerId' => '12345',
            'FibBookingSystem.config.googleWalletServiceAccountJson' => 'not-json',
        ]));

        static::assertFalse($generator->isConfigured());
    }

    public function testGeneratesSignedSaveLink(): void
    {
        $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        static::assertNotFalse($keyPair);
        $privateKeyPem = '';
        openssl_pkey_export($keyPair, $privateKeyPem);

        $generator = new GoogleWalletLinkGenerator($this->createConfigStub([
            'FibBookingSystem.config.googleWalletIssuerId' => '3388000000012345',
            'FibBookingSystem.config.googleWalletServiceAccountJson' => json_encode([
                'client_email' => 'wallet@test-project.iam.gserviceaccount.com',
                'private_key' => $privateKeyPem,
            ], JSON_THROW_ON_ERROR),
        ]));

        static::assertTrue($generator->isConfigured());

        $link = $generator->generateSaveLink($this->createTicketData());

        static::assertStringStartsWith('https://pay.google.com/gp/v/save/', $link);

        $jwt = substr($link, strlen('https://pay.google.com/gp/v/save/'));
        [$header, $payload, $signature] = explode('.', $jwt);

        $decodedHeader = json_decode($this->base64UrlDecode($header), true);
        static::assertSame(['alg' => 'RS256', 'typ' => 'JWT'], $decodedHeader);

        $claims = json_decode($this->base64UrlDecode($payload), true);
        static::assertIsArray($claims);
        static::assertSame('google', $claims['aud']);
        static::assertSame('savetowallet', $claims['typ']);
        static::assertSame('wallet@test-project.iam.gserviceaccount.com', $claims['iss']);

        $object = $claims['payload']['eventTicketObjects'][0];
        static::assertSame('3388000000012345.T1001', $object['id']);
        static::assertSame('QR_CODE', $object['barcode']['type']);
        static::assertStringContainsString('fib_booking_ticket', $object['barcode']['value']);

        // Verify the RS256 signature against the public key.
        $publicKey = openssl_pkey_get_details($keyPair)['key'];
        $verified = openssl_verify(
            $header . '.' . $payload,
            $this->base64UrlDecode($signature, raw: true),
            $publicKey,
            OPENSSL_ALGO_SHA256,
        );
        static::assertSame(1, $verified);
    }

    private function createTicketData(): TicketWalletData
    {
        return new TicketWalletData(
            ticketId: 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
            ticketNumber: 'T1001',
            bookingNumber: 'B1001',
            status: 'sent',
            qrPayload: json_encode(['type' => 'fib_booking_ticket', 'ticketNumber' => 'T1001', 'scanToken' => str_repeat('a', 64)], JSON_THROW_ON_ERROR),
            window: new BookingWindow(
                resourceName: 'Main Stage',
                startsAt: new DateTimeImmutable('2026-07-01 18:00:00'),
                endsAt: new DateTimeImmutable('2026-07-01 22:00:00'),
                quantity: 2,
            ),
            customerName: 'Jane Doe',
        );
    }

    /**
     * @param array<string, string> $values
     */
    private function createConfigStub(array $values): SystemConfigService
    {
        $stub = $this->createStub(SystemConfigService::class);
        $stub->method('get')->willReturnCallback(static fn (string $key) => $values[$key] ?? null);

        return $stub;
    }

    private function base64UrlDecode(
        string $data,
        bool $raw = false,
    ): string {
        $decoded = base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4), true);

        if ($decoded === false) {
            static::fail('Invalid base64url segment.');
        }

        return $decoded;
    }
}
