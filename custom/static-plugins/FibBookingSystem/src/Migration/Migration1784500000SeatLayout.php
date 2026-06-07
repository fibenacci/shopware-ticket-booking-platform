<?php

declare(strict_types=1);

namespace FibBookingSystem\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Free-form seat-map editor (docs/SEAT_EDITOR.md): an OPTIONAL richer layout
 * for irregular venues (aisles, columns, stage, curved/rotated tiers).
 *
 * - `fib_booking_resource.layout` (JSON): presentation-only — canvas size,
 *   non-bookable decoration elements (blocker/column/wall/stage/shape/label)
 *   and the seat-category palette. Never claimed/queried; the seat ROWS stay
 *   the bookable truth.
 * - `fib_booking_seat.rotation`: per-seat angle (deg) so curved/rotated rows
 *   render correctly. The curve maths lives in the EDITOR's generation tools;
 *   persisted seats are flat points (x, y, rotation), so the storefront
 *   picker just renders points — no curve maths at render time.
 *   (`fib_booking_seat.category` already exists.)
 */
class Migration1784500000SeatLayout extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1784500000;
    }

    public function update(Connection $connection): void
    {
        if (!$this->columnExists($connection, 'fib_booking_resource', 'layout')) {
            $connection->executeStatement(<<<'SQL'
                    ALTER TABLE `fib_booking_resource`
                    ADD COLUMN `layout` JSON NULL AFTER `configuration`;
                SQL);
        }

        if (!$this->columnExists($connection, 'fib_booking_seat', 'rotation')) {
            $connection->executeStatement(<<<'SQL'
                    ALTER TABLE `fib_booking_seat`
                    ADD COLUMN `rotation` INT NOT NULL DEFAULT 0 AFTER `pos_y`;
                SQL);
        }
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
