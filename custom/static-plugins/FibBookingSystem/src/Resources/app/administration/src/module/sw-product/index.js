import './page/sw-product-detail';
import './view/fib-product-detail-booking';

import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

Shopware.Locale.extend('de-DE', deDE);
Shopware.Locale.extend('en-GB', enGB);

/**
 * Adds the "Booking & Tickets" tab to the product detail page. The tab hosts
 * the generic ticket validity configuration (see docs/TICKET_TYPES.md):
 * validity mode × entry policy with duration presets.
 */
Shopware.Module.register('fib-booking-product-tab', {
    routeMiddleware(next, currentRoute) {
        if (
            currentRoute.name === 'sw.product.detail'
            && Array.isArray(currentRoute.children)
            && currentRoute.children.every((child) => child.name !== 'sw.product.detail.fibBooking')
        ) {
            currentRoute.children.push({
                name: 'sw.product.detail.fibBooking',
                path: '/sw/product/detail/:id/fib-booking',
                component: 'fib-product-detail-booking',
                meta: {
                    parentPath: 'sw.product.index',
                },
            });
        }

        next(currentRoute);
    },
});
