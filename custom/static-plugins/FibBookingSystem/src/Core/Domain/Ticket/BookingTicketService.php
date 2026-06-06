<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Ticket;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Exception;
use FibBookingSystem\Core\Content\BookingTicket\BookingTicketCollection;
use FibBookingSystem\Core\Domain\Security\TokenCipher;
use FibBookingSystem\Core\Domain\Validity\TicketValidity;
use FibBookingSystem\Core\Domain\Validity\TicketValidityResolver;
use FibBookingSystem\FibBookingException;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class BookingTicketService
{
    public const NUMBER_RANGE_TYPE = 'fib_booking_ticket';

    /**
     * The DBAL connection is used for two things only: the surrounding
     * transaction with its pessimistic `SELECT … FOR UPDATE` lock, and the
     * ticket INSERT — scan_token_cipher is deliberately NOT part of the DAL
     * definition (see plugin Security.md), so the insert cannot go through the
     * DAL and stays raw. Status transitions go through the DAL.
     *
     * @param EntityRepository<BookingTicketCollection> $ticketRepository
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityRepository $ticketRepository,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly QrCodeGenerator $qrCodeGenerator,
        private readonly NumberRangeValueGeneratorInterface $numberRangeValueGenerator,
        private readonly TokenCipher $tokenCipher,
        private readonly TicketValidityResolver $validityResolver,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function issueTicket(string $reservationId, Context $context, array $payload = [], ?DateTimeInterface $expiresAt = null): BookingTicket
    {
        $ticket = $this->connection->transactional(function () use ($reservationId, $context, $payload, $expiresAt): BookingTicket {
            $reservation = $this->connection->fetchAssociative(
                <<<'SQL'
                    SELECT reservation.id, reservation.booking_number, reservation.payload, `order`.sales_channel_id,
                    config.validity_mode, config.validity_duration, config.validity_anchor,
                    config.entry_policy, config.max_entries_per_day
                    FROM fib_booking_reservation reservation
                    LEFT JOIN `order` ON `order`.id = reservation.order_id AND `order`.version_id = reservation.order_version_id
                    LEFT JOIN order_line_item line_item
                    ON line_item.id = reservation.order_line_item_id AND line_item.version_id = reservation.order_line_item_version_id
                    LEFT JOIN fib_booking_product_config config
                    ON config.product_id = line_item.product_id AND config.product_version_id = line_item.product_version_id
                    WHERE reservation.id = :reservationId
                    FOR UPDATE
                SQL,
                ['reservationId' => Uuid::fromHexToBytes($reservationId)],
            );

            if ($reservation === false) {
                throw FibBookingException::reservationNotFound($reservationId);
            }

            $validity = $this->resolveValidity($reservation);

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

            // Deliberate raw INSERT: scan_token_cipher is intentionally NOT
            // part of the DAL definition (see plugin Security.md), so the
            // ticket row cannot be written through the DAL. Runs on the same
            // connection, hence inside the FOR UPDATE transaction scope.
            $this->connection->insert('fib_booking_ticket', [
                'id' => Uuid::fromHexToBytes($ticketId),
                'reservation_id' => Uuid::fromHexToBytes($reservationId),
                'ticket_number' => $ticketNumber,
                'scan_token_hash' => hash('sha256', $scanToken),
                'scan_token_cipher' => $this->tokenCipher->encrypt($scanToken),
                'status' => 'issued',
                'issued_at' => $this->formatDateTime($issuedAt),
                // An explicit expiry (caller knows best, e.g. slot end) wins
                // over the validity model's computed window.
                'expires_at' => $expiresAt ? $this->formatDateTime($expiresAt) : ($validity->expiresAt ? $this->formatDateTime($validity->expiresAt) : null),
                'valid_from' => $validity->validFrom ? $this->formatDateTime($validity->validFrom) : null,
                'entry_policy' => $validity->entryPolicy,
                'max_entries_per_day' => $validity->maxEntriesPerDay,
                'validity_anchor' => $validity->anchor,
                'validity_duration' => $validity->duration,
                'payload' => $payload === [] ? null : json_encode($payload, JSON_THROW_ON_ERROR),
                'created_at' => $this->formatDateTime($issuedAt),
            ]);

            return new BookingTicket($ticketId, $ticketNumber, $scanToken, $qrPayload, $qrCodeDataUri);
        });

        // Side effects (mail, flows, webhooks) only AFTER the ticket is
        // committed — a failing listener must never roll back the ticket.
        $this->eventDispatcher->dispatch(new BookingTicketIssuedEvent($reservationId, $ticket, $context), BookingTicketIssuedEvent::EVENT_NAME);

        return $ticket;
    }

    public function markSent(string $ticketId, Context $context): void
    {
        $this->ticketRepository->update([
            [
                'id' => $ticketId,
                'status' => 'sent',
                'sentAt' => (new DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ],
        ], $context);
    }

    /**
     * Resolves the validity snapshot from the product's booking config (joined
     * into the reservation row). Reservations without an order line item or
     * without a config keep the legacy behavior (slot validity, single entry).
     *
     * @param array<string, mixed> $reservation
     */
    private function resolveValidity(array $reservation): TicketValidity
    {
        $mode = $reservation['validity_mode'] ?? null;

        if (!is_string($mode) || $mode === '') {
            return $this->validityResolver->resolve(null);
        }

        $config = [
            'validity_mode' => $mode,
            'validity_duration' => is_string($reservation['validity_duration'] ?? null) ? $reservation['validity_duration'] : null,
            'validity_anchor' => is_string($reservation['validity_anchor'] ?? null) ? $reservation['validity_anchor'] : null,
            'entry_policy' => is_string($reservation['entry_policy'] ?? null) ? $reservation['entry_policy'] : null,
            'max_entries_per_day' => is_numeric($reservation['max_entries_per_day'] ?? null) ? (int) $reservation['max_entries_per_day'] : null,
        ];

        return $this->validityResolver->resolve($config, $this->extractCustomerStart($reservation));
    }

    /**
     * Customer-chosen start date (the `customer` anchor), carried in the
     * reservation payload as `validityStart` (ISO 8601 / Y-m-d).
     *
     * @param array<string, mixed> $reservation
     */
    private function extractCustomerStart(array $reservation): ?DateTimeImmutable
    {
        $raw = $reservation['payload'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $payload = json_decode($raw, true);
        $start = is_array($payload) ? ($payload['validityStart'] ?? null) : null;

        if (!is_string($start) || $start === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($start);
        } catch (Exception) {
            throw FibBookingException::invalidPayload('validityStart', sprintf('"%s" is not a valid date', $start));
        }
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
