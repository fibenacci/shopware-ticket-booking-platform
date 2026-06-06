<?php

declare(strict_types=1);

namespace FibBookingSystem\Checkout\Order;

use FibBookingSystem\Core\Domain\Reservation\BookingReservationService;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class BookingOrderPlacedSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly BookingReservationService $reservationService)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            CheckoutOrderPlacedEvent::class => 'onOrderPlaced',
        ];
    }

    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        $this->reservationService->convertOrderHolds($event->getOrderId(), $event->getContext());
    }
}
