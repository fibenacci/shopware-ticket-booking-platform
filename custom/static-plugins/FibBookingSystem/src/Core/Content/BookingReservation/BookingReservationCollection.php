<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\BookingReservation;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<BookingReservationEntity>
 */
class BookingReservationCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return BookingReservationEntity::class;
    }
}
