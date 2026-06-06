<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Unit\Reservation;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use FibBookingSystem\Core\Content\BookingReservation\BookingReservationCollection;
use FibBookingSystem\Core\Content\BookingReservation\BookingReservationEntity;
use FibBookingSystem\Core\Domain\Availability\AvailabilityResult;
use FibBookingSystem\Core\Domain\Availability\AvailabilityService;
use FibBookingSystem\Core\Domain\Reservation\BookingReservationService;
use FibBookingSystem\Core\Domain\Seating\SeatClaimService;
use FibBookingSystem\Core\Domain\Seating\SeatmapUpdatePublisher;
use FibBookingSystem\Core\Domain\Ticket\BookingTicketService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticEntityRepository;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Late payments on expired reservations: re-activate when the window is still
 * free (availability re-check under resource lock), otherwise leave expired
 * and alert the operator — never silently confirm into an oversold window.
 */
class BookingReservationResurrectionTest extends TestCase
{
    private const ORDER_ID = 'f1b0000000000000000000000000e101';

    public function testLatePaymentResurrectsWhenWindowIsFree(): void
    {
        $reservation = $this->expiredReservation();

        /** @var StaticEntityRepository<BookingReservationCollection> $reservationRepository */
        $reservationRepository = new StaticEntityRepository([
            new BookingReservationCollection([$reservation]), // resurrect lookup
            [$reservation->getId()],                          // confirm searchIds
            new BookingReservationCollection([$reservation]), // confirm entity reload
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(static::never())->method('error');

        $confirmed = $this->service($reservationRepository, available: true, logger: $logger)
            ->confirmReservationsForOrder(self::ORDER_ID, Context::createDefaultContext());

        static::assertSame(1, $confirmed);
        static::assertSame(
            [['id' => $reservation->getId(), 'status' => 'pending_payment']],
            $reservationRepository->updates[0],
            'resurrection re-enters via pending_payment',
        );
        static::assertSame(
            [['id' => $reservation->getId(), 'status' => 'confirmed']],
            $reservationRepository->updates[1],
        );
    }

    public function testLatePaymentOnGoneWindowStaysExpiredAndAlerts(): void
    {
        $reservation = $this->expiredReservation();

        /** @var StaticEntityRepository<BookingReservationCollection> $reservationRepository */
        $reservationRepository = new StaticEntityRepository([
            new BookingReservationCollection([$reservation]), // resurrect lookup
            [],                                               // confirm searchIds: nothing
        ]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(static::once())
            ->method('error')
            ->with(static::stringContains('no longer available'));

        $confirmed = $this->service($reservationRepository, available: false, logger: $logger)
            ->confirmReservationsForOrder(self::ORDER_ID, Context::createDefaultContext());

        static::assertSame(0, $confirmed);
        static::assertSame([], $reservationRepository->updates, 'a gone window must not be re-activated');
    }

    /**
     * @param StaticEntityRepository<BookingReservationCollection> $reservationRepository
     */
    private function service(
        StaticEntityRepository $reservationRepository,
        bool $available,
        LoggerInterface $logger,
    ): BookingReservationService {
        $connection = $this->createStub(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn (callable $callback) => $callback());
        $connection->method('fetchOne')->willReturn('resource-row'); // resource lock

        $availabilityService = $this->createStub(AvailabilityService::class);
        $availabilityService->method('check')->willReturn(
            new AvailabilityResult($available, 10, $available ? 0 : 10, 1),
        );

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
            $this->createStub(BookingTicketService::class),
            $availabilityService,
            $this->createStub(SeatClaimService::class),
            new SeatmapUpdatePublisher($this->createStub(HubInterface::class), new NullLogger()),
            $this->createStub(NumberRangeValueGeneratorInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            $logger,
        );
    }

    private function expiredReservation(): BookingReservationEntity
    {
        $reservation = new BookingReservationEntity();
        $reservation->setId(Uuid::randomHex());
        $reservation->setResourceId('f1b0000000000000000000000000c001');
        $reservation->setBookingNumber('B-LATE-1');
        $reservation->setStartsAt(new DateTimeImmutable('2026-12-01 18:00:00'));
        $reservation->setEndsAt(new DateTimeImmutable('2026-12-01 20:00:00'));
        $reservation->setQuantity(1);
        $reservation->setStatus('expired');

        return $reservation;
    }
}
