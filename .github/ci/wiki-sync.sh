#!/usr/bin/env bash
set -euo pipefail

# Assembles wiki pages from docs/ + plugin docs and pushes them to the
# GitHub wiki repository. Requires: GH_TOKEN, GITHUB_REPOSITORY, GITHUB_SHA.
# NOTE: the wiki must be initialized once (create any page in the UI) before
# this script can push to it.

PLUGIN_DIR="custom/static-plugins/FibBookingSystem"
STAGING="wiki-staging"

echo "▶ Assembling wiki pages..."
mkdir -p "$STAGING"
# Project docs map 1:1 to wiki pages
cp docs/*.md "$STAGING"/
# Plugin docs get a Plugin- prefix
cp "$PLUGIN_DIR/README.md" "$STAGING/Plugin-FibBookingSystem.md"
cp "$PLUGIN_DIR/docs/ARCHITECTURE_PLAN.md" "$STAGING/Plugin-Architecture-Plan.md"
ls -la "$STAGING"/

echo "▶ Cloning wiki repository..."
if ! git clone "https://x-access-token:${GH_TOKEN}@github.com/${GITHUB_REPOSITORY}.wiki.git" wiki-repo; then
    echo "::error::Wiki repo not found. Initialize the wiki once via the GitHub UI (create any page), then re-run."
    exit 1
fi

rsync -av --delete --exclude '.git' "$STAGING"/ wiki-repo/
cd wiki-repo
git config user.name "github-actions[bot]"
git config user.email "github-actions[bot]@users.noreply.github.com"
git add -A

if git diff --cached --quiet; then
    echo "✅ Wiki already up to date."
else
    git commit -m "docs: sync from ${GITHUB_SHA}"
    git push
    echo "✅ Wiki updated."
fi
