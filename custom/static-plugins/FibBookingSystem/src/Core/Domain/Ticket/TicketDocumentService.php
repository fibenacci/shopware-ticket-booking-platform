<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Ticket;

use FibBookingSystem\Checkout\Document\BookingTicketRenderer;
use Shopware\Core\Checkout\Document\DocumentCollection;
use Shopware\Core\Checkout\Document\Renderer\RenderedDocument;
use Shopware\Core\Checkout\Document\Service\DocumentGenerator;
use Shopware\Core\Checkout\Document\Struct\DocumentGenerateOperation;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * Creates and reads the "Ticket" order document (PDF with QR codes).
 * The document shows up in the administration order detail like any other
 * document type and is attached to the ticket mail.
 */
class TicketDocumentService
{
    /**
     * @param EntityRepository<DocumentCollection> $documentRepository
     */
    public function __construct(
        private readonly DocumentGenerator $documentGenerator,
        private readonly EntityRepository $documentRepository,
    ) {
    }

    /**
     * Generates the ticket document for an order. Re-generates when called
     * again (e.g. a second ticket was issued later) so the newest document
     * always contains every ticket.
     */
    public function generateForOrder(string $orderId, Context $context): ?string
    {
        $operation = new DocumentGenerateOperation($orderId);

        $result = $this->documentGenerator->generate(
            BookingTicketRenderer::TYPE,
            [$orderId => $operation],
            $context,
        );

        return $result->getSuccess()->first()?->getId();
    }

    /**
     * Returns the newest ticket document of the order as rendered PDF —
     * generating it first when none exists yet.
     */
    public function getOrderTicketPdf(string $orderId, Context $context): ?RenderedDocument
    {
        $documentId = $this->findNewestDocumentId($orderId, $context) ?? $this->generateForOrder($orderId, $context);

        if ($documentId === null) {
            return null;
        }

        return $this->documentGenerator->readDocument($documentId, $context);
    }

    private function findNewestDocumentId(string $orderId, Context $context): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderId', $orderId));
        $criteria->addFilter(new EqualsFilter('documentType.technicalName', BookingTicketRenderer::TYPE));
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));
        $criteria->setLimit(1);

        $documentId = $this->documentRepository->searchIds($criteria, $context)->firstId();

        return is_string($documentId) ? $documentId : null;
    }
}
