<?php

declare(strict_types=1);

namespace FibBookingSystem;

use Doctrine\DBAL\Connection;
use FibBookingSystem\Core\Domain\Reservation\BookingReservationConfirmedEvent;
use FibBookingSystem\Core\Domain\Ticket\BookingTicketIssuedEvent;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;

class FibBookingSystem extends Plugin
{
    protected function getActionEventClasses(): array
    {
        return [
            BookingReservationConfirmedEvent::class,
            BookingTicketIssuedEvent::class,
        ];
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        // Bookings, reservations and tickets are customer records — they are
        // kept by default ("keep user data" checkbox in the administration).
        if ($uninstallContext->keepUserData()) {
            return;
        }

        $container = $this->container;
        if ($container === null) {
            return;
        }

        $connection = $container->get(Connection::class);
        \assert($connection instanceof Connection);

        // FK-safe drop order: children before parents.
        $connection->executeStatement('DROP TABLE IF EXISTS `fib_booking_scan_log`');
        $connection->executeStatement('DROP TABLE IF EXISTS `fib_booking_ticket`');
        $connection->executeStatement('DROP TABLE IF EXISTS `fib_booking_reservation`');
        $connection->executeStatement('DROP TABLE IF EXISTS `fib_booking_hold`');
        $connection->executeStatement('DROP TABLE IF EXISTS `fib_booking_slot`');
        $connection->executeStatement('DROP TABLE IF EXISTS `fib_booking_product_config`');
        $connection->executeStatement('DROP TABLE IF EXISTS `fib_booking_resource`');
    }
}
