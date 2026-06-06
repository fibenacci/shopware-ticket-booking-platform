<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\BookingSeatClaim;

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @extends EntityCollection<BookingSeatClaimEntity>
 */
class BookingSeatClaimCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return BookingSeatClaimEntity::class;
    }
}
