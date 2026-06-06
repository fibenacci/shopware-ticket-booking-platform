<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Reservation;

use Doctrine\DBAL\Connection;
use FibBookingSystem\Core\Content\BookingHold\BookingHoldCollection;
use FibBookingSystem\Core\Content\BookingReservation\BookingReservationCollection;
use FibBookingSystem\Core\Content\BookingReservation\BookingReservationEntity;
use FibBookingSystem\Core\Domain\Availability\AvailabilityService;
use FibBookingSystem\Core\Domain\Ticket\BookingTicketService;
use FibBookingSystem\Core\Domain\Time\UtcDateTime;
use FibBookingSystem\FibBookingException;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\Aggregate\OrderLineItem\OrderLineItemEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionCollection;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class BookingReservationService
{
    public const NUMBER_RANGE_TYPE = 'fib_booking_reservation';

    /**
     * The DBAL connection is used for two things only: the surrounding
     * transaction and the pessimistic `SELECT … FOR UPDATE` lock on the hold
     * row — the DAL has no pessimistic locking, and that lock is the
     * no-overbooking guarantee. Everything else goes through the DAL.
     *
     * @param EntityRepository<OrderCollection>              $orderRepository
     * @param EntityRepository<OrderTransactionCollection>   $orderTransactionRepository
     * @param EntityRepository<BookingReservationCollection> $reservationRepository
     * @param EntityRepository<BookingHoldCollection>        $holdRepository
     *
     * @SuppressWarnings("PHPMD.ExcessiveParameterList") constructor DI of the
     * reservation lifecycle hub — four repositories plus the collaborating
     * domain services; splitting it would only move the wiring, not reduce it
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $orderTransactionRepository,
        private readonly EntityRepository $reservationRepository,
        private readonly EntityRepository $holdRepository,
        private readonly BookingTicketService $ticketService,
        private readonly AvailabilityService $availabilityService,
        private readonly NumberRangeValueGeneratorInterface $numberRangeValueGenerator,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function convertOrderHolds(string $orderId, Context $context): int
    {
        return $this->connection->transactional(function () use ($orderId, $context): int {
            $criteria = new Criteria([$orderId]);
            $criteria->addAssociation('orderCustomer');
            $criteria->addAssociation('lineItems');

            /** @var OrderEntity|null $order */
            $order = $this->orderRepository->search($criteria, $context)->first();

            if ($order === null) {
                return 0;
            }

            $converted = 0;

            foreach ($order->getLineItems() ?? [] as $lineItem) {
                $bookingPayload = $this->extractBookingPayload($lineItem->getPayload());

                if ($bookingPayload === null) {
                    continue;
                }

                if ($this->convertHold($context, $order, $lineItem, $bookingPayload)) {
                    ++$converted;
                }
            }

            return $converted;
        });
    }

    public function confirmReservationsForOrder(string $orderId, Context $context): int
    {
        // Payment may legitimately arrive AFTER the pending-payment TTL
        // (prepayment takes days) — resurrect what the expiry task flipped,
        // as long as the window is still free.
        $this->resurrectExpiredReservations($orderId, $context);

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderId', $orderId));
        $criteria->addFilter(new EqualsAnyFilter('status', ['pending_payment', 'draft']));

        /** @var list<string> $reservationIds */
        $reservationIds = $this->reservationRepository->searchIds($criteria, $context)->getIds();

        return $this->confirmReservations($reservationIds, $context);
    }

    /**
     * Re-activates expired reservations of an order whose payment arrived
     * late. Same locking discipline as the hold conversion: the resource row
     * lock serializes against concurrent hold creation, then the availability
     * math decides — the expired reservation itself no longer counts, so a
     * plain re-check is exact. A window that was given away in the meantime
     * stays lost: the operator gets an error log to re-book or refund.
     */
    private function resurrectExpiredReservations(string $orderId, Context $context): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderId', $orderId));
        $criteria->addFilter(new EqualsFilter('status', 'expired'));

        $expired = $this->reservationRepository->search($criteria, $context)->getEntities();

        /** @var BookingReservationEntity $reservation */
        foreach ($expired as $reservation) {
            $resurrected = $this->connection->transactional(function () use ($reservation, $context): bool {
                // Deliberate raw SQL: pessimistic resource lock, same
                // no-overbooking guarantee as BookingHoldService::createHold.
                $this->connection->fetchOne(
                    <<<'SQL'
                        SELECT id FROM fib_booking_resource WHERE id = :resourceId FOR UPDATE
                    SQL,
                    ['resourceId' => Uuid::fromHexToBytes($reservation->getResourceId())],
                );

                $availability = $this->availabilityService->check(
                    $reservation->getResourceId(),
                    $reservation->getStartsAt(),
                    $reservation->getEndsAt(),
                    $reservation->getQuantity(),
                );

                if (!$availability->isAvailable()) {
                    return false;
                }

                $context->scope(Context::SYSTEM_SCOPE, function (Context $systemContext) use ($reservation): void {
                    $this->reservationRepository->update([
                        ['id' => $reservation->getId(), 'status' => 'pending_payment'],
                    ], $systemContext);
                });

                return true;
            });

            if (!$resurrected) {
                $this->logger->error('Late payment for expired reservation {bookingNumber} (order {orderId}): window no longer available — re-book or refund manually.', [
                    'bookingNumber' => $reservation->getBookingNumber(),
                    'reservationId' => $reservation->getId(),
                    'orderId' => $orderId,
                ]);
            }
        }
    }

    public function confirmReservationsForOrderTransaction(string $orderTransactionId, Context $context): int
    {
        /** @var OrderTransactionEntity|null $transaction */
        $transaction = $this->orderTransactionRepository
            ->search(new Criteria([$orderTransactionId]), $context)
            ->first();

        if ($transaction === null) {
            return 0;
        }

        return $this->confirmReservationsForOrder($transaction->getOrderId(), $context);
    }

    /**
     * Cancellation/refund tail: every non-final reservation of the order is
     * cancelled and its tickets are revoked. Cancelled reservations leave the
     * availability math (status filter), so the booked window is released
     * automatically — a cancelled order must never block a slot, and a
     * refunded order must never hold a scannable ticket.
     *
     * `completed` reservations are included on purpose: the past visit is
     * history, but a refunded multi-entry pass must stop granting entries.
     *
     * @return int number of reservations cancelled
     */
    public function cancelReservationsForOrder(string $orderId, Context $context, string $reason): int
    {
        // One transaction: a cancelled reservation with a still-scannable
        // ticket (or vice versa) must not exist, not even transiently.
        $count = $this->connection->transactional(function () use ($orderId, $context): int {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('orderId', $orderId));
            $criteria->addFilter(new EqualsAnyFilter('status', ['draft', 'pending_payment', 'confirmed', 'completed']));

            /** @var list<string> $reservationIds */
            $reservationIds = $this->reservationRepository->searchIds($criteria, $context)->getIds();

            if ($reservationIds === []) {
                return 0;
            }

            // Bulk payload + SYSTEM_SCOPE: the transition is an internal
            // effect of an authorized order state change; the acting admin
            // user needs order privileges, not booking-entity write ACLs.
            $payload = array_map(
                static fn (string $id): array => ['id' => $id, 'status' => 'cancelled'],
                $reservationIds,
            );

            $context->scope(Context::SYSTEM_SCOPE, function (Context $systemContext) use ($payload): void {
                $this->reservationRepository->update($payload, $systemContext);
            });

            $this->ticketService->revokeForReservations($reservationIds, $context);

            return count($reservationIds);
        });

        if ($count > 0) {
            $this->logger->info('Cancelled {reservations} reservation(s) for order {orderId} ({reason}); their booking windows are released.', [
                'reservations' => $count,
                'orderId' => $orderId,
                'reason' => $reason,
            ]);
        }

        return $count;
    }

    /**
     * @see cancelReservationsForOrder — resolved via the transaction's order
     */
    public function cancelReservationsForOrderTransaction(string $orderTransactionId, Context $context, string $reason): int
    {
        /** @var OrderTransactionEntity|null $transaction */
        $transaction = $this->orderTransactionRepository
            ->search(new Criteria([$orderTransactionId]), $context)
            ->first();

        if ($transaction === null) {
            return 0;
        }

        return $this->cancelReservationsForOrder($transaction->getOrderId(), $context, $reason);
    }

    /**
     * @param list<string> $reservationIds
     */
    private function confirmReservations(array $reservationIds, Context $context): int
    {
        if ($reservationIds === []) {
            return 0;
        }

        // Bulk payload: one write operation for all reservations — single
        // transaction, single indexer round trip instead of N round trips.
        $this->reservationRepository->update(
            array_map(
                static fn (string $id): array => ['id' => $id, 'status' => 'confirmed'],
                $reservationIds,
            ),
            $context,
        );

        $reservations = $this->reservationRepository
            ->search(new Criteria($reservationIds), $context)
            ->getEntities();

        $confirmed = 0;

        /** @var BookingReservationEntity $reservation */
        foreach ($reservations as $reservation) {
            $this->eventDispatcher->dispatch(
                new BookingReservationConfirmedEvent($reservation->getId(), $reservation->getBookingNumber(), $context),
                BookingReservationConfirmedEvent::EVENT_NAME,
            );

            try {
                $this->ticketService->issueTicket($reservation->getId(), $context);
            } catch (FibBookingException $exception) {
                // Only "already exists" is expected (retry / duplicated state
                // event) — anything else must surface in the logs, otherwise
                // paid orders silently end up without a ticket.
                if ($exception->getErrorCode() !== FibBookingException::TICKET_ALREADY_EXISTS) {
                    $this->logger->error('Ticket issuing failed for reservation {reservationId}: {message}', [
                        'reservationId' => $reservation->getId(),
                        'message' => $exception->getMessage(),
                        'exception' => $exception,
                    ]);
                }
            }

            ++$confirmed;
        }

        return $confirmed;
    }

    /**
     * @param array<string, mixed>|null $payload
     *
     * @return array{holdId: string, holdToken: string}|null
     */
    private function extractBookingPayload(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        $nested = $payload['fibBooking'] ?? null;
        $nested = is_array($nested) ? $nested : [];

        $holdId = $payload['bookingHoldId'] ?? $nested['holdId'] ?? null;
        $holdToken = $payload['bookingHoldToken'] ?? $nested['holdToken'] ?? null;

        if (!is_string($holdId) || !is_string($holdToken) || $holdId === '' || $holdToken === '') {
            return null;
        }

        return [
            'holdId' => $holdId,
            'holdToken' => $holdToken,
        ];
    }

    /**
     * @param array{holdId: string, holdToken: string} $bookingPayload
     */
    private function convertHold(
        Context $context,
        OrderEntity $order,
        OrderLineItemEntity $lineItem,
        array $bookingPayload,
    ): bool {
        // Deliberate raw SQL: pessimistic row lock so concurrent conversions
        // of the same hold (double submit, webhook retry) serialize here. The
        // DAL reads/writes below run on the same connection and therefore
        // inside the same transaction/lock scope.
        /** @var array{id: string, resource_id: string, status: string, expires_at: string, starts_at: string, ends_at: string, quantity: int|numeric-string, payload: string|null}|false $hold */
        $hold = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT LOWER(HEX(`id`)) AS `id`, LOWER(HEX(`resource_id`)) AS `resource_id`,
                       `status`, `expires_at`, `starts_at`, `ends_at`, `quantity`, `payload`
                FROM `fib_booking_hold`
                WHERE `id` = :holdId AND `token` = :holdToken
                FOR UPDATE
            SQL,
            [
                'holdId' => Uuid::fromHexToBytes($bookingPayload['holdId']),
                'holdToken' => $bookingPayload['holdToken'],
            ],
        );

        // Every false path below means: the order exists (and may get paid)
        // WITHOUT a reservation. That must never disappear silently — the
        // operator has to re-book or refund manually.
        if ($hold === false) {
            $this->logger->error('Booking hold conversion failed for order {orderNumber}: hold {holdId} not found or token mismatch.', [
                'orderNumber' => $order->getOrderNumber(),
                'orderId' => $order->getId(),
                'lineItemId' => $lineItem->getId(),
                'holdId' => $bookingPayload['holdId'],
            ]);

            return false;
        }

        $alreadyConverted = $this->hasReservationForHold($bookingPayload['holdId'], $context);

        if ($alreadyConverted) {
            // Expected idempotent replay (double submit, webhook retry) — the
            // reservation exists, nothing to repair.
            return false;
        }

        if ($hold['status'] !== 'active' || UtcDateTime::parse($hold['expires_at']) <= UtcDateTime::now()) {
            $this->logger->error('Booking hold conversion failed for order {orderNumber}: hold {holdId} is {status} (expires {expiresAt}) — order placed without a reservation.', [
                'orderNumber' => $order->getOrderNumber(),
                'orderId' => $order->getId(),
                'lineItemId' => $lineItem->getId(),
                'holdId' => $bookingPayload['holdId'],
                'status' => $hold['status'],
                'expiresAt' => $hold['expires_at'],
            ]);

            return false;
        }

        $this->reservationRepository->create([
            [
                'id' => Uuid::randomHex(),
                'resourceId' => $hold['resource_id'],
                'orderId' => $order->getId(),
                'orderVersionId' => $order->getVersionId(),
                'orderLineItemId' => $lineItem->getId(),
                'orderLineItemVersionId' => $lineItem->getVersionId(),
                'customerId' => $order->getOrderCustomer()?->getCustomerId(),
                'holdId' => $bookingPayload['holdId'],
                'bookingNumber' => $this->numberRangeValueGenerator->getValue(
                    self::NUMBER_RANGE_TYPE,
                    $context,
                    $order->getSalesChannelId(),
                ),
                // UTC objects instead of the naive DB strings — the DAL would
                // re-interpret a naive string in the PHP default timezone.
                'startsAt' => UtcDateTime::parse($hold['starts_at']),
                'endsAt' => UtcDateTime::parse($hold['ends_at']),
                'quantity' => (int) $hold['quantity'],
                'status' => 'pending_payment',
                'payload' => is_string($hold['payload']) ? json_decode($hold['payload'], true) : null,
            ],
        ], $context);

        $this->holdRepository->update([
            [
                'id' => $bookingPayload['holdId'],
                'status' => 'converted',
            ],
        ], $context);

        return true;
    }

    private function hasReservationForHold(string $holdId, Context $context): bool
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('holdId', $holdId));

        return $this->reservationRepository->searchIds($criteria, $context)->firstId() !== null;
    }
}
