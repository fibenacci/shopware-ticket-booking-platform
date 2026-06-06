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
DB_PASSWORD=<secret>
DB_ROOT_PASSWORD=<secret>
REDIS_PASSWORD=<secret>
MAILER_DSN=smtp://...
TRUSTED_PROXIES=...                       # optional; defaults cover the compose network
```

DNS: point `SHOP_DOMAIN` **and** `scanner.SHOP_DOMAIN` at the host, then:

```bash
docker compose -f compose.prod.yaml --env-file .env.prod up -d
```

## Persistent data

Named volumes: `db_data`, `redis_data`, `traefik_certs` (Let's Encrypt
account + certificates), `files`, `theme`, `media`, `thumbnail`, `sitemap`.
Backups: at least `db_data` + `media` + `files`.
