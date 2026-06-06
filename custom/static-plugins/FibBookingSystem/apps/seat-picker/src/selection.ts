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
    category: string | null;
    state: SeatState;
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
