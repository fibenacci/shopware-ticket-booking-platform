<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Ticket;

use FibBookingSystem\Checkout\Document\BookingTicketRenderer;
use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Document\DocumentCollection;
use Shopware\Core\Checkout\Document\DocumentException;
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
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Generates the ticket document for an order — idempotent: a second
     * ticket issued later changes the document number (newest ticket) and
     * re-generates; a repeat call for the SAME ticket set trips the core
     * duplicate-number check, which is answered with the existing document
     * instead of a silent null. Every other failure is logged.
     */
    public function generateForOrder(string $orderId, Context $context): ?string
    {
        $operation = new DocumentGenerateOperation($orderId);

        $result = $this->documentGenerator->generate(
            BookingTicketRenderer::TYPE,
            [$orderId => $operation],
            $context,
        );

        $documentId = $result->getSuccess()->first()?->getId();

        if ($documentId !== null) {
            return $documentId;
        }

        $error = $result->getErrors()[$orderId] ?? null;

        if ($error instanceof DocumentException && $error->getErrorCode() === DocumentException::DOCUMENT_NUMBER_ALREADY_EXISTS) {
            // Same ticket set → same document number: the newest existing
            // document already contains every ticket.
            return $this->findNewestDocumentId($orderId, $context);
        }

        if ($error !== null) {
            $this->logger->error('Ticket document generation failed for order {orderId}: {message}', [
                'orderId' => $orderId,
                'message' => $error->getMessage(),
                'exception' => $error,
            ]);
        }

        return null;
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
