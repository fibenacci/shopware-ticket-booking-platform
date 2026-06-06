/**
 * Pure selection logic — unit-tested without the component.
 */

/**
 * Toggle a seat in the selection, capped at maxSeats. Returns a NEW array.
 * Selecting beyond the cap replaces nothing — the click is ignored (the
 * component surfaces the cap via UI state instead).
 */
export function toggleSeat(selection, seatId, maxSeats) {
    if (selection.includes(seatId)) {
        return selection.filter((id) => id !== seatId);
    }

    if (maxSeats > 0 && selection.length >= maxSeats) {
        return selection;
    }

    return [...selection, seatId];
}

/**
 * Reconciles the local selection with a fresh server seatmap: seats that
 * are no longer free (someone else held/bought them) drop out.
 *
 * @returns {{selection: string[], lost: string[]}} lost = labels for the user notice
 */
export function reconcileSelection(seats, selection) {
    const byId = new Map(seats.map((seat) => [seat.id, seat]));

    const kept = [];
    const lost = [];

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
export function groupRows(seats) {
    const rows = new Map();

    for (const seat of seats) {
        if (!rows.has(seat.y)) {
            rows.set(seat.y, []);
        }
        rows.get(seat.y).push(seat);
    }

    return [...rows.entries()]
        .sort(([a], [b]) => a - b)
        .map(([, rowSeats]) => rowSeats.sort((a, b) => a.x - b.x));
}
