<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Resale;

use DateInterval;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use FibBookingSystem\Core\Domain\Time\UtcDateTime;
use FibBookingSystem\FibBookingException;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Listing lifecycle with the resale guardrails (docs/RESELL_PLAN.md):
 *
 * - only the ticket OWNER (reservation customer) may list,
 * - only live tickets (issued/sent, not expired/revoked/scanned),
 * - slot tickets only until `resaleCutoffMinutes` before the slot starts,
 * - optional anti-scalping cap: ask price ≤ `resaleMaxFactor` × paid unit
 *   price (regulatory in several markets; 0 = off),
 * - ONE live listing per ticket — decided by the database via the
 *   `active_ticket_id` UNIQUE key (insert-wins, same philosophy as seat
 *   claims).
 *
 * Deliberate raw DBAL (documented exception, see docs/ARCHITECTURE_PLAN.md):
 * `active_ticket_id` is intentionally NOT part of the DAL definition (an
 * internal guard column, like scan_token_cipher), and the insert race IS the
 * business signal.
 *
 * @phpstan-type ListableRow array{status: string, expires_at: string|null, owner_customer_id: string|null, starts_at: string, validity_mode: string|null, unit_price: float|string|null}
 */
class BookingResaleService
{
    private const DEFAULT_CUTOFF_MINUTES = 60;

    public function __construct(
        private readonly Connection $connection,
        private readonly SystemConfigService $systemConfig,
    ) {
    }

    /**
     * @return string the listing id
     */
    public function createListing(
        string $ticketId,
        string $sellerCustomerId,
        string $mode,
        float $askPrice,
        ?float $reservePrice = null,
        ?float $minIncrement = null,
        ?DateTimeInterface $endsAt = null,
    ): string {
        if (!in_array($mode, ListingMode::ALL, true)) {
            throw FibBookingException::invalidPayload('mode', sprintf('unknown listing mode "%s"', $mode));
        }

        if ($askPrice <= 0) {
            throw FibBookingException::invalidPayload('askPrice', 'must be positive');
        }

        if ($mode === ListingMode::AUCTION && $endsAt === null) {
            throw FibBookingException::invalidPayload('endsAt', 'auctions need an end time');
        }

        $ticket = $this->fetchListableRow($ticketId);
        $this->assertListable($ticket, $sellerCustomerId, $askPrice);

        $listingId = Uuid::randomHex();

        try {
            $this->connection->insert('fib_booking_listing', [
                'id' => Uuid::fromHexToBytes($listingId),
                'ticket_id' => Uuid::fromHexToBytes($ticketId),
                // THE guard: UNIQUE — a concurrent second listing loses here.
                'active_ticket_id' => Uuid::fromHexToBytes($ticketId),
                'seller_customer_id' => Uuid::fromHexToBytes($sellerCustomerId),
                'mode' => $mode,
                'status' => ListingStatus::ACTIVE,
                'ask_price' => $askPrice,
                'reserve_price' => $reservePrice,
                'min_increment' => $minIncrement,
                'ends_at' => $endsAt !== null ? UtcDateTime::toStorage($endsAt) : null,
                'created_at' => UtcDateTime::now()->format(UtcDateTime::STORAGE_FORMAT),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw BookingResaleException::listingAlreadyExists();
        }

        return $listingId;
    }

    public function cancelListing(
        string $listingId,
        string $sellerCustomerId,
    ): void {
        $seller = $this->connection->fetchOne(
            <<<'SQL'
                SELECT LOWER(HEX(seller_customer_id)) FROM fib_booking_listing
                WHERE id = :listingId AND status = 'active'
            SQL,
            ['listingId' => Uuid::fromHexToBytes($listingId)],
        );

        if (!is_string($seller)) {
            throw BookingResaleException::listingNotFound();
        }

        if ($seller !== strtolower($sellerCustomerId)) {
            throw BookingResaleException::notListingOwner();
        }

        // Releasing the unique guard makes the ticket listable again.
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE fib_booking_listing
                SET status = 'cancelled', active_ticket_id = NULL, updated_at = UTC_TIMESTAMP(3)
                WHERE id = :listingId AND status = 'active'
            SQL,
            ['listingId' => Uuid::fromHexToBytes($listingId)],
        );
    }

    /**
     * @return ListableRow
     */
    private function fetchListableRow(string $ticketId): array
    {
        /** @var ListableRow|false $ticket */
        $ticket = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT ticket.status, ticket.expires_at,
                -- effective owner: a transfer override (resale) wins over the
                -- reservation customer (primary market default)
                LOWER(HEX(COALESCE(ticket.owner_customer_id, reservation.customer_id))) AS owner_customer_id,
                reservation.starts_at,
                config.validity_mode,
                line_item.unit_price
                FROM fib_booking_ticket ticket
                INNER JOIN fib_booking_reservation reservation ON reservation.id = ticket.reservation_id
                LEFT JOIN order_line_item line_item
                ON line_item.id = reservation.order_line_item_id AND line_item.version_id = reservation.order_line_item_version_id
                LEFT JOIN fib_booking_product_config config
                ON config.product_id = line_item.product_id AND config.product_version_id = line_item.product_version_id
                WHERE ticket.id = :ticketId
            SQL,
            ['ticketId' => Uuid::fromHexToBytes($ticketId)],
        );

        if ($ticket === false) {
            throw BookingResaleException::ticketNotListable('ticket not found');
        }

        return $ticket;
    }

    /**
     * @param ListableRow $ticket
     */
    private function assertListable(
        array $ticket,
        string $sellerCustomerId,
        float $askPrice,
    ): void {
        if ($ticket['owner_customer_id'] === null || $ticket['owner_customer_id'] !== strtolower($sellerCustomerId)) {
            throw BookingResaleException::ticketNotListable('you do not own this ticket');
        }

        if (!in_array($ticket['status'], ['issued', 'sent'], true)) {
            throw BookingResaleException::ticketNotListable(sprintf('status "%s"', $ticket['status']));
        }

        if ($ticket['expires_at'] !== null && UtcDateTime::parse($ticket['expires_at']) <= UtcDateTime::now()) {
            throw BookingResaleException::ticketNotListable('ticket is expired');
        }

        $this->assertBeforeCutoff($ticket);
        $this->assertPriceCap($ticket, $askPrice);
    }

    /**
     * Slot-bound tickets must leave the buyer enough time to actually use
     * them — period/unlimited passes have no slot to be late for.
     *
     * @param ListableRow $ticket
     */
    private function assertBeforeCutoff(array $ticket): void
    {
        $mode = $ticket['validity_mode'];
        if (is_string($mode) && $mode !== '' && $mode !== 'slot') {
            return;
        }

        // Unset config falls back to the default; an explicit 0 disables.
        $raw = $this->systemConfig->get('FibBookingSystem.config.resaleCutoffMinutes');
        $cutoffMinutes = is_numeric($raw) ? max(0, (int) $raw) : self::DEFAULT_CUTOFF_MINUTES;

        if ($cutoffMinutes === 0) {
            return;
        }

        $cutoff = UtcDateTime::parse($ticket['starts_at'])->sub(new DateInterval(sprintf('PT%dM', $cutoffMinutes)));

        if (UtcDateTime::now() >= $cutoff) {
            throw BookingResaleException::ticketNotListable('too close to the booked slot');
        }
    }

    /**
     * Anti-scalping price cap (`resaleMaxFactor`, 0/unset = off): ask price
     * may not exceed factor × the originally paid unit price. Tickets
     * without an order (manually issued) have no reference price — exempt.
     *
     * @param ListableRow $ticket
     */
    private function assertPriceCap(
        array $ticket,
        float $askPrice,
    ): void {
        $raw = $this->systemConfig->get('FibBookingSystem.config.resaleMaxFactor');
        $factor = is_numeric($raw) ? max(0.0, (float) $raw) : 0.0;

        if ($factor <= 0.0 || !is_numeric($ticket['unit_price'])) {
            return;
        }

        $cap = round((float) $ticket['unit_price'] * $factor, 2);

        if ($askPrice > $cap) {
            throw BookingResaleException::ticketNotListable(sprintf('price exceeds the allowed maximum of %.2f', $cap));
        }
    }
}
