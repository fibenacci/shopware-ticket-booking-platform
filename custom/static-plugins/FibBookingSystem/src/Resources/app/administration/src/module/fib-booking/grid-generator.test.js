import { describe, expect, it } from 'vitest';
import { planSeatGrid, rowLabelFor } from './grid-generator.js';

const seat = (id, rowLabel, seatLabel, active = true) => ({ id, rowLabel, seatLabel, active });

describe('rowLabelFor', () => {
    it('maps indices to letters', () => {
        expect(rowLabelFor(0)).toBe('A');
        expect(rowLabelFor(25)).toBe('Z');
    });
});

describe('planSeatGrid', () => {
    it('creates a fresh grid with coordinates', () => {
        const { upserts, deactivateIds } = planSeatGrid([], 2, 3);

        expect(upserts).toHaveLength(6);
        expect(deactivateIds).toEqual([]);
        expect(upserts[0]).toMatchObject({ rowLabel: 'A', seatLabel: '1', posX: 1, posY: 1, active: true });
        expect(upserts[5]).toMatchObject({ rowLabel: 'B', seatLabel: '3', posX: 3, posY: 2 });
        expect(upserts[0].id).toBeUndefined();
    });

    it('reuses existing seat ids so claims survive regeneration', () => {
        const { upserts } = planSeatGrid([seat('s-a1', 'A', '1')], 1, 2);

        expect(upserts[0]).toMatchObject({ id: 's-a1', rowLabel: 'A', seatLabel: '1', active: true });
        expect(upserts[1].id).toBeUndefined();
    });

    it('reactivates previously deactivated seats inside the grid', () => {
        const { upserts } = planSeatGrid([seat('s-a1', 'A', '1', false)], 1, 1);

        expect(upserts[0]).toMatchObject({ id: 's-a1', active: true });
    });

    it('deactivates seats that fall outside the new grid instead of deleting', () => {
        const existing = [seat('s-a1', 'A', '1'), seat('s-c9', 'C', '9'), seat('s-d1', 'D', '1', false)];

        const { deactivateIds } = planSeatGrid(existing, 1, 1);

        expect(deactivateIds).toEqual(['s-c9']);
    });

    it('clamps rows to the supported maximum and floors fractions', () => {
        const { upserts } = planSeatGrid([], 99.9, 1.9);

        expect(upserts).toHaveLength(26);
        expect(upserts.at(-1).rowLabel).toBe('Z');
    });
});
