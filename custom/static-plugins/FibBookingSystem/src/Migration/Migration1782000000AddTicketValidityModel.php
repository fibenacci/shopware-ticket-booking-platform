<?php

declare(strict_types=1);

namespace FibBookingSystem\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Generic ticket validity model — one configuration, every ticket kind:
 *
 *   ticket type = validity mode × entry policy
 *
 * Product config (operator-defined, per product):
 * - validity_mode:      slot (calendar Termin, default) | period | unlimited
 * - validity_duration:  ISO-8601 duration for period mode (P1D, P1M, P3M, P1Y, PT4H …)
 * - validity_anchor:    purchase | first_use | customer (period mode)
 * - entry_policy:       single (consumed by first scan) | multi (re-usable while valid)
 * - max_entries_per_day: optional cap for multi tickets (NULL = unlimited)
 *
 * Ticket columns are a SNAPSHOT taken at issue time, so later product
 * reconfiguration never changes tickets already sold. valid_from/expires_at
 * stay NULL for first_use anchors until the first successful check-in
 * activates the pass.
 */
class Migration1782000000AddTicketValidityModel extends MigrationStep
{
    private const COLUMNS = [
        'fib_booking_product_config' => [
            'validity_mode' => "ADD COLUMN `validity_mode` VARCHAR(16) NOT NULL DEFAULT 'slot' AFTER `slot_minutes`",
            'validity_duration' => 'ADD COLUMN `validity_duration` VARCHAR(32) NULL AFTER `validity_mode`',
            'validity_anchor' => 'ADD COLUMN `validity_anchor` VARCHAR(16) NULL AFTER `validity_duration`',
            'entry_policy' => "ADD COLUMN `entry_policy` VARCHAR(16) NOT NULL DEFAULT 'single' AFTER `validity_anchor`",
            'max_entries_per_day' => 'ADD COLUMN `max_entries_per_day` INT UNSIGNED NULL AFTER `entry_policy`',
        ],
        'fib_booking_ticket' => [
            'valid_from' => 'ADD COLUMN `valid_from` DATETIME(3) NULL AFTER `expires_at`',
            'entry_policy' => "ADD COLUMN `entry_policy` VARCHAR(16) NOT NULL DEFAULT 'single' AFTER `valid_from`",
            'max_entries_per_day' => 'ADD COLUMN `max_entries_per_day` INT UNSIGNED NULL AFTER `entry_policy`',
            'validity_anchor' => 'ADD COLUMN `validity_anchor` VARCHAR(16) NULL AFTER `max_entries_per_day`',
            'validity_duration' => 'ADD COLUMN `validity_duration` VARCHAR(32) NULL AFTER `validity_anchor`',
        ],
    ];

    public function getCreationTimestamp(): int
    {
        return 1782000000;
    }

    public function update(Connection $connection): void
    {
        // columnExists() (MigrationStep) probes the schema — MySQL 8 has no
        // "ADD COLUMN IF NOT EXISTS" (MariaDB-only), so this keeps re-runs
        // idempotent.
        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column => $alterClause) {
                if ($this->columnExists($connection, $table, $column)) {
                    continue;
                }

                $connection->executeStatement(sprintf('ALTER TABLE `%s` %s;', $table, $alterClause));
            }
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
