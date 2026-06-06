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
            'SELECT capacity FROM fib_booking_resource WHERE id = :resourceId AND active = 1',
            ['resourceId' => $resourceBytes],
        );

        return $capacity === false ? null : (int) $capacity;
    }

    private function fetchReservedQuantity(string $resourceBytes, DateTimeInterface $startsAt, DateTimeInterface $endsAt): int
    {
        $holdQuantity = (int) $this->connection->fetchOne(
            'SELECT COALESCE(SUM(quantity), 0)
             FROM fib_booking_hold
             WHERE resource_id = :resourceId
               AND status = :status
               AND expires_at > UTC_TIMESTAMP(3)
               AND starts_at < :endsAt
               AND ends_at > :startsAt',
            [
                'resourceId' => $resourceBytes,
                'status' => self::ACTIVE_HOLD_STATUS,
                'startsAt' => $this->formatDateTime($startsAt),
                'endsAt' => $this->formatDateTime($endsAt),
            ],
        );

        $reservationQuantity = (int) $this->connection->fetchOne(
            'SELECT COALESCE(SUM(quantity), 0)
             FROM fib_booking_reservation
             WHERE resource_id = :resourceId
               AND status IN (:pendingPayment, :confirmed, :completed)
               AND starts_at < :endsAt
               AND ends_at > :startsAt',
            [
                'resourceId' => $resourceBytes,
                'pendingPayment' => 'pending_payment',
                'confirmed' => 'confirmed',
                'completed' => 'completed',
                'startsAt' => $this->formatDateTime($startsAt),
                'endsAt' => $this->formatDateTime($endsAt),
            ],
        );

        return $holdQuantity + $reservationQuantity;
    }

    private function formatDateTime(DateTimeInterface $dateTime): string
    {
        return $dateTime->format('Y-m-d H:i:s.v');
    }
}
