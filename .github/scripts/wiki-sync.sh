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
# Plugin docs get a Plugin- prefix; SNAKE_CASE.md → Title-Case page names
cp "$PLUGIN_DIR/README.md" "$STAGING/Plugin-FibBookingSystem.md"
for doc in "$PLUGIN_DIR"/docs/*.md; do
    base="$(basename "$doc" .md)"
    page="$(echo "$base" | tr '[:upper:]' '[:lower:]' | tr '_' ' ' | awk '{for (i=1; i<=NF; i++) $i=toupper(substr($i,1,1)) substr($i,2)}1' | tr ' ' '-')"
    cp "$doc" "$STAGING/Plugin-$page.md"
done
ls -la "$STAGING"/

echo "▶ Cloning wiki repository..."
# Token via header, NOT in the URL: keeps it out of argv, the remote config
# on disk and any later `git remote -v` / error output.
AUTH_HEADER="AUTHORIZATION: basic $(printf 'x-access-token:%s' "${GH_TOKEN}" | base64 | tr -d '\n')"
if ! git -c http.extraHeader="$AUTH_HEADER" clone "https://github.com/${GITHUB_REPOSITORY}.wiki.git" wiki-repo; then
    echo "::warning::Wiki repo not found — one-time setup needed: open the repository's Wiki tab, click 'Create the first page', save it (any content), then re-run this workflow. Docs will sync automatically afterwards."
    exit 0
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
    git -c http.extraHeader="$AUTH_HEADER" push
    echo "✅ Wiki updated."
fi
