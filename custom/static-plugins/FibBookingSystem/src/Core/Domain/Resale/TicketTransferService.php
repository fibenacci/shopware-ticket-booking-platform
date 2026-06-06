<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Resale;

use Doctrine\DBAL\Connection;
use FibBookingSystem\Core\Domain\Security\TokenCipher;
use FibBookingSystem\Core\Domain\Ticket\BookingTicket;
use FibBookingSystem\Core\Domain\Ticket\BookingTicketService;
use FibBookingSystem\Core\Domain\Ticket\QrCodeGenerator;
use FibBookingSystem\Core\Domain\Time\UtcDateTime;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;

/**
 * The transfer primitive (docs/RESELL_PLAN.md): a resale MUST kill the
 * seller's copy — QR screenshot, wallet pass, PDF, all of it.
 *
 * revoke + re-issue in ONE transaction on the ticket row lock:
 * the old ticket flips to `revoked` (scan-dead immediately), a NEW row is
 * issued with a FRESH scan token/cipher/number, carrying over every
 * snapshot (seat label, validity window/policy, payload) and pointing back
 * via `replaced_ticket_id` for the audit chain.
 *
 * Deliberate raw DBAL (documented exception, see docs/ARCHITECTURE_PLAN.md):
 * the FOR UPDATE lock serializes concurrent transfers of the same ticket,
 * and scan_token_cipher is not part of the DAL definition (Security.md) —
 * the insert mirrors BookingTicketService.
 *
 * Invariant: no two live tickets per lineage — the old row is revoked in
 * the same transaction that creates the new one.
 *
 * @phpstan-type TransferableRow array{id: string, reservation_id: string, status: string, expires_at: string|null, valid_from: string|null, entry_policy: string, max_entries_per_day: int|string|null, validity_anchor: string|null, validity_duration: string|null, seat_label: string|null, payload: string|null, owner_customer_id: string|null, sales_channel_id: string|null}
 */
class TicketTransferService
{
    private const TRANSFERABLE_STATUSES = ['issued', 'sent'];

    public function __construct(
        private readonly Connection $connection,
        private readonly QrCodeGenerator $qrCodeGenerator,
        private readonly NumberRangeValueGeneratorInterface $numberRangeValueGenerator,
        private readonly TokenCipher $tokenCipher,
    ) {
    }

    /**
     * @param string|null $newOwnerCustomerId the buyer — stamped as
     *                                        owner_customer_id on the replacement so ownership moves at
     *                                        ticket level while the reservation (capacity) stays untouched;
     *                                        null re-issues for the current owner (e.g. lost-ticket flows)
     */
    public function transfer(string $ticketId, Context $context, ?string $newOwnerCustomerId = null): BookingTicket
    {
        return $this->connection->transactional(function () use ($ticketId, $context, $newOwnerCustomerId): BookingTicket {
            /** @var TransferableRow|false $ticket */
            $ticket = $this->connection->fetchAssociative(
                <<<'SQL'
                    SELECT ticket.id, LOWER(HEX(ticket.reservation_id)) AS reservation_id, ticket.status,
                    ticket.expires_at, ticket.valid_from, ticket.entry_policy, ticket.max_entries_per_day,
                    ticket.validity_anchor, ticket.validity_duration, ticket.seat_label, ticket.payload,
                    LOWER(HEX(ticket.owner_customer_id)) AS owner_customer_id,
                    LOWER(HEX(`order`.sales_channel_id)) AS sales_channel_id
                    FROM fib_booking_ticket ticket
                    INNER JOIN fib_booking_reservation reservation ON reservation.id = ticket.reservation_id
                    LEFT JOIN `order` ON `order`.id = reservation.order_id AND `order`.version_id = reservation.order_version_id
                    WHERE ticket.id = :ticketId
                    FOR UPDATE
                SQL,
                ['ticketId' => Uuid::fromHexToBytes($ticketId)],
            );

            if ($ticket === false) {
                throw BookingResaleException::ticketNotTransferable('ticket not found');
            }

            if (!in_array($ticket['status'], self::TRANSFERABLE_STATUSES, true)) {
                throw BookingResaleException::ticketNotTransferable(sprintf('status "%s"', $ticket['status']));
            }

            if ($ticket['expires_at'] !== null && UtcDateTime::parse($ticket['expires_at']) <= UtcDateTime::now()) {
                throw BookingResaleException::ticketNotTransferable('ticket is expired');
            }

            // 1. Kill the seller's copy — same transaction, scan-dead at commit.
            $this->connection->executeStatement(
                <<<'SQL'
                    UPDATE fib_booking_ticket
                    SET status = 'revoked', updated_at = UTC_TIMESTAMP(3)
                    WHERE id = :ticketId
                SQL,
                ['ticketId' => $ticket['id']],
            );

            // 2. Fresh identity for the buyer, snapshots carried over.
            return $this->issueReplacement($ticket, $ticketId, $context, $newOwnerCustomerId);
        });
    }

    /**
     * @param TransferableRow $ticket
     */
    private function issueReplacement(array $ticket, string $oldTicketId, Context $context, ?string $newOwnerCustomerId): BookingTicket
    {
        $newTicketId = Uuid::randomHex();
        $ticketNumber = $this->numberRangeValueGenerator->getValue(
            BookingTicketService::NUMBER_RANGE_TYPE,
            $context,
            $ticket['sales_channel_id'],
        );
        $scanToken = bin2hex(random_bytes(32));
        $qrPayload = json_encode([
            'type' => 'fib_booking_ticket',
            'ticketNumber' => $ticketNumber,
            'scanToken' => $scanToken,
        ], \JSON_THROW_ON_ERROR);
        $now = UtcDateTime::now()->format(UtcDateTime::STORAGE_FORMAT);

        $this->connection->insert('fib_booking_ticket', [
            'id' => Uuid::fromHexToBytes($newTicketId),
            'reservation_id' => Uuid::fromHexToBytes($ticket['reservation_id']),
            'ticket_number' => $ticketNumber,
            'scan_token_hash' => hash('sha256', $scanToken),
            'scan_token_cipher' => $this->tokenCipher->encrypt($scanToken),
            'status' => 'issued',
            'issued_at' => $now,
            'expires_at' => $ticket['expires_at'],
            'valid_from' => $ticket['valid_from'],
            'entry_policy' => $ticket['entry_policy'],
            'max_entries_per_day' => $ticket['max_entries_per_day'],
            'validity_anchor' => $ticket['validity_anchor'],
            'validity_duration' => $ticket['validity_duration'],
            'seat_label' => $ticket['seat_label'],
            'replaced_ticket_id' => Uuid::fromHexToBytes($oldTicketId),
            'owner_customer_id' => ($newOwnerCustomerId ?? $ticket['owner_customer_id']) !== null
                ? Uuid::fromHexToBytes((string) ($newOwnerCustomerId ?? $ticket['owner_customer_id']))
                : null,
            'payload' => $ticket['payload'],
            'created_at' => $now,
        ]);

        return new BookingTicket(
            $newTicketId,
            $ticketNumber,
            $scanToken,
            $qrPayload,
            $this->qrCodeGenerator->generateDataUri($qrPayload),
            $ticket['seat_label'],
        );
    }
}
