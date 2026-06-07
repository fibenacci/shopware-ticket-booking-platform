<?php

declare(strict_types=1);

namespace FibBookingDemoData\Tests\Integration;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The seeded scanner access must follow least privilege: a NON-admin user
 * bound to a role that carries ONLY what the operator app needs — scanning
 * tickets and reading the statistics dashboard.
 */
class SeededScannerAccessTest extends TestCase
{
    use KernelTestBehaviour;
    use SeederFactoryTrait;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = self::container()->get(Connection::class);

        $this->createSeeder(self::container())->seed(Context::createCLIContext(), withReservation: false, withOrders: false);
    }

    public function testScannerUserIsNotAnAdmin(): void
    {
        $user = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT admin, active FROM user WHERE username = 'scanner'
                SQL,
        );

        static::assertIsArray($user, 'the seeder must create the scanner user');
        static::assertSame(0, (int) $user['admin'], 'scanner user must NOT have the admin flag');
        static::assertSame(1, (int) $user['active']);
    }

    public function testScannerRoleCarriesOnlyTheOperatorPrivileges(): void
    {
        $privileges = $this->connection->fetchOne(
            <<<'SQL'
                SELECT privileges FROM acl_role WHERE name = 'Booking Scanner'
                SQL,
        );

        static::assertIsString($privileges, 'the seeder must create the Booking Scanner role');
        static::assertSame(
            ['fib_booking.ticket_scan', 'fib_booking.statistics'],
            json_decode($privileges, true),
        );
    }

    public function testDemoEnablesCheckOutScanning(): void
    {
        $value = $this->connection->fetchOne(
            <<<'SQL'
                SELECT configuration_value FROM system_config
                WHERE configuration_key = 'FibBookingSystem.config.scanCheckOutEnabled'
                AND sales_channel_id IS NULL
                SQL,
        );

        static::assertIsString($value, 'the demo must persist the check-out flag');
        $decoded = json_decode($value, true);
        static::assertIsArray($decoded);
        static::assertSame(true, $decoded['_value'] ?? null);
    }

    public function testScannerUserIsBoundToTheScannerRole(): void
    {
        $bound = $this->connection->fetchOne(
            <<<'SQL'
                SELECT 1
                FROM acl_user_role mapping
                INNER JOIN user ON user.id = mapping.user_id
                INNER JOIN acl_role ON acl_role.id = mapping.acl_role_id
                WHERE user.username = 'scanner' AND acl_role.name = 'Booking Scanner'
                SQL,
        );

        static::assertSame('1', (string) $bound);
    }

    public function testSeedingTwiceDoesNotDuplicateRoleOrUser(): void
    {
        $this->createSeeder(self::container())->seed(Context::createCLIContext(), withReservation: false, withOrders: false);

        static::assertSame('1', (string) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) FROM user WHERE username = 'scanner'
                SQL,
        ));
        static::assertSame('1', (string) $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) FROM acl_role WHERE name = 'Booking Scanner'
                SQL,
        ));
    }

    private static function container(): ContainerInterface
    {
        $container = static::getKernel()->getContainer();

        return $container->has('test.service_container') ? $container->get('test.service_container') : $container;
    }
}
