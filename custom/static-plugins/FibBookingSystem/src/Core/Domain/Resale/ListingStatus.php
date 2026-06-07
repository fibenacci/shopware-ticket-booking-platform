<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Resale;

/**
 * Listing lifecycle (docs/RESELL_PLAN.md). Settled listings keep their
 * snapshot (sold_* columns); `active` AND `pending` listings hold the unique
 * active_ticket_id guard (the ticket stays unlistable while a buyer order is
 * in flight).
 *
 * active → pending (buyer order placed, atomic claim)
 *        → sold    (payment settled)   | back to active (order cancelled)
 *        → cancelled (seller withdraws an ACTIVE listing, ticket scanned,
 *                     or settlement conflict)
 */
final class ListingStatus
{
    public const ACTIVE = 'active';
    public const PENDING = 'pending';
    public const SOLD = 'sold';
    public const CANCELLED = 'cancelled';
    public const EXPIRED = 'expired';

    private function __construct()
    {
    }
}
