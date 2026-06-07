<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Domain\Wallet;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use FibBookingSystem\Core\Content\BookingTicket\BookingTicketCollection;
use FibBookingSystem\Core\Content\BookingTicket\BookingTicketEntity;
use FibBookingSystem\Core\Domain\Security\TokenCipher;
use FibBookingSystem\Core\Domain\Ticket\QrCodeGenerator;
use RuntimeException;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;

/**
 * Facade for wallet pass generation: loads ticket data (rebuilding the QR
 * payload from the encrypted scan token) and delegates to the providers.
 */
class WalletPassService
{
    public const PROVIDER_APPLE = 'apple';
    public const PROVIDER_GOOGLE = 'google';

    /**
     * Wallet links are bearer credentials (no login behind them): a leaked
     * URL downloads the pass until it expires, and HMAC signatures cannot be
     * revoked short of rotating the app secret. 14 days covers the mail
     * use case (download right after purchase) while the account area mints
     * fresh links on every page view anyway.
     */
    public const DEFAULT_LINK_TTL_DAYS = 14;

    /**
     * The DBAL connection is used for one thing only: reading the
     * scan_token_cipher column, which is deliberately NOT part of the DAL
     * definition so the secret never leaks through the Admin API (see plugin
     * Security.md). Everything else goes through the DAL.
     *
     * @param EntityRepository<BookingTicketCollection> $ticketRepository
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityRepository $ticketRepository,
        private readonly TokenCipher $tokenCipher,
        private readonly AppleWalletPassGenerator $appleGenerator,
        private readonly GoogleWalletLinkGenerator $googleGenerator,
        private readonly WalletLinkSigner $linkSigner,
        private readonly QrCodeGenerator $qrCodeGenerator,
    ) {
    }

    /**
     * Signed query parameters for a wallet download URL (used in mails and the
     * account area). The link is valid for $ttlDays.
     *
     * @return array{exp: int, sig: string}
     */
    public function createSignedParams(
        string $provider,
        string $ticketId,
        int $ttlDays = self::DEFAULT_LINK_TTL_DAYS,
    ): array {
        $expiresAt = time() + ($ttlDays * 86400);

        return [
            'exp' => $expiresAt,
            'sig' => $this->linkSigner->sign($provider, $ticketId, $expiresAt),
        ];
    }

    public function verifySignature(
        string $provider,
        string $ticketId,
        int $expiresAt,
        string $signature,
    ): bool {
        return $this->linkSigner->verify($provider, $ticketId, $expiresAt, $signature);
    }

    public function isAppleAvailable(): bool
    {
        return $this->appleGenerator->isConfigured();
    }

    public function isGoogleAvailable(): bool
    {
        return $this->googleGenerator->isConfigured();
    }

    public function generateApplePass(TicketWalletData $data): string
    {
        return $this->appleGenerator->generate($data);
    }

    public function generateGoogleSaveLink(TicketWalletData $data): string
    {
        return $this->googleGenerator->generateSaveLink($data);
    }

    /**
     * Loads everything a wallet pass needs. Returns null when the ticket does
     * not exist, is not in a pass-worthy state, or predates wallet support
     * (no encrypted token stored).
     */
    public function loadTicketData(
        string $ticketId,
        Context $context,
    ): ?TicketWalletData {
        if (!Uuid::isValid($ticketId)) {
            return null;
        }

        $ticket = $this->fetchPassWorthyTicket($ticketId, $context);
        $reservation = $ticket?->getReservation();

        if ($ticket === null || $reservation === null) {
            return null;
        }

        $scanToken = $this->decryptScanToken($ticketId);

        if ($scanToken === null) {
            return null;
        }

        $qrPayload = json_encode([
            'type' => 'fib_booking_ticket',
            'ticketNumber' => $ticket->getTicketNumber(),
            'scanToken' => $scanToken,
        ], JSON_THROW_ON_ERROR);

        $customer = $reservation->getCustomer();
        $customerName = $customer instanceof CustomerEntity
            ? trim(sprintf('%s %s', $customer->getFirstName(), $customer->getLastName()))
            : '';

        return new TicketWalletData(
            ticketId: $ticket->getId(),
            ticketNumber: $ticket->getTicketNumber(),
            bookingNumber: $reservation->getBookingNumber(),
            status: $ticket->getStatus(),
            qrPayload: $qrPayload,
            window: new BookingWindow(
                resourceName: (string) $reservation->getResource()?->getName(),
                startsAt: DateTimeImmutable::createFromInterface($reservation->getStartsAt()),
                endsAt: DateTimeImmutable::createFromInterface($reservation->getEndsAt()),
                quantity: $reservation->getQuantity(),
            ),
            customerName: $customerName === '' ? null : $customerName,
            seatLabel: $ticket->getSeatLabel(),
        );
    }

    /**
     * Which of the given tickets have a stored wallet token. scan_token_cipher
     * is intentionally NOT part of the DAL definition (see plugin Security.md),
     * so presence is read via the same documented raw exception as
     * loadTicketData().
     *
     * @param array<string> $ticketIds
     *
     * @return array<string, true> ticket id (hex) => true
     */
    public function ticketsWithWalletToken(array $ticketIds): array
    {
        if ($ticketIds === []) {
            return [];
        }

        /** @var list<string> $rows */
        $rows = $this->connection->fetchFirstColumn(
            'SELECT LOWER(HEX(id)) FROM fib_booking_ticket WHERE id IN (:ids) AND scan_token_cipher IS NOT NULL',
            ['ids' => Uuid::fromHexToBytesList($ticketIds)],
            ['ids' => ArrayParameterType::BINARY],
        );

        return array_fill_keys($rows, true);
    }

    /**
     * Batched QR data URIs for the account ticket list: rebuilds each scan
     * QR from the encrypted token (same documented raw exception as
     * loadTicketData — scan_token_cipher is not in the DAL definition).
     * Tickets without a stored cipher (pre-wallet era) are simply absent.
     *
     * @param array<string> $ticketIds
     *
     * @return array<string, string> ticket id (hex) => image data URI
     */
    public function qrDataUris(array $ticketIds): array
    {
        if ($ticketIds === []) {
            return [];
        }

        /** @var list<array{id: string, ticket_number: string, scan_token_cipher: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT LOWER(HEX(id)) AS id, ticket_number, scan_token_cipher
                FROM fib_booking_ticket
                WHERE id IN (:ids) AND scan_token_cipher IS NOT NULL
            SQL,
            ['ids' => Uuid::fromHexToBytesList($ticketIds)],
            ['ids' => ArrayParameterType::BINARY],
        );

        $result = [];

        foreach ($rows as $row) {
            try {
                $scanToken = $this->tokenCipher->decrypt($row['scan_token_cipher']);
            } catch (RuntimeException) {
                continue; // undecryptable (rotated secret) — no QR, no crash
            }

            $qrPayload = json_encode([
                'type' => 'fib_booking_ticket',
                'ticketNumber' => $row['ticket_number'],
                'scanToken' => $scanToken,
            ], JSON_THROW_ON_ERROR);

            $result[$row['id']] = $this->qrCodeGenerator->generateDataUri($qrPayload);
        }

        return $result;
    }

    private function fetchPassWorthyTicket(
        string $ticketId,
        Context $context,
    ): ?BookingTicketEntity {
        $criteria = new Criteria([$ticketId]);
        $criteria->addAssociation('reservation.resource');
        $criteria->addAssociation('reservation.customer');

        /** @var BookingTicketEntity|null $ticket */
        $ticket = $this->ticketRepository->search($criteria, $context)->first();

        if ($ticket === null || !in_array($ticket->getStatus(), ['issued', 'sent', 'scanned'], true)) {
            return null;
        }

        return $ticket;
    }

    private function decryptScanToken(string $ticketId): ?string
    {
        $cipher = $this->fetchScanTokenCipher($ticketId);

        if ($cipher === null || $cipher === '') {
            return null;
        }

        try {
            return $this->tokenCipher->decrypt($cipher);
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * Deliberate raw SQL: scan_token_cipher is intentionally NOT part of the
     * DAL definition so the secret never leaks through the Admin API (see
     * plugin Security.md). Same pattern as BookingTicketRenderer.
     */
    private function fetchScanTokenCipher(string $ticketId): ?string
    {
        $cipher = $this->connection->fetchOne(
            'SELECT scan_token_cipher FROM fib_booking_ticket WHERE id = :ticketId',
            ['ticketId' => Uuid::fromHexToBytes($ticketId)],
        );

        return is_string($cipher) ? $cipher : null;
    }
}
