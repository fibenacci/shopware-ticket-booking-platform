<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Validity;

/**
 * HOW OFTEN a ticket can be used while it is valid.
 */
final class EntryPolicy
{
    /** Consumed by the first scan (event ticket). */
    public const SINGLE = 'single';

    /** Re-usable for the whole validity window (pass), optionally capped per day. */
    public const MULTI = 'multi';

    public const ALL = [self::SINGLE, self::MULTI];

    private function __construct()
    {
    }
}
