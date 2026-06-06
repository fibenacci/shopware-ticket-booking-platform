<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Seating;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use FibBookingSystem\Core\Domain\Time\UtcDateTime;
use FibBookingSystem\FibBookingException;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The seat-claim primitive (docs/SEATING_PLAN.md).
 *
 * Deliberate raw DBAL (documented exception, see docs/ARCHITECTURE_PLAN.md):
 * the whole point of `fib_booking_seat_claim` is that its
 * `UNIQUE (seat_id, slot_id)` decides every race at INSERT time — the claim
 * must run on the SAME connection/transaction as the hold it belongs to, and
 * the duplicate-key outcome IS the business signal ("seat just taken").
 * Releasing and binding are set-based maintenance on the same table.
 */
class SeatClaimService
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Validates the selection against the resource layout and claims every
     * seat for the slot. MUST run inside the hold-creation transaction —
     * a lost race rolls the entire hold back.
     *
     * @param list<string> $seatIds hex UUIDs
     */
    public function claimSeatsForHold(string $holdId, string $resourceId, string $slotId, array $seatIds, int $quantity): void
    {
        $seatIds = array_values(array_unique($seatIds));

        if (count($seatIds) !== $quantity) {
            throw FibBookingException::seatSelectionInvalid(sprintf('%d seat(s) selected but quantity is %d', count($seatIds), $quantity));
        }

        $this->assertSlotBelongsToResource($slotId, $resourceId);
        $this->assertSeatsBelongToResource($seatIds, $resourceId);

        $now = UtcDateTime::now()->format(UtcDateTime::STORAGE_FORMAT);

        foreach ($seatIds as $seatId) {
            try {
                $this->connection->executeStatement(
                    <<<'SQL'
                        INSERT INTO fib_booking_seat_claim (id, seat_id, slot_id, hold_id, created_at)
                        VALUES (:id, :seatId, :slotId, :holdId, :createdAt)
                    SQL,
                    [
                        'id' => Uuid::randomBytes(),
                        'seatId' => Uuid::fromHexToBytes($seatId),
                        'slotId' => Uuid::fromHexToBytes($slotId),
                        'holdId' => Uuid::fromHexToBytes($holdId),
                        'createdAt' => $now,
                    ],
                );
            } catch (UniqueConstraintViolationException) {
                // The race is decided by the unique key — translate the loss
                // into an actionable message listing the contested seats.
                throw FibBookingException::seatsTaken($this->fetchSeatLabels([$seatId]));
            }
        }
    }

    /**
     * Hold → reservation conversion: claims survive, now owned by the
     * reservation. Runs inside the conversion transaction.
     *
     * @return list<string> affected slot ids (hex) — for live seat-map pushes
     */
    public function bindHoldClaimsToReservation(string $holdId, string $reservationId): array
    {
        /** @var list<string> $slotIds */
        $slotIds = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT DISTINCT LOWER(HEX(slot_id)) FROM fib_booking_seat_claim WHERE hold_id = :holdId
            SQL,
            ['holdId' => Uuid::fromHexToBytes($holdId)],
        );

        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE fib_booking_seat_claim
                SET reservation_id = :reservationId, hold_id = NULL
                WHERE hold_id = :holdId
            SQL,
            [
                'reservationId' => Uuid::fromHexToBytes($reservationId),
                'holdId' => Uuid::fromHexToBytes($holdId),
            ],
        );

        return $slotIds;
    }

    /**
     * Frees seats whose hold died without converting (expired or flipped by
     * the scheduled task). Set-based — called by the hold expiration task.
     *
     * @return list<string> affected slot ids (hex) — for live seat-map pushes
     */
    public function releaseOrphanedClaims(): array
    {
        /** @var list<string> $slotIds */
        $slotIds = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT DISTINCT LOWER(HEX(claim.slot_id))
                FROM fib_booking_seat_claim claim
                INNER JOIN fib_booking_hold hold ON hold.id = claim.hold_id
                WHERE claim.reservation_id IS NULL
                AND (hold.status != 'active' OR hold.expires_at <= UTC_TIMESTAMP(3))
            SQL,
        );

        if ($slotIds !== []) {
            $this->connection->executeStatement(
                <<<'SQL'
                    DELETE claim FROM fib_booking_seat_claim claim
                    INNER JOIN fib_booking_hold hold ON hold.id = claim.hold_id
                    WHERE claim.reservation_id IS NULL
                    AND (hold.status != 'active' OR hold.expires_at <= UTC_TIMESTAMP(3))
                SQL,
            );
        }

        return $slotIds;
    }

    /**
     * Human-readable labels ("F7") for error messages and ticket snapshots.
     *
     * @param list<string> $seatIds hex UUIDs
     *
     * @return list<string>
     */
    public function fetchSeatLabels(array $seatIds): array
    {
        if ($seatIds === []) {
            return [];
        }

        /** @var list<string> $labels */
        $labels = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT CONCAT(row_label, seat_label) FROM fib_booking_seat
                WHERE id IN (:seatIds)
                ORDER BY row_label, CAST(seat_label AS UNSIGNED), seat_label
            SQL,
            ['seatIds' => array_map(Uuid::fromHexToBytes(...), $seatIds)],
            ['seatIds' => ArrayParameterType::BINARY],
        );

        return $labels;
    }

    private function assertSlotBelongsToResource(string $slotId, string $resourceId): void
    {
        $belongs = $this->connection->fetchOne(
            <<<'SQL'
                SELECT 1 FROM fib_booking_slot
                WHERE id = :slotId AND resource_id = :resourceId AND active = 1
            SQL,
            [
                'slotId' => Uuid::fromHexToBytes($slotId),
                'resourceId' => Uuid::fromHexToBytes($resourceId),
            ],
        );

        if ($belongs === false) {
            throw FibBookingException::seatSelectionInvalid('the slot does not belong to the resource');
        }
    }

    /**
     * @param list<string> $seatIds hex UUIDs
     */
    private function assertSeatsBelongToResource(array $seatIds, string $resourceId): void
    {
        $found = $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) FROM fib_booking_seat
                WHERE id IN (:seatIds) AND resource_id = :resourceId AND active = 1
            SQL,
            [
                'seatIds' => array_map(Uuid::fromHexToBytes(...), $seatIds),
                'resourceId' => Uuid::fromHexToBytes($resourceId),
            ],
            ['seatIds' => ArrayParameterType::BINARY],
        );

        if (!is_numeric($found) || (int) $found !== count($seatIds)) {
            throw FibBookingException::seatSelectionInvalid('unknown or inactive seats for this resource');
        }
    }
}
