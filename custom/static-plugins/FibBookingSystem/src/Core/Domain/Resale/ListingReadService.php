<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Resale;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Storefront read models for the resale market: the public browse page and
 * the per-ticket listing state in the customer account. Read-only by
 * construction — every write goes through BookingResaleService.
 *
 * Deliberate raw DBAL (documented exception, see docs/ARCHITECTURE_PLAN.md):
 * set-based reads over guard columns that are not in the DAL definition.
 *
 * @phpstan-type BrowseRow array{listing_id: string, ask_price: float, created_at: string, ticket_number: string, seat_label: string|null, resource_name: string, starts_at: string, ends_at: string}
 * @phpstan-type AccountListing array{listingId: string, askPrice: float, status: string}
 */
class ListingReadService
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Active fixed-price lots for the public browse page, newest first.
     * Auctions get their own surface in a later phase.
     *
     * @return list<BrowseRow>
     */
    public function fetchActiveFixedPrice(int $limit = 50): array
    {
        $sql = <<<'SQL'
            SELECT LOWER(HEX(listing.id)) AS listing_id, listing.ask_price, listing.created_at,
            ticket.ticket_number, ticket.seat_label,
            resource.name AS resource_name, reservation.starts_at, reservation.ends_at
            FROM fib_booking_listing listing
            INNER JOIN fib_booking_ticket ticket ON ticket.id = listing.ticket_id
            INNER JOIN fib_booking_reservation reservation ON reservation.id = ticket.reservation_id
            INNER JOIN fib_booking_resource resource ON resource.id = reservation.resource_id
            WHERE listing.status = :active AND listing.mode = :fixedPrice
            ORDER BY listing.created_at DESC
            SQL;

        /** @var list<array{listing_id: string, ask_price: string|float, created_at: string, ticket_number: string, seat_label: string|null, resource_name: string, starts_at: string, ends_at: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            // LIMIT is not parameterizable — clamped integer interpolation.
            sprintf('%s LIMIT %d', $sql, max(1, min(100, $limit))),
            [
                'active' => ListingStatus::ACTIVE,
                'fixedPrice' => ListingMode::FIXED_PRICE,
            ],
        );

        $result = [];

        foreach ($rows as $row) {
            $result[] = [...$row, 'ask_price' => (float) $row['ask_price']];
        }

        return $result;
    }

    /**
     * Listing state for the account ticket list: which of MY tickets are
     * currently up for sale (or claimed by a buyer order), and at what price.
     * `pending` is included so the seller sees WHY the sell form is gone —
     * but only `active` listings are seller-cancellable.
     *
     * @param list<string> $ticketIds
     *
     * @return array<string, AccountListing> keyed by ticket id (hex, lowercase)
     */
    public function fetchActiveForTickets(array $ticketIds): array
    {
        $ids = [];
        foreach (array_unique($ticketIds) as $ticketId) {
            if (Uuid::isValid($ticketId)) {
                $ids[] = Uuid::fromHexToBytes($ticketId);
            }
        }

        if ($ids === []) {
            return [];
        }

        /** @var list<array{ticket_id: string, listing_id: string, ask_price: string|float, status: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT LOWER(HEX(ticket_id)) AS ticket_id, LOWER(HEX(id)) AS listing_id, ask_price, status
                FROM fib_booking_listing
                WHERE ticket_id IN (:ids) AND status IN (:live)
            SQL,
            ['ids' => $ids, 'live' => [ListingStatus::ACTIVE, ListingStatus::PENDING]],
            ['ids' => ArrayParameterType::BINARY, 'live' => ArrayParameterType::STRING],
        );

        $result = [];

        foreach ($rows as $row) {
            $result[$row['ticket_id']] = [
                'listingId' => $row['listing_id'],
                'askPrice' => (float) $row['ask_price'],
                'status' => $row['status'],
            ];
        }

        return $result;
    }
}
