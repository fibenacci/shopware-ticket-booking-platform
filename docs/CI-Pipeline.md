# CI Pipeline

`.github/workflows/ci.yml` runs on every PR and push to `trunk`: parallel
checks feed two gates, the gates feed the test stack, results get published,
a strict final gate reports.

```
Static Quality ──┐
JS/TS Lint ──────┼─► Quality Gate ──┐
Shopware Extension Validate ─┘      ├─► PHPUnit + Playwright ─► Publish Test Results ─► Report CI
Composer Audit ──┐                  │
NPM Audit ───────┼─► Security Gate ─┘
CodeQL ──────────┘
```

## Jobs

| Job | Checks |
|---|---|
| **Static Quality** | PHP-CS-Fixer (dry-run) + PHPStan level max + PHPMD |
| **JS/TS Lint** | Scanner app production build (type/syntax gate) + `node --check` over plugin storefront JS |
| **Composer Audit** | Known vulnerabilities in `composer.lock` |
| **NPM Audit** | High advisories in scanner app + Playwright suite |
| **CodeQL** | SAST (`security-extended`) over the scanner app + plugin JS and all workflows — reusable workflow (`codeql.yml`), also re-scans weekly; scope in `.github/codeql/codeql-config.yml`. PHP is not CodeQL-supported — covered by PHPStan + composer audit |
| **Shopware Extension Validate** | `shopware-cli extension validate` over all plugins |
| **Quality Gate / Security Gate** | Aggregate gates — tests only run when both are green |
| **PHPUnit + Playwright** | Pre-baked CI stack (see below): PHPUnit unit+integration inside the container, booking Playwright suite against the storefront with seeded demo data |
| **Publish Test Results** | `dorny/test-reporter` checks + test-result block in the PR description |
| **Report CI** | Strict final gate (failure/cancelled/skipped ⇒ red) |

## The CI stack (`.github/ci/`)

The test job builds a **pre-baked image** (`.github/ci/Dockerfile`, based on
`dockware/shopware:6.7.10.2`): composer install, plugin install/activation
(FibBookingSystem + FibBookingDemoData), **demo data seeding**
(`fib-booking:demodata`) and the storefront build all happen at image build
time — Docker layer cache makes repeat runs cheap. The running container
needs zero setup.

| Script | Purpose |
|---|---|
| `bootstrap.sh` | build + `up --wait` + point sales channel at `localhost:8080` |
| `phpunit.sh` | run plugin suites in the container, `docker cp` JUnit XML out |
| `playwright.sh` | resolve demo-data ids from the container, run the E2E suite |
| `teardown.sh` / `cleanup-stack.sh` | stop the stack (optionally dump logs) |
| `symfony-ci-overrides.yaml` | filesystem cache + mock sessions + sync messenger — no Redis/RabbitMQ sidecars |

Shared helpers live in `.github/scripts/`: `gate.sh` (outcome aggregation),
`validate-plugins.sh`, `update-pr-test-results.js`, `wiki-sync.sh`.

## Demo data in CI

The **FibBookingDemoData** plugin (dev/CI only, `require-dev`) seeds bookable
products, booking resources, a confirmed reservation and a QR ticket — with
**deterministic ids**, so re-running upserts instead of duplicating.
`playwright.sh` re-runs the idempotent command and evals its
`FIB_BOOKING_PRODUCT_ID=… / FIB_BOOKING_RESOURCE_ID=…` output for the E2E
suite.

## Reproduce locally

The CI pipeline is fully rehearsable on a dev machine (the CI stack uses its
own container name `fib-shopware-ci` and never touches the dev stack):

```bash
make ci-bootstrap   # build + start the pre-baked stack
make ci-phpunit     # unit + integration suites in the container
make ci-e2e         # Playwright against http://localhost:8080
make ci-teardown    # stop (WITH_LOGS=1 to dump logs first)
```

Static checks use the same targets as local dev: `make php-cs-fixer-check`,
`make phpstan`, `make test-unit`.

## Image publishing & wiki

- `.github/workflows/docker-publish.yml` — builds and pushes the production
  image to GHCR on `trunk` pushes and `v*` tags. See [Deployment](Deployment).
- `.github/workflows/wiki-sync.yml` — syncs `docs/` + plugin docs to the
  GitHub wiki on every push to `trunk`.
