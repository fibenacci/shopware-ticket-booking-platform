<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Reservation;

use Doctrine\DBAL\Connection;

/**
 * Expires stale `pending_payment` reservations — the inventory-leak guard:
 * an abandoned async payment (prepayment, PayPal bounce) would otherwise
 * block its booking window FOREVER, because pending reservations count
 * against availability.
 *
 * The TTL must be generous (default 72h, see `pendingReservationTtlHours`):
 * prepayment legitimately takes days. A payment that still arrives after
 * expiry is resurrected with an availability re-check — see
 * BookingReservationService::confirmReservationsForOrder().
 *
 * Deliberate raw DBAL (documented exception, see docs/ARCHITECTURE_PLAN.md):
 * scheduled-task maintenance flips expired reservations in one set-based
 * UPDATE — same pattern as BookingHoldExpirationService.
 */
class BookingReservationExpirationService
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function expireOverduePendingReservations(int $ttlHours): int
    {
        if ($ttlHours <= 0) {
            return 0; // 0 disables the expiry entirely
        }

        return (int) $this->connection->executeStatement(
            <<<'SQL'
                UPDATE fib_booking_reservation
                SET status = 'expired', updated_at = UTC_TIMESTAMP(3)
                WHERE status = 'pending_payment'
                AND created_at <= UTC_TIMESTAMP(3) - INTERVAL :ttlHours HOUR
            SQL,
            ['ttlHours' => $ttlHours],
        );
    }
}
