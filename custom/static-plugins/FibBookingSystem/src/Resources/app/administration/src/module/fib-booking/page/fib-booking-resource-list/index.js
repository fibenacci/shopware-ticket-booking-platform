import template from './fib-booking-resource-list.html.twig';

const { Component, Context } = Shopware;
const { Criteria } = Shopware.Data;

/**
 * Booking resources overview — entry point to the seat-map editor.
 */
Component.register('fib-booking-resource-list', {
    template,

    inject: ['repositoryFactory'],

    data() {
        return {
            resources: null,
            isLoading: true,
        };
    },

    computed: {
        resourceRepository() {
            return this.repositoryFactory.create('fib_booking_resource');
        },

        columns() {
            return [
                { property: 'name', label: this.$tc('fibBooking.list.columnName'), routerLink: 'fib.booking.seating', primary: true },
                { property: 'technicalName', label: this.$tc('fibBooking.list.columnTechnicalName') },
                { property: 'seatingMode', label: this.$tc('fibBooking.list.columnSeatingMode') },
                { property: 'capacity', label: this.$tc('fibBooking.list.columnCapacity') },
                { property: 'active', label: this.$tc('fibBooking.list.columnActive') },
            ];
        },
    },

    created() {
        this.loadResources();
    },

    methods: {
        async loadResources() {
            this.isLoading = true;

            try {
                const criteria = new Criteria(1, 100);
                criteria.addSorting(Criteria.sort('name', 'ASC'));
                this.resources = await this.resourceRepository.search(criteria, Context.api);
            } finally {
                this.isLoading = false;
            }
        },
    },
});
