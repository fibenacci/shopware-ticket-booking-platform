# Ticket Types — Generic Validity Model

One configuration spans every ticket kind — nothing is hardcoded per "type":

```
ticket type = validity mode × entry policy
```

| Example | validityMode | validityDuration | validityAnchor | entryPolicy |
|---|---|---|---|---|
| Event/concert ticket | `slot` | — | — | `single` |
| Escape-room session | `slot` | — | — | `single` |
| Transit day ticket | `period` | `P1D` | `first_use` | `multi` |
| Monthly pass | `period` | `P1M` | `purchase` | `multi` |
| 3-month pass | `period` | `P3M` | `purchase` | `multi` |
| Season/annual pass | `period` | `P1Y` | `customer` | `multi` |
| 4-hour pool ticket | `period` | `PT4H` | `first_use` | `multi` |
| Simple admission voucher | `unlimited` | — | — | `single` |

Day/month/year tickets are NOT distinct features — they are the same three
fields with different ISO-8601 durations. The presets in the admin UI only
pre-fill these values; everything stays freely configurable.

## Admin UI

Product detail → tab **"Booking & Tickets"**: enable ticketing, pick the
resource, choose the validity mode and entry policy. For period passes a
preset select (day / week / month / 3 months / 6 months / year / custom)
pre-fills the ISO-8601 duration; the duration field validates the format
before saving. The card saves the `fib_booking_product_config` row
independently of the product save (admin sources:
`Resources/app/administration/src/module/sw-product/`, preset logic
unit-tested via vitest).

## Configuration (per product, `fib_booking_product_config`)

| Field | Values | Meaning |
|---|---|---|
| `validityMode` | `slot` (default) / `period` / `unlimited` | WHEN the ticket is valid |
| `validityDuration` | ISO-8601 duration (`P1D`, `P1M`, `P3M`, `P1Y`, `PT4H`, …) | window length for `period` |
| `validityAnchor` | `purchase` (default) / `first_use` / `customer` | where the window starts |
| `entryPolicy` | `single` (default) / `multi` | HOW OFTEN it can be used while valid |
| `maxEntriesPerDay` | int, optional | daily cap for `multi` (NULL = unlimited) |

Anchors:

- **purchase** — valid from the moment of purchase (classic monthly pass).
- **first_use** — the pass is dormant until the FIRST successful check-in
  activates it (`valid_from = now`, `expires_at = now + duration`) — transit
  style "Entwertung". Activation happens inside the scan's row-lock scope, so
  concurrent first scans activate exactly once.
- **customer** — the customer picks the start date in the buy box (date
  picker, min = today). The value travels as `fibBookingValidityStart` in the
  line item payload (`BookingPassStartDateSubscriber` validates strictly) and
  as `validityStart` in the reservation payload. The cart processor BLOCKS
  checkout when a customer-anchored pass has no valid future start date.

## Snapshot semantics

At issue time the resolved validity is **snapshotted onto the ticket**
(`valid_from`, `expires_at`, `entry_policy`, `max_entries_per_day`,
`validity_anchor`, `validity_duration`). Reconfiguring the product later
never changes tickets already sold. Resolution lives in
`Core/Domain/Validity/TicketValidityResolver` (pure, unit-tested).

## Purchase flow

- **slot products**: unchanged — calendar → hold → order → reservation.
- **pass products** (`period`/`unlimited`): bought with the standard product
  buy box — the product page shows the validity terms (duration, anchor,
  entry policy, daily cap) instead of a calendar.
  `BookingPassReservationService` creates the reservation when the order is
  placed (no hold, no slot, no capacity accounting — slots remain the
  instrument for capacity-bound offers). Misconfigured passes never abort
  checkout; they are logged for manual follow-up.

## Scanning

New verdicts on top of the existing matrix (see [SCANNER.md](SCANNER.md)):

| Verdict | Meaning |
|---|---|
| `not_yet_valid` | `valid_from` is in the future (e.g. customer-anchored pass) |
| `entry_limit_reached` | multi pass exhausted its daily cap |

`entryPolicy: multi` allows passing the gate repeatedly while valid — with
check-out scanning enabled the attendance statistics still work per session;
without it, repeated check-ins simply log one valid entry each.

## Demo data

`fib-booking:demodata` seeds transit-style examples (resource
"Demo Transit Network"): `FIB-PASS-DAY` (P1D, first_use, multi),
`FIB-PASS-MONTH` (P1M, purchase, multi) and `FIB-PASS-YEAR` (P1Y, customer,
multi, max 10 entries/day). See
`FibBookingDemoData/src/Resources/seeds/booking-demo.json` — the `validity`
block on a product is all it takes.
