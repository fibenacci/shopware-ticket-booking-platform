#!/usr/bin/env bash
# Run the PHPUnit suites of all maintained plugins inside the CI container and
# copy the JUnit XML back to the host so `actions/upload-artifact` can pick it
# up. The CI compose stack runs without bind mounts (the image IS the project
# state), so files written by `docker exec` live only inside the container —
# without the `docker cp` step the upload silently grabs nothing.
#
# JUnit reports land in junit-artifacts/ under FLAT, stable names (one file
# per plugin): actions/upload-artifact strips the longest common path prefix
# of its inputs, so nested plugin paths would make the download paths in the
# publish-results job unpredictable. Uploading the directory keeps the
# artifact layout deterministic.
#
# JUnit is copied out even if PHPUnit fails — the workflow's gate decides what
# to do with the outcome, and the test report needs the XML either way.
set -uo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "${REPO_ROOT}"

ARTIFACT_DIR="junit-artifacts"
mkdir -p "${ARTIFACT_DIR}"

# plugin path → flat artifact name
PLUGIN_PATHS=(
    "custom/static-plugins/FibBookingSystem=phpunit-fib-booking-system.xml"
    "custom/static-plugins/FibBookingDemoData=phpunit-fib-booking-demo-data.xml"
)

overall_rc=0
for entry in "${PLUGIN_PATHS[@]}"; do
    plugin_path="${entry%%=*}"
    artifact_name="${entry#*=}"
    junit_path="${plugin_path}/build/junit-phpunit.xml"
    docker exec -w /var/www/html fib-shopware-ci mkdir -p "${plugin_path}/build"

    echo "▶ PHPUnit: ${plugin_path}"
    docker exec -w /var/www/html fib-shopware-ci vendor/bin/phpunit \
        --configuration "${plugin_path}/phpunit.xml.dist" \
        --log-junit "${junit_path}"
    rc=$?

    docker cp "fib-shopware-ci:/var/www/html/${junit_path}" "${ARTIFACT_DIR}/${artifact_name}" 2>/dev/null \
        || echo "::warning::Could not retrieve ${junit_path} from container"

    if [ "${rc}" -ne 0 ]; then
        overall_rc=1
    fi
done

exit "${overall_rc}"
