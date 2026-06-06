<?php

declare(strict_types=1);

namespace FibBookingDemoData\Tests\Integration;

use FibBookingDemoData\Service\BookingDemoDataSeeder;
use FibBookingSystem\Core\Domain\Ticket\BookingTicketService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Builds the seeder from the PUBLIC `*.repository` services — the seeder
 * service itself is private and may be inlined out of the compiled container.
 * The ticket service is stubbed: these tests seed with withReservation=false,
 * so no ticket is ever issued.
 */
trait SeederFactoryTrait
{
    private function createSeeder(ContainerInterface $container): BookingDemoDataSeeder
    {
        return new BookingDemoDataSeeder(
            $container->get('product.repository'),
            $container->get('sales_channel.repository'),
            $container->get('tax.repository'),
            $container->get('fib_booking_resource.repository'),
            $container->get('fib_booking_product_config.repository'),
            $container->get('fib_booking_reservation.repository'),
            $container->get('fib_booking_slot.repository'),
            $container->get('cms_page.repository'),
            $container->get('category.repository'),
            $container->get('acl_role.repository'),
            $container->get('user.repository'),
            $container->get('locale.repository'),
            $this->createStub(BookingTicketService::class),
        );
    }
}
