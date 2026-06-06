# Seating — Numbered Seats (Design)

> Status: **phases 1-3 implemented.**
> Phase 1 (core): schema, claim primitive, hold/expiry/conversion
> integration, seatmap Store API (`GET /store-api/fib-booking/seatmap/{slotId}`
> + storefront proxy) — covered by
> `tests/Integration/Seating/SeatClaimFlowTest`.
> Phase 2 (picker): `<fib-seat-picker>` custom element — a Vue 3 island
> (apps/seat-picker, own Vite build → Resources/public, vitest-covered
> selection logic) lazy-loaded by the vanilla calendar widget only on
> seatmap resources; quantity derives from the selection, the hold request
> carries `slotId` + `seatIds`. Demo cinema (5×8 grid) seeds onto the
> homepage.
> Phase 3 (ticket-per-seat): `BookingTicketService::issueTickets()` issues
> one ticket PER claimed seat with the seat-label snapshot; the seat shows on
> the scan verdict (scanner app), Apple/Google wallet passes and the ticket
> PDF. Phases 4-5 (admin generator, realtime push) follow this blueprint.

Numbered seating (cinema, theater) as a **per-resource option** — the
pool-based model stays the default and remains untouched for everything that
has no seats (transit passes, escape rooms, standing concerts, workshops):

```
fib_booking_resource.seating_mode: 'pool' (default) | 'seatmap'
```

## Why a separate claim table (and not stock, and not more FOR UPDATE)

Pool capacity needs a counter and therefore a lock. **A numbered seat does
not** — it is either taken for a slot or it is not. That maps onto a unique
constraint, and the database makes the race decision for us:

```sql
CREATE TABLE fib_booking_seat_claim (
    id              BINARY(16) NOT NULL,
    seat_id         BINARY(16) NOT NULL,
    slot_id         BINARY(16) NOT NULL,
    hold_id         BINARY(16) NULL,     -- claiming hold (TTL)
    reservation_id  BINARY(16) NULL,     -- set when the hold converts
    created_at      DATETIME(3) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq.seat_per_slot (seat_id, slot_id)
);
```

Two customers grabbing seat `F7` for the same showing: both INSERT, exactly
one succeeds, the other gets a duplicate-key error → "seat just taken".
No lock wait, no serialization point, no deadlock surface — **insert-wins**.
Claims are rows, not flags, so releasing (hold expiry) is a set-based DELETE
and converting (payment) is an UPDATE binding `reservation_id`.

## Data model

```
fib_booking_seat
    id, resource_id (FK), row_label, seat_label,   -- "F", "7"
    pos_x, pos_y,                                  -- render coordinates (grid units)
    category (nullable),                           -- pricing/zone tier, later
    active (bool)                                  -- gaps, broken seats, distancing

fib_booking_seat_claim                             -- see above
fib_booking_resource.seating_mode                  -- 'pool' | 'seatmap'
fib_booking_ticket.seat_label (nullable snapshot)  -- "Row F · Seat 7"
```

- A cinema room = one resource with `seatmap` + its seats. The seat set is
  slot-independent; **claims are per (seat, slot)** — the same room serves
  every showing.
- In seatmap mode the slot's effective capacity IS `COUNT(active seats)`;
  `fib_booking_slot.capacity` becomes a derived display value.
- `seat_label` is **snapshotted onto the ticket** (same principle as the
  validity snapshot): renaming rows later never changes sold tickets.

## Flow integration (all existing seams)

| Step | Today (pool) | With seatmap |
|---|---|---|
| Hold | decrement-style check inside FOR UPDATE | same hold row + N seat-claim INSERTs in the SAME transaction; any duplicate key → rollback → `SEAT_TAKEN` error listing the lost seats |
| Hold expiry | set-based status flip | + `DELETE claims WHERE hold_id IN (expired)` (same scheduled task) |
| Order placed | hold → reservation | + claims get `reservation_id`, `hold_id` cleared |
| Ticket issue | 1 ticket per reservation | **1 ticket per seat** (quantity N → N tickets), each with `seat_label` — wallet pass & PDF show the seat |
| Scan | unchanged | unchanged (seat shows in the verdict payload for staff) |

## Storefront (cinema picker)

- Product page in seatmap mode replaces the quantity field with a **seat
  picker**: CSS grid from `pos_x/pos_y`, states `free / held / sold / selected`
  (no canvas, no library — cinema grids are small).
- State source: slot-scoped availability endpoint
  (`GET /store-api/fib-booking/seatmap/{slotId}`), short-poll with jitter +
  `stale-while-revalidate` — realtime stage 1. Stage 2 (SSE/Mercure) publishes
  on `seatmap.{slotId}` from the claim commit events; the channel granularity
  was designed for this.
- Selecting N seats → hold request carries `seatIds[]` instead of `quantity`.

## Admin (V1 pragmatic)

Resource module gets a **grid generator**: rows × seats-per-row, optional row
labels, click to deactivate individual seats (pillars, wheelchair spaces).
That covers cinema/theater rectangles. A free-form visual editor (curved
rows, balconies) is explicitly a later iteration — the data model (x/y
coordinates) already supports it.

## Phases

1. **Core**: migration (seats, claims, seating_mode, ticket.seat_label),
   claim primitive in `BookingHoldService` + expiry + conversion, seatmap
   read model + Store API route, `SEAT_TAKEN` domain error. Tests: claim
   race (parallel inserts), expiry release, conversion binding.
2. **Storefront picker** (CSS grid + short-poll) + hold-with-seats flow.
3. **Ticket-per-seat issuing** + seat on wallet pass / ticket PDF / scanner
   verdict.
4. **Admin grid generator** on the resource module.
5. **Realtime push** (SSE/Mercure, `seatmap.{slotId}`) — optional, see
   ARCHITECTURE_PLAN scaling notes.

## Explicit non-goals (V1)

- Seat-level pricing (category column exists, pricing rules come later —
  until then: one product per category, e.g. "Parkett"/"Loge", same room).
- Collaborative presence ("seatmap cursors") — state push only.
- Free-form venue editor.
