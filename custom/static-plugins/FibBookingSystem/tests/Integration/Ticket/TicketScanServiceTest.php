<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Integration\Ticket;

use Doctrine\DBAL\Connection;
use FibBookingSystem\Core\Domain\Ticket\TicketScanResult;
use FibBookingSystem\Core\Domain\Ticket\TicketScanService;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Full scan lifecycle against the real schema: verdict matrix, replay
 * protection and the audit trail.
 */
class TicketScanServiceTest extends TestCase
{
    use KernelTestBehaviour;

    private const RESOURCE_ID = 'f1b0000000000000000000000000c001';
    private const RESERVATION_ID = 'f1b0000000000000000000000000c002';

    private Connection $connection;
    private TicketScanService $scanService;

    protected function setUp(): void
    {
        $this->connection = self::container()->get(Connection::class);
        $this->scanService = new TicketScanService($this->connection);

        $this->cleanupFixtures();
        $this->seedReservation();
    }

    protected function tearDown(): void
    {
        $this->cleanupFixtures();
    }

    public function testFirstScanIsValidReplayIsAlreadyScanned(): void
    {
        $token = $this->seedTicket('T-SCAN-1', 'sent');

        $first = $this->scanService->scan($token, 'test-user', 'phpunit');
        static::assertSame(TicketScanResult::VALID, $first->verdict);
        static::assertSame('T-SCAN-1', $first->ticketNumber);
        static::assertSame('B-SCAN-TEST', $first->bookingNumber);

        $replay = $this->scanService->scan($token, 'test-user', 'phpunit');
        static::assertSame(TicketScanResult::ALREADY_SCANNED, $replay->verdict);
        static::assertSame($first->scannedAt, $replay->scannedAt, 'replay must report the FIRST scan time');
    }

    public function testRevokedTicketIsRejected(): void
    {
        $token = $this->seedTicket('T-SCAN-2', 'revoked');

        static::assertSame(TicketScanResult::REVOKED, $this->scanService->scan($token)->verdict);
    }

    public function testExpiredTicketIsRejectedAndTransitioned(): void
    {
        $token = $this->seedTicket('T-SCAN-3', 'sent', expiresAt: '2020-01-01 00:00:00.000');

        static::assertSame(TicketScanResult::EXPIRED, $this->scanService->scan($token)->verdict);

        $status = $this->connection->fetchOne(
            <<<'SQL'
                SELECT status FROM fib_booking_ticket WHERE ticket_number = 'T-SCAN-3'
                SQL,
        );
        static::assertSame('expired', $status);
    }

    public function testUnknownTokenIsNotFound(): void
    {
        static::assertSame(
            TicketScanResult::NOT_FOUND,
            $this->scanService->scan(str_repeat('0', 64))->verdict,
        );
    }

    public function testEveryAttemptIsAudited(): void
    {
        $token = $this->seedTicket('T-SCAN-4', 'issued');

        $this->scanService->scan($token, 'auditor', 'phpunit');
        $this->scanService->scan($token, 'auditor', 'phpunit');
        $this->scanService->scan(str_repeat('f', 64), 'auditor', 'phpunit');

        $verdicts = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT verdict FROM fib_booking_scan_log
                WHERE scanned_by = 'auditor' AND source = 'phpunit'
                ORDER BY created_at, verdict
                SQL,
        );

        sort($verdicts);
        static::assertSame(['already_scanned', 'not_found', 'valid'], $verdicts);

        $loggedTokens = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT token_fingerprint FROM fib_booking_scan_log WHERE scanned_by = 'auditor'
                SQL,
        );
        foreach ($loggedTokens as $fingerprint) {
            static::assertSame(12, strlen((string) $fingerprint), 'only the fingerprint may be persisted');
        }
    }

    private function seedReservation(): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO fib_booking_resource (id, name, technical_name, capacity, active, created_at)
                VALUES (:id, 'Scan Test Resource', 'fib_test_scan_resource', 10, 1, NOW(3))
                SQL,
            ['id' => Uuid::fromHexToBytes(self::RESOURCE_ID)],
        );

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO fib_booking_reservation (id, resource_id, booking_number, starts_at, ends_at, quantity, status, created_at)
                VALUES (:id, :resourceId, 'B-SCAN-TEST', '2026-12-01 18:00:00.000', '2026-12-01 20:00:00.000', 2, 'confirmed', NOW(3))
                SQL,
            [
                'id' => Uuid::fromHexToBytes(self::RESERVATION_ID),
                'resourceId' => Uuid::fromHexToBytes(self::RESOURCE_ID),
            ],
        );
    }

    private function seedTicket(string $ticketNumber, string $status, ?string $expiresAt = null): string
    {
        $token = bin2hex(random_bytes(32));

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO fib_booking_ticket (id, reservation_id, ticket_number, scan_token_hash, status, issued_at, expires_at, created_at)
                VALUES (:id, :reservationId, :ticketNumber, :tokenHash, :status, NOW(3), :expiresAt, NOW(3))
                SQL,
            [
                'id' => Uuid::randomBytes(),
                'reservationId' => Uuid::fromHexToBytes(self::RESERVATION_ID),
                'ticketNumber' => $ticketNumber,
                'tokenHash' => hash('sha256', $token),
                'status' => $status,
                'expiresAt' => $expiresAt,
            ],
        );

        return $token;
    }

    private function cleanupFixtures(): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                DELETE FROM fib_booking_scan_log WHERE source = 'phpunit'
                SQL,
        );
        $this->connection->executeStatement(
            <<<'SQL'
                DELETE FROM fib_booking_ticket WHERE ticket_number LIKE 'T-SCAN-%'
                SQL,
        );
        $this->connection->executeStatement(
            <<<'SQL'
                DELETE FROM fib_booking_reservation WHERE booking_number = 'B-SCAN-TEST'
                SQL,
        );
        $this->connection->executeStatement(
            <<<'SQL'
                DELETE FROM fib_booking_resource WHERE technical_name = 'fib_test_scan_resource'
                SQL,
        );
    }

    private static function container(): ContainerInterface
    {
        $container = static::getKernel()->getContainer();

        return $container->has('test.service_container') ? $container->get('test.service_container') : $container;
    }
}
