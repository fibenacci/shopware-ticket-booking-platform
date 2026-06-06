<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Reservation;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use FibBookingSystem\Core\Domain\Ticket\BookingTicketService;
use RuntimeException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class BookingReservationService
{
    public const NUMBER_RANGE_TYPE = 'fib_booking_reservation';

    public function __construct(
        private readonly Connection $connection,
        private readonly BookingTicketService $ticketService,
        private readonly NumberRangeValueGeneratorInterface $numberRangeValueGenerator,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function convertOrderHolds(string $orderId, Context $context): int
    {
        return $this->connection->transactional(function () use ($orderId, $context): int {
            $order = $this->connection->fetchAssociative(
                'SELECT `order`.`id`, `order`.`version_id`, `order`.`sales_channel_id`, `order`.`order_customer_id`, `order_customer`.`customer_id`
                 FROM `order`
                 LEFT JOIN `order_customer` ON `order_customer`.`id` = `order`.`order_customer_id`
                 WHERE `order`.`id` = :orderId',
                ['orderId' => Uuid::fromHexToBytes($orderId)],
            );

            if ($order === false) {
                return 0;
            }

            $lineItems = $this->connection->fetchAllAssociative(
                'SELECT `id`, `version_id`, `payload`
                 FROM `order_line_item`
                 WHERE `order_id` = :orderId',
                ['orderId' => Uuid::fromHexToBytes($orderId)],
            );

            $converted = 0;

            foreach ($lineItems as $lineItem) {
                $bookingPayload = $this->extractBookingPayload($lineItem['payload']);

                if ($bookingPayload === null) {
                    continue;
                }

                if ($this->convertHold($context, $orderId, $order['version_id'], $order['sales_channel_id'], $lineItem['id'], $lineItem['version_id'], $order['customer_id'], $bookingPayload)) {
                    ++$converted;
                }
            }

            return $converted;
        });
    }

    public function confirmReservationsForOrder(string $orderId, Context $context): int
    {
        $reservationIds = $this->connection->fetchFirstColumn(
            "SELECT LOWER(HEX(id))
             FROM fib_booking_reservation
             WHERE order_id = :orderId
               AND status IN ('pending_payment', 'draft')",
            ['orderId' => Uuid::fromHexToBytes($orderId)],
        );

        return $this->confirmReservations($reservationIds, $context);
    }

    public function confirmReservationsForOrderTransaction(string $orderTransactionId, Context $context): int
    {
        $orderId = $this->connection->fetchOne(
            'SELECT LOWER(HEX(order_id)) FROM order_transaction WHERE id = :transactionId',
            ['transactionId' => Uuid::fromHexToBytes($orderTransactionId)],
        );

        if (!is_string($orderId) || $orderId === '') {
            return 0;
        }

        return $this->confirmReservationsForOrder($orderId, $context);
    }

    /**
     * @param array<int, string> $reservationIds
     */
    private function confirmReservations(array $reservationIds, Context $context): int
    {
        $confirmed = 0;

        foreach ($reservationIds as $reservationId) {
            if (!is_string($reservationId) || $reservationId === '') {
                continue;
            }

            $updated = $this->connection->update('fib_booking_reservation', [
                'status' => 'confirmed',
                'updated_at' => $this->formatDateTime(new DateTimeImmutable()),
            ], [
                'id' => Uuid::fromHexToBytes($reservationId),
            ]);

            if ($updated < 1) {
                continue;
            }

            $bookingNumber = $this->connection->fetchOne(
                'SELECT booking_number FROM fib_booking_reservation WHERE id = :reservationId',
                ['reservationId' => Uuid::fromHexToBytes($reservationId)],
            );

            if (is_string($bookingNumber)) {
                $this->eventDispatcher->dispatch(
                    new BookingReservationConfirmedEvent($reservationId, $bookingNumber, $context),
                    BookingReservationConfirmedEvent::EVENT_NAME,
                );
            }

            try {
                $this->ticketService->issueTicket($reservationId, $context);
            } catch (RuntimeException) {
                // A valid ticket may already exist after a retry or duplicated state event.
            }

            ++$confirmed;
        }

        return $confirmed;
    }

    /**
     * @return array{holdId: string, holdToken: string}|null
     */
    private function extractBookingPayload(mixed $payload): ?array
    {
        if (!is_string($payload) || $payload === '') {
            return null;
        }

        $decoded = json_decode($payload, true);

        if (!is_array($decoded)) {
            return null;
        }

        $holdId = $decoded['bookingHoldId'] ?? $decoded['fibBooking']['holdId'] ?? null;
        $holdToken = $decoded['bookingHoldToken'] ?? $decoded['fibBooking']['holdToken'] ?? null;

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
        string $orderId,
        mixed $orderVersionId,
        mixed $salesChannelId,
        mixed $orderLineItemId,
        mixed $orderLineItemVersionId,
        mixed $customerId,
        array $bookingPayload,
    ): bool {
        $hold = $this->connection->fetchAssociative(
            'SELECT *
             FROM fib_booking_hold
             WHERE id = :holdId
               AND token = :holdToken
             FOR UPDATE',
            [
                'holdId' => Uuid::fromHexToBytes($bookingPayload['holdId']),
                'holdToken' => $bookingPayload['holdToken'],
            ],
        );

        if ($hold === false || $hold['status'] !== 'active') {
            return false;
        }

        if (new DateTimeImmutable((string) $hold['expires_at']) <= new DateTimeImmutable()) {
            return false;
        }

        $existingReservationId = $this->connection->fetchOne(
            'SELECT id FROM fib_booking_reservation WHERE hold_id = :holdId',
            ['holdId' => Uuid::fromHexToBytes($bookingPayload['holdId'])],
        );

        if ($existingReservationId !== false) {
            return false;
        }

        $reservationId = Uuid::randomHex();

        $this->connection->insert('fib_booking_reservation', [
            'id' => Uuid::fromHexToBytes($reservationId),
            'resource_id' => $hold['resource_id'],
            'order_id' => Uuid::fromHexToBytes($orderId),
            'order_version_id' => $orderVersionId,
            'order_line_item_id' => $orderLineItemId,
            'order_line_item_version_id' => $orderLineItemVersionId,
            'customer_id' => $customerId,
            'hold_id' => Uuid::fromHexToBytes($bookingPayload['holdId']),
            'booking_number' => $this->numberRangeValueGenerator->getValue(
                self::NUMBER_RANGE_TYPE,
                $context,
                is_string($salesChannelId) ? Uuid::fromBytesToHex($salesChannelId) : null,
            ),
            'starts_at' => $hold['starts_at'],
            'ends_at' => $hold['ends_at'],
            'quantity' => $hold['quantity'],
            'status' => 'pending_payment',
            'payload' => $hold['payload'],
            'created_at' => $this->formatDateTime(new DateTimeImmutable()),
        ]);

        $this->connection->update('fib_booking_hold', [
            'status' => 'converted',
            'updated_at' => $this->formatDateTime(new DateTimeImmutable()),
        ], [
            'id' => Uuid::fromHexToBytes($bookingPayload['holdId']),
        ]);

        return true;
    }

    private function formatDateTime(DateTimeInterface $dateTime): string
    {
        return $dateTime->format('Y-m-d H:i:s.v');
    }
}
