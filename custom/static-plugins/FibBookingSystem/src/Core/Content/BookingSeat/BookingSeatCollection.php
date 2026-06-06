<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\BookingSeat;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<BookingSeatEntity>
 */
class BookingSeatCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return BookingSeatEntity::class;
    }
}
