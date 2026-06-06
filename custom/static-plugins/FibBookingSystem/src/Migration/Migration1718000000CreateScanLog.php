<?php

declare(strict_types=1);

namespace FibBookingSystem\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Ticketing platform groundwork:
 *
 * 1. Audit trail for ticket scans. Every scan attempt — including failed ones —
 *    is recorded with its verdict. The scan token itself is never stored; only
 *    a short fingerprint (first 12 hex chars of its sha256) for correlation.
 * 2. `scan_token_cipher` on fib_booking_ticket: the scan token encrypted with
 *    AES-256-GCM (key derived from the application secret). Needed to rebuild
 *    the QR payload for wallet passes at download time — a database leak alone
 *    does not expose usable tokens.
 */
class Migration1718000000CreateScanLog extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1718000000;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            ALTER TABLE `fib_booking_ticket`
                ADD COLUMN IF NOT EXISTS `scan_token_cipher` VARCHAR(512) NULL AFTER `scan_token_hash`;
        ');

        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `fib_booking_scan_log` (
                `id` BINARY(16) NOT NULL,
                `ticket_id` BINARY(16) NULL,
                `verdict` VARCHAR(32) NOT NULL,
                `token_fingerprint` CHAR(12) NULL,
                `scanned_by` VARCHAR(64) NULL,
                `source` VARCHAR(32) NOT NULL DEFAULT \'api\',
                `created_at` DATETIME(3) NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx.fib_booking_scan_log.ticket_id` (`ticket_id`),
                KEY `idx.fib_booking_scan_log.created_at` (`created_at`),
                KEY `idx.fib_booking_scan_log.verdict` (`verdict`),
                CONSTRAINT `fk.fib_booking_scan_log.ticket_id` FOREIGN KEY (`ticket_id`)
                    REFERENCES `fib_booking_ticket` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
