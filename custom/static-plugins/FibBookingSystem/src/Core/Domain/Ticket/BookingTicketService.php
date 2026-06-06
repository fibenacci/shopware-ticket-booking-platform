<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Ticket;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use FibBookingSystem\Core\Domain\Security\TokenCipher;
use FibBookingSystem\FibBookingException;
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
        private readonly TokenCipher $tokenCipher,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function issueTicket(string $reservationId, Context $context, array $payload = [], ?DateTimeInterface $expiresAt = null): BookingTicket
    {
        return $this->connection->transactional(function () use ($reservationId, $context, $payload, $expiresAt): BookingTicket {
            $reservation = $this->connection->fetchAssociative(
                <<<'SQL'
                    SELECT reservation.id, reservation.booking_number, `order`.sales_channel_id
                    FROM fib_booking_reservation reservation
                    LEFT JOIN `order` ON `order`.id = reservation.order_id AND `order`.version_id = reservation.order_version_id
                    WHERE reservation.id = :reservationId
                    FOR UPDATE
                SQL,
                ['reservationId' => Uuid::fromHexToBytes($reservationId)],
            );

            if ($reservation === false) {
                throw FibBookingException::reservationNotFound($reservationId);
            }

            $existingTicket = $this->connection->fetchAssociative(
                <<<'SQL'
                    SELECT id, ticket_number FROM fib_booking_ticket WHERE reservation_id = :reservationId AND status IN (:issued, :sent)
                SQL,
                [
                    'reservationId' => Uuid::fromHexToBytes($reservationId),
                    'issued' => 'issued',
                    'sent' => 'sent',
                ],
            );

            if ($existingTicket !== false) {
                throw FibBookingException::ticketAlreadyExists($reservationId);
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
                'scan_token_cipher' => $this->tokenCipher->encrypt($scanToken),
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
