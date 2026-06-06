# Statistics — Purchases & Dwell Time

Operator statistics for the ticketing platform: how many tickets are sold
(per day / per product, with revenue) and how long guests stay
(check-in → check-out sessions).

## Data sources

Nothing is tracked twice — the statistics are a **read model** over data the
platform already produces:

| Figure | Source |
|---|---|
| Purchases (count, quantity, revenue) | `fib_booking_reservation` (status `confirmed`) joined to its `order_line_item` |
| Check-ins / check-outs | `fib_booking_scan_log` (`direction` + verdict) |
| Currently inside | `fib_booking_ticket.status = 'scanned'` |
| Dwell time | each successful check-out paired with the latest preceding successful check-in of the same ticket |

Re-entry produces one session per visit; the dwell figures (average, median)
naturally sum over sessions. Aggregation lives in
`Core/Domain/Statistics/BookingStatisticsService` — raw DBAL by design, see
the "Read models" exception in
[ARCHITECTURE_PLAN.md](ARCHITECTURE_PLAN.md#dal-first-policy-dbal-exceptions).

## Check-in / check-out

Check-out scanning is **opt-in** via plugin config
(`FibBookingSystem.config.scanCheckOutEnabled`, Admin → Extensions →
FIB Booking System → "Ticket scanning"):

- **Disabled (default)**: original behavior — a ticket is consumed by its
  first scan, replays report `already_scanned`. No dwell-time data.
- **Enabled**: the scanner app shows a Check-in / Check-out mode toggle.
  Ticket lifecycle: `issued/sent → scanned → checked_out → scanned → …`
  (re-entry allowed). `scanned_at` always keeps the FIRST entry time;
  individual sessions are reconstructed from the scan log.

Check-out verdicts: `checked_out` (success), `not_checked_in` (guest is not
currently inside), plus the usual `revoked` / `not_found`. Expiry never
blocks leaving. A check-out scan against a disabled config is rejected with
the domain error `FIB_BOOKING__SCAN_CHECK_OUT_DISABLED`.

The scanner app discovers the flag at runtime via
`GET /api/_action/fib-booking/scanner/config` (same ACL as scanning).

## API

```
GET /api/_action/fib-booking/statistics?from=2026-06-01&to=2026-06-30
```

- ACL: **`fib_booking.statistics`** — deliberately separate from
  `fib_booking.ticket_scan`, so a scan-only role does not see revenue.
  The demo scanner role carries both (the operator app shows a dashboard).
- `from`/`to` accept `YYYY-MM-DD` or ISO 8601; default range is the last
  30 days. Rate-limited and `Cache-Control: no-store`.

Response shape:

```json
{
    "range": { "from": "…", "to": "…" },
    "purchases": {
        "total": 12, "quantity": 31, "revenue": 1240.5,
        "byDay": [{ "date": "2026-06-03", "count": 4, "quantity": 9, "revenue": 360.0 }],
        "byProduct": [{ "label": "Premium Package", "count": 5, "quantity": 12, "revenue": 600.0 }]
    },
    "attendance": {
        "checkIns": 28, "checkOuts": 25, "currentlyInside": 3,
        "dwell": { "sessions": 25, "averageMinutes": 84.2, "medianMinutes": 78.0 }
    }
}
```

Reservations without an order (e.g. manually issued demo tickets) count with
zero revenue under the `(no order)` label.

## Scanner app dashboard

The operator app (`apps/scanner/`) has a **Statistics** tab: bookings,
tickets, revenue, currently-inside count, check-ins and average dwell time
for the last 30 days, plus per-product and per-day tables. Users without the
`fib_booking.statistics` privilege get a hint instead of data (the server
enforces the 403 regardless).

## Privacy

The statistics aggregate counts and durations only. The underlying scan log
stores no scan tokens (12-char fingerprint only) and no guest identity — see
[SECURITY.md](SECURITY.md).
