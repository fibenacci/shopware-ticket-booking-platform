<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Unit\Flow;

use FibBookingSystem\Core\Domain\Reservation\BookingReservationAware;
use FibBookingSystem\Core\Domain\Ticket\BookingTicketService;
use FibBookingSystem\Flow\Action\IssueBookingTicketAction;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Flow\Dispatching\StorableFlow;
use Shopware\Core\Framework\Context;

class IssueBookingTicketActionTest extends TestCase
{
    public function testIssuesTicketFromStoredReservationId(): void
    {
        $ticketService = $this->createMock(BookingTicketService::class);
        $ticketService->expects(static::once())
            ->method('issueTicket')
            ->with('reservation-id');

        $flow = new StorableFlow(IssueBookingTicketAction::getName(), Context::createDefaultContext(), [
            BookingReservationAware::RESERVATION_ID => 'reservation-id',
        ]);

        (new IssueBookingTicketAction($ticketService))->handleFlow($flow);
    }
}
