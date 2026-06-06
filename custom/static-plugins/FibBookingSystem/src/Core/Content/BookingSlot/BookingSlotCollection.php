<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\BookingSlot;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<BookingSlotEntity>
 */
class BookingSlotCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return BookingSlotEntity::class;
    }
}
