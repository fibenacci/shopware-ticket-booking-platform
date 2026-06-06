<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Unit\Checkout;

use FibBookingSystem\Checkout\Payment\BookingPaymentStateSubscriber;
use FibBookingSystem\Core\Domain\Reservation\BookingReservationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineState\StateMachineStateEntity;
use Shopware\Core\System\StateMachine\Event\StateMachineStateChangeEvent;
use Shopware\Core\System\StateMachine\StateMachineEntity;
use Shopware\Core\System\StateMachine\Transition;
use Shopware\Core\Test\Stub\SystemConfigService\StaticSystemConfigService;

/**
 * State routing matrix of the payment subscriber: which order/payment state
 * confirms (issues tickets), which cancels (revokes tickets), and that the
 * partial-payment opt-in and the enter-side filter behave as documented.
 */
class BookingPaymentStateSubscriberTest extends TestCase
{
    private const ENTITY_ID = 'f1b0000000000000000000000000d001';

    private BookingReservationService&MockObject $reservationService;

    protected function setUp(): void
    {
        $this->reservationService = $this->createMock(BookingReservationService::class);
    }

    public function testPaidConfirmsReservations(): void
    {
        $this->reservationService->expects(static::once())
            ->method('confirmReservationsForOrderTransaction')
            ->with(self::ENTITY_ID);
        $this->reservationService->expects(static::never())->method('cancelReservationsForOrderTransaction');

        $this->subscriber()->onOrderTransactionStateChanged($this->transactionEvent('paid'));
    }

    public function testAuthorizedConfirmsReservations(): void
    {
        $this->reservationService->expects(static::once())->method('confirmReservationsForOrderTransaction');

        $this->subscriber()->onOrderTransactionStateChanged($this->transactionEvent('authorized'));
    }

    public function testPartialPaymentDoesNotConfirmByDefault(): void
    {
        $this->reservationService->expects(static::never())->method('confirmReservationsForOrderTransaction');

        $this->subscriber()->onOrderTransactionStateChanged($this->transactionEvent('paid_partially'));
    }

    public function testPartialPaymentConfirmsWhenOperatorOptedIn(): void
    {
        $this->reservationService->expects(static::once())->method('confirmReservationsForOrderTransaction');

        $this->subscriber(ticketsOnPartialPayment: true)
            ->onOrderTransactionStateChanged($this->transactionEvent('paid_partially'));
    }

    public function testRefundCancelsReservations(): void
    {
        $this->reservationService->expects(static::once())
            ->method('cancelReservationsForOrderTransaction')
            ->with(self::ENTITY_ID, static::anything(), 'payment refunded');
        $this->reservationService->expects(static::never())->method('confirmReservationsForOrderTransaction');

        $this->subscriber()->onOrderTransactionStateChanged($this->transactionEvent('refunded'));
    }

    public function testPartialRefundIsDeliberatelyIgnored(): void
    {
        $this->reservationService->expects(static::never())->method('cancelReservationsForOrderTransaction');
        $this->reservationService->expects(static::never())->method('confirmReservationsForOrderTransaction');

        $this->subscriber()->onOrderTransactionStateChanged($this->transactionEvent('refunded_partially'));
    }

    public function testLeaveSideIsIgnored(): void
    {
        // Leaving "refunded" (e.g. transitioning to reopened) reports
        // stateName=refunded on the LEAVE side — reacting would cancel
        // reservations on the way OUT of the refund.
        $this->reservationService->expects(static::never())->method('cancelReservationsForOrderTransaction');
        $this->reservationService->expects(static::never())->method('confirmReservationsForOrderTransaction');

        $this->subscriber()->onOrderTransactionStateChanged(
            $this->transactionEvent('refunded', StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_LEAVE),
        );
    }

    public function testOrderCancelledCancelsReservations(): void
    {
        $this->reservationService->expects(static::once())
            ->method('cancelReservationsForOrder')
            ->with(self::ENTITY_ID, static::anything(), 'order cancelled');

        $this->subscriber()->onOrderStateChanged($this->orderEvent('cancelled'));
    }

    public function testOrderCompletedConfirmsReservations(): void
    {
        $this->reservationService->expects(static::once())
            ->method('confirmReservationsForOrder')
            ->with(self::ENTITY_ID);

        $this->subscriber()->onOrderStateChanged($this->orderEvent('completed'));
    }

    public function testForeignEntityIsIgnored(): void
    {
        $this->reservationService->expects(static::never())->method('confirmReservationsForOrderTransaction');

        $this->subscriber()->onOrderTransactionStateChanged($this->event('order_delivery', 'paid'));
    }

    private function subscriber(bool $ticketsOnPartialPayment = false): BookingPaymentStateSubscriber
    {
        return new BookingPaymentStateSubscriber(
            $this->reservationService,
            new StaticSystemConfigService([
                'FibBookingSystem.config.ticketsOnPartialPayment' => $ticketsOnPartialPayment,
            ]),
        );
    }

    private function transactionEvent(
        string $state,
        string $side = StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER,
    ): StateMachineStateChangeEvent {
        return $this->event('order_transaction', $state, $side);
    }

    private function orderEvent(string $state): StateMachineStateChangeEvent
    {
        return $this->event('order', $state);
    }

    private function event(
        string $entityName,
        string $state,
        string $side = StateMachineStateChangeEvent::STATE_MACHINE_TRANSITION_SIDE_ENTER,
    ): StateMachineStateChangeEvent {
        $previousState = new StateMachineStateEntity();
        $previousState->setTechnicalName($state);

        $nextState = new StateMachineStateEntity();
        $nextState->setTechnicalName($state);

        $stateMachine = new StateMachineEntity();
        $stateMachine->setTechnicalName($entityName . '.state');

        return new StateMachineStateChangeEvent(
            Context::createDefaultContext(),
            $side,
            new Transition($entityName, self::ENTITY_ID, 'test-transition', 'stateId'),
            $stateMachine,
            $previousState,
            $nextState,
        );
    }
}
