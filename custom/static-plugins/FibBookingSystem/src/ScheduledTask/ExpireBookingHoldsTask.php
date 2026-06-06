<?php

declare(strict_types=1);

namespace FibBookingSystem\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class ExpireBookingHoldsTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'fib_booking_system.expire_booking_holds';
    }

    public static function getDefaultInterval(): int
    {
        return 300;
    }
}
