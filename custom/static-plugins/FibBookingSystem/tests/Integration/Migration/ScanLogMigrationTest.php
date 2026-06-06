<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Integration\Migration;

use Doctrine\DBAL\Connection;
use FibBookingSystem\Migration\Migration1718000000CreateScanLog;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Symfony\Component\DependencyInjection\ContainerInterface;

class ScanLogMigrationTest extends TestCase
{
    use KernelTestBehaviour;

    public function testMigrationCreatesScanLogAndCipherColumn(): void
    {
        $connection = self::container()->get(Connection::class);

        (new Migration1718000000CreateScanLog())->update($connection);

        static::assertSame('fib_booking_scan_log', $connection->fetchOne(
            "SHOW TABLES LIKE 'fib_booking_scan_log'",
        ));

        static::assertSame('scan_token_cipher', $connection->fetchOne(
            "SHOW COLUMNS FROM fib_booking_ticket LIKE 'scan_token_cipher'",
        ));
    }

    private static function container(): ContainerInterface
    {
        $container = static::getKernel()->getContainer();

        return $container->has('test.service_container') ? $container->get('test.service_container') : $container;
    }
}
