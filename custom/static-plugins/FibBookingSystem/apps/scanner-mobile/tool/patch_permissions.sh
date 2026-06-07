#!/usr/bin/env bash
# Idempotently injects the camera permission / usage string into the freshly
# generated platform shells. android/ios are regenerated per build by
# `flutter create .` (they are not committed), so this runs every CI build.
set -euo pipefail

target="${1:?usage: patch_permissions.sh android|ios}"

case "$target" in
  android)
    manifest="android/app/src/main/AndroidManifest.xml"
    if ! grep -q 'android.permission.CAMERA' "$manifest"; then
      # Insert the permission immediately before the <application> tag.
      sed -i.bak 's#\([[:space:]]*\)<application#\1<uses-permission android:name="android.permission.CAMERA"/>\n\1<application#' "$manifest"
      rm -f "$manifest.bak"
    fi
    ;;
  ios)
    plist="ios/Runner/Info.plist"
    usage="Scan ticket QR codes at the gate."
    /usr/libexec/PlistBuddy -c "Set :NSCameraUsageDescription ${usage}" "$plist" 2>/dev/null \
      || /usr/libexec/PlistBuddy -c "Add :NSCameraUsageDescription string ${usage}" "$plist"
    ;;
  *)
    echo "unknown target: $target (expected android|ios)" >&2
    exit 1
    ;;
esac
