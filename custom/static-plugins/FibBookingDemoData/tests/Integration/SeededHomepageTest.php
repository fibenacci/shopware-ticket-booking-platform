<?php

declare(strict_types=1);

namespace FibBookingDemoData\Tests\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The seeded homepage layout must contain the booking-calendar element
 * (configured with a resource) plus the scanner link block, and be assigned
 * to every storefront entry category.
 */
class SeededHomepageTest extends TestCase
{
    use KernelTestBehaviour;
    use SeederFactoryTrait;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = self::container()->get(Connection::class);

        $this->createSeeder(self::container())->seed(Context::createCLIContext(), withReservation: false);
    }

    public function testHomepageLayoutContainsCalendarElementWithResource(): void
    {
        $slotConfig = $this->connection->fetchOne(
            <<<'SQL'
                SELECT translation.config
                FROM cms_slot slot
                INNER JOIN cms_slot_translation translation ON translation.cms_slot_id = slot.id
                WHERE slot.type = 'fib-booking-calendar'
                LIMIT 1
                SQL,
        );

        static::assertIsString($slotConfig, 'the calendar CMS slot must exist');

        $config = json_decode($slotConfig, true);
        static::assertIsArray($config);
        static::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $config['resourceId']['value']);
        static::assertGreaterThanOrEqual(1, (int) $config['monthsAhead']['value']);
    }

    public function testHomepageLayoutContainsScannerLinkBlock(): void
    {
        $content = $this->connection->fetchOne(
            <<<'SQL'
                SELECT translation.config
                FROM cms_slot slot
                INNER JOIN cms_slot_translation translation ON translation.cms_slot_id = slot.id
                INNER JOIN cms_block block ON block.id = slot.cms_block_id
                WHERE slot.type = 'text'
                  AND block.type = 'text'
                  AND translation.config LIKE '%Open ticket scanner%'
                LIMIT 1
                SQL,
        );

        static::assertIsString($content, 'the scanner link text block must exist');
        static::assertStringContainsString('scanner.booking.docker', $content);
    }

    public function testEveryStorefrontEntryCategoryUsesTheSeededLayout(): void
    {
        $unassigned = $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*)
                FROM sales_channel
                INNER JOIN sales_channel_type ON sales_channel_type.id = sales_channel.type_id
                INNER JOIN category ON category.id = sales_channel.navigation_category_id
                LEFT JOIN cms_page_translation page_translation
                    ON page_translation.cms_page_id = category.cms_page_id
                   AND page_translation.name = 'FIB Booking Home'
                WHERE sales_channel.type_id = UNHEX('8A243080F92E4C719546314B577CF82B')
                  AND page_translation.cms_page_id IS NULL
                SQL,
        );

        static::assertSame('0', (string) $unassigned, 'every storefront entry category must use the seeded homepage layout');
    }

    private static function container(): ContainerInterface
    {
        $container = static::getKernel()->getContainer();

        return $container->has('test.service_container') ? $container->get('test.service_container') : $container;
    }
}
