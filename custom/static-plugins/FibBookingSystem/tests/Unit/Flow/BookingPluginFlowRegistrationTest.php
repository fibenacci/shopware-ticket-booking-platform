<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Unit\Flow;

use FibBookingSystem\Core\Domain\Reservation\BookingReservationConfirmedEvent;
use FibBookingSystem\Core\Domain\Ticket\BookingTicketIssuedEvent;
use FibBookingSystem\FibBookingSystem;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class BookingPluginFlowRegistrationTest extends TestCase
{
    public function testPluginRegistersBookingFlowEvents(): void
    {
        $plugin = (new ReflectionClass(FibBookingSystem::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(FibBookingSystem::class, 'getActionEventClasses');
        $method->setAccessible(true);

        $events = $method->invoke($plugin);

        static::assertContains(BookingReservationConfirmedEvent::class, $events);
        static::assertContains(BookingTicketIssuedEvent::class, $events);
    }
}
