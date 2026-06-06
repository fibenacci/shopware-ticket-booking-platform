# Deployment (Docker)

The production deployment follows the
[official Shopware Docker pattern](https://developer.shopware.com/docs/guides/hosting/installation-updates/docker.html).

## Building blocks

| File | Purpose |
|---|---|
| `Dockerfile` | Multi-stage: `shopware-cli project ci` (composer `--no-dev`, asset build) → `shopware/docker-base` runtime (PHP-FPM + Caddy, port 8000) |
| `.shopware-project.yml` | Configures the deployment helper: migrations + extension lifecycle run automatically on container start |
| `compose.prod.yaml` | Single-host deployment: `init`, `web`, `worker`, `scheduler`, `db`, `redis` |
| `.github/workflows/docker-publish.yml` | Builds + pushes `ghcr.io/<owner>/fib-booking-system` |

## Deploy flow

1. Push to `trunk` or a `v*` tag → GitHub Actions builds the image and pushes it to GHCR
2. On the host: `docker compose -f compose.prod.yaml --env-file .env.prod pull && … up -d`
3. The `init` service runs the **deployment helper**:
   - first boot ever: full install (`system:install`)
   - otherwise: migrations, plugin install/update/activation, theme, cache
4. Then `web` (HTTP), `worker` (messenger queue) and `scheduler` (scheduled tasks) start

## Edge: Traefik (TLS + routing)

`compose.prod.yaml` ships a **Traefik v3** edge:

```
Internet ──► Traefik :80/:443 ── Host(`<SHOP_DOMAIN>`)          ──► web (:8000)
             │  HTTP→HTTPS       Host(`scanner.<SHOP_DOMAIN>`)  ──► scanner (:80, /api → web)
             └─ Let's Encrypt (HTTP-01, auto-renewal, certs in `traefik_certs`)
```

- Routing is declared as **labels on the services** — adding a host later is
  one label, no proxy config files.
- The scanner app ships as its own image
  (`ghcr.io/<owner>/fib-booking-scanner`, built from
  `apps/scanner/Dockerfile`) and proxies `/api` same-origin to `web`.
- Local dev is unaffected: the dinghy proxy + nginx ingress stay; the
  application itself is proxy-agnostic (`TRUSTED_PROXIES`, `X-Forwarded-*`).
- If the target later becomes Kubernetes/PaaS, drop the `traefik` service —
  the app images work unchanged behind any ingress.

## Host configuration (`.env.prod`)

```bash
SHOP_DOMAIN=booking.example.com           # APP_URL becomes https://<SHOP_DOMAIN>
ACME_EMAIL=ops@example.com                # Let's Encrypt notifications
SHOPWARE_IMAGE=ghcr.io/<owner>/fib-booking-system:latest
SCANNER_IMAGE=ghcr.io/<owner>/fib-booking-scanner:latest
APP_SECRET=<openssl rand -hex 32>
ALTCHA_SECRET_KEY=<openssl rand -hex 32>  # HMAC secret for the ALTCHA captcha (see Bot-Protection)
DB_PASSWORD=<secret>
DB_ROOT_PASSWORD=<secret>
REDIS_PASSWORD=<secret>
MAILER_DSN=smtp://...
TRUSTED_PROXIES=...                       # optional; defaults cover the compose network
SENTRY_DSN=...                            # optional; empty = error tracking disabled
SENTRY_RELEASE=...                        # optional; e.g. the deployed image tag
ADMIN_ALLOWED_IPS=...                     # optional; CIDR list — locks /admin to office/VPN IPs
```

Optional hardening profile (behavioural bot/scan detection, see
[Bot Protection](Bot-Protection)):

```bash
docker compose -f compose.prod.yaml --env-file .env.prod --profile crowdsec up -d
```

DNS: point `SHOP_DOMAIN` **and** `scanner.SHOP_DOMAIN` at the host, then:

```bash
docker compose -f compose.prod.yaml --env-file .env.prod up -d
```

## Performance & search

- **Caching (always on in prod)**: sessions + locks live in Redis (compose
  env), the object cache, carts and number ranges are Redis-backed via
  `config/packages/prod/redis.yaml` — shared across web/worker/scheduler
  replicas. Dev/CI keep the filesystem cache.
- **Search (opt-in)**: product search defaults to MySQL, which is fine for
  small/medium catalogs. For large catalogs start OpenSearch:
  `SHOPWARE_ES_ENABLED=1` in `.env.prod` plus
  `docker compose --profile search … up -d`, then run `bin/console es:index`
  once in the `web` container.

## Observability

- **Errors/Tracing**: Sentry via `SENTRY_DSN` (see `.env.prod` above).
- **Edge metrics**: Traefik exposes Prometheus metrics on the internal
  `:8082` entrypoint (edge network only, not published) — ready for a
  future Prometheus/Grafana or Grafana-Cloud agent.
- **Access logs**: JSON in the `traefik_logs` volume (forensics, CrowdSec).
- **Ops tooling**: FroshTools in the admin (queue, scheduled tasks, logs).

## Persistent data

Named volumes: `db_data`, `redis_data`, `traefik_certs` (Let's Encrypt
account + certificates), `files`, `theme`, `media`, `thumbnail`, `sitemap`.
Backups: at least `db_data` + `media` + `files`.
