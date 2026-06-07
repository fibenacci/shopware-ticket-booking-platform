<?php

declare(strict_types=1);

namespace FibBookingSystem\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * A real, editable SEO URL for the public resale market page so the
 * navigation menu (and any link) points at a clean `/resale-market` slug
 * instead of the technical `/fib-booking/resale` route — the same
 * technical-path + canonical-seo-slug split Shopware uses for every product
 * and category, so there is no duplicate-content problem.
 *
 * One canonical row per sales-channel × language (the scope Shopware uses for
 * SEO), derived from `sales_channel_domain`. `is_modified = 1` marks the rows
 * as hand-authored so the SEO indexer never regenerates or deletes them — the
 * merchant can still rename the slug under Settings > SEO URLs. Idempotent via
 * a deterministic id, so re-running the migration is a no-op.
 */
class Migration1784600000ResaleSeoUrl extends MigrationStep
{
    private const ROUTE_NAME = 'frontend.fib_booking.resale.index';
    private const PATH_INFO = '/fib-booking/resale';
    private const SEO_PATH = 'resale-market';

    public function getCreationTimestamp(): int
    {
        return 1784600000;
    }

    public function update(Connection $connection): void
    {
        // One row per channel+language combination that actually exists.
        // GROUP BY collapses the http/https (and extra) domains that share a
        // channel+language into a single canonical entry.
        $connection->executeStatement(
            <<<'SQL'
                INSERT IGNORE INTO `seo_url`
                    (`id`, `language_id`, `sales_channel_id`, `foreign_key`,
                     `route_name`, `path_info`, `seo_path_info`,
                     `is_canonical`, `is_modified`, `is_deleted`, `created_at`)
                SELECT
                    UNHEX(MD5(CONCAT(:idSeed, LOWER(HEX(d.sales_channel_id)), LOWER(HEX(d.language_id))))),
                    d.language_id,
                    d.sales_channel_id,
                    UNHEX(MD5(:foreignSeed)),
                    :routeName,
                    :pathInfo,
                    :seoPath,
                    1, 1, 0, NOW(3)
                FROM `sales_channel_domain` d
                GROUP BY d.sales_channel_id, d.language_id
            SQL,
            [
                'idSeed' => 'fib-booking-resale-seo:',
                'foreignSeed' => 'fib-booking-resale-foreign-key',
                'routeName' => self::ROUTE_NAME,
                'pathInfo' => self::PATH_INFO,
                'seoPath' => self::SEO_PATH,
            ],
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
