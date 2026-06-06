<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\BookingTicket;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<BookingTicketEntity>
 */
class BookingTicketCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return BookingTicketEntity::class;
    }
}
