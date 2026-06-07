# Booking Scanner — mobile (Flutter)

Native iOS/Android gate scanner for the FIB Booking System. A thin client over
the Admin-API scan contract — the **same** endpoints the web scanner
(`../scanner`) uses, so the server is unchanged and both share its tests. Design
rationale and the full distribution plan: [`../../docs/MOBILE_SCANNER_PLAN.md`](../../docs/MOBILE_SCANNER_PLAN.md).

## What it does

- Operator login (Admin-API password grant); **tokens live in memory only**,
  never on disk — mirrors the web scanner's posture.
- Continuous QR scanning via `mobile_scanner` (MLKit on Android, Vision on iOS).
  Handles all three payload shapes — static JSON, bare 64-hex token, and the
  rotating `FIBR1:…` TOTP wire — by forwarding the value **verbatim**; the
  server decides static vs rotating, so rotating QR needs no client logic.
- Check-in / check-out (toggle shown only when the server enables check-out),
  torch, duplicate-scan debounce, large colour-coded verdict.
- First-run **server URL** setup (https only, no silent default), stored in the
  OS keystore.

## Structure

```
lib/api/token.dart         extractScanToken + patterns (pure, tested)
lib/api/session.dart       in-memory tokens + expiry (pure, tested)
lib/api/scan_api.dart      OAuth + scan/config/stats, 1-shot refresh on 401
lib/config/server_config.dart  base-URL validation + keystore (pure validator)
lib/ui/*.dart              settings / login / scanner views
test/*.dart                ports of apps/scanner/src/api.test.js + more
```

The pure logic carries the unit tests; the views are intentionally thin.

## Build & run locally

Requires the Flutter SDK (incl. Xcode + iOS Simulator on macOS). The generated
platform shells (`android/`, `ios/`) are **not** committed — they're generated
on demand and the camera permission is patched in by `tool/patch_permissions.sh`.
The repo's Makefile wraps all of this:

```bash
make mobile-install    # install Flutter + CocoaPods via Homebrew (asks first)
make mobile-doctor     # check the toolchain (offers to install if missing)
make mobile-test       # Dart unit tests — no device needed
make mobile-setup      # one-time: generate platform shells + deps + permission
make mobile-devices    # list simulators/emulators/devices
make mobile-ios        # boot the iOS Simulator (macOS) and run
make mobile-android    # run on a started Android emulator/device
make mobile-run        # run on the default device (DEVICE=<id> to pick one)
make mobile-apk        # build a release APK locally
make mobile-clean      # drop generated shells + build output
```

Raw equivalent without make:

```bash
flutter create --org org.fibbooking --project-name booking_scanner .
flutter pub get && tool/patch_permissions.sh android   # or: ios
flutter run
```

> **Backend for a full scan test:** the app enforces a valid-HTTPS server URL
> (no self-signed, by design). The local `booking.docker` cert is self-signed,
> so for an end-to-end test point the app at a valid-TLS tunnel — `make
> scanner-ngrok` prints one. The UI/login flow itself runs fine in the
> simulator without a backend.

## Releases

Pushing a `scanner-v*` tag runs `.github/workflows/mobile-release.yml`, which
builds a signed **`.apk`** (debug-key default — installs out of the box) and an
**unsigned `.ipa`**, and attaches both to the GitHub Release.

- **Android**: download the `.apk`, allow "install from unknown sources", tap.
- **iOS**: see [`SIDELOADING.md`](SIDELOADING.md) — install the unsigned `.ipa`
  with your own **free** Apple ID via AltStore / Sideloadly. No paid account.
