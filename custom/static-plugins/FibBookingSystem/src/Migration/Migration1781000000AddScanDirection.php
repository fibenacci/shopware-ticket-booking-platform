<?php

declare(strict_types=1);

namespace FibBookingSystem\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Check-in/check-out tracking for visit statistics:
 *
 * Every scan log row carries a `direction` (check_in | check_out). Existing
 * rows are check-ins by definition — until this migration the scanner only
 * supported entry scans. Dwell time is derived by pairing a check-out with
 * the latest preceding check-in of the same ticket, so no schema beyond the
 * direction column is needed (re-entry simply produces multiple sessions).
 */
class Migration1781000000AddScanDirection extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1781000000;
    }

    public function update(Connection $connection): void
    {
        // MySQL 8 has no "ADD COLUMN IF NOT EXISTS" (MariaDB-only) — probe the
        // information schema for idempotency instead.
        $columnExists = (bool) $connection->fetchOne(
            <<<'SQL'
                SELECT 1 FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'fib_booking_scan_log'
                AND COLUMN_NAME = 'direction'
            SQL,
        );

        if (!$columnExists) {
            $connection->executeStatement(<<<'SQL'
                    ALTER TABLE `fib_booking_scan_log`
                    ADD COLUMN `direction` VARCHAR(16) NOT NULL DEFAULT 'check_in' AFTER `verdict`;
                SQL);
        }

        $indexExists = (bool) $connection->fetchOne(
            <<<'SQL'
                SELECT 1 FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'fib_booking_scan_log'
                AND INDEX_NAME = 'idx.fib_booking_scan_log.direction'
            SQL,
        );

        if (!$indexExists) {
            // Statistics read model filters by direction within a date range.
            $connection->executeStatement(<<<'SQL'
                    ALTER TABLE `fib_booking_scan_log`
                    ADD KEY `idx.fib_booking_scan_log.direction` (`direction`, `created_at`);
                SQL);
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
