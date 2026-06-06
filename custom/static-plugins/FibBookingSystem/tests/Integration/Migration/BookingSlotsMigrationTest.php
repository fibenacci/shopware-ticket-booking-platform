<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Integration\Migration;

use Doctrine\DBAL\Connection;
use FibBookingSystem\Migration\Migration1719000000CreateBookingSlots;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Symfony\Component\DependencyInjection\ContainerInterface;

class BookingSlotsMigrationTest extends TestCase
{
    use KernelTestBehaviour;

    public function testMigrationCreatesSlotTable(): void
    {
        $connection = self::container()->get(Connection::class);

        (new Migration1719000000CreateBookingSlots())->update($connection);

        static::assertSame('fib_booking_slot', $connection->fetchOne(
            <<<'SQL'
                SHOW TABLES LIKE 'fib_booking_slot'
                SQL,
        ));

        $columns = $connection->fetchFirstColumn(
            <<<'SQL'
                SHOW COLUMNS FROM fib_booking_slot
                SQL,
        );
        foreach (['id', 'resource_id', 'starts_at', 'ends_at', 'capacity', 'active'] as $expected) {
            static::assertContains($expected, $columns);
        }
    }

    private static function container(): ContainerInterface
    {
        $container = static::getKernel()->getContainer();

        return $container->has('test.service_container') ? $container->get('test.service_container') : $container;
    }
}
