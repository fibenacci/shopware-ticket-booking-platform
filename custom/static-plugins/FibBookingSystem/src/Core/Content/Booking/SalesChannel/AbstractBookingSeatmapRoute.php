<?php

declare(strict_types=1);

namespace FibBookingSystem\Core\Content\Booking\SalesChannel;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;

/**
 * Store API: seat layout + live claim state for one slot — feeds the
 * storefront seat picker (docs/SEATING_PLAN.md).
 */
abstract class AbstractBookingSeatmapRoute
{
    abstract public function getDecorated(): AbstractBookingSeatmapRoute;

    abstract public function load(string $slotId, Request $request, SalesChannelContext $context): BookingSeatmapRouteResponse;
}
