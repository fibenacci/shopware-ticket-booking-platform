<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Integration\Statistics;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use FibBookingSystem\Core\Domain\Statistics\BookingStatisticsService;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Statistics read model against the real schema: purchase aggregation from
 * reservations and dwell-time sessions paired from the scan audit log
 * (including re-entry — two sessions for one ticket).
 */
class BookingStatisticsServiceTest extends TestCase
{
    use KernelTestBehaviour;

    private const RESOURCE_ID = 'f1b0000000000000000000000000d001';
    private const RESERVATION_A = 'f1b0000000000000000000000000d002';
    private const RESERVATION_B = 'f1b0000000000000000000000000d003';
    private const TICKET_ID = 'f1b0000000000000000000000000d004';

    private Connection $connection;
    private BookingStatisticsService $statisticsService;

    protected function setUp(): void
    {
        $this->connection = self::container()->get(Connection::class);
        $this->statisticsService = new BookingStatisticsService($this->connection);

        $this->cleanupFixtures();
        $this->seedFixtures();
    }

    protected function tearDown(): void
    {
        $this->cleanupFixtures();
    }

    public function testPurchasesAreAggregatedByDay(): void
    {
        $overview = $this->statisticsService->overview(
            new DateTimeImmutable('2026-03-01 00:00:00'),
            new DateTimeImmutable('2026-03-31 23:59:59'),
        );

        static::assertSame(2, $overview['purchases']['total']);
        static::assertSame(5, $overview['purchases']['quantity']);

        $byDay = $overview['purchases']['byDay'];
        static::assertCount(2, $byDay);
        static::assertSame('2026-03-10', $byDay[0]['date']);
        static::assertSame(2, $byDay[0]['quantity']);
        static::assertSame('2026-03-12', $byDay[1]['date']);
        static::assertSame(3, $byDay[1]['quantity']);
    }

    public function testReservationsOutsideTheRangeAreIgnored(): void
    {
        $overview = $this->statisticsService->overview(
            new DateTimeImmutable('2026-03-11 00:00:00'),
            new DateTimeImmutable('2026-03-31 23:59:59'),
        );

        static::assertSame(1, $overview['purchases']['total']);
        static::assertSame(3, $overview['purchases']['quantity']);
    }

    public function testDwellSessionsPairCheckOutsWithLatestCheckIn(): void
    {
        // Session 1: 10:00 → 11:30 (90 min). Session 2 (re-entry): 12:00 → 12:30 (30 min).
        $this->seedScanPair('2026-03-10 10:00:00.000', '2026-03-10 11:30:00.000');
        $this->seedScanPair('2026-03-10 12:00:00.000', '2026-03-10 12:30:00.000');

        $overview = $this->statisticsService->overview(
            new DateTimeImmutable('2026-03-01 00:00:00'),
            new DateTimeImmutable('2026-03-31 23:59:59'),
        );

        $attendance = $overview['attendance'];
        static::assertSame(2, $attendance['checkIns']);
        static::assertSame(2, $attendance['checkOuts']);
        static::assertSame(2, $attendance['dwell']['sessions']);
        static::assertSame(60.0, $attendance['dwell']['averageMinutes']);
        static::assertSame(60.0, $attendance['dwell']['medianMinutes']);
    }

    public function testNoDwellFiguresWithoutCheckOuts(): void
    {
        $overview = $this->statisticsService->overview(
            new DateTimeImmutable('2026-03-01 00:00:00'),
            new DateTimeImmutable('2026-03-31 23:59:59'),
        );

        static::assertSame(0, $overview['attendance']['dwell']['sessions']);
        static::assertNull($overview['attendance']['dwell']['averageMinutes']);
        static::assertNull($overview['attendance']['dwell']['medianMinutes']);
    }

    private function seedFixtures(): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO fib_booking_resource (id, name, technical_name, capacity, active, created_at)
                VALUES (:id, 'Stats Test Resource', 'fib_test_stats_resource', 10, 1, NOW(3))
                SQL,
            ['id' => Uuid::fromHexToBytes(self::RESOURCE_ID)],
        );

        $this->seedReservation(self::RESERVATION_A, 'B-STATS-1', 2, '2026-03-10 09:00:00.000');
        $this->seedReservation(self::RESERVATION_B, 'B-STATS-2', 3, '2026-03-12 14:00:00.000');

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO fib_booking_ticket (id, reservation_id, ticket_number, scan_token_hash, status, issued_at, created_at)
                VALUES (:id, :reservationId, 'T-STATS-1', :tokenHash, 'checked_out', NOW(3), NOW(3))
                SQL,
            [
                'id' => Uuid::fromHexToBytes(self::TICKET_ID),
                'reservationId' => Uuid::fromHexToBytes(self::RESERVATION_A),
                'tokenHash' => hash('sha256', 'stats-test-token'),
            ],
        );
    }

    private function seedReservation(
        string $id,
        string $bookingNumber,
        int $quantity,
        string $createdAt,
    ): void {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO fib_booking_reservation (id, resource_id, booking_number, starts_at, ends_at, quantity, status, created_at)
                VALUES (:id, :resourceId, :bookingNumber, '2026-03-20 18:00:00.000', '2026-03-20 20:00:00.000', :quantity, 'confirmed', :createdAt)
                SQL,
            [
                'id' => Uuid::fromHexToBytes($id),
                'resourceId' => Uuid::fromHexToBytes(self::RESOURCE_ID),
                'bookingNumber' => $bookingNumber,
                'quantity' => $quantity,
                'createdAt' => $createdAt,
            ],
        );
    }

    private function seedScanPair(
        string $checkInAt,
        string $checkOutAt,
    ): void {
        $this->seedScanLog('check_in', 'valid', $checkInAt);
        $this->seedScanLog('check_out', 'checked_out', $checkOutAt);
    }

    private function seedScanLog(
        string $direction,
        string $verdict,
        string $createdAt,
    ): void {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO fib_booking_scan_log (id, ticket_id, verdict, direction, token_fingerprint, scanned_by, source, created_at)
                VALUES (:id, :ticketId, :verdict, :direction, 'abcdef123456', 'stats-test', 'phpunit-stats', :createdAt)
                SQL,
            [
                'id' => Uuid::randomBytes(),
                'ticketId' => Uuid::fromHexToBytes(self::TICKET_ID),
                'verdict' => $verdict,
                'direction' => $direction,
                'createdAt' => $createdAt,
            ],
        );
    }

    private function cleanupFixtures(): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                DELETE FROM fib_booking_scan_log WHERE source = 'phpunit-stats'
                SQL,
        );
        $this->connection->executeStatement(
            <<<'SQL'
                DELETE FROM fib_booking_ticket WHERE ticket_number LIKE 'T-STATS-%'
                SQL,
        );
        $this->connection->executeStatement(
            <<<'SQL'
                DELETE FROM fib_booking_reservation WHERE booking_number LIKE 'B-STATS-%'
                SQL,
        );
        $this->connection->executeStatement(
            <<<'SQL'
                DELETE FROM fib_booking_resource WHERE technical_name = 'fib_test_stats_resource'
                SQL,
        );
    }

    private static function container(): ContainerInterface
    {
        $container = static::getKernel()->getContainer();

        return $container->has('test.service_container') ? $container->get('test.service_container') : $container;
    }
}
