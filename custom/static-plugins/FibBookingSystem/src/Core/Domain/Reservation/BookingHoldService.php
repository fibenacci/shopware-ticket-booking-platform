<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Reservation;

use DateInterval;
use Doctrine\DBAL\Connection;
use FibBookingSystem\Core\Content\BookingHold\BookingHoldCollection;
use FibBookingSystem\Core\Domain\Availability\AvailabilityService;
use FibBookingSystem\Core\Domain\Seating\SeatClaimService;
use FibBookingSystem\Core\Domain\Seating\SeatingMode;
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
        private readonly SeatClaimService $seatClaimService,
    ) {
    }

    public function createHold(
        BookingHoldRequest $request,
        Context $context,
    ): BookingHold {
        return $this->connection->transactional(function () use ($request, $context): BookingHold {
            $seatingMode = $this->lockResource($request->resourceId);
            $this->assertHoldQuota($request->customerId);

            $this->assertSeatingPreconditions($seatingMode, $request);

            $holdId = Uuid::randomHex();
            $token = bin2hex(random_bytes(32));
            $expiresAt = UtcDateTime::now()->add(new DateInterval(sprintf('PT%dM', max(1, $request->ttlMinutes))));

            // UTC DateTime OBJECTS, not pre-formatted strings: the DAL
            // serializer normalizes objects to UTC itself, while a naive
            // string would be re-interpreted in the PHP default timezone.
            $this->holdRepository->create([
                [
                    'id' => $holdId,
                    'resourceId' => $request->resourceId,
                    'salesChannelId' => $request->salesChannelId,
                    'customerId' => $request->customerId,
                    'token' => $token,
                    'startsAt' => UtcDateTime::from($request->startsAt),
                    'endsAt' => UtcDateTime::from($request->endsAt),
                    'expiresAt' => $expiresAt,
                    'quantity' => $request->quantity,
                    'status' => 'active',
                    'payload' => $request->payload === [] ? null : $request->payload,
                ],
            ], $context);

            if ($seatingMode === SeatingMode::SEATMAP) {
                $slotId = $request->payload['slotId'] ?? null;
                if (!is_string($slotId) || !Uuid::isValid($slotId)) {
                    throw FibBookingException::seatSelectionInvalid('seat selection requires a slotId');
                }

                // Same transaction: a lost seat race rolls the hold back.
                $this->seatClaimService->claimSeatsForHold($holdId, $request->resourceId, strtolower($slotId), $request->seatIds, $request->quantity);
            }

            return new BookingHold($holdId, $token, $expiresAt);
        });
    }

    /**
     * pool: the FOR UPDATE capacity check guards the window; seat picks are
     * meaningless. seatmap: the claim primitive (UNIQUE seat+slot) is the
     * authoritative guard — the pool check is skipped on purpose, see
     * docs/SEATING_PLAN.md.
     */
    private function assertSeatingPreconditions(
        string $seatingMode,
        BookingHoldRequest $request,
    ): void {
        if ($seatingMode === SeatingMode::SEATMAP) {
            if ($request->seatIds === []) {
                throw FibBookingException::seatSelectionInvalid('this resource requires picking seats');
            }

            return;
        }

        if ($request->seatIds !== []) {
            throw FibBookingException::seatSelectionInvalid('this resource has no seat map');
        }

        $availability = $this->availabilityService->check($request->resourceId, $request->startsAt, $request->endsAt, $request->quantity);

        if (!$availability->isAvailable()) {
            throw FibBookingException::windowUnavailable();
        }
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

    /**
     * @return string the resource's seating mode
     */
    private function lockResource(string $resourceId): string
    {
        $seatingMode = $this->connection->fetchOne(
            <<<'SQL'
                SELECT seating_mode FROM fib_booking_resource WHERE id = :resourceId FOR UPDATE
            SQL,
            ['resourceId' => Uuid::fromHexToBytes($resourceId)],
        );

        if (!is_string($seatingMode)) {
            throw FibBookingException::resourceNotFound();
        }

        return $seatingMode;
    }
}
