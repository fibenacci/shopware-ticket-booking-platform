#!/usr/bin/env bash
# Tear down any leftover Shopware CI container. Idempotent — safe to run when
# nothing is up. Used by bootstrap.sh (pre-flight) and teardown.sh (post-job).
#
# The local dev stack (compose.yaml, fib-shopware / fib-mariadb / ...) is
# never started in CI, so we don't touch those names — only the CI container.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${REPO_ROOT}"

docker compose -f .github/ci/compose.ci.yml down --remove-orphans 2>/dev/null || true
docker rm -f fib-shopware-ci >/dev/null 2>&1 || true
