import template from './fib-booking-resource-seating.html.twig';
import './fib-booking-resource-seating.scss';
import { planSeatGrid } from '../../grid-generator';

const { Component, Context, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

/**
 * Seat-map editor for one booking resource (docs/SEATING_PLAN.md phase 4):
 *
 * - grid generator (rows × seats per row) — regenerating UPSERTS by
 *   row/label and deactivates out-of-grid seats, never deletes (claims of
 *   sold tickets reference seat rows)
 * - click a seat to toggle active (pillars, wheelchair spaces, broken seats)
 * - switches the resource between pool and seatmap mode
 */
Component.register('fib-booking-resource-seating', {
    template,

    inject: ['repositoryFactory'],

    mixins: [
        Mixin.getByName('notification'),
    ],

    data() {
        return {
            resource: null,
            seats: [],
            isLoading: true,
            isSaving: false,
            generatorRows: 5,
            generatorSeatsPerRow: 8,
        };
    },

    computed: {
        resourceRepository() {
            return this.repositoryFactory.create('fib_booking_resource');
        },

        seatRepository() {
            return this.repositoryFactory.create('fib_booking_seat');
        },

        resourceId() {
            return this.$route.params.id;
        },

        isSeatmap() {
            return this.resource?.seatingMode === 'seatmap';
        },

        seatingModeOptions() {
            return [
                { value: 'pool', label: this.$tc('fibBooking.list.modePool') },
                { value: 'seatmap', label: this.$tc('fibBooking.list.modeSeatmap') },
            ];
        },

        /** Seats grouped into render rows by posY, ordered by posX. */
        seatRows() {
            const rows = new Map();
            for (const seat of this.seats) {
                if (!rows.has(seat.posY)) {
                    rows.set(seat.posY, []);
                }
                rows.get(seat.posY).push(seat);
            }

            return [...rows.entries()]
                .sort(([a], [b]) => a - b)
                .map(([, rowSeats]) => [...rowSeats].sort((a, b) => a.posX - b.posX));
        },

        activeSeatCount() {
            return this.seats.filter((seat) => seat.active).length;
        },
    },

    created() {
        this.loadAll();
    },

    methods: {
        async loadAll() {
            this.isLoading = true;

            try {
                this.resource = await this.resourceRepository.get(this.resourceId, Context.api);
                await this.loadSeats();
            } finally {
                this.isLoading = false;
            }
        },

        async loadSeats() {
            const criteria = new Criteria(1, 500);
            criteria.addFilter(Criteria.equals('resourceId', this.resourceId));
            criteria.addSorting(Criteria.sort('posY', 'ASC'));
            criteria.addSorting(Criteria.sort('posX', 'ASC'));

            const result = await this.seatRepository.search(criteria, Context.api);
            this.seats = [...result];
        },

        toggleSeat(seat) {
            seat.active = !seat.active;
        },

        async generateGrid() {
            const plan = planSeatGrid(this.seats, this.generatorRows, this.generatorSeatsPerRow);

            this.isSaving = true;
            try {
                const entities = [];

                for (const upsert of plan.upserts) {
                    const entity = upsert.id
                        ? this.seats.find((seat) => seat.id === upsert.id)
                        : this.seatRepository.create(Context.api);

                    Object.assign(entity, {
                        resourceId: this.resourceId,
                        rowLabel: upsert.rowLabel,
                        seatLabel: upsert.seatLabel,
                        posX: upsert.posX,
                        posY: upsert.posY,
                        active: upsert.active,
                    });
                    entities.push(entity);
                }

                for (const seatId of plan.deactivateIds) {
                    const entity = this.seats.find((seat) => seat.id === seatId);
                    if (entity) {
                        entity.active = false;
                        entities.push(entity);
                    }
                }

                for (const entity of entities) {
                    await this.seatRepository.save(entity, Context.api);
                }

                await this.loadSeats();
                this.createNotificationSuccess({
                    message: this.$tc('fibBooking.seating.notification.generated'),
                });
            } catch {
                this.createNotificationError({
                    message: this.$tc('fibBooking.seating.notification.error'),
                });
            } finally {
                this.isSaving = false;
            }
        },

        async onSave() {
            this.isSaving = true;

            try {
                await this.resourceRepository.save(this.resource, Context.api);

                for (const seat of this.seats) {
                    await this.seatRepository.save(seat, Context.api);
                }

                await this.loadAll();
                this.createNotificationSuccess({
                    message: this.$tc('fibBooking.seating.notification.saved'),
                });
            } catch {
                this.createNotificationError({
                    message: this.$tc('fibBooking.seating.notification.error'),
                });
            } finally {
                this.isSaving = false;
            }
        },
    },
});
