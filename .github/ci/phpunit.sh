#!/usr/bin/env bash
# Run the FibBookingSystem PHPUnit suites (unit + integration) inside the CI
# container and copy the JUnit XML back to the host so
# `actions/upload-artifact` can pick it up. The CI compose stack runs without
# bind mounts (the image IS the project state), so files written by
# `docker exec` live only inside the container — without the `docker cp` step
# the upload silently grabs nothing.
#
# JUnit is copied out even if PHPUnit fails — the workflow's gate decides what
# to do with the outcome, and the test report needs the XML either way.
set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${REPO_ROOT}"

PLUGIN_PATH="custom/static-plugins/FibBookingSystem"
JUNIT_PATH="${PLUGIN_PATH}/build/junit-phpunit.xml"

mkdir -p "${PLUGIN_PATH}/build"
docker exec -w /var/www/html fib-shopware-ci mkdir -p "${PLUGIN_PATH}/build"

echo "▶ PHPUnit: ${PLUGIN_PATH} (unit + integration)"
docker exec -w /var/www/html fib-shopware-ci vendor/bin/phpunit \
    --configuration "${PLUGIN_PATH}/phpunit.xml.dist" \
    --log-junit "${JUNIT_PATH}"
rc=$?

docker cp "fib-shopware-ci:/var/www/html/${JUNIT_PATH}" "${JUNIT_PATH}" 2>/dev/null \
    || echo "::warning::Could not retrieve ${JUNIT_PATH} from container"

exit "${rc}"
