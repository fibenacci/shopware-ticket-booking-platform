<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Ticket;

/**
 * Direction of a ticket scan. Check-out scans exist only when the operator
 * enables them (plugin config `scanCheckOutEnabled`) — they power the
 * dwell-time statistics and allow re-entry.
 */
final class ScanDirection
{
    public const CHECK_IN = 'check_in';
    public const CHECK_OUT = 'check_out';

    public const ALL = [self::CHECK_IN, self::CHECK_OUT];

    private function __construct()
    {
    }
}
