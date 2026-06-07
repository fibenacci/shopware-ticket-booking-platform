<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Resale;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use FibBookingSystem\Core\Domain\Ticket\BookingTicket;
use FibBookingSystem\Core\Domain\Time\UtcDateTime;
use FibBookingSystem\FibBookingException;
use Psr\Log\LoggerInterface;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Settles fixed-price listings when the buyer's order is paid, and unwinds
 * them when it is refunded/cancelled (docs/RESELL_PLAN.md).
 *
 * Settle = in ONE transaction per listing: lock the listing row, flip it to
 * `sold` (full snapshot: price, buyer, order, replacement ticket — NO fee,
 * see the legal framing: the platform earns nothing), release the unique
 * `active_ticket_id` guard, and run the transfer primitive — the seller's
 * ticket dies, the buyer gets a fresh identity stamped with their
 * `owner_customer_id`.
 *
 * Idempotent by the `sold_order_id` anchor: replayed state-machine events
 * (retries, duplicated transitions) see "already sold to THIS order" and
 * skip. A listing that was cancelled between checkout and payment is logged
 * loudly — that conflict is an operator refund case, never a silent loss.
 *
 * Deliberate raw DBAL (documented exception, see docs/ARCHITECTURE_PLAN.md):
 * FOR UPDATE serializes concurrent settlements, and the guard columns are
 * not part of the DAL definition.
 *
 * @phpstan-type ResaleOrderItem array{listing_id: string, unit_price: float}
 */
class ResaleSettlementService
{
    private const REVOCABLE_STATUSES = ['issued', 'sent', 'scanned'];

    public function __construct(
        private readonly Connection $connection,
        private readonly TicketTransferService $transferService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Atomic claim at order placement (double-sell guard): every resale
     * listing the order references flips `active → pending` in one UPDATE —
     * the database decides concurrent claims. From this moment the listing
     * drops out of every other cart (the processor only accepts `active`).
     * A claim that finds the listing already taken is logged; that buyer's
     * settlement will conflict loudly instead of double-charging silently.
     *
     * No TTL on purpose: slow payment methods (invoice!) legitimately take
     * days. The claim is released when the order is cancelled or refunded.
     *
     * @return int number of listings claimed
     */
    public function claimForOrder(string $orderId): int
    {
        $claimed = 0;

        foreach ($this->fetchResaleOrderItems($orderId) as $item) {
            $affected = $this->connection->executeStatement(
                <<<'SQL'
                    UPDATE fib_booking_listing
                    SET status = :pending, pending_order_id = :orderId, updated_at = UTC_TIMESTAMP(3)
                    WHERE id = :listingId AND status = :active
                SQL,
                [
                    'pending' => ListingStatus::PENDING,
                    'orderId' => Uuid::fromHexToBytes($orderId),
                    'listingId' => Uuid::fromHexToBytes($item['listing_id']),
                    'active' => ListingStatus::ACTIVE,
                ],
            );

            if ($affected === 0) {
                $this->logger->warning('Resale claim lost: listing {listingId} was no longer active when order {orderId} was placed.', [
                    'listingId' => $item['listing_id'],
                    'orderId' => $orderId,
                ]);

                continue;
            }

            ++$claimed;
        }

        return $claimed;
    }

    public function settleForOrderTransaction(
        string $orderTransactionId,
        Context $context,
    ): int {
        $orderId = $this->fetchOrderIdForTransaction($orderTransactionId);

        return $orderId !== null ? $this->settleForOrder($orderId, $context) : 0;
    }

    /**
     * @return int number of listings settled
     */
    public function settleForOrder(
        string $orderId,
        Context $context,
    ): int {
        $items = $this->fetchResaleOrderItems($orderId);

        if ($items === []) {
            return 0;
        }

        $buyerCustomerId = $this->fetchBuyerCustomerId($orderId);

        if ($buyerCustomerId === null) {
            // The cart processor blocks guests — reaching this means the
            // order was built outside the storefront flow. Loud, no settle.
            $this->logger->error('Resale order {orderId} has no customer — listings stay unsettled, refund manually.', [
                'orderId' => $orderId,
            ]);

            return 0;
        }

        $settled = 0;

        foreach ($items as $item) {
            if ($this->settleListing($item, $orderId, $buyerCustomerId, $context)) {
                ++$settled;
            }
        }

        return $settled;
    }

    public function revokeForOrderTransaction(
        string $orderTransactionId,
        string $reason,
    ): int {
        $orderId = $this->fetchOrderIdForTransaction($orderTransactionId);

        return $orderId !== null ? $this->revokeForOrder($orderId, $reason) : 0;
    }

    /**
     * Refund/cancellation tail: the buyer's replacement ticket dies. The
     * seller's original stays revoked (its identity is burned — resurrecting
     * scan tokens is not a thing); compensating the seller is an operator
     * decision, so the conflict is logged loudly instead of guessed at.
     *
     * @return int number of tickets revoked
     */
    public function revokeForOrder(
        string $orderId,
        string $reason,
    ): int {
        // Cancelled before payment: the claim is released and the listing is
        // sellable again — the seller lost nothing.
        $released = $this->connection->executeStatement(
            <<<'SQL'
                UPDATE fib_booking_listing
                SET status = :active, pending_order_id = NULL, updated_at = UTC_TIMESTAMP(3)
                WHERE pending_order_id = :orderId AND status = :pending
            SQL,
            [
                'active' => ListingStatus::ACTIVE,
                'orderId' => Uuid::fromHexToBytes($orderId),
                'pending' => ListingStatus::PENDING,
            ],
        );

        if ($released > 0) {
            $this->logger->info('Released {listings} pending resale claim(s) of cancelled order {orderId} ({reason}).', [
                'listings' => $released,
                'orderId' => $orderId,
                'reason' => $reason,
            ]);
        }

        $revoked = $this->connection->executeStatement(
            <<<'SQL'
                UPDATE fib_booking_ticket ticket
                INNER JOIN fib_booking_listing listing ON listing.sold_ticket_id = ticket.id
                SET ticket.status = 'revoked', ticket.updated_at = UTC_TIMESTAMP(3)
                WHERE listing.sold_order_id = :orderId
                AND listing.status = :sold
                AND ticket.status IN (:revocable)
            SQL,
            [
                'orderId' => Uuid::fromHexToBytes($orderId),
                'sold' => ListingStatus::SOLD,
                'revocable' => self::REVOCABLE_STATUSES,
            ],
            ['revocable' => ArrayParameterType::STRING],
        );

        if ($revoked > 0) {
            $this->logger->warning('Revoked {tickets} resale ticket(s) for refunded/cancelled order {orderId} ({reason}). Seller compensation is an operator decision.', [
                'tickets' => $revoked,
                'orderId' => $orderId,
                'reason' => $reason,
            ]);
        }

        return (int) $revoked;
    }

    /**
     * @param ResaleOrderItem $item
     */
    private function settleListing(
        array $item,
        string $orderId,
        string $buyerCustomerId,
        Context $context,
    ): bool {
        return $this->connection->transactional(function () use ($item, $orderId, $buyerCustomerId, $context): bool {
            /** @var array{ticket_id: string, status: string, sold_order_id: string|null, pending_order_id: string|null}|false $listing */
            $listing = $this->connection->fetchAssociative(
                <<<'SQL'
                    SELECT LOWER(HEX(ticket_id)) AS ticket_id, status,
                    LOWER(HEX(sold_order_id)) AS sold_order_id,
                    LOWER(HEX(pending_order_id)) AS pending_order_id
                    FROM fib_booking_listing WHERE id = :listingId
                    FOR UPDATE
                SQL,
                ['listingId' => Uuid::fromHexToBytes($item['listing_id'])],
            );

            if ($listing === false) {
                $this->logger->error('Resale settlement: listing {listingId} of paid order {orderId} does not exist.', [
                    'listingId' => $item['listing_id'],
                    'orderId' => $orderId,
                ]);

                return false;
            }

            // Replayed payment event — already settled by THIS order.
            if ($listing['status'] === ListingStatus::SOLD && $listing['sold_order_id'] === strtolower($orderId)) {
                return false;
            }

            // Settleable: the order's OWN pending claim, or (orders created
            // outside the storefront claim path) a still-active listing.
            $ownClaim = $listing['status'] === ListingStatus::PENDING
                && $listing['pending_order_id'] === strtolower($orderId);

            if (!$ownClaim && $listing['status'] !== ListingStatus::ACTIVE) {
                $this->logger->error('Resale settlement conflict: listing {listingId} is "{status}" but order {orderId} paid for it — refund the buyer manually.', [
                    'listingId' => $item['listing_id'],
                    'status' => $listing['status'],
                    'orderId' => $orderId,
                ]);

                return false;
            }

            $newTicket = $this->transferOrCloseConflicted($listing['ticket_id'], $item['listing_id'], $orderId, $buyerCustomerId, $context);

            if ($newTicket === null) {
                return false;
            }

            $this->connection->executeStatement(
                <<<'SQL'
                    UPDATE fib_booking_listing
                    SET status = :sold, active_ticket_id = NULL, pending_order_id = NULL,
                    sold_to_customer_id = :buyerId, sold_price = :soldPrice, sold_at = :soldAt,
                    sold_order_id = :orderId, sold_order_version_id = :orderVersionId,
                    sold_ticket_id = :soldTicketId, updated_at = UTC_TIMESTAMP(3)
                    WHERE id = :listingId
                SQL,
                [
                    'sold' => ListingStatus::SOLD,
                    'buyerId' => Uuid::fromHexToBytes($buyerCustomerId),
                    'soldPrice' => $item['unit_price'],
                    'soldAt' => UtcDateTime::now()->format(UtcDateTime::STORAGE_FORMAT),
                    'orderId' => Uuid::fromHexToBytes($orderId),
                    'orderVersionId' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
                    'soldTicketId' => Uuid::fromHexToBytes($newTicket->getId()),
                    'listingId' => Uuid::fromHexToBytes($item['listing_id']),
                ],
            );

            return true;
        });
    }

    /**
     * Runs the transfer primitive for a settling listing. A transfer failure
     * (e.g. the seller scanned the ticket after the buyer had already paid)
     * must NEVER escape into the payment state transition — the order has to
     * reach `paid` regardless. The listing is closed instead and the
     * conflict logged loudly: refunding the buyer is an operator decision.
     */
    private function transferOrCloseConflicted(
        string $ticketId,
        string $listingId,
        string $orderId,
        string $buyerCustomerId,
        Context $context,
    ): ?BookingTicket {
        try {
            return $this->transferService->transfer($ticketId, $context, $buyerCustomerId);
        } catch (FibBookingException $exception) {
            $this->connection->executeStatement(
                <<<'SQL'
                    UPDATE fib_booking_listing
                    SET status = :cancelled, active_ticket_id = NULL, updated_at = UTC_TIMESTAMP(3)
                    WHERE id = :listingId
                SQL,
                [
                    'cancelled' => ListingStatus::CANCELLED,
                    'listingId' => Uuid::fromHexToBytes($listingId),
                ],
            );

            $this->logger->error('Resale settlement conflict: ticket of listing {listingId} is no longer transferable ({reason}) but order {orderId} paid for it — refund the buyer manually.', [
                'listingId' => $listingId,
                'reason' => $exception->getMessage(),
                'orderId' => $orderId,
            ]);

            return null;
        }
    }

    /**
     * @return list<ResaleOrderItem>
     */
    private function fetchResaleOrderItems(string $orderId): array
    {
        /** @var list<array{listing_id: string|null, unit_price: string|float|null}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                -- referenced_id is a VARCHAR carrying the hex id as-is (no FK)
                SELECT LOWER(referenced_id) AS listing_id, unit_price
                FROM order_line_item
                WHERE order_id = :orderId AND version_id = :liveVersion AND type = 'fib-resale'
            SQL,
            [
                'orderId' => Uuid::fromHexToBytes($orderId),
                'liveVersion' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            ],
        );

        $items = [];

        foreach ($rows as $row) {
            if (is_string($row['listing_id']) && Uuid::isValid($row['listing_id'])) {
                $items[] = [
                    'listing_id' => $row['listing_id'],
                    'unit_price' => (float) $row['unit_price'],
                ];
            }
        }

        return $items;
    }

    private function fetchBuyerCustomerId(string $orderId): ?string
    {
        $customerId = $this->connection->fetchOne(
            <<<'SQL'
                SELECT LOWER(HEX(customer_id)) FROM order_customer
                WHERE order_id = :orderId AND version_id = :liveVersion
            SQL,
            [
                'orderId' => Uuid::fromHexToBytes($orderId),
                'liveVersion' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            ],
        );

        return is_string($customerId) && $customerId !== '' ? $customerId : null;
    }

    private function fetchOrderIdForTransaction(string $orderTransactionId): ?string
    {
        $orderId = $this->connection->fetchOne(
            <<<'SQL'
                SELECT LOWER(HEX(order_id)) FROM order_transaction
                WHERE id = :transactionId AND version_id = :liveVersion
            SQL,
            [
                'transactionId' => Uuid::fromHexToBytes($orderTransactionId),
                'liveVersion' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
            ],
        );

        return is_string($orderId) && $orderId !== '' ? $orderId : null;
    }
}
