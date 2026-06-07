/**
 * Pure selection logic — unit-tested without the component.
 */

export type SeatState = 'free' | 'held' | 'sold';

export interface Seat {
    id: string;
    row: string;
    label: string;
    x: number;
    y: number;
    /** Seat angle (deg) for curved/rotated rows; 0 for plain grids. */
    rotation: number;
    category: string | null;
    state: SeatState;
}

/** A non-bookable decoration in the free-form layout. */
export interface LayoutElement {
    id: string;
    type: 'aisle' | 'column' | 'wall' | 'stage' | 'rect' | 'circle' | 'polygon' | 'label';
    x: number;
    y: number;
    width: number;
    height: number;
    rotation: number;
    label: string;
    color: string | null;
}

export interface SeatCategory {
    key: string;
    name: string;
    color: string | null;
}

/** Optional free-form layout; absent → the picker uses the plain grid. */
export interface SeatmapLayout {
    canvas: { width: number; height: number };
    elements: LayoutElement[];
    categories: SeatCategory[];
}

export interface ReconcileResult {
    selection: string[];
    /** Labels ("F7") of seats lost to other buyers — for the user notice. */
    lost: string[];
}

/**
 * Toggle a seat in the selection, capped at maxSeats. Returns a NEW array.
 * Selecting beyond the cap replaces nothing — the click is ignored (the
 * component surfaces the cap via UI state instead).
 */
export function toggleSeat(selection: readonly string[], seatId: string, maxSeats: number): string[] {
    if (selection.includes(seatId)) {
        return selection.filter((id) => id !== seatId);
    }

    if (maxSeats > 0 && selection.length >= maxSeats) {
        // Intentionally the SAME array reference: callers detect the
        // rejected pick via identity.
        return selection as string[];
    }

    return [...selection, seatId];
}

/**
 * Reconciles the local selection with a fresh server seatmap: seats that
 * are no longer free (someone else held/bought them) drop out.
 */
export function reconcileSelection(seats: readonly Seat[], selection: readonly string[]): ReconcileResult {
    const byId = new Map(seats.map((seat) => [seat.id, seat]));

    const kept: string[] = [];
    const lost: string[] = [];

    for (const seatId of selection) {
        const seat = byId.get(seatId);

        if (seat && seat.state === 'free') {
            kept.push(seatId);
        } else if (seat) {
            lost.push(`${seat.row}${seat.label}`);
        }
    }

    return { selection: kept, lost };
}

/**
 * Groups seats into render rows by their y coordinate (ascending), seats
 * ordered by x within the row.
 */
export function groupRows(seats: readonly Seat[]): Seat[][] {
    const rows = new Map<number, Seat[]>();

    for (const seat of seats) {
        const row = rows.get(seat.y);
        if (row) {
            row.push(seat);
        } else {
            rows.set(seat.y, [seat]);
        }
    }

    return [...rows.entries()]
        .sort(([a], [b]) => a - b)
        .map(([, rowSeats]) => rowSeats.sort((a, b) => a.x - b.x));
}

/**
 * Category key → colour lookup for rendering seats in the free-form layout.
 */
export function categoryColors(categories: readonly SeatCategory[]): Record<string, string> {
    const map: Record<string, string> = {};
    for (const category of categories) {
        if (category.color) {
            map[category.key] = category.color;
        }
    }
    return map;
}

export interface ContentBox {
    minX: number;
    minY: number;
    width: number;
    height: number;
}

/**
 * Bounding box of the rendered content (seats + decorations) with a margin,
 * so the SVG viewBox can fit the room tightly instead of floating it inside
 * the full canvas. Falls back to the canvas (or a default) when empty.
 */
export function fitContentBox(
    seats: readonly Seat[],
    layout: SeatmapLayout | null,
    pad = 30,
    seatHalf = 15,
): ContentBox {
    const fallback: ContentBox = layout
        ? { minX: 0, minY: 0, width: layout.canvas.width, height: layout.canvas.height }
        : { minX: 0, minY: 0, width: 1000, height: 700 };

    if (!layout) {
        return fallback;
    }

    const xs: number[] = [];
    const ys: number[] = [];

    for (const seat of seats) {
        xs.push(seat.x - seatHalf, seat.x + seatHalf);
        ys.push(seat.y - seatHalf, seat.y + seatHalf);
    }
    for (const el of layout.elements) {
        xs.push(el.x, el.x + el.width);
        ys.push(el.y, el.y + el.height);
    }

    if (xs.length === 0) {
        return fallback;
    }

    const minX = Math.min(...xs) - pad;
    const minY = Math.min(...ys) - pad;

    return {
        minX,
        minY,
        width: Math.max(...xs) - minX + pad,
        height: Math.max(...ys) - minY + pad,
    };
}

/**
 * Fill colour for a seat in the free-form SVG layout: selected wins, then
 * sold/held state, then the seat's category colour, else free-green.
 */
export function seatFill(
    seat: Seat,
    selected: boolean,
    categoryColorMap: Record<string, string>,
): string {
    if (selected) {
        return '#2563eb';
    }
    if (seat.state === 'sold') {
        return '#dc2626';
    }
    if (seat.state === 'held') {
        return '#f59e0b';
    }
    const categoryColor = seat.category ? categoryColorMap[seat.category] : undefined;
    if (categoryColor) {
        return categoryColor;
    }
    return '#22c55e';
}
