<?php

declare(strict_types=1);

namespace FibBookingSystem\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * `gate` on the scan audit log: which entrance/device lane produced the scan.
 * With several entrances the duplicate-scan answer "already scanned at 18:03"
 * is only actionable when staff can see WHERE — and per-gate throughput is
 * the basis for staffing decisions.
 */
class Migration1781100000AddScanLogGate extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1781100000;
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
                AND COLUMN_NAME = 'gate'
            SQL,
        );

        if (!$columnExists) {
            $connection->executeStatement(<<<'SQL'
                    ALTER TABLE `fib_booking_scan_log`
                    ADD COLUMN `gate` VARCHAR(64) NULL AFTER `source`;
                SQL);
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
