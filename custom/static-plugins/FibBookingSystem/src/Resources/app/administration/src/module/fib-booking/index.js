import './page/fib-booking-resource-list';
import './page/fib-booking-resource-seating';
import './page/fib-booking-seat-editor';

import deDE from './snippet/de-DE.json';
import enGB from './snippet/en-GB.json';

Shopware.Locale.extend('de-DE', deDE);
Shopware.Locale.extend('en-GB', enGB);

/**
 * Booking resources module: list of resources with a seat-map editor for
 * `seatingMode: seatmap` rooms (grid generator + per-seat toggling, see
 * plugin docs/SEATING_PLAN.md phase 4).
 */
Shopware.Module.register('fib-booking', {
    type: 'plugin',
    name: 'fib-booking',
    title: 'fibBooking.module.title',
    description: 'fibBooking.module.description',
    color: '#6366f1',
    icon: 'regular-calendar',

    routes: {
        list: {
            component: 'fib-booking-resource-list',
            path: 'list',
        },
        seating: {
            component: 'fib-booking-resource-seating',
            path: 'seating/:id',
            meta: {
                parentPath: 'fib.booking.list',
            },
        },
        seatEditor: {
            component: 'fib-booking-seat-editor',
            path: 'seat-editor/:id',
            meta: {
                parentPath: 'fib.booking.list',
            },
        },
    },

    navigation: [
        {
            id: 'fib-booking-resources',
            label: 'fibBooking.module.title',
            color: '#6366f1',
            path: 'fib.booking.list',
            icon: 'regular-calendar',
            parent: 'sw-catalogue',
            position: 100,
        },
    ],
});
