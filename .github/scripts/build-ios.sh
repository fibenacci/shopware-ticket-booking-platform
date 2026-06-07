#!/usr/bin/env bash
# Builds an UNSIGNED iOS .ipa for the mobile scanner.
#
# Apple has no free distribution licence, so the open-source path is to ship an
# unsigned .ipa and let each user sign it with their own free Apple ID via
# AltStore / Sideloadly (see the app's SIDELOADING.md). Runs on a macOS runner
# (free for public repos). Regenerates the (uncommitted) platform shell and
# patches the camera usage string first.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
APP_DIR="$REPO_ROOT/custom/static-plugins/FibBookingSystem/apps/scanner-mobile"
VERSION="${1:-dev}"

cd "$APP_DIR"
flutter --version
flutter create --org org.fibbooking --project-name booking_scanner --platforms=ios .
flutter pub get
tool/patch_permissions.sh ios
flutter build ios --release --no-codesign

# Repackage the .app into an unsigned .ipa (Payload/ zip — the .ipa layout).
STAGE="build/ipa"
rm -rf "$STAGE"
mkdir -p "$STAGE/Payload"
cp -R build/ios/iphoneos/Runner.app "$STAGE/Payload/"
( cd "$STAGE" && zip -qr "scanner-${VERSION}-unsigned.ipa" Payload )
echo "Built $APP_DIR/$STAGE/scanner-${VERSION}-unsigned.ipa"
