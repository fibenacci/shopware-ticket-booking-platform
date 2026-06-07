# Mobile scanner app — plan (Flutter, CI-built artifacts)

A native iOS + Android operator app for scanning tickets at the gate, built
in CI and attached to GitHub Releases as downloadable `.apk` / `.ipa`. This is
a **plan** — nothing here is built yet; it exists to agree the approach before
the first line of Dart.

The app is a thin, native front-end over the **same Admin-API scan contract**
the existing web scanner (`apps/scanner`) already uses. No new server code is
required for a minimum-viable app; the server is the single source of truth and
stays unchanged.

> **Status:** MVP scaffolded under `apps/scanner-mobile/` — the contract port
> (`lib/api/`, `lib/config/`) with Dart unit tests, the UI (`lib/ui/`), the CI
> (`.github/scripts/build-*.sh`, `.github/workflows/mobile-release.yml`) and
> `SIDELOADING.md` are in place. First `flutter` toolchain run happens in CI
> (no SDK in the dev image); package versions and the `mobile_scanner` widget
> API are validated/bumped on that first run.

---

## 1. Framework decision: Flutter

Chosen over Capacitor/React-Native. Rationale for this project:

- **One codebase, two true-native binaries** with a single toolchain (Dart +
  Flutter SDK) — exactly what "ship an Android *and* an iOS artifact from CI"
  wants.
- **Best-in-class camera/scanning**: `mobile_scanner` wraps Google MLKit
  (Android) and AVFoundation/Vision (iOS) — fast, continuous, autofocus,
  torch, works at gate-light levels far better than a `<video>`+canvas web
  decoder.
- **No dependence on the web app's runtime**: the gate device runs a signed
  binary, not a tab pointed at an ngrok tunnel. Better for unreliable venue
  Wi-Fi and for handing a phone to door staff.
- Trade-off accepted: the existing JS scanner logic is **not reused as code** —
  but it *is* reused as a **contract**. The whole client is ~5 small files
  (`api.js`, token patterns, 3 endpoints); re-expressing that in Dart is a
  day's work, and the JS test cases port 1:1 to Dart unit tests.

The web scanner (`apps/scanner`) **stays** — it remains the zero-install option
(operators with a laptop/desktop, quick demos via the ingress URL). The Flutter
app is the field tool. Both speak the identical API, so server behaviour and
tests cover both.

---

## 2. The contract being consumed (already exists, do not change)

From `apps/scanner/src/api.js` + `BookingTicketScanController`:

| Purpose | Call |
|---|---|
| Login | `POST /api/oauth/token` — `grant_type=password`, `client_id=administration`, `scopes=write` → `{access_token, refresh_token, expires_in}` |
| Refresh | `POST /api/oauth/token` — `grant_type=refresh_token` |
| Scan | `POST /api/_action/fib-booking/ticket/scan` — `{scanToken, direction}`, Bearer auth. `403` = missing `fib_booking.ticket_scan` ACL |
| Config | `GET /api/_action/fib-booking/scanner/config` → `{checkOutEnabled}` |
| Stats | `GET /api/_action/fib-booking/statistics` — ACL `fib_booking.statistics` |

**QR payload formats** (forwarded verbatim — server decides static vs rotating):
- static JSON `{type:"fib_booking_ticket", ticketNumber, scanToken}`
- bare static token — 64 lowercase hex
- rotating wire — `FIBR1:<ticketNumber>:<code>` (TOTP; see `ROTATING_QR.md`)

Rotating QR needs **no special client handling** — the app reads the wire
string off the screen and POSTs it like any other token. Online verification
on the server does the rest. (This is why "rotating" is invisible to the
scanner: the security lives server-side.)

---

## 3. One genuinely new concern: the server base URL

The web scanner is **same-origin** — it just calls `/api/...`. A native app is
not, so it needs to know **which Shopware instance** to talk to. This is the
only real architectural addition.

- A first-run **server URL setup screen** (validated `https://…`), stored in
  `flutter_secure_storage`. No hidden default — an unconfigured app refuses to
  call anything (matches the project's "no silent fallback defaults" rule).
- The Shopware instance must serve a valid TLS cert (the field app must **not**
  disable cert validation — unlike our local `booking.docker` self-signed dev
  cert). Document this as a deployment prerequisite.
- CORS is irrelevant for a native HTTP client (no browser origin), so no server
  CORS changes are needed.

---

## 4. App architecture

```
apps/scanner-mobile/                 # new Flutter project (generic name)
  lib/
    main.dart                        # app shell, routing, theme
    api/
      scan_api.dart                  # Dart port of api.js (the contract)
      token.dart                     # extractScanToken + patterns (pure, tested)
      session.dart                   # in-memory tokens + 1-shot refresh
    config/
      server_config.dart             # base URL setup + secure storage
    ui/
      login_view.dart
      scanner_view.dart              # mobile_scanner camera + verdict overlay
      stats_view.dart
      settings_view.dart            # server URL, check-in/out toggle
  test/
    token_test.dart                  # ports api.test.js cases 1:1
    session_test.dart                # refresh/expiry/logout
  android/ ios/                      # platform shells (generated)
  pubspec.yaml
```

Key packages: `mobile_scanner` (barcode), `http` (client), `flutter_secure_storage`
(server URL + optional refresh token), `flutter_riverpod` or plain
`ChangeNotifier` (state — small app, keep it light).

**Security posture — mirror the web app:** access token in memory only; on
`401` try exactly one refresh then force re-login. Optional upgrade for the
field: persist *only* the refresh token in the OS keystore behind biometric
unlock (Face ID / fingerprint) so a shift change doesn't need a password
re-type — opt-in, off by default, decision below.

**Scanning UX:** continuous camera, debounce duplicate decodes (same token
within N seconds ignored), large colour-coded verdict (green = admitted, red =
rejected with reason), haptic + sound, torch toggle, check-in/out mode switch
gated by `scannerConfig.checkOutEnabled`.

---

## 5. CI: build + ship to GitHub Releases — the **zero-cost, open-source path**

This project is open source, so the distribution strategy is deliberately
**free of any paid account or store fee**. GitHub Actions (including macOS
runners) is **free for public repos**, so CI itself costs nothing either.

New workflow `.github/workflows/mobile-release.yml`, triggered on a
`scanner-v*` tag (and `workflow_dispatch`). **Thin workflow, logic in scripts**
(matches the repo convention — see `.github/scripts/`):

- `.github/scripts/build-android.sh` — sets up Flutter, `flutter build apk
  --release`, signs with a keystore decoded from secrets, emits
  `scanner-<version>.apk`.
- `.github/scripts/build-ios.sh` — macOS runner, `flutter build ios
  --release --no-codesign`, then repackages the `.app` into an **unsigned**
  `Payload/…` zip → `scanner-<version>-unsigned.ipa`.
- The workflow's only inline step is `gh release` upload of both artifacts.

```
jobs:
  android:  runs-on: ubuntu-latest   → build-android.sh → upload .apk
  ios:      runs-on: macos-latest    → build-ios.sh     → upload unsigned .ipa
  release:  needs: [android, ios]    → gh release upload
```

### Android — fully free, tap-to-install

Direct `.apk` download works out of the box: the user enables "install from
unknown sources" and taps it. The signing keystore is the app's **own**
self-generated key (base64 + passwords in repo secrets) — no Google account,
no Play Store fee, no expiry. This is the primary, friction-free path.

### iOS — free via user-side sideloading (the OSS standard)

Apple has **no free distribution licence**: ad-hoc, TestFlight and the App
Store all require the paid Developer Program ($99/yr). A free Apple ID can only
run a build on the developer's *own* device, tethered to a Mac, expiring after
7 days — useless for distribution. This is an Apple platform constraint, not a
tooling gap.

The zero-cost route that keeps the project free is to **ship an unsigned
`.ipa` and let each user sign it with their own free Apple ID**:

1. CI publishes `scanner-<version>-unsigned.ipa` to the GitHub Release.
2. The end-user installs it with **AltStore / SideStore / Sideloadly**, which
   re-sign the app with the user's **own free Apple ID** and **auto-refresh the
   7-day signature** in the background. The project pays nothing; the per-user
   7-day limit is handled by their sideloader.
3. **Build-from-source** is documented as the developer route: clone, open in
   Xcode, run on your own device with a free Apple ID.

Honest trade-off: iOS install is **power-user level** (set up AltStore/Sideloadly
once, accept the 7-day auto-refresh) — not "tap to install" like Android. For
an open-source operator tool this is the accepted norm. If a friction-free iOS
experience (TestFlight invite link, auto-update for non-technical staff) is
ever wanted, the *only* lever is the $99/yr Apple account — a later, optional
upgrade, never a blocker for shipping.

| Platform | Artifact | Install method | Cost | Comfort |
|---|---|---|---|---|
| Android | signed `.apk` | direct download, tap-to-install | 0 | easy |
| iOS | **unsigned** `.ipa` | sideload w/ own free Apple ID (AltStore/Sideloadly) | 0 | power-user, 7-day auto-refresh |
| iOS (devs) | from source | Xcode + free Apple ID, own device | 0 | dev only |

---

## 6. Testing

- **Dart unit tests** port the existing `apps/scanner/src/api.test.js` cases
  verbatim: token extraction (static hex / JSON payload / rotating wire /
  rejects garbage & oversized), session refresh/expiry/logout. Same coverage
  the web client has today.
- **Widget tests** for the login → scan → verdict flow with a mocked `ScanApi`.
- Run in CI on every push (cheap, no devices needed); the build/sign jobs only
  run on release tags.

---

## 7. Scope of a first milestone (MVP)

1. Flutter project skeleton + server-URL setup + login (password grant, memory
   tokens, refresh).
2. `mobile_scanner` camera → `extractScanToken` → scan POST → verdict overlay
   (handles static **and** rotating, since both are just strings).
3. Check-in/out mode from `scannerConfig`; torch; duplicate-debounce.
4. Dart unit tests (ported) green.
5. `build-android.sh` + workflow → signed `.apk` on a test release (the
   primary, fully-free artifact).
6. `build-ios.sh` → unsigned `.ipa` on the release + a `SIDELOADING.md`
   documenting AltStore/Sideloadly and the build-from-source route (§5).

Stats dashboard, biometric refresh-token persistence, and i18n are fast
follow-ups, not MVP.

---

## 8. Distribution decision — settled: zero-cost

iOS distribution is **settled on the free, open-source path** (§5): unsigned
`.ipa` + user-side sideloading (AltStore/Sideloadly) + build-from-source.
**No paid Apple account, no UDID management, no store fee.** A $99/yr Apple
account (for TestFlight/App Store convenience) is explicitly out of scope — a
later optional upgrade, not a prerequisite for shipping.

## 9. Remaining decisions to confirm before building

1. **Biometric refresh-token persistence** — keep the strict memory-only
   posture (re-login each session), or allow opt-in keychain storage of the
   refresh token behind Face ID / fingerprint for shift changes?
2. **App identity** — name shown on the home screen + bundle id
   (e.g. `<generic>.scanner`), icon. Generic, no foreign-project names.
3. **Release trigger** — dedicated `scanner-v*` tags (keeps mobile releases
   independent of the Docker image release cadence)? Assumed yes above.
