<?php

declare(strict_types=1);

namespace FibBookingSystem\Checkout\Payment;

use FibBookingSystem\Core\Domain\Reservation\BookingReservationService;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Routes order/payment state changes into the reservation lifecycle:
 *
 * - paid / authorized            → confirm reservations, issue tickets
 * - paid_partially               → confirm ONLY when the operator opted in via
 *                                  `ticketsOnPartialPayment` (default off — an
 *                                  entry ticket on a down payment is a fraud
 *                                  vector: pay a part, enter, never settle)
 * - order completed/in_progress  → confirm (manual fulfilment without payment
 *                                  state, e.g. POS / invoice workflows)
 * - refunded / order cancelled   → cancel reservations + revoke tickets; the
 *                                  window returns to the availability pool
 *
 * `refunded_partially` is deliberately NOT handled: which of the order's
 * tickets a partial refund voids is an operator decision (admin), not
 * something the state machine can infer.
 *
 * Only the ENTER side of a transition is processed: Shopware dispatches the
 * same event name for leave AND enter, and on the leave side getStateName()
 * is the state being LEFT — reacting to it would e.g. cancel reservations
 * when a payment transitions AWAY from refunded.
 */
class BookingPaymentStateSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly BookingReservationService $reservationService,
        private readonly SystemConfigService $systemConfig,
    ) {
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
        if ($event->getTransition()->getEntityName() !== 'order_transaction' || !$this->isEnterSide($event)) {
            return;
        }

        $state = $event->getStateName();

        if (in_array($state, $this->confirmingTransactionStates(), true)) {
            $this->reservationService->confirmReservationsForOrderTransaction($event->getTransition()->getEntityId(), $event->getContext());

            return;
        }

        if ($state === 'refunded') {
            $this->reservationService->cancelReservationsForOrderTransaction(
                $event->getTransition()->getEntityId(),
                $event->getContext(),
                'payment refunded',
            );
        }
    }

    public function onOrderStateChanged(StateMachineStateChangeEvent $event): void
    {
        if ($event->getTransition()->getEntityName() !== 'order' || !$this->isEnterSide($event)) {
            return;
        }

        $state = $event->getStateName();

        if (in_array($state, ['completed', 'in_progress'], true)) {
            $this->reservationService->confirmReservationsForOrder($event->getTransition()->getEntityId(), $event->getContext());

            return;
        }

        if ($state === 'cancelled') {
            $this->reservationService->cancelReservationsForOrder(
                $event->getTransition()->getEntityId(),
                $event->getContext(),
                'order cancelled',
            );
        }
    }

    private function isEnterSide(StateMachineStateChangeEvent $event): bool
    {
        return $event->getTransitionSide() === StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER;
    }

    /**
     * @return list<string>
     */
    private function confirmingTransactionStates(): array
    {
        $states = ['paid', 'authorized'];

        if ((bool) $this->systemConfig->get('FibBookingSystem.config.ticketsOnPartialPayment')) {
            $states[] = 'paid_partially';
        }

        return $states;
    }
}
