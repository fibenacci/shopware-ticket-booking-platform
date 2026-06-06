<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Validity;

/**
 * WHEN a ticket is valid. Together with {@see EntryPolicy} this spans every
 * ticket kind without hardcoding types: event ticket = slot × single,
 * monthly transit pass = period(P1M) × multi, season ticket = period(P1Y) ×
 * multi, simple admission voucher = unlimited × single, ….
 */
final class ValidityMode
{
    /** Bound to a booked calendar slot (Termin) — the original model. */
    public const SLOT = 'slot';

    /** Valid for an ISO-8601 duration from an anchor point. */
    public const PERIOD = 'period';

    /** Never expires. */
    public const UNLIMITED = 'unlimited';

    public const ALL = [self::SLOT, self::PERIOD, self::UNLIMITED];

    private function __construct()
    {
    }
}
