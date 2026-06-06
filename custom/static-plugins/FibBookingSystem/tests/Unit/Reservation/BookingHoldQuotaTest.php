<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Unit\Reservation;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use FibBookingSystem\Core\Domain\Availability\AvailabilityResult;
use FibBookingSystem\Core\Domain\Availability\AvailabilityService;
use FibBookingSystem\Core\Domain\Reservation\BookingHoldService;
use FibBookingSystem\FibBookingException;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Test\Stub\DataAbstractionLayer\StaticEntityRepository;
use Shopware\Core\Test\Stub\SystemConfigService\StaticSystemConfigService;

/**
 * The per-customer hold cap: a customer at the limit gets HOLD_LIMIT_REACHED
 * instead of stacking more inventory-blocking holds; under the limit and for
 * guests (no stable identity — IP rate limit covers them) holds are created
 * normally.
 */
class BookingHoldQuotaTest extends TestCase
{
    private const RESOURCE_ID = 'f1b0000000000000000000000000c001';
    private const CUSTOMER_ID = 'f1b0000000000000000000000000d001';

    public function testCustomerAtTheLimitIsRejected(): void
    {
        /** @var StaticEntityRepository<\FibBookingSystem\Core\Content\BookingHold\BookingHoldCollection> $holdRepository */
        $holdRepository = new StaticEntityRepository([]);

        $this->expectExceptionObject(FibBookingException::holdLimitReached(5));

        $this->createHold($holdRepository, activeHolds: 5, configuredLimit: 5);
    }

    public function testCustomerBelowTheLimitCreatesTheHold(): void
    {
        /** @var StaticEntityRepository<\FibBookingSystem\Core\Content\BookingHold\BookingHoldCollection> $holdRepository */
        $holdRepository = new StaticEntityRepository([]);

        $this->createHold($holdRepository, activeHolds: 4, configuredLimit: 5);

        static::assertCount(1, $holdRepository->creates);
    }

    public function testZeroLimitDisablesTheCap(): void
    {
        /** @var StaticEntityRepository<\FibBookingSystem\Core\Content\BookingHold\BookingHoldCollection> $holdRepository */
        $holdRepository = new StaticEntityRepository([]);

        $this->createHold($holdRepository, activeHolds: 100, configuredLimit: 0);

        static::assertCount(1, $holdRepository->creates);
    }

    public function testGuestsAreNotCapped(): void
    {
        /** @var StaticEntityRepository<\FibBookingSystem\Core\Content\BookingHold\BookingHoldCollection> $holdRepository */
        $holdRepository = new StaticEntityRepository([]);

        $this->createHold($holdRepository, activeHolds: 100, configuredLimit: 5, customerId: null);

        static::assertCount(1, $holdRepository->creates);
    }

    /**
     * @param StaticEntityRepository<\FibBookingSystem\Core\Content\BookingHold\BookingHoldCollection> $holdRepository
     */
    private function createHold(
        StaticEntityRepository $holdRepository,
        int $activeHolds,
        int $configuredLimit,
        ?string $customerId = self::CUSTOMER_ID,
    ): void {
        $connection = $this->createStub(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn (callable $callback) => $callback());
        // fetchOne call order inside createHold: resource lock → quota count.
        // Guests skip the quota count entirely, so the lock result leads.
        $connection->method('fetchOne')->willReturn(
            Uuid::fromHexToBytes(self::RESOURCE_ID),
            (string) $activeHolds,
        );

        $availabilityService = $this->createStub(AvailabilityService::class);
        $availabilityService->method('check')->willReturn(new AvailabilityResult(true, 10, 0, 1));

        $service = new BookingHoldService(
            $connection,
            $holdRepository,
            $availabilityService,
            new StaticSystemConfigService([
                'FibBookingSystem.config.maxActiveHoldsPerCustomer' => $configuredLimit,
            ]),
        );

        $service->createHold(
            self::RESOURCE_ID,
            new DateTimeImmutable('2026-12-01 18:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-12-01 20:00:00', new DateTimeZone('UTC')),
            1,
            null,
            $customerId,
            Context::createDefaultContext(),
        );
    }
}
