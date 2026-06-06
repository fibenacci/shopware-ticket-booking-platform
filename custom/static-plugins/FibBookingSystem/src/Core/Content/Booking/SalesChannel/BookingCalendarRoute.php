<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\Booking\SalesChannel;

use DateTimeImmutable;
use FibBookingSystem\Core\Domain\Availability\BookingCalendarService;
use FibBookingSystem\Core\Domain\Security\BookingRateLimiter;
use FibBookingSystem\FibBookingException;
use Shopware\Core\Framework\Plugin\Exception\DecorationPatternException;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['store-api']])]
class BookingCalendarRoute extends AbstractBookingCalendarRoute
{
    public function __construct(
        private readonly BookingCalendarService $calendarService,
        private readonly BookingRateLimiter $rateLimiter,
    ) {
    }

    public function getDecorated(): AbstractBookingCalendarRoute
    {
        throw new DecorationPatternException(self::class);
    }

    #[Route(
        path: '/store-api/fib-booking/calendar',
        name: 'store-api.fib_booking.calendar',
        defaults: ['_httpCache' => false],
        methods: ['POST'],
    )]
    public function load(Request $request, SalesChannelContext $context): BookingCalendarRouteResponse
    {
        $this->rateLimiter->ensureAccepted(BookingRateLimiter::READ, $request->getClientIp());

        $payload = BookingRequestParser::payload($request);
        $resourceId = BookingRequestParser::uuid($payload, 'resourceId');
        $month = $this->parseMonth($payload);

        return new BookingCalendarRouteResponse($this->calendarService->getMonth($resourceId, $month));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function parseMonth(array $payload): DateTimeImmutable
    {
        $value = $payload['month'] ?? null;

        if ($value === null) {
            return new DateTimeImmutable('first day of this month');
        }

        if (!is_string($value) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
            throw FibBookingException::invalidPayload('month', 'expected YYYY-MM');
        }

        return new DateTimeImmutable($value . '-01');
    }
}
