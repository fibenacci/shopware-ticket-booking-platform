<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\BookingResource;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<BookingResourceEntity>
 */
class BookingResourceCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return BookingResourceEntity::class;
    }
}
