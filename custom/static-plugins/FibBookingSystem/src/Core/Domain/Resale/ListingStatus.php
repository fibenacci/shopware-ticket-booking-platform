<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Resale;

/**
 * Listing lifecycle (docs/RESELL_PLAN.md). Settled listings keep their
 * snapshot (sold_* columns); only `active` listings hold the unique
 * active_ticket_id guard.
 */
final class ListingStatus
{
    public const ACTIVE = 'active';
    public const SOLD = 'sold';
    public const CANCELLED = 'cancelled';
    public const EXPIRED = 'expired';

    private function __construct()
    {
    }
}
