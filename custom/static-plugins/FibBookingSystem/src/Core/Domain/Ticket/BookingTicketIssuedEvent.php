<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Ticket;

use Shopware\Core\Content\Flow\Dispatching\Aware\ScalarValuesAware;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\EventData\EventDataCollection;
use Shopware\Core\Framework\Event\EventData\ScalarValueType;
use Shopware\Core\Framework\Event\FlowEventAware;
use Shopware\Core\Framework\Event\IsFlowEventAware;
use Symfony\Contracts\EventDispatcher\Event;

#[IsFlowEventAware]
class BookingTicketIssuedEvent extends Event implements FlowEventAware, ScalarValuesAware
{
    public const EVENT_NAME = 'fib_booking.ticket.issued';

    public function __construct(
        private readonly string $reservationId,
        private readonly BookingTicket $ticket,
        private readonly Context $context,
    ) {
    }

    public function getReservationId(): string
    {
        return $this->reservationId;
    }

    public function getName(): string
    {
        return self::EVENT_NAME;
    }

    public static function getAvailableData(): EventDataCollection
    {
        return (new EventDataCollection())
            ->add('reservationId', new ScalarValueType(ScalarValueType::TYPE_STRING))
            ->add('ticketId', new ScalarValueType(ScalarValueType::TYPE_STRING))
            ->add('ticketNumber', new ScalarValueType(ScalarValueType::TYPE_STRING))
            ->add('qrPayload', new ScalarValueType(ScalarValueType::TYPE_STRING));
    }

    public function getValues(): array
    {
        return [
            'reservationId' => $this->reservationId,
            'ticketId' => $this->ticket->getId(),
            'ticketNumber' => $this->ticket->getTicketNumber(),
            'qrPayload' => $this->ticket->getQrPayload(),
        ];
    }

    public function getTicket(): BookingTicket
    {
        return $this->ticket;
    }

    public function getContext(): Context
    {
        return $this->context;
    }
}
