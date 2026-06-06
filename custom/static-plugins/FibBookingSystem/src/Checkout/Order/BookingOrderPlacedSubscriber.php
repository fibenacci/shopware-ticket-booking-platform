<?php

declare(strict_types=1);

namespace FibBookingSystem\Checkout\Order;

use FibBookingSystem\Core\Domain\Reservation\BookingPassReservationService;
use FibBookingSystem\Core\Domain\Reservation\BookingReservationService;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class BookingOrderPlacedSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly BookingReservationService $reservationService,
        private readonly BookingPassReservationService $passReservationService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutOrderPlacedEvent::class => 'onOrderPlaced',
        ];
    }

    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        // Slot products: convert the calendar holds created in the cart.
        $this->reservationService->convertOrderHolds($event->getOrderId(), $event->getContext());

        // Pass products (period/unlimited validity): no hold, no slot — the
        // reservation is derived from the product's booking config.
        $this->passReservationService->createForOrder($event->getOrderId(), $event->getContext());
    }
}
