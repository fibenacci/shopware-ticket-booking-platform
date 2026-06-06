<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Reservation;

use DateInterval;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use FibBookingSystem\Core\Content\BookingHold\BookingHoldCollection;
use FibBookingSystem\Core\Domain\Availability\AvailabilityService;
use FibBookingSystem\Core\Domain\Time\UtcDateTime;
use FibBookingSystem\FibBookingException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class BookingHoldService
{
    private const DEFAULT_MAX_ACTIVE_HOLDS_PER_CUSTOMER = 5;

    /**
     * @param EntityRepository<BookingHoldCollection> $holdRepository
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityRepository $holdRepository,
        private readonly AvailabilityService $availabilityService,
        private readonly SystemConfigService $systemConfig,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createHold(
        string $resourceId,
        DateTimeInterface $startsAt,
        DateTimeInterface $endsAt,
        int $quantity,
        ?string $salesChannelId,
        ?string $customerId,
        Context $context,
        array $payload = [],
        int $ttlMinutes = 15,
    ): BookingHold {
        return $this->connection->transactional(function () use (
            $resourceId,
            $startsAt,
            $endsAt,
            $quantity,
            $salesChannelId,
            $customerId,
            $context,
            $payload,
            $ttlMinutes,
        ): BookingHold {
            $this->lockResource($resourceId);
            $this->assertHoldQuota($customerId);

            $availability = $this->availabilityService->check($resourceId, $startsAt, $endsAt, $quantity);

            if (!$availability->isAvailable()) {
                throw FibBookingException::windowUnavailable();
            }

            $holdId = Uuid::randomHex();
            $token = bin2hex(random_bytes(32));
            $expiresAt = UtcDateTime::now()->add(new DateInterval(sprintf('PT%dM', max(1, $ttlMinutes))));

            // UTC DateTime OBJECTS, not pre-formatted strings: the DAL
            // serializer normalizes objects to UTC itself, while a naive
            // string would be re-interpreted in the PHP default timezone.
            $this->holdRepository->create([
                [
                    'id' => $holdId,
                    'resourceId' => $resourceId,
                    'salesChannelId' => $salesChannelId,
                    'customerId' => $customerId,
                    'token' => $token,
                    'startsAt' => UtcDateTime::from($startsAt),
                    'endsAt' => UtcDateTime::from($endsAt),
                    'expiresAt' => $expiresAt,
                    'quantity' => $quantity,
                    'status' => 'active',
                    'payload' => $payload === [] ? null : $payload,
                ],
            ], $context);

            return new BookingHold($holdId, $token, $expiresAt);
        });
    }

    /**
     * Caps the number of LIVING holds per customer (`maxActiveHoldsPerCustomer`,
     * default 5, 0 = off) — without it, one customer with a few browser tabs
     * blocks the whole slot for the hold TTL without ever buying. Guests have
     * no stable identity here; they are throttled by the IP rate limit on the
     * hold route instead.
     */
    private function assertHoldQuota(?string $customerId): void
    {
        if ($customerId === null) {
            return;
        }

        // Unset config falls back to the default; an explicit 0 (= disabled)
        // is respected — that distinction is why getInt() is not used here.
        $raw = $this->systemConfig->get('FibBookingSystem.config.maxActiveHoldsPerCustomer');
        $limit = is_numeric($raw) ? max(0, (int) $raw) : self::DEFAULT_MAX_ACTIVE_HOLDS_PER_CUSTOMER;

        if ($limit === 0) {
            return;
        }

        $activeHolds = $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) FROM fib_booking_hold
                WHERE customer_id = :customerId
                AND status = 'active'
                AND expires_at > UTC_TIMESTAMP(3)
            SQL,
            ['customerId' => Uuid::fromHexToBytes($customerId)],
        );

        if (is_numeric($activeHolds) && (int) $activeHolds >= $limit) {
            throw FibBookingException::holdLimitReached($limit);
        }
    }

    private function lockResource(string $resourceId): void
    {
        $resource = $this->connection->fetchOne(
            <<<'SQL'
                SELECT id FROM fib_booking_resource WHERE id = :resourceId FOR UPDATE
            SQL,
            ['resourceId' => Uuid::fromHexToBytes($resourceId)],
        );

        if ($resource === false) {
            throw FibBookingException::resourceNotFound();
        }
    }
}
