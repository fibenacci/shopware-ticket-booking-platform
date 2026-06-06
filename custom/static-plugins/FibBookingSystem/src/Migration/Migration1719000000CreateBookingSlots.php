<?php

declare(strict_types=1);

namespace FibBookingSystem\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Operator-defined bookable dates ("Termine"): each slot is a concrete time
 * window on a resource with its own capacity. When a resource has slots,
 * bookings are only accepted ON those slots and capacity comes from the slot
 * (calendar turns red when a date's slots are sold out). Resources without
 * slots keep the free-form window behaviour.
 */
class Migration1719000000CreateBookingSlots extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1719000000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
                CREATE TABLE IF NOT EXISTS `fib_booking_slot` (
                `id` BINARY(16) NOT NULL,
                `resource_id` BINARY(16) NOT NULL,
                `starts_at` DATETIME(3) NOT NULL,
                `ends_at` DATETIME(3) NOT NULL,
                `capacity` INT NOT NULL,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME(3) NOT NULL,
                `updated_at` DATETIME(3) NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.fib_booking_slot.resource_window` (`resource_id`, `starts_at`),
                KEY `idx.fib_booking_slot.window` (`resource_id`, `starts_at`, `ends_at`, `active`),
                CONSTRAINT `fk.fib_booking_slot.resource_id` FOREIGN KEY (`resource_id`)
                REFERENCES `fib_booking_resource` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            SQL);
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
