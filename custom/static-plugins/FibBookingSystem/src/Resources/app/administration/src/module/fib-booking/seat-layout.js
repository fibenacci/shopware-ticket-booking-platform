/**
 * Pure geometry + serialization for the free-form seat-map editor
 * (docs/SEAT_EDITOR.md). No DOM, no framework — fully unit-tested; the Vue
 * component only does drag/selection on top of this.
 *
 * Design: ALL three geometries (straight, rotated, curved) are produced by
 * GENERATION here and collapse to flat seat points {x, y, rotation}. The
 * persisted model and the storefront picker never do curve maths — they
 * render points. Decorations (blocker/column/wall/stage/shape/label) and the
 * category palette live in the resource layout JSON; seats stay DB rows.
 *
 * Reuse-by-position (like the grid generator): regenerating reuses an
 * existing seat's id by `rowLabel:seatLabel`, so claims of sold tickets
 * survive; seats no longer in the layout are deactivated, never deleted.
 */

export const SEAT_SIZE = 26; // px box per seat on the canvas
export const ELEMENT_TYPES = ['aisle', 'column', 'wall', 'stage', 'rect', 'circle', 'polygon', 'label'];

export function rowLabelFor(rowIndex) {
    // A..Z, then AA, AB, … so large venues still get unique row labels.
    let label = '';
    let n = rowIndex;
    do {
        label = String.fromCharCode('A'.charCodeAt(0) + (n % 26)) + label;
        n = Math.floor(n / 26) - 1;
    } while (n >= 0);

    return label;
}

function round(value) {
    return Math.round(value);
}

/**
 * Straight (optionally rotated) row of seats.
 *
 * @param {object} opts
 * @param {string} opts.rowLabel
 * @param {number} opts.count
 * @param {number} opts.startX  canvas x of the first seat (centre)
 * @param {number} opts.startY  canvas y of the first seat (centre)
 * @param {number} [opts.spacing=SEAT_SIZE+8]  centre-to-centre distance
 * @param {number} [opts.angleDeg=0]  row direction; 0 = left→right, the seat
 *                                    rotation follows the row so a tilted
 *                                    tribune's seats face the right way
 * @param {string} [opts.category]
 * @param {number} [opts.startNumber=1]
 * @returns {Array<{rowLabel,seatLabel,posX,posY,rotation,category}>}
 */
export function generateStraightRow(opts) {
    const {
        rowLabel,
        count,
        startX,
        startY,
        spacing = SEAT_SIZE + 8,
        angleDeg = 0,
        category = null,
        startNumber = 1,
    } = opts;

    const n = Math.max(1, Math.floor(count) || 1);
    const rad = (angleDeg * Math.PI) / 180;
    const dx = Math.cos(rad) * spacing;
    const dy = Math.sin(rad) * spacing;
    const seats = [];

    for (let i = 0; i < n; i++) {
        seats.push({
            rowLabel,
            seatLabel: String(startNumber + i),
            posX: round(startX + dx * i),
            posY: round(startY + dy * i),
            rotation: round(angleDeg),
            category,
        });
    }

    return seats;
}

/**
 * Curved row of seats along a circular arc (stadium tier). Seats are spread
 * evenly between startAngle and endAngle on a circle of `radius` around
 * (centerX, centerY); each seat's rotation is the arc tangent so it faces the
 * centre — collapsed to a flat point, exactly like a straight row.
 *
 * @param {object} opts
 * @param {string} opts.rowLabel
 * @param {number} opts.count
 * @param {number} opts.centerX
 * @param {number} opts.centerY
 * @param {number} opts.radius
 * @param {number} opts.startAngleDeg
 * @param {number} opts.endAngleDeg
 * @param {string} [opts.category]
 * @param {number} [opts.startNumber=1]
 * @param {boolean} [opts.faceCenter=true]  seats look toward the centre
 * @returns {Array<{rowLabel,seatLabel,posX,posY,rotation,category}>}
 */
export function generateArcRow(opts) {
    const {
        rowLabel,
        count,
        centerX,
        centerY,
        radius,
        startAngleDeg,
        endAngleDeg,
        category = null,
        startNumber = 1,
        faceCenter = true,
    } = opts;

    const n = Math.max(1, Math.floor(count) || 1);
    const start = (startAngleDeg * Math.PI) / 180;
    const end = (endAngleDeg * Math.PI) / 180;
    const step = n === 1 ? 0 : (end - start) / (n - 1);
    const seats = [];

    for (let i = 0; i < n; i++) {
        const angle = start + step * i;
        const x = centerX + Math.cos(angle) * radius;
        const y = centerY + Math.sin(angle) * radius;
        // Facing the centre: the seat normal points inward (angle + 180°);
        // otherwise it points outward along the radius.
        const facing = faceCenter ? angle + Math.PI : angle;

        seats.push({
            rowLabel,
            seatLabel: String(startNumber + i),
            posX: round(x),
            posY: round(y),
            rotation: ((round((facing * 180) / Math.PI) % 360) + 360) % 360,
            category,
        });
    }

    return seats;
}

/**
 * A decoration element (non-bookable) for the layout JSON.
 *
 * @param {string} type  one of ELEMENT_TYPES
 * @param {object} props
 * @returns {object}
 */
export function createElement(type, props = {}) {
    if (!ELEMENT_TYPES.includes(type)) {
        throw new Error(`Unknown element type: ${type}`);
    }

    return {
        id: props.id ?? `el-${Math.random().toString(36).slice(2, 10)}`,
        type,
        x: round(props.x ?? 0),
        y: round(props.y ?? 0),
        width: round(props.width ?? 80),
        height: round(props.height ?? 40),
        rotation: round(props.rotation ?? 0),
        label: props.label ?? '',
        color: props.color ?? null,
        // Only polygons carry explicit points; everyone else uses the box.
        ...(type === 'polygon' && Array.isArray(props.points) ? { points: props.points } : {}),
    };
}

/**
 * Builds the persisted layout JSON for the resource (decorations + canvas +
 * category palette). Seats are NOT in here — they are DB rows.
 */
export function serializeLayout({ canvas, elements, categories }) {
    return {
        canvas: {
            width: Math.max(1, round(canvas?.width ?? 1000)),
            height: Math.max(1, round(canvas?.height ?? 700)),
        },
        elements: (elements ?? []).map((el) => createElement(el.type, el)),
        categories: (categories ?? []).map((c) => ({
            key: String(c.key),
            name: String(c.name ?? c.key),
            color: c.color ?? null,
        })),
    };
}

/**
 * Plans the seat upserts + deactivations for a set of editor seats, reusing
 * existing ids by `rowLabel:seatLabel` so claims survive. Mirrors the grid
 * generator's contract.
 *
 * @param {Array<{id,rowLabel,seatLabel,active}>} existingSeats
 * @param {Array<{rowLabel,seatLabel,posX,posY,rotation,category}>} editorSeats
 * @returns {{upserts: Array<object>, deactivateIds: string[]}}
 */
export function planSeatLayout(existingSeats, editorSeats) {
    const byPosition = new Map(
        existingSeats.map((seat) => [`${seat.rowLabel}:${seat.seatLabel}`, seat]),
    );

    const inLayout = new Set();
    const upserts = [];

    for (const seat of editorSeats) {
        const key = `${seat.rowLabel}:${seat.seatLabel}`;
        inLayout.add(key);
        const existing = byPosition.get(key);

        upserts.push({
            ...(existing ? { id: existing.id } : {}),
            rowLabel: seat.rowLabel,
            seatLabel: seat.seatLabel,
            posX: round(seat.posX),
            posY: round(seat.posY),
            rotation: round(seat.rotation ?? 0),
            category: seat.category ?? null,
            active: true,
        });
    }

    const deactivateIds = existingSeats
        .filter((seat) => seat.active && !inLayout.has(`${seat.rowLabel}:${seat.seatLabel}`))
        .map((seat) => seat.id);

    return { upserts, deactivateIds };
}
