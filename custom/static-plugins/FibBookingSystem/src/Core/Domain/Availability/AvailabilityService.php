<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Availability;

use DateTimeInterface;
use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Shopware\Core\Framework\Uuid\Uuid;

class AvailabilityService
{
    private const ACTIVE_HOLD_STATUS = 'active';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function check(string $resourceId, DateTimeInterface $startsAt, DateTimeInterface $endsAt, int $quantity): AvailabilityResult
    {
        if ($quantity < 1) {
            throw new InvalidArgumentException('Booking quantity must be greater than zero.');
        }

        if ($startsAt >= $endsAt) {
            throw new InvalidArgumentException('Booking end must be after booking start.');
        }

        $resourceBytes = Uuid::fromHexToBytes($resourceId);
        $capacity = $this->fetchCapacity($resourceBytes);

        if ($capacity === null) {
            return new AvailabilityResult(false, 0, 0, $quantity);
        }

        // Operator-defined slots ("Termine") take precedence: when the
        // resource has slots, bookings are accepted only ON a slot and the
        // slot's own capacity wins over the resource capacity.
        $slotCapacity = $this->fetchSlotCapacity($resourceBytes, $startsAt, $endsAt);

        if ($slotCapacity === null && $this->hasActiveSlots($resourceBytes)) {
            return new AvailabilityResult(false, 0, 0, $quantity);
        }

        if ($slotCapacity !== null) {
            $capacity = $slotCapacity;
        }

        $reservedQuantity = $this->fetchReservedQuantity($resourceBytes, $startsAt, $endsAt);

        return new AvailabilityResult(
            $reservedQuantity + $quantity <= $capacity,
            $capacity,
            $reservedQuantity,
            $quantity,
        );
    }

    private function fetchCapacity(string $resourceBytes): ?int
    {
        $capacity = $this->connection->fetchOne(
            <<<'SQL'
                SELECT capacity FROM fib_booking_resource WHERE id = :resourceId AND active = 1
            SQL,
            ['resourceId' => $resourceBytes],
        );

        return is_numeric($capacity) ? (int) $capacity : null;
    }

    private function fetchSlotCapacity(string $resourceBytes, DateTimeInterface $startsAt, DateTimeInterface $endsAt): ?int
    {
        $capacity = $this->connection->fetchOne(
            <<<'SQL'
                SELECT capacity FROM fib_booking_slot
                WHERE resource_id = :resourceId
                AND starts_at = :startsAt
                AND ends_at = :endsAt
                AND active = 1
            SQL,
            [
                'resourceId' => $resourceBytes,
                'startsAt' => $this->formatDateTime($startsAt),
                'endsAt' => $this->formatDateTime($endsAt),
            ],
        );

        return is_numeric($capacity) ? (int) $capacity : null;
    }

    private function hasActiveSlots(string $resourceBytes): bool
    {
        return (bool) $this->connection->fetchOne(
            <<<'SQL'
                SELECT 1 FROM fib_booking_slot WHERE resource_id = :resourceId AND active = 1 LIMIT 1
            SQL,
            ['resourceId' => $resourceBytes],
        );
    }

    private function fetchReservedQuantity(string $resourceBytes, DateTimeInterface $startsAt, DateTimeInterface $endsAt): int
    {
        $holdQuantity = $this->connection->fetchOne(
            <<<'SQL'
                SELECT COALESCE(SUM(quantity), 0)
                FROM fib_booking_hold
                WHERE resource_id = :resourceId
                AND status = :status
                AND expires_at > UTC_TIMESTAMP(3)
                AND starts_at < :endsAt
                AND ends_at > :startsAt
            SQL,
            [
                'resourceId' => $resourceBytes,
                'status' => self::ACTIVE_HOLD_STATUS,
                'startsAt' => $this->formatDateTime($startsAt),
                'endsAt' => $this->formatDateTime($endsAt),
            ],
        );

        $reservationQuantity = $this->connection->fetchOne(
            <<<'SQL'
                SELECT COALESCE(SUM(quantity), 0)
                FROM fib_booking_reservation
                WHERE resource_id = :resourceId
                AND status IN (:pendingPayment, :confirmed, :completed)
                AND starts_at < :endsAt
                AND ends_at > :startsAt
            SQL,
            [
                'resourceId' => $resourceBytes,
                'pendingPayment' => 'pending_payment',
                'confirmed' => 'confirmed',
                'completed' => 'completed',
                'startsAt' => $this->formatDateTime($startsAt),
                'endsAt' => $this->formatDateTime($endsAt),
            ],
        );

        return (is_numeric($holdQuantity) ? (int) $holdQuantity : 0)
            + (is_numeric($reservationQuantity) ? (int) $reservationQuantity : 0);
    }

    private function formatDateTime(DateTimeInterface $dateTime): string
    {
        return $dateTime->format('Y-m-d H:i:s.v');
    }
}
