<?php

declare(strict_types=1);

namespace FibBookingSystem\Checkout\Order;

use FibBookingSystem\Core\Domain\Resale\ResaleSettlementService;
use FibBookingSystem\Core\Domain\Reservation\BookingPassReservationService;
use FibBookingSystem\Core\Domain\Reservation\BookingReservationService;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class BookingOrderPlacedSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly BookingReservationService $reservationService,
        private readonly BookingPassReservationService $passReservationService,
        private readonly ResaleSettlementService $resaleSettlement,
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

        // Resale lots: claim them atomically (active → pending) so no second
        // buyer can start a checkout for the same listing (double-sell guard).
        $this->resaleSettlement->claimForOrder($event->getOrderId());
    }
}
