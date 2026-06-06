<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\Booking\SalesChannel;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Store API route checking booking availability for a resource time window.
 * Follows the Shopware abstract-route pattern so projects can decorate it.
 */
abstract class AbstractBookingAvailabilityRoute
{
    abstract public function getDecorated(): AbstractBookingAvailabilityRoute;

    abstract public function check(
        Request $request,
        SalesChannelContext $context,
    ): BookingAvailabilityRouteResponse;
}
