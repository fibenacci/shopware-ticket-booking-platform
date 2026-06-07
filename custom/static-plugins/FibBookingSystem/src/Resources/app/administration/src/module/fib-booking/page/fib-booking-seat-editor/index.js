import template from './fib-booking-seat-editor.html.twig';
import './fib-booking-seat-editor.scss';
import {
    SEAT_SIZE,
    rowLabelFor,
    generateStraightRow,
    generateArcRow,
    createElement,
    serializeLayout,
    planSeatLayout,
} from '../../seat-layout';

const { Component, Context, Mixin } = Shopware;
const { Criteria } = Shopware.Data;

/**
 * Free-form 2D seat-map editor (docs/SEAT_EDITOR.md) — the optional rich
 * variant for irregular venues. The operator draws seat rows (straight,
 * rotated or curved), places blockers (aisle/column/wall/stage), free-form
 * shapes and labels, and groups seats into colour categories.
 *
 * Persistence keeps the booking model intact: seats are upserted as DB rows
 * (ids reused by row:label so claims survive, dropped seats deactivated),
 * decorations + canvas + categories go into resource.layout JSON. All the
 * geometry maths lives in the pure ../seat-layout module.
 */
Component.register('fib-booking-seat-editor', {
    template,

    inject: ['repositoryFactory'],

    mixins: [Mixin.getByName('notification')],

    data() {
        return {
            resource: null,
            existingSeats: [],
            // editor state
            seats: [],        // {key,rowLabel,seatLabel,posX,posY,rotation,category,active}
            elements: [],     // decorations
            categories: [],   // {key,name,color}
            canvas: { width: 1000, height: 700 },
            tool: 'select',
            selectedKey: null,
            activeCategory: null,
            isLoading: true,
            isSaving: false,
            // generation forms
            rowForm: { rowLabel: '', count: 10, spacing: SEAT_SIZE + 8, angleDeg: 0 },
            arcForm: { rowLabel: '', count: 12, radius: 240, startAngleDeg: 200, endAngleDeg: 340 },
            // drag
            drag: null,
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
        activeSeatCount() {
            return this.seats.length;
        },
        nextRowLabel() {
            return rowLabelFor(new Set(this.seats.map((s) => s.rowLabel)).size);
        },
        categoryColor() {
            const map = {};
            for (const c of this.categories) {
                map[c.key] = c.color;
            }
            return map;
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

                // 500 is the DAL search MAX_LIMIT; rooms larger than that
                // page through. Sorted so the page boundary is stable.
                const criteria = new Criteria(1, 500);
                criteria.addFilter(Criteria.equals('resourceId', this.resourceId));
                criteria.addSorting(Criteria.sort('posY', 'ASC'));
                criteria.addSorting(Criteria.sort('posX', 'ASC'));

                this.existingSeats = [];
                let page = 1;
                let pageResult;
                do {
                    criteria.setPage(page);
                    pageResult = await this.seatRepository.search(criteria, Context.api);
                    this.existingSeats.push(...pageResult);
                    page += 1;
                } while (pageResult.length === 500);

                // Hydrate editor seats from active rows.
                this.seats = this.existingSeats
                    .filter((s) => s.active)
                    .map((s) => ({
                        key: `${s.rowLabel}:${s.seatLabel}`,
                        rowLabel: s.rowLabel,
                        seatLabel: s.seatLabel,
                        posX: s.posX,
                        posY: s.posY,
                        rotation: s.rotation ?? 0,
                        category: s.category ?? null,
                    }));

                const layout = this.resource.layout ?? {};
                this.canvas = layout.canvas ?? this.canvas;
                this.elements = (layout.elements ?? []).map((el) => ({ ...el }));
                this.categories = layout.categories ?? [];
                this.rowForm.rowLabel = this.nextRowLabel;
                this.arcForm.rowLabel = this.nextRowLabel;
            } finally {
                this.isLoading = false;
            }
        },

        setTool(tool) {
            this.tool = tool;
            this.selectedKey = null;
        },

        // --- placement ----------------------------------------------------
        onCanvasClick(event) {
            if (this.tool === 'select') {
                this.selectedKey = null;
                return;
            }

            const { x, y } = this.toCanvasCoords(event);

            if (this.tool === 'seat') {
                this.placeSingleSeat(x, y);
            } else if (this.tool.startsWith('element:')) {
                this.placeElement(this.tool.slice('element:'.length), x, y);
            }
        },

        placeSingleSeat(x, y) {
            const rowLabel = this.rowForm.rowLabel || this.nextRowLabel;
            const inRow = this.seats.filter((s) => s.rowLabel === rowLabel);
            const seatLabel = String(inRow.length + 1);
            this.addSeats([{ rowLabel, seatLabel, posX: x, posY: y, rotation: 0, category: this.activeCategory }]);
        },

        generateStraight() {
            const seats = generateStraightRow({
                rowLabel: this.rowForm.rowLabel || this.nextRowLabel,
                count: this.rowForm.count,
                startX: this.canvas.width / 2 - ((this.rowForm.count - 1) * this.rowForm.spacing) / 2,
                startY: this.canvas.height / 2,
                spacing: this.rowForm.spacing,
                angleDeg: this.rowForm.angleDeg,
                category: this.activeCategory,
            });
            this.addSeats(seats);
            this.rowForm.rowLabel = this.nextRowLabel;
        },

        generateArc() {
            const seats = generateArcRow({
                rowLabel: this.arcForm.rowLabel || this.nextRowLabel,
                count: this.arcForm.count,
                centerX: this.canvas.width / 2,
                centerY: this.canvas.height / 2,
                radius: this.arcForm.radius,
                startAngleDeg: this.arcForm.startAngleDeg,
                endAngleDeg: this.arcForm.endAngleDeg,
                category: this.activeCategory,
            });
            this.addSeats(seats);
            this.arcForm.rowLabel = this.nextRowLabel;
        },

        addSeats(seats) {
            for (const s of seats) {
                const key = `${s.rowLabel}:${s.seatLabel}`;
                // Overwrite a same-key seat (regeneration of the same row).
                this.seats = this.seats.filter((x) => x.key !== key);
                this.seats.push({ ...s, key, category: s.category ?? null });
            }
        },

        placeElement(type, x, y) {
            this.elements.push(createElement(type, {
                x: x - 40,
                y: y - 20,
                label: type === 'stage' ? 'STAGE' : (type === 'label' ? 'Text' : ''),
                color: this.activeCategory ? this.categoryColor[this.activeCategory] : null,
            }));
        },

        // --- selection & drag --------------------------------------------
        selectSeat(seat, event) {
            event.stopPropagation();
            if (this.tool === 'delete') {
                this.seats = this.seats.filter((s) => s.key !== seat.key);
                return;
            }
            if (this.tool === 'category' && this.activeCategory) {
                seat.category = this.activeCategory;
                return;
            }
            this.selectedKey = `seat:${seat.key}`;
            this.startDrag(event, (dx, dy) => {
                seat.posX += dx;
                seat.posY += dy;
            });
        },

        selectElement(el, event) {
            event.stopPropagation();
            if (this.tool === 'delete') {
                this.elements = this.elements.filter((e) => e.id !== el.id);
                return;
            }
            this.selectedKey = `el:${el.id}`;
            this.startDrag(event, (dx, dy) => {
                el.x += dx;
                el.y += dy;
            });
        },

        startDrag(event, apply) {
            const scale = this.canvasScale();
            let last = { x: event.clientX, y: event.clientY };
            const move = (e) => {
                apply(Math.round((e.clientX - last.x) / scale), Math.round((e.clientY - last.y) / scale));
                last = { x: e.clientX, y: e.clientY };
            };
            const up = () => {
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', up);
            };
            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', up);
        },

        // --- categories ---------------------------------------------------
        addCategory() {
            const key = `cat-${this.categories.length + 1}`;
            const palette = ['#6366f1', '#ec4899', '#f59e0b', '#10b981', '#8b5cf6'];
            this.categories.push({ key, name: `Category ${this.categories.length + 1}`, color: palette[this.categories.length % palette.length] });
            this.activeCategory = key;
        },

        removeElement(id) {
            this.elements = this.elements.filter((e) => e.id !== id);
        },

        // --- coordinate helpers ------------------------------------------
        canvasScale() {
            const svg = this.$refs.canvas;
            if (!svg) return 1;
            return svg.getBoundingClientRect().width / this.canvas.width;
        },

        toCanvasCoords(event) {
            const svg = this.$refs.canvas;
            const rect = svg.getBoundingClientRect();
            const scale = rect.width / this.canvas.width;
            return {
                x: Math.round((event.clientX - rect.left) / scale),
                y: Math.round((event.clientY - rect.top) / scale),
            };
        },

        // --- save ---------------------------------------------------------
        async onSave() {
            this.isSaving = true;
            try {
                const plan = planSeatLayout(this.existingSeats, this.seats);
                const entities = [];

                for (const upsert of plan.upserts) {
                    const entity = upsert.id
                        ? this.existingSeats.find((s) => s.id === upsert.id)
                        : this.seatRepository.create(Context.api);
                    Object.assign(entity, {
                        resourceId: this.resourceId,
                        rowLabel: upsert.rowLabel,
                        seatLabel: upsert.seatLabel,
                        posX: upsert.posX,
                        posY: upsert.posY,
                        rotation: upsert.rotation,
                        category: upsert.category,
                        active: true,
                    });
                    entities.push(entity);
                }
                for (const id of plan.deactivateIds) {
                    const entity = this.existingSeats.find((s) => s.id === id);
                    if (entity) {
                        entity.active = false;
                        entities.push(entity);
                    }
                }
                for (const entity of entities) {
                    await this.seatRepository.save(entity, Context.api);
                }

                // Resource: store the layout + keep capacity in sync with the
                // active seat count, and ensure seatmap mode.
                this.resource.layout = serializeLayout({
                    canvas: this.canvas,
                    elements: this.elements,
                    categories: this.categories,
                });
                this.resource.seatingMode = 'seatmap';
                this.resource.capacity = this.seats.length;
                await this.resourceRepository.save(this.resource, Context.api);

                await this.loadAll();
                this.createNotificationSuccess({ message: this.$tc('fibBooking.seatEditor.notification.saved') });
            } catch (e) {
                this.createNotificationError({ message: this.$tc('fibBooking.seatEditor.notification.error') });
            } finally {
                this.isSaving = false;
            }
        },
    },
});
