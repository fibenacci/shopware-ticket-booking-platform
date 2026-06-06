<?php

declare(strict_types=1);

namespace FibBookingSystem;

use FibBookingSystem\Core\Domain\Reservation\BookingReservationConfirmedEvent;
use FibBookingSystem\Core\Domain\Ticket\BookingTicketIssuedEvent;
use Shopware\Core\Framework\Plugin;

class FibBookingSystem extends Plugin
{
    protected function getActionEventClasses(): array
    {
        return [
            BookingReservationConfirmedEvent::class,
            BookingTicketIssuedEvent::class,
        ];
    }
}
