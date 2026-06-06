<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Resale;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Batched read model for the cart: everything the processor needs to price
 * and label `fib-resale` line items, one query per cart calculation.
 *
 * Deliberate raw DBAL (documented exception, see docs/ARCHITECTURE_PLAN.md):
 * cart calculation is a hot path, and the listing guard columns are not part
 * of the DAL definition anyway.
 *
 * @phpstan-type CartRow array{listing_id: string, ticket_id: string, status: string, mode: string, ask_price: float, seller_customer_id: string, ticket_number: string, seat_label: string|null, resource_name: string, starts_at: string}
 */
class ListingCartReader
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param list<string> $listingIds
     *
     * @return array<string, CartRow> keyed by listing id (hex, lowercase)
     */
    public function fetchCartRows(array $listingIds): array
    {
        $ids = [];
        foreach (array_unique($listingIds) as $listingId) {
            if (Uuid::isValid($listingId)) {
                $ids[] = Uuid::fromHexToBytes($listingId);
            }
        }

        if ($ids === []) {
            return [];
        }

        /** @var list<array{listing_id: string, ticket_id: string, status: string, mode: string, ask_price: string|float, seller_customer_id: string, ticket_number: string, seat_label: string|null, resource_name: string, starts_at: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT LOWER(HEX(listing.id)) AS listing_id, LOWER(HEX(listing.ticket_id)) AS ticket_id,
                listing.status, listing.mode, listing.ask_price,
                LOWER(HEX(listing.seller_customer_id)) AS seller_customer_id,
                ticket.ticket_number, ticket.seat_label,
                resource.name AS resource_name, reservation.starts_at
                FROM fib_booking_listing listing
                INNER JOIN fib_booking_ticket ticket ON ticket.id = listing.ticket_id
                INNER JOIN fib_booking_reservation reservation ON reservation.id = ticket.reservation_id
                INNER JOIN fib_booking_resource resource ON resource.id = reservation.resource_id
                WHERE listing.id IN (:ids)
            SQL,
            ['ids' => $ids],
            ['ids' => ArrayParameterType::BINARY],
        );

        $result = [];

        foreach ($rows as $row) {
            $result[$row['listing_id']] = [...$row, 'ask_price' => (float) $row['ask_price']];
        }

        return $result;
    }
}
