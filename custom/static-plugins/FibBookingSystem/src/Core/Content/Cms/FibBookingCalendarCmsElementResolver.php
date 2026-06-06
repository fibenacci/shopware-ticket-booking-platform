<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\Cms;

use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotEntity;
use Shopware\Core\Content\Cms\DataResolver\CriteriaCollection;
use Shopware\Core\Content\Cms\DataResolver\Element\AbstractCmsElementResolver;
use Shopware\Core\Content\Cms\DataResolver\Element\ElementDataCollection;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\ResolverContext;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * CMS element resolver for the booking calendar ("Terminkalender").
 *
 * The element is configured with a booking resource; at render time the
 * resolver enriches the slot with the resource name and the bookable
 * packages — all storefront-visible products configured to that resource
 * via fib_booking_product_config (e.g. Starter / Premium / Luxury).
 */
class FibBookingCalendarCmsElementResolver extends AbstractCmsElementResolver
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function getType(): string
    {
        return 'fib-booking-calendar';
    }

    public function collect(CmsSlotEntity $slot, ResolverContext $resolverContext): ?CriteriaCollection
    {
        return null;
    }

    public function enrich(CmsSlotEntity $slot, ResolverContext $resolverContext, ElementDataCollection $result): void
    {
        $config = $slot->getFieldConfig();
        $resourceConfig = $config->get('resourceId');
        $resourceId = $resourceConfig?->getValue();

        if (!is_string($resourceId) || !Uuid::isValid($resourceId)) {
            $slot->setData(new ArrayStruct(['resourceId' => null, 'resourceName' => null, 'packages' => []], 'fib_booking_calendar_data'));

            return;
        }

        $resourceName = $this->fetchResourceName($resourceId);
        $packages = $this->fetchPackages($resourceId, $resolverContext->getSalesChannelContext()->getLanguageId());

        $slot->setData(new ArrayStruct([
            'resourceId' => strtolower($resourceId),
            'resourceName' => $resourceName,
            'packages' => $packages,
        ], 'fib_booking_calendar_data'));
    }

    private function fetchResourceName(string $resourceId): ?string
    {
        $name = $this->connection->fetchOne(
            <<<'SQL'
                SELECT name FROM fib_booking_resource WHERE id = :resourceId AND active = 1
                SQL,
            ['resourceId' => Uuid::fromHexToBytes($resourceId)],
        );

        return is_string($name) ? $name : null;
    }

    /**
     * @return list<array{productId: string, productNumber: string, name: string|null}>
     */
    private function fetchPackages(string $resourceId, string $languageId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT LOWER(HEX(product.id)) AS product_id,
                       product.product_number,
                       COALESCE(translation.name, fallback.name) AS name
                FROM fib_booking_product_config config
                INNER JOIN product
                    ON product.id = config.product_id AND product.version_id = config.product_version_id
                LEFT JOIN product_translation translation
                    ON translation.product_id = product.id
                   AND translation.product_version_id = product.version_id
                   AND translation.language_id = :languageId
                LEFT JOIN product_translation fallback
                    ON fallback.product_id = product.id
                   AND fallback.product_version_id = product.version_id
                WHERE config.resource_id = :resourceId
                  AND config.enabled = 1
                  AND product.active = 1
                ORDER BY product.product_number
                SQL,
            [
                'resourceId' => Uuid::fromHexToBytes($resourceId),
                'languageId' => Uuid::fromHexToBytes($languageId),
            ],
        );

        $packages = [];
        $seen = [];
        foreach ($rows as $row) {
            $productId = (string) $row['product_id'];
            if (isset($seen[$productId])) {
                continue;
            }
            $seen[$productId] = true;
            $packages[] = [
                'productId' => $productId,
                'productNumber' => (string) $row['product_number'],
                'name' => $row['name'] !== null ? (string) $row['name'] : null,
            ];
        }

        return $packages;
    }
}
