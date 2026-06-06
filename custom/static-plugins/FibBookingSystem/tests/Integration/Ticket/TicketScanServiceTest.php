<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Integration\Ticket;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use FibBookingSystem\Core\Domain\Ticket\ScanDirection;
use FibBookingSystem\Core\Domain\Ticket\TicketScanResult;
use FibBookingSystem\Core\Domain\Ticket\TicketScanService;
use FibBookingSystem\FibBookingException;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Test\Stub\SystemConfigService\StaticSystemConfigService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Full scan lifecycle against the real schema: verdict matrix, replay
 * protection, check-in/check-out with re-entry and the audit trail.
 */
class TicketScanServiceTest extends TestCase
{
    use KernelTestBehaviour;

    private const RESOURCE_ID = 'f1b0000000000000000000000000c001';
    private const RESERVATION_ID = 'f1b0000000000000000000000000c002';

    private Connection $connection;
    private TicketScanService $scanService;
    private Context $context;

    protected function setUp(): void
    {
        $this->connection = self::container()->get(Connection::class);
        $this->scanService = $this->createScanService(checkOutEnabled: true);
        $this->context = Context::createDefaultContext();

        $this->cleanupFixtures();
        $this->seedReservation();
    }

    private function createScanService(bool $checkOutEnabled): TicketScanService
    {
        return new TicketScanService(
            $this->connection,
            self::container()->get('fib_booking_ticket.repository'),
            self::container()->get('fib_booking_scan_log.repository'),
            new StaticSystemConfigService([
                'FibBookingSystem.config.scanCheckOutEnabled' => $checkOutEnabled,
            ]),
        );
    }

    protected function tearDown(): void
    {
        $this->cleanupFixtures();
    }

    public function testFirstScanIsValidReplayIsAlreadyScanned(): void
    {
        $token = $this->seedTicket('T-SCAN-1', 'sent');

        $first = $this->scanService->scan($token, $this->context, 'test-user', 'phpunit');
        static::assertSame(TicketScanResult::VALID, $first->verdict);
        static::assertSame('T-SCAN-1', $first->ticketNumber);
        static::assertSame('B-SCAN-TEST', $first->bookingNumber);

        $replay = $this->scanService->scan($token, $this->context, 'test-user', 'phpunit');
        static::assertSame(TicketScanResult::ALREADY_SCANNED, $replay->verdict);
        static::assertSame($first->scannedAt, $replay->scannedAt, 'replay must report the FIRST scan time');
    }

    public function testRevokedTicketIsRejected(): void
    {
        $token = $this->seedTicket('T-SCAN-2', 'revoked');

        static::assertSame(TicketScanResult::REVOKED, $this->scanService->scan($token, $this->context)->verdict);
    }

    public function testExpiredTicketIsRejectedAndTransitioned(): void
    {
        $token = $this->seedTicket('T-SCAN-3', 'sent', expiresAt: '2020-01-01 00:00:00.000');

        static::assertSame(TicketScanResult::EXPIRED, $this->scanService->scan($token, $this->context)->verdict);

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
            $this->scanService->scan(str_repeat('0', 64), $this->context)->verdict,
        );
    }

    public function testCheckOutAndReEntryLifecycle(): void
    {
        $token = $this->seedTicket('T-SCAN-5', 'sent');

        $checkIn = $this->scanService->scan($token, $this->context, 'test-user', 'phpunit');
        static::assertSame(TicketScanResult::VALID, $checkIn->verdict);

        $checkOut = $this->scanService->scan($token, $this->context, 'test-user', 'phpunit', ScanDirection::CHECK_OUT);
        static::assertSame(TicketScanResult::CHECKED_OUT, $checkOut->verdict);
        static::assertSame(ScanDirection::CHECK_OUT, $checkOut->direction);

        // Re-entry: a checked-out guest may check in again …
        $reEntry = $this->scanService->scan($token, $this->context, 'test-user', 'phpunit');
        static::assertSame(TicketScanResult::VALID, $reEntry->verdict);
        static::assertSame($checkIn->scannedAt, $reEntry->scannedAt, 're-entry must keep the FIRST entry time');

        // … and is "already scanned" while inside.
        static::assertSame(
            TicketScanResult::ALREADY_SCANNED,
            $this->scanService->scan($token, $this->context, 'test-user', 'phpunit')->verdict,
        );
    }

    public function testCheckOutWithoutCheckInIsRejected(): void
    {
        $token = $this->seedTicket('T-SCAN-6', 'sent');

        $result = $this->scanService->scan($token, $this->context, 'test-user', 'phpunit', ScanDirection::CHECK_OUT);

        static::assertSame(TicketScanResult::NOT_CHECKED_IN, $result->verdict);
        static::assertSame('sent', $this->connection->fetchOne(
            <<<'SQL'
                SELECT status FROM fib_booking_ticket WHERE ticket_number = 'T-SCAN-6'
                SQL,
        ), 'a rejected check-out must not change the ticket status');
    }

    public function testCheckOutRequiresThePluginConfigFlag(): void
    {
        $disabledService = $this->createScanService(checkOutEnabled: false);
        $token = $this->seedTicket('T-SCAN-7', 'sent');

        $this->expectException(FibBookingException::class);
        $this->expectExceptionMessage('Check-out scanning is disabled');

        $disabledService->scan($token, $this->context, 'test-user', 'phpunit', ScanDirection::CHECK_OUT);
    }

    public function testFutureValidFromIsNotYetValid(): void
    {
        $token = $this->seedTicket('T-SCAN-9', 'sent', validFrom: '2030-01-01 00:00:00.000');

        static::assertSame(
            TicketScanResult::NOT_YET_VALID,
            $this->scanService->scan($token, $this->context, 'test-user', 'phpunit')->verdict,
        );
    }

    public function testFirstUseAnchorActivatesThePassOnFirstCheckIn(): void
    {
        $token = $this->seedTicket('T-SCAN-10', 'sent', entryPolicy: 'multi', validityAnchor: 'first_use', validityDuration: 'P1D');

        $result = $this->scanService->scan($token, $this->context, 'test-user', 'phpunit');
        static::assertSame(TicketScanResult::VALID, $result->verdict);

        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT valid_from, expires_at FROM fib_booking_ticket WHERE ticket_number = 'T-SCAN-10'
                SQL,
        );
        static::assertIsArray($row);
        static::assertNotNull($row['valid_from'], 'first check-in must activate the pass');
        static::assertNotNull($row['expires_at']);
        static::assertSame(
            86400,
            (int) round((new DateTimeImmutable((string) $row['expires_at']))->getTimestamp() - (new DateTimeImmutable((string) $row['valid_from']))->getTimestamp()),
            'expiry must be valid_from + P1D',
        );
    }

    public function testMultiEntryPassMayEnterRepeatedly(): void
    {
        $token = $this->seedTicket('T-SCAN-11', 'sent', entryPolicy: 'multi');

        static::assertSame(TicketScanResult::VALID, $this->scanService->scan($token, $this->context, 'test-user', 'phpunit')->verdict);
        // No check-out in between — a pass may pass the gate again.
        static::assertSame(TicketScanResult::VALID, $this->scanService->scan($token, $this->context, 'test-user', 'phpunit')->verdict);
    }

    public function testMultiEntryDailyLimitIsEnforced(): void
    {
        $token = $this->seedTicket('T-SCAN-12', 'sent', entryPolicy: 'multi', maxEntriesPerDay: 2);

        static::assertSame(TicketScanResult::VALID, $this->scanService->scan($token, $this->context, 'limit-user', 'phpunit')->verdict);
        static::assertSame(TicketScanResult::VALID, $this->scanService->scan($token, $this->context, 'limit-user', 'phpunit')->verdict);
        static::assertSame(
            TicketScanResult::ENTRY_LIMIT_REACHED,
            $this->scanService->scan($token, $this->context, 'limit-user', 'phpunit')->verdict,
        );
    }

    public function testScanLogCarriesTheDirection(): void
    {
        $token = $this->seedTicket('T-SCAN-8', 'sent');

        $this->scanService->scan($token, $this->context, 'direction-auditor', 'phpunit');
        $this->scanService->scan($token, $this->context, 'direction-auditor', 'phpunit', ScanDirection::CHECK_OUT);

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT direction, verdict FROM fib_booking_scan_log
                WHERE scanned_by = 'direction-auditor'
                ORDER BY created_at, direction
                SQL,
        );

        static::assertSame([
            ['direction' => 'check_in', 'verdict' => 'valid'],
            ['direction' => 'check_out', 'verdict' => 'checked_out'],
        ], $rows);
    }

    public function testEveryAttemptIsAudited(): void
    {
        $token = $this->seedTicket('T-SCAN-4', 'issued');

        $this->scanService->scan($token, $this->context, 'auditor', 'phpunit');
        $this->scanService->scan($token, $this->context, 'auditor', 'phpunit');
        $this->scanService->scan(str_repeat('f', 64), $this->context, 'auditor', 'phpunit');

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

    private function seedTicket(
        string $ticketNumber,
        string $status,
        ?string $expiresAt = null,
        ?string $validFrom = null,
        string $entryPolicy = 'single',
        ?int $maxEntriesPerDay = null,
        ?string $validityAnchor = null,
        ?string $validityDuration = null,
    ): string {
        $token = bin2hex(random_bytes(32));

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO fib_booking_ticket (id, reservation_id, ticket_number, scan_token_hash, status, issued_at,
                expires_at, valid_from, entry_policy, max_entries_per_day, validity_anchor, validity_duration, created_at)
                VALUES (:id, :reservationId, :ticketNumber, :tokenHash, :status, NOW(3),
                :expiresAt, :validFrom, :entryPolicy, :maxEntriesPerDay, :validityAnchor, :validityDuration, NOW(3))
                SQL,
            [
                'id' => Uuid::randomBytes(),
                'reservationId' => Uuid::fromHexToBytes(self::RESERVATION_ID),
                'ticketNumber' => $ticketNumber,
                'tokenHash' => hash('sha256', $token),
                'status' => $status,
                'expiresAt' => $expiresAt,
                'validFrom' => $validFrom,
                'entryPolicy' => $entryPolicy,
                'maxEntriesPerDay' => $maxEntriesPerDay,
                'validityAnchor' => $validityAnchor,
                'validityDuration' => $validityDuration,
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
