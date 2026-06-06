<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Unit\Flow;

use FibBookingSystem\Core\Domain\Reservation\BookingReservationConfirmedEvent;
use FibBookingSystem\Core\Domain\Ticket\BookingTicket;
use FibBookingSystem\Core\Domain\Ticket\BookingTicketIssuedEvent;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\FlowEventAware;

class BookingFlowEventTest extends TestCase
{
    public function testReservationConfirmedEventIsFlowAwareAndExposesScalarValues(): void
    {
        $event = new BookingReservationConfirmedEvent('reservation-id', 'B10000', Context::createDefaultContext());

        static::assertInstanceOf(FlowEventAware::class, $event);
        static::assertSame(BookingReservationConfirmedEvent::EVENT_NAME, $event->getName());
        static::assertSame([
            'reservationId' => 'reservation-id',
            'bookingNumber' => 'B10000',
        ], $event->getValues());
        static::assertArrayHasKey('reservationId', BookingReservationConfirmedEvent::getAvailableData()->toArray());
    }

    public function testTicketIssuedEventIsFlowAwareAndExposesScalarValues(): void
    {
        $ticket = new BookingTicket('ticket-id', 'T10000', 'scan-token', '{"scanToken":"scan-token"}', 'data:image/png;base64,abc');
        $event = new BookingTicketIssuedEvent('reservation-id', $ticket, Context::createDefaultContext());

        static::assertInstanceOf(FlowEventAware::class, $event);
        static::assertSame(BookingTicketIssuedEvent::EVENT_NAME, $event->getName());
        static::assertSame([
            'reservationId' => 'reservation-id',
            'ticketId' => 'ticket-id',
            'ticketNumber' => 'T10000',
            'qrPayload' => '{"scanToken":"scan-token"}',
        ], $event->getValues());
        static::assertArrayHasKey('ticketNumber', BookingTicketIssuedEvent::getAvailableData()->toArray());
    }
}
