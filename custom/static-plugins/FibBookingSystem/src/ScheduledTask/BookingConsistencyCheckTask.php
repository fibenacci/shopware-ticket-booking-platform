<?php

declare(strict_types=1);

namespace FibBookingSystem\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class BookingConsistencyCheckTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'fib_booking_system.consistency_check';
    }

    public static function getDefaultInterval(): int
    {
        return 3600; // hourly — a violation is rare but must surface the same day, not the same second
    }
}
