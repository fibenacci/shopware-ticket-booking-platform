# FIB Booking System - Architecture and Implementation Plan

## Goal

The plugin provides a technically clean booking and reservation system for Shopware 6. Functionally it takes inspiration from systems like Reservia, but it is not built as a form hack. The booking domain is its own bounded context within Shopware and uses Shopware directly only where Shopware is strong: product, cart, checkout, payment, customer, mail, documents and administration.

## Product decision

A Shopware plugin makes sense when the target customer already uses Shopware as their commerce, customer account, payment and mail system. For a multi-tenant SaaS serving arbitrary websites, an external booking SaaS with a Shopware app or connector would be the better long-term fit.

This codebase deliberately starts as a self-hosted Shopware plugin. A later SaaS extraction remains possible because the domain services are separated from storefront and checkout.

## Architecture principles

- Booking logic stays independent of the cart.
- Critical booking data lives in dedicated tables, not only in line-item payloads.
- Availability is checked transactionally when holding and again at checkout.
- Status transitions go through services, never through arbitrary repository writes.
- Holds have a short TTL and are cleaned up by a scheduled task.
- The Shopware order is the commercial truth, `fib_booking_reservation` is the domain booking truth.
- Side effects such as mail, webhooks and calendar sync will later run through events / the message queue.
- Admin actions must become auditable in the long run.

## DAL-first policy (DBAL exceptions)

All persistence goes through the Shopware DAL. Raw DBAL is allowed ONLY for
the following documented cases — each call site carries a justification
docblock referencing this section:

1. **Pessimistic locks**: `SELECT … FOR UPDATE` on resources/holds/
   reservations/tickets — the DAL has no pessimistic locking, and these locks
   ARE the no-overbooking / no-double-scan guarantee
   (`BookingHoldService`, `BookingReservationService`, `TicketScanService`,
   `BookingTicketService`).
2. **The concurrency core's reads**: every read feeding the overbooking
   decision inside the lock scope (`AvailabilityService`) stays on the same
   raw connection — one cohesive serialization path, no DAL/SQL mix.
3. **Security-excluded column**: `scan_token_cipher` is intentionally not
   part of the DAL definition so it can never leak through the Admin API;
   reading/writing it is raw by design (`BookingTicketService` insert,
   `WalletPassService`, `BookingTicketRenderer`).
4. **Read models & set-based maintenance**: joined aggregate subqueries the
   DAL cannot express (`BookingCalendarService` month view,
   `BookingStatisticsService` purchase/dwell-time aggregation) and
   scheduled-task bulk flips with no cache/index relevance
   (`BookingHoldExpirationService`) — same pattern Shopware core uses.

Migrations and the plugin uninstall (`DROP TABLE`) use the connection as
Shopware's own APIs prescribe. Everything else — CRUD, lookups, status
transitions, seeding — is DAL.

## Module structure

```text
FibBookingSystem/
  docs/
    ARCHITECTURE_PLAN.md
  src/
    Api/Controller/
    Checkout/
      Order/
      Payment/
    Core/
      Content/
        BookingResource/
        BookingHold/
        BookingReservation/
        BookingTicket/
        ProductBookingConfig/
      Domain/
        Availability/
        Reservation/
        Ticket/
    Extension/
      Content/Product/
    Flow/
      Action/
    Migration/
    Resources/config/
    ScheduledTask/
    Storefront/Controller/
```

Planned extensions:

```text
src/
  Administration/
  Checkout/
    Cart/
    Order/
    Payment/
  Core/
    Content/
      BookingLocation/
      BookingArea/
      BookingPlan/
      BookingAddon/
      BookingCancellationPolicy/
      BookingAuditLog/
    Domain/
      Assignment/
      Cancellation/
      Pricing/
  Flow/
    Event/
    Action/
  StoreApi/
```

## Data model

### Current core

`fib_booking_resource`

- Bookable resource, e.g. table, room, staff member, device or slot contingent.
- Optionally linked to a Shopware product.
- Holds capacity and technical configuration.

`fib_booking_hold`

- Temporary lock of a time window.
- Created during the customer flow before cart/checkout.
- Has `expires_at` and status `active` or `expired`.

`fib_booking_reservation`

- The domain-level reservation.
- Created from a hold once checkout/order is secured.
- Optionally references order, order line item, customer and hold.

`fib_booking_ticket`

- Scannable ticket for a confirmed reservation.
- Contains ticket number, status and only the hash of the scan token.
- The plaintext scan token is used only for QR code generation and is never stored.
- Sent via a dedicated ticket mail, not via the regular order confirmation.

`fib_booking_product_config`

- Shopware-compliant product entity extension for booking data.
- Contains product, product version, resource, active flag and slot duration.
- Loaded via `ProductBookingConfigExtension` as `product.extensions.fibBookingConfig`.
- Deliberately replaces product custom fields because the booking configuration is structured plugin data with its own relation.

## Number ranges

The plugin uses its own Shopware-compliant number ranges:

- `fib_booking_reservation` with pattern `B{n}`
- `fib_booking_ticket` with pattern `T{n}`

The number ranges are created by the migration in `number_range_type`, `number_range`, `number_range_state` and the translation tables. Booking and ticket numbers are generated exclusively via `NumberRangeValueGeneratorInterface`.

### Planned entities

- `fib_booking_location`: location.
- `fib_booking_area`: room, area or zone.
- `fib_booking_plan`: seat or floor plan as JSON/SVG/canvas data.
- `fib_booking_addon`: additional service, menu, package, fee.
- `fib_booking_availability_rule`: opening hours, blocked times, lead times.
- `fib_booking_capacity_rule`: group sizes, parallel bookings, resource types.
- `fib_booking_cancellation_policy`: cancellation and no-show rules.
- `fib_booking_audit_log`: traceability of all manual and automatic status transitions.

## Status model

Transitions, triggers and safety nets are documented in
[`RESERVATION_LIFECYCLE.md`](RESERVATION_LIFECYCLE.md) — including
cancellation/refund handling, expiry of unpaid reservations, the
per-customer hold cap, scan-log retention and the hourly oversell
consistency check.

Holds:

- `active`
- `expired`
- `converted`
- `cancelled`

Reservations:

- `draft`
- `pending_payment`
- `confirmed`
- `cancelled`
- `expired`
- `no_show`
- `completed`
- `refunded`

Tickets:

- `issued`
- `sent`
- `scanned`
- `revoked`
- `expired`

## Availability and race-condition protection

Availability is computed from active holds and blocking reservations. A time window collides when:

```text
existing.starts_at < requested.ends_at
AND existing.ends_at > requested.starts_at
```

When creating a hold, `BookingHoldService` locks the resource with `SELECT ... FOR UPDATE`. Parallel requests for the same resource are therefore checked serially. This is the most important technical safety line against double bookings.

Implemented hardening (see `RESERVATION_LIFECYCLE.md` for details):

- dedicated slot tables for fixed time grids (`fib_booking_slot`).
- transactional conversion of hold to reservation during checkout
  (pessimistic hold-row lock, idempotent against retries).
- expiry of unpaid reservations + availability-checked resurrection on late
  payment.
- per-customer cap on concurrent holds (`maxActiveHoldsPerCustomer`).
- hourly oversell consistency check (`BookingConsistencyCheckTask`) — every
  violation is an ERROR log meant for alerting.
- single UTC entry point for all time handling
  (`Core/Domain/Time/UtcDateTime`): client offsets are normalized at the
  parser, raw SQL compares UTC wall time, DAL writes receive UTC objects.

Still open:

- retry strategy on deadlocks.
- load tests for parallel hold creation.

## Cache strategy

Booking API routes are not cacheable. The responses for availability, hold creation and hold-to-cart set `Cache-Control: no-store`.

Important:

- Holds do not invalidate the product cache.
- Availability is checked live via JSON routes.
- Product detail cache entries receive the tag `fib-booking-configuration` via `HttpCacheStoreEvent`.
- Changes to `fib_booking_resource` invalidate the `fib-booking-configuration` tag.
- Changes to `fib_booking_product_config` invalidate `product` and `fib-booking-configuration`, because bookability on the product detail page may change.

Customer interaction therefore causes no cache churn, while administrative configuration changes become cleanly visible.

## Storefront/API

Current endpoints:

`POST /fib-booking/availability`

Checks whether a resource time window is available.

```json
{
  "resourceId": "018f0000000000000000000000000001",
  "startsAt": "2026-06-01T18:00:00+00:00",
  "endsAt": "2026-06-01T20:00:00+00:00",
  "quantity": 2
}
```

`POST /fib-booking/hold`

Creates a temporary hold with token and expiry time.

`POST /fib-booking/cart/add`

Puts a bookable product into the cart with `bookingHoldId` and `bookingHoldToken`.

Product configuration:

- `fib_booking_product_config.enabled`
- `fib_booking_product_config.resource_id`
- `fib_booking_product_config.slot_minutes`

This data is loaded as a product entity extension. For products flagged accordingly, the storefront widget replaces the standard buy form with an appointment form.

The product detail widget is registered as a Shopware-compliant storefront vanilla-JS plugin `FibBookingWidget`. Twig delivers only markup and `data-fib-booking-widget-options`; there is no inline script.

Next storefront steps:

- better UX for slot selection and availability display.
- resource selection or automatic resource assignment.

## Checkout integration

Planned flow:

1. Customer picks date, time, number of people and optionally a resource.
2. The storefront creates a `fib_booking_hold`.
3. The cart line item carries only reference and token, never the sole truth.
4. The cart processor validates the hold on every cart calculation.
5. The order subscriber converts the hold into a `fib_booking_reservation` within a transaction.
6. Payment events set the reservation to `confirmed`, `pending_payment`, `cancelled` or `refunded`.
7. After the reservation is finally confirmed, `BookingTicketService` creates a QR ticket and triggers the dedicated ticket mail.

Implemented classes:

- `Checkout/Order/BookingOrderPlacedSubscriber`
- `Checkout/Payment/BookingPaymentStateSubscriber`
- `Core/Domain/Reservation/BookingReservationService`
- `Checkout/Cart/BookingCartProcessor`
- `Checkout/Cart/BookingLineItemFactory`

## QR ticket and ticket desk

After a reservation is successfully confirmed, a dedicated ticket is created. The ticket is deliberately a separate entity and not a field on the reservation, because it has its own lifecycle: issued, sent, scanned, revoked or expired.

Technical flow:

1. `BookingTicketService::issueTicket()` locks the reservation transactionally.
2. Exactly one active ticket is created per reservation.
3. The ticket receives a human-readable ticket number.
4. A cryptographically strong scan token is generated.
5. Only `sha256(scanToken)` is stored in the database.
6. The QR code contains a JSON payload with ticket number and scan token.
7. `BookingTicketIssuedEvent` triggers the separate ticket mail.
8. At the ticket desk, a scanner validates the token against the hash and marks the ticket as `scanned`.

The QR images are generated server-side as PNG data URIs with `chillerlan/php-qrcode`. `ext-gd` is declared as a composer requirement for this.

The ticket mail is registered as a dedicated Shopware mail template type `fib_booking_ticket_mail`. Its content can be maintained separately from order or booking confirmations.

Implemented desk endpoint:

`POST /api/_action/fib-booking/ticket/scan`

```json
{
  "scanToken": "token-from-the-qr-code"
}
```

Response:

```json
{
  "valid": true
}
```

The endpoint deliberately lives in the admin API scope and is not publicly exposed in the storefront scope.

Next steps for the ticket desk:

- role/ACL checks for desk staff.
- explicit scan results: valid, already scanned, expired, revoked, unknown.
- optional offline fallback with periodic sync.

## Administration

MVP:

- resource list.
- create/edit resource.
- reservation list.
- manual status change.

Operational expansion:

- calendar view day/week/month.
- manual booking and rebooking.
- blocked times and special opening hours.
- no-show handling.
- audit log.
- CSV export.

Premium expansion:

- interactive seat or floor plan.
- automatic best-resource assignment.
- waiting list.
- iCal/Google Calendar export.
- webhooks/API for external systems.

## Flow Builder and communication

Implemented custom events:

- `fib_booking.reservation.confirmed`
- `fib_booking.ticket.issued`

These events are registered as Shopware flow events and provide scalar values, e.g. reservation ID, booking number, ticket ID, ticket number and QR payload.

Implemented flow action:

- `action.fib_booking.issue_ticket`

The action can issue a ticket from a flow given a `reservationId`. It is idempotent against already existing tickets.

Planned additional custom events:

- `fib_booking.reservation.created`
- `fib_booking.reservation.cancelled`
- `fib_booking.reservation.expired`
- `fib_booking.reservation.no_show`
- `fib_booking.ticket.scanned`
- `fib_booking.ticket.revoked`

These events shall be able to trigger e-mail, webhooks, calendar sync and internal notifications.

## Rule Builder

Implemented rule:

- `fibBookingLineItemInCart`

The rule matches as soon as the cart contains at least one line item with a `fibBooking` payload. It is registered with the Shopware Rule Builder and can be used e.g. for payment/shipping/flow conditions or promotion exclusions.

Deliberately not in the Rule Builder:

- concrete slot availability
- race-condition protection
- hold validation
- resource capacity

These remain transactional domain logic.

## Test strategy

Priority 1:

- unit tests for time-window collisions.
- integration test for hold creation.
- concurrency test for two simultaneous holds on the same resource.
- scheduled-task test for expired holds.
- unit tests for line item factory and cart processor. Status: done.
- integration tests for migration and number ranges. Status: done.
- unit tests for cache headers and cache invalidation. Status: done.
- unit tests for flow event metadata and flow registration. Status: done.
- unit tests for the rule builder rule. Status: done.

Priority 2:

- cart processor validates expired holds.
- order subscriber creates exactly one reservation.
- payment status updates the reservation correctly.
- ticket is created exactly once per confirmed reservation.
- ticket mail is sent separately from the regular order mail flow.
- QR scan accepts valid tokens and prevents double use.

Priority 3:

- storefront flow.
- administration components.
- E2E test for a complete booking.
- Playwright E2E for hold and add-to-cart API. Status: prepared.

## Implementation phases

### Phase 1: Technical core

- plugin skeleton.
- migrations.
- DAL entities for resource, hold, reservation.
- availability service.
- hold service with DB lock.
- scheduled task for hold expiry.
- simple storefront JSON endpoints.

Status: started.

### Phase 2: Shopware checkout

- configure bookable products.
- storefront widget on the product detail page.
- take the hold into the cart.
- product entity extension for bookable products. Status: done.
- storefront widget on the product detail page. Status: done as a Shopware storefront plugin.
- take the hold into the cart. Status: done via `/fib-booking/cart/add`.
- cart processor. Status: done.
- booking line item factory. Status: done.
- order subscriber. Status: done.
- payment event subscriber. Status: done for order transaction states `paid`, `paid_partially`, `authorized` and order states `completed`, `in_progress`.
- ticket creation after reservation confirmation. Status: done.
- separate ticket mail with QR code. Status: done.

Still open in phase 2:

- storefront widget on the product detail page.
- configure bookable products.

### Phase 3: Administration

- CRUD for resources.
- reservation list.
- calendar view.
- manual booking, cancellation and rebooking.

### Phase 4: Reservia-level features

- interactive plan.
- automatic assignment.
- deposit/prepayment.
- add-on options.
- no-show management.
- Flow Builder events.
- exports and webhooks.

## Open technical decisions

- Should the system enforce free time windows or fixed, predefined slots?
- Should one resource be able to serve multiple products?
- Are prices computed from Shopware products, booking rules or hybrid pricing rules?
- Does the plugin need to become Shopware Cloud capable? If so, an app/SaaS split is required.
- Should the plan be maintained interactively via SVG, canvas or an external editor component?
