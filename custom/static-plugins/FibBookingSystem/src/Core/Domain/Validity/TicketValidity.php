<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Validity;

use DateTimeImmutable;

/**
 * The resolved validity snapshot stamped onto a ticket at issue time.
 * Later product reconfiguration never changes tickets already sold.
 *
 * For {@see ValidityAnchor::FIRST_USE} both dates stay null — the first
 * successful check-in activates the pass using the snapshotted anchor and
 * duration.
 */
final class TicketValidity
{
    public function __construct(
        public readonly ?DateTimeImmutable $validFrom,
        public readonly ?DateTimeImmutable $expiresAt,
        public readonly string $entryPolicy = EntryPolicy::SINGLE,
        public readonly ?int $maxEntriesPerDay = null,
        public readonly ?string $anchor = null,
        public readonly ?string $duration = null,
    ) {
    }
}
