#!/usr/bin/env bash
# Run `shopware-cli extension validate` on every maintained plugin/app that
# carries a composer.json or manifest.xml. Fails if any validation fails.
set -euo pipefail

failed=0
for dir in custom/static-plugins/*/ custom/plugins/*/; do
    dir=${dir%/}

    # Unexpanded glob (directory does not exist) — nothing to validate.
    if [ ! -d "$dir" ]; then
        continue
    fi

    if [ ! -f "$dir/composer.json" ] && [ ! -f "$dir/manifest.xml" ]; then
        echo "::notice::Skipping $dir (no composer.json or manifest.xml)"
        continue
    fi

    echo "::group::shopware-cli extension validate $dir"
    if ! shopware-cli extension validate "$dir"; then
        echo "::error file=$dir::validation failed"
        failed=1
    fi
    echo "::endgroup::"
done

if [ "$failed" -eq 1 ]; then
    echo "::error::One or more plugins failed validation — see the grouped logs above."
    exit 1
fi

echo "✅ All maintained plugins validated."
