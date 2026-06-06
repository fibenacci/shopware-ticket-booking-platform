<?php

declare(strict_types=1);

namespace FibBookingSystem\Storefront\Controller;

use Doctrine\DBAL\Connection;
use FibBookingSystem\Core\Domain\Security\BookingRateLimiter;
use FibBookingSystem\Core\Domain\Wallet\WalletPassService;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Wallet pass downloads + the customer account ticket area.
 *
 * Download routes are reachable WITHOUT login (the ticket mail links there),
 * protected by an expiring HMAC signature instead. Any validation failure —
 * bad id, bad signature, expired link, unknown ticket, unconfigured provider —
 * results in the same uniform 404 so the endpoint cannot be used as an oracle.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class BookingWalletController extends StorefrontController
{
    public function __construct(
        private readonly WalletPassService $walletService,
        private readonly Connection $connection,
        private readonly BookingRateLimiter $rateLimiter,
    ) {
    }

    #[Route(
        path: '/fib-booking/wallet/{ticketId}/apple.pkpass',
        name: 'frontend.fib_booking.wallet.apple',
        defaults: ['_httpCache' => false],
        methods: ['GET'],
    )]
    public function applePass(string $ticketId, Request $request): Response
    {
        $data = $this->validateSignedRequest(WalletPassService::PROVIDER_APPLE, $ticketId, $request);

        if (!$this->walletService->isAppleAvailable()) {
            throw new NotFoundHttpException();
        }

        $response = new Response($this->walletService->generateApplePass($data));
        $response->headers->set('Content-Type', 'application/vnd.apple.pkpass');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="ticket-%s.pkpass"', $data->ticketNumber));
        $this->hardenResponse($response);

        return $response;
    }

    #[Route(
        path: '/fib-booking/wallet/{ticketId}/google',
        name: 'frontend.fib_booking.wallet.google',
        defaults: ['_httpCache' => false],
        methods: ['GET'],
    )]
    public function googlePass(string $ticketId, Request $request): Response
    {
        $data = $this->validateSignedRequest(WalletPassService::PROVIDER_GOOGLE, $ticketId, $request);

        if (!$this->walletService->isGoogleAvailable()) {
            throw new NotFoundHttpException();
        }

        $response = new RedirectResponse($this->walletService->generateGoogleSaveLink($data), Response::HTTP_FOUND);
        $this->hardenResponse($response);

        return $response;
    }

    #[Route(
        path: '/account/fib-booking/tickets',
        name: 'frontend.account.fib_booking.tickets',
        defaults: ['_loginRequired' => true, '_noStore' => true],
        methods: ['GET'],
    )]
    public function accountTickets(SalesChannelContext $salesChannelContext): Response
    {
        $customer = $salesChannelContext->getCustomer();

        if (!$customer instanceof CustomerEntity) {
            throw new NotFoundHttpException();
        }

        $tickets = $this->fetchCustomerTickets($customer->getId());

        foreach ($tickets as &$ticket) {
            $ticket['appleParams'] = $this->walletService->isAppleAvailable() && $ticket['hasWalletToken']
                ? $this->walletService->createSignedParams(WalletPassService::PROVIDER_APPLE, $ticket['ticketId'])
                : null;
            $ticket['googleParams'] = $this->walletService->isGoogleAvailable() && $ticket['hasWalletToken']
                ? $this->walletService->createSignedParams(WalletPassService::PROVIDER_GOOGLE, $ticket['ticketId'])
                : null;
        }
        unset($ticket);

        $response = $this->renderStorefront('@FibBookingSystem/storefront/page/account/fib-booking-tickets.html.twig', [
            'tickets' => $tickets,
        ]);
        $this->hardenResponse($response);

        return $response;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchCustomerTickets(string $customerId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT LOWER(HEX(ticket.id)) AS ticket_id, ticket.ticket_number, ticket.status,
                    ticket.scan_token_cipher IS NOT NULL AS has_wallet_token,
                    reservation.booking_number, reservation.starts_at, reservation.ends_at, reservation.quantity,
                    resource.name AS resource_name
             FROM fib_booking_ticket ticket
             INNER JOIN fib_booking_reservation reservation ON reservation.id = ticket.reservation_id
             INNER JOIN fib_booking_resource resource ON resource.id = reservation.resource_id
             WHERE reservation.customer_id = :customerId
               AND ticket.status IN ('issued', 'sent', 'scanned')
             ORDER BY reservation.starts_at DESC
             LIMIT 100",
            ['customerId' => Uuid::fromHexToBytes($customerId)],
        );

        return array_map(static fn (array $row): array => [
            'ticketId' => (string) $row['ticket_id'],
            'ticketNumber' => (string) $row['ticket_number'],
            'status' => (string) $row['status'],
            'hasWalletToken' => (bool) $row['has_wallet_token'],
            'bookingNumber' => (string) $row['booking_number'],
            'startsAt' => (string) $row['starts_at'],
            'endsAt' => (string) $row['ends_at'],
            'quantity' => (int) $row['quantity'],
            'resourceName' => (string) $row['resource_name'],
        ], $rows);
    }

    private function validateSignedRequest(string $provider, string $ticketId, Request $request): \FibBookingSystem\Core\Domain\Wallet\TicketWalletData
    {
        $this->rateLimiter->ensureAccepted(BookingRateLimiter::WALLET, $request->getClientIp());

        $exp = $request->query->get('exp');
        $sig = $request->query->get('sig');

        if (!Uuid::isValid($ticketId)
            || !is_string($exp) || !ctype_digit($exp)
            || !is_string($sig)
            || !$this->walletService->verifySignature($provider, $ticketId, (int) $exp, $sig)
        ) {
            throw new NotFoundHttpException();
        }

        $data = $this->walletService->loadTicketData($ticketId);

        if ($data === null) {
            throw new NotFoundHttpException();
        }

        return $data;
    }

    private function hardenResponse(Response $response): void
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');
    }
}
