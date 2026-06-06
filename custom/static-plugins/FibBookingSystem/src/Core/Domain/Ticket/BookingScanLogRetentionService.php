<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Ticket;

use Doctrine\DBAL\Connection;

/**
 * GDPR retention for the scan audit log: scan rows are movement data tied to
 * a person. After `scanLogRetentionDays` (default 180, 0 = off) the
 * person-related columns are ANONYMIZED — `scanned_by` (operator/user) and
 * `token_fingerprint` (pseudonymous ticket correlation) are nulled. The rows
 * themselves stay: verdict, direction, gate and timestamps feed the
 * attendance/dwell-time statistics, which must survive the retention window.
 *
 * Deliberate raw DBAL (documented exception, see docs/ARCHITECTURE_PLAN.md):
 * scheduled-task maintenance as one set-based UPDATE on an append-only table
 * that is not part of any cache/index.
 */
class BookingScanLogRetentionService
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function anonymizeOlderThan(int $retentionDays): int
    {
        if ($retentionDays <= 0) {
            return 0; // 0 disables the retention entirely
        }

        return (int) $this->connection->executeStatement(
            <<<'SQL'
                UPDATE fib_booking_scan_log
                SET scanned_by = NULL, token_fingerprint = NULL
                WHERE created_at <= UTC_TIMESTAMP(3) - INTERVAL :retentionDays DAY
                AND (scanned_by IS NOT NULL OR token_fingerprint IS NOT NULL)
            SQL,
            ['retentionDays' => $retentionDays],
        );
    }
}
