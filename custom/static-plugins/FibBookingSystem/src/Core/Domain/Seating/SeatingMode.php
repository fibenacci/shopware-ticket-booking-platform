<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Seating;

/**
 * How a resource's capacity is sold:
 *
 * - pool: a counter — N interchangeable places per slot (default; concerts,
 *   escape rooms, workshops, transit). Guarded by the FOR UPDATE pool check.
 * - seatmap: numbered seats (cinema, theater). Guarded by the seat-claim
 *   unique constraint instead of a lock — see docs/SEATING_PLAN.md.
 */
final class SeatingMode
{
    public const POOL = 'pool';
    public const SEATMAP = 'seatmap';

    public const ALL = [self::POOL, self::SEATMAP];

    private function __construct()
    {
    }
}
