import { describe, expect, it } from 'vitest';
import {
    rowLabelFor,
    generateStraightRow,
    generateArcRow,
    createElement,
    serializeLayout,
    planSeatLayout,
} from './seat-layout.js';

const seat = (id, rowLabel, seatLabel, active = true) => ({ id, rowLabel, seatLabel, active });

describe('rowLabelFor', () => {
    it('maps indices to letters then double letters', () => {
        expect(rowLabelFor(0)).toBe('A');
        expect(rowLabelFor(25)).toBe('Z');
        expect(rowLabelFor(26)).toBe('AA');
        expect(rowLabelFor(27)).toBe('AB');
    });
});

describe('generateStraightRow', () => {
    it('places seats left→right with no rotation by default', () => {
        const seats = generateStraightRow({ rowLabel: 'A', count: 3, startX: 100, startY: 50, spacing: 30 });

        expect(seats).toHaveLength(3);
        expect(seats[0]).toMatchObject({ rowLabel: 'A', seatLabel: '1', posX: 100, posY: 50, rotation: 0 });
        expect(seats[1]).toMatchObject({ seatLabel: '2', posX: 130, posY: 50 });
        expect(seats[2]).toMatchObject({ seatLabel: '3', posX: 160, posY: 50 });
    });

    it('rotates the whole row and stamps the angle on each seat', () => {
        const seats = generateStraightRow({ rowLabel: 'B', count: 2, startX: 0, startY: 0, spacing: 100, angleDeg: 90 });

        expect(seats[0]).toMatchObject({ posX: 0, posY: 0, rotation: 90 });
        // 90° → straight down
        expect(seats[1]).toMatchObject({ posX: 0, posY: 100, rotation: 90 });
    });

    it('honours a custom start number', () => {
        const seats = generateStraightRow({ rowLabel: 'A', count: 2, startX: 0, startY: 0, startNumber: 5 });
        expect(seats.map((s) => s.seatLabel)).toEqual(['5', '6']);
    });
});

describe('generateArcRow', () => {
    it('spreads seats along an arc and faces them toward the centre', () => {
        const seats = generateArcRow({
            rowLabel: 'C',
            count: 3,
            centerX: 0,
            centerY: 0,
            radius: 100,
            startAngleDeg: 0,
            endAngleDeg: 180,
        });

        expect(seats).toHaveLength(3);
        // start (0°) → right of centre
        expect(seats[0]).toMatchObject({ posX: 100, posY: 0 });
        // middle (90°) → below centre
        expect(seats[1].posX).toBe(0);
        expect(seats[1].posY).toBe(100);
        // end (180°) → left of centre
        expect(seats[2].posX).toBe(-100);
        // facing the centre from the right means looking left (≈180°)
        expect(seats[0].rotation).toBe(180);
    });

    it('keeps a single seat at the start angle', () => {
        const [only] = generateArcRow({
            rowLabel: 'C', count: 1, centerX: 0, centerY: 0, radius: 50, startAngleDeg: 0, endAngleDeg: 180,
        });
        expect(only).toMatchObject({ posX: 50, posY: 0 });
    });
});

describe('createElement', () => {
    it('builds a decoration with sane defaults and an id', () => {
        const el = createElement('column', { x: 10, y: 20 });
        expect(el).toMatchObject({ type: 'column', x: 10, y: 20, width: 80, height: 40, rotation: 0 });
        expect(el.id).toBeTruthy();
    });

    it('keeps polygon points', () => {
        const el = createElement('polygon', { points: [[0, 0], [10, 0], [5, 10]] });
        expect(el.points).toHaveLength(3);
    });

    it('rejects unknown types', () => {
        expect(() => createElement('teleporter')).toThrow();
    });
});

describe('serializeLayout', () => {
    it('normalizes canvas, elements and categories', () => {
        const layout = serializeLayout({
            canvas: { width: 800.4, height: 600.6 },
            elements: [{ type: 'stage', x: 1, y: 2, label: 'STAGE' }],
            categories: [{ key: 'vip', name: 'VIP', color: '#f0a' }],
        });

        expect(layout.canvas).toEqual({ width: 800, height: 601 });
        expect(layout.elements[0]).toMatchObject({ type: 'stage', label: 'STAGE' });
        expect(layout.categories[0]).toEqual({ key: 'vip', name: 'VIP', color: '#f0a' });
    });
});

describe('planSeatLayout', () => {
    it('reuses ids by row:label and deactivates seats no longer present', () => {
        const existing = [seat('s-a1', 'A', '1'), seat('s-a2', 'A', '2')];
        const editorSeats = [
            { rowLabel: 'A', seatLabel: '1', posX: 5, posY: 5, rotation: 0, category: 'vip' },
            { rowLabel: 'A', seatLabel: '3', posX: 60, posY: 5, rotation: 15, category: null },
        ];

        const { upserts, deactivateIds } = planSeatLayout(existing, editorSeats);

        expect(upserts[0]).toMatchObject({ id: 's-a1', seatLabel: '1', category: 'vip', active: true });
        expect(upserts[1].id).toBeUndefined();
        expect(upserts[1]).toMatchObject({ seatLabel: '3', rotation: 15 });
        // A2 dropped out of the layout → deactivated, never deleted.
        expect(deactivateIds).toEqual(['s-a2']);
    });
});
