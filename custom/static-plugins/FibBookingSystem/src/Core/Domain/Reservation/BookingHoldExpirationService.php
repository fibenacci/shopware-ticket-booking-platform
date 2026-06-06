<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Reservation;

use Doctrine\DBAL\Connection;

class BookingHoldExpirationService
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function expireOverdueHolds(): int
    {
        return $this->connection->executeStatement(
            "UPDATE fib_booking_hold
             SET status = 'expired', updated_at = UTC_TIMESTAMP(3)
             WHERE status = 'active'
               AND expires_at <= UTC_TIMESTAMP(3)",
        );
    }
}
