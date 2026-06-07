<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Ticket;

use DateInterval;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use FibBookingSystem\Core\Content\BookingTicket\BookingTicketCollection;
use FibBookingSystem\Core\Domain\Time\UtcDateTime;
use FibBookingSystem\Core\Domain\Validity\EntryPolicy;
use FibBookingSystem\Core\Domain\Validity\ValidityAnchor;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * The scan VERDICT matrix, split out of TicketScanService so that class stays
 * a lean orchestrator (lock → fetch → verdict → log). Both classes share the
 * SAME DBAL connection, so every write here still runs inside the FOR UPDATE
 * transaction TicketScanService opens — the row stays locked across the
 * verdict, status transition and first-use activation.
 *
 * Lifecycle (see TicketScanService docblock for the full matrix):
 * - check-in:  revoked / expired / not-yet-valid / already-scanned (single)
 *              vs VALID (issued/sent/checked-out, multi re-entry).
 * - check-out: only a currently checked-in ticket; expiry never blocks it.
 *
 * @phpstan-type TicketRow array{id: string, ticket_number: string, status: string, expires_at: string|null, scanned_at: string|null, valid_from: string|null, entry_policy: string, max_entries_per_day: int|string|null, validity_anchor: string|null, validity_duration: string|null, seat_label: string|null, booking_number: string|null, rotating_qr_enabled: int|string|null, rotating_qr_interval: int|string|null, last_rotating_window: int|string|null, scan_token_cipher: string|null}
 */
class ScanVerdictResolver
{
    private const CHECK_IN_STATUSES = ['issued', 'sent', 'checked_out'];

    /**
     * @param EntityRepository<BookingTicketCollection> $ticketRepository
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityRepository $ticketRepository,
    ) {
    }

    /**
     * @param TicketRow|false $ticket
     */
    public function checkIn(
        array|false $ticket,
        Context $context,
    ): TicketScanResult {
        if ($ticket === false) {
            return new TicketScanResult(TicketScanResult::NOT_FOUND);
        }

        $ticketNumber = $ticket['ticket_number'];
        $bookingNumber = $ticket['booking_number'];
        $status = $ticket['status'];

        if ($status === 'revoked') {
            return new TicketScanResult(TicketScanResult::REVOKED, $ticketNumber, $bookingNumber);
        }

        $expired = $this->resolveExpiredVerdict($ticket, $context);
        if ($expired !== null) {
            return $expired;
        }

        if ($ticket['valid_from'] !== null && UtcDateTime::parse($ticket['valid_from']) > UtcDateTime::now()) {
            return new TicketScanResult(TicketScanResult::NOT_YET_VALID, $ticketNumber, $bookingNumber, seatLabel: $ticket['seat_label']);
        }

        if ($status === 'scanned') {
            // Multi-entry passes may enter again while "inside" (pass-style
            // usage without check-out); single tickets are consumed.
            return $ticket['entry_policy'] === EntryPolicy::MULTI
                ? $this->acceptEntry($ticket, $context, transition: false)
                : new TicketScanResult(TicketScanResult::ALREADY_SCANNED, $ticketNumber, $bookingNumber, $ticket['scanned_at'] ?? '', seatLabel: $ticket['seat_label']);
        }

        if (!in_array($status, self::CHECK_IN_STATUSES, true)) {
            return new TicketScanResult(TicketScanResult::NOT_FOUND);
        }

        return $this->acceptEntry($ticket, $context, transition: true);
    }

    /**
     * @param TicketRow|false $ticket
     */
    public function checkOut(
        array|false $ticket,
        Context $context,
    ): TicketScanResult {
        if ($ticket === false) {
            return new TicketScanResult(TicketScanResult::NOT_FOUND, direction: ScanDirection::CHECK_OUT);
        }

        $ticketNumber = $ticket['ticket_number'];
        $bookingNumber = $ticket['booking_number'];
        $status = $ticket['status'];

        if ($status === 'revoked') {
            return new TicketScanResult(TicketScanResult::REVOKED, $ticketNumber, $bookingNumber, direction: ScanDirection::CHECK_OUT);
        }

        // Only a currently checked-in guest can check out. Expiry does not
        // block leaving — the visit already happened.
        if ($status !== 'scanned') {
            return new TicketScanResult(TicketScanResult::NOT_CHECKED_IN, $ticketNumber, $bookingNumber, direction: ScanDirection::CHECK_OUT, seatLabel: $ticket['seat_label']);
        }

        $this->updateTicket([
            'id' => Uuid::fromBytesToHex($ticket['id']),
            'status' => 'checked_out',
        ], $context);

        return new TicketScanResult(
            TicketScanResult::CHECKED_OUT,
            $ticketNumber,
            $bookingNumber,
            $ticket['scanned_at'],
            ScanDirection::CHECK_OUT,
            $ticket['seat_label'],
        );
    }

    /**
     * Shared tail of every successful check-in: daily limit, first-use
     * activation, scan-then-sell guard, status transition, VALID result.
     *
     * @param TicketRow $ticket
     */
    private function acceptEntry(
        array $ticket,
        Context $context,
        bool $transition,
    ): TicketScanResult {
        if ($this->hasReachedDailyLimit($ticket)) {
            return new TicketScanResult(TicketScanResult::ENTRY_LIMIT_REACHED, $ticket['ticket_number'], $ticket['booking_number'], seatLabel: $ticket['seat_label']);
        }

        $this->activateFirstUse($ticket, $context);

        $this->cancelLiveResaleListings($ticket['id']);

        // Re-entry keeps the FIRST entry time on the ticket; the per-session
        // history is reconstructed from the scan log.
        $scannedAt = $ticket['scanned_at'] !== null
            ? UtcDateTime::parse($ticket['scanned_at'])
            : UtcDateTime::now();

        if ($transition) {
            $this->updateTicket([
                'id' => Uuid::fromBytesToHex($ticket['id']),
                'status' => 'scanned',
                // UTC object: keeps millisecond precision AND lets the DAL
                // serializer normalize — a replay reports the exact
                // first-scan time.
                'scannedAt' => $scannedAt,
            ], $context);
        }

        return new TicketScanResult(TicketScanResult::VALID, $ticket['ticket_number'], $ticket['booking_number'], $this->formatDateTime($scannedAt), seatLabel: $ticket['seat_label']);
    }

    /**
     * Scan-then-sell guard: releases the unique active_ticket_id guard and
     * cancels every non-final listing of the just-used ticket. Status list
     * covers 'active' and the order-placed claim state ('pending').
     */
    private function cancelLiveResaleListings(string $ticketIdBytes): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE fib_booking_listing
                SET status = 'cancelled', active_ticket_id = NULL, updated_at = UTC_TIMESTAMP(3)
                WHERE ticket_id = :ticketId AND status IN ('active', 'pending')
            SQL,
            ['ticketId' => $ticketIdBytes],
        );
    }

    /**
     * Daily cap for multi-entry passes: counts today's successful check-ins
     * in the scan log — raw SQL on the same connection, hence inside the
     * FOR UPDATE lock scope (no double entry through concurrent scans).
     * Dates are evaluated in UTC, matching all stored timestamps.
     *
     * @param TicketRow $ticket
     */
    private function hasReachedDailyLimit(array $ticket): bool
    {
        $limit = $ticket['max_entries_per_day'];
        if ($ticket['entry_policy'] !== EntryPolicy::MULTI || !is_numeric($limit) || (int) $limit <= 0) {
            return false;
        }

        $entriesToday = $this->connection->fetchOne(
            <<<'SQL'
                SELECT COUNT(*) FROM fib_booking_scan_log
                WHERE ticket_id = :ticketId AND direction = 'check_in' AND verdict = 'valid'
                AND created_at >= :dayStart
            SQL,
            [
                'ticketId' => $ticket['id'],
                // "Today" starts at UTC midnight — `new DateTimeImmutable('today')`
                // would use the PHP default timezone and shift the window.
                'dayStart' => UtcDateTime::toStorage(UtcDateTime::now()->setTime(0, 0)),
            ],
        );

        return is_numeric($entriesToday) && (int) $entriesToday >= (int) $limit;
    }

    /**
     * First-use anchor: the first successful check-in activates the pass —
     * valid_from = now, expires_at = now + snapshotted duration. Runs inside
     * the lock scope, so concurrent first scans activate exactly once.
     *
     * @param TicketRow $ticket
     */
    private function activateFirstUse(
        array $ticket,
        Context $context,
    ): void {
        if ($ticket['validity_anchor'] !== ValidityAnchor::FIRST_USE
            || $ticket['valid_from'] !== null
            || !is_string($ticket['validity_duration'])
            || $ticket['validity_duration'] === ''
        ) {
            return;
        }

        $now = UtcDateTime::now();

        $this->updateTicket([
            'id' => Uuid::fromBytesToHex($ticket['id']),
            'validFrom' => $now,
            'expiresAt' => $now->add(new DateInterval($ticket['validity_duration'])),
        ], $context);
    }

    /**
     * Returns the EXPIRED result when the ticket is (or has just become)
     * expired — transitioning it lazily on first contact — or null when the
     * ticket is still usable.
     *
     * @param TicketRow $ticket
     */
    private function resolveExpiredVerdict(
        array $ticket,
        Context $context,
    ): ?TicketScanResult {
        $isExpired = $ticket['expires_at'] !== null
            && UtcDateTime::parse($ticket['expires_at']) < UtcDateTime::now();

        if ($ticket['status'] !== 'expired' && !$isExpired) {
            return null;
        }

        if ($ticket['status'] !== 'expired') {
            $this->updateTicket([
                'id' => Uuid::fromBytesToHex($ticket['id']),
                'status' => 'expired',
            ], $context);
        }

        return new TicketScanResult(TicketScanResult::EXPIRED, $ticket['ticket_number'], $ticket['booking_number'], seatLabel: $ticket['seat_label']);
    }

    /**
     * DAL write — shares the connection, so it stays inside the FOR UPDATE
     * transaction scope opened by TicketScanService.
     *
     * SYSTEM_SCOPE: authorization happens at the route (`_acl` =
     * fib_booking.ticket_scan). The scanner role deliberately carries NO
     * generic entity-write privileges — these transitions are internal
     * effects of an authorized scan, not Admin-API entity writes.
     *
     * @param array<string, mixed> $update
     */
    private function updateTicket(
        array $update,
        Context $context,
    ): void {
        $context->scope(Context::SYSTEM_SCOPE, function (Context $systemContext) use ($update): void {
            $this->ticketRepository->update([$update], $systemContext);
        });
    }

    private function formatDateTime(DateTimeInterface $dateTime): string
    {
        return UtcDateTime::toStorage($dateTime);
    }
}
