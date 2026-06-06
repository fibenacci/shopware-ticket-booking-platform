import './component';
import './preview';

/**
 * CMS block wrapping the booking-calendar element so operators can drag it
 * straight into any section from the block sidebar (category "commerce").
 */
Shopware.Service('cmsService').registerCmsBlock({
    name: 'fib-booking-calendar',
    category: 'commerce',
    label: 'fib-booking.cms.blocks.calendar.label',
    component: 'sw-cms-block-fib-booking-calendar',
    previewComponent: 'sw-cms-preview-fib-booking-calendar',
    defaultConfig: {
        marginBottom: '20px',
        marginTop: '20px',
        marginLeft: '20px',
        marginRight: '20px',
        sizingMode: 'boxed',
    },
    slots: {
        calendar: 'fib-booking-calendar',
    },
});
