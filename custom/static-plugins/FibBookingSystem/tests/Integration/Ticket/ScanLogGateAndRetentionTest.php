<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Integration\Ticket;

use Doctrine\DBAL\Connection;
use FibBookingSystem\Core\Domain\Security\TokenCipher;
use FibBookingSystem\Core\Domain\Ticket\BookingScanLogRetentionService;
use FibBookingSystem\Core\Domain\Ticket\RotatingCodeService;
use FibBookingSystem\Core\Domain\Ticket\RotatingScanVerifier;
use FibBookingSystem\Core\Domain\Ticket\ScanVerdictResolver;
use FibBookingSystem\Core\Domain\Ticket\TicketScanService;
use FibBookingSystem\Migration\Migration1781100000AddScanLogGate;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Test\Stub\SystemConfigService\StaticSystemConfigService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Gate label on the audit trail + GDPR retention: anonymization strips the
 * person-related columns (scanned_by, token_fingerprint) of OLD rows only,
 * while verdict/direction/gate/timestamps — the statistics substrate — stay.
 */
class ScanLogGateAndRetentionTest extends TestCase
{
    use KernelTestBehaviour;

    private const RESOURCE_ID = 'f1b0000000000000000000000000c301';
    private const RESERVATION_ID = 'f1b0000000000000000000000000c302';

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = self::container()->get(Connection::class);

        // Idempotent (information-schema probe) — guarantees the gate column
        // exists even when the test DB predates the migration.
        (new Migration1781100000AddScanLogGate())->update($this->connection);

        $this->cleanupFixtures();
        $this->seedReservation();
    }

    protected function tearDown(): void
    {
        $this->cleanupFixtures();
    }

    public function testScanPersistsTheGateLabel(): void
    {
        $token = $this->seedTicket('T-GATE-1');

        $result = $this->scanService()->scan($token, Context::createDefaultContext(), 'test-user', 'phpunit', gate: 'north-entrance-2');

        static::assertSame('valid', $result->verdict);

        $gate = $this->connection->fetchOne(
            <<<'SQL'
                SELECT log.gate FROM fib_booking_scan_log log
                INNER JOIN fib_booking_ticket ticket ON ticket.id = log.ticket_id
                WHERE ticket.ticket_number = 'T-GATE-1'
                SQL,
        );

        static::assertSame('north-entrance-2', $gate);
    }

    public function testRetentionAnonymizesOnlyOldRowsAndKeepsStatisticsColumns(): void
    {
        $oldRow = $this->seedScanLogRow(ageDays: 200, gate: 'gate-a');
        $freshRow = $this->seedScanLogRow(ageDays: 2, gate: 'gate-b');

        $anonymized = (new BookingScanLogRetentionService($this->connection))->anonymizeOlderThan(180);

        static::assertSame(1, $anonymized);

        /** @var array{scanned_by: string|null, token_fingerprint: string|null, verdict: string, direction: string, gate: string|null} $old */
        $old = $this->fetchRow($oldRow);
        static::assertNull($old['scanned_by'], 'operator must be anonymized');
        static::assertNull($old['token_fingerprint'], 'ticket fingerprint must be anonymized');
        static::assertSame('valid', $old['verdict'], 'statistics substrate must survive');
        static::assertSame('check_in', $old['direction']);
        static::assertSame('gate-a', $old['gate']);

        /** @var array{scanned_by: string|null, token_fingerprint: string|null} $fresh */
        $fresh = $this->fetchRow($freshRow);
        static::assertSame('test-user', $fresh['scanned_by'], 'rows inside the retention window stay untouched');
        static::assertSame('abcdef123456', $fresh['token_fingerprint']);
    }

    public function testZeroRetentionDisablesAnonymization(): void
    {
        $oldRow = $this->seedScanLogRow(ageDays: 1000, gate: null);

        $anonymized = (new BookingScanLogRetentionService($this->connection))->anonymizeOlderThan(0);

        static::assertSame(0, $anonymized);

        /** @var array{scanned_by: string|null} $row */
        $row = $this->fetchRow($oldRow);
        static::assertSame('test-user', $row['scanned_by']);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchRow(string $id): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT scanned_by, token_fingerprint, verdict, direction, gate FROM fib_booking_scan_log WHERE id = :id',
            ['id' => Uuid::fromHexToBytes($id)],
        );

        static::assertIsArray($row);

        return $row;
    }

    private function seedScanLogRow(
        int $ageDays,
        ?string $gate,
    ): string {
        $id = Uuid::randomHex();

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO fib_booking_scan_log (id, ticket_id, verdict, direction, token_fingerprint, scanned_by, source, gate, created_at)
                VALUES (:id, NULL, 'valid', 'check_in', 'abcdef123456', 'test-user', 'phpunit', :gate, UTC_TIMESTAMP(3) - INTERVAL :ageDays DAY)
                SQL,
            ['id' => Uuid::fromHexToBytes($id), 'gate' => $gate, 'ageDays' => $ageDays],
        );

        return $id;
    }

    private function scanService(): TicketScanService
    {
        return new TicketScanService(
            $this->connection,
            self::container()->get('fib_booking_scan_log.repository'),
            new StaticSystemConfigService([]),
            new RotatingScanVerifier(new RotatingCodeService(), new TokenCipher('scan-test-secret')),
            new ScanVerdictResolver($this->connection, self::container()->get('fib_booking_ticket.repository')),
        );
    }

    private function seedReservation(): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO fib_booking_resource (id, name, technical_name, capacity, active, created_at)
                VALUES (:id, 'Gate Test Resource', 'fib_test_gate_resource', 10, 1, NOW(3))
                SQL,
            ['id' => Uuid::fromHexToBytes(self::RESOURCE_ID)],
        );

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO fib_booking_reservation (id, resource_id, booking_number, starts_at, ends_at, quantity, status, created_at)
                VALUES (:id, :resourceId, 'B-GATE-TEST', '2026-12-01 18:00:00.000', '2026-12-01 20:00:00.000', 1, 'confirmed', NOW(3))
                SQL,
            [
                'id' => Uuid::fromHexToBytes(self::RESERVATION_ID),
                'resourceId' => Uuid::fromHexToBytes(self::RESOURCE_ID),
            ],
        );
    }

    private function seedTicket(string $ticketNumber): string
    {
        $token = bin2hex(random_bytes(32));

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO fib_booking_ticket (id, reservation_id, ticket_number, scan_token_hash, status, entry_policy, issued_at, created_at)
                VALUES (:id, :reservationId, :ticketNumber, :tokenHash, 'sent', 'single', NOW(3), NOW(3))
                SQL,
            [
                'id' => Uuid::fromHexToBytes(Uuid::randomHex()),
                'reservationId' => Uuid::fromHexToBytes(self::RESERVATION_ID),
                'ticketNumber' => $ticketNumber,
                'tokenHash' => hash('sha256', $token),
            ],
        );

        return $token;
    }

    private static function container(): ContainerInterface
    {
        $container = static::getKernel()->getContainer();

        return $container->has('test.service_container') ? $container->get('test.service_container') : $container;
    }

    private function cleanupFixtures(): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                DELETE log FROM fib_booking_scan_log log
                INNER JOIN fib_booking_ticket ticket ON ticket.id = log.ticket_id
                INNER JOIN fib_booking_reservation reservation ON reservation.id = ticket.reservation_id
                WHERE reservation.resource_id = :resourceId
                SQL,
            ['resourceId' => Uuid::fromHexToBytes(self::RESOURCE_ID)],
        );
        $this->connection->executeStatement(
            "DELETE FROM fib_booking_scan_log WHERE source = 'phpunit' AND ticket_id IS NULL",
        );
        $this->connection->executeStatement(
            <<<'SQL'
                DELETE ticket FROM fib_booking_ticket ticket
                INNER JOIN fib_booking_reservation reservation ON reservation.id = ticket.reservation_id
                WHERE reservation.resource_id = :resourceId
                SQL,
            ['resourceId' => Uuid::fromHexToBytes(self::RESOURCE_ID)],
        );
        $this->connection->executeStatement(
            'DELETE FROM fib_booking_reservation WHERE resource_id = :resourceId',
            ['resourceId' => Uuid::fromHexToBytes(self::RESOURCE_ID)],
        );
        $this->connection->executeStatement(
            'DELETE FROM fib_booking_resource WHERE id = :id',
            ['id' => Uuid::fromHexToBytes(self::RESOURCE_ID)],
        );
    }
}
