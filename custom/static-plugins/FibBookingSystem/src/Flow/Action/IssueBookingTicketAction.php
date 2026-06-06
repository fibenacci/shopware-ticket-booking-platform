<?php

declare(strict_types=1);

namespace FibBookingSystem\Flow\Action;

use FibBookingSystem\Core\Domain\Reservation\BookingReservationAware;
use FibBookingSystem\Core\Domain\Ticket\BookingTicketService;
use RuntimeException;
use Shopware\Core\Content\Flow\Dispatching\Action\FlowAction;
use Shopware\Core\Content\Flow\Dispatching\StorableFlow;

class IssueBookingTicketAction extends FlowAction
{
    public function __construct(private readonly BookingTicketService $ticketService)
    {
    }

    public static function getName(): string
    {
        return 'action.fib_booking.issue_ticket';
    }

    public function requirements(): array
    {
        return [BookingReservationAware::class];
    }

    public function handleFlow(StorableFlow $flow): void
    {
        $reservationId = $flow->getStore(BookingReservationAware::RESERVATION_ID)
            ?? $flow->getData(BookingReservationAware::RESERVATION_ID);

        if (!is_string($reservationId) || $reservationId === '') {
            return;
        }

        try {
            $this->ticketService->issueTicket($reservationId, $flow->getContext());
        } catch (RuntimeException) {
            // The action is idempotent for retried flows and already issued tickets.
        }
    }
}
