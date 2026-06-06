<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\Cms;

use FibBookingSystem\Core\Content\BookingResource\BookingResourceCollection;
use FibBookingSystem\Core\Content\BookingResource\BookingResourceEntity;
use FibBookingSystem\Core\Content\ProductBookingConfig\ProductBookingConfigCollection;
use FibBookingSystem\Core\Content\ProductBookingConfig\ProductBookingConfigEntity;
use Shopware\Core\Content\Cms\Aggregate\CmsSlot\CmsSlotEntity;
use Shopware\Core\Content\Cms\DataResolver\CriteriaCollection;
use Shopware\Core\Content\Cms\DataResolver\Element\AbstractCmsElementResolver;
use Shopware\Core\Content\Cms\DataResolver\Element\ElementDataCollection;
use Shopware\Core\Content\Cms\DataResolver\ResolverContext\ResolverContext;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
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
    /**
     * @param EntityRepository<BookingResourceCollection>      $resourceRepository
     * @param EntityRepository<ProductBookingConfigCollection> $productConfigRepository
     */
    public function __construct(
        private readonly EntityRepository $resourceRepository,
        private readonly EntityRepository $productConfigRepository,
    ) {
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
        $resourceId = is_string($resourceId) ? strtolower($resourceId) : null;

        if ($resourceId === null || !Uuid::isValid($resourceId)) {
            $slot->setData(new ArrayStruct(['resourceId' => null, 'resourceName' => null, 'packages' => []], 'fib_booking_calendar_data'));

            return;
        }

        $context = $resolverContext->getSalesChannelContext()->getContext();

        $resourceName = $this->fetchResourceName($resourceId, $context);
        $packages = $this->fetchPackages($resourceId, $context);

        $slot->setData(new ArrayStruct([
            'resourceId' => $resourceId,
            'resourceName' => $resourceName,
            'packages' => $packages,
        ], 'fib_booking_calendar_data'));
    }

    private function fetchResourceName(string $resourceId, Context $context): ?string
    {
        $criteria = new Criteria([$resourceId]);
        $criteria->addFilter(new EqualsFilter('active', true));

        /** @var BookingResourceEntity|null $resource */
        $resource = $this->resourceRepository->search($criteria, $context)->first();

        return $resource?->getName();
    }

    /**
     * @return list<array{productId: string, productNumber: string, name: string|null}>
     */
    private function fetchPackages(string $resourceId, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('resourceId', $resourceId));
        $criteria->addFilter(new EqualsFilter('enabled', true));
        $criteria->addFilter(new EqualsFilter('product.active', true));
        $criteria->addAssociation('product');

        $configs = $this->productConfigRepository->search($criteria, $context)->getEntities();

        $packages = [];
        $seen = [];

        /** @var ProductBookingConfigEntity $config */
        foreach ($configs as $config) {
            $product = $config->getProduct();

            if (!$product instanceof ProductEntity) {
                continue;
            }

            $productId = $product->getId();

            if (isset($seen[$productId])) {
                continue;
            }
            $seen[$productId] = true;

            // The DAL resolves the storefront language with its fallback chain
            // (what the SQL did with the COALESCE over product_translation).
            $name = $product->getTranslation('name');

            $packages[] = [
                'productId' => $productId,
                'productNumber' => $product->getProductNumber(),
                'name' => is_string($name) ? $name : null,
            ];
        }

        usort(
            $packages,
            static fn (array $left, array $right): int => $left['productNumber'] <=> $right['productNumber'],
        );

        return $packages;
    }
}
