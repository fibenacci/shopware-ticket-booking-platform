<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Unit\Reservation;

use Doctrine\DBAL\Connection;
use FibBookingSystem\Core\Domain\Availability\AvailabilityService;
use FibBookingSystem\Core\Domain\Reservation\BookingReservationService;
use FibBookingSystem\Core\Domain\Ticket\BookingTicketService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticEntityRepository;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Every failed hold conversion means a placed (and possibly paid) order
 * WITHOUT a reservation — it must surface in the logs, never silently.
 * Only the idempotent replay (reservation already exists) stays quiet.
 */
class BookingHoldConversionLoggingTest extends TestCase
{
    private const HOLD_ID = 'f1b0000000000000000000000000f001';

    public function testMissingHoldIsLoggedAsError(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(static::once())
            ->method('error')
            ->with(static::stringContains('not found or token mismatch'));

        $connection = $this->createStub(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn (callable $callback) => $callback());
        $connection->method('fetchAssociative')->willReturn(false);

        /** @var StaticEntityRepository<\FibBookingSystem\Core\Content\BookingReservation\BookingReservationCollection> $reservationRepository */
        $reservationRepository = new StaticEntityRepository([]);

        $converted = $this->service($connection, $reservationRepository, $logger)
            ->convertOrderHolds(Uuid::randomHex(), Context::createDefaultContext());

        static::assertSame(0, $converted);
    }

    public function testExpiredHoldIsLoggedAsError(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(static::once())
            ->method('error')
            ->with(static::stringContains('order placed without a reservation'));

        $connection = $this->createStub(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn (callable $callback) => $callback());
        $connection->method('fetchAssociative')->willReturn([
            'id' => self::HOLD_ID,
            'resource_id' => 'f1b0000000000000000000000000c001',
            'status' => 'active',
            'expires_at' => '2020-01-01 00:00:00.000',
            'starts_at' => '2026-12-01 18:00:00.000',
            'ends_at' => '2026-12-01 20:00:00.000',
            'quantity' => 1,
            'payload' => null,
        ]);

        /** @var StaticEntityRepository<\FibBookingSystem\Core\Content\BookingReservation\BookingReservationCollection> $reservationRepository */
        $reservationRepository = new StaticEntityRepository([[]]); // dedupe lookup: no reservation yet

        $converted = $this->service($connection, $reservationRepository, $logger)
            ->convertOrderHolds(Uuid::randomHex(), Context::createDefaultContext());

        static::assertSame(0, $converted);
        static::assertSame([], $reservationRepository->creates, 'an expired hold must not create a reservation');
    }

    public function testIdempotentReplayStaysQuiet(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(static::never())->method('error');
        $logger->expects(static::never())->method('warning');

        $connection = $this->createStub(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn (callable $callback) => $callback());
        $connection->method('fetchAssociative')->willReturn([
            'id' => self::HOLD_ID,
            'resource_id' => 'f1b0000000000000000000000000c001',
            'status' => 'converted',
            'expires_at' => '2099-01-01 00:00:00.000',
            'starts_at' => '2026-12-01 18:00:00.000',
            'ends_at' => '2026-12-01 20:00:00.000',
            'quantity' => 1,
            'payload' => null,
        ]);

        /** @var StaticEntityRepository<\FibBookingSystem\Core\Content\BookingReservation\BookingReservationCollection> $reservationRepository */
        $reservationRepository = new StaticEntityRepository([
            ['f1b0000000000000000000000000b001'], // dedupe lookup: reservation exists
        ]);

        $converted = $this->service($connection, $reservationRepository, $logger)
            ->convertOrderHolds(Uuid::randomHex(), Context::createDefaultContext());

        static::assertSame(0, $converted);
    }

    /**
     * @param StaticEntityRepository<\FibBookingSystem\Core\Content\BookingReservation\BookingReservationCollection> $reservationRepository
     */
    private function service(
        Connection $connection,
        StaticEntityRepository $reservationRepository,
        LoggerInterface $logger,
    ): BookingReservationService {
        /** @var StaticEntityRepository<OrderCollection> $orderRepository */
        $orderRepository = new StaticEntityRepository([
            new OrderCollection([$this->orderWithBookingLineItem()]),
        ]);
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
            $this->createStub(AvailabilityService::class),
            $this->createStub(NumberRangeValueGeneratorInterface::class),
            $this->createStub(EventDispatcherInterface::class),
            $logger,
        );
    }

    private function orderWithBookingLineItem(): OrderEntity
    {
        $lineItem = new OrderLineItemEntity();
        $lineItem->setId(Uuid::randomHex());
        $lineItem->setPayload([
            'fibBooking' => [
                'holdId' => self::HOLD_ID,
                'holdToken' => str_repeat('ab', 32),
            ],
        ]);

        $order = new OrderEntity();
        $order->setId(Uuid::randomHex());
        $order->setOrderNumber('10001');
        $order->setLineItems(new OrderLineItemCollection([$lineItem]));

        return $order;
    }
}
