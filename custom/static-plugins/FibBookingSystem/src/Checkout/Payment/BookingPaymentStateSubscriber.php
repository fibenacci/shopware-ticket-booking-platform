<?php

declare(strict_types=1);

namespace FibBookingSystem\Checkout\Payment;

use FibBookingSystem\Core\Domain\Reservation\BookingReservationService;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class BookingPaymentStateSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly BookingReservationService $reservationService)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'state_machine.order_transaction.state_changed' => 'onOrderTransactionStateChanged',
            'state_machine.order.state_changed' => 'onOrderStateChanged',
        ];
    }

    public function onOrderTransactionStateChanged(StateMachineStateChangeEvent $event): void
    {
        if ($event->getTransition()->getEntityName() !== 'order_transaction') {
            return;
        }

        if (!in_array($event->getStateName(), ['paid', 'paid_partially', 'authorized'], true)) {
            return;
        }

        $this->reservationService->confirmReservationsForOrderTransaction($event->getTransition()->getEntityId(), $event->getContext());
    }

    public function onOrderStateChanged(StateMachineStateChangeEvent $event): void
    {
        if ($event->getTransition()->getEntityName() !== 'order') {
            return;
        }

        if (!in_array($event->getStateName(), ['completed', 'in_progress'], true)) {
            return;
        }

        $this->reservationService->confirmReservationsForOrder($event->getTransition()->getEntityId(), $event->getContext());
    }
}
