# Reservation & Ticket Lifecycle

How a booking moves through the system, which transitions exist, who triggers
them, and which safety nets watch the whole thing. Complements
`ARCHITECTURE_PLAN.md` (status model, DAL policy) and `TICKETING_PLAN.md`
(scan/wallet flows).

## The happy path

```
Storefront                Checkout                  Payment                   Door
─────────                 ────────                  ───────                   ────
hold (active, TTL 15m) ─► reservation              reservation               ticket
                          (pending_payment) ──────► (confirmed)              (scanned)
                          hold → converted          ticket (issued → sent) ─►
```

1. **Hold** — `BookingHoldRoute` → `BookingHoldService::createHold()`.
   Resource row lock (`SELECT … FOR UPDATE`) + live availability check in one
   transaction: the no-overbooking guarantee. Active holds count against
   capacity until they expire or convert.
2. **Conversion** — `CheckoutOrderPlacedEvent` →
   `BookingReservationService::convertOrderHolds()`. Pessimistic lock on the
   hold row, idempotent against double submit / webhook retries. Every failed
   conversion is an ERROR log — a placed order without a reservation is an
   operator incident, never silent.
3. **Confirmation** — `BookingPaymentStateSubscriber` on `paid` /
   `authorized` (and `paid_partially` only when `ticketsOnPartialPayment` is
   on). Confirms reservations and issues tickets.
4. **Ticket** — `BookingTicketService::issueTicket()`. Slot-bound tickets are
   stamped with their scan window: `valid_from = slot start −
   scanEarlyEntryMinutes`, `expires_at = slot end`. Period/unlimited passes
   carry their window from the validity model instead (see
   `TICKET_TYPES.md`).
5. **Scan** — `TicketScanService::scan()`. Race-safe via `FOR UPDATE` on the
   token hash; every attempt (including failures) lands in
   `fib_booking_scan_log` with verdict, direction and optional `gate` label.

## Cancellation & refund

`BookingPaymentStateSubscriber` (ENTER side of the transition only — the
LEAVE side reports the state being *left* and must never trigger actions):

| Trigger                          | Effect |
|----------------------------------|--------|
| order_transaction → `refunded`   | reservations → `cancelled`, tickets → `revoked` |
| order → `cancelled`              | reservations → `cancelled`, tickets → `revoked` |
| order_transaction → `refunded_partially` | **deliberately ignored** — which tickets a partial refund voids is an operator decision in the admin |

`cancelled` reservations leave the availability filter, so the booked window
returns to the pool automatically. `scanned`/`checked_out` tickets are
revoked too: the past visit is history, but a refunded multi-entry pass must
not grant further entries. Reservation cancel + ticket revoke run in one
transaction (`cancelReservationsForOrder`).

## Expiry (the inventory-leak guards)

| What | TTL | Where |
|------|-----|-------|
| Holds | minutes (hold row `expires_at`, default 15m) | `BookingHoldExpirationService`, set-based UPDATE per scheduled task |
| Pending reservations | hours (`pendingReservationTtlHours`, default 72, 0 = off) | `BookingReservationExpirationService`, same task tick |

Without the second guard, an abandoned prepayment would block its window
forever — pending reservations count against capacity.

**Late payments (resurrection):** when payment arrives after the reservation
expired, `confirmReservationsForOrder()` re-checks availability under the
resource lock and re-activates the reservation if the window is still free.
If it was given away in the meantime, the reservation stays `expired` and an
ERROR log tells the operator to re-book or refund — the system never confirms
into an oversold window.

## Abuse limits

- **Per-customer hold cap** — `maxActiveHoldsPerCustomer` (default 5,
  0 = off): a logged-in customer cannot block a slot with browser tabs.
  Guests have no stable identity and are throttled by the IP rate limit on
  the hold route instead. Violation → HTTP 429 `FIB_BOOKING__HOLD_LIMIT_REACHED`.
- **Rate limits** — sliding-window limiter per route class (read/write/
  wallet/scan), see `BookingRateLimiter`.

## Time handling (UTC invariant)

All persisted timestamps are UTC wall time (`DATETIME(3)`), all raw SQL
compares against `UTC_TIMESTAMP(3)`. `Core/Domain/Time/UtcDateTime` is the
single entry point:

- `UtcDateTime::parse()` — client input AND storage strings → UTC instant
  (explicit offsets win, naive strings are UTC, never the PHP default
  timezone).
- `UtcDateTime::now()` / `from()` / `toStorage()` — see class docblock.

Why it matters: two clients describing the same instant with different
offsets (`10:00+02:00` vs `08:00Z`) must resolve to the SAME window —
otherwise availability counts them separately (oversell). DAL writes pass
UTC `DateTimeImmutable` OBJECTS, raw SQL uses `toStorage()` strings.

## Scan audit log & GDPR

`fib_booking_scan_log` is append-only: every attempt with verdict
(`valid` / `already_scanned` / `revoked` / `expired` / `not_yet_valid` /
`not_found` / …), direction (`check_in` / `check_out`), `source`, optional
`gate` (entrance/lane label, ≤64 chars) and the acting admin user/integration.

Retention: `AnonymizeScanLogTask` (daily) nulls the person-related columns
(`scanned_by`, `token_fingerprint`) of rows older than
`scanLogRetentionDays` (default 180, 0 = off). The rows themselves stay —
verdict/direction/gate/timestamps feed the attendance and dwell-time
statistics, which must survive the retention window.

## Consistency check (the alarm)

`BookingConsistencyCheckTask` (hourly) verifies the invariant

```
Σ(living holds) + Σ(pending/confirmed/completed reservations) ≤ capacity
```

per future slot, and per slotless resource via a sweep line over overlapping
windows. The expected steady state is **silence** — every violation is an
ERROR log (`OVERSELL: …`) and means a real bug, a manual DB edit or a broken
migration. Route these logs to alerting.

## Plugin configuration (lifecycle card)

| Key | Default | Meaning |
|-----|---------|---------|
| `ticketsOnPartialPayment` | `false` | issue tickets already on `paid_partially` (fraud trade-off: deposit → enter → never settle) |
| `pendingReservationTtlHours` | `72` | unpaid-reservation expiry; 0 disables |
| `maxActiveHoldsPerCustomer` | `5` | concurrent-hold cap per customer; 0 disables |
| `scanEarlyEntryMinutes` | `60` | slot tickets scan valid this many minutes before slot start |
| `scanLogRetentionDays` | `180` | GDPR anonymization horizon for the scan log; 0 disables |
| `scanCheckOutEnabled` | `false` | check-out mode for the scanner app (dwell-time statistics) |

## Operational metrics worth watching

- Hold → order conversion rate and hold-abandonment (TTL tuning).
- `expired` pending reservations per day (payment friction).
- Scan verdict distribution per gate; spikes in `already_scanned` /
  `not_found` = fraud signal or broken device.
- No-show rate (`issued`/`sent` but never `scanned`).
- `OVERSELL` errors: must be 0 — alert, don't dashboard.
- Resurrection failures (`no longer available`): each one is a manual
  re-book/refund case.
