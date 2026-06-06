<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\Booking\SalesChannel;

use FibBookingSystem\Core\Domain\Availability\AvailabilityService;
use FibBookingSystem\Core\Domain\Security\BookingRateLimiter;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['store-api']])]
class BookingAvailabilityRoute extends AbstractBookingAvailabilityRoute
{
    public function __construct(
        private readonly AvailabilityService $availabilityService,
        private readonly BookingRateLimiter $rateLimiter,
    ) {
    }

    public function getDecorated(): AbstractBookingAvailabilityRoute
    {
        throw new DecorationPatternException(self::class);
    }

    #[Route(
        path: '/store-api/fib-booking/availability',
        name: 'store-api.fib_booking.availability',
        defaults: ['_httpCache' => false],
        methods: ['POST'],
    )]
    public function check(
        Request $request,
        SalesChannelContext $context,
    ): BookingAvailabilityRouteResponse {
        $this->rateLimiter->ensureAccepted(BookingRateLimiter::READ, $request->getClientIp());

        $payload = BookingRequestParser::payload($request);

        $result = $this->availabilityService->check(
            BookingRequestParser::uuid($payload, 'resourceId'),
            BookingRequestParser::dateTime($payload, 'startsAt'),
            BookingRequestParser::dateTime($payload, 'endsAt'),
            BookingRequestParser::positiveInt($payload, 'quantity'),
        );

        return new BookingAvailabilityRouteResponse($result->jsonSerialize());
    }
}
