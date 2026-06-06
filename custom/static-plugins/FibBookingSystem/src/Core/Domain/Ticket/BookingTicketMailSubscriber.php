<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Ticket;

use DateTimeImmutable;
use FibBookingSystem\Core\Content\BookingReservation\BookingReservationCollection;
use FibBookingSystem\Core\Content\BookingReservation\BookingReservationEntity;
use FibBookingSystem\Core\Content\BookingTicket\BookingTicketCollection;
use FibBookingSystem\Core\Domain\Wallet\WalletPassService;
use Shopware\Core\Content\Mail\Service\AbstractMailService;
use Shopware\Core\Content\MailTemplate\MailTemplateCollection;
use Shopware\Core\Content\MailTemplate\MailTemplateEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainCollection;
use Shopware\Core\System\SalesChannel\Aggregate\SalesChannelDomain\SalesChannelDomainEntity;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class BookingTicketMailSubscriber implements EventSubscriberInterface
{
    private const MAIL_TEMPLATE_TYPE = 'fib_booking_ticket_mail';

    /**
     * @param EntityRepository<BookingReservationCollection> $reservationRepository
     * @param EntityRepository<MailTemplateCollection>       $mailTemplateRepository
     * @param EntityRepository<SalesChannelDomainCollection> $salesChannelDomainRepository
     * @param EntityRepository<BookingTicketCollection>      $ticketRepository
     */
    public function __construct(
        private readonly AbstractMailService $mailService,
        private readonly EntityRepository $reservationRepository,
        private readonly EntityRepository $mailTemplateRepository,
        private readonly EntityRepository $salesChannelDomainRepository,
        private readonly EntityRepository $ticketRepository,
        private readonly WalletPassService $walletService,
        private readonly TicketDocumentService $ticketDocumentService,
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
        $reservation = $this->fetchReservationMailData($event->getReservationId(), $context);

        if ($reservation === null) {
            return;
        }

        $customer = $reservation->getCustomer();
        $email = $customer?->getEmail();

        if ($customer === null || !is_string($email) || $email === '') {
            return;
        }

        $template = $this->fetchTemplate($context);

        if ($template === null) {
            return;
        }

        $ticket = $event->getTicket();
        $salesChannelId = $customer->getSalesChannelId();

        // MailService does NOT resolve template content from a templateId —
        // subject/sender/content must be passed in resolved form (the same
        // contract Shopware's own SendMailAction fulfills).
        $data = [
            'recipients' => [
                $email => trim(sprintf('%s %s', $customer->getFirstName(), $customer->getLastName())),
            ],
            'salesChannelId' => $salesChannelId,
            'templateId' => $template->getId(),
            'subject' => $template->getTranslation('subject'),
            'senderName' => $template->getTranslation('senderName'),
            'contentHtml' => $template->getTranslation('contentHtml'),
            'contentPlain' => $template->getTranslation('contentPlain'),
        ];

        // Attach the ticket PDF (QR codes) — same document that appears in
        // the administration order detail.
        $orderId = $reservation->getOrderId();

        if (is_string($orderId)) {
            $document = $this->ticketDocumentService->getOrderTicketPdf($orderId, $context);

            if ($document !== null) {
                $data['binAttachments'] = [
                    [
                        'content' => $document->getContent(),
                        'fileName' => $document->getName(),
                        'mimeType' => $document->getContentType(),
                    ],
                ];
            }
        }

        $templateData = [
            'booking' => [
                'number' => $reservation->getBookingNumber(),
                'startsAt' => $reservation->getStartsAt(),
                'endsAt' => $reservation->getEndsAt(),
            ],
            'ticket' => [
                'number' => $ticket->getTicketNumber(),
                'qrPayload' => $ticket->getQrPayload(),
                'qrCodeDataUri' => $ticket->getQrCodeDataUri(),
            ],
            'wallet' => $this->buildWalletTemplateData($ticket->getId(), $salesChannelId, $context),
        ];

        $this->mailService->send($data, $context, $templateData);

        $this->ticketRepository->update([
            [
                'id' => $ticket->getId(),
                'status' => 'sent',
                'sentAt' => (new DateTimeImmutable())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
            ],
        ], $context);
    }

    private function fetchReservationMailData(
        string $reservationId,
        Context $context,
    ): ?BookingReservationEntity {
        $criteria = new Criteria([$reservationId]);
        $criteria->addAssociation('customer');

        /** @var BookingReservationEntity|null $reservation */
        $reservation = $this->reservationRepository->search($criteria, $context)->first();

        return $reservation;
    }

    /**
     * Builds absolute, signed wallet URLs for the ticket mail. Returns null
     * values for providers that are not configured — the mail template can
     * simply check for presence.
     *
     * @return array{appleUrl: string|null, googleUrl: string|null, accountUrl: string|null}
     */
    private function buildWalletTemplateData(
        string $ticketId,
        ?string $salesChannelId,
        Context $context,
    ): array {
        $baseUrl = is_string($salesChannelId) ? $this->fetchSalesChannelBaseUrl($salesChannelId, $context) : null;

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

    private function fetchSalesChannelBaseUrl(
        string $salesChannelId,
        Context $context,
    ): ?string {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('salesChannelId', $salesChannelId));

        $domains = $this->salesChannelDomainRepository->search($criteria, $context)->getEntities();

        // ORDER BY url LIKE 'https%' is not expressible in DAL — prefer an
        // https domain, otherwise fall back to the first http one.
        $fallback = null;

        /** @var SalesChannelDomainEntity $domain */
        foreach ($domains as $domain) {
            $url = $domain->getUrl();

            if (str_starts_with($url, 'https')) {
                return rtrim($url, '/');
            }

            if ($fallback === null && str_starts_with($url, 'http')) {
                $fallback = $url;
            }
        }

        return $fallback !== null ? rtrim($fallback, '/') : null;
    }

    private function fetchTemplate(Context $context): ?MailTemplateEntity
    {
        $criteria = (new Criteria())
            ->addAssociation('mailTemplateType')
            ->addFilter(new EqualsFilter('mailTemplateType.technicalName', self::MAIL_TEMPLATE_TYPE))
            ->setLimit(1);

        /** @var MailTemplateEntity|null $template */
        $template = $this->mailTemplateRepository->search($criteria, $context)->first();

        return $template;
    }
}
