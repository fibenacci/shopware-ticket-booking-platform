<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Integration\Ticket;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use FibBookingSystem\Core\Domain\Security\TokenCipher;
use FibBookingSystem\Core\Domain\Ticket\BookingTicketService;
use FibBookingSystem\Core\Domain\Ticket\QrCodeGenerator;
use FibBookingSystem\Core\Domain\Ticket\RotatingCodeService;
use FibBookingSystem\Core\Domain\Ticket\RotatingScanVerifier;
use FibBookingSystem\Core\Domain\Ticket\ScanVerdictResolver;
use FibBookingSystem\Core\Domain\Ticket\TicketScanResult;
use FibBookingSystem\Core\Domain\Ticket\TicketScanService;
use FibBookingSystem\Core\Domain\Validity\TicketValidityResolver;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;
use Shopware\Core\Test\Stub\SystemConfigService\StaticSystemConfigService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Slot-bound tickets must carry their Termin as scan window: valid from
 * `scanEarlyEntryMinutes` before the slot starts until the slot ends — a
 * ticket for tomorrow's slot must NOT scan VALID today (NOT_YET_VALID), and
 * a past slot's ticket must be EXPIRED.
 */
class TicketSlotWindowTest extends TestCase
{
    use KernelTestBehaviour;

    private const RESOURCE_ID = 'f1b0000000000000000000000000c101';

    private Connection $connection;
    private Context $context;

    protected function setUp(): void
    {
        $this->connection = self::container()->get(Connection::class);
        $this->context = Context::createDefaultContext();

        $this->cleanupFixtures();
        $this->seedResource();
    }

    protected function tearDown(): void
    {
        $this->cleanupFixtures();
    }

    public function testTicketForFutureSlotCarriesTheSlotWindow(): void
    {
        $reservationId = $this->seedReservation('B-WINDOW-1', '2026-12-01 18:00:00.000', '2026-12-01 20:00:00.000');

        $this->ticketService(earlyEntryMinutes: 60)->issueTicket($reservationId, $this->context);

        /** @var array{valid_from: string, expires_at: string}|false $row */
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT valid_from, expires_at FROM fib_booking_ticket WHERE reservation_id = :reservationId
                SQL,
            ['reservationId' => Uuid::fromHexToBytes($reservationId)],
        );

        static::assertNotFalse($row);
        static::assertSame('2026-12-01 17:00:00.000', $row['valid_from'], 'valid from 60 minutes before slot start');
        static::assertSame('2026-12-01 20:00:00.000', $row['expires_at'], 'expires at slot end');
    }

    public function testExplicitZeroEarlyEntryStartsExactlyAtSlotStart(): void
    {
        $reservationId = $this->seedReservation('B-WINDOW-2', '2026-12-01 18:00:00.000', '2026-12-01 20:00:00.000');

        $this->ticketService(earlyEntryMinutes: 0)->issueTicket($reservationId, $this->context);

        $validFrom = $this->connection->fetchOne(
            <<<'SQL'
                SELECT valid_from FROM fib_booking_ticket WHERE reservation_id = :reservationId
                SQL,
            ['reservationId' => Uuid::fromHexToBytes($reservationId)],
        );

        static::assertSame('2026-12-01 18:00:00.000', $validFrom);
    }

    public function testFutureSlotTicketScansAsNotYetValid(): void
    {
        $reservationId = $this->seedReservation('B-WINDOW-3', '2099-12-01 18:00:00.000', '2099-12-01 20:00:00.000');

        $ticket = $this->ticketService(earlyEntryMinutes: 60)->issueTicket($reservationId, $this->context);

        $result = $this->scanService()->scan($ticket->getScanToken(), $this->context, 'test-user', 'phpunit');

        static::assertSame(TicketScanResult::NOT_YET_VALID, $result->verdict);
    }

    public function testPastSlotTicketScansAsExpired(): void
    {
        $reservationId = $this->seedReservation('B-WINDOW-4', '2020-01-01 18:00:00.000', '2020-01-01 20:00:00.000');

        $ticket = $this->ticketService(earlyEntryMinutes: 60)->issueTicket($reservationId, $this->context);

        $result = $this->scanService()->scan($ticket->getScanToken(), $this->context, 'test-user', 'phpunit');

        static::assertSame(TicketScanResult::EXPIRED, $result->verdict);
    }

    public function testExplicitCallerExpiryStillWins(): void
    {
        $reservationId = $this->seedReservation('B-WINDOW-5', '2026-12-01 18:00:00.000', '2026-12-01 20:00:00.000');

        $this->ticketService(earlyEntryMinutes: 60)->issueTicket(
            $reservationId,
            $this->context,
            expiresAt: new DateTimeImmutable('2026-12-01 19:00:00', new DateTimeZone('UTC')),
        );

        $expiresAt = $this->connection->fetchOne(
            <<<'SQL'
                SELECT expires_at FROM fib_booking_ticket WHERE reservation_id = :reservationId
                SQL,
            ['reservationId' => Uuid::fromHexToBytes($reservationId)],
        );

        static::assertSame('2026-12-01 19:00:00.000', $expiresAt);
    }

    private function ticketService(int $earlyEntryMinutes): BookingTicketService
    {
        return new BookingTicketService(
            $this->connection,
            self::container()->get('fib_booking_ticket.repository'),
            self::container()->get('event_dispatcher'),
            new QrCodeGenerator(),
            self::container()->get(NumberRangeValueGeneratorInterface::class),
            new TokenCipher('test-secret'),
            new TicketValidityResolver(),
            new StaticSystemConfigService([
                'FibBookingSystem.config.scanEarlyEntryMinutes' => $earlyEntryMinutes,
            ]),
            new RotatingCodeService(),
        );
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

    private function seedResource(): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO fib_booking_resource (id, name, technical_name, capacity, active, created_at)
                VALUES (:id, 'Window Test Resource', 'fib_test_window_resource', 10, 1, NOW(3))
                SQL,
            ['id' => Uuid::fromHexToBytes(self::RESOURCE_ID)],
        );
    }

    private function seedReservation(
        string $bookingNumber,
        string $startsAt,
        string $endsAt,
    ): string {
        $reservationId = Uuid::randomHex();

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO fib_booking_reservation (id, resource_id, booking_number, starts_at, ends_at, quantity, status, created_at)
                VALUES (:id, :resourceId, :bookingNumber, :startsAt, :endsAt, 1, 'confirmed', NOW(3))
                SQL,
            [
                'id' => Uuid::fromHexToBytes($reservationId),
                'resourceId' => Uuid::fromHexToBytes(self::RESOURCE_ID),
                'bookingNumber' => $bookingNumber,
                'startsAt' => $startsAt,
                'endsAt' => $endsAt,
            ],
        );

        return $reservationId;
    }

    /**
     * Same helper as TicketScanServiceTest: prefer the test service container
     * (public service access) when available.
     */
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
