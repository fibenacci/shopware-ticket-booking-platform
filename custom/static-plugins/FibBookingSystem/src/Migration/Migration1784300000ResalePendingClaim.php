<?php

declare(strict_types=1);

namespace FibBookingSystem\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Double-sell hardening (security audit F1): a buyer order CLAIMS the
 * listing atomically at order placement (`status active → pending`,
 * `pending_order_id` = the claiming order). A second buyer's cart drops the
 * item on its placement recalculation, shrinking the race window from
 * "placement → payment" (potentially hours) to the milliseconds between two
 * simultaneous placements — and that residue is conflict-logged at
 * settlement instead of double-charging silently.
 */
class Migration1784300000ResalePendingClaim extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1784300000;
    }

    public function update(Connection $connection): void
    {
        if (!$this->columnExists($connection, 'fib_booking_listing', 'pending_order_id')) {
            $connection->executeStatement(<<<'SQL'
                    ALTER TABLE `fib_booking_listing`
                    ADD COLUMN `pending_order_id` BINARY(16) NULL AFTER `ends_at`,
                    ADD KEY `idx.fib_booking_listing.pending_order` (`pending_order_id`);
                SQL);
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
