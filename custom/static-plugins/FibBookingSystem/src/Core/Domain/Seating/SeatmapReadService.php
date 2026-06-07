<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Seating;

use Doctrine\DBAL\Connection;
use FibBookingSystem\FibBookingException;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Read model for the storefront seat picker: the room layout joined with the
 * claim state for ONE slot.
 *
 * Deliberate raw DBAL (documented exception, see docs/ARCHITECTURE_PLAN.md):
 * a single joined aggregate the DAL cannot express — and the freshness rules
 * (live holds only) must match the claim primitive exactly.
 */
class SeatmapReadService
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array{slotId: string, resourceId: string, layout: array<string, mixed>|null, seats: list<array{id: string, row: string, label: string, x: int, y: int, rotation: int, category: string|null, state: string}>}
     */
    public function getSeatmap(string $slotId): array
    {
        /** @var array{id: string, resource_id: string, seating_mode: string, layout: string|null}|false $slot */
        $slot = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT LOWER(HEX(slot.id)) AS id, LOWER(HEX(slot.resource_id)) AS resource_id,
                resource.seating_mode, resource.layout
                FROM fib_booking_slot slot
                INNER JOIN fib_booking_resource resource ON resource.id = slot.resource_id
                WHERE slot.id = :slotId AND slot.active = 1
            SQL,
            ['slotId' => Uuid::fromHexToBytes($slotId)],
        );

        if ($slot === false) {
            throw FibBookingException::resourceNotFound();
        }

        if ($slot['seating_mode'] !== SeatingMode::SEATMAP) {
            throw FibBookingException::seatSelectionInvalid('this resource has no seat map');
        }

        // Optional free-form layout (decorations + category palette). Null →
        // the storefront picker falls back to the plain grid.
        $layout = null;
        if (is_string($slot['layout']) && $slot['layout'] !== '') {
            $decoded = json_decode($slot['layout'], true);
            /** @var array<string, mixed>|null $layout */
            $layout = is_array($decoded) ? $decoded : null;
        }

        // State priority: sold (reservation) > held (LIVING hold) > free.
        // Claims of dead holds count as free — the cleanup task merely
        // garbage-collects what this predicate already ignores. NOTE: a
        // re-claim of such a stale seat still loses the unique-key insert
        // until the cleanup ran; the storefront treats SEATS_TAKEN on a
        // "free" seat as a refresh signal.
        /** @var list<array{id: string, row: string, label: string, x: int|string, y: int|string, rotation: int|string, category: string|null, state: string}> $seats */
        $seats = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT LOWER(HEX(seat.id)) AS id,
                seat.row_label AS `row`,
                seat.seat_label AS label,
                seat.pos_x AS x,
                seat.pos_y AS y,
                seat.rotation,
                seat.category,
                CASE
                    WHEN claim.reservation_id IS NOT NULL THEN 'sold'
                    WHEN hold.id IS NOT NULL AND hold.status = 'active' AND hold.expires_at > UTC_TIMESTAMP(3) THEN 'held'
                    ELSE 'free'
                END AS state
                FROM fib_booking_seat seat
                LEFT JOIN fib_booking_seat_claim claim
                ON claim.seat_id = seat.id AND claim.slot_id = :slotId
                LEFT JOIN fib_booking_hold hold ON hold.id = claim.hold_id
                WHERE seat.resource_id = UNHEX(:resourceId) AND seat.active = 1
                ORDER BY seat.pos_y, seat.pos_x
            SQL,
            [
                'slotId' => Uuid::fromHexToBytes($slotId),
                'resourceId' => $slot['resource_id'],
            ],
        );

        return [
            'slotId' => $slot['id'],
            'resourceId' => $slot['resource_id'],
            'layout' => $layout,
            'seats' => array_map(static fn (array $seat): array => [
                'id' => $seat['id'],
                'row' => $seat['row'],
                'label' => $seat['label'],
                'x' => (int) $seat['x'],
                'y' => (int) $seat['y'],
                'rotation' => (int) $seat['rotation'],
                'category' => $seat['category'],
                'state' => $seat['state'],
            ], $seats),
        ];
    }
}
