<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\BookingScanLog;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<BookingScanLogEntity>
 */
class BookingScanLogCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return BookingScanLogEntity::class;
    }
}
