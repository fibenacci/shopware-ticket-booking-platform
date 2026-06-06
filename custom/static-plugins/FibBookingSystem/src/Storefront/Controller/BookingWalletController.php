<?php

declare(strict_types=1);

namespace FibBookingSystem\Storefront\Controller;

use FibBookingSystem\Core\Content\BookingTicket\BookingTicketCollection;
use FibBookingSystem\Core\Content\BookingTicket\BookingTicketEntity;
use FibBookingSystem\Core\Domain\Resale\ListingReadService;
use FibBookingSystem\Core\Domain\Security\BookingRateLimiter;
use FibBookingSystem\Core\Domain\Wallet\TicketWalletData;
use FibBookingSystem\Core\Domain\Wallet\WalletPassService;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\AndFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
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
    /**
     * @param EntityRepository<BookingTicketCollection> $ticketRepository
     */
    public function __construct(
        private readonly WalletPassService $walletService,
        private readonly EntityRepository $ticketRepository,
        private readonly BookingRateLimiter $rateLimiter,
        private readonly ListingReadService $listingReadService,
    ) {
    }

    #[Route(
        path: '/fib-booking/wallet/{ticketId}/apple.pkpass',
        name: 'frontend.fib_booking.wallet.apple',
        defaults: ['_httpCache' => false],
        methods: ['GET'],
    )]
    public function applePass(
        string $ticketId,
        Request $request,
        SalesChannelContext $context,
    ): Response {
        $data = $this->validateSignedRequest(WalletPassService::PROVIDER_APPLE, $ticketId, $request, $context);

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
    public function googlePass(
        string $ticketId,
        Request $request,
        SalesChannelContext $context,
    ): Response {
        $data = $this->validateSignedRequest(WalletPassService::PROVIDER_GOOGLE, $ticketId, $request, $context);

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

        $tickets = $this->fetchCustomerTickets($customer->getId(), $salesChannelContext);

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
            // ticket id → active listing (sell/cancel state per ticket card)
            'resaleListings' => $this->listingReadService->fetchActiveForTickets(
                array_column($tickets, 'ticketId'),
            ),
        ]);
        $this->hardenResponse($response);

        return $response;
    }

    /**
     * @return list<array<string, mixed>>
     */
    /**
     * @return list<array{ticketId: string, ticketNumber: string, status: string, hasWalletToken: bool, bookingNumber: string, startsAt: string|null, endsAt: string|null, quantity: int, resourceName: string}>
     */
    private function fetchCustomerTickets(
        string $customerId,
        SalesChannelContext $context,
    ): array {
        $criteria = new Criteria();
        $criteria->addFilter(new OrFilter([
            new EqualsFilter('ownerCustomerId', $customerId),
            new AndFilter([
                new EqualsFilter('ownerCustomerId', null),
                new EqualsFilter('reservation.customerId', $customerId),
            ]),
        ]));
        $criteria->addFilter(new EqualsAnyFilter('status', ['issued', 'sent', 'scanned']));
        $criteria->addAssociation('reservation.resource');
        $criteria->addSorting(new FieldSorting('reservation.startsAt', FieldSorting::DESCENDING));
        $criteria->setLimit(100);

        $tickets = $this->ticketRepository->search($criteria, $context->getContext())->getEntities();

        // scan_token_cipher is not in the DAL definition (security), so the
        // wallet-token flag comes from a separate, batched raw lookup that
        // owns that documented exception.
        $ticketsWithToken = $this->walletService->ticketsWithWalletToken($tickets->getIds());

        $result = [];

        /** @var BookingTicketEntity $ticket */
        foreach ($tickets as $ticket) {
            $reservation = $ticket->getReservation();

            $result[] = [
                'ticketId' => $ticket->getId(),
                'ticketNumber' => $ticket->getTicketNumber(),
                'status' => $ticket->getStatus(),
                'hasWalletToken' => isset($ticketsWithToken[$ticket->getId()]),
                'bookingNumber' => (string) $reservation?->getBookingNumber(),
                'startsAt' => $reservation?->getStartsAt()->format(\DATE_ATOM),
                'endsAt' => $reservation?->getEndsAt()->format(\DATE_ATOM),
                'quantity' => (int) $reservation?->getQuantity(),
                'resourceName' => (string) $reservation?->getResource()?->getName(),
            ];
        }

        return $result;
    }

    private function validateSignedRequest(
        string $provider,
        string $ticketId,
        Request $request,
        SalesChannelContext $context,
    ): TicketWalletData {
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

        $data = $this->walletService->loadTicketData($ticketId, $context->getContext());

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
