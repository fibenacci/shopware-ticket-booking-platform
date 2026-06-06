import { describe, expect, it } from 'vitest';
import { groupRows, reconcileSelection, toggleSeat } from './selection';
import type { Seat, SeatState } from './selection';

const seat = (
    id: string,
    state: SeatState = 'free',
    row = 'A',
    label = '1',
    x = 1,
    y = 1,
): Seat => ({ id, state, row, label, x, y, category: null });

describe('toggleSeat', () => {
    it('adds and removes a seat', () => {
        const once = toggleSeat([], 's1', 0);
        expect(once).toEqual(['s1']);
        expect(toggleSeat(once, 's1', 0)).toEqual([]);
    });

    it('enforces the cap by ignoring further picks', () => {
        const selection = ['s1', 's2'];
        expect(toggleSeat(selection, 's3', 2)).toBe(selection);
    });

    it('always allows deselecting, even at the cap', () => {
        expect(toggleSeat(['s1', 's2'], 's2', 2)).toEqual(['s1']);
    });

    it('treats 0 as unlimited', () => {
        expect(toggleSeat(['s1', 's2', 's3'], 's4', 0)).toHaveLength(4);
    });
});

describe('reconcileSelection', () => {
    it('keeps seats that are still free', () => {
        const result = reconcileSelection([seat('s1'), seat('s2')], ['s1', 's2']);
        expect(result.selection).toEqual(['s1', 's2']);
        expect(result.lost).toEqual([]);
    });

    it('drops seats lost to other buyers and reports their labels', () => {
        const result = reconcileSelection(
            [seat('s1'), seat('s2', 'held', 'F', '7')],
            ['s1', 's2'],
        );
        expect(result.selection).toEqual(['s1']);
        expect(result.lost).toEqual(['F7']);
    });

    it('drops seats that vanished from the map entirely', () => {
        const result = reconcileSelection([seat('s1')], ['s1', 'ghost']);
        expect(result.selection).toEqual(['s1']);
        expect(result.lost).toEqual([]);
    });
});

describe('groupRows', () => {
    it('groups by y ascending and sorts by x within a row', () => {
        const rows = groupRows([
            seat('b2', 'free', 'B', '2', 2, 2),
            seat('a2', 'free', 'A', '2', 2, 1),
            seat('a1', 'free', 'A', '1', 1, 1),
            seat('b1', 'free', 'B', '1', 1, 2),
        ]);

        expect(rows.map((row) => row.map((s) => s.id))).toEqual([
            ['a1', 'a2'],
            ['b1', 'b2'],
        ]);
    });
});
