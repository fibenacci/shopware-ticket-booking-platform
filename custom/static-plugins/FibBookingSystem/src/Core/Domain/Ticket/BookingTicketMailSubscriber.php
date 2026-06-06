<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Ticket;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use FibBookingSystem\Core\Domain\Wallet\WalletPassService;
use Shopware\Core\Content\Mail\Service\AbstractMailService;
use Shopware\Core\Content\MailTemplate\MailTemplateCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class BookingTicketMailSubscriber implements EventSubscriberInterface
{
    private const MAIL_TEMPLATE_TYPE = 'fib_booking_ticket_mail';

    /**
     * @param EntityRepository<MailTemplateCollection> $mailTemplateRepository
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly AbstractMailService $mailService,
        private readonly EntityRepository $mailTemplateRepository,
        private readonly WalletPassService $walletService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BookingTicketIssuedEvent::EVENT_NAME => 'sendTicketMail',
        ];
    }

    public function sendTicketMail(BookingTicketIssuedEvent $event): void
    {
        $context = $event->getContext();
        $reservation = $this->fetchReservationMailData($event->getReservationId());

        if ($reservation === null || !is_string($reservation['email']) || $reservation['email'] === '') {
            return;
        }

        $templateId = $this->fetchTemplateId($context);

        if ($templateId === null) {
            return;
        }

        $ticket = $event->getTicket();

        $data = [
            'recipients' => [
                $reservation['email'] => trim(sprintf('%s %s', (string) $reservation['first_name'], (string) $reservation['last_name'])),
            ],
            'salesChannelId' => $reservation['sales_channel_id'] ? Uuid::fromBytesToHex($reservation['sales_channel_id']) : null,
            'templateId' => $templateId,
        ];

        $templateData = [
            'booking' => [
                'number' => $reservation['booking_number'],
                'startsAt' => $reservation['starts_at'],
                'endsAt' => $reservation['ends_at'],
            ],
            'ticket' => [
                'number' => $ticket->getTicketNumber(),
                'qrPayload' => $ticket->getQrPayload(),
                'qrCodeDataUri' => $ticket->getQrCodeDataUri(),
            ],
            'wallet' => $this->buildWalletTemplateData(
                $ticket->getId(),
                $reservation['sales_channel_id'] ?? null,
            ),
        ];

        $this->mailService->send($data, $context, $templateData);

        $this->connection->update('fib_booking_ticket', [
            'status' => 'sent',
            'sent_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s.v'),
            'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s.v'),
        ], [
            'id' => Uuid::fromHexToBytes($ticket->getId()),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchReservationMailData(string $reservationId): ?array
    {
        $data = $this->connection->fetchAssociative(
            'SELECT reservation.booking_number,
                    reservation.starts_at,
                    reservation.ends_at,
                    customer.email,
                    customer.first_name,
                    customer.last_name,
                    customer.sales_channel_id
             FROM fib_booking_reservation reservation
             LEFT JOIN customer customer ON customer.id = reservation.customer_id
             WHERE reservation.id = :reservationId',
            ['reservationId' => Uuid::fromHexToBytes($reservationId)],
        );

        return $data === false ? null : $data;
    }

    /**
     * Builds absolute, signed wallet URLs for the ticket mail. Returns null
     * values for providers that are not configured — the mail template can
     * simply check for presence.
     *
     * @return array{appleUrl: string|null, googleUrl: string|null, accountUrl: string|null}
     */
    private function buildWalletTemplateData(string $ticketId, mixed $salesChannelIdBytes): array
    {
        $baseUrl = is_string($salesChannelIdBytes) ? $this->fetchSalesChannelBaseUrl($salesChannelIdBytes) : null;

        if ($baseUrl === null) {
            return ['appleUrl' => null, 'googleUrl' => null, 'accountUrl' => null];
        }

        $appleUrl = null;
        if ($this->walletService->isAppleAvailable()) {
            $params = $this->walletService->createSignedParams(WalletPassService::PROVIDER_APPLE, $ticketId);
            $appleUrl = sprintf('%s/fib-booking/wallet/%s/apple.pkpass?exp=%d&sig=%s', $baseUrl, $ticketId, $params['exp'], $params['sig']);
        }

        $googleUrl = null;
        if ($this->walletService->isGoogleAvailable()) {
            $params = $this->walletService->createSignedParams(WalletPassService::PROVIDER_GOOGLE, $ticketId);
            $googleUrl = sprintf('%s/fib-booking/wallet/%s/google?exp=%d&sig=%s', $baseUrl, $ticketId, $params['exp'], $params['sig']);
        }

        return [
            'appleUrl' => $appleUrl,
            'googleUrl' => $googleUrl,
            'accountUrl' => $baseUrl . '/account/fib-booking/tickets',
        ];
    }

    private function fetchSalesChannelBaseUrl(string $salesChannelIdBytes): ?string
    {
        $url = $this->connection->fetchOne(
            "SELECT url FROM sales_channel_domain
             WHERE sales_channel_id = :salesChannelId AND url LIKE 'http%'
             ORDER BY url LIKE 'https%' DESC
             LIMIT 1",
            ['salesChannelId' => $salesChannelIdBytes],
        );

        return is_string($url) && $url !== '' ? rtrim($url, '/') : null;
    }

    private function fetchTemplateId(Context $context): ?string
    {
        $criteria = (new Criteria())
            ->addAssociation('mailTemplateType')
            ->addFilter(new EqualsFilter('mailTemplateType.technicalName', self::MAIL_TEMPLATE_TYPE))
            ->setLimit(1);

        $templateId = $this->mailTemplateRepository->searchIds($criteria, $context)->firstId();

        return is_string($templateId) ? $templateId : null;
    }
}
