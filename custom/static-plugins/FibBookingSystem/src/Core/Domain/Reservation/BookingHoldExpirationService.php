<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Reservation;

use Doctrine\DBAL\Connection;
use FibBookingSystem\Core\Domain\Seating\SeatClaimService;
use FibBookingSystem\Core\Domain\Seating\SeatmapUpdatePublisher;

/**
 * Deliberate raw DBAL (documented exception, see docs/ARCHITECTURE_PLAN.md):
 * scheduled-task maintenance flips expired holds in one set-based UPDATE —
 * same pattern Shopware core uses for cleanup tasks. Holds are not part of
 * any cache/index, so skipping DAL write events has no observable effect;
 * availability is computed live with `expires_at` predicates either way.
 */
class BookingHoldExpirationService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SeatClaimService $seatClaimService,
        private readonly SeatmapUpdatePublisher $seatmapPublisher,
    ) {
    }

    public function expireOverdueHolds(): int
    {
        $expired = (int) $this->connection->executeStatement(
            <<<'SQL'
                UPDATE fib_booking_hold
                SET status = 'expired', updated_at = UTC_TIMESTAMP(3)
                WHERE status = 'active'
                AND expires_at <= UTC_TIMESTAMP(3)
            SQL,
        );

        // Seats of dead holds become claimable again. The seatmap read model
        // already ignores these claims via its liveness predicate — this is
        // garbage collection, not the correctness boundary.
        $releasedSlotIds = $this->seatClaimService->releaseOrphanedClaims();

        // Live seat maps: freed seats appear without waiting for the poll.
        foreach ($releasedSlotIds as $slotId) {
            $this->seatmapPublisher->defer($slotId);
        }
        $this->seatmapPublisher->flush();

        return $expired;
    }
}
