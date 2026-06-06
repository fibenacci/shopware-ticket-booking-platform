<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Reservation;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use FibBookingSystem\Core\Content\BookingHold\BookingHoldCollection;
use FibBookingSystem\Core\Content\BookingReservation\BookingReservationCollection;
use FibBookingSystem\Core\Content\BookingReservation\BookingReservationEntity;
use FibBookingSystem\Core\Domain\Ticket\BookingTicketService;
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
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $orderTransactionRepository,
        private readonly EntityRepository $reservationRepository,
        private readonly EntityRepository $holdRepository,
        private readonly BookingTicketService $ticketService,
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
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderId', $orderId));
        $criteria->addFilter(new EqualsAnyFilter('status', ['pending_payment', 'draft']));

        /** @var array<string> $reservationIds */
        $reservationIds = $this->reservationRepository->searchIds($criteria, $context)->getIds();

        return $this->confirmReservations($reservationIds, $context);
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
     * @param array<string> $reservationIds
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

        if ($hold === false || $hold['status'] !== 'active') {
            return false;
        }

        if (new DateTimeImmutable($hold['expires_at']) <= new DateTimeImmutable()) {
            return false;
        }

        $existingCriteria = new Criteria();
        $existingCriteria->addFilter(new EqualsFilter('holdId', $bookingPayload['holdId']));

        if ($this->reservationRepository->searchIds($existingCriteria, $context)->firstId() !== null) {
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
                'startsAt' => $hold['starts_at'],
                'endsAt' => $hold['ends_at'],
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
}
