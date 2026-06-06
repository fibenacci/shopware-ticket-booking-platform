<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\BookingHold;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<BookingHoldEntity>
 */
class BookingHoldCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return BookingHoldEntity::class;
    }
}
