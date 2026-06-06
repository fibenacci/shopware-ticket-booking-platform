<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\BookingBid;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<BookingBidEntity>
 */
class BookingBidCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return BookingBidEntity::class;
    }
}
