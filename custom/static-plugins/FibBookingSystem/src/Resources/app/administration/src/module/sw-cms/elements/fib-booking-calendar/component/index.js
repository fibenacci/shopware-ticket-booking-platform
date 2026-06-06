import template from './sw-cms-el-fib-booking-calendar.html.twig';

const { Mixin } = Shopware;

Shopware.Component.register('sw-cms-el-fib-booking-calendar', {
    template,

    mixins: [
        Mixin.getByName('cms-element'),
    ],

    computed: {
        hasResource() {
            return !!this.element?.config?.resourceId?.value;
        },
    },

    created() {
        this.initElementConfig('fib-booking-calendar');
    },
});
