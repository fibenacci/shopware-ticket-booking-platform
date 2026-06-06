<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\Booking\SalesChannel;

use FibBookingSystem\Core\Domain\Reservation\BookingHoldRequest;
use FibBookingSystem\Core\Domain\Reservation\BookingHoldService;
use FibBookingSystem\Core\Domain\Seating\SeatmapUpdatePublisher;
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
        private readonly SeatmapUpdatePublisher $seatmapPublisher,
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
    public function create(
        Request $request,
        SalesChannelContext $context,
    ): BookingHoldRouteResponse {
        $this->rateLimiter->ensureAccepted(BookingRateLimiter::WRITE, $request->getClientIp());

        $payload = BookingRequestParser::payload($request);
        $customer = $context->getCustomer();

        $seatIds = BookingRequestParser::optionalUuidList($payload, 'seatIds');

        $hold = $this->holdService->createHold(
            new BookingHoldRequest(
                resourceId: BookingRequestParser::uuid($payload, 'resourceId'),
                startsAt: BookingRequestParser::dateTime($payload, 'startsAt'),
                endsAt: BookingRequestParser::dateTime($payload, 'endsAt'),
                quantity: BookingRequestParser::positiveInt($payload, 'quantity'),
                salesChannelId: $context->getSalesChannelId(),
                customerId: $customer?->getId(),
                payload: $payload,
                seatIds: $seatIds,
            ),
            $context->getContext(),
        );

        // Live seat maps: other shoppers see the seats turn "held" instantly.
        // The hold transaction committed inside createHold — safe to publish.
        if ($seatIds !== [] && is_string($payload['slotId'] ?? null)) {
            $this->seatmapPublisher->publish(strtolower($payload['slotId']));
        }

        return new BookingHoldRouteResponse([
            'id' => $hold->getId(),
            'token' => $hold->getToken(),
            'expiresAt' => $hold->getExpiresAt()->format(\DATE_ATOM),
        ]);
    }
}
