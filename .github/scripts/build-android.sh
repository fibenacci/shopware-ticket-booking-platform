#!/usr/bin/env bash
# Builds the mobile scanner Android APK and copies it to a versioned name.
#
# Regenerates the (uncommitted) platform shell, patches the camera permission,
# then builds a release APK. The Flutter template's release build signs with
# the debug key by default, so the APK installs out of the box — fully free, no
# Google account, no keystore secret required for the MVP. A proper upload key
# can be wired via android/key.properties later.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
APP_DIR="$REPO_ROOT/custom/static-plugins/FibBookingSystem/apps/scanner-mobile"
VERSION="${1:-dev}"

cd "$APP_DIR"
flutter --version
flutter create --org org.fibbooking --project-name booking_scanner --platforms=android .
flutter pub get
tool/patch_permissions.sh android
flutter build apk --release

cp build/app/outputs/flutter-apk/app-release.apk "build/scanner-${VERSION}.apk"
echo "Built build/scanner-${VERSION}.apk"
