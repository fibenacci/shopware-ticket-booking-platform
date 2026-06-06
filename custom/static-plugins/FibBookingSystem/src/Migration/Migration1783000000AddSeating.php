<?php

declare(strict_types=1);

namespace FibBookingSystem\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Numbered seating (cinema/theater style) as a per-resource OPTION — see
 * docs/SEATING_PLAN.md for the full design.
 *
 * - `fib_booking_seat`: the room layout (slot-independent; the same room
 *   serves every showing). Coordinates are grid units for the storefront
 *   picker; `category` is a pricing/zone tier for later iterations.
 * - `fib_booking_seat_claim`: THE concurrency primitive. A seat for a slot
 *   is either claimed or not — `UNIQUE (seat_id, slot_id)` lets the database
 *   decide every race (insert-wins), no lock waits, no serialization point.
 * - `fib_booking_resource.seating_mode`: 'pool' (default, untouched
 *   behavior) or 'seatmap'.
 * - `fib_booking_ticket.seat_label`: snapshot for ticket-per-seat issuing
 *   (phase 3) — renaming rows later never changes sold tickets.
 */
class Migration1783000000AddSeating extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1783000000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
                CREATE TABLE IF NOT EXISTS `fib_booking_seat` (
                `id` BINARY(16) NOT NULL,
                `resource_id` BINARY(16) NOT NULL,
                `row_label` VARCHAR(16) NOT NULL,
                `seat_label` VARCHAR(16) NOT NULL,
                `pos_x` INT NOT NULL,
                `pos_y` INT NOT NULL,
                `category` VARCHAR(64) NULL,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.fib_booking_seat.position` (`resource_id`, `row_label`, `seat_label`),
                KEY `idx.fib_booking_seat.resource_id` (`resource_id`),
                CONSTRAINT `fk.fib_booking_seat.resource_id` FOREIGN KEY (`resource_id`)
                REFERENCES `fib_booking_resource` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);

        $connection->executeStatement(<<<'SQL'
                CREATE TABLE IF NOT EXISTS `fib_booking_seat_claim` (
                `id` BINARY(16) NOT NULL,
                `seat_id` BINARY(16) NOT NULL,
                `slot_id` BINARY(16) NOT NULL,
                `hold_id` BINARY(16) NULL,
                `reservation_id` BINARY(16) NULL,
                `created_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.fib_booking_seat_claim.seat_slot` (`seat_id`, `slot_id`),
                KEY `idx.fib_booking_seat_claim.slot_id` (`slot_id`),
                KEY `idx.fib_booking_seat_claim.hold_id` (`hold_id`),
                KEY `idx.fib_booking_seat_claim.reservation_id` (`reservation_id`),
                CONSTRAINT `fk.fib_booking_seat_claim.seat_id` FOREIGN KEY (`seat_id`)
                REFERENCES `fib_booking_seat` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk.fib_booking_seat_claim.slot_id` FOREIGN KEY (`slot_id`)
                REFERENCES `fib_booking_slot` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);

        if (!$this->columnExists($connection, 'fib_booking_resource', 'seating_mode')) {
            $connection->executeStatement(<<<'SQL'
                    ALTER TABLE `fib_booking_resource`
                    ADD COLUMN `seating_mode` VARCHAR(16) NOT NULL DEFAULT 'pool' AFTER `capacity`;
                SQL);
        }

        if (!$this->columnExists($connection, 'fib_booking_ticket', 'seat_label')) {
            $connection->executeStatement(<<<'SQL'
                    ALTER TABLE `fib_booking_ticket`
                    ADD COLUMN `seat_label` VARCHAR(64) NULL AFTER `validity_duration`;
                SQL);
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
