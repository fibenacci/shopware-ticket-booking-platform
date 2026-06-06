#!/usr/bin/env bash
set -euo pipefail

# Result gate: fails when any passed "name=result" pair is not "success".
# Usage: gate.sh "<failure message>" "job-a=success" "job-b=failure" ...

message="$1"
shift

failed=0
for pair in "$@"; do
    name="${pair%%=*}"
    result="${pair#*=}"
    if [ "$result" = "success" ]; then
        echo "✅ ${name}: ${result}"
    else
        echo "❌ ${name}: ${result}"
        failed=1
    fi
done

if [ "$failed" = "1" ]; then
    echo "::error::${message}"
    exit 1
fi

echo "✅ All gated jobs passed."
