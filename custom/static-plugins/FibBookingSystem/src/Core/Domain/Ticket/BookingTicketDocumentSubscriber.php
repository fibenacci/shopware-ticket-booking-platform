<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Ticket;

use FibBookingSystem\Core\Content\BookingReservation\BookingReservationCollection;
use FibBookingSystem\Core\Content\BookingReservation\BookingReservationEntity;
use Psr\Log\LoggerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Throwable;

/**
 * Generates the "Ticket" order document as soon as a ticket is issued.
 * Runs BEFORE the mail subscriber (priority) so the mail can attach the PDF.
 */
class BookingTicketDocumentSubscriber implements EventSubscriberInterface
{
    /**
     * @param EntityRepository<BookingReservationCollection> $reservationRepository
     */
    public function __construct(
        private readonly EntityRepository $reservationRepository,
        private readonly TicketDocumentService $ticketDocumentService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BookingTicketIssuedEvent::EVENT_NAME => ['generateDocument', 100],
        ];
    }

    public function generateDocument(BookingTicketIssuedEvent $event): void
    {
        try {
            /** @var BookingReservationEntity|null $reservation */
            $reservation = $this->reservationRepository
                ->search(new Criteria([$event->getReservationId()]), $event->getContext())
                ->first();

            $orderId = $reservation?->getOrderId();

            if ($orderId === null) {
                return;
            }

            $this->ticketDocumentService->generateForOrder($orderId, $event->getContext());
        } catch (Throwable $exception) {
            // Document generation must never break ticket issuing — surface
            // in the logs instead.
            $this->logger->error('Ticket document generation failed for reservation {reservationId}: {message}', [
                'reservationId' => $event->getReservationId(),
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);
        }
    }
}
