#!/usr/bin/env bash
# Tear down the CI stack. Dumps recent shopware logs first when called with
# "--with-logs" (typical use: invoke from a failure step before the success
# step that just stops services).
#
# Usage:
#   .github/ci/teardown.sh             # stop the container quietly
#   .github/ci/teardown.sh --with-logs # dump logs then stop the container
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${REPO_ROOT}"

if [ "${1:-}" = "--with-logs" ]; then
    echo "▶ Service status"
    docker compose -f .github/ci/compose.ci.yml ps || true
    echo "▶ Container stdout (dockware boot)"
    docker compose -f .github/ci/compose.ci.yml logs --tail=120 shopware || true
    echo "▶ Shopware app logs (var/log)"
    docker exec fib-shopware-ci sh -c 'tail -n 200 var/log/*.log 2>/dev/null' || true
fi

echo "▶ Stopping stack"
"$(dirname "${BASH_SOURCE[0]}")/cleanup-stack.sh"
