#!/usr/bin/env bash
# Runs the mobile scanner's Dart analysis + unit tests (pure logic, no device).
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$REPO_ROOT/custom/static-plugins/FibBookingSystem/apps/scanner-mobile"

flutter --version
flutter pub get
flutter analyze
flutter test
