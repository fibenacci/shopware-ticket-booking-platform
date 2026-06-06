<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Wallet;

use DateTimeImmutable;

/**
 * The booked event window a wallet pass describes: which resource, when,
 * and for how many guests.
 */
final class BookingWindow
{
    public function __construct(
        public readonly string $resourceName,
        public readonly DateTimeImmutable $startsAt,
        public readonly DateTimeImmutable $endsAt,
        public readonly int $quantity,
    ) {
    }
}
