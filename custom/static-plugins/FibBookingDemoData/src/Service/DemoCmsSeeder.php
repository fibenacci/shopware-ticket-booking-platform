<?php

declare(strict_types=1);

namespace FibBookingDemoData\Service;

use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

/**
 * Creates the demo CMS layout containing the booking-calendar element and
 * assigns it as the homepage of every storefront sales channel — `make up`
 * boots straight into a bookable calendar.
 */
class DemoCmsSeeder
{
    /**
     * @param EntityRepository<\Shopware\Core\Content\Cms\CmsPageCollection>              $cmsPageRepository
     * @param EntityRepository<\Shopware\Core\Content\Category\CategoryCollection>        $categoryRepository
     * @param EntityRepository<\Shopware\Core\System\SalesChannel\SalesChannelCollection> $salesChannelRepository
     */
    public function __construct(
        private readonly EntityRepository $cmsPageRepository,
        private readonly EntityRepository $categoryRepository,
        private readonly EntityRepository $salesChannelRepository,
    ) {
    }

    /**
     * Creates a CMS layout containing the booking-calendar element (configured
     * with the package resource) and assigns it as the homepage of every
     * storefront sales channel's root category.
     */
    public function seedHomepageWithCalendar(SeedSection $homepage, string $calendarResourceId, Context $context, ?string $cinemaResourceId = null): bool
    {
        $pageId = SeedIds::stable('cms:homepage');

        $blocks = [
            $this->buildCalendarBlock('cms:homepage:block', 0, $calendarResourceId, $homepage->int('monthsAhead', 3)),
        ];

        // Second calendar bound to the seatmap demo (cinema) — shows the
        // numbered-seat flow right on the start page.
        $cinemaCalendar = $homepage->sectionOrNull('cinemaCalendar');
        if ($cinemaResourceId !== null && $cinemaCalendar !== null) {
            $blocks[] = $this->buildCalendarBlock('cms:homepage:cinema-block', 1, $cinemaResourceId, $cinemaCalendar->int('monthsAhead', 1));
        }

        $scannerLink = $homepage->sectionOrNull('scannerLink');
        if ($scannerLink !== null) {
            $blocks[] = $this->buildScannerLinkBlock($scannerLink, position: count($blocks));
        }

        $this->cmsPageRepository->upsert([
            [
                'id' => $pageId,
                'type' => 'page',
                'name' => $homepage->string('name', 'FIB Booking Home'),
                'sections' => [
                    [
                        'id' => SeedIds::stable('cms:homepage:section'),
                        'type' => 'default',
                        'position' => 0,
                        'blocks' => $blocks,
                    ],
                ],
            ],
        ], $context);

        if (!$homepage->bool('assignToHomepage')) {
            return false;
        }

        // Point every storefront sales channel's entry category at the layout.
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('typeId', Defaults::SALES_CHANNEL_TYPE_STOREFRONT));
        $salesChannels = $this->salesChannelRepository->search($criteria, $context);

        $categoryUpdates = [];
        foreach ($salesChannels as $salesChannel) {
            $navigationCategoryId = $salesChannel->getNavigationCategoryId();
            $categoryUpdates[$navigationCategoryId] = [
                'id' => $navigationCategoryId,
                'cmsPageId' => $pageId,
            ];
        }

        if ($categoryUpdates !== []) {
            $this->categoryRepository->update(array_values($categoryUpdates), $context);
        }

        return $categoryUpdates !== [];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCalendarBlock(string $seedKey, int $position, string $resourceId, int $monthsAhead): array
    {
        return [
            'id' => SeedIds::stable($seedKey),
            'type' => 'fib-booking-calendar',
            'position' => $position,
            'sectionPosition' => 'main',
            'slots' => [
                [
                    'id' => SeedIds::stable($seedKey . ':slot'),
                    'type' => 'fib-booking-calendar',
                    'slot' => 'calendar',
                    'config' => [
                        'resourceId' => ['source' => 'static', 'value' => $resourceId],
                        'monthsAhead' => ['source' => 'static', 'value' => $monthsAhead],
                    ],
                ],
            ],
        ];
    }

    /**
     * Standard text block linking operators to the scanner app login —
     * rendered below the calendars on the seeded homepage.
     *
     * @return array<string, mixed>
     */
    private function buildScannerLinkBlock(SeedSection $scannerLink, int $position = 1): array
    {
        $url = htmlspecialchars($scannerLink->string('url', '/scanner/'), \ENT_QUOTES);
        $headline = htmlspecialchars($scannerLink->string('headline', 'Operator area'), \ENT_QUOTES);
        $label = htmlspecialchars($scannerLink->string('label', 'Open ticket scanner'), \ENT_QUOTES);
        $hint = $scannerLink->has('hint') ? htmlspecialchars($scannerLink->string('hint'), \ENT_QUOTES) : '';

        $content = sprintf(
            '<h3>%s</h3><p><a class="btn btn-outline-primary" href="%s" target="_blank" rel="noopener">%s</a></p>%s',
            $headline,
            $url,
            $label,
            $hint !== '' ? sprintf('<p><small>%s</small></p>', $hint) : '',
        );

        return [
            'id' => SeedIds::stable('cms:homepage:scanner-block'),
            'type' => 'text',
            'position' => $position,
            'sectionPosition' => 'main',
            'slots' => [
                [
                    'id' => SeedIds::stable('cms:homepage:scanner-slot'),
                    'type' => 'text',
                    'slot' => 'content',
                    'config' => [
                        'content' => ['source' => 'static', 'value' => $content],
                        'verticalAlign' => ['source' => 'static', 'value' => null],
                    ],
                ],
            ],
        ];
    }
}
