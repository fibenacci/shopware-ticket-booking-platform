<?php

declare(strict_types=1);

namespace FibBookingDemoData\Service;

use DateInterval;
use DateTimeImmutable;
use RuntimeException;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

/**
 * Seeds the bookable catalog through the DAL:
 *
 * - booking resources + bookable products (one resource each)
 * - a "packages" scenario: Starter/Premium/Luxury products sharing ONE
 *   resource whose capacity is consumed via operator-defined slots (Termine)
 * - slots for the next N days (relative — re-seeding keeps the calendar full)
 *
 * @phpstan-type ProductSummary array{id: string, number: string, name: string, resourceId: string}
 * @phpstan-type CatalogResult array{resourceIds: array<string, string>, resources: list<array{id: string, name: string}>, products: list<ProductSummary>, packages: list<ProductSummary>, slots: int}
 */
class CatalogSeeder
{
    /**
     * @param EntityRepository<\Shopware\Core\Content\Product\ProductCollection>                                   $productRepository
     * @param EntityRepository<\Shopware\Core\System\SalesChannel\SalesChannelCollection>                          $salesChannelRepository
     * @param EntityRepository<\Shopware\Core\System\Tax\TaxCollection>                                            $taxRepository
     * @param EntityRepository<\FibBookingSystem\Core\Content\BookingResource\BookingResourceCollection>           $resourceRepository
     * @param EntityRepository<\FibBookingSystem\Core\Content\ProductBookingConfig\ProductBookingConfigCollection> $productConfigRepository
     * @param EntityRepository<\FibBookingSystem\Core\Content\BookingSlot\BookingSlotCollection>                   $slotRepository
     * @param EntityRepository<\FibBookingSystem\Core\Content\BookingSeat\BookingSeatCollection>                   $seatRepository
     */
    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $salesChannelRepository,
        private readonly EntityRepository $taxRepository,
        private readonly EntityRepository $resourceRepository,
        private readonly EntityRepository $productConfigRepository,
        private readonly EntityRepository $slotRepository,
        private readonly EntityRepository $seatRepository,
    ) {
    }

    /**
     * @return CatalogResult
     */
    public function seedCatalog(SeedSection $seeds, Context $context): array
    {
        $taxId = $this->fetchTaxId($context);
        $salesChannelIds = $this->fetchStorefrontSalesChannelIds($context);

        $resourceIds = [];
        $resources = [];
        foreach ($seeds->sections('resources') as $resource) {
            $resourceId = $this->upsertResource($resource, $context);
            $resourceIds[$resource->string('key')] = $resourceId;
            $resources[] = ['id' => $resourceId, 'name' => $resource->string('name')];

            // Numbered seating (e.g. the demo cinema): generate the seat grid.
            $seating = $resource->sectionOrNull('seating');
            if ($seating !== null && $seating->string('mode', 'pool') === 'seatmap') {
                $this->seedSeatGrid($seating, $resource->string('key'), $resourceId, $context);
            }

            // Standalone slot plans (resources outside the packages scenario).
            $resourceSlots = $resource->sectionOrNull('slots');
            if ($resourceSlots !== null) {
                $this->seedSlots($resourceSlots, $resourceId, $context);
            }
        }

        $products = [];
        foreach ($seeds->sections('products') as $product) {
            $resourceId = $resourceIds[$product->string('resource')]
                ?? throw new RuntimeException(sprintf('Product "%s" references unknown resource key "%s".', $product->string('productNumber'), $product->string('resource')));
            $products[] = $this->upsertBookableProduct($product, $resourceId, $taxId, $salesChannelIds, $context);
        }

        [$packages, $slotCount] = $this->seedPackages($seeds->section('packages'), $taxId, $salesChannelIds, $context);

        return [
            'resourceIds' => $resourceIds,
            'resources' => $resources,
            'products' => $products,
            'packages' => $packages,
            'slots' => $slotCount,
        ];
    }

    private function upsertResource(SeedSection $resource, Context $context): string
    {
        // Reuse an existing resource with the same technical name (unique key)
        // so id-scheme changes between seeder versions never collide.
        $existingId = $this->resourceRepository->searchIds(
            (new Criteria())->addFilter(new EqualsFilter('technicalName', $resource->string('technicalName'))),
            $context,
        )->firstId();

        $id = is_string($existingId) ? $existingId : SeedIds::stable('resource:' . $resource->string('key'));

        $seating = $resource->sectionOrNull('seating');

        $this->resourceRepository->upsert([
            [
                'id' => $id,
                'name' => $resource->string('name'),
                'technicalName' => $resource->string('technicalName'),
                'capacity' => $resource->int('capacity'),
                'seatingMode' => $seating?->string('mode', 'pool') ?? 'pool',
                'active' => true,
                'configuration' => ['demo' => true],
            ],
        ], $context);

        return $id;
    }

    /**
     * Cinema-style rectangle: rows × seatsPerRow, row labels A, B, C, …
     * Idempotent via stable ids — re-seeding updates in place.
     */
    private function seedSeatGrid(SeedSection $seating, string $resourceKey, string $resourceId, Context $context): void
    {
        $rows = max(1, min(26, $seating->int('rows', 5)));
        $seatsPerRow = max(1, $seating->int('seatsPerRow', 8));

        $payload = [];
        for ($row = 0; $row < $rows; ++$row) {
            $rowLabel = chr(ord('A') + $row);

            for ($seat = 1; $seat <= $seatsPerRow; ++$seat) {
                $payload[] = [
                    'id' => SeedIds::stable(sprintf('seat:%s:%s%d', $resourceKey, $rowLabel, $seat)),
                    'resourceId' => $resourceId,
                    'rowLabel' => $rowLabel,
                    'seatLabel' => (string) $seat,
                    'posX' => $seat,
                    'posY' => $row + 1,
                    'active' => true,
                ];
            }
        }

        $this->seatRepository->upsert($payload, $context);
    }

    /**
     * @param list<string> $salesChannelIds
     *
     * @return ProductSummary
     */
    private function upsertBookableProduct(SeedSection $product, string $resourceId, string $taxId, array $salesChannelIds, Context $context, ?int $slotMinutes = null): array
    {
        $productNumber = $product->string('productNumber');
        $grossPrice = $product->float('grossPrice');

        // Reuse existing rows keyed by their unique columns so id-scheme
        // changes between seeder versions never collide.
        $existingProductId = $this->productRepository->searchIds(
            (new Criteria())->addFilter(new EqualsFilter('productNumber', $productNumber)),
            $context,
        )->firstId();
        $productId = is_string($existingProductId) ? $existingProductId : SeedIds::stable('product:' . $productNumber);

        $existingConfigId = $this->productConfigRepository->searchIds(
            (new Criteria())->addFilter(new EqualsFilter('productId', $productId)),
            $context,
        )->firstId();
        $configId = is_string($existingConfigId) ? $existingConfigId : SeedIds::stable('product-config:' . $productNumber);

        $payload = [
            'id' => $productId,
            'name' => $product->string('name'),
            'productNumber' => $productNumber,
            'stock' => 1000,
            'active' => true,
            'taxId' => $taxId,
            'price' => [
                [
                    'currencyId' => Defaults::CURRENCY,
                    'gross' => $grossPrice,
                    'net' => $grossPrice / 1.19,
                    'linked' => true,
                ],
            ],
        ];

        // Visibilities only for NEW products — existing ones already carry
        // them (unique on product_id + sales_channel_id).
        if ($existingProductId === null) {
            $payload['visibilities'] = array_map(static fn (string $salesChannelId): array => [
                'id' => SeedIds::stable('visibility:' . $productNumber . ':' . $salesChannelId),
                'salesChannelId' => $salesChannelId,
                'visibility' => 30,
            ], $salesChannelIds);
        }

        $this->productRepository->upsert([$payload], $context);

        $config = [
            'id' => $configId,
            'productId' => $productId,
            'productVersionId' => Defaults::LIVE_VERSION,
            'resourceId' => $resourceId,
            'enabled' => true,
            'slotMinutes' => $slotMinutes ?? $product->int('slotMinutes', 60),
        ];

        // Optional generic validity model (period passes etc.) — defaults to
        // the slot/single behavior when absent.
        $validity = $product->sectionOrNull('validity');
        if ($validity !== null) {
            $config['validityMode'] = $validity->string('mode', 'slot');
            $config['validityDuration'] = $validity->has('duration') ? $validity->string('duration') : null;
            $config['validityAnchor'] = $validity->has('anchor') ? $validity->string('anchor') : null;
            $config['entryPolicy'] = $validity->string('entryPolicy', 'single');
            $config['maxEntriesPerDay'] = $validity->has('maxEntriesPerDay') ? $validity->int('maxEntriesPerDay') : null;
        }

        $this->productConfigRepository->upsert([$config], $context);

        return [
            'id' => $productId,
            'number' => $productNumber,
            'name' => $product->string('name'),
            'resourceId' => $resourceId,
        ];
    }

    /**
     * Packages scenario: multiple products (Starter/Premium/Luxury) share one
     * resource; bookability is driven by that resource's slots.
     *
     * @param list<string> $salesChannelIds
     *
     * @return array{0: list<ProductSummary>, 1: int}
     */
    private function seedPackages(SeedSection $packages, string $taxId, array $salesChannelIds, Context $context): array
    {
        $resourceId = $this->upsertResource($packages->section('resource'), $context);
        $slots = $packages->section('slots');
        $slotMinutes = $slots->int('durationMinutes', 120);

        $packageProducts = [];
        foreach ($packages->sections('products') as $product) {
            $packageProducts[] = $this->upsertBookableProduct($product, $resourceId, $taxId, $salesChannelIds, $context, $slotMinutes);
        }

        $slotCount = $this->seedSlots($slots, $resourceId, $context);

        return [$packageProducts, $slotCount];
    }

    private function seedSlots(SeedSection $slotConfig, string $resourceId, Context $context): int
    {
        $daysAhead = max(1, $slotConfig->int('daysAhead', 14));
        $duration = max(5, $slotConfig->int('durationMinutes', 120));
        $capacity = max(1, $slotConfig->int('capacity', 10));
        $times = $slotConfig->stringList('times', ['18:00']);

        $payload = [];
        $today = new DateTimeImmutable('today');

        for ($offset = 1; $offset <= $daysAhead; ++$offset) {
            $day = $today->add(new DateInterval('P' . $offset . 'D'));

            foreach ($times as $time) {
                [$hour, $minute] = array_map(intval(...), explode(':', $time));
                $startsAt = $day->setTime($hour, $minute);

                $payload[] = [
                    'id' => SeedIds::stable('slot:' . $resourceId . ':' . $startsAt->format('Y-m-d H:i')),
                    'resourceId' => $resourceId,
                    'startsAt' => $startsAt->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                    'endsAt' => $startsAt->add(new DateInterval(sprintf('PT%dM', $duration)))->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                    'capacity' => $capacity,
                    'active' => true,
                ];
            }
        }

        $this->slotRepository->upsert($payload, $context);

        return count($payload);
    }

    private function fetchTaxId(Context $context): string
    {
        $criteria = (new Criteria())->setLimit(1);
        $taxId = $this->taxRepository->searchIds($criteria, $context)->firstId();

        if (!is_string($taxId)) {
            throw new RuntimeException('No tax entity found — is Shopware fully installed?');
        }

        return $taxId;
    }

    /**
     * @return list<string>
     */
    private function fetchStorefrontSalesChannelIds(Context $context): array
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('typeId', Defaults::SALES_CHANNEL_TYPE_STOREFRONT));

        /** @var list<string> $ids */
        $ids = $this->salesChannelRepository->searchIds($criteria, $context)->getIds();

        if ($ids === []) {
            throw new RuntimeException('No storefront sales channel found — is Shopware fully installed?');
        }

        return $ids;
    }
}
