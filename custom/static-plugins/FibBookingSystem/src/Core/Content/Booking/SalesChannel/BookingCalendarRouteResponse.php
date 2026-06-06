<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\Booking\SalesChannel;

use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\StoreApiResponse;

/**
 * @extends StoreApiResponse<ArrayStruct<array<string, mixed>>>
 */
class BookingCalendarRouteResponse extends StoreApiResponse
{
    /**
     * @param array<string, mixed> $calendar
     */
    public function __construct(array $calendar)
    {
        parent::__construct(new ArrayStruct($calendar, 'fib_booking_calendar'));
    }

    /**
     * @return array<string, mixed>
     */
    public function getCalendar(): array
    {
        return $this->object->all();
    }
}
