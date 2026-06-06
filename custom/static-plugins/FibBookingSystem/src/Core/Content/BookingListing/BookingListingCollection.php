<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\BookingListing;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<BookingListingEntity>
 */
class BookingListingCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return BookingListingEntity::class;
    }
}
