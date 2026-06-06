<?php

declare(strict_types=1);

namespace FibBookingSystem\ScheduledTask;

use Shopware\Core\Framework\MessageQueue\ScheduledTask\ScheduledTask;

class AnonymizeScanLogTask extends ScheduledTask
{
    public static function getTaskName(): string
    {
        return 'fib_booking_system.anonymize_scan_log';
    }

    public static function getDefaultInterval(): int
    {
        return 86400; // daily — retention works on a days scale
    }
}
