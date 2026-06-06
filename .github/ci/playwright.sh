#!/usr/bin/env bash
# Run the FibBookingSystem Playwright suite against the CI storefront.
#
# The booking E2E spec needs FIB_BOOKING_PRODUCT_ID / FIB_BOOKING_RESOURCE_ID.
# The demo data is baked into the CI image with deterministic ids; re-running
# the (idempotent) seed command prints them as a machine-readable block which
# we eval here.
#
# The JUnit report lands in junit-artifacts/ under a flat, stable name — same
# rationale as in phpunit.sh (deterministic artifact layout for the
# publish-results job). It is staged even when the suite fails.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${REPO_ROOT}"

E2E_DIR="${REPO_ROOT}/custom/static-plugins/FibBookingSystem"
ARTIFACT_DIR="${REPO_ROOT}/junit-artifacts"
mkdir -p "${ARTIFACT_DIR}"

stage_junit() {
    cp "${E2E_DIR}/test-results/junit.xml" "${ARTIFACT_DIR}/playwright-booking.xml" 2>/dev/null \
        || echo "::warning::No Playwright JUnit report found to stage"
}
trap stage_junit EXIT

STOREFRONT_PORT="${STOREFRONT_PORT:-8080}"
STOREFRONT_HOST_DEFAULT="localhost"
if [ "${ACT:-}" = "true" ]; then
    STOREFRONT_HOST_DEFAULT="host.docker.internal"
fi
BASE_URL="${BASE_URL:-http://${STOREFRONT_HOST_DEFAULT}:${STOREFRONT_PORT}}"

echo "▶ Resolving demo data ids from the CI container"
eval "$(docker exec fib-shopware-ci bin/console fib-booking:demodata | grep -E '^FIB_BOOKING_(PRODUCT|RESOURCE)_ID=')"
echo "  product:  ${FIB_BOOKING_PRODUCT_ID}"
echo "  resource: ${FIB_BOOKING_RESOURCE_ID}"

cd "${E2E_DIR}"

echo "▶ Installing Playwright deps"
if [ -f package-lock.json ]; then
    npm ci
else
    npm install --no-audit --no-fund
fi

echo "▶ Installing Chromium"
npx playwright install --with-deps chromium

echo "▶ Running Playwright (BASE_URL=${BASE_URL})"
BASE_URL="${BASE_URL}" \
FIB_BOOKING_PRODUCT_ID="${FIB_BOOKING_PRODUCT_ID}" \
FIB_BOOKING_RESOURCE_ID="${FIB_BOOKING_RESOURCE_ID}" \
    npx playwright test
