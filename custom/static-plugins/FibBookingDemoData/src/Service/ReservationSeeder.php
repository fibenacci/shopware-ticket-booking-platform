<?php

declare(strict_types=1);

namespace FibBookingDemoData\Service;

use DateInterval;
use DateTimeImmutable;
use FibBookingSystem\Core\Domain\Ticket\BookingTicketService;
use FibBookingSystem\FibBookingException;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

/**
 * Seeds a confirmed demo reservation and issues a QR ticket for it through
 * the regular ticket service — the demo ticket behaves exactly like a real
 * purchase (scan token, wallet passes, scan lifecycle).
 */
class ReservationSeeder
{
    /**
     * @param EntityRepository<\FibBookingSystem\Core\Content\BookingReservation\BookingReservationCollection> $reservationRepository
     */
    public function __construct(
        private readonly EntityRepository $reservationRepository,
        private readonly BookingTicketService $ticketService,
    ) {
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    public function seedReservationWithTicket(
        SeedSection $reservation,
        string $resourceId,
        Context $context,
    ): array {
        $bookingNumber = $reservation->string('bookingNumber');

        $existingReservationId = $this->reservationRepository->searchIds(
            (new Criteria())->addFilter(new EqualsFilter('bookingNumber', $bookingNumber)),
            $context,
        )->firstId();
        $reservationId = is_string($existingReservationId)
            ? $existingReservationId
            : SeedIds::stable('reservation:' . $bookingNumber);

        [$hour, $minute] = array_map(intval(...), explode(':', $reservation->string('startTime', '18:00')));
        $startsAt = (new DateTimeImmutable('today'))
            ->add(new DateInterval('P' . max(1, $reservation->int('startsAtOffsetDays', 1)) . 'D'))
            ->setTime($hour, $minute);
        $endsAt = $startsAt->add(new DateInterval(sprintf('PT%dM', max(5, $reservation->int('durationMinutes', 120)))));

        $this->reservationRepository->upsert([
            [
                'id' => $reservationId,
                'resourceId' => $resourceId,
                'bookingNumber' => $bookingNumber,
                'startsAt' => $startsAt->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                'endsAt' => $endsAt->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                'quantity' => $reservation->int('quantity', 1),
                'status' => 'confirmed',
                'payload' => ['demo' => true],
            ],
        ], $context);

        try {
            $ticket = $this->ticketService->issueTicket($reservationId, $context, ['demo' => true]);

            return [$reservationId, $ticket->getTicketNumber()];
        } catch (FibBookingException) {
            // Re-run: a valid ticket already exists for the demo reservation.
            return [$reservationId, null];
        }
    }
}
