<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\Booking\SalesChannel;

use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\StoreApiResponse;

/**
 * @extends StoreApiResponse<ArrayStruct<array<string, mixed>>>
 */
class BookingAvailabilityRouteResponse extends StoreApiResponse
{
    /**
     * @param array<string, mixed> $availability
     */
    public function __construct(array $availability)
    {
        parent::__construct(new ArrayStruct($availability, 'fib_booking_availability'));
    }

    /**
     * @return array<string, mixed>
     */
    public function getAvailability(): array
    {
        return $this->object->all();
    }
}
