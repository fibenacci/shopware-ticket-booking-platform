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

## Host configuration (`.env.prod`)

```bash
SHOPWARE_IMAGE=ghcr.io/<owner>/fib-booking-system:latest
APP_URL=https://booking.example.com
APP_SECRET=<openssl rand -hex 32>
DB_PASSWORD=<secret>
DB_ROOT_PASSWORD=<secret>
REDIS_PASSWORD=<secret>
MAILER_DSN=smtp://...
WEB_PORT=8000            # port behind the reverse proxy / TLS terminator
TRUSTED_PROXIES=...      # IP of the upstream proxy
```

```bash
docker compose -f compose.prod.yaml --env-file .env.prod up -d
```

## Persistent data

Named volumes: `db_data`, `redis_data`, `files`, `theme`, `media`,
`thumbnail`, `sitemap`. Backups: at least `db_data` + `media` + `files`.
