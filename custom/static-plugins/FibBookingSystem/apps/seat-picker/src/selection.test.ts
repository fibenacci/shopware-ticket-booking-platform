import { describe, expect, it } from 'vitest';
import { categoryColors, fitContentBox, groupRows, reconcileSelection, seatFill, toggleSeat } from './selection';
import type { Seat, SeatState, SeatmapLayout } from './selection';

const seat = (
    id: string,
    state: SeatState = 'free',
    row = 'A',
    label = '1',
    x = 1,
    y = 1,
): Seat => ({ id, state, row, label, x, y, rotation: 0, category: null });

describe('categoryColors', () => {
    it('maps category keys to colours, skipping colourless ones', () => {
        const map = categoryColors([
            { key: 'vip', name: 'VIP', color: '#f0a' },
            { key: 'plain', name: 'Plain', color: null },
        ]);
        expect(map).toEqual({ vip: '#f0a' });
    });
});

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

describe('fitContentBox', () => {
    const layout = (
        elements: Array<{ x: number; y: number; width: number; height: number }> = [],
        canvas = { width: 1000, height: 700 },
    ): SeatmapLayout => ({
        canvas,
        elements: elements.map((el) => ({ id: 'e', type: 'rect', rotation: 0, label: '', color: null, ...el })),
        categories: [],
    });

    it('falls back to the canvas when there is no content', () => {
        expect(fitContentBox([], layout())).toEqual({ minX: 0, minY: 0, width: 1000, height: 700 });
    });

    it('falls back to a default when there is no layout', () => {
        expect(fitContentBox([seat('s')], null)).toEqual({ minX: 0, minY: 0, width: 1000, height: 700 });
    });

    it('tightly bounds seats with padding', () => {
        const seats = [
            { ...seat('a'), x: 100, y: 100 },
            { ...seat('b'), x: 300, y: 200 },
        ];
        // x: 100-15..300+15 = 85..315, pad 30 → minX 55, width 315-55+30=290
        const box = fitContentBox(seats, layout(), 30, 15);
        expect(box).toEqual({ minX: 55, minY: 55, width: 290, height: 190 });
    });

    it('includes decoration elements in the bounds', () => {
        const box = fitContentBox([], layout([{ x: 200, y: 20, width: 600, height: 40 }]), 10, 15);
        // x: 200..800, pad 10 → minX 190, width 800-190+10=620
        expect(box.minX).toBe(190);
        expect(box.width).toBe(620);
    });
});

describe('seatFill', () => {
    const colors = { vip: '#f0a' };

    it('prioritises selection, then sold/held, then category, then free', () => {
        expect(seatFill(seat('s', 'free'), true, colors)).toBe('#2563eb');
        expect(seatFill(seat('s', 'sold'), false, colors)).toBe('#dc2626');
        expect(seatFill(seat('s', 'held'), false, colors)).toBe('#f59e0b');
        expect(seatFill({ ...seat('s', 'free'), category: 'vip' }, false, colors)).toBe('#f0a');
        expect(seatFill(seat('s', 'free'), false, colors)).toBe('#22c55e');
    });

    it('sold/held override the category colour', () => {
        expect(seatFill({ ...seat('s', 'sold'), category: 'vip' }, false, colors)).toBe('#dc2626');
    });
});
