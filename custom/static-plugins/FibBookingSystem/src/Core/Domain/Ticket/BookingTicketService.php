<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Ticket;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;
use Exception;
use FibBookingSystem\Core\Content\BookingTicket\BookingTicketCollection;
use FibBookingSystem\Core\Domain\Security\TokenCipher;
use FibBookingSystem\Core\Domain\Time\UtcDateTime;
use FibBookingSystem\Core\Domain\Validity\TicketValidity;
use FibBookingSystem\Core\Domain\Validity\TicketValidityResolver;
use FibBookingSystem\Core\Domain\Validity\ValidityMode;
use FibBookingSystem\FibBookingException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\NumberRange\ValueGenerator\NumberRangeValueGeneratorInterface;
use Shopware\Core\System\SystemConfig\SystemConfigService;
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
        private readonly SystemConfigService $systemConfig,
        private readonly RotatingCodeService $rotatingCodeService,
    ) {
    }

    /**
     * Convenience for single-ticket flows — see issueTickets() for the
     * seatmap case (one ticket PER SEAT).
     *
     * @param array<string, mixed> $payload
     */
    public function issueTicket(
        string $reservationId,
        Context $context,
        array $payload = [],
        ?DateTimeInterface $expiresAt = null,
    ): BookingTicket {
        return $this->issueTickets($reservationId, $context, $payload, $expiresAt)[0];
    }

    /**
     * Issues the reservation's tickets: ONE per claimed seat for seatmap
     * resources (each carrying its seat-label snapshot, see
     * docs/SEATING_PLAN.md), a single ticket for pool reservations.
     *
     * @param array<string, mixed> $payload
     *
     * @return non-empty-list<BookingTicket>
     */
    public function issueTickets(
        string $reservationId,
        Context $context,
        array $payload = [],
        ?DateTimeInterface $expiresAt = null,
    ): array {
        $tickets = $this->connection->transactional(function () use ($reservationId, $context, $payload, $expiresAt): array {
            $reservation = $this->connection->fetchAssociative(
                <<<'SQL'
                    SELECT reservation.id, reservation.booking_number, reservation.payload,
                    reservation.starts_at, reservation.ends_at, `order`.sales_channel_id,
                    config.validity_mode, config.validity_duration, config.validity_anchor,
                    config.entry_policy, config.max_entries_per_day,
                    config.rotating_qr_enabled, config.rotating_qr_interval,
                    config.calendar_invite_enabled
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
            $slotWindow = $this->resolveSlotWindow($reservation);

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

            // Seatmap reservations carry their claimed seats; pool
            // reservations get exactly one (seat-less) ticket.
            $seatLabels = $this->fetchClaimedSeatLabels($reservationId);
            $issuedAt = UtcDateTime::now();

            $tickets = [];
            foreach ($seatLabels === [] ? [null] : $seatLabels as $seatLabel) {
                $tickets[] = $this->insertTicket($reservationId, $reservation, $validity, $slotWindow, $payload, $expiresAt, $issuedAt, $seatLabel, $context);
            }

            return $tickets;
        });

        // Side effects (mail, flows, webhooks) only AFTER the tickets are
        // committed — a failing listener must never roll back a ticket.
        foreach ($tickets as $ticket) {
            $this->eventDispatcher->dispatch(new BookingTicketIssuedEvent($reservationId, $ticket, $context), BookingTicketIssuedEvent::EVENT_NAME);
        }

        return $tickets;
    }

    /**
     * @param array{validFrom: DateTimeImmutable, expiresAt: DateTimeImmutable}|null $slotWindow
     * @param array<string, mixed>                                                   $reservation
     * @param array<string, mixed>                                                   $payload
     */
    private function insertTicket(
        string $reservationId,
        array $reservation,
        TicketValidity $validity,
        ?array $slotWindow,
        array $payload,
        ?DateTimeInterface $expiresAt,
        DateTimeImmutable $issuedAt,
        ?string $seatLabel,
        Context $context,
    ): BookingTicket {
        $ticketId = Uuid::randomHex();
        $ticketNumber = $this->numberRangeValueGenerator->getValue(
            self::NUMBER_RANGE_TYPE,
            $context,
            is_string($reservation['sales_channel_id'] ?? null) ? Uuid::fromBytesToHex($reservation['sales_channel_id']) : null,
        );
        $scanToken = bin2hex(random_bytes(32));

        // Rotating-QR snapshot: a rotating ticket's QR carries a time-based
        // code (built on demand from this scan token), so its issue-time QR
        // would be stale by the time the holder opens it — emit no static
        // image, the account page renders the live one.
        $rotatingEnabled = (bool) ($reservation['rotating_qr_enabled'] ?? false);
        $rotatingInterval = $rotatingEnabled
            ? $this->rotatingCodeService->normalizeInterval(is_numeric($reservation['rotating_qr_interval'] ?? null) ? (int) $reservation['rotating_qr_interval'] : null)
            : null;

        // Calendar invite is opt-out per product (default on); a missing
        // config row (LEFT JOIN null) means default-enabled.
        $calendarInviteEnabled = (bool) ($reservation['calendar_invite_enabled'] ?? true);

        $qrPayload = $this->createQrPayload($ticketNumber, $scanToken);
        $qrCodeDataUri = $rotatingEnabled ? '' : $this->qrCodeGenerator->generateDataUri($qrPayload);

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
            // Precedence: explicit caller expiry > validity model window >
            // slot window. Slot-bound tickets default to their Termin —
            // a ticket for tomorrow's slot must not scan VALID today.
            'expires_at' => $this->resolveExpiresAt($expiresAt, $validity, $slotWindow),
            'valid_from' => $this->resolveValidFrom($validity, $slotWindow),
            'entry_policy' => $validity->entryPolicy,
            'max_entries_per_day' => $validity->maxEntriesPerDay,
            'validity_anchor' => $validity->anchor,
            'validity_duration' => $validity->duration,
            'seat_label' => $seatLabel,
            'rotating_qr_enabled' => $rotatingEnabled ? 1 : 0,
            'rotating_qr_interval' => $rotatingInterval,
            'calendar_invite_enabled' => $calendarInviteEnabled ? 1 : 0,
            'payload' => $payload === [] ? null : json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => $this->formatDateTime($issuedAt),
        ]);

        return new BookingTicket($ticketId, $ticketNumber, $scanToken, $qrPayload, $qrCodeDataUri, $seatLabel, $calendarInviteEnabled);
    }

    /**
     * Seat labels ("F7") of the claims bound to this reservation — same
     * connection, hence inside the FOR UPDATE scope of issueTickets().
     *
     * @return list<string>
     */
    private function fetchClaimedSeatLabels(string $reservationId): array
    {
        /** @var list<string> $labels */
        $labels = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT CONCAT(seat.row_label, seat.seat_label)
                FROM fib_booking_seat_claim claim
                INNER JOIN fib_booking_seat seat ON seat.id = claim.seat_id
                WHERE claim.reservation_id = :reservationId
                ORDER BY seat.row_label, CAST(seat.seat_label AS UNSIGNED), seat.seat_label
            SQL,
            ['reservationId' => Uuid::fromHexToBytes($reservationId)],
        );

        return $labels;
    }

    /**
     * Revokes every still-usable ticket of the given reservations — the
     * cancellation/refund tail: a refunded order must never hold a scannable
     * ticket. Final states (revoked/expired) are left untouched, `scanned`
     * and `checked_out` ARE revoked: the past visit already happened, but a
     * multi-entry pass must not grant further entries after the refund.
     *
     * SYSTEM_SCOPE: revocation is an internal effect of an authorized order
     * state change — the acting admin user needs order privileges, not
     * booking-entity write ACLs.
     *
     * @param list<string> $reservationIds
     *
     * @return int number of tickets revoked
     */
    public function revokeForReservations(
        array $reservationIds,
        Context $context,
    ): int {
        if ($reservationIds === []) {
            return 0;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('reservationId', $reservationIds));
        $criteria->addFilter(new EqualsAnyFilter('status', ['issued', 'sent', 'scanned', 'checked_out']));

        /** @var array<string> $ticketIds */
        $ticketIds = $this->ticketRepository->searchIds($criteria, $context)->getIds();

        if ($ticketIds === []) {
            return 0;
        }

        // Bulk payload: one write operation for all tickets.
        $payload = array_map(
            static fn (string $id): array => ['id' => $id, 'status' => 'revoked'],
            $ticketIds,
        );

        $context->scope(Context::SYSTEM_SCOPE, function (Context $systemContext) use ($payload): void {
            $this->ticketRepository->update($payload, $systemContext);
        });

        return count($ticketIds);
    }

    public function markSent(
        string $ticketId,
        Context $context,
    ): void {
        $this->ticketRepository->update([
            [
                'id' => $ticketId,
                'status' => 'sent',
                'sentAt' => UtcDateTime::now(),
            ],
        ], $context);
    }

    /**
     * The scan window of a slot-bound ticket (validity mode `slot` or no
     * product config): valid from `scanEarlyEntryMinutes` before the booked
     * Termin (default 60 — gates usually open before the slot starts) until
     * its end. Period/unlimited tickets carry their own window from the
     * validity model and return null here.
     *
     * @param array<string, mixed> $reservation
     *
     * @return array{validFrom: DateTimeImmutable, expiresAt: DateTimeImmutable}|null
     */
    private function resolveSlotWindow(array $reservation): ?array
    {
        $mode = $reservation['validity_mode'] ?? null;

        if (is_string($mode) && $mode !== '' && $mode !== ValidityMode::SLOT) {
            return null;
        }

        $startsAt = $reservation['starts_at'] ?? null;
        $endsAt = $reservation['ends_at'] ?? null;

        if (!is_string($startsAt) || !is_string($endsAt)) {
            return null;
        }

        // Unset config falls back to 60; an explicit 0 ("no early entry")
        // is respected — that distinction is why getInt() is not used here.
        $raw = $this->systemConfig->get('FibBookingSystem.config.scanEarlyEntryMinutes');
        $earlyEntryMinutes = is_numeric($raw) ? max(0, (int) $raw) : 60;

        return [
            'validFrom' => UtcDateTime::parse($startsAt)->sub(new DateInterval(sprintf('PT%dM', $earlyEntryMinutes))),
            'expiresAt' => UtcDateTime::parse($endsAt),
        ];
    }

    /**
     * @param array{validFrom: DateTimeImmutable, expiresAt: DateTimeImmutable}|null $slotWindow
     */
    private function resolveExpiresAt(
        ?DateTimeInterface $expiresAt,
        TicketValidity $validity,
        ?array $slotWindow,
    ): ?string {
        $resolved = $expiresAt ?? $validity->expiresAt ?? $slotWindow['expiresAt'] ?? null;

        return $resolved !== null ? $this->formatDateTime($resolved) : null;
    }

    /**
     * @param array{validFrom: DateTimeImmutable, expiresAt: DateTimeImmutable}|null $slotWindow
     */
    private function resolveValidFrom(
        TicketValidity $validity,
        ?array $slotWindow,
    ): ?string {
        $resolved = $validity->validFrom ?? $slotWindow['validFrom'] ?? null;

        return $resolved !== null ? $this->formatDateTime($resolved) : null;
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
            return UtcDateTime::parse($start);
        } catch (Exception) {
            throw FibBookingException::invalidPayload('validityStart', sprintf('"%s" is not a valid date', $start));
        }
    }

    private function createQrPayload(
        string $ticketNumber,
        string $scanToken,
    ): string {
        return json_encode([
            'type' => 'fib_booking_ticket',
            'ticketNumber' => $ticketNumber,
            'scanToken' => $scanToken,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Raw INSERT values are UTC wall time (DATETIME(3)) — normalize before
     * formatting; the raw path has no DAL serializer to do it.
     */
    private function formatDateTime(DateTimeInterface $dateTime): string
    {
        return UtcDateTime::toStorage($dateTime);
    }
}
