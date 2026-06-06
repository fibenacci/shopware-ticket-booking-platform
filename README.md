# FIB Booking System — Shopware 6 Shop

> ⚠️ **Prototype** — this project is a prototype for evaluating a
> Shopware-based booking & ticketing platform. It is not production-hardened
> yet: APIs, data model and configuration may change without migration paths,
> and some areas (admin CRUD UI, wallet certificates, scanner deployment)
> still need productization before going live.

Shopware 6.7 project (based on `shopware/production`) containing the
**FibBookingSystem** plugin (`custom/static-plugins/FibBookingSystem`) — a
reservation and booking system with holds, reservations, tickets and QR codes.

## Quickstart

Requirements: Docker Desktop. No local PHP/Composer needed.

```bash
make up
```

That's it. The first run bootstraps everything:

1. Creates `.env.local` from `.env.example`
2. Runs `composer install` via the `shopware-cli` image (scaffolds `bin/`, `config/`, `vendor/`, …)
3. Starts the stack (MariaDB, Redis, Mailpit, Adminer, scanner app, ingress, Shopware via dockware)
4. Fresh-installs Shopware (`system:install --basic-setup`), installs + activates the plugins, seeds the booking demo data (homepage calendar, packages, slots, scanner access), compiles the theme

## URLs

All `*.booking.docker` hosts route through the central ingress
(`docker/ingress.conf`) behind the [dinghy HTTP proxy](https://github.com/codekitchen/dinghy-http-proxy)
that `make up` starts automatically. Make sure `*.docker` resolves to
`127.0.0.1` (e.g. via `dnsmasq` — `address=/.docker/127.0.0.1`); without it,
use the `127.0.0.1` fallback ports.

| Service | URL | Fallback / notes |
| --- | --- | --- |
| Storefront (homepage with booking calendar) | http://booking.docker | http://127.0.0.1:8090 |
| Administration | http://booking.docker/admin | login `admin` / `shopware` |
| Scanner app (operators) | http://scanner.booking.docker | login `scanner` / `fib-scanner-demo!` (demo); camera needs a secure context → use http://127.0.0.1:8096 or `make scanner-ngrok` |
| Scanner via public HTTPS (phone camera) | `make scanner-ngrok` prints the tunnel URL | needs `NGROK_AUTHTOKEN` in `.env.local`; stop via `make scanner-ngrok-stop` |
| Customer ticket area | http://booking.docker/account/fib-booking/tickets | requires storefront login |
| Mailpit (mail UI) | http://mail.booking.docker | http://127.0.0.1:8095 |
| Adminer (DB UI) | http://adminer.booking.docker | server `mariadb`, `root` / `root` |
| Storefront watcher | http://watch-storefront.booking.docker | after `make watch-storefront` |
| Admin watcher | http://watch-admin.booking.docker | after `make watch-admin` |

API endpoints (selection):

| API | URL |
| --- | --- |
| Booking Store API | `POST /store-api/fib-booking/availability` · `…/hold` · `…/calendar` |
| Storefront JSON (widget/calendar) | `POST /fib-booking/availability` · `…/hold` · `…/cart/add` · `…/calendar` |
| Wallet downloads (signed links) | `GET /fib-booking/wallet/{ticketId}/apple.pkpass` · `…/google` |
| Ticket scan (Admin API, ACL-guarded) | `POST /api/_action/fib-booking/ticket/scan` |

## Daily workflow

```bash
make help               # all targets
make up                 # start (idempotent — also applies migrations/plugin updates)
make logs               # follow shopware logs
make shell              # bash inside the shopware container
make cache / make theme # clear cache / compile theme
make watch-storefront   # storefront watcher (hot reload)
make demodata           # generate demo products/categories/customers
make down               # stop + remove containers (DB volume survives)
make down-volumes       # clean slate including database
```

## Tests & quality

```bash
make test               # all PHPUnit tests (unit + integration)
make test-unit          # unit only (no DB needed)
make test-integration   # integration (boots Shopware kernel, needs running stack)
make phpstan            # static analysis (.build/phpstan.neon)
make php-cs-fixer       # code style auto-fix (.build/php-cs-fixer.php)
make php-cs-fixer-check # code style check (CI mode)
```

E2E (Playwright, against the running stack):

```bash
make e2e-install        # one-time: npm install + chromium
FIB_BOOKING_PRODUCT_ID=... FIB_BOOKING_RESOURCE_ID=... make e2e
```

## CI

`.github/workflows/ci.yml` runs on every PR / push to `trunk` (parallel
checks feed quality/security gates, the gates feed the test stack):

- **Static Quality** (CS-Fixer + PHPStan) · **JS/TS Lint** (scanner build +
  plugin JS) · **Shopware Extension Validate** → **Quality Gate**
- **Composer Audit** · **NPM Audit** → **Security Gate**
- **PHPUnit + Playwright** — pre-baked CI stack (`.github/ci/`) with seeded
  demo data (FibBookingDemoData)
- **Publish Test Results** (checks + PR description) → **Report CI** (strict gate)

Rehearse locally: `make ci-bootstrap && make ci-phpunit && make ci-e2e && make ci-teardown`.

## Demo data

```bash
make seed-booking    # bookable products, resources, confirmed reservation + QR ticket
```

Provided by the dev/CI-only plugin `custom/static-plugins/FibBookingDemoData`
(`bin/console fib-booking:demodata`, idempotent, deterministic ids).

## Docker deployment

The production image follows the [official Shopware docker pattern](https://developer.shopware.com/docs/guides/hosting/installation-updates/docker.html):

- `Dockerfile` — stage 1 builds the project with `shopware-cli project ci`
  (composer `--no-dev`, asset build), stage 2 is the slim
  `shopware/docker-base` runtime (PHP-FPM + Caddy on port 8000).
- `.shopware-project.yml` — deployment-helper config: on every container
  start migrations run and extensions (FibBookingSystem) are
  installed/updated/activated automatically.
- `.github/workflows/docker-publish.yml` — builds and pushes
  `ghcr.io/<owner>/fib-booking-system` on pushes to `trunk` and `v*` tags.

Deploy on a host:

```bash
# .env.prod: APP_URL, APP_SECRET, DB_PASSWORD, DB_ROOT_PASSWORD, REDIS_PASSWORD, SHOPWARE_IMAGE
docker compose -f compose.prod.yaml --env-file .env.prod up -d
```

The `init` service runs the deployment-helper (install/migrations/plugins),
then `web` (HTTP), `worker` (messenger queue) and `scheduler` (scheduled
tasks) start.

## Documentation

Structured docs live in [`docs/`](docs/Home.md) and are synced to the GitHub
wiki on every push to `trunk` (`.github/workflows/wiki-sync.yml`) — including
the plugin docs. The repository is the source of truth; don't edit the wiki
directly.

## Project layout

```
custom/static-plugins/FibBookingSystem/   # the booking plugin (source of truth)
compose.yaml                              # local dev stack
compose.prod.yaml                         # production deployment
Dockerfile                                # production image
docker/setup-dev.sh                       # idempotent dev setup (run by make up)
make/*.mk                                 # Makefile modules (stack, assets, quality, tests, e2e)
docs/                                     # project docs (synced to GitHub wiki)
.build/                                   # phpstan + php-cs-fixer configs
.github/workflows/ci.yml                  # CI pipeline
.github/workflows/docker-publish.yml      # image publish pipeline
.github/workflows/wiki-sync.yml           # docs → wiki sync
```
