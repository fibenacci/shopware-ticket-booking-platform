<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Ticket;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use RuntimeException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class BookingTicketService
{
    public const NUMBER_RANGE_TYPE = 'fib_booking_ticket';

    public function __construct(
        private readonly Connection $connection,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly QrCodeGenerator $qrCodeGenerator,
        private readonly NumberRangeValueGeneratorInterface $numberRangeValueGenerator,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function issueTicket(string $reservationId, Context $context, array $payload = [], ?DateTimeInterface $expiresAt = null): BookingTicket
    {
        return $this->connection->transactional(function () use ($reservationId, $context, $payload, $expiresAt): BookingTicket {
            $reservation = $this->connection->fetchAssociative(
                'SELECT reservation.id, reservation.booking_number, `order`.sales_channel_id
                 FROM fib_booking_reservation reservation
                 LEFT JOIN `order` ON `order`.id = reservation.order_id AND `order`.version_id = reservation.order_version_id
                 WHERE reservation.id = :reservationId
                 FOR UPDATE',
                ['reservationId' => Uuid::fromHexToBytes($reservationId)],
            );

            if ($reservation === false) {
                throw new RuntimeException('The requested booking reservation does not exist.');
            }

            $existingTicket = $this->connection->fetchAssociative(
                'SELECT id, ticket_number FROM fib_booking_ticket WHERE reservation_id = :reservationId AND status IN (:issued, :sent)',
                [
                    'reservationId' => Uuid::fromHexToBytes($reservationId),
                    'issued' => 'issued',
                    'sent' => 'sent',
                ],
            );

            if ($existingTicket !== false) {
                throw new RuntimeException('A valid ticket already exists for this reservation.');
            }

            $ticketId = Uuid::randomHex();
            $ticketNumber = $this->numberRangeValueGenerator->getValue(
                self::NUMBER_RANGE_TYPE,
                $context,
                is_string($reservation['sales_channel_id'] ?? null) ? Uuid::fromBytesToHex($reservation['sales_channel_id']) : null,
            );
            $scanToken = bin2hex(random_bytes(32));
            $qrPayload = $this->createQrPayload($ticketNumber, $scanToken);
            $qrCodeDataUri = $this->qrCodeGenerator->generateDataUri($qrPayload);
            $issuedAt = new DateTimeImmutable();

            $this->connection->insert('fib_booking_ticket', [
                'id' => Uuid::fromHexToBytes($ticketId),
                'reservation_id' => Uuid::fromHexToBytes($reservationId),
                'ticket_number' => $ticketNumber,
                'scan_token_hash' => hash('sha256', $scanToken),
                'status' => 'issued',
                'issued_at' => $this->formatDateTime($issuedAt),
                'expires_at' => $expiresAt ? $this->formatDateTime($expiresAt) : null,
                'payload' => $payload === [] ? null : json_encode($payload, JSON_THROW_ON_ERROR),
                'created_at' => $this->formatDateTime($issuedAt),
            ]);

            $ticket = new BookingTicket($ticketId, $ticketNumber, $scanToken, $qrPayload, $qrCodeDataUri);
            $this->eventDispatcher->dispatch(new BookingTicketIssuedEvent($reservationId, $ticket, $context), BookingTicketIssuedEvent::EVENT_NAME);

            return $ticket;
        });
    }

    public function markSent(string $ticketId): void
    {
        $this->connection->update('fib_booking_ticket', [
            'status' => 'sent',
            'sent_at' => $this->formatDateTime(new DateTimeImmutable()),
            'updated_at' => $this->formatDateTime(new DateTimeImmutable()),
        ], [
            'id' => Uuid::fromHexToBytes($ticketId),
        ]);
    }

    public function markScanned(string $scanToken): bool
    {
        return $this->connection->transactional(function () use ($scanToken): bool {
            $ticket = $this->connection->fetchAssociative(
                'SELECT id, status, expires_at
                 FROM fib_booking_ticket
                 WHERE scan_token_hash = :scanTokenHash
                 FOR UPDATE',
                ['scanTokenHash' => hash('sha256', $scanToken)],
            );

            if ($ticket === false || !in_array($ticket['status'], ['issued', 'sent'], true)) {
                return false;
            }

            if ($ticket['expires_at'] !== null && new DateTimeImmutable((string) $ticket['expires_at']) < new DateTimeImmutable()) {
                return false;
            }

            $now = new DateTimeImmutable();
            $this->connection->update('fib_booking_ticket', [
                'status' => 'scanned',
                'scanned_at' => $this->formatDateTime($now),
                'updated_at' => $this->formatDateTime($now),
            ], [
                'id' => $ticket['id'],
            ]);

            return true;
        });
    }

    private function createQrPayload(string $ticketNumber, string $scanToken): string
    {
        return json_encode([
            'type' => 'fib_booking_ticket',
            'ticketNumber' => $ticketNumber,
            'scanToken' => $scanToken,
        ], JSON_THROW_ON_ERROR);
    }

    private function formatDateTime(DateTimeInterface $dateTime): string
    {
        return $dateTime->format('Y-m-d H:i:s.v');
    }
}
