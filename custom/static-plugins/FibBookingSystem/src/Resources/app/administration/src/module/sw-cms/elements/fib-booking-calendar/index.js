import './component';
import './config';
import './preview';

/**
 * CMS element "fib-booking-calendar" — the booking calendar ("Terminkalender").
 * Operators place it on any shopping-experience layout and pick the booking
 * resource whose slots it should display.
 */
Shopware.Service('cmsService').registerCmsElement({
    name: 'fib-booking-calendar',
    label: 'fib-booking.cms.elements.calendar.label',
    component: 'sw-cms-el-fib-booking-calendar',
    configComponent: 'sw-cms-el-config-fib-booking-calendar',
    previewComponent: 'sw-cms-el-preview-fib-booking-calendar',
    defaultConfig: {
        resourceId: {
            source: 'static',
            value: null,
        },
        monthsAhead: {
            source: 'static',
            value: 3,
        },
    },
});
