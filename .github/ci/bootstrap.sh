#!/usr/bin/env bash
# Bring up the pre-baked CI image and adjust runtime-only state.
#
# The heavy lifting (composer install + plugin:install + demo data + storefront
# build) happens at IMAGE BUILD time, declared in .github/ci/Dockerfile. This
# script just builds the image (cached layer by layer) and starts the
# container. First build is slow (~5-10 min); every subsequent run that
# doesn't touch composer.lock or the source dirs reuses the cache and
# finishes in seconds.
#
# Inputs (env):
#   STOREFRONT_PORT  Optional. Default: 8080.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${REPO_ROOT}"

export STOREFRONT_PORT="${STOREFRONT_PORT:-8080}"
# Sales-channel host: real GitHub Actions runs Playwright on the same VM that
# binds the dockware container, so `localhost` works. Local `act` rehearsal
# runs the workflow inside a runner container; the CI container is a sibling
# on the host's Docker daemon, reachable only via `host.docker.internal`.
# Shopware routes per Host header, so the sales-channel domain has to match
# what Playwright actually sends. `$ACT` is set automatically by act.
STOREFRONT_HOST_DEFAULT="localhost"
if [ "${ACT:-}" = "true" ]; then
    STOREFRONT_HOST_DEFAULT="host.docker.internal"
fi
STOREFRONT_HOST="${STOREFRONT_HOST:-${STOREFRONT_HOST_DEFAULT}}"

.github/ci/cleanup-stack.sh

echo "▶ Building CI image (Docker layer cache will reuse anything unchanged)"
docker compose -f .github/ci/compose.ci.yml build

echo "▶ Starting CI container"
docker compose -f .github/ci/compose.ci.yml up -d --wait

echo "▶ Pointing sales channels at http://${STOREFRONT_HOST}:${STOREFRONT_PORT}"
# Direct UPDATE via mysql on purpose: bypasses entity-write listeners that may
# have been compiled into the image with services we don't run in CI.
docker exec fib-shopware-ci mysql -u root -proot shopware -e \
    "UPDATE sales_channel_domain SET url = 'http://${STOREFRONT_HOST}:${STOREFRONT_PORT}' WHERE url LIKE 'http%';"

# cache:clear after the raw SQL UPDATE — Shopware caches the domain→channel
# mapping compiled at image build time.
docker exec fib-shopware-ci bin/console cache:clear --no-warmup --no-interaction >/dev/null

echo "✅ Ready — storefront on http://${STOREFRONT_HOST}:${STOREFRONT_PORT}"
