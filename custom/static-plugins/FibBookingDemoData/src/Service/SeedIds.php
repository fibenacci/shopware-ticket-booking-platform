<?php

declare(strict_types=1);

namespace FibBookingDemoData\Service;

/**
 * Deterministic ids derived from stable seed strings — re-running the seeders
 * upserts instead of duplicating.
 */
final class SeedIds
{
    public static function stable(string $seed): string
    {
        return md5('fib-booking-demo:' . $seed);
    }
}
