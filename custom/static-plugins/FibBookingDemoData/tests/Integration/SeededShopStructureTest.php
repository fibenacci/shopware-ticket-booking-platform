<?php

declare(strict_types=1);

namespace FibBookingDemoData\Tests\Integration;

use Doctrine\DBAL\Connection;
use FibBookingDemoData\Service\SeedIds;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The seeded shop structure must give the ticket shop a navigable frame:
 * offer categories with products assigned, the legal footer pages and the
 * domain service pages (how-it-works, FAQ, contact).
 */
class SeededShopStructureTest extends TestCase
{
    use KernelTestBehaviour;
    use SeederFactoryTrait;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = self::container()->get(Connection::class);

        $this->createSeeder(self::container())->seed(Context::createCLIContext(), withReservation: false, withOrders: false);
    }

    public function testNavigationCategoriesExistWithProductsAssigned(): void
    {
        $channelId = $this->connection->fetchOne(
            <<<'SQL'
                SELECT LOWER(HEX(id)) FROM sales_channel
                WHERE type_id = UNHEX('8A243080F92E4C719546314B577CF82B') LIMIT 1
            SQL,
        );
        static::assertIsString($channelId);

        $cinemaCategoryId = SeedIds::stable(sprintf('category:nav:%s:cinema', $channelId));

        $category = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT category.active, category.type, translation.name
                FROM category
                INNER JOIN category_translation translation ON translation.category_id = category.id
                -- system language: channel languages may carry empty rows
                AND translation.language_id = UNHEX('2FBB5FE2E29A4D70AA5854CE7CE3E20B')
                WHERE category.id = :id
                LIMIT 1
            SQL,
            ['id' => Uuid::fromHexToBytes($cinemaCategoryId)],
        );
        static::assertIsArray($category, 'cinema navigation category must exist');
        static::assertSame('Cinema', $category['name']);
        static::assertSame(1, (int) $category['active']);

        $assigned = $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) FROM product_category pc
                INNER JOIN product ON product.id = pc.product_id AND product.version_id = pc.product_version_id
                WHERE pc.category_id = :categoryId AND product.product_number = 'FIB-CINEMA-1'
            SQL,
            ['categoryId' => Uuid::fromHexToBytes($cinemaCategoryId)],
        );
        static::assertSame(1, (int) $assigned, 'the cinema product must be assigned to the cinema category');
    }

    public function testLegalAndServicePagesExist(): void
    {
        foreach (['footer-page:imprint', 'footer-page:privacy', 'footer-page:terms', 'footer-page:withdrawal', 'service-page:faq', 'service-page:contact', 'service-page:how-it-works'] as $key) {
            $exists = $this->connection->fetchOne(
                'SELECT 1 FROM category WHERE id = :id',
                ['id' => Uuid::fromHexToBytes(SeedIds::stable('category:' . $key))],
            );
            static::assertSame('1', (string) $exists, sprintf('category "%s" must exist', $key));
        }

        // Page categories carry a CMS layout with the seeded content.
        $content = $this->connection->fetchOne(
            <<<'SQL'
                SELECT translation.config
                FROM cms_slot slot
                INNER JOIN cms_slot_translation translation ON translation.cms_slot_id = slot.id
                WHERE slot.id = :slotId
            SQL,
            ['slotId' => Uuid::fromHexToBytes(SeedIds::stable(SeedIds::stable('cms:service-page:how-it-works') . ':slot'))],
        );
        static::assertIsString($content);
        static::assertStringContainsString('resale market', $content, 'how-it-works must explain the resale flow');
    }

    public function testFooterAndServiceRootsAreWiredIntoTheSalesChannel(): void
    {
        $roots = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT LOWER(HEX(footer_category_id)) AS footer, LOWER(HEX(service_category_id)) AS service
                FROM sales_channel
                WHERE type_id = UNHEX('8A243080F92E4C719546314B577CF82B') LIMIT 1
            SQL,
        );
        static::assertIsArray($roots);
        // Either our seeded roots or a pre-existing shop structure (which the
        // seeder must never hijack) — both are valid; null is not.
        static::assertNotNull($roots['footer'], 'sales channel must have a footer root');
        static::assertNotNull($roots['service'], 'sales channel must have a service root');
    }

    private static function container(): ContainerInterface
    {
        $container = static::getKernel()->getContainer();

        return $container->has('test.service_container') ? $container->get('test.service_container') : $container;
    }
}
