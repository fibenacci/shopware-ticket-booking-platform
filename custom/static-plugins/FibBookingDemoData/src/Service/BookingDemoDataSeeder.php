<?php

declare(strict_types=1);

namespace FibBookingDemoData\Service;

use DateInterval;
use DateTimeImmutable;
use FibBookingSystem\Core\Domain\Ticket\BookingTicketService;
use FibBookingSystem\FibBookingException;
use RuntimeException;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

/**
 * Seeds booking demo data through the DAL. The seed DEFINITIONS live in
 * Resources/seeds/booking-demo.json — this class only interprets them:
 *
 * - booking resources + bookable products (one resource each)
 * - a "packages" scenario: Starter/Premium/Luxury products sharing ONE
 *   resource whose capacity is consumed via operator-defined slots (Termine)
 * - slots for the next N days (relative — re-seeding keeps the calendar full)
 * - a confirmed reservation with an issued QR ticket
 *
 * All ids are derived from stable seed strings, so re-running upserts instead
 * of duplicating — safe for repeated use in dev and CI.
 *
 * @phpstan-type SeedResult array{resources: list<array{id: string, name: string}>, products: list<array{id: string, number: string, name: string, resourceId: string}>, packages: list<array{id: string, number: string, name: string, resourceId: string}>, slots: int, reservationId: string|null, ticketNumber: string|null, homepageAssigned: bool, scannerUser: array{username: string, role: string}|null}
 */
class BookingDemoDataSeeder
{
    private const SEED_FILE = __DIR__ . '/../Resources/seeds/booking-demo.json';

    /**
     * @param EntityRepository<\Shopware\Core\Content\Product\ProductCollection>                                   $productRepository
     * @param EntityRepository<\Shopware\Core\System\SalesChannel\SalesChannelCollection>                          $salesChannelRepository
     * @param EntityRepository<\Shopware\Core\System\Tax\TaxCollection>                                            $taxRepository
     * @param EntityRepository<\FibBookingSystem\Core\Content\BookingResource\BookingResourceCollection>           $resourceRepository
     * @param EntityRepository<\FibBookingSystem\Core\Content\ProductBookingConfig\ProductBookingConfigCollection> $productConfigRepository
     * @param EntityRepository<\FibBookingSystem\Core\Content\BookingReservation\BookingReservationCollection>     $reservationRepository
     * @param EntityRepository<\FibBookingSystem\Core\Content\BookingSlot\BookingSlotCollection>                   $slotRepository
     * @param EntityRepository<\Shopware\Core\Content\Cms\CmsPageCollection>                                       $cmsPageRepository
     * @param EntityRepository<\Shopware\Core\Content\Category\CategoryCollection>                                 $categoryRepository
     * @param EntityRepository<\Shopware\Core\Framework\Api\Acl\Role\AclRoleCollection>                            $aclRoleRepository
     * @param EntityRepository<\Shopware\Core\System\User\UserCollection>                                          $userRepository
     * @param EntityRepository<\Shopware\Core\System\Locale\LocaleCollection>                                      $localeRepository
     */
    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly EntityRepository $salesChannelRepository,
        private readonly EntityRepository $taxRepository,
        private readonly EntityRepository $resourceRepository,
        private readonly EntityRepository $productConfigRepository,
        private readonly EntityRepository $reservationRepository,
        private readonly EntityRepository $slotRepository,
        private readonly EntityRepository $cmsPageRepository,
        private readonly EntityRepository $categoryRepository,
        private readonly EntityRepository $aclRoleRepository,
        private readonly EntityRepository $userRepository,
        private readonly EntityRepository $localeRepository,
        private readonly BookingTicketService $ticketService,
    ) {
    }

    /**
     * @return SeedResult
     */
    public function seed(Context $context, bool $withReservation = true): array
    {
        $seeds = $this->loadSeeds();

        $taxId = $this->fetchTaxId($context);
        $salesChannelIds = $this->fetchStorefrontSalesChannelIds($context);

        $resourceIds = [];
        $resources = [];
        foreach ($seeds['resources'] as $resource) {
            $resourceIds[$resource['key']] = $this->upsertResource($resource, $context);
            $resources[] = ['id' => $resourceIds[$resource['key']], 'name' => (string) $resource['name']];
        }

        $products = [];
        foreach ($seeds['products'] as $product) {
            $resourceId = $resourceIds[$product['resource']];
            $products[] = $this->upsertBookableProduct($product, $resourceId, $taxId, $salesChannelIds, $context);
        }

        [$packages, $slotCount] = $this->seedPackages($seeds['packages'], $taxId, $salesChannelIds, $context);

        $reservationId = null;
        $ticketNumber = null;
        if ($withReservation && isset($seeds['reservation'])) {
            [$reservationId, $ticketNumber] = $this->seedReservationWithTicket(
                $seeds['reservation'],
                $resourceIds[$seeds['reservation']['resource']],
                $context,
            );
        }

        $homepageAssigned = false;
        if (isset($seeds['cms']['homepage'])) {
            $calendarResourceKey = (string) $seeds['cms']['homepage']['calendarResource'];
            $calendarResourceId = $resourceIds[$calendarResourceKey] ?? $packages[0]['resourceId'] ?? null;

            if ($calendarResourceId !== null) {
                $homepageAssigned = $this->seedHomepageWithCalendar($seeds['cms']['homepage'], $calendarResourceId, $context);
            }
        }

        $scannerUser = null;
        if (isset($seeds['scanner'])) {
            $scannerUser = $this->seedScannerAccess($seeds['scanner'], $context);
        }

        return [
            'resources' => $resources,
            'products' => $products,
            'packages' => $packages,
            'slots' => $slotCount,
            'reservationId' => $reservationId,
            'ticketNumber' => $ticketNumber,
            'homepageAssigned' => $homepageAssigned,
            'scannerUser' => $scannerUser,
        ];
    }

    /**
     * Standard text block linking operators to the scanner app login —
     * rendered below the calendar on the seeded homepage.
     *
     * @param array<string, mixed> $scannerLink
     *
     * @return array<string, mixed>
     */
    private function buildScannerLinkBlock(array $scannerLink): array
    {
        $url = htmlspecialchars((string) ($scannerLink['url'] ?? '/scanner/'), \ENT_QUOTES);
        $headline = htmlspecialchars((string) ($scannerLink['headline'] ?? 'Operator area'), \ENT_QUOTES);
        $label = htmlspecialchars((string) ($scannerLink['label'] ?? 'Open ticket scanner'), \ENT_QUOTES);
        $hint = htmlspecialchars((string) ($scannerLink['hint'] ?? ''), \ENT_QUOTES);

        $content = sprintf(
            '<h3>%s</h3><p><a class="btn btn-outline-primary" href="%s" target="_blank" rel="noopener">%s</a></p>%s',
            $headline,
            $url,
            $label,
            $hint !== '' ? sprintf('<p><small>%s</small></p>', $hint) : '',
        );

        return [
            'id' => self::id('cms:homepage:scanner-block'),
            'type' => 'text',
            'position' => 1,
            'sectionPosition' => 'main',
            'slots' => [
                [
                    'id' => self::id('cms:homepage:scanner-slot'),
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

    /**
     * Least-privilege scanner access: an ACL role carrying ONLY the
     * fib_booking.ticket_scan privilege and a non-admin demo user bound to it.
     * The scanner app logs in with this user — it can scan tickets and
     * nothing else.
     *
     * @param array<string, mixed> $scanner
     *
     * @return array{username: string, role: string}|null
     */
    private function seedScannerAccess(array $scanner, Context $context): ?array
    {
        $roleName = (string) ($scanner['role']['name'] ?? 'Booking Scanner');
        $privileges = $scanner['role']['privileges'] ?? ['fib_booking.ticket_scan'];

        $existingRoleId = $this->aclRoleRepository->searchIds(
            (new Criteria())->addFilter(new EqualsFilter('name', $roleName)),
            $context,
        )->firstId();
        $roleId = is_string($existingRoleId) ? $existingRoleId : self::id('acl-role:scanner');

        $this->aclRoleRepository->upsert([
            [
                'id' => $roleId,
                'name' => $roleName,
                'description' => 'Demo role: may scan booking tickets — nothing else.',
                'privileges' => array_values($privileges),
            ],
        ], $context);

        $user = $scanner['user'] ?? null;
        if (!is_array($user)) {
            return null;
        }

        $username = (string) $user['username'];
        $existingUserId = $this->userRepository->searchIds(
            (new Criteria())->addFilter(new EqualsFilter('username', $username)),
            $context,
        )->firstId();
        $userId = is_string($existingUserId) ? $existingUserId : self::id('user:scanner');

        $localeId = $this->localeRepository->searchIds((new Criteria())->setLimit(1), $context)->firstId();
        if (!is_string($localeId)) {
            return null;
        }

        $this->userRepository->upsert([
            [
                'id' => $userId,
                'username' => $username,
                'password' => (string) $user['password'],
                'firstName' => (string) ($user['firstName'] ?? 'Demo'),
                'lastName' => (string) ($user['lastName'] ?? 'Scanner'),
                'email' => (string) ($user['email'] ?? 'scanner@example.invalid'),
                'localeId' => $localeId,
                'admin' => false,
                'aclRoles' => [
                    ['id' => $roleId],
                ],
            ],
        ], $context);

        return ['username' => $username, 'role' => $roleName];
    }

    /**
     * Creates a CMS layout containing the booking-calendar element (configured
     * with the package resource) and assigns it as the homepage of every
     * storefront sales channel's root category — `make up` boots straight
     * into a bookable calendar.
     *
     * @param array<string, mixed> $homepage
     */
    private function seedHomepageWithCalendar(array $homepage, string $calendarResourceId, Context $context): bool
    {
        $pageId = self::id('cms:homepage');

        $blocks = [
            [
                'id' => self::id('cms:homepage:block'),
                'type' => 'fib-booking-calendar',
                'position' => 0,
                'sectionPosition' => 'main',
                'slots' => [
                    [
                        'id' => self::id('cms:homepage:slot'),
                        'type' => 'fib-booking-calendar',
                        'slot' => 'calendar',
                        'config' => [
                            'resourceId' => ['source' => 'static', 'value' => $calendarResourceId],
                            'monthsAhead' => ['source' => 'static', 'value' => (int) ($homepage['monthsAhead'] ?? 3)],
                        ],
                    ],
                ],
            ],
        ];

        if (isset($homepage['scannerLink'])) {
            $blocks[] = $this->buildScannerLinkBlock($homepage['scannerLink']);
        }

        $this->cmsPageRepository->upsert([
            [
                'id' => $pageId,
                'type' => 'page',
                'name' => (string) ($homepage['name'] ?? 'FIB Booking Home'),
                'sections' => [
                    [
                        'id' => self::id('cms:homepage:section'),
                        'type' => 'default',
                        'position' => 0,
                        'blocks' => $blocks,
                    ],
                ],
            ],
        ], $context);

        if (!($homepage['assignToHomepage'] ?? false)) {
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
    private function loadSeeds(): array
    {
        $raw = file_get_contents(self::SEED_FILE);
        if ($raw === false) {
            throw new RuntimeException(sprintf('Seed file "%s" is missing.', self::SEED_FILE));
        }

        $seeds = json_decode($raw, true, 16, \JSON_THROW_ON_ERROR);
        if (!is_array($seeds)) {
            throw new RuntimeException('Seed file must contain a JSON object.');
        }

        return $seeds;
    }

    /**
     * @param array<string, mixed> $resource
     */
    private function upsertResource(array $resource, Context $context): string
    {
        // Reuse an existing resource with the same technical name (unique key)
        // so id-scheme changes between seeder versions never collide.
        $existingId = $this->resourceRepository->searchIds(
            (new Criteria())->addFilter(new EqualsFilter('technicalName', (string) $resource['technicalName'])),
            $context,
        )->firstId();

        $id = is_string($existingId) ? $existingId : self::id('resource:' . $resource['key']);

        $this->resourceRepository->upsert([
            [
                'id' => $id,
                'name' => (string) $resource['name'],
                'technicalName' => (string) $resource['technicalName'],
                'capacity' => (int) $resource['capacity'],
                'active' => true,
                'configuration' => ['demo' => true],
            ],
        ], $context);

        return $id;
    }

    /**
     * @param array<string, mixed> $product
     * @param list<string>         $salesChannelIds
     *
     * @return array{id: string, number: string, name: string, resourceId: string}
     */
    private function upsertBookableProduct(array $product, string $resourceId, string $taxId, array $salesChannelIds, Context $context): array
    {
        $productNumber = (string) $product['productNumber'];
        $grossPrice = (float) $product['grossPrice'];

        // Reuse existing rows keyed by their unique columns so id-scheme
        // changes between seeder versions never collide.
        $existingProductId = $this->productRepository->searchIds(
            (new Criteria())->addFilter(new EqualsFilter('productNumber', $productNumber)),
            $context,
        )->firstId();
        $productId = is_string($existingProductId) ? $existingProductId : self::id('product:' . $productNumber);

        $existingConfigId = $this->productConfigRepository->searchIds(
            (new Criteria())->addFilter(new EqualsFilter('productId', $productId)),
            $context,
        )->firstId();
        $configId = is_string($existingConfigId) ? $existingConfigId : self::id('product-config:' . $productNumber);

        $payload = [
            'id' => $productId,
            'name' => (string) $product['name'],
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
                'id' => self::id('visibility:' . $productNumber . ':' . $salesChannelId),
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
                'slotMinutes' => (int) ($product['slotMinutes'] ?? 60),
            ],
        ], $context);

        return [
            'id' => $productId,
            'number' => $productNumber,
            'name' => (string) $product['name'],
            'resourceId' => $resourceId,
        ];
    }

    /**
     * Packages scenario: multiple products (Starter/Premium/Luxury) share one
     * resource; bookability is driven by that resource's slots.
     *
     * @param array<string, mixed> $packages
     * @param list<string>         $salesChannelIds
     *
     * @return array{0: list<array{id: string, number: string, name: string, resourceId: string}>, 1: int}
     */
    private function seedPackages(array $packages, string $taxId, array $salesChannelIds, Context $context): array
    {
        $resourceId = $this->upsertResource($packages['resource'], $context);

        $packageProducts = [];
        foreach ($packages['products'] as $product) {
            $product['slotMinutes'] = $packages['slots']['durationMinutes'] ?? 120;
            $packageProducts[] = $this->upsertBookableProduct($product, $resourceId, $taxId, $salesChannelIds, $context);
        }

        $slotCount = $this->seedSlots($packages['slots'], $resourceId, $context);

        return [$packageProducts, $slotCount];
    }

    /**
     * @param array<string, mixed> $slotConfig
     */
    private function seedSlots(array $slotConfig, string $resourceId, Context $context): int
    {
        $daysAhead = max(1, (int) ($slotConfig['daysAhead'] ?? 14));
        $duration = max(5, (int) ($slotConfig['durationMinutes'] ?? 120));
        $capacity = max(1, (int) ($slotConfig['capacity'] ?? 10));
        $times = $slotConfig['times'] ?? ['18:00'];

        $payload = [];
        $today = new DateTimeImmutable('today');

        for ($offset = 1; $offset <= $daysAhead; ++$offset) {
            $day = $today->add(new DateInterval('P' . $offset . 'D'));

            foreach ($times as $time) {
                [$hour, $minute] = array_map(intval(...), explode(':', (string) $time));
                $startsAt = $day->setTime($hour, $minute);

                $payload[] = [
                    'id' => self::id('slot:' . $resourceId . ':' . $startsAt->format('Y-m-d H:i')),
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

    /**
     * @param array<string, mixed> $reservation
     *
     * @return array{0: string, 1: string|null}
     */
    private function seedReservationWithTicket(array $reservation, string $resourceId, Context $context): array
    {
        $existingReservationId = $this->reservationRepository->searchIds(
            (new Criteria())->addFilter(new EqualsFilter('bookingNumber', (string) $reservation['bookingNumber'])),
            $context,
        )->firstId();
        $reservationId = is_string($existingReservationId)
            ? $existingReservationId
            : self::id('reservation:' . $reservation['bookingNumber']);

        [$hour, $minute] = array_map(intval(...), explode(':', (string) ($reservation['startTime'] ?? '18:00')));
        $startsAt = (new DateTimeImmutable('today'))
            ->add(new DateInterval('P' . max(1, (int) ($reservation['startsAtOffsetDays'] ?? 1)) . 'D'))
            ->setTime($hour, $minute);
        $endsAt = $startsAt->add(new DateInterval(sprintf('PT%dM', max(5, (int) ($reservation['durationMinutes'] ?? 120)))));

        $this->reservationRepository->upsert([
            [
                'id' => $reservationId,
                'resourceId' => $resourceId,
                'bookingNumber' => (string) $reservation['bookingNumber'],
                'startsAt' => $startsAt->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                'endsAt' => $endsAt->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                'quantity' => (int) ($reservation['quantity'] ?? 1),
                'status' => 'confirmed',
                'payload' => ['demo' => true],
            ],
        ], $context);

        try {
            $ticket = $this->ticketService->issueTicket($reservationId, $context, ['demo' => true]);

            return [$reservationId, $ticket->getTicketNumber()];
        } catch (FibBookingException) {
            // Re-run: a valid ticket already exists for the demo reservation.
            return [$reservationId, null];
        }
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

    private static function id(string $seed): string
    {
        return md5('fib-booking-demo:' . $seed);
    }
}
