<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Integration\Reservation;

use Doctrine\DBAL\Connection;
use FibBookingSystem\Core\Domain\Reservation\BookingReservationExpirationService;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The inventory-leak guard: only pending_payment reservations OLDER than the
 * TTL flip to expired — fresh pendings, confirmed reservations and a TTL of 0
 * (disabled) stay untouched.
 */
class ReservationExpirationTest extends TestCase
{
    use KernelTestBehaviour;

    private const RESOURCE_ID = 'f1b0000000000000000000000000c201';

    private Connection $connection;
    private BookingReservationExpirationService $expirationService;

    protected function setUp(): void
    {
        $this->connection = self::container()->get(Connection::class);
        $this->expirationService = new BookingReservationExpirationService($this->connection);

        $this->cleanupFixtures();
        $this->seedResource();
    }

    protected function tearDown(): void
    {
        $this->cleanupFixtures();
    }

    public function testOnlyStalePendingReservationsExpire(): void
    {
        $stalePending = $this->seedReservation('B-EXP-STALE', 'pending_payment', ageHours: 100);
        $freshPending = $this->seedReservation('B-EXP-FRESH', 'pending_payment', ageHours: 1);
        $staleConfirmed = $this->seedReservation('B-EXP-CONF', 'confirmed', ageHours: 100);

        $expired = $this->expirationService->expireOverduePendingReservations(72);

        static::assertSame(1, $expired);
        static::assertSame('expired', $this->statusOf($stalePending));
        static::assertSame('pending_payment', $this->statusOf($freshPending));
        static::assertSame('confirmed', $this->statusOf($staleConfirmed));
    }

    public function testZeroTtlDisablesTheExpiry(): void
    {
        $stalePending = $this->seedReservation('B-EXP-OFF', 'pending_payment', ageHours: 1000);

        $expired = $this->expirationService->expireOverduePendingReservations(0);

        static::assertSame(0, $expired);
        static::assertSame('pending_payment', $this->statusOf($stalePending));
    }

    private function statusOf(string $reservationId): string
    {
        $status = $this->connection->fetchOne(
            'SELECT status FROM fib_booking_reservation WHERE id = :id',
            ['id' => Uuid::fromHexToBytes($reservationId)],
        );

        static::assertIsString($status);

        return $status;
    }

    private function seedResource(): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO fib_booking_resource (id, name, technical_name, capacity, active, created_at)
                VALUES (:id, 'Expiry Test Resource', 'fib_test_expiry_resource', 10, 1, NOW(3))
                SQL,
            ['id' => Uuid::fromHexToBytes(self::RESOURCE_ID)],
        );
    }

    private function seedReservation(
        string $bookingNumber,
        string $status,
        int $ageHours,
    ): string {
        $reservationId = Uuid::randomHex();

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO fib_booking_reservation (id, resource_id, booking_number, starts_at, ends_at, quantity, status, created_at)
                VALUES (
                    :id, :resourceId, :bookingNumber,
                    '2026-12-01 18:00:00.000', '2026-12-01 20:00:00.000', 1, :status,
                    UTC_TIMESTAMP(3) - INTERVAL :ageHours HOUR
                )
                SQL,
            [
                'id' => Uuid::fromHexToBytes($reservationId),
                'resourceId' => Uuid::fromHexToBytes(self::RESOURCE_ID),
                'bookingNumber' => $bookingNumber,
                'status' => $status,
                'ageHours' => $ageHours,
            ],
        );

        return $reservationId;
    }

    private static function container(): ContainerInterface
    {
        $container = static::getKernel()->getContainer();

        return $container->has('test.service_container') ? $container->get('test.service_container') : $container;
    }

    private function cleanupFixtures(): void
    {
        $this->connection->executeStatement(
            'DELETE FROM fib_booking_reservation WHERE resource_id = :resourceId',
            ['resourceId' => Uuid::fromHexToBytes(self::RESOURCE_ID)],
        );
        $this->connection->executeStatement(
            'DELETE FROM fib_booking_resource WHERE id = :id',
            ['id' => Uuid::fromHexToBytes(self::RESOURCE_ID)],
        );
    }
}
