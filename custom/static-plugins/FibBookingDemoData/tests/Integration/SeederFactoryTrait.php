<?php

declare(strict_types=1);

namespace FibBookingDemoData\Tests\Integration;

use FibBookingDemoData\Service\BookingDemoDataSeeder;
use FibBookingDemoData\Service\CatalogSeeder;
use FibBookingDemoData\Service\DemoCmsSeeder;
use FibBookingDemoData\Service\ReservationSeeder;
use FibBookingDemoData\Service\ScannerAccessSeeder;
use FibBookingSystem\Core\Domain\Ticket\BookingTicketService;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Builds the seeder graph from the PUBLIC `*.repository` services — the
 * seeder services themselves are private and may be inlined out of the
 * compiled container. The ticket service is stubbed: these tests seed with
 * withReservation=false, so no ticket is ever issued.
 */
trait SeederFactoryTrait
{
    private function createSeeder(ContainerInterface $container): BookingDemoDataSeeder
    {
        return new BookingDemoDataSeeder(
            new CatalogSeeder(
                $container->get('product.repository'),
                $container->get('sales_channel.repository'),
                $container->get('tax.repository'),
                $container->get('fib_booking_resource.repository'),
                $container->get('fib_booking_product_config.repository'),
                $container->get('fib_booking_slot.repository'),
                $container->get('fib_booking_seat.repository'),
            ),
            new ReservationSeeder(
                $container->get('fib_booking_reservation.repository'),
                $this->createStub(BookingTicketService::class),
            ),
            new DemoCmsSeeder(
                $container->get('cms_page.repository'),
                $container->get('category.repository'),
                $container->get('sales_channel.repository'),
            ),
            new ScannerAccessSeeder(
                $container->get('acl_role.repository'),
                $container->get('user.repository'),
                $container->get('locale.repository'),
                $container->get(SystemConfigService::class),
            ),
        );
    }
}
