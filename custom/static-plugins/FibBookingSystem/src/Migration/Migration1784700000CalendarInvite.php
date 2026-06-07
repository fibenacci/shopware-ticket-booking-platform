<?php

declare(strict_types=1);

namespace FibBookingSystem\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Calendar invite (.ics) in the confirmation mail — opt-out per product,
 * ENABLED by default. Snapshotted onto the ticket at issue (same principle as
 * the validity / rotating-QR models: later config changes never alter
 * already-sold tickets).
 *
 * - product config: `calendar_invite_enabled` — the operator switch (default 1).
 * - ticket: the snapshot, read by the mail subscriber to decide whether to
 *   attach the invite.
 */
class Migration1784700000CalendarInvite extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1784700000;
    }

    public function update(Connection $connection): void
    {
        if (!$this->columnExists($connection, 'fib_booking_product_config', 'calendar_invite_enabled')) {
            $connection->executeStatement(<<<'SQL'
                    ALTER TABLE `fib_booking_product_config`
                    ADD COLUMN `calendar_invite_enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER `max_entries_per_day`;
                SQL);
        }

        if (!$this->columnExists($connection, 'fib_booking_ticket', 'calendar_invite_enabled')) {
            $connection->executeStatement(<<<'SQL'
                    ALTER TABLE `fib_booking_ticket`
                    ADD COLUMN `calendar_invite_enabled` TINYINT(1) NOT NULL DEFAULT 1 AFTER `seat_label`;
                SQL);
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
