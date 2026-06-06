# Local Development

## Requirements

- Docker Desktop
- `*.docker` domains must resolve to `127.0.0.1` (e.g. via `dnsmasq`:
  `address=/.docker/127.0.0.1`). Without it, use the fallback ports.

No local PHP/Composer/Node needed.

## Start

```bash
make up
```

The first run bootstraps everything:

1. `.env.local` is created from `.env.example`
2. `composer install` runs via the `shopware-cli` image (scaffolds `bin/`, `config/`, `vendor/`, …)
3. The Docker stack starts (MariaDB, Redis, Mailpit, Adminer, Shopware/dockware)
4. `docker/setup-dev.sh` fresh-installs Shopware (`system:install --basic-setup`),
   installs + activates **FibBookingSystem**, compiles the theme

`make up` is idempotent — with an existing database only migrations and
plugin updates run.

## Services

All HTTP traffic flows through a central **ingress** container
(`docker/ingress.conf`) behind the dinghy proxy — one entry point for
`booking.docker` and every subdomain, proxying to explicit container names
(compose service aliases collide with other stacks on the shared proxy
network).

| Service    | URL                                                    |
|------------|--------------------------------------------------------|
| Storefront | http://booking.docker (or http://127.0.0.1:8090)       |
| Admin      | http://booking.docker/admin (admin / shopware)         |
| Scanner    | http://scanner.booking.docker — camera testing needs a secure context: use http://127.0.0.1:8096 or `make scanner-ngrok` (public HTTPS tunnel for real phones, needs `NGROK_AUTHTOKEN` in `.env.local`) |
| Mailpit    | http://mail.booking.docker (or http://127.0.0.1:8095)  |
| Adminer    | http://adminer.booking.docker                          |
| Watchers   | http://watch-storefront.booking.docker / watch-admin.… |

## Key targets

The Makefile is split by responsibility into `make/*.mk`; `make help`
lists all targets.

```bash
make logs               # follow shopware logs
make shell              # bash inside the shopware container
make cache / make theme # clear cache / compile theme
make watch-storefront   # storefront watcher (hot reload)
make demodata           # generic Shopware demo data (products/categories/customers)
make seed-booking       # booking demo data: bookable products, resources, reservation + QR ticket
make down               # remove containers (DB volume survives)
make down-volumes       # clean slate including database
```

`make seed-booking` runs `fib-booking:demodata` from the **FibBookingDemoData**
plugin (dev/CI only). It is idempotent (deterministic ids) and prints
`FIB_BOOKING_PRODUCT_ID` / `FIB_BOOKING_RESOURCE_ID` for the Playwright suite.

## Configuration

- `.env` — committed defaults (flex-generated)
- `.env.local` — local overrides, gitignored, loaded by the container as
  `env_file` (source: `.env.example`)
- `XDEBUG_ENABLED=1 make up` — enable Xdebug (default: off)
