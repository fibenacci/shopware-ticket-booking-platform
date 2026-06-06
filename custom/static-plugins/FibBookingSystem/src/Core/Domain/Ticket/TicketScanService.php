<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Ticket;

use DateInterval;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use FibBookingSystem\Core\Content\BookingScanLog\BookingScanLogCollection;
use FibBookingSystem\Core\Content\BookingTicket\BookingTicketCollection;
use FibBookingSystem\Core\Domain\Time\UtcDateTime;
use FibBookingSystem\Core\Domain\Validity\EntryPolicy;
use FibBookingSystem\Core\Domain\Validity\ValidityAnchor;
use FibBookingSystem\FibBookingException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Validates scan tokens and transitions tickets along the scan lifecycle —
 * race-safe via SELECT ... FOR UPDATE, so two simultaneous scans of the same
 * ticket resolve deterministically into one VALID and one ALREADY_SCANNED
 * verdict.
 *
 * Directions (check-out only when the operator enables it via plugin config
 * `scanCheckOutEnabled`):
 *
 * - check_in:  issued/sent → scanned. Re-entry: checked_out → scanned again.
 *   `scanned_at` keeps the FIRST entry time; individual sessions live in the
 *   scan log.
 * - check_out: scanned → checked_out. Anything not currently checked in
 *   yields NOT_CHECKED_IN. Expiry does not block leaving.
 *
 * Validity model (snapshotted on the ticket at issue time, see
 * TicketValidityResolver):
 *
 * - valid_from in the future → NOT_YET_VALID.
 * - first_use anchor: the first successful check-in activates the pass
 *   (valid_from = now, expires_at = now + duration) inside the lock scope.
 * - entry_policy multi: a currently checked-in ticket may check in again
 *   (pass-style usage without check-out), optionally capped per day
 *   (ENTRY_LIMIT_REACHED).
 *
 * Every attempt is written to fib_booking_scan_log with its direction. The
 * token itself is never persisted — only a 12-char fingerprint of its sha256
 * hash for correlation.
 *
 * @phpstan-type TicketRow array{id: string, ticket_number: string, status: string, expires_at: string|null, scanned_at: string|null, valid_from: string|null, entry_policy: string, max_entries_per_day: int|string|null, validity_anchor: string|null, validity_duration: string|null, seat_label: string|null, booking_number: string|null}
 */
class TicketScanService
{
    private const CHECK_IN_STATUSES = ['issued', 'sent', 'checked_out'];

    /**
     * The DBAL connection is used for one thing only: the pessimistic
     * `SELECT … FOR UPDATE` lock that makes concurrent scans of the same
     * ticket resolve deterministically. The status transition goes through
     * the DAL on the same connection, hence inside the lock scope.
     *
     * @param EntityRepository<BookingTicketCollection>  $ticketRepository
     * @param EntityRepository<BookingScanLogCollection> $scanLogRepository
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityRepository $ticketRepository,
        private readonly EntityRepository $scanLogRepository,
        private readonly SystemConfigService $systemConfig,
    ) {
    }

    public function isCheckOutEnabled(): bool
    {
        return (bool) $this->systemConfig->get('FibBookingSystem.config.scanCheckOutEnabled');
    }

    public function scan(
        string $scanToken,
        Context $context,
        ?string $scannedBy = null,
        string $source = 'api',
        string $direction = ScanDirection::CHECK_IN,
        ?string $gate = null,
    ): TicketScanResult {
        if ($direction === ScanDirection::CHECK_OUT && !$this->isCheckOutEnabled()) {
            throw FibBookingException::scanCheckOutDisabled();
        }

        $tokenHash = hash('sha256', $scanToken);

        return $this->connection->transactional(function () use ($tokenHash, $context, $scannedBy, $source, $direction, $gate): TicketScanResult {
            /** @var TicketRow|false $ticket */
            $ticket = $this->connection->fetchAssociative(
                <<<'SQL'
                    SELECT ticket.id, ticket.ticket_number, ticket.status, ticket.expires_at, ticket.scanned_at,
                    ticket.valid_from, ticket.entry_policy, ticket.max_entries_per_day,
                    ticket.validity_anchor, ticket.validity_duration, ticket.seat_label,
                    reservation.booking_number
                    FROM fib_booking_ticket ticket
                    LEFT JOIN fib_booking_reservation reservation ON reservation.id = ticket.reservation_id
                    WHERE ticket.scan_token_hash = :tokenHash
                    FOR UPDATE
                SQL,
                ['tokenHash' => $tokenHash],
            );

            $result = $direction === ScanDirection::CHECK_OUT
                ? $this->resolveCheckOutVerdict($ticket, $context)
                : $this->resolveCheckInVerdict($ticket, $context);
            $this->logAttempt($context, $result, $ticket === false ? null : $ticket['id'], $tokenHash, $scannedBy, $source, $gate);

            return $result;
        });
    }

    /**
     * @param TicketRow|false $ticket
     */
    private function resolveCheckInVerdict(
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
     * Shared tail of every successful check-in: daily limit, first-use
     * activation, status transition, VALID result.
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
     * @param TicketRow|false $ticket
     */
    private function resolveCheckOutVerdict(
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
     * DAL write — shares the connection, so it stays inside the FOR UPDATE
     * transaction scope opened in scan().
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

    private function logAttempt(
        Context $context,
        TicketScanResult $result,
        ?string $ticketIdBytes,
        string $tokenHash,
        ?string $scannedBy,
        string $source,
        ?string $gate,
    ): void {
        // DAL write — shares the connection, so it stays inside the
        // FOR UPDATE transaction scope above. SYSTEM_SCOPE for the same
        // reason as updateTicket(): the audit row is an internal effect of
        // the authorized scan action.
        $entry = [
            'id' => Uuid::randomHex(),
            'ticketId' => $ticketIdBytes !== null ? Uuid::fromBytesToHex($ticketIdBytes) : null,
            'verdict' => $result->verdict,
            'direction' => $result->direction,
            'tokenFingerprint' => substr($tokenHash, 0, 12),
            'scannedBy' => $scannedBy !== null ? mb_substr($scannedBy, 0, 64) : null,
            'source' => mb_substr($source, 0, 32),
            'gate' => $gate !== null ? mb_substr($gate, 0, 64) : null,
        ];

        $context->scope(Context::SYSTEM_SCOPE, function (Context $systemContext) use ($entry): void {
            $this->scanLogRepository->create([$entry], $systemContext);
        });
    }

    private function formatDateTime(DateTimeInterface $dateTime): string
    {
        return UtcDateTime::toStorage($dateTime);
    }
}
