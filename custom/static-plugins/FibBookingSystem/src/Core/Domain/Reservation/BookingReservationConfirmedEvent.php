<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Reservation;

use Shopware\Core\Content\Flow\Dispatching\Aware\ScalarValuesAware;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Event\EventData\EventDataCollection;
use Shopware\Core\Framework\Event\EventData\ScalarValueType;
use Shopware\Core\Framework\Event\FlowEventAware;
use Shopware\Core\Framework\Event\IsFlowEventAware;
use Symfony\Contracts\EventDispatcher\Event;

#[IsFlowEventAware]
class BookingReservationConfirmedEvent extends Event implements FlowEventAware, ScalarValuesAware, BookingReservationAware
{
    public const EVENT_NAME = 'fib_booking.reservation.confirmed';

    public function __construct(
        private readonly string $reservationId,
        private readonly string $bookingNumber,
        private readonly Context $context,
    ) {
    }

    public function getName(): string
    {
        return self::EVENT_NAME;
    }

    public static function getAvailableData(): EventDataCollection
    {
        return (new EventDataCollection())
            ->add('reservationId', new ScalarValueType(ScalarValueType::TYPE_STRING))
            ->add('bookingNumber', new ScalarValueType(ScalarValueType::TYPE_STRING));
    }

    public function getValues(): array
    {
        return [
            self::RESERVATION_ID => $this->reservationId,
            'bookingNumber' => $this->bookingNumber,
        ];
    }

    public function getContext(): Context
    {
        return $this->context;
    }
}
