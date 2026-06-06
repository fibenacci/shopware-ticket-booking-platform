<?php

declare(strict_types=1);

namespace FibBookingSystem\Storefront\Controller;

use FibBookingSystem\Checkout\Cart\BookingLineItemFactory;
use FibBookingSystem\Core\Content\Booking\SalesChannel\AbstractBookingAvailabilityRoute;
use FibBookingSystem\Core\Content\Booking\SalesChannel\AbstractBookingCalendarRoute;
use FibBookingSystem\Core\Content\Booking\SalesChannel\AbstractBookingHoldRoute;
use FibBookingSystem\Core\Content\Booking\SalesChannel\AbstractBookingSeatmapRoute;
use FibBookingSystem\Core\Content\Booking\SalesChannel\BookingRequestParser;
use FibBookingSystem\Core\Domain\Security\BookingRateLimiter;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

/**
 * Storefront JSON endpoints for the booking widget. Availability and hold
 * creation delegate to the Store API routes (Shopware abstract-route pattern)
 * — headless consumers use /store-api/fib-booking/* directly.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class BookingApiController extends StorefrontController
{
    public function __construct(
        private readonly AbstractBookingAvailabilityRoute $availabilityRoute,
        private readonly AbstractBookingHoldRoute $holdRoute,
        private readonly AbstractBookingCalendarRoute $calendarRoute,
        private readonly AbstractBookingSeatmapRoute $seatmapRoute,
        private readonly BookingLineItemFactory $lineItemFactory,
        private readonly CartService $cartService,
        private readonly BookingRateLimiter $rateLimiter,
    ) {
    }

    #[Route(
        path: '/fib-booking/seatmap/{slotId}',
        name: 'frontend.fib_booking.seatmap',
        defaults: ['XmlHttpRequest' => true, '_httpCache' => false],
        methods: ['GET'],
    )]
    public function seatmap(string $slotId, Request $request, SalesChannelContext $context): JsonResponse
    {
        try {
            $response = $this->seatmapRoute->load($slotId, $request, $context);

            return $this->noStoreJson($response->getSeatmap());
        } catch (TooManyRequestsHttpException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return $this->noStoreJson([
                'seats' => [],
                'error' => $exception->getMessage(),
            ], JsonResponse::HTTP_BAD_REQUEST);
        }
    }

    #[Route(
        path: '/fib-booking/calendar',
        name: 'frontend.fib_booking.calendar',
        defaults: ['XmlHttpRequest' => true, '_httpCache' => false],
        methods: ['POST'],
    )]
    public function calendar(Request $request, SalesChannelContext $context): JsonResponse
    {
        try {
            $response = $this->calendarRoute->load($request, $context);

            return $this->noStoreJson($response->getCalendar());
        } catch (TooManyRequestsHttpException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return $this->noStoreJson([
                'days' => [],
                'error' => $exception->getMessage(),
            ], JsonResponse::HTTP_BAD_REQUEST);
        }
    }

    #[Route(
        path: '/fib-booking/availability',
        name: 'frontend.fib_booking.availability',
        defaults: ['XmlHttpRequest' => true, '_httpCache' => false],
        methods: ['POST'],
    )]
    public function availability(Request $request, SalesChannelContext $context): JsonResponse
    {
        try {
            $response = $this->availabilityRoute->check($request, $context);

            return $this->noStoreJson($response->getAvailability());
        } catch (TooManyRequestsHttpException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return $this->noStoreJson([
                'available' => false,
                'error' => $exception->getMessage(),
            ], JsonResponse::HTTP_BAD_REQUEST);
        }
    }

    #[Route(
        path: '/fib-booking/hold',
        name: 'frontend.fib_booking.hold',
        defaults: ['XmlHttpRequest' => true, '_httpCache' => false],
        methods: ['POST'],
    )]
    public function hold(Request $request, SalesChannelContext $context): JsonResponse
    {
        try {
            $response = $this->holdRoute->create($request, $context);

            return $this->noStoreJson($response->getHold());
        } catch (TooManyRequestsHttpException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return $this->noStoreJson([
                'success' => false,
                'error' => $exception->getMessage(),
            ], JsonResponse::HTTP_BAD_REQUEST);
        }
    }

    #[Route(
        path: '/fib-booking/cart/add',
        name: 'frontend.fib_booking.cart.add',
        defaults: ['XmlHttpRequest' => true, '_httpCache' => false],
        methods: ['POST'],
    )]
    public function addToCart(Request $request, SalesChannelContext $context): JsonResponse
    {
        $this->rateLimiter->ensureAccepted(BookingRateLimiter::WRITE, $request->getClientIp());

        try {
            $payload = BookingRequestParser::payload($request);
            $lineItem = $this->lineItemFactory->createProductLineItem(
                BookingRequestParser::uuid($payload, 'productId'),
                BookingRequestParser::uuid($payload, 'holdId'),
                $this->requireString($payload, 'holdToken'),
                BookingRequestParser::positiveInt($payload, 'quantity'),
            );

            $cart = $this->cartService->getCart($context->getToken(), $context);
            $cart = $this->cartService->add($cart, $lineItem, $context);

            return $this->noStoreJson([
                'success' => true,
                'cartToken' => $cart->getToken(),
                'lineItemId' => $lineItem->getId(),
            ]);
        } catch (Throwable $exception) {
            return $this->noStoreJson([
                'success' => false,
                'error' => $exception->getMessage(),
            ], JsonResponse::HTTP_BAD_REQUEST);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requireString(array $payload, string $field): string
    {
        $value = $payload[$field] ?? null;

        if (!is_string($value) || $value === '') {
            throw \FibBookingSystem\FibBookingException::invalidPayload($field, 'expected a non-empty string');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function noStoreJson(array $data, int $status = JsonResponse::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
