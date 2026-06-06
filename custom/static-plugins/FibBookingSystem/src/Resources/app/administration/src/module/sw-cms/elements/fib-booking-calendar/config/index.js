import template from './sw-cms-el-config-fib-booking-calendar.html.twig';

const { Mixin } = Shopware;

Shopware.Component.register('sw-cms-el-config-fib-booking-calendar', {
    template,

    mixins: [
        Mixin.getByName('cms-element'),
    ],

    computed: {
        resourceId: {
            get() {
                return this.element?.config?.resourceId?.value ?? null;
            },
            set(value) {
                this.element.config.resourceId.value = value;
                this.$emit('element-update', this.element);
            },
        },

        monthsAhead: {
            get() {
                return this.element?.config?.monthsAhead?.value ?? 3;
            },
            set(value) {
                this.element.config.monthsAhead.value = value;
                this.$emit('element-update', this.element);
            },
        },
    },

    created() {
        this.initElementConfig('fib-booking-calendar');
    },
});
