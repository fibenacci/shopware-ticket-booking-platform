#!/usr/bin/env bash
# Syntax-check every storefront/administration JS file of the maintained
# plugins with `node --check` (catches parse errors without a build).
set -euo pipefail

find custom/static-plugins/*/src/Resources/app -name '*.js' -not -path '*/node_modules/*' -print0 \
    | xargs -0 -n1 node --check

echo "✅ Plugin JS syntax OK"
