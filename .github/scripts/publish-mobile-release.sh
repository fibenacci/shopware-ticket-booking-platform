#!/usr/bin/env bash
# Creates (or reuses) the GitHub release for a scanner-v* tag and uploads the
# built APK + unsigned IPA. Requires GH_TOKEN in the environment.
set -euo pipefail

TAG="${1:?usage: publish-mobile-release.sh <tag> <file...>}"
shift

if ! gh release view "$TAG" >/dev/null 2>&1; then
  gh release create "$TAG" --title "$TAG" --generate-notes
fi

gh release upload "$TAG" "$@" --clobber
