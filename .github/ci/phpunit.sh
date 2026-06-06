#!/usr/bin/env bash
# Run the PHPUnit suites of all maintained plugins inside the CI container and
# copy the JUnit XML back to the host so `actions/upload-artifact` can pick it
# up. The CI compose stack runs without bind mounts (the image IS the project
# state), so files written by `docker exec` live only inside the container —
# without the `docker cp` step the upload silently grabs nothing.
#
# JUnit is copied out even if PHPUnit fails — the workflow's gate decides what
# to do with the outcome, and the test report needs the XML either way.
set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${REPO_ROOT}"

PLUGIN_PATHS=(
    "custom/static-plugins/FibBookingSystem"
    "custom/static-plugins/FibBookingDemoData"
)

overall_rc=0
for plugin_path in "${PLUGIN_PATHS[@]}"; do
    junit_path="${plugin_path}/build/junit-phpunit.xml"
    mkdir -p "${plugin_path}/build"
    docker exec -w /var/www/html fib-shopware-ci mkdir -p "${plugin_path}/build"

    echo "▶ PHPUnit: ${plugin_path}"
    docker exec -w /var/www/html fib-shopware-ci vendor/bin/phpunit \
        --configuration "${plugin_path}/phpunit.xml.dist" \
        --log-junit "${junit_path}"
    rc=$?

    docker cp "fib-shopware-ci:/var/www/html/${junit_path}" "${junit_path}" 2>/dev/null \
        || echo "::warning::Could not retrieve ${junit_path} from container"

    if [ "${rc}" -ne 0 ]; then
        overall_rc=1
    fi
done

exit "${overall_rc}"
