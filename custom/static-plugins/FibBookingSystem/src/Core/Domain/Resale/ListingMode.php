<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Resale;

/**
 * How a listing finds its price (docs/RESELL_PLAN.md).
 */
final class ListingMode
{
    public const FIXED_PRICE = 'fixed_price';
    public const AUCTION = 'auction';

    public const ALL = [self::FIXED_PRICE, self::AUCTION];

    private function __construct()
    {
    }
}
