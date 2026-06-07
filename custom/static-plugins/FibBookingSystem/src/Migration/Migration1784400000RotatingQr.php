<?php

declare(strict_types=1);

namespace FibBookingSystem\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Rotating QR codes (time-based / TOTP) — opt-in per product, snapshotted
 * onto the ticket at issue (same principle as the validity model: later
 * config changes never alter already-sold tickets).
 *
 * - product config: `rotating_qr_enabled` + `rotating_qr_interval` — the
 *   operator switch.
 * - ticket: the snapshot of both, plus `last_rotating_window` — the
 *   single-use guard (a code for a window already accepted on this ticket
 *   cannot be redeemed again).
 */
class Migration1784400000RotatingQr extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1784400000;
    }

    public function update(Connection $connection): void
    {
        if (!$this->columnExists($connection, 'fib_booking_product_config', 'rotating_qr_enabled')) {
            $connection->executeStatement(<<<'SQL'
                    ALTER TABLE `fib_booking_product_config`
                    ADD COLUMN `rotating_qr_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `max_entries_per_day`,
                    ADD COLUMN `rotating_qr_interval` INT NULL AFTER `rotating_qr_enabled`;
                SQL);
        }

        if (!$this->columnExists($connection, 'fib_booking_ticket', 'rotating_qr_enabled')) {
            $connection->executeStatement(<<<'SQL'
                    ALTER TABLE `fib_booking_ticket`
                    ADD COLUMN `rotating_qr_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `seat_label`,
                    ADD COLUMN `rotating_qr_interval` INT NULL AFTER `rotating_qr_enabled`,
                    ADD COLUMN `last_rotating_window` BIGINT NULL AFTER `rotating_qr_interval`;
                SQL);
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
