<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Integration\Availability;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use FibBookingSystem\Core\Domain\Availability\AvailabilityService;
use FibBookingSystem\Core\Domain\Availability\BookingCalendarService;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Slot ("Termin") behaviour: slots gate the bookable windows, slot capacity
 * wins over resource capacity, and the calendar day status turns "full" (red)
 * when every slot of a day is sold out.
 */
class SlotAvailabilityTest extends TestCase
{
    use KernelTestBehaviour;

    private const RESOURCE_ID = 'f1b0000000000000000000000000d001';

    private Connection $connection;
    private AvailabilityService $availability;
    private BookingCalendarService $calendar;

    protected function setUp(): void
    {
        $this->connection = self::container()->get(Connection::class);
        $this->availability = new AvailabilityService($this->connection);
        $this->calendar = new BookingCalendarService($this->connection);

        $this->cleanupFixtures();

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO fib_booking_resource (id, name, technical_name, capacity, active, created_at)
                VALUES (:id, 'Slot Test Hall', 'fib_test_slot_resource', 99, 1, NOW(3))
                SQL,
            ['id' => Uuid::fromHexToBytes(self::RESOURCE_ID)],
        );

        // Two slots on 2026-12-10 (capacity 2), one slot on 2026-12-11 (capacity 5).
        foreach ([
            ['2026-12-10 18:00:00.000', '2026-12-10 20:00:00.000', 2],
            ['2026-12-10 20:30:00.000', '2026-12-10 22:30:00.000', 2],
            ['2026-12-11 18:00:00.000', '2026-12-11 20:00:00.000', 5],
        ] as [$startsAt, $endsAt, $capacity]) {
            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO fib_booking_slot (id, resource_id, starts_at, ends_at, capacity, active, created_at)
                    VALUES (:id, :resourceId, :startsAt, :endsAt, :capacity, 1, NOW(3))
                    SQL,
                [
                    'id' => Uuid::randomBytes(),
                    'resourceId' => Uuid::fromHexToBytes(self::RESOURCE_ID),
                    'startsAt' => $startsAt,
                    'endsAt' => $endsAt,
                    'capacity' => $capacity,
                ],
            );
        }
    }

    protected function tearDown(): void
    {
        $this->cleanupFixtures();
    }

    public function testBookingOnASlotUsesSlotCapacity(): void
    {
        $result = $this->availability->check(
            self::RESOURCE_ID,
            new DateTimeImmutable('2026-12-10 18:00:00'),
            new DateTimeImmutable('2026-12-10 20:00:00'),
            2,
        );

        static::assertTrue($result->isAvailable());
        // Slot capacity (2) wins over resource capacity (99).
        static::assertSame(2, $result->jsonSerialize()['capacity']);
    }

    public function testBookingOffSlotIsRejectedWhenResourceHasSlots(): void
    {
        $result = $this->availability->check(
            self::RESOURCE_ID,
            new DateTimeImmutable('2026-12-10 09:00:00'),
            new DateTimeImmutable('2026-12-10 10:00:00'),
            1,
        );

        static::assertFalse($result->isAvailable());
    }

    public function testOverbookingASlotIsRejected(): void
    {
        $result = $this->availability->check(
            self::RESOURCE_ID,
            new DateTimeImmutable('2026-12-10 18:00:00'),
            new DateTimeImmutable('2026-12-10 20:00:00'),
            3,
        );

        static::assertFalse($result->isAvailable());
    }

    public function testCalendarDayTurnsFullWhenAllSlotsAreSoldOut(): void
    {
        // Consume the full capacity of BOTH slots on 2026-12-10.
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO fib_booking_reservation (id, resource_id, booking_number, starts_at, ends_at, quantity, status, created_at)
                VALUES (:id, :resourceId, 'B-SLOT-TEST', '2026-12-10 18:00:00.000', '2026-12-10 22:30:00.000', 2, 'confirmed', NOW(3))
                SQL,
            [
                'id' => Uuid::randomBytes(),
                'resourceId' => Uuid::fromHexToBytes(self::RESOURCE_ID),
            ],
        );

        $month = $this->calendar->getMonth(self::RESOURCE_ID, new DateTimeImmutable('2026-12-01'));
        $days = array_column($month['days'], null, 'date');

        static::assertSame('full', $days['2026-12-10']['status'], 'sold-out day must be marked full (red)');
        static::assertSame([0, 0], array_column($days['2026-12-10']['slots'], 'available'));

        static::assertSame('free', $days['2026-12-11']['status']);
        static::assertSame([5], array_column($days['2026-12-11']['slots'], 'available'));
    }

    public function testCalendarDayIsPartialWhenSomeSlotsRemain(): void
    {
        // Sell out only the 18:00 slot on 2026-12-10.
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO fib_booking_reservation (id, resource_id, booking_number, starts_at, ends_at, quantity, status, created_at)
                VALUES (:id, :resourceId, 'B-SLOT-TEST', '2026-12-10 18:00:00.000', '2026-12-10 20:00:00.000', 2, 'confirmed', NOW(3))
                SQL,
            [
                'id' => Uuid::randomBytes(),
                'resourceId' => Uuid::fromHexToBytes(self::RESOURCE_ID),
            ],
        );

        $month = $this->calendar->getMonth(self::RESOURCE_ID, new DateTimeImmutable('2026-12-01'));
        $days = array_column($month['days'], null, 'date');

        static::assertSame('partial', $days['2026-12-10']['status']);
    }

    private function cleanupFixtures(): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                DELETE FROM fib_booking_reservation WHERE booking_number = 'B-SLOT-TEST'
                SQL,
        );
        $this->connection->executeStatement(
            <<<'SQL'
                DELETE FROM fib_booking_slot WHERE resource_id = :resourceId
                SQL,
            ['resourceId' => Uuid::fromHexToBytes(self::RESOURCE_ID)],
        );
        $this->connection->executeStatement(
            <<<'SQL'
                DELETE FROM fib_booking_resource WHERE technical_name = 'fib_test_slot_resource'
                SQL,
        );
    }

    private static function container(): ContainerInterface
    {
        $container = static::getKernel()->getContainer();

        return $container->has('test.service_container') ? $container->get('test.service_container') : $container;
    }
}
