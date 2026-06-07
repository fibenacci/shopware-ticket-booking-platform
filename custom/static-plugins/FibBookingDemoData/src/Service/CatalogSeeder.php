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
    public function seedCatalog(
        SeedSection $seeds,
        Context $context,
    ): array {
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

    private function upsertResource(
        SeedSection $resource,
        Context $context,
    ): string {
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
    private function seedSeatGrid(
        SeedSection $seating,
        string $resourceKey,
        string $resourceId,
        Context $context,
    ): void {
        $rows = max(1, min(26, $seating->int('rows', 5)));
        $seatsPerRow = max(1, $seating->int('seatsPerRow', 8));

        // A free-form layout block turns the plain grid into canvas geometry
        // (px coordinates, a stage, category bands) — the same shape the 2D
        // editor produces, so the demo cinema shows off the rich variant.
        $layout = $seating->sectionOrNull('layout');
        $canvas = $layout !== null
            ? ['width' => $layout->int('canvasWidth', 1000), 'height' => $layout->int('canvasHeight', 700)]
            : null;
        $spacing = $layout?->int('seatSpacing', 40) ?? 40;
        $rowSpacing = $layout?->int('rowSpacing', 46) ?? 46;
        $topOffset = $layout?->int('topOffset', 150) ?? 150;
        $categoryByRow = $this->categoryByRow($layout);

        $payload = [];
        for ($row = 0; $row < $rows; ++$row) {
            $rowLabel = chr(ord('A') + $row);
            $rowWidth = ($seatsPerRow - 1) * $spacing;
            $startX = $canvas !== null ? (int) (($canvas['width'] - $rowWidth) / 2) : 0;

            for ($seat = 1; $seat <= $seatsPerRow; ++$seat) {
                $payload[] = [
                    'id' => SeedIds::stable(sprintf('seat:%s:%s%d', $resourceKey, $rowLabel, $seat)),
                    'resourceId' => $resourceId,
                    'rowLabel' => $rowLabel,
                    'seatLabel' => (string) $seat,
                    'posX' => $canvas !== null ? $startX + ($seat - 1) * $spacing : $seat,
                    'posY' => $canvas !== null ? $topOffset + $row * $rowSpacing : $row + 1,
                    'category' => $categoryByRow[$rowLabel] ?? null,
                    'active' => true,
                ];
            }
        }

        $this->seatRepository->upsert($payload, $context);

        if ($layout !== null && $canvas !== null) {
            $this->writeResourceLayout($resourceId, $layout, $canvas, $seatsPerRow, $spacing, $context);
        }
    }

    /**
     * Reuse an existing row's id (id-scheme changes never collide), else a
     * deterministic stable id from the seed string.
     */
    private function reuseOrStableId(
        mixed $existingId,
        string $seed,
    ): string {
        return is_string($existingId) ? $existingId : SeedIds::stable($seed);
    }

    /**
     * Row label → category key, from the layout's category bands.
     *
     * @return array<string, string>
     */
    private function categoryByRow(?SeedSection $layout): array
    {
        if ($layout === null) {
            return [];
        }

        $map = [];
        foreach ($layout->sections('categories') as $category) {
            foreach ($category->stringList('rows', []) as $rowLabel) {
                $map[$rowLabel] = $category->string('key');
            }
        }

        return $map;
    }

    /**
     * Writes the resource's free-form layout JSON (canvas, a stage element,
     * category palette) — exactly the shape the 2D editor persists.
     *
     * @param array{width: int, height: int} $canvas
     */
    private function writeResourceLayout(
        string $resourceId,
        SeedSection $layout,
        array $canvas,
        int $seatsPerRow,
        int $spacing,
        Context $context,
    ): void {
        $elements = [];
        if ($layout->bool('stage')) {
            // Stage spans the seat block (a touch wider) so the room reads
            // proportionally instead of a wide screen over a narrow block.
            $blockWidth = ($seatsPerRow - 1) * $spacing + 40;
            $stageWidth = (int) min($canvas['width'] - 80, $blockWidth + 80);

            $elements[] = [
                'id' => 'stage',
                'type' => 'stage',
                'x' => (int) (($canvas['width'] - $stageWidth) / 2),
                'y' => 30,
                'width' => $stageWidth,
                'height' => 48,
                'rotation' => 0,
                'label' => $layout->string('stageLabel', 'SCREEN'),
                'color' => null,
            ];
        }

        $categories = array_map(static fn (SeedSection $category): array => [
            'key' => $category->string('key'),
            'name' => $category->string('name'),
            'color' => $category->has('color') ? $category->string('color') : null,
        ], $layout->sections('categories'));

        $this->resourceRepository->upsert([
            [
                'id' => $resourceId,
                'layout' => [
                    'canvas' => $canvas,
                    'elements' => $elements,
                    'categories' => $categories,
                ],
            ],
        ], $context);
    }

    /**
     * @param list<string> $salesChannelIds
     *
     * @return ProductSummary
     */
    private function upsertBookableProduct(
        SeedSection $product,
        string $resourceId,
        string $taxId,
        array $salesChannelIds,
        Context $context,
        ?int $slotMinutes = null,
    ): array {
        $productNumber = $product->string('productNumber');
        $grossPrice = $product->float('grossPrice');

        // Reuse existing rows keyed by their unique columns so id-scheme
        // changes between seeder versions never collide.
        $existingProductId = $this->productRepository->searchIds(
            (new Criteria())->addFilter(new EqualsFilter('productNumber', $productNumber)),
            $context,
        )->firstId();
        $productId = $this->reuseOrStableId($existingProductId, 'product:' . $productNumber);

        $existingConfigId = $this->productConfigRepository->searchIds(
            (new Criteria())->addFilter(new EqualsFilter('productId', $productId)),
            $context,
        )->firstId();
        $configId = $this->reuseOrStableId($existingConfigId, 'product-config:' . $productNumber);

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

        $this->productConfigRepository->upsert([
            [
                'id' => $configId,
                'productId' => $productId,
                'productVersionId' => Defaults::LIVE_VERSION,
                'resourceId' => $resourceId,
                'enabled' => true,
                'slotMinutes' => $slotMinutes ?? $product->int('slotMinutes', 60),
                ...$this->validityConfig($product),
                ...$this->rotatingQrConfig($product),
            ],
        ], $context);

        return [
            'id' => $productId,
            'number' => $productNumber,
            'name' => $product->string('name'),
            'resourceId' => $resourceId,
        ];
    }

    /**
     * Optional generic validity model (period passes etc.) — empty (slot/single
     * defaults) when the seed omits it.
     *
     * @return array<string, mixed>
     */
    private function validityConfig(SeedSection $product): array
    {
        $validity = $product->sectionOrNull('validity');

        if ($validity === null) {
            return [];
        }

        return [
            'validityMode' => $validity->string('mode', 'slot'),
            'validityDuration' => $validity->has('duration') ? $validity->string('duration') : null,
            'validityAnchor' => $validity->has('anchor') ? $validity->string('anchor') : null,
            'entryPolicy' => $validity->string('entryPolicy', 'single'),
            'maxEntriesPerDay' => $validity->has('maxEntriesPerDay') ? $validity->int('maxEntriesPerDay') : null,
        ];
    }

    /**
     * Optional rotating QR (TOTP) — the operator switch, off unless the seed
     * opts in (the transit passes do, as the demo's "Fahrkarten").
     *
     * @return array<string, mixed>
     */
    private function rotatingQrConfig(SeedSection $product): array
    {
        $rotating = $product->sectionOrNull('rotatingQr');

        if ($rotating === null) {
            return [];
        }

        return [
            'rotatingQrEnabled' => $rotating->bool('enabled'),
            'rotatingQrInterval' => $rotating->has('interval') ? $rotating->int('interval') : null,
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
    private function seedPackages(
        SeedSection $packages,
        string $taxId,
        array $salesChannelIds,
        Context $context,
    ): array {
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

    private function seedSlots(
        SeedSection $slotConfig,
        string $resourceId,
        Context $context,
    ): int {
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
