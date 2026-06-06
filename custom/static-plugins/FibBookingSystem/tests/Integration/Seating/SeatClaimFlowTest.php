<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Integration\Seating;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use FibBookingSystem\Core\Domain\Availability\AvailabilityService;
use FibBookingSystem\Core\Domain\Reservation\BookingHoldExpirationService;
use FibBookingSystem\Core\Domain\Reservation\BookingHoldRequest;
use FibBookingSystem\Core\Domain\Reservation\BookingHoldService;
use FibBookingSystem\Core\Domain\Seating\SeatClaimService;
use FibBookingSystem\Core\Domain\Seating\SeatmapReadService;
use FibBookingSystem\FibBookingException;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Test\Stub\SystemConfigService\StaticSystemConfigService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The seat-claim primitive against the real schema: the UNIQUE(seat_id,
 * slot_id) key decides every race, expiry releases, conversion binds, and
 * the read model reports free/held/sold consistently
 * (docs/SEATING_PLAN.md).
 */
class SeatClaimFlowTest extends TestCase
{
    use KernelTestBehaviour;

    private const RESOURCE_ID = 'f1b0000000000000000000000000e001';
    private const POOL_RESOURCE_ID = 'f1b0000000000000000000000000e002';
    private const SLOT_ID = 'f1b0000000000000000000000000e003';
    private const SEAT_A = 'f1b0000000000000000000000000e0a1';
    private const SEAT_B = 'f1b0000000000000000000000000e0a2';

    private Connection $connection;
    private SeatClaimService $claimService;
    private BookingHoldService $holdService;
    private Context $context;

    protected function setUp(): void
    {
        $this->connection = self::container()->get(Connection::class);
        $this->claimService = new SeatClaimService($this->connection);
        $this->holdService = new BookingHoldService(
            $this->connection,
            self::container()->get('fib_booking_hold.repository'),
            new AvailabilityService($this->connection),
            new StaticSystemConfigService([]),
            $this->claimService,
        );
        $this->context = Context::createDefaultContext();

        $this->cleanupFixtures();
        $this->seedFixtures();
    }

    protected function tearDown(): void
    {
        $this->cleanupFixtures();
    }

    public function testSeatRaceIsDecidedByTheUniqueKey(): void
    {
        $this->claimService->claimSeatsForHold(Uuid::randomHex(), self::RESOURCE_ID, self::SLOT_ID, [self::SEAT_A], 1);

        $this->expectException(FibBookingException::class);
        $this->expectExceptionMessage('just taken');

        $this->claimService->claimSeatsForHold(Uuid::randomHex(), self::RESOURCE_ID, self::SLOT_ID, [self::SEAT_A], 1);
    }

    public function testHoldWithSeatsClaimsThemInOneTransaction(): void
    {
        $hold = $this->holdService->createHold(
            new BookingHoldRequest(
                resourceId: self::RESOURCE_ID,
                startsAt: new DateTimeImmutable('2026-12-01 18:00:00'),
                endsAt: new DateTimeImmutable('2026-12-01 20:00:00'),
                quantity: 2,
                payload: ['slotId' => self::SLOT_ID],
                seatIds: [self::SEAT_A, self::SEAT_B],
            ),
            $this->context,
        );

        $claims = $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) FROM fib_booking_seat_claim WHERE hold_id = :holdId
            SQL,
            ['holdId' => Uuid::fromHexToBytes($hold->getId())],
        );

        static::assertSame(2, (int) $claims);
    }

    public function testLostSeatRaceRollsTheWholeHoldBack(): void
    {
        // Someone else already holds seat B.
        $this->claimService->claimSeatsForHold(Uuid::randomHex(), self::RESOURCE_ID, self::SLOT_ID, [self::SEAT_B], 1);

        try {
            $this->holdService->createHold(
                new BookingHoldRequest(
                    resourceId: self::RESOURCE_ID,
                    startsAt: new DateTimeImmutable('2026-12-01 18:00:00'),
                    endsAt: new DateTimeImmutable('2026-12-01 20:00:00'),
                    quantity: 2,
                    payload: ['slotId' => self::SLOT_ID],
                    seatIds: [self::SEAT_A, self::SEAT_B],
                ),
                $this->context,
            );
            static::fail('expected SEATS_TAKEN');
        } catch (FibBookingException $exception) {
            static::assertSame(FibBookingException::SEATS_TAKEN, $exception->getErrorCode());
        }

        // Neither the hold nor the partial claim on seat A may survive.
        static::assertSame(0, (int) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) FROM fib_booking_seat_claim WHERE seat_id = :seatId
                SQL,
            ['seatId' => Uuid::fromHexToBytes(self::SEAT_A)],
        ), 'partial claims must roll back with the hold');
    }

    public function testPoolResourcesRejectSeatSelections(): void
    {
        $this->expectException(FibBookingException::class);
        $this->expectExceptionMessage('no seat map');

        $this->holdService->createHold(
            new BookingHoldRequest(
                resourceId: self::POOL_RESOURCE_ID,
                startsAt: new DateTimeImmutable('2026-12-01 18:00:00'),
                endsAt: new DateTimeImmutable('2026-12-01 20:00:00'),
                quantity: 1,
                payload: ['slotId' => self::SLOT_ID],
                seatIds: [self::SEAT_A],
            ),
            $this->context,
        );
    }

    public function testSeatmapResourcesRequireSeatSelections(): void
    {
        $this->expectException(FibBookingException::class);
        $this->expectExceptionMessage('requires picking seats');

        $this->holdService->createHold(
            new BookingHoldRequest(
                resourceId: self::RESOURCE_ID,
                startsAt: new DateTimeImmutable('2026-12-01 18:00:00'),
                endsAt: new DateTimeImmutable('2026-12-01 20:00:00'),
                quantity: 1,
                payload: ['slotId' => self::SLOT_ID],
            ),
            $this->context,
        );
    }

    public function testQuantityMustMatchTheSelection(): void
    {
        $this->expectException(FibBookingException::class);
        $this->expectExceptionMessage('quantity');

        $this->claimService->claimSeatsForHold(Uuid::randomHex(), self::RESOURCE_ID, self::SLOT_ID, [self::SEAT_A], 2);
    }

    public function testExpiredHoldsReleaseTheirSeats(): void
    {
        $hold = $this->holdService->createHold(
            new BookingHoldRequest(
                resourceId: self::RESOURCE_ID,
                startsAt: new DateTimeImmutable('2026-12-01 18:00:00'),
                endsAt: new DateTimeImmutable('2026-12-01 20:00:00'),
                quantity: 1,
                payload: ['slotId' => self::SLOT_ID],
                seatIds: [self::SEAT_A],
            ),
            $this->context,
        );

        // Force-expire, then run the maintenance the scheduled task runs.
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE fib_booking_hold SET expires_at = '2020-01-01 00:00:00.000' WHERE id = :holdId
            SQL,
            ['holdId' => Uuid::fromHexToBytes($hold->getId())],
        );
        (new BookingHoldExpirationService(
            $this->connection,
            $this->claimService,
            new \FibBookingSystem\Core\Domain\Seating\SeatmapUpdatePublisher(
                $this->createStub(\Symfony\Component\Mercure\HubInterface::class),
                new \Psr\Log\NullLogger(),
            ),
        ))->expireOverdueHolds();

        static::assertSame(0, (int) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) FROM fib_booking_seat_claim WHERE slot_id = :slotId
                SQL,
            ['slotId' => Uuid::fromHexToBytes(self::SLOT_ID)],
        ), 'expired holds must free their seats');
    }

    public function testSeatmapReservationsIssueOneTicketPerSeat(): void
    {
        // Reservation with two bound seat claims (A1, A2).
        $reservationId = Uuid::randomHex();
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO fib_booking_reservation (id, resource_id, booking_number, starts_at, ends_at, quantity, status, created_at)
                VALUES (:id, :resourceId, 'B-SEAT-TEST', '2026-12-01 18:00:00.000', '2026-12-01 20:00:00.000', 2, 'confirmed', NOW(3))
                SQL,
            [
                'id' => Uuid::fromHexToBytes($reservationId),
                'resourceId' => Uuid::fromHexToBytes(self::RESOURCE_ID),
            ],
        );
        $holdId = Uuid::randomHex();
        $this->claimService->claimSeatsForHold($holdId, self::RESOURCE_ID, self::SLOT_ID, [self::SEAT_A, self::SEAT_B], 2);
        $this->claimService->bindHoldClaimsToReservation($holdId, $reservationId);

        $tickets = $this->createTicketService()->issueTickets($reservationId, $this->context, ['demo' => true]);

        static::assertCount(2, $tickets, 'one ticket PER SEAT');
        static::assertSame(['A1', 'A2'], array_map(static fn ($ticket) => $ticket->getSeatLabel(), $tickets));
        static::assertCount(2, array_unique(array_map(static fn ($ticket) => $ticket->getScanToken(), $tickets)), 'distinct scan tokens');

        $persistedLabels = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT seat_label FROM fib_booking_ticket WHERE reservation_id = :reservationId ORDER BY seat_label
                SQL,
            ['reservationId' => Uuid::fromHexToBytes($reservationId)],
        );
        static::assertSame(['A1', 'A2'], $persistedLabels, 'seat labels are snapshotted onto the tickets');
    }

    private function createTicketService(): \FibBookingSystem\Core\Domain\Ticket\BookingTicketService
    {
        return new \FibBookingSystem\Core\Domain\Ticket\BookingTicketService(
            $this->connection,
            self::container()->get('fib_booking_ticket.repository'),
            self::container()->get('event_dispatcher'),
            new \FibBookingSystem\Core\Domain\Ticket\QrCodeGenerator(),
            self::container()->get(\Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface::class),
            new \FibBookingSystem\Core\Domain\Security\TokenCipher('seat-test-secret'),
            new \FibBookingSystem\Core\Domain\Validity\TicketValidityResolver(),
            new StaticSystemConfigService([]),
        );
    }

    public function testSeatmapReportsFreeHeldAndSold(): void
    {
        // Seat A: held by a living hold.
        $this->holdService->createHold(
            new BookingHoldRequest(
                resourceId: self::RESOURCE_ID,
                startsAt: new DateTimeImmutable('2026-12-01 18:00:00'),
                endsAt: new DateTimeImmutable('2026-12-01 20:00:00'),
                quantity: 1,
                payload: ['slotId' => self::SLOT_ID],
                seatIds: [self::SEAT_A],
            ),
            $this->context,
        );

        // Seat B: sold (claim bound to a reservation).
        $holdId = Uuid::randomHex();
        $this->claimService->claimSeatsForHold($holdId, self::RESOURCE_ID, self::SLOT_ID, [self::SEAT_B], 1);
        $this->claimService->bindHoldClaimsToReservation($holdId, Uuid::randomHex());

        $seatmap = (new SeatmapReadService($this->connection))->getSeatmap(self::SLOT_ID);

        $states = [];
        foreach ($seatmap['seats'] as $seat) {
            $states[$seat['row'] . $seat['label']] = $seat['state'];
        }

        static::assertSame('held', $states['A1']);
        static::assertSame('sold', $states['A2']);
        static::assertSame('free', $states['A3']);
    }

    private function seedFixtures(): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO fib_booking_resource (id, name, technical_name, capacity, seating_mode, active, created_at)
                VALUES (:id, 'Seat Test Cinema', 'fib_test_seat_cinema', 3, 'seatmap', 1, NOW(3)),
                (:poolId, 'Seat Test Pool', 'fib_test_seat_pool', 10, 'pool', 1, NOW(3))
                SQL,
            [
                'id' => Uuid::fromHexToBytes(self::RESOURCE_ID),
                'poolId' => Uuid::fromHexToBytes(self::POOL_RESOURCE_ID),
            ],
        );

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO fib_booking_slot (id, resource_id, starts_at, ends_at, capacity, active, created_at)
                VALUES (:id, :resourceId, '2026-12-01 18:00:00.000', '2026-12-01 20:00:00.000', 3, 1, NOW(3))
                SQL,
            [
                'id' => Uuid::fromHexToBytes(self::SLOT_ID),
                'resourceId' => Uuid::fromHexToBytes(self::RESOURCE_ID),
            ],
        );

        foreach ([[self::SEAT_A, '1', 1], [self::SEAT_B, '2', 2], [Uuid::randomHex(), '3', 3]] as [$seatId, $label, $x]) {
            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO fib_booking_seat (id, resource_id, row_label, seat_label, pos_x, pos_y, active, created_at)
                    VALUES (:id, :resourceId, 'A', :label, :x, 1, 1, NOW(3))
                    SQL,
                [
                    'id' => Uuid::fromHexToBytes($seatId),
                    'resourceId' => Uuid::fromHexToBytes(self::RESOURCE_ID),
                    'label' => $label,
                    'x' => $x,
                ],
            );
        }
    }

    private function cleanupFixtures(): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                DELETE claim FROM fib_booking_seat_claim claim
                INNER JOIN fib_booking_seat seat ON seat.id = claim.seat_id
                INNER JOIN fib_booking_resource resource ON resource.id = seat.resource_id
                WHERE resource.technical_name LIKE 'fib_test_seat_%'
                SQL,
        );
        $this->connection->executeStatement(
            <<<'SQL'
                DELETE seat FROM fib_booking_seat seat
                INNER JOIN fib_booking_resource resource ON resource.id = seat.resource_id
                WHERE resource.technical_name LIKE 'fib_test_seat_%'
                SQL,
        );
        $this->connection->executeStatement(
            <<<'SQL'
                DELETE slot FROM fib_booking_slot slot
                INNER JOIN fib_booking_resource resource ON resource.id = slot.resource_id
                WHERE resource.technical_name LIKE 'fib_test_seat_%'
                SQL,
        );
        $this->connection->executeStatement(
            <<<'SQL'
                DELETE hold FROM fib_booking_hold hold
                INNER JOIN fib_booking_resource resource ON resource.id = hold.resource_id
                WHERE resource.technical_name LIKE 'fib_test_seat_%'
                SQL,
        );
        $this->connection->executeStatement(
            <<<'SQL'
                DELETE ticket FROM fib_booking_ticket ticket
                INNER JOIN fib_booking_reservation reservation ON reservation.id = ticket.reservation_id
                WHERE reservation.booking_number = 'B-SEAT-TEST'
                SQL,
        );
        $this->connection->executeStatement(
            <<<'SQL'
                DELETE FROM fib_booking_reservation WHERE booking_number = 'B-SEAT-TEST'
                SQL,
        );
        $this->connection->executeStatement(
            <<<'SQL'
                DELETE FROM fib_booking_resource WHERE technical_name LIKE 'fib_test_seat_%'
                SQL,
        );
    }

    private static function container(): ContainerInterface
    {
        $container = static::getKernel()->getContainer();

        return $container->has('test.service_container') ? $container->get('test.service_container') : $container;
    }
}
