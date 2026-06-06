<?php

declare(strict_types=1);

namespace FibBookingSystem\Storefront\Controller;

use DateTimeImmutable;
use Exception;
use FibBookingSystem\Checkout\Cart\BookingLineItemFactory;
use FibBookingSystem\Core\Domain\Availability\AvailabilityService;
use FibBookingSystem\Core\Domain\Reservation\BookingHoldService;
use InvalidArgumentException;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Throwable;

#[Route(defaults: ['_routeScope' => ['storefront']])]
class BookingApiController extends StorefrontController
{
    public function __construct(
        private readonly AvailabilityService $availabilityService,
        private readonly BookingHoldService $holdService,
        private readonly BookingLineItemFactory $lineItemFactory,
        private readonly CartService $cartService,
    ) {
    }

    #[Route(
        path: '/fib-booking/availability',
        name: 'frontend.fib_booking.availability',
        defaults: ['XmlHttpRequest' => true],
        methods: ['POST'],
    )]
    public function availability(Request $request): JsonResponse
    {
        try {
            $payload = $this->decodeJsonPayload($request);
            $result = $this->availabilityService->check(
                $this->requireString($payload, 'resourceId'),
                $this->requireDateTime($payload, 'startsAt'),
                $this->requireDateTime($payload, 'endsAt'),
                $this->requirePositiveInt($payload, 'quantity'),
            );

            return $this->noStoreJson($result->jsonSerialize());
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
        defaults: ['XmlHttpRequest' => true],
        methods: ['POST'],
    )]
    public function hold(Request $request, SalesChannelContext $context): JsonResponse
    {
        try {
            $payload = $this->decodeJsonPayload($request);
            $customer = $context->getCustomer();
            $hold = $this->holdService->createHold(
                $this->requireString($payload, 'resourceId'),
                $this->requireDateTime($payload, 'startsAt'),
                $this->requireDateTime($payload, 'endsAt'),
                $this->requirePositiveInt($payload, 'quantity'),
                $context->getSalesChannelId(),
                $customer ? $customer->getId() : null,
                $payload,
            );

            return $this->noStoreJson([
                'id' => $hold->getId(),
                'token' => $hold->getToken(),
                'expiresAt' => $hold->getExpiresAt()->format(DATE_ATOM),
            ]);
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
        defaults: ['XmlHttpRequest' => true],
        methods: ['POST'],
    )]
    public function addToCart(Request $request, SalesChannelContext $context): JsonResponse
    {
        try {
            $payload = $this->decodeJsonPayload($request);
            $lineItem = $this->lineItemFactory->createProductLineItem(
                $this->requireString($payload, 'productId'),
                $this->requireString($payload, 'holdId'),
                $this->requireString($payload, 'holdToken'),
                $this->requirePositiveInt($payload, 'quantity'),
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
     * @param array<string, mixed> $data
     */
    private function noStoreJson(array $data, int $status = JsonResponse::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonPayload(Request $request): array
    {
        $payload = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($payload)) {
            throw new InvalidArgumentException('Expected a JSON object.');
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requireString(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException(sprintf('Missing required string "%s".', $key));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requireDateTime(array $payload, string $key): DateTimeImmutable
    {
        $value = $this->requireString($payload, $key);

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            throw new InvalidArgumentException(sprintf('Expected "%s" as ISO-8601 datetime.', $key));
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requirePositiveInt(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;

        if (!is_int($value) || $value < 1) {
            throw new InvalidArgumentException(sprintf('Expected "%s" as positive integer.', $key));
        }

        return $value;
    }
}
