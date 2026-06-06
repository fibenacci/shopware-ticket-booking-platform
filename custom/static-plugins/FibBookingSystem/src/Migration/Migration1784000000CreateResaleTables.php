<?php

declare(strict_types=1);

namespace FibBookingSystem\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Secondary market (docs/RESELL_PLAN.md): the TICKET is the tradable good.
 *
 * - `fib_booking_listing`: one lot per listing. `active_ticket_id` is the
 *   insert-wins guard ("one LIVE listing per ticket"): it mirrors ticket_id
 *   while the listing is active and is NULLed on settle/cancel — the UNIQUE
 *   key lets the database decide concurrent listing attempts, same
 *   philosophy as the seat claims. The column is intentionally NOT part of
 *   the DAL definition (internal guard, raw-only).
 * - `fib_booking_bid`: append-only audit of auction bids (like the scan log).
 * - `fib_booking_ticket.replaced_ticket_id`: the transfer audit chain —
 *   a resale revokes the seller's ticket and issues a fresh one (token
 *   rotation), the new row points back at the dead one.
 */
class Migration1784000000CreateResaleTables extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1784000000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
                CREATE TABLE IF NOT EXISTS `fib_booking_listing` (
                `id` BINARY(16) NOT NULL,
                `ticket_id` BINARY(16) NOT NULL,
                `active_ticket_id` BINARY(16) NULL,
                `seller_customer_id` BINARY(16) NOT NULL,
                `mode` VARCHAR(16) NOT NULL,
                `status` VARCHAR(16) NOT NULL DEFAULT 'active',
                `ask_price` DOUBLE NOT NULL,
                `reserve_price` DOUBLE NULL,
                `min_increment` DOUBLE NULL,
                `ends_at` DATETIME(3) NULL,
                `sold_to_customer_id` BINARY(16) NULL,
                `sold_price` DOUBLE NULL,
                `sold_at` DATETIME(3) NULL,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.fib_booking_listing.active_ticket` (`active_ticket_id`),
                KEY `idx.fib_booking_listing.ticket_id` (`ticket_id`),
                KEY `idx.fib_booking_listing.status` (`status`, `ends_at`),
                KEY `idx.fib_booking_listing.seller` (`seller_customer_id`),
                CONSTRAINT `fk.fib_booking_listing.ticket_id` FOREIGN KEY (`ticket_id`)
                REFERENCES `fib_booking_ticket` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);

        $connection->executeStatement(<<<'SQL'
                CREATE TABLE IF NOT EXISTS `fib_booking_bid` (
                `id` BINARY(16) NOT NULL,
                `listing_id` BINARY(16) NOT NULL,
                `customer_id` BINARY(16) NOT NULL,
                `amount` DOUBLE NOT NULL,
                `created_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx.fib_booking_bid.listing_amount` (`listing_id`, `amount`),
                CONSTRAINT `fk.fib_booking_bid.listing_id` FOREIGN KEY (`listing_id`)
                REFERENCES `fib_booking_listing` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);

        if (!$this->columnExists($connection, 'fib_booking_ticket', 'replaced_ticket_id')) {
            $connection->executeStatement(<<<'SQL'
                    ALTER TABLE `fib_booking_ticket`
                    ADD COLUMN `replaced_ticket_id` BINARY(16) NULL AFTER `seat_label`,
                    ADD KEY `idx.fib_booking_ticket.replaced` (`replaced_ticket_id`);
                SQL);
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
