<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Unit\Reservation;

use Doctrine\DBAL\Connection;
use FibBookingSystem\Core\Domain\Availability\AvailabilityService;
use FibBookingSystem\Core\Domain\Reservation\BookingReservationService;
use FibBookingSystem\Core\Domain\Seating\SeatClaimService;
use FibBookingSystem\Core\Domain\Ticket\BookingTicketService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticEntityRepository;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Cancellation releases the booked window (status leaves the availability
 * filter) and revokes the tickets — both inside one transaction, as one bulk
 * write each.
 */
class BookingReservationCancellationTest extends TestCase
{
    private const ORDER_ID = 'f1b0000000000000000000000000e001';

    private BookingTicketService&MockObject $ticketService;

    protected function setUp(): void
    {
        $this->ticketService = $this->createMock(BookingTicketService::class);
    }

    public function testCancelsReservationsAndRevokesTheirTickets(): void
    {
        $reservationIds = ['f1b000000000000000000000000000b1', 'f1b000000000000000000000000000b2'];
        /** @var StaticEntityRepository<\FibBookingSystem\Core\Content\BookingReservation\BookingReservationCollection> $reservationRepository */
        $reservationRepository = new StaticEntityRepository([$reservationIds]);

        $this->ticketService->expects(static::once())
            ->method('revokeForReservations')
            ->with($reservationIds);

        $cancelled = $this->service($reservationRepository)
            ->cancelReservationsForOrder(self::ORDER_ID, Context::createDefaultContext(), 'order cancelled');

        static::assertSame(2, $cancelled);
        static::assertCount(1, $reservationRepository->updates, 'one bulk payload, not one write per reservation');
        static::assertSame(
            [
                ['id' => $reservationIds[0], 'status' => 'cancelled'],
                ['id' => $reservationIds[1], 'status' => 'cancelled'],
            ],
            $reservationRepository->updates[0],
        );
    }

    public function testOrderWithoutReservationsIsANoOp(): void
    {
        /** @var StaticEntityRepository<\FibBookingSystem\Core\Content\BookingReservation\BookingReservationCollection> $reservationRepository */
        $reservationRepository = new StaticEntityRepository([[]]);

        $this->ticketService->expects(static::never())->method('revokeForReservations');

        $cancelled = $this->service($reservationRepository)
            ->cancelReservationsForOrder(self::ORDER_ID, Context::createDefaultContext(), 'order cancelled');

        static::assertSame(0, $cancelled);
        static::assertSame([], $reservationRepository->updates);
    }

    /**
     * @param StaticEntityRepository<\FibBookingSystem\Core\Content\BookingReservation\BookingReservationCollection> $reservationRepository
     */
    private function service(StaticEntityRepository $reservationRepository): BookingReservationService
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn (callable $callback) => $callback());

        /** @var StaticEntityRepository<\Shopware\Core\Checkout\Order\OrderCollection> $orderRepository */
        $orderRepository = new StaticEntityRepository([]);
        /** @var StaticEntityRepository<\Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection> $orderTransactionRepository */
        $orderTransactionRepository = new StaticEntityRepository([]);
        /** @var StaticEntityRepository<\FibBookingSystem\Core\Content\BookingHold\BookingHoldCollection> $holdRepository */
        $holdRepository = new StaticEntityRepository([]);

        return new BookingReservationService(
            $connection,
            $orderRepository,
            $orderTransactionRepository,
            $reservationRepository,
            $holdRepository,
            $this->ticketService,
            $this->createStub(AvailabilityService::class),
            $this->createStub(SeatClaimService::class),
            $this->createStub(NumberRangeValueGeneratorInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            $this->createStub(LoggerInterface::class),
        );
    }
}
