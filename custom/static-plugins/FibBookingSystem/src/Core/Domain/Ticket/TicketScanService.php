<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Ticket;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Validates scan tokens and transitions tickets to "scanned" — race-safe via
 * SELECT ... FOR UPDATE, so two simultaneous scans of the same ticket resolve
 * deterministically into one VALID and one ALREADY_SCANNED verdict.
 *
 * Every attempt is written to fib_booking_scan_log. The token itself is never
 * persisted — only a 12-char fingerprint of its sha256 hash for correlation.
 */
class TicketScanService
{
    private const SCANNABLE_STATUSES = ['issued', 'sent'];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function scan(string $scanToken, ?string $scannedBy = null, string $source = 'api'): TicketScanResult
    {
        $tokenHash = hash('sha256', $scanToken);

        return $this->connection->transactional(function () use ($tokenHash, $scannedBy, $source): TicketScanResult {
            $ticket = $this->connection->fetchAssociative(
                <<<'SQL'
                    SELECT ticket.id, ticket.ticket_number, ticket.status, ticket.expires_at, ticket.scanned_at,
                    reservation.booking_number
                    FROM fib_booking_ticket ticket
                    LEFT JOIN fib_booking_reservation reservation ON reservation.id = ticket.reservation_id
                    WHERE ticket.scan_token_hash = :tokenHash
                    FOR UPDATE
                SQL,
                ['tokenHash' => $tokenHash],
            );

            $result = $this->resolveVerdict($ticket);
            $this->logAttempt($result, $ticket === false ? null : (string) $ticket['id'], $tokenHash, $scannedBy, $source);

            return $result;
        });
    }

    /**
     * @param array<string, mixed>|false $ticket
     */
    private function resolveVerdict(array|false $ticket): TicketScanResult
    {
        if ($ticket === false) {
            return new TicketScanResult(TicketScanResult::NOT_FOUND);
        }

        $ticketNumber = (string) $ticket['ticket_number'];
        $bookingNumber = isset($ticket['booking_number']) ? (string) $ticket['booking_number'] : null;
        $status = (string) $ticket['status'];

        if ($status === 'scanned') {
            return new TicketScanResult(TicketScanResult::ALREADY_SCANNED, $ticketNumber, $bookingNumber, (string) $ticket['scanned_at']);
        }

        if ($status === 'revoked') {
            return new TicketScanResult(TicketScanResult::REVOKED, $ticketNumber, $bookingNumber);
        }

        $isExpired = $ticket['expires_at'] !== null
            && new DateTimeImmutable((string) $ticket['expires_at']) < new DateTimeImmutable();

        if ($status === 'expired' || $isExpired) {
            if ($status !== 'expired') {
                $this->updateTicketStatus((string) $ticket['id'], 'expired');
            }

            return new TicketScanResult(TicketScanResult::EXPIRED, $ticketNumber, $bookingNumber);
        }

        if (!in_array($status, self::SCANNABLE_STATUSES, true)) {
            return new TicketScanResult(TicketScanResult::NOT_FOUND);
        }

        $scannedAt = new DateTimeImmutable();
        $this->updateTicketStatus((string) $ticket['id'], 'scanned', $scannedAt);

        return new TicketScanResult(TicketScanResult::VALID, $ticketNumber, $bookingNumber, $this->formatDateTime($scannedAt));
    }

    private function updateTicketStatus(string $ticketIdBytes, string $status, ?DateTimeImmutable $scannedAt = null): void
    {
        $now = new DateTimeImmutable();
        $update = [
            'status' => $status,
            'updated_at' => $this->formatDateTime($now),
        ];

        if ($scannedAt !== null) {
            $update['scanned_at'] = $this->formatDateTime($scannedAt);
        }

        $this->connection->update('fib_booking_ticket', $update, ['id' => $ticketIdBytes]);
    }

    private function logAttempt(TicketScanResult $result, ?string $ticketIdBytes, string $tokenHash, ?string $scannedBy, string $source): void
    {
        $this->connection->insert('fib_booking_scan_log', [
            'id' => Uuid::randomBytes(),
            'ticket_id' => $ticketIdBytes,
            'verdict' => $result->verdict,
            'token_fingerprint' => substr($tokenHash, 0, 12),
            'scanned_by' => $scannedBy !== null ? mb_substr($scannedBy, 0, 64) : null,
            'source' => mb_substr($source, 0, 32),
            'created_at' => $this->formatDateTime(new DateTimeImmutable()),
        ]);
    }

    private function formatDateTime(DateTimeInterface $dateTime): string
    {
        return $dateTime->format('Y-m-d H:i:s.v');
    }
}
