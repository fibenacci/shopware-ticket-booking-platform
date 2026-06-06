<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\Booking\SalesChannel;

use FibBookingSystem\Core\Domain\Seating\SeatmapReadService;
use FibBookingSystem\Core\Domain\Security\BookingRateLimiter;
use FibBookingSystem\FibBookingException;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['store-api']])]
class BookingSeatmapRoute extends AbstractBookingSeatmapRoute
{
    public function __construct(
        private readonly SeatmapReadService $seatmapReadService,
        private readonly BookingRateLimiter $rateLimiter,
    ) {
    }

    public function getDecorated(): AbstractBookingSeatmapRoute
    {
        throw new DecorationPatternException(self::class);
    }

    #[Route(
        path: '/store-api/fib-booking/seatmap/{slotId}',
        name: 'store-api.fib_booking.seatmap',
        defaults: ['_httpCache' => false],
        methods: ['GET'],
    )]
    public function load(string $slotId, Request $request, SalesChannelContext $context): BookingSeatmapRouteResponse
    {
        $this->rateLimiter->ensureAccepted(BookingRateLimiter::READ, $request->getClientIp());

        $normalized = strtolower($slotId);
        if (!Uuid::isValid($normalized)) {
            throw FibBookingException::invalidPayload('slotId', 'expected a UUID');
        }

        return new BookingSeatmapRouteResponse($this->seatmapReadService->getSeatmap($normalized));
    }
}
