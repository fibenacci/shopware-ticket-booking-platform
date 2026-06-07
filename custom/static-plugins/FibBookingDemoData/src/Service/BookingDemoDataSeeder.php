<?php

declare(strict_types=1);

namespace FibBookingDemoData\Service;

use RuntimeException;
use Shopware\Core\Framework\Context;

/**
 * Orchestrates the booking demo data. The seed DEFINITIONS live in
 * Resources/seeds/booking-demo.json; the actual seeding is split into
 * focused services:
 *
 * - {@see CatalogSeeder}:       resources, products, packages, calendar slots
 * - {@see ShopStructureSeeder}: navigation categories, footer/service pages
 * - {@see CustomerSeeder}:      storefront demo customers (buyer/seller)
 * - {@see OrderSeeder}:         paid orders + reservations + QR tickets +
 *                               one active resale listing
 * - {@see ReservationSeeder}:   confirmed reservation + issued QR ticket
 * - {@see DemoCmsSeeder}:       homepage layout with the calendar element
 * - {@see ScannerAccessSeeder}: least-privilege scanner role + demo user
 *
 * All ids are derived from stable seed strings ({@see SeedIds}), so
 * re-running upserts instead of duplicating — safe for dev and CI.
 *
 * @phpstan-import-type ProductSummary from CatalogSeeder
 * @phpstan-import-type OrderSummary from OrderSeeder
 *
 * @phpstan-type SeedResult array{resources: list<array{id: string, name: string}>, products: list<ProductSummary>, packages: list<ProductSummary>, slots: int, reservationId: string|null, ticketNumber: string|null, homepageAssigned: bool, scannerUser: array{username: string, role: string}|null, structure: array{navigationCategories: int, pages: int}, customers: list<array{email: string, customerNumber: string}>, orders: list<OrderSummary>}
 */
class BookingDemoDataSeeder
{
    private const SEED_FILE = __DIR__ . '/../Resources/seeds/booking-demo.json';

    public function __construct(
        private readonly CatalogSeeder $catalogSeeder,
        private readonly ReservationSeeder $reservationSeeder,
        private readonly DemoCmsSeeder $cmsSeeder,
        private readonly ScannerAccessSeeder $scannerAccessSeeder,
        private readonly ShopStructureSeeder $shopStructureSeeder,
        private readonly CustomerSeeder $customerSeeder,
        private readonly OrderSeeder $orderSeeder,
    ) {
    }

    /**
     * @param bool $withOrders demo customers + orders + resale listing —
     *                         disable for pure structure/catalog runs (e.g.
     *                         tests that stub the ticket service)
     *
     * @return SeedResult
     */
    public function seed(
        Context $context,
        bool $withReservation = true,
        bool $withOrders = true,
    ): array {
        $seeds = $this->loadSeeds();

        $catalog = $this->catalogSeeder->seedCatalog($seeds, $context);
        $resourceIds = $catalog['resourceIds'];

        $structure = ['navigationCategories' => 0, 'pages' => 0];
        $shop = $seeds->sectionOrNull('shop');
        if ($shop !== null) {
            $structure = $this->shopStructureSeeder->seedShopStructure($shop, $context);
        }

        [$customers, $orders] = $withOrders
            ? $this->seedCommerce($seeds, $resourceIds, $context)
            : [[], []];

        $reservationId = null;
        $ticketNumber = null;
        $reservation = $seeds->sectionOrNull('reservation');
        if ($withReservation && $reservation !== null) {
            $reservationResourceId = $resourceIds[$reservation->string('resource')]
                ?? throw new RuntimeException(sprintf('Reservation references unknown resource key "%s".', $reservation->string('resource')));
            [$reservationId, $ticketNumber] = $this->reservationSeeder->seedReservationWithTicket($reservation, $reservationResourceId, $context);
        }

        $homepageAssigned = $this->seedHomepage($seeds, $resourceIds, $catalog, $context);

        $scannerUser = null;
        $scanner = $seeds->sectionOrNull('scanner');
        if ($scanner !== null) {
            $scannerUser = $this->scannerAccessSeeder->seedScannerAccess($scanner, $context);
        }

        return [
            'resources' => $catalog['resources'],
            'products' => $catalog['products'],
            'packages' => $catalog['packages'],
            'slots' => $catalog['slots'],
            'reservationId' => $reservationId,
            'ticketNumber' => $ticketNumber,
            'homepageAssigned' => $homepageAssigned,
            'scannerUser' => $scannerUser,
            'structure' => $structure,
            'customers' => array_values(array_map(
                static fn (array $customer): array => ['email' => $customer['email'], 'customerNumber' => $customer['customerNumber']],
                $customers,
            )),
            'orders' => $orders,
        ];
    }

    /**
     * @param array<string, string> $resourceIds
     *
     * @return array{0: array<string, array{id: string, email: string, customerNumber: string}>, 1: list<OrderSummary>}
     */
    private function seedCommerce(
        SeedSection $seeds,
        array $resourceIds,
        Context $context,
    ): array {
        if (!$seeds->has('customers')) {
            return [[], []];
        }

        $customers = $this->customerSeeder->seedCustomers($seeds->sections('customers'), $context);
        $orders = $seeds->has('orders')
            ? $this->orderSeeder->seedOrders($seeds->sections('orders'), $customers, $resourceIds, $context)
            : [];

        return [$customers, $orders];
    }

    /**
     * @param array<string, string>                 $resourceIds
     * @param array{packages: list<ProductSummary>} $catalog
     */
    private function seedHomepage(
        SeedSection $seeds,
        array $resourceIds,
        array $catalog,
        Context $context,
    ): bool {
        $homepage = $seeds->sectionOrNull('cms')?->sectionOrNull('homepage');

        if ($homepage === null) {
            return false;
        }

        $calendarResourceId = $resourceIds[$homepage->string('calendarResource')] ?? $catalog['packages'][0]['resourceId'] ?? null;
        $cinemaResourceId = $resourceIds[$homepage->sectionOrNull('cinemaCalendar')?->string('resource') ?? ''] ?? null;

        if ($calendarResourceId === null) {
            return false;
        }

        return $this->cmsSeeder->seedHomepageWithCalendar($homepage, $calendarResourceId, $context, $cinemaResourceId);
    }

    private function loadSeeds(): SeedSection
    {
        $raw = file_get_contents(self::SEED_FILE);
        if ($raw === false) {
            throw new RuntimeException(sprintf('Seed file "%s" is missing.', self::SEED_FILE));
        }

        return SeedSection::fromJson($raw, 'booking-demo');
    }
}
