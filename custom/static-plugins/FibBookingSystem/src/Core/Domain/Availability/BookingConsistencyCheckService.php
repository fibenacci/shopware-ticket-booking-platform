<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Availability;

use Doctrine\DBAL\Connection;

/**
 * The no-overbooking invariant, verified from the OUTSIDE:
 *
 *   Σ(living holds) + Σ(active reservations) ≤ capacity, per booking window.
 *
 * The transactional paths (hold creation, conversion, resurrection) are built
 * to make a violation impossible — this check is the safety net that turns
 * "should be impossible" into an ALERT when a future code path, a manual DB
 * edit or a migration breaks the math. Every violation is logged as an error
 * by the scheduled task and must page someone; the expected steady state is
 * an empty result.
 *
 * Two shapes of capacity:
 * - Slots ("Termine"): capacity per operator-defined window — one set-based
 *   query over all future active slots.
 * - Slotless resources (free-floating windows): max concurrent usage via a
 *   sweep line over the future windows of each resource.
 *
 * Deliberate raw DBAL (documented exception, see docs/ARCHITECTURE_PLAN.md):
 * read-only set-based aggregation across tables, same pattern as
 * BookingStatisticsService.
 *
 * @phpstan-type Violation array{resourceId: string, window: string, capacity: int, committed: int}
 */
class BookingConsistencyCheckService
{
    private const ACTIVE_RESERVATION_STATUSES = ['pending_payment', 'confirmed', 'completed'];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return list<Violation>
     */
    public function findOversellViolations(): array
    {
        return [...$this->slotViolations(), ...$this->slotlessResourceViolations()];
    }

    /**
     * @return list<Violation>
     */
    private function slotViolations(): array
    {
        /** @var list<array{resource_id: string, starts_at: string, ends_at: string, capacity: int|numeric-string, held: int|numeric-string, reserved: int|numeric-string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT LOWER(HEX(slot.resource_id)) AS resource_id, slot.starts_at, slot.ends_at, slot.capacity,
                (
                    SELECT COALESCE(SUM(h.quantity), 0) FROM fib_booking_hold h
                    WHERE h.resource_id = slot.resource_id
                    AND h.status = 'active'
                    AND h.expires_at > UTC_TIMESTAMP(3)
                    AND h.starts_at < slot.ends_at
                    AND h.ends_at > slot.starts_at
                ) AS held,
                (
                    SELECT COALESCE(SUM(r.quantity), 0) FROM fib_booking_reservation r
                    WHERE r.resource_id = slot.resource_id
                    AND r.status IN (:statuses)
                    AND r.starts_at < slot.ends_at
                    AND r.ends_at > slot.starts_at
                ) AS reserved
                FROM fib_booking_slot slot
                WHERE slot.active = 1
                AND slot.ends_at > UTC_TIMESTAMP(3)
            SQL,
            ['statuses' => self::ACTIVE_RESERVATION_STATUSES],
            ['statuses' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );

        $violations = [];

        foreach ($rows as $row) {
            $committed = (int) $row['held'] + (int) $row['reserved'];

            if ($committed > (int) $row['capacity']) {
                $violations[] = [
                    'resourceId' => $row['resource_id'],
                    'window' => sprintf('%s – %s', $row['starts_at'], $row['ends_at']),
                    'capacity' => (int) $row['capacity'],
                    'committed' => $committed,
                ];
            }
        }

        return $violations;
    }

    /**
     * Sweep line per slotless resource: +quantity at window start, -quantity
     * at window end (end before start on ties — touching windows do not
     * overlap), violation when the running total ever exceeds capacity.
     *
     * @return list<Violation>
     */
    private function slotlessResourceViolations(): array
    {
        /** @var list<array{id: string, capacity: int|numeric-string}> $resources */
        $resources = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT LOWER(HEX(res.id)) AS id, res.capacity
                FROM fib_booking_resource res
                WHERE res.active = 1
                AND NOT EXISTS (
                    SELECT 1 FROM fib_booking_slot s WHERE s.resource_id = res.id AND s.active = 1
                )
            SQL,
        );

        $violations = [];

        foreach ($resources as $resource) {
            $violation = $this->sweepResource($resource['id'], (int) $resource['capacity']);

            if ($violation !== null) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    /**
     * @return Violation|null
     */
    private function sweepResource(
        string $resourceIdHex,
        int $capacity,
    ): ?array {
        /** @var list<array{starts_at: string, ends_at: string, quantity: int|numeric-string}> $windows */
        $windows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT starts_at, ends_at, quantity FROM fib_booking_hold
                WHERE resource_id = :resourceId
                AND status = 'active'
                AND expires_at > UTC_TIMESTAMP(3)
                AND ends_at > UTC_TIMESTAMP(3)
                UNION ALL
                SELECT starts_at, ends_at, quantity FROM fib_booking_reservation
                WHERE resource_id = :resourceId
                AND status IN (:statuses)
                AND ends_at > UTC_TIMESTAMP(3)
            SQL,
            [
                'resourceId' => hex2bin($resourceIdHex),
                'statuses' => self::ACTIVE_RESERVATION_STATUSES,
            ],
            ['statuses' => \Doctrine\DBAL\ArrayParameterType::STRING],
        );

        if ($windows === []) {
            return null;
        }

        /** @var list<array{0: string, 1: int}> $events */
        $events = [];
        foreach ($windows as $window) {
            $events[] = [$window['starts_at'], (int) $window['quantity']];
            $events[] = [$window['ends_at'], -(int) $window['quantity']];
        }

        // Sort by time; on ties the -quantity (window end) comes first, so
        // back-to-back windows never count as overlapping.
        usort($events, static fn (array $left, array $right): int => [$left[0], $left[1]] <=> [$right[0], $right[1]]);

        $running = 0;
        $peak = 0;
        $peakAt = '';

        foreach ($events as [$time, $delta]) {
            $running += $delta;

            if ($running > $peak) {
                $peak = $running;
                $peakAt = $time;
            }
        }

        if ($peak <= $capacity) {
            return null;
        }

        return [
            'resourceId' => $resourceIdHex,
            'window' => sprintf('peak at %s', $peakAt),
            'capacity' => $capacity,
            'committed' => $peak,
        ];
    }
}
