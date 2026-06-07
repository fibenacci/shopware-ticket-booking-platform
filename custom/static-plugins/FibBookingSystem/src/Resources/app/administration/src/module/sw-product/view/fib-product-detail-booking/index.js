import template from './fib-product-detail-booking.html.twig';
import {
    CUSTOM_PRESET,
    DURATION_PRESETS,
    durationForPreset,
    isValidIsoDuration,
    presetForDuration,
} from '../../fib-booking/validity-presets';

const { Component, Context, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

/**
 * Product tab "Booking & Tickets": manages the fib_booking_product_config
 * row for this product. One generic model spans every ticket kind
 * (see plugin docs/TICKET_TYPES.md):
 *
 *   ticket type = validity mode (slot | period | unlimited)
 *               × entry policy (single | multi)
 *
 * Day/month/3-month/year passes are presets that only pre-fill the ISO-8601
 * duration — everything stays freely configurable.
 */
Component.register('fib-product-detail-booking', {
    template,

    inject: ['repositoryFactory'],

    mixins: [
        Mixin.getByName('notification'),
    ],

    data() {
        return {
            config: null,
            isLoading: true,
            isSaving: false,
            durationPreset: CUSTOM_PRESET,
        };
    },

    computed: {
        configRepository() {
            return this.repositoryFactory.create('fib_booking_product_config');
        },

        productId() {
            return this.$route.params.id;
        },

        validityModeOptions() {
            return ['slot', 'period', 'unlimited'].map((value) => ({
                value,
                label: this.$tc(`fibBooking.product.validityMode.${value}`),
            }));
        },

        validityAnchorOptions() {
            return ['purchase', 'first_use', 'customer'].map((value) => ({
                value,
                label: this.$tc(`fibBooking.product.validityAnchor.${value}`),
            }));
        },

        entryPolicyOptions() {
            return ['single', 'multi'].map((value) => ({
                value,
                label: this.$tc(`fibBooking.product.entryPolicy.${value}`),
            }));
        },

        durationPresetOptions() {
            const presets = DURATION_PRESETS.map(({ value }) => ({
                value,
                label: this.$tc(`fibBooking.product.presets.${value}`),
            }));

            return [...presets, { value: CUSTOM_PRESET, label: this.$tc('fibBooking.product.presets.custom') }];
        },

        isPeriodMode() {
            return this.config?.validityMode === 'period';
        },

        isMultiEntry() {
            return this.config?.entryPolicy === 'multi';
        },

        isSlotMode() {
            return !this.config || this.config.validityMode === 'slot';
        },

        durationIsInvalid() {
            return this.isPeriodMode && !isValidIsoDuration(this.config?.validityDuration);
        },
    },

    created() {
        this.loadConfig();
    },

    methods: {
        async loadConfig() {
            this.isLoading = true;

            try {
                const criteria = new Criteria(1, 1);
                criteria.addFilter(Criteria.equals('productId', this.productId));

                const result = await this.configRepository.search(criteria, Context.api);
                this.config = result.first() ?? this.createNewConfig();
                this.durationPreset = presetForDuration(this.config.validityDuration);
            } finally {
                this.isLoading = false;
            }
        },

        createNewConfig() {
            const config = this.configRepository.create(Context.api);
            config.productId = this.productId;
            config.productVersionId = Context.api.liveVersionId;
            config.enabled = false;
            config.slotMinutes = 60;
            config.validityMode = 'slot';
            config.entryPolicy = 'single';
            config.rotatingQrEnabled = false;

            return config;
        },

        onPresetChange(presetValue) {
            this.durationPreset = presetValue;

            const duration = durationForPreset(presetValue);
            if (duration !== null) {
                this.config.validityDuration = duration;
            }
        },

        onDurationChange(value) {
            this.durationPreset = presetForDuration(value);
        },

        async onSave() {
            if (this.durationIsInvalid) {
                this.createNotificationError({
                    message: this.$tc('fibBooking.product.notification.invalidDuration'),
                });

                return;
            }

            this.isSaving = true;

            try {
                await this.configRepository.save(this.config, Context.api);
                await this.loadConfig();
                this.createNotificationSuccess({
                    message: this.$tc('fibBooking.product.notification.saved'),
                });
            } catch {
                this.createNotificationError({
                    message: this.$tc('fibBooking.product.notification.saveError'),
                });
            } finally {
                this.isSaving = false;
            }
        },
    },
});
