# FIB Booking Demo Data

Dev/CI-only plugin seeding demo data for the
[FibBookingSystem](../FibBookingSystem/README.md) plugin. Registered in the
project's `require-dev`, so production image builds
(`shopware-cli project ci` runs `composer install --no-dev`) never ship it.

## Usage

```bash
bin/console fib-booking:demodata
bin/console fib-booking:demodata --skip-reservation
```

From the project root: `make seed-booking`.

## Seed definitions (JSON)

The seed DEFINITIONS live in **`src/Resources/seeds/booking-demo.json`** —
structured and editable without touching PHP:

- `resources` — booking resources (stage, room, …) with capacity
- `products` — bookable demo products (`FIB-DEMO-*`), each on its own resource
- `packages` — the Starter/Premium/Luxury scenario: three products sharing
  ONE resource, plus calendar **slots** (next N days × times × capacity) for
  the booking-calendar CMS element
- `reservation` — one confirmed reservation `B-DEMO-1` with a real QR ticket
  (issued through `BookingTicketService`)
- `cms.homepage` — a CMS layout ("FIB Booking Home") with the booking-calendar
  element (configured to the package resource) and a scanner-link text block,
  assigned to every storefront entry category (`assignToHomepage: false` to
  opt out)
- `scanner` — least-privilege scanner access: ACL role `Booking Scanner`
  (ONLY `fib_booking.ticket_scan`) plus a non-admin demo user bound to it
  (**demo credentials** — change for anything public)

`BookingDemoDataSeeder` only interprets the JSON; all writes go through DAL
repositories. Ids are **deterministic** and existing rows are matched by
their unique keys (technical name / product number / booking number), so
re-running upserts instead of duplicating — safe for repeated use in dev/CI.

The command prints a machine-readable block consumed by CI / Playwright:

```
FIB_BOOKING_PRODUCT_ID=…
FIB_BOOKING_RESOURCE_ID=…
FIB_BOOKING_PACKAGE_PRODUCT_ID=…
FIB_BOOKING_PACKAGE_RESOURCE_ID=…
```
