<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\Booking\SalesChannel;

use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\System\SalesChannel\StoreApiResponse;

/**
 * @extends StoreApiResponse<ArrayStruct<array<string, mixed>>>
 */
class BookingSeatmapRouteResponse extends StoreApiResponse
{
    /**
     * @param array<string, mixed> $seatmap
     */
    public function __construct(array $seatmap)
    {
        parent::__construct(new ArrayStruct($seatmap, 'fib_booking_seatmap'));
    }

    /**
     * @return array<string, mixed>
     */
    public function getSeatmap(): array
    {
        return $this->object->all();
    }
}
