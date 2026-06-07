# Free-form 2D seat-map editor

An OPTIONAL richer seat layout for irregular venues (every hall/stadium is
built differently — aisles, columns, walls, stages, curved or rotated tiers).
Additive to the plain grid generator: a resource without a custom layout keeps
rendering as the rectangular grid.

## Principle: curves live in the editor, the model is flat

All three geometries — straight, rotated and curved (arc) rows — are produced
by the editor's GENERATION tools, which compute each seat's `(x, y, rotation)`
and collapse them to plain points. The persisted model and the storefront
picker never do curve maths; they render points and shapes. This keeps the
data model simple while supporting stadium-style tiers.

## Data model

- **Seats stay first-class DB rows** (`fib_booking_seat`): claims reference
  them, tickets snapshot the seat label. The editor sets `pos_x`/`pos_y`
  (canvas px), `rotation` (deg) and `category`. Regenerating reuses ids by
  `row:label` and deactivates dropped seats — never deletes (booking history).
- **Decorations + canvas + category palette** live in
  `fib_booking_resource.layout` (JSON) — presentation only, never claimed:
  ```
  { canvas: {width, height},
    categories: [{key, name, color}],
    elements:   [{id, type, x, y, width, height, rotation, label, color}] }
  ```
  Element types: `aisle`, `column`, `wall`, `stage`, `rect`, `circle`,
  `polygon`, `label`.

## Pieces

- **Geometry module** `administration/.../fib-booking/seat-layout.js` (pure,
  vitest): `generateStraightRow` (count, spacing, angle), `generateArcRow`
  (count, radius, start/end angle, tangent rotation), `createElement`,
  `serializeLayout`, `planSeatLayout` (reuse-by-position upsert plan).
- **Admin editor** `page/fib-booking-seat-editor` (route `seat-editor/:id`,
  linked from the seating page on seatmap resources): SVG canvas with tools
  (select/move, add seat, straight/arc row, blockers, shapes, label, category
  palette, delete). Persists seats + `resource.layout` via the DAL
  repositories; keeps `capacity` in sync with the active seat count.
- **Storefront** the Vue seat-picker island renders the layout (decorations +
  seats at x/y/rotation, coloured by category, free/held/sold/selected, live
  Mercure updates) when `layout` is present; falls back to the grid otherwise.

## Demo

The demo cinema (`fib_demo_resource_cinema`) ships a free-form layout: a
`SCREEN` stage, canvas-positioned seats and two category bands (Premium rows
A–B, Standard C–E) — seeded by `CatalogSeeder` from the `seating.layout` block
in `booking-demo.json`.

## Tests

- `seat-layout.test.js` (vitest): straight/rotated/arc generation, element
  creation, serialization, reuse-by-position plan.
- `SeatClaimFlowTest` (integration): the read model exposes the layout JSON +
  per-seat rotation/category.
