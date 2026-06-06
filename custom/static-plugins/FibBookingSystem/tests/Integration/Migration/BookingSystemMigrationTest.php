<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Integration\Migration;

use Doctrine\DBAL\Connection;
use FibBookingSystem\Migration\Migration1717000000CreateBookingTables;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Symfony\Component\DependencyInjection\ContainerInterface;

class BookingSystemMigrationTest extends TestCase
{
    use KernelTestBehaviour;

    public function testMigrationCreatesBookingTablesNumberRangesAndProductConfigEntity(): void
    {
        $connection = self::container()->get(Connection::class);

        (new Migration1717000000CreateBookingTables())->update($connection);

        static::assertTableExists($connection, 'fib_booking_resource');
        static::assertTableExists($connection, 'fib_booking_hold');
        static::assertTableExists($connection, 'fib_booking_reservation');
        static::assertTableExists($connection, 'fib_booking_ticket');
        static::assertTableExists($connection, 'fib_booking_product_config');

        static::assertSame(1, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM number_range_type WHERE technical_name = :technicalName',
            ['technicalName' => 'fib_booking_reservation'],
        ));
        static::assertSame(1, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM number_range_type WHERE technical_name = :technicalName',
            ['technicalName' => 'fib_booking_ticket'],
        ));
    }

    private static function assertTableExists(Connection $connection, string $tableName): void
    {
        static::assertSame($tableName, $connection->fetchOne('SHOW TABLES LIKE :tableName', [
            'tableName' => $tableName,
        ]));
    }

    private static function container(): ContainerInterface
    {
        $container = static::getKernel()->getContainer();

        return $container->has('test.service_container') ? $container->get('test.service_container') : $container;
    }
}
