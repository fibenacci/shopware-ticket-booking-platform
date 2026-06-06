<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\Booking\SalesChannel;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Store API route returning a month of bookable slots ("Termine") for a
 * resource — drives the storefront booking calendar (red = sold out).
 * Follows the Shopware abstract-route pattern so projects can decorate it.
 */
abstract class AbstractBookingCalendarRoute
{
    abstract public function getDecorated(): AbstractBookingCalendarRoute;

    abstract public function load(
        Request $request,
        SalesChannelContext $context,
    ): BookingCalendarRouteResponse;
}
