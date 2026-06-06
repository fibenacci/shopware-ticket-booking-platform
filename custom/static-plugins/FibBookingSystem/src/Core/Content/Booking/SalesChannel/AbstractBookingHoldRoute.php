<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\Booking\SalesChannel;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Store API route creating a temporary booking hold for a resource window.
 * Follows the Shopware abstract-route pattern so projects can decorate it.
 */
abstract class AbstractBookingHoldRoute
{
    abstract public function getDecorated(): AbstractBookingHoldRoute;

    abstract public function create(Request $request, SalesChannelContext $context): BookingHoldRouteResponse;
}
