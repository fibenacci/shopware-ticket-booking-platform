<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Integration\Availability;

use Doctrine\DBAL\Connection;
use FibBookingSystem\Core\Domain\Availability\BookingConsistencyCheckService;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The oversell invariant from the outside: a slot (or slotless window peak)
 * whose committed quantity exceeds capacity is reported; a fully booked but
 * not overbooked window stays silent.
 */
class BookingConsistencyCheckTest extends TestCase
{
    use KernelTestBehaviour;

    private const SLOT_RESOURCE_ID = 'f1b0000000000000000000000000c401';
    private const SWEEP_RESOURCE_ID = 'f1b0000000000000000000000000c402';

    private Connection $connection;
    private BookingConsistencyCheckService $checkService;

    protected function setUp(): void
    {
        $this->connection = self::container()->get(Connection::class);
        $this->checkService = new BookingConsistencyCheckService($this->connection);

        $this->cleanupFixtures();
    }

    protected function tearDown(): void
    {
        $this->cleanupFixtures();
    }

    public function testOverbookedSlotIsReported(): void
    {
        $this->seedResource(self::SLOT_RESOURCE_ID, 'fib_test_consistency_slot', capacity: 10);
        $this->seedSlot(self::SLOT_RESOURCE_ID, capacity: 2);
        // 2 confirmed + 1 pending = 3 committed against slot capacity 2.
        $this->seedReservation(self::SLOT_RESOURCE_ID, 'B-CONS-1', 'confirmed', quantity: 2);
        $this->seedReservation(self::SLOT_RESOURCE_ID, 'B-CONS-2', 'pending_payment', quantity: 1);

        $violations = $this->violationsFor(self::SLOT_RESOURCE_ID);

        static::assertCount(1, $violations);
        static::assertSame(2, $violations[0]['capacity']);
        static::assertSame(3, $violations[0]['committed']);
    }

    public function testFullyBookedButNotOverbookedStaysSilent(): void
    {
        $this->seedResource(self::SLOT_RESOURCE_ID, 'fib_test_consistency_slot', capacity: 10);
        $this->seedSlot(self::SLOT_RESOURCE_ID, capacity: 2);
        $this->seedReservation(self::SLOT_RESOURCE_ID, 'B-CONS-3', 'confirmed', quantity: 2);
        // cancelled/expired reservations release their window and never count
        $this->seedReservation(self::SLOT_RESOURCE_ID, 'B-CONS-4', 'cancelled', quantity: 5);
        $this->seedReservation(self::SLOT_RESOURCE_ID, 'B-CONS-5', 'expired', quantity: 5);

        static::assertSame([], $this->violationsFor(self::SLOT_RESOURCE_ID));
    }

    public function testOverlappingWindowsOnSlotlessResourceAreSweptCorrectly(): void
    {
        $this->seedResource(self::SWEEP_RESOURCE_ID, 'fib_test_consistency_sweep', capacity: 2);
        // 18:00–20:00 (2) and 19:00–21:00 (1) overlap 19:00–20:00 → peak 3 > 2.
        $this->seedReservation(self::SWEEP_RESOURCE_ID, 'B-CONS-6', 'confirmed', quantity: 2, startsAt: '2099-12-01 18:00:00.000', endsAt: '2099-12-01 20:00:00.000');
        $this->seedReservation(self::SWEEP_RESOURCE_ID, 'B-CONS-7', 'confirmed', quantity: 1, startsAt: '2099-12-01 19:00:00.000', endsAt: '2099-12-01 21:00:00.000');

        $violations = $this->violationsFor(self::SWEEP_RESOURCE_ID);

        static::assertCount(1, $violations);
        static::assertSame(3, $violations[0]['committed']);
    }

    public function testBackToBackWindowsDoNotCountAsOverlap(): void
    {
        $this->seedResource(self::SWEEP_RESOURCE_ID, 'fib_test_consistency_sweep', capacity: 2);
        // 18:00–20:00 (2) and 20:00–22:00 (2) touch but never overlap.
        $this->seedReservation(self::SWEEP_RESOURCE_ID, 'B-CONS-8', 'confirmed', quantity: 2, startsAt: '2099-12-01 18:00:00.000', endsAt: '2099-12-01 20:00:00.000');
        $this->seedReservation(self::SWEEP_RESOURCE_ID, 'B-CONS-9', 'confirmed', quantity: 2, startsAt: '2099-12-01 20:00:00.000', endsAt: '2099-12-01 22:00:00.000');

        static::assertSame([], $this->violationsFor(self::SWEEP_RESOURCE_ID));
    }

    /**
     * Other tests and demo data may legitimately produce violations on THEIR
     * resources — scope the assertion to the fixture resource.
     *
     * @return list<array{resourceId: string, window: string, capacity: int, committed: int}>
     */
    private function violationsFor(string $resourceId): array
    {
        return array_values(array_filter(
            $this->checkService->findOversellViolations(),
            static fn (array $violation): bool => $violation['resourceId'] === strtolower($resourceId),
        ));
    }

    private function seedResource(
        string $resourceId,
        string $technicalName,
        int $capacity,
    ): void {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO fib_booking_resource (id, name, technical_name, capacity, active, created_at)
                VALUES (:id, 'Consistency Test Resource', :technicalName, :capacity, 1, NOW(3))
                SQL,
            [
                'id' => Uuid::fromHexToBytes($resourceId),
                'technicalName' => $technicalName,
                'capacity' => $capacity,
            ],
        );
    }

    private function seedSlot(
        string $resourceId,
        int $capacity,
    ): void {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO fib_booking_slot (id, resource_id, starts_at, ends_at, capacity, active, created_at)
                VALUES (:id, :resourceId, '2099-12-01 18:00:00.000', '2099-12-01 20:00:00.000', :capacity, 1, NOW(3))
                SQL,
            [
                'id' => Uuid::fromHexToBytes(Uuid::randomHex()),
                'resourceId' => Uuid::fromHexToBytes($resourceId),
                'capacity' => $capacity,
            ],
        );
    }

    private function seedReservation(
        string $resourceId,
        string $bookingNumber,
        string $status,
        int $quantity,
        string $startsAt = '2099-12-01 18:00:00.000',
        string $endsAt = '2099-12-01 20:00:00.000',
    ): void {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO fib_booking_reservation (id, resource_id, booking_number, starts_at, ends_at, quantity, status, created_at)
                VALUES (:id, :resourceId, :bookingNumber, :startsAt, :endsAt, :quantity, :status, NOW(3))
                SQL,
            [
                'id' => Uuid::fromHexToBytes(Uuid::randomHex()),
                'resourceId' => Uuid::fromHexToBytes($resourceId),
                'bookingNumber' => $bookingNumber,
                'startsAt' => $startsAt,
                'endsAt' => $endsAt,
                'quantity' => $quantity,
                'status' => $status,
            ],
        );
    }

    private static function container(): ContainerInterface
    {
        $container = static::getKernel()->getContainer();

        return $container->has('test.service_container') ? $container->get('test.service_container') : $container;
    }

    private function cleanupFixtures(): void
    {
        foreach ([self::SLOT_RESOURCE_ID, self::SWEEP_RESOURCE_ID] as $resourceId) {
            $this->connection->executeStatement(
                'DELETE FROM fib_booking_reservation WHERE resource_id = :resourceId',
                ['resourceId' => Uuid::fromHexToBytes($resourceId)],
            );
            $this->connection->executeStatement(
                'DELETE FROM fib_booking_slot WHERE resource_id = :resourceId',
                ['resourceId' => Uuid::fromHexToBytes($resourceId)],
            );
            $this->connection->executeStatement(
                'DELETE FROM fib_booking_resource WHERE id = :id',
                ['id' => Uuid::fromHexToBytes($resourceId)],
            );
        }
    }
}
