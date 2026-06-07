<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Integration\Wallet;

use Doctrine\DBAL\Connection;
use FibBookingSystem\Core\Domain\Security\TokenCipher;
use FibBookingSystem\Core\Domain\Ticket\QrCodeGenerator;
use FibBookingSystem\Core\Domain\Ticket\RotatingCodeService;
use FibBookingSystem\Core\Domain\Wallet\AppleWalletPassGenerator;
use FibBookingSystem\Core\Domain\Wallet\GoogleWalletLinkGenerator;
use FibBookingSystem\Core\Domain\Wallet\WalletLinkSigner;
use FibBookingSystem\Core\Domain\Wallet\WalletPassService;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The rotating-QR account endpoint logic (WalletPassService::rotatingQrForOwner):
 * the live code is returned ONLY to the ticket's effective owner, only for
 * rotating tickets, and the wire it encodes verifies for the current window.
 */
class RotatingQrEndpointTest extends TestCase
{
    use KernelTestBehaviour;

    private const SECRET = 'rotating-endpoint-test-secret';
    private const RESOURCE_ID = 'f1b0000000000000000000000000e001';
    private const RESERVATION_ID = 'f1b0000000000000000000000000e002';
    private const OWNER_ID = 'f1b0000000000000000000000000e0c1';
    private const STRANGER_ID = 'f1b0000000000000000000000000e0c2';

    private Connection $connection;
    private WalletPassService $walletService;
    private RotatingCodeService $rotatingCodeService;

    protected function setUp(): void
    {
        $this->connection = self::container()->get(Connection::class);
        $this->rotatingCodeService = new RotatingCodeService();
        $systemConfig = self::container()->get(SystemConfigService::class);
        $this->walletService = new WalletPassService(
            $this->connection,
            self::container()->get('fib_booking_ticket.repository'),
            new TokenCipher(self::SECRET),
            new AppleWalletPassGenerator($systemConfig, '/tmp'),
            new GoogleWalletLinkGenerator($systemConfig),
            new WalletLinkSigner(self::SECRET),
            new QrCodeGenerator(),
            $this->rotatingCodeService,
        );

        $this->cleanup();
        $this->createOwnerCustomer();
        $this->seed();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
    }

    /**
     * reservation.customer_id carries a real FK — the owner must exist.
     */
    private function createOwnerCustomer(): void
    {
        $channel = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT LOWER(HEX(id)) AS id, LOWER(HEX(customer_group_id)) AS group_id, LOWER(HEX(language_id)) AS language_id
                FROM sales_channel WHERE type_id = UNHEX('8A243080F92E4C719546314B577CF82B') LIMIT 1
            SQL,
        );
        $countryId = $this->connection->fetchOne('SELECT LOWER(HEX(id)) FROM country WHERE active = 1 LIMIT 1');
        $salutationId = $this->connection->fetchOne('SELECT LOWER(HEX(id)) FROM salutation LIMIT 1');

        static::assertIsArray($channel);
        static::assertIsString($countryId);
        static::assertIsString($salutationId);

        $address = [
            'id' => Uuid::randomHex(),
            'salutationId' => $salutationId,
            'firstName' => 'Rot',
            'lastName' => 'Owner',
            'street' => 'Teststr. 1',
            'zipcode' => '00000',
            'city' => 'Testcity',
            'countryId' => $countryId,
        ];

        self::container()->get('customer.repository')->create([
            [
                'id' => self::OWNER_ID,
                'customerNumber' => 'ROT-EP-OWNER',
                'salesChannelId' => $channel['id'],
                'languageId' => $channel['language_id'],
                'groupId' => $channel['group_id'],
                'salutationId' => $salutationId,
                'firstName' => 'Rot',
                'lastName' => 'Owner',
                'email' => 'rot-ep-owner@example.invalid',
                'defaultBillingAddress' => $address,
                'defaultShippingAddress' => ['id' => Uuid::randomHex()] + $address,
            ],
        ], \Shopware\Core\Framework\Context::createDefaultContext());
    }

    public function testOwnerGetsAVerifiableRotatingCode(): void
    {
        $token = $this->seedRotatingTicket('T-ROT-EP-1', self::OWNER_ID);
        $now = time();

        $result = $this->walletService->rotatingQrForOwner('f1b0000000000000000000000000e003', self::OWNER_ID, $now);

        static::assertIsArray($result);
        static::assertStringStartsWith('data:image/', $result['qrCodeDataUri']);
        static::assertGreaterThan(0, $result['ttl']);

        // The wire that the QR encodes must verify for the current window.
        $expectedWire = $this->rotatingCodeService->wireToken('T-ROT-EP-1', $token, 30, $now);
        [, $code] = $this->rotatingCodeService->parseWireToken($expectedWire);
        static::assertNotNull($this->rotatingCodeService->verify($code, $token, 30, $now));
    }

    public function testAStrangerGetsNothing(): void
    {
        $this->seedRotatingTicket('T-ROT-EP-2', self::OWNER_ID);

        static::assertNull(
            $this->walletService->rotatingQrForOwner('f1b0000000000000000000000000e003', self::STRANGER_ID, time()),
            'only the effective owner may pull the live code',
        );
    }

    public function testNonRotatingTicketReturnsNull(): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO fib_booking_ticket (id, reservation_id, ticket_number, scan_token_hash, scan_token_cipher, status, issued_at, entry_policy, rotating_qr_enabled, created_at)
                VALUES (:id, :reservationId, 'T-ROT-EP-3', :hash, :cipher, 'sent', NOW(3), 'single', 0, NOW(3))
                SQL,
            [
                'id' => Uuid::fromHexToBytes('f1b0000000000000000000000000e004'),
                'reservationId' => Uuid::fromHexToBytes(self::RESERVATION_ID),
                'hash' => hash('sha256', 'x'),
                'cipher' => (new TokenCipher(self::SECRET))->encrypt('x'),
            ],
        );

        static::assertNull(
            $this->walletService->rotatingQrForOwner('f1b0000000000000000000000000e004', self::OWNER_ID, time()),
            'a non-rotating ticket has no live code',
        );
    }

    private function seedRotatingTicket(string $ticketNumber, string $ownerCustomerId): string
    {
        $token = bin2hex(random_bytes(32));

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO fib_booking_ticket (id, reservation_id, ticket_number, scan_token_hash, scan_token_cipher, status, issued_at,
                entry_policy, rotating_qr_enabled, rotating_qr_interval, created_at)
                VALUES (:id, :reservationId, :ticketNumber, :hash, :cipher, 'sent', NOW(3),
                'single', 1, 30, NOW(3))
                SQL,
            [
                'id' => Uuid::fromHexToBytes('f1b0000000000000000000000000e003'),
                'reservationId' => Uuid::fromHexToBytes(self::RESERVATION_ID),
                'ticketNumber' => $ticketNumber,
                'hash' => hash('sha256', $token),
                'cipher' => (new TokenCipher(self::SECRET))->encrypt($token),
            ],
        );

        return $token;
    }

    private function seed(): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO fib_booking_resource (id, name, technical_name, capacity, active, created_at)
                VALUES (:id, 'Rotating EP Resource', 'fib_test_rot_ep', 10, 1, NOW(3))
                SQL,
            ['id' => Uuid::fromHexToBytes(self::RESOURCE_ID)],
        );

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO fib_booking_reservation (id, resource_id, customer_id, booking_number, starts_at, ends_at, quantity, status, created_at)
                VALUES (:id, :resourceId, :customerId, 'B-ROT-EP', '2026-12-01 18:00:00.000', '2026-12-01 20:00:00.000', 1, 'confirmed', NOW(3))
                SQL,
            [
                'id' => Uuid::fromHexToBytes(self::RESERVATION_ID),
                'resourceId' => Uuid::fromHexToBytes(self::RESOURCE_ID),
                'customerId' => Uuid::fromHexToBytes(self::OWNER_ID),
            ],
        );
    }

    private function cleanup(): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                DELETE ticket FROM fib_booking_ticket ticket
                INNER JOIN fib_booking_reservation reservation ON reservation.id = ticket.reservation_id
                WHERE reservation.booking_number = 'B-ROT-EP'
                SQL,
        );
        $this->connection->executeStatement("DELETE FROM fib_booking_reservation WHERE booking_number = 'B-ROT-EP'");
        $this->connection->executeStatement("DELETE FROM fib_booking_resource WHERE technical_name = 'fib_test_rot_ep'");
        $this->connection->executeStatement("DELETE FROM customer WHERE customer_number = 'ROT-EP-OWNER'");
    }

    private static function container(): ContainerInterface
    {
        $container = static::getKernel()->getContainer();

        return $container->has('test.service_container') ? $container->get('test.service_container') : $container;
    }
}
