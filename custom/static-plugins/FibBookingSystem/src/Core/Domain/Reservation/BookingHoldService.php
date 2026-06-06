<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Reservation;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use FibBookingSystem\Core\Content\BookingHold\BookingHoldCollection;
use FibBookingSystem\Core\Domain\Availability\AvailabilityService;
use FibBookingSystem\FibBookingException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;

class BookingHoldService
{
    /**
     * @param EntityRepository<BookingHoldCollection> $holdRepository
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityRepository $holdRepository,
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

            $availability = $this->availabilityService->check($resourceId, $startsAt, $endsAt, $quantity);

            if (!$availability->isAvailable()) {
                throw FibBookingException::windowUnavailable();
            }

            $holdId = Uuid::randomHex();
            $token = bin2hex(random_bytes(32));
            $expiresAt = (new DateTimeImmutable())->add(new DateInterval(sprintf('PT%dM', max(1, $ttlMinutes))));

            $this->holdRepository->create([
                [
                    'id' => $holdId,
                    'resourceId' => $resourceId,
                    'salesChannelId' => $salesChannelId,
                    'customerId' => $customerId,
                    'token' => $token,
                    'startsAt' => $this->formatDateTime($startsAt),
                    'endsAt' => $this->formatDateTime($endsAt),
                    'expiresAt' => $this->formatDateTime($expiresAt),
                    'quantity' => $quantity,
                    'status' => 'active',
                    'payload' => $payload === [] ? null : $payload,
                ],
            ], $context);

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
