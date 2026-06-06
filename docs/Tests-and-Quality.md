# Tests and Quality

All commands run inside the `fib-shopware` container locally (`make` takes
care of it); in CI (`CI=true`) the same targets run directly on the runner.

## PHPUnit

```bash
make test               # unit + integration
make test-unit          # unit suite only (no DB needed)
make test-integration   # boots the Shopware kernel, needs a running stack
```

Configuration: `custom/static-plugins/FibBookingSystem/phpunit.xml.dist`
(suites `unit` and `integration`).

## Static analysis & code style

```bash
make quality            # full chain in CI order: cs-fixer check → phpstan → phpmd
make phpstan            # PHPStan level MAX, no baseline (.build/phpstan.neon)
make php-cs-fixer       # auto-fix (.build/php-cs-fixer.php, @PSR12 + @Symfony)
make php-cs-fixer-check # dry-run — identical to the CI check
make phpmd              # mess detection (.build/phpmd.xml) — last quality step
```

PHPMD exclusions are Shopware-idiomatic patterns, justified inline in the
ruleset and via `@SuppressWarnings` docblocks at the call sites (interface
contracts, DAL entities, DI aggregates).

## E2E (Playwright)

Two suites run against the local storefront:

- `homepage-calendar.spec.ts` — self-contained: asserts the booking-calendar
  CMS element renders on the homepage and the month endpoint serves data
- `booking-flow.spec.ts` — API booking flow; needs the demo-data ids

```bash
make e2e-install        # one-time: npm install + Chromium
FIB_BOOKING_PRODUCT_ID=... FIB_BOOKING_RESOURCE_ID=... make e2e
make e2e-ui             # interactive UI mode
make e2e-report         # open the latest HTML report
```

The ids come from `make seed-booking` output; without them only the booking
flow spec skips itself (`test.skip`).

## Scanner app (Vitest)

```bash
cd custom/static-plugins/FibBookingSystem/apps/scanner
npm test                # api.js: token extraction/validation, session handling
```

## Test coverage map

| Layer | Suite |
| --- | --- |
| Domain/unit (45 tests) | crypto (cipher/signer/JWT), cart, rules, flow, cache, request parser, CMS resolver |
| Integration — FibBookingSystem (14 tests) | migrations (1717/1718/1719), number ranges, **scan verdict matrix + audit**, **slot availability + calendar red-day logic** |
| Integration — FibBookingDemoData (7 tests) | seeded scanner access (least-privilege role/user, idempotency), seeded homepage layout (calendar element, scanner link, category assignment) |
| Storefront E2E (2 specs) | homepage calendar smoke (incl. scanner entry point), API booking flow |
| Scanner app (17 tests) | QR token parsing, in-memory session handling |

**Known gap**: administration component tests (CMS element/block) — requires
the Shopware admin Jest/Vitest infrastructure inside the plugin; the CI
covers the admin build only indirectly via the full asset build in the
CI image. Tracked as a follow-up in the ticketing plan.
