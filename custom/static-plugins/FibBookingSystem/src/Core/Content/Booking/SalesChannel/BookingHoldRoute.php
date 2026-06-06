<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\Booking\SalesChannel;

use FibBookingSystem\Core\Domain\Reservation\BookingHoldService;
use FibBookingSystem\Core\Domain\Security\BookingRateLimiter;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['store-api']])]
class BookingHoldRoute extends AbstractBookingHoldRoute
{
    public function __construct(
        private readonly BookingHoldService $holdService,
        private readonly BookingRateLimiter $rateLimiter,
    ) {
    }

    public function getDecorated(): AbstractBookingHoldRoute
    {
        throw new DecorationPatternException(self::class);
    }

    #[Route(
        path: '/store-api/fib-booking/hold',
        name: 'store-api.fib_booking.hold',
        defaults: ['_httpCache' => false],
        methods: ['POST'],
    )]
    public function create(Request $request, SalesChannelContext $context): BookingHoldRouteResponse
    {
        $this->rateLimiter->ensureAccepted(BookingRateLimiter::WRITE, $request->getClientIp());

        $payload = BookingRequestParser::payload($request);
        $customer = $context->getCustomer();

        $hold = $this->holdService->createHold(
            BookingRequestParser::uuid($payload, 'resourceId'),
            BookingRequestParser::dateTime($payload, 'startsAt'),
            BookingRequestParser::dateTime($payload, 'endsAt'),
            BookingRequestParser::positiveInt($payload, 'quantity'),
            $context->getSalesChannelId(),
            $customer?->getId(),
            $payload,
        );

        return new BookingHoldRouteResponse([
            'id' => $hold->getId(),
            'token' => $hold->getToken(),
            'expiresAt' => $hold->getExpiresAt()->format(\DATE_ATOM),
        ]);
    }
}
