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
make phpstan            # PHPStan level 6 (.build/phpstan.neon)
make php-cs-fixer       # auto-fix (.build/php-cs-fixer.php, @PSR12 + @Symfony)
make php-cs-fixer-check # dry-run — identical to the CI check
```

## E2E (Playwright)

The E2E tests run against the local storefront and need a booking resource
plus a linked product:

```bash
make e2e-install        # one-time: npm install + Chromium
FIB_BOOKING_PRODUCT_ID=... FIB_BOOKING_RESOURCE_ID=... make e2e
make e2e-ui             # interactive UI mode
make e2e-report         # open the latest HTML report
```

Without the IDs set, the tests skip themselves (`test.skip`).
