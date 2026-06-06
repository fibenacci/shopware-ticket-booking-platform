<?php

declare(strict_types=1);

namespace FibBookingSystem\Checkout\Document;

use DateTime;
use DateTimeInterface;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use FibBookingSystem\Core\Content\BookingTicket\BookingTicketCollection;
use FibBookingSystem\Core\Content\BookingTicket\BookingTicketEntity;
use FibBookingSystem\Core\Domain\Security\TokenCipher;
use FibBookingSystem\Core\Domain\Ticket\QrCodeGenerator;
use FibBookingSystem\FibBookingException;
use Shopware\Core\Checkout\Document\Renderer\AbstractDocumentRenderer;
use Shopware\Core\Checkout\Document\Renderer\DocumentRendererConfig;
use Shopware\Core\Checkout\Document\Renderer\OrderDocumentCriteriaFactory;
use Shopware\Core\Checkout\Document\Renderer\RenderedDocument;
use Shopware\Core\Checkout\Document\Renderer\RendererResult;
use Shopware\Core\Checkout\Document\Service\DocumentConfigLoader;
use Shopware\Core\Checkout\Document\Service\DocumentFileRendererRegistry;
use Shopware\Core\Checkout\Document\Struct\DocumentGenerateOperation;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Uuid\Uuid;
use Throwable;

/**
 * Renders the "Ticket" order document: one PDF per order containing every
 * issued booking ticket with its QR code. Appears in the administration
 * order detail like invoices/delivery notes; the document number reuses the
 * ticket number (own number range, T…).
 */
final class BookingTicketRenderer extends AbstractDocumentRenderer
{
    public const TYPE = 'fib_booking_ticket';

    /**
     * @internal
     *
     * @param EntityRepository<OrderCollection>         $orderRepository
     * @param EntityRepository<BookingTicketCollection> $ticketRepository
     */
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $ticketRepository,
        private readonly DocumentConfigLoader $documentConfigLoader,
        private readonly DocumentFileRendererRegistry $fileRendererRegistry,
        private readonly Connection $connection,
        private readonly TokenCipher $tokenCipher,
        private readonly QrCodeGenerator $qrCodeGenerator,
    ) {
    }

    public function supports(): string
    {
        return self::TYPE;
    }

    public function render(array $operations, Context $context, DocumentRendererConfig $rendererConfig): RendererResult
    {
        $result = new RendererResult();

        $ids = \array_map(static fn (DocumentGenerateOperation $operation) => $operation->getOrderId(), $operations);

        if ($ids === []) {
            return $result;
        }

        $languageIdChain = $context->getLanguageIdChain();
        /** @var list<array{language_id: string, ids: string}> $chunk */
        $chunk = $this->getOrdersLanguageId(array_values($ids), $context->getVersionId(), $this->connection);

        foreach ($chunk as ['language_id' => $languageId, 'ids' => $chunkIds]) {
            $criteria = OrderDocumentCriteriaFactory::create(\explode(',', $chunkIds), $rendererConfig->deepLinkCode, self::TYPE);

            $context = $context->assign([
                'languageIdChain' => \array_values(\array_unique(\array_filter([$languageId, ...$languageIdChain]))),
            ]);

            $orders = $this->orderRepository->search($criteria, $context)->getEntities();

            foreach ($orders as $order) {
                $orderId = $order->getId();

                try {
                    if (!\array_key_exists($orderId, $operations)) {
                        continue;
                    }

                    /** @var DocumentGenerateOperation $operation */
                    $operation = $operations[$orderId];

                    $tickets = $this->loadTickets($orderId, $context);

                    if ($tickets === []) {
                        throw FibBookingException::noTicketsForOrder($orderId);
                    }

                    $config = clone $this->documentConfigLoader->load(self::TYPE, $order->getSalesChannelId(), $context);
                    $config->merge($operation->getConfig());

                    // The ticket already carries its own number range (T…) —
                    // the document reuses it instead of introducing a second,
                    // unrelated number.
                    $number = $config->getDocumentNumber() ?: $tickets[0]['number'];

                    $config->merge([
                        'documentDate' => $operation->getConfig()['documentDate'] ?? (new DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                        'documentNumber' => $number,
                        'custom' => [
                            'ticketNumber' => $number,
                        ],
                    ]);

                    // Freeze the order state this document was generated from.
                    $operation->setOrderVersionId($this->orderRepository->createVersion($orderId, $context, 'document'));

                    /** @var array<string, mixed> $documentConfig */
                    $documentConfig = $config->jsonSerialize();

                    $doc = new RenderedDocument(
                        $number,
                        $config->buildName(),
                        $operation->getFileType(),
                        $documentConfig,
                    );

                    $doc->setTemplate('@FibBookingSystem/documents/fib_booking_ticket.html.twig');
                    $doc->setOrder($order);
                    $doc->setContext($context);
                    $doc->setParameters(['tickets' => $tickets]);

                    $doc->setContent($this->fileRendererRegistry->render($doc));

                    $result->addSuccess($orderId, $doc);
                } catch (Throwable $exception) {
                    $result->addError($orderId, $exception);
                }
            }
        }

        return $result;
    }

    public function getDecorated(): AbstractDocumentRenderer
    {
        throw new DecorationPatternException(self::class);
    }

    /**
     * @return list<array{number: string, status: string, issuedAt: ?DateTimeInterface, bookingNumber: ?string, resourceName: ?string, startsAt: ?DateTimeInterface, endsAt: ?DateTimeInterface, qrCodeDataUri: ?string}>
     */
    private function loadTickets(string $orderId, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('reservation.orderId', $orderId));
        $criteria->addFilter(new EqualsAnyFilter('status', ['issued', 'sent', 'scanned']));
        $criteria->addAssociation('reservation.resource');
        // Newest ticket first — it provides the document number/filename.
        $criteria->addSorting(new FieldSorting('ticketNumber', FieldSorting::DESCENDING));

        $tickets = $this->ticketRepository->search($criteria, $context)->getEntities();

        if ($tickets->count() === 0) {
            return [];
        }

        $ciphers = $this->fetchScanTokenCiphers($tickets->getIds());

        $data = [];

        /** @var BookingTicketEntity $ticket */
        foreach ($tickets as $ticket) {
            $reservation = $ticket->getReservation();

            $data[] = [
                'number' => $ticket->getTicketNumber(),
                'status' => $ticket->getStatus(),
                'issuedAt' => $ticket->getIssuedAt(),
                'bookingNumber' => $reservation?->getBookingNumber(),
                'resourceName' => $reservation?->getResource()?->getName(),
                'startsAt' => $reservation?->getStartsAt(),
                'endsAt' => $reservation?->getEndsAt(),
                'qrCodeDataUri' => $this->buildQrCodeDataUri($ticket, $ciphers[$ticket->getId()] ?? null),
            ];
        }

        return $data;
    }

    /**
     * Deliberate raw SQL: scan_token_cipher is intentionally NOT part of the
     * DAL definition so the secret never leaks through the Admin API (see
     * plugin Security.md). Same pattern as WalletPassService.
     *
     * @param array<string> $ticketIds
     *
     * @return array<string, string> ticket id (hex) => cipher
     */
    private function fetchScanTokenCiphers(array $ticketIds): array
    {
        /** @var array<string, string> $rows */
        $rows = $this->connection->fetchAllKeyValue(
            'SELECT LOWER(HEX(id)), scan_token_cipher FROM fib_booking_ticket WHERE id IN (:ids)',
            ['ids' => Uuid::fromHexToBytesList($ticketIds)],
            ['ids' => ArrayParameterType::BINARY],
        );

        return $rows;
    }

    private function buildQrCodeDataUri(BookingTicketEntity $ticket, ?string $cipher): ?string
    {
        if ($cipher === null || $cipher === '') {
            return null;
        }

        $scanToken = $this->tokenCipher->decrypt($cipher);

        $qrPayload = json_encode([
            'type' => 'fib_booking_ticket',
            'ticketNumber' => $ticket->getTicketNumber(),
            'scanToken' => $scanToken,
        ], \JSON_THROW_ON_ERROR);

        return $this->qrCodeGenerator->generateDataUri($qrPayload);
    }
}
