import './module/sw-cms/elements/fib-booking-calendar';
import './module/sw-cms/blocks/commerce/fib-booking-calendar';
import './module/sw-product';
import './module/fib-booking';

// ACL: make the booking privileges assignable via checkbox in the admin UI
// (Settings → Users & permissions → Roles → Additional permissions).
Shopware.Service('privileges').addPrivilegeMappingEntry({
    category: 'additional_permissions',
    parent: null,
    key: 'fib_booking',
    roles: {
        ticket_scan: {
            privileges: ['fib_booking.ticket_scan'],
            dependencies: [],
        },
        statistics: {
            privileges: ['fib_booking.statistics'],
            dependencies: [],
        },
    },
});

import deDE from './module/sw-cms/snippet/de-DE.json';
import enGB from './module/sw-cms/snippet/en-GB.json';

Shopware.Locale.extend('de-DE', deDE);
Shopware.Locale.extend('en-GB', enGB);
