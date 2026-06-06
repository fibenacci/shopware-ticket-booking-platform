<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Reservation;

use Doctrine\DBAL\Connection;

/**
 * Deliberate raw DBAL (documented exception, see docs/ARCHITECTURE_PLAN.md):
 * scheduled-task maintenance flips expired holds in one set-based UPDATE —
 * same pattern Shopware core uses for cleanup tasks. Holds are not part of
 * any cache/index, so skipping DAL write events has no observable effect;
 * availability is computed live with `expires_at` predicates either way.
 */
class BookingHoldExpirationService
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function expireOverdueHolds(): int
    {
        return (int) $this->connection->executeStatement(
            <<<'SQL'
                UPDATE fib_booking_hold
                SET status = 'expired', updated_at = UTC_TIMESTAMP(3)
                WHERE status = 'active'
                AND expires_at <= UTC_TIMESTAMP(3)
            SQL,
        );
    }
}
