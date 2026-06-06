<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Reservation;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use FibBookingSystem\Core\Domain\Availability\AvailabilityService;
use FibBookingSystem\FibBookingException;
use Shopware\Core\Framework\Uuid\Uuid;

class BookingHoldService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly AvailabilityService $availabilityService,
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
            $payload,
            $ttlMinutes,
        ): BookingHold {
            $this->lockResource($resourceId);

            $availability = $this->availabilityService->check($resourceId, $startsAt, $endsAt, $quantity);

            if (!$availability->isAvailable()) {
                throw FibBookingException::windowUnavailable();
            }

            $holdId = Uuid::randomHex();
            $token = bin2hex(random_bytes(32));
            $expiresAt = (new DateTimeImmutable())->add(new DateInterval(sprintf('PT%dM', max(1, $ttlMinutes))));

            $this->connection->insert('fib_booking_hold', [
                'id' => Uuid::fromHexToBytes($holdId),
                'resource_id' => Uuid::fromHexToBytes($resourceId),
                'sales_channel_id' => $salesChannelId ? Uuid::fromHexToBytes($salesChannelId) : null,
                'customer_id' => $customerId ? Uuid::fromHexToBytes($customerId) : null,
                'token' => $token,
                'starts_at' => $this->formatDateTime($startsAt),
                'ends_at' => $this->formatDateTime($endsAt),
                'expires_at' => $this->formatDateTime($expiresAt),
                'quantity' => $quantity,
                'status' => 'active',
                'payload' => $payload === [] ? null : json_encode($payload, JSON_THROW_ON_ERROR),
                'created_at' => $this->formatDateTime(new DateTimeImmutable()),
            ]);

            return new BookingHold($holdId, $token, $expiresAt);
        });
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

    private function formatDateTime(DateTimeInterface $dateTime): string
    {
        return $dateTime->format('Y-m-d H:i:s.v');
    }
}
