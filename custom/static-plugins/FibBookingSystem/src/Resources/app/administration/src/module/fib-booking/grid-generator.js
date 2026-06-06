/**
 * Pure seat-grid planning for the admin generator (docs/SEATING_PLAN.md).
 *
 * Regenerating a room must NEVER delete seats: sold/held claims reference
 * them (FK CASCADE would erase booking history). Instead the plan
 * - upserts every in-grid position (reusing the existing seat's id so its
 *   claims survive),
 * - deactivates seats that fall outside the new grid.
 *
 * Row labels are A, B, C, … (capped at 26 rows — beyond that you want the
 * future free-form editor anyway).
 */

export const MAX_ROWS = 26;

export function rowLabelFor(rowIndex) {
    return String.fromCharCode('A'.charCodeAt(0) + rowIndex);
}

/**
 * @param {Array<{id: string, rowLabel: string, seatLabel: string, active: boolean}>} existingSeats
 * @param {number} rows
 * @param {number} seatsPerRow
 * @returns {{upserts: Array<object>, deactivateIds: string[]}}
 */
export function planSeatGrid(existingSeats, rows, seatsPerRow) {
    const safeRows = Math.max(1, Math.min(MAX_ROWS, Math.floor(rows) || 1));
    const safeSeats = Math.max(1, Math.floor(seatsPerRow) || 1);

    const byPosition = new Map(
        existingSeats.map((seat) => [`${seat.rowLabel}:${seat.seatLabel}`, seat]),
    );

    const upserts = [];
    const inGrid = new Set();

    for (let row = 0; row < safeRows; row++) {
        const rowLabel = rowLabelFor(row);

        for (let seat = 1; seat <= safeSeats; seat++) {
            const seatLabel = String(seat);
            const key = `${rowLabel}:${seatLabel}`;
            inGrid.add(key);

            const existing = byPosition.get(key);
            upserts.push({
                ...(existing ? { id: existing.id } : {}),
                rowLabel,
                seatLabel,
                posX: seat,
                posY: row + 1,
                active: true,
            });
        }
    }

    const deactivateIds = existingSeats
        .filter((seat) => seat.active && !inGrid.has(`${seat.rowLabel}:${seat.seatLabel}`))
        .map((seat) => seat.id);

    return { upserts, deactivateIds };
}
