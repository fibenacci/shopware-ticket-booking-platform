<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Availability;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Month view over a resource's slots ("Termine") for the storefront calendar.
 *
 * Day status drives the calendar colors:
 * - "free":    every slot of the day still has remaining capacity
 * - "partial": some slots are sold out
 * - "full":    ALL slots of the day are sold out  → rendered red
 * - "none":    the day has no slots               → not bookable
 *
 * Deliberate raw DBAL (documented exception, see docs/ARCHITECTURE_PLAN.md):
 * the month view is a read model — slots joined against per-window hold and
 * reservation SUM subqueries. The DAL cannot express joined aggregate
 * subqueries; per-slot DAL aggregations would be an N+1 on a public endpoint.
 */
class BookingCalendarService
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array{resourceId: string, month: string, days: list<array<string, mixed>>}
     */
    public function getMonth(string $resourceId, DateTimeImmutable $month): array
    {
        $resourceBytes = Uuid::fromHexToBytes($resourceId);
        $from = $month->modify('first day of this month')->setTime(0, 0);
        $to = $from->modify('first day of next month');

        /** @var list<array{id: string, starts_at: string, ends_at: string, capacity: int|numeric-string, booked: int|numeric-string}> $slots */
        $slots = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT LOWER(HEX(slot.id)) AS id, slot.starts_at, slot.ends_at, slot.capacity,
                COALESCE(holds.quantity, 0) + COALESCE(reservations.quantity, 0) AS booked
                FROM fib_booking_slot slot
                LEFT JOIN (
                SELECT resource_id, starts_at, ends_at, SUM(quantity) AS quantity
                FROM fib_booking_hold
                WHERE status = 'active' AND expires_at > UTC_TIMESTAMP(3)
                GROUP BY resource_id, starts_at, ends_at
                ) holds ON holds.resource_id = slot.resource_id
                AND holds.starts_at < slot.ends_at AND holds.ends_at > slot.starts_at
                LEFT JOIN (
                SELECT resource_id, starts_at, ends_at, SUM(quantity) AS quantity
                FROM fib_booking_reservation
                WHERE status IN ('pending_payment', 'confirmed', 'completed')
                GROUP BY resource_id, starts_at, ends_at
                ) reservations ON reservations.resource_id = slot.resource_id
                AND reservations.starts_at < slot.ends_at AND reservations.ends_at > slot.starts_at
                WHERE slot.resource_id = :resourceId
                AND slot.active = 1
                AND slot.starts_at >= :from
                AND slot.starts_at < :to
                ORDER BY slot.starts_at
            SQL,
            [
                'resourceId' => $resourceBytes,
                'from' => $from->format('Y-m-d H:i:s.v'),
                'to' => $to->format('Y-m-d H:i:s.v'),
            ],
        );

        $days = [];
        foreach ($slots as $slot) {
            $startsAt = new DateTimeImmutable($slot['starts_at']);
            $endsAt = new DateTimeImmutable($slot['ends_at']);
            $capacity = (int) $slot['capacity'];
            $available = max(0, $capacity - (int) $slot['booked']);
            $day = $startsAt->format('Y-m-d');

            $days[$day] ??= ['date' => $day, 'slots' => []];
            $days[$day]['slots'][] = [
                'id' => $slot['id'],
                'startsAt' => $this->formatAtom($startsAt),
                'endsAt' => $this->formatAtom($endsAt),
                'capacity' => $capacity,
                'available' => $available,
            ];
        }

        foreach ($days as &$day) {
            $availableSlots = count(array_filter($day['slots'], static fn (array $slot): bool => $slot['available'] > 0));
            $day['status'] = match (true) {
                $availableSlots === 0 => 'full',
                $availableSlots < count($day['slots']) => 'partial',
                default => 'free',
            };
        }
        unset($day);

        return [
            'resourceId' => $resourceId,
            'month' => $from->format('Y-m'),
            'days' => array_values($days),
        ];
    }

    private function formatAtom(DateTimeInterface $dateTime): string
    {
        return $dateTime->format(\DATE_ATOM);
    }
}
