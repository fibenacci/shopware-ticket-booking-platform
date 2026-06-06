<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Reservation;

use DateTimeInterface;

/**
 * Everything a hold needs, as one value object — the request grew past a
 * readable argument list when seat selections joined the party.
 *
 * @phpstan-type HoldPayload array<string, mixed>
 */
final class BookingHoldRequest
{
    /**
     * @param array<string, mixed> $payload
     * @param list<string>         $seatIds seatmap resources only: the picked seats
     *                                      (count must equal $quantity); requires
     *                                      `slotId` in $payload
     */
    public function __construct(
        public readonly string $resourceId,
        public readonly DateTimeInterface $startsAt,
        public readonly DateTimeInterface $endsAt,
        public readonly int $quantity,
        public readonly ?string $salesChannelId = null,
        public readonly ?string $customerId = null,
        public readonly array $payload = [],
        public readonly int $ttlMinutes = 15,
        public readonly array $seatIds = [],
    ) {
    }
}
