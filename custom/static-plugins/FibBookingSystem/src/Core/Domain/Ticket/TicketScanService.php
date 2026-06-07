<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Ticket;

use Doctrine\DBAL\Connection;
use FibBookingSystem\Core\Content\BookingScanLog\BookingScanLogCollection;
use FibBookingSystem\Core\Domain\Time\UtcDateTime;
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
 * @phpstan-type TicketRow array{id: string, ticket_number: string, status: string, expires_at: string|null, scanned_at: string|null, valid_from: string|null, entry_policy: string, max_entries_per_day: int|string|null, validity_anchor: string|null, validity_duration: string|null, seat_label: string|null, booking_number: string|null, rotating_qr_enabled: int|string|null, rotating_qr_interval: int|string|null, last_rotating_window: int|string|null, scan_token_cipher: string|null}
 */
class TicketScanService
{
    /**
     * The columns every verdict needs — shared by the static-token and the
     * rotating-code lookup so both paths feed the identical verdict
     * pipeline. Includes the rotating snapshot + the single-use window guard
     * and the encrypted scan token (the rotating HMAC key).
     */
    private const TICKET_COLUMNS = <<<'SQL'
        ticket.id, ticket.ticket_number, ticket.status, ticket.expires_at, ticket.scanned_at,
        ticket.valid_from, ticket.entry_policy, ticket.max_entries_per_day,
        ticket.validity_anchor, ticket.validity_duration, ticket.seat_label,
        ticket.rotating_qr_enabled, ticket.rotating_qr_interval, ticket.last_rotating_window,
        ticket.scan_token_cipher, reservation.booking_number
        SQL;

    /**
     * The DBAL connection is used for one thing only: the pessimistic
     * `SELECT … FOR UPDATE` lock that makes concurrent scans of the same
     * ticket resolve deterministically. The verdict + its status writes run
     * through ScanVerdictResolver on the SAME connection, hence inside the
     * lock scope.
     *
     * @param EntityRepository<BookingScanLogCollection> $scanLogRepository
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityRepository $scanLogRepository,
        private readonly SystemConfigService $systemConfig,
        private readonly RotatingScanVerifier $rotatingScanVerifier,
        private readonly ScanVerdictResolver $verdictResolver,
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
        $columns = self::TICKET_COLUMNS;

        return $this->connection->transactional(function () use ($tokenHash, $columns, $context, $scannedBy, $source, $direction, $gate): TicketScanResult {
            /** @var TicketRow|false $ticket */
            $ticket = $this->connection->fetchAssociative(
                <<<SQL
                    SELECT {$columns}
                    FROM fib_booking_ticket ticket
                    LEFT JOIN fib_booking_reservation reservation ON reservation.id = ticket.reservation_id
                    WHERE ticket.scan_token_hash = :tokenHash
                    FOR UPDATE
                SQL,
                ['tokenHash' => $tokenHash],
            );

            // A rotating ticket must NOT be redeemable via its static token —
            // the whole point is that only the time-based code is presented.
            if (is_array($ticket) && (bool) $ticket['rotating_qr_enabled']) {
                $result = new TicketScanResult(TicketScanResult::INVALID_CODE, $ticket['ticket_number'], $ticket['booking_number'], direction: $direction);
                $this->logAttempt($context, $result, $ticket['id'], $tokenHash, $scannedBy, $source, $gate);

                return $result;
            }

            $result = $direction === ScanDirection::CHECK_OUT
                ? $this->verdictResolver->checkOut($ticket, $context)
                : $this->verdictResolver->checkIn($ticket, $context);
            $this->logAttempt($context, $result, $ticket === false ? null : $ticket['id'], $tokenHash, $scannedBy, $source, $gate);

            return $result;
        });
    }

    /**
     * Rotating-QR scan: the holder presents a time-based code instead of the
     * static token. Verification + the single-use window guard run inside
     * the same FOR UPDATE lock as the verdict, so two scans of the same
     * rotated code resolve deterministically (one VALID, one INVALID_CODE).
     */
    public function scanRotating(
        string $ticketNumber,
        string $code,
        Context $context,
        ?string $scannedBy = null,
        string $source = 'api',
        string $direction = ScanDirection::CHECK_IN,
        ?string $gate = null,
    ): TicketScanResult {
        if ($direction === ScanDirection::CHECK_OUT && !$this->isCheckOutEnabled()) {
            throw FibBookingException::scanCheckOutDisabled();
        }

        $columns = self::TICKET_COLUMNS;

        return $this->connection->transactional(function () use ($ticketNumber, $code, $columns, $context, $scannedBy, $source, $direction, $gate): TicketScanResult {
            /** @var TicketRow|false $ticket */
            $ticket = $this->connection->fetchAssociative(
                <<<SQL
                    SELECT {$columns}
                    FROM fib_booking_ticket ticket
                    LEFT JOIN fib_booking_reservation reservation ON reservation.id = ticket.reservation_id
                    WHERE ticket.ticket_number = :ticketNumber
                    FOR UPDATE
                SQL,
                ['ticketNumber' => $ticketNumber],
            );

            $result = $this->resolveRotatingVerdict($ticket, $code, $context, $direction);
            $this->logAttempt($context, $result, is_array($ticket) ? $ticket['id'] : null, 'rotating:' . $ticketNumber, $scannedBy, $source, $gate);

            return $result;
        });
    }

    /**
     * @param TicketRow|false $ticket
     */
    private function resolveRotatingVerdict(
        array|false $ticket,
        string $code,
        Context $context,
        string $direction,
    ): TicketScanResult {
        if ($ticket === false) {
            return new TicketScanResult(TicketScanResult::NOT_FOUND, direction: $direction);
        }

        $window = $this->rotatingScanVerifier->matchWindow(
            $code,
            (bool) $ticket['rotating_qr_enabled'],
            $ticket['scan_token_cipher'],
            is_numeric($ticket['rotating_qr_interval'] ?? null) ? (int) $ticket['rotating_qr_interval'] : null,
            is_numeric($ticket['last_rotating_window'] ?? null) ? (int) $ticket['last_rotating_window'] : null,
            UtcDateTime::now()->getTimestamp(),
        );

        // Wrong/expired code, replayed window, non-rotating ticket or an
        // undecryptable token — all collapse to one operator-facing verdict.
        if ($window === null) {
            return new TicketScanResult(TicketScanResult::INVALID_CODE, $ticket['ticket_number'], $ticket['booking_number'], direction: $direction);
        }

        $result = $direction === ScanDirection::CHECK_OUT
            ? $this->verdictResolver->checkOut($ticket, $context)
            : $this->verdictResolver->checkIn($ticket, $context);

        // Burn the window only when the entry actually counted.
        if ($result->isValid()) {
            $this->connection->executeStatement(
                'UPDATE fib_booking_ticket SET last_rotating_window = :window WHERE id = :id',
                ['window' => $window, 'id' => $ticket['id']],
            );
        }

        return $result;
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
}
