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
 * - {@see ReservationSeeder}:   confirmed reservation + issued QR ticket
 * - {@see DemoCmsSeeder}:       homepage layout with the calendar element
 * - {@see ScannerAccessSeeder}: least-privilege scanner role + demo user
 *
 * All ids are derived from stable seed strings ({@see SeedIds}), so
 * re-running upserts instead of duplicating — safe for dev and CI.
 *
 * @phpstan-import-type ProductSummary from CatalogSeeder
 *
 * @phpstan-type SeedResult array{resources: list<array{id: string, name: string}>, products: list<ProductSummary>, packages: list<ProductSummary>, slots: int, reservationId: string|null, ticketNumber: string|null, homepageAssigned: bool, scannerUser: array{username: string, role: string}|null}
 */
class BookingDemoDataSeeder
{
    private const SEED_FILE = __DIR__ . '/../Resources/seeds/booking-demo.json';

    public function __construct(
        private readonly CatalogSeeder $catalogSeeder,
        private readonly ReservationSeeder $reservationSeeder,
        private readonly DemoCmsSeeder $cmsSeeder,
        private readonly ScannerAccessSeeder $scannerAccessSeeder,
    ) {
    }

    /**
     * @return SeedResult
     */
    public function seed(
        Context $context,
        bool $withReservation = true,
    ): array {
        $seeds = $this->loadSeeds();

        $catalog = $this->catalogSeeder->seedCatalog($seeds, $context);
        $resourceIds = $catalog['resourceIds'];

        $reservationId = null;
        $ticketNumber = null;
        $reservation = $seeds->sectionOrNull('reservation');
        if ($withReservation && $reservation !== null) {
            $reservationResourceId = $resourceIds[$reservation->string('resource')]
                ?? throw new RuntimeException(sprintf('Reservation references unknown resource key "%s".', $reservation->string('resource')));
            [$reservationId, $ticketNumber] = $this->reservationSeeder->seedReservationWithTicket($reservation, $reservationResourceId, $context);
        }

        $homepageAssigned = false;
        $homepage = $seeds->sectionOrNull('cms')?->sectionOrNull('homepage');
        if ($homepage !== null) {
            $calendarResourceId = $resourceIds[$homepage->string('calendarResource')] ?? $catalog['packages'][0]['resourceId'] ?? null;
            $cinemaResourceId = $resourceIds[$homepage->sectionOrNull('cinemaCalendar')?->string('resource') ?? ''] ?? null;

            if ($calendarResourceId !== null) {
                $homepageAssigned = $this->cmsSeeder->seedHomepageWithCalendar($homepage, $calendarResourceId, $context, $cinemaResourceId);
            }
        }

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
        ];
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
