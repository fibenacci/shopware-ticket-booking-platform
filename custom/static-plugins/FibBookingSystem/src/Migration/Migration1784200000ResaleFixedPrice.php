<?php

declare(strict_types=1);

namespace FibBookingSystem\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Resale phase 2 — fixed price end-to-end (docs/RESELL_PLAN.md):
 *
 * - `fib_booking_ticket.owner_customer_id`: per-ticket ownership override.
 *   NULL = the ticket belongs to the reservation customer (primary market,
 *   the unchanged default); set = the ticket was transferred (resale) and
 *   belongs to this customer instead. Ownership moves at TICKET level on
 *   purpose: the reservation keeps carrying the capacity booking (a second
 *   buyer-side reservation would double-count the slot in the availability
 *   math), and a seller can sell 1 of N tickets of one reservation.
 * - `fib_booking_listing.sold_order_id` (+version): the buyer's Shopware
 *   order that settled this listing — idempotency anchor for the payment
 *   subscriber and the refund trail.
 * - `fib_booking_listing.sold_ticket_id`: the replacement ticket issued to
 *   the buyer — the refund path revokes exactly this one.
 */
class Migration1784200000ResaleFixedPrice extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1784200000;
    }

    public function update(Connection $connection): void
    {
        if (!$this->columnExists($connection, 'fib_booking_ticket', 'owner_customer_id')) {
            $connection->executeStatement(<<<'SQL'
                    ALTER TABLE `fib_booking_ticket`
                    ADD COLUMN `owner_customer_id` BINARY(16) NULL AFTER `replaced_ticket_id`,
                    ADD KEY `idx.fib_booking_ticket.owner` (`owner_customer_id`);
                SQL);
        }

        if (!$this->columnExists($connection, 'fib_booking_listing', 'sold_order_id')) {
            $connection->executeStatement(<<<'SQL'
                    ALTER TABLE `fib_booking_listing`
                    ADD COLUMN `sold_order_id` BINARY(16) NULL AFTER `sold_at`,
                    ADD COLUMN `sold_order_version_id` BINARY(16) NULL AFTER `sold_order_id`,
                    ADD COLUMN `sold_ticket_id` BINARY(16) NULL AFTER `sold_order_version_id`,
                    ADD KEY `idx.fib_booking_listing.sold_order` (`sold_order_id`);
                SQL);
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
