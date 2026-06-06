<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\Booking\SalesChannel;

use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\StoreApiResponse;

/**
 * @extends StoreApiResponse<ArrayStruct<array<string, mixed>>>
 */
class BookingHoldRouteResponse extends StoreApiResponse
{
    /**
     * @param array<string, mixed> $hold
     */
    public function __construct(array $hold)
    {
        parent::__construct(new ArrayStruct($hold, 'fib_booking_hold'));
    }

    /**
     * @return array<string, mixed>
     */
    public function getHold(): array
    {
        return $this->object->all();
    }
}
