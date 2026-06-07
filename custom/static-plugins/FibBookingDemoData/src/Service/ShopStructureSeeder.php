<?php

declare(strict_types=1);

namespace FibBookingDemoData\Service;

use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

/**
 * Seeds the page structure a ticket shop actually needs:
 *
 * - main navigation categories per offer type (events, experiences, cinema,
 *   passes) with the demo products assigned,
 * - a footer tree with the standard legal pages (imprint, privacy, terms,
 *   right of withdrawal — clearly marked DEMO PLACEHOLDERS, not legal
 *   advice),
 * - a service navigation with the domain pages (how booking works, FAQ,
 *   contact).
 *
 * Every page is a simple text CMS layout; categories/pages are upserted via
 * stable ids, so re-seeding updates in place. Footer/service roots are only
 * wired into sales channels that do not have one yet — an existing shop
 * structure is never hijacked.
 */
class ShopStructureSeeder
{
    /**
     * @param EntityRepository<\Shopware\Core\Content\Cms\CmsPageCollection>              $cmsPageRepository
     * @param EntityRepository<\Shopware\Core\Content\Category\CategoryCollection>        $categoryRepository
     * @param EntityRepository<\Shopware\Core\System\SalesChannel\SalesChannelCollection> $salesChannelRepository
     * @param EntityRepository<\Shopware\Core\Content\Product\ProductCollection>          $productRepository
     */
    public function __construct(
        private readonly EntityRepository $cmsPageRepository,
        private readonly EntityRepository $categoryRepository,
        private readonly EntityRepository $salesChannelRepository,
        private readonly EntityRepository $productRepository,
    ) {
    }

    /**
     * @return array{navigationCategories: int, pages: int}
     */
    public function seedShopStructure(
        SeedSection $shop,
        Context $context,
    ): array {
        $salesChannels = $this->fetchStorefrontSalesChannels($context);

        if ($salesChannels === []) {
            return ['navigationCategories' => 0, 'pages' => 0];
        }

        $navigationCount = $this->seedNavigation($shop, $salesChannels, $context);
        $pageCount = $this->seedFooterTree($shop->sectionOrNull('footer'), $salesChannels, $context);
        $pageCount += $this->seedServiceTree($shop->sectionOrNull('service'), $salesChannels, $context);

        return ['navigationCategories' => $navigationCount, 'pages' => $pageCount];
    }

    /**
     * @param list<SalesChannelEntity> $salesChannels
     */
    private function seedNavigation(
        SeedSection $shop,
        array $salesChannels,
        Context $context,
    ): int {
        $listingLayoutId = $this->fetchDefaultListingLayoutId($context);
        $count = 0;

        foreach ($salesChannels as $salesChannel) {
            $parentId = $salesChannel->getNavigationCategoryId();
            $navCategories = $shop->sections('navigation');

            foreach ($navCategories as $position => $navCategory) {
                $categoryId = SeedIds::stable(sprintf('category:nav:%s:%s', $salesChannel->getId(), $navCategory->string('key')));
                $afterCategoryId = $position === 0
                    ? null
                    : SeedIds::stable(sprintf('category:nav:%s:%s', $salesChannel->getId(), $navCategories[$position - 1]->string('key')));

                $payload = $navCategory->has('link')
                    ? $this->linkCategoryPayload($categoryId, $parentId, $navCategory, $afterCategoryId)
                    : $this->listingCategoryPayload($categoryId, $parentId, $navCategory, $afterCategoryId, $listingLayoutId);

                $this->categoryRepository->upsert([$payload], $context);

                if (!$navCategory->has('link')) {
                    $this->assignProducts($navCategory->stringList('products', []), $categoryId, $context);
                }

                ++$count;
            }
        }

        return $count;
    }

    /**
     * A product-listing navigation category (the offer-type menus).
     *
     * @return array<string, mixed>
     */
    private function listingCategoryPayload(
        string $categoryId,
        string $parentId,
        SeedSection $navCategory,
        ?string $afterCategoryId,
        ?string $listingLayoutId,
    ): array {
        return [
            'id' => $categoryId,
            'parentId' => $parentId,
            'name' => $navCategory->string('name'),
            'description' => $navCategory->string('description', ''),
            'active' => true,
            'displayNestedProducts' => true,
            'type' => 'page',
            'productAssignmentType' => 'product',
            'cmsPageId' => $listingLayoutId,
            'afterCategoryId' => $afterCategoryId,
        ];
    }

    /**
     * A "link" navigation category that points at a (SEO) URL instead of a
     * CMS page — e.g. the resale market at its canonical `/resale-market`
     * slug. Mirrors what a merchant sets up in the admin under a category of
     * type "Link" → "External".
     *
     * @return array<string, mixed>
     */
    private function linkCategoryPayload(
        string $categoryId,
        string $parentId,
        SeedSection $navCategory,
        ?string $afterCategoryId,
    ): array {
        return [
            'id' => $categoryId,
            'parentId' => $parentId,
            'name' => $navCategory->string('name'),
            'description' => $navCategory->string('description', ''),
            'active' => true,
            'type' => 'link',
            'linkType' => 'external',
            'externalLink' => $navCategory->string('link'),
            'linkNewTab' => false,
            'afterCategoryId' => $afterCategoryId,
        ];
    }

    /**
     * Footer structure: root → column(s) → page links. Shopware renders the
     * CHILDREN of the footer root as columns and their children as links.
     *
     * @param list<SalesChannelEntity> $salesChannels
     */
    private function seedFooterTree(
        ?SeedSection $footer,
        array $salesChannels,
        Context $context,
    ): int {
        if ($footer === null) {
            return 0;
        }

        $rootId = $this->upsertRootCategory('footer-root', $footer->string('rootName', 'Demo Footer'), $context);
        $pageCount = 0;

        foreach ($footer->sections('columns') as $column) {
            $columnId = SeedIds::stable('category:footer-column:' . $column->string('key'));
            $this->categoryRepository->upsert([
                [
                    'id' => $columnId,
                    'parentId' => $rootId,
                    'name' => $column->string('name'),
                    'active' => true,
                    'type' => 'folder',
                ],
            ], $context);

            $pageCount += $this->seedPageLinks($column->sections('pages'), $columnId, 'footer', $context);
        }

        $this->wireSalesChannelRoot($salesChannels, 'footerCategoryId', $rootId, $context);

        return $pageCount;
    }

    /**
     * Service navigation (top bar): root → page links, one level.
     *
     * @param list<SalesChannelEntity> $salesChannels
     */
    private function seedServiceTree(
        ?SeedSection $service,
        array $salesChannels,
        Context $context,
    ): int {
        if ($service === null) {
            return 0;
        }

        $rootId = $this->upsertRootCategory('service-root', $service->string('rootName', 'Demo Service'), $context);
        $pageCount = $this->seedPageLinks($service->sections('pages'), $rootId, 'service', $context);

        $this->wireSalesChannelRoot($salesChannels, 'serviceCategoryId', $rootId, $context);

        return $pageCount;
    }

    /**
     * @param list<SeedSection> $pages
     */
    private function seedPageLinks(
        array $pages,
        string $parentId,
        string $scope,
        Context $context,
    ): int {
        $count = 0;

        foreach ($pages as $page) {
            $cmsPageId = $this->upsertTextPage($page, $scope, $context);

            $this->categoryRepository->upsert([
                [
                    'id' => SeedIds::stable(sprintf('category:%s-page:%s', $scope, $page->string('key'))),
                    'parentId' => $parentId,
                    'name' => $page->string('name'),
                    'active' => true,
                    'type' => 'page',
                    'cmsPageId' => $cmsPageId,
                ],
            ], $context);

            ++$count;
        }

        return $count;
    }

    /**
     * One-section/one-text-block shop page — content comes verbatim from the
     * seed file (trusted, ships with the plugin).
     */
    private function upsertTextPage(
        SeedSection $page,
        string $scope,
        Context $context,
    ): string {
        $pageId = SeedIds::stable(sprintf('cms:%s-page:%s', $scope, $page->string('key')));

        $blocks = [
            [
                'id' => SeedIds::stable($pageId . ':block'),
                'type' => 'text',
                'position' => 0,
                'sectionPosition' => 'main',
                'slots' => [
                    [
                        'id' => SeedIds::stable($pageId . ':slot'),
                        'type' => 'text',
                        'slot' => 'content',
                        'config' => [
                            'content' => ['source' => 'static', 'value' => $page->string('content')],
                            'verticalAlign' => ['source' => 'static', 'value' => null],
                        ],
                    ],
                ],
            ],
        ];

        // The contact page carries a REAL contact form (system default mail
        // receiver) below its intro text — a demo shop must be contactable.
        if ($page->bool('contactForm')) {
            $blocks[] = [
                'id' => SeedIds::stable($pageId . ':form-block'),
                'type' => 'form',
                'position' => 1,
                'sectionPosition' => 'main',
                'slots' => [
                    [
                        'id' => SeedIds::stable($pageId . ':form-slot'),
                        'type' => 'form',
                        'slot' => 'content',
                        'config' => [
                            'type' => ['source' => 'static', 'value' => 'contact'],
                            'title' => ['source' => 'static', 'value' => $page->string('name')],
                            'mailReceiver' => ['source' => 'static', 'value' => []],
                            'defaultMailReceiver' => ['source' => 'static', 'value' => true],
                            'confirmationText' => ['source' => 'static', 'value' => ''],
                        ],
                    ],
                ],
            ];
        }

        $this->cmsPageRepository->upsert([
            [
                'id' => $pageId,
                'type' => 'page',
                'name' => 'Demo — ' . $page->string('name'),
                'sections' => [
                    [
                        'id' => SeedIds::stable($pageId . ':section'),
                        'type' => 'default',
                        'position' => 0,
                        'blocks' => $blocks,
                    ],
                ],
            ],
        ], $context);

        return $pageId;
    }

    private function upsertRootCategory(
        string $seedKey,
        string $name,
        Context $context,
    ): string {
        $rootId = SeedIds::stable('category:' . $seedKey);

        $this->categoryRepository->upsert([
            [
                'id' => $rootId,
                'name' => $name,
                'active' => true,
                'type' => 'folder',
            ],
        ], $context);

        return $rootId;
    }

    /**
     * Points the sales channel's footer/service root at the seeded tree —
     * but ONLY when none is configured yet (never hijack a real shop).
     *
     * @param list<SalesChannelEntity> $salesChannels
     */
    private function wireSalesChannelRoot(
        array $salesChannels,
        string $field,
        string $rootId,
        Context $context,
    ): void {
        $updates = [];

        foreach ($salesChannels as $salesChannel) {
            $current = $field === 'footerCategoryId'
                ? $salesChannel->getFooterCategoryId()
                : $salesChannel->getServiceCategoryId();

            if ($current === null || $current === $rootId) {
                $updates[] = ['id' => $salesChannel->getId(), $field => $rootId];
            }
        }

        if ($updates !== []) {
            $this->salesChannelRepository->update($updates, $context);
        }
    }

    /**
     * @param list<string> $productNumbers
     */
    private function assignProducts(
        array $productNumbers,
        string $categoryId,
        Context $context,
    ): void {
        if ($productNumbers === []) {
            return;
        }

        // One lookup per number — the list is tiny and a missing product
        // (e.g. partially seeded catalog) simply skips instead of failing.
        $updates = [];
        foreach ($productNumbers as $number) {
            $productId = $this->productRepository->searchIds(
                (new Criteria())->addFilter(new EqualsFilter('productNumber', $number)),
                $context,
            )->firstId();

            if (is_string($productId)) {
                $updates[] = ['id' => $productId, 'categories' => [['id' => $categoryId]]];
            }
        }

        if ($updates !== []) {
            $this->productRepository->update($updates, $context);
        }
    }

    /**
     * The stock "Default listing layout" — category listings need a CMS
     * layout to render products.
     */
    private function fetchDefaultListingLayoutId(Context $context): ?string
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('type', 'product_list'))
            ->addFilter(new EqualsFilter('locked', true))
            ->setLimit(1);

        $id = $this->cmsPageRepository->searchIds($criteria, $context)->firstId();

        return is_string($id) ? $id : null;
    }

    /**
     * @return list<SalesChannelEntity>
     */
    private function fetchStorefrontSalesChannels(Context $context): array
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('typeId', Defaults::SALES_CHANNEL_TYPE_STOREFRONT));

        /** @var list<SalesChannelEntity> $salesChannels */
        $salesChannels = array_values($this->salesChannelRepository->search($criteria, $context)->getEntities()->getElements());

        return $salesChannels;
    }
}
