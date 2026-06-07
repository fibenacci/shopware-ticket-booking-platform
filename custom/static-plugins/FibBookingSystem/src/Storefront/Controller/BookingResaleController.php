<?php

declare(strict_types=1);

namespace FibBookingSystem\Storefront\Controller;

use FibBookingSystem\Checkout\Cart\Resale\ResaleLineItemFactory;
use FibBookingSystem\Core\Domain\Resale\BookingResaleService;
use FibBookingSystem\Core\Domain\Resale\ListingMode;
use FibBookingSystem\Core\Domain\Resale\ListingReadService;
use FibBookingSystem\Core\Domain\Security\BookingRateLimiter;
use FibBookingSystem\FibBookingException;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Storefront surface of the resale market (docs/RESELL_PLAN.md, legal
 * framing): a public browse page for active fixed-price lots, sell/cancel
 * actions in the customer account, and the buy action that drops a
 * `fib-resale` line item into the cart. Strictly private C2C — every write
 * route requires a logged-in customer, and all eligibility/price truth lives
 * server-side (BookingResaleService / ResaleCartProcessor), never in forms.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class BookingResaleController extends StorefrontController
{
    public function __construct(
        private readonly BookingResaleService $resaleService,
        private readonly ListingReadService $listingReadService,
        private readonly ResaleLineItemFactory $lineItemFactory,
        private readonly CartService $cartService,
        private readonly BookingRateLimiter $rateLimiter,
    ) {
    }

    #[Route(
        path: '/fib-booking/resale',
        name: 'frontend.fib_booking.resale.index',
        defaults: ['_httpCache' => false],
        methods: ['GET'],
    )]
    public function index(
        Request $request,
        SalesChannelContext $context,
    ): Response {
        $this->rateLimiter->ensureAccepted(BookingRateLimiter::READ, $request->getClientIp());

        return $this->renderStorefront('@FibBookingSystem/storefront/page/fib-booking/resale-index.html.twig', [
            'listings' => $this->listingReadService->fetchActiveFixedPrice(),
        ]);
    }

    #[Route(
        path: '/fib-booking/resale/{listingId}/buy',
        name: 'frontend.fib_booking.resale.buy',
        defaults: ['_loginRequired' => true],
        methods: ['POST'],
    )]
    public function buy(
        string $listingId,
        Request $request,
        SalesChannelContext $context,
    ): Response {
        $this->rateLimiter->ensureAccepted(BookingRateLimiter::WRITE, $request->getClientIp());

        if (!Uuid::isValid($listingId)) {
            throw new NotFoundHttpException();
        }

        $cart = $this->cartService->getCart($context->getToken(), $context);
        $lineItem = $this->lineItemFactory->create(['referencedId' => $listingId], $context);

        // Eligibility (still active, buyer ≠ seller, non-guest) is enforced
        // by ResaleCartProcessor during this add and on every recalculation.
        $this->cartService->add($cart, $lineItem, $context);

        return $this->redirectToRoute('frontend.checkout.cart.page');
    }

    #[Route(
        path: '/account/fib-booking/tickets/{ticketId}/sell',
        name: 'frontend.account.fib_booking.resale.sell',
        defaults: ['_loginRequired' => true, '_noStore' => true],
        methods: ['POST'],
    )]
    public function sell(
        string $ticketId,
        Request $request,
        SalesChannelContext $context,
    ): Response {
        $this->rateLimiter->ensureAccepted(BookingRateLimiter::WRITE, $request->getClientIp());

        $customer = $this->requireCustomer($context);

        if (!Uuid::isValid($ticketId)) {
            throw new NotFoundHttpException();
        }

        $askPrice = $this->parsePrice($request->request->get('askPrice'));

        try {
            if ($askPrice === null) {
                throw FibBookingException::invalidPayload('askPrice', 'must be a positive amount');
            }

            $this->resaleService->createListing($ticketId, $customer->getId(), ListingMode::FIXED_PRICE, $askPrice);
            $this->addFlash(self::SUCCESS, $this->trans('fibBooking.resale.flash.listed'));
        } catch (FibBookingException $exception) {
            $this->addFlash(self::DANGER, $this->trans('fibBooking.resale.flash.sellFailed', [
                '%reason%' => $exception->getMessage(),
            ]));
        }

        return $this->redirectToRoute('frontend.account.fib_booking.tickets');
    }

    #[Route(
        path: '/account/fib-booking/resale/{listingId}/cancel',
        name: 'frontend.account.fib_booking.resale.cancel',
        defaults: ['_loginRequired' => true, '_noStore' => true],
        methods: ['POST'],
    )]
    public function cancel(
        string $listingId,
        Request $request,
        SalesChannelContext $context,
    ): Response {
        $this->rateLimiter->ensureAccepted(BookingRateLimiter::WRITE, $request->getClientIp());

        $customer = $this->requireCustomer($context);

        if (!Uuid::isValid($listingId)) {
            throw new NotFoundHttpException();
        }

        try {
            $this->resaleService->cancelListing($listingId, $customer->getId());
            $this->addFlash(self::SUCCESS, $this->trans('fibBooking.resale.flash.cancelled'));
        } catch (FibBookingException $exception) {
            $this->addFlash(self::DANGER, $this->trans('fibBooking.resale.flash.cancelFailed', [
                '%reason%' => $exception->getMessage(),
            ]));
        }

        return $this->redirectToRoute('frontend.account.fib_booking.tickets');
    }

    private function requireCustomer(SalesChannelContext $context): CustomerEntity
    {
        $customer = $context->getCustomer();

        // Guests pass _loginRequired but are not accounts — C2C needs one.
        if (!$customer instanceof CustomerEntity || $customer->getGuest()) {
            throw new NotFoundHttpException();
        }

        return $customer;
    }

    /**
     * Accepts "12.50" and the locale form "12,50"; rejects everything that
     * is not a plain positive amount.
     */
    private function parsePrice(mixed $raw): ?float
    {
        if (!is_string($raw)) {
            return null;
        }

        $normalized = str_replace(',', '.', trim($raw));

        if (!preg_match('/^\d{1,7}(\.\d{1,2})?$/', $normalized)) {
            return null;
        }

        $price = (float) $normalized;

        return $price > 0 ? $price : null;
    }
}
