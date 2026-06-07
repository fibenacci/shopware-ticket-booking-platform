# Installing the iOS scanner (free, no paid Apple account)

Apple does not allow installing an arbitrary `.ipa` from a web link the way
Android does, and it has **no free distribution licence** — ad-hoc, TestFlight
and the App Store all require the paid Developer Program ($99/yr).

The open-source way around this: we ship an **unsigned `.ipa`**, and you sign it
with **your own free Apple ID** using a sideloading tool. The tool re-signs the
app and refreshes the 7-day signature automatically. The project pays nothing;
you need only a free Apple ID.

## Option A — AltStore / SideStore (recommended)

1. Install **AltStore** (needs AltServer on a Mac/PC on the same Wi-Fi to
   auto-refresh) or **SideStore** (on-device refresh, no computer after setup).
   Follow their official setup guide and sign in with your free Apple ID.
2. Download `scanner-<version>-unsigned.ipa` from this project's GitHub Release.
3. In AltStore/SideStore: **My Apps → +** → pick the `.ipa`. It signs and
   installs with your Apple ID.
4. The app runs for 7 days per signature; AltStore/SideStore **auto-refresh** it
   in the background while reachable. Re-open the tool occasionally if a refresh
   was missed.

## Option B — Sideloadly (quick, manual refresh)

1. Install **Sideloadly** on a Mac/PC, connect the iPhone by cable.
2. Drag the `.ipa` in, enter your free Apple ID, **Start**.
3. On the phone: Settings → General → VPN & Device Management → trust your
   developer certificate.
4. Re-run every ~7 days to refresh (free Apple IDs expire signatures weekly).

## Option C — build from source (developers)

```bash
flutter create --org org.fibbooking --project-name booking_scanner .
tool/patch_permissions.sh ios
open ios/Runner.xcworkspace   # set your free Apple ID team, run on your device
```

## Notes & limits of free signing

- **7-day expiry** on free Apple IDs — handled automatically by AltStore/
  SideStore, manual with Sideloadly.
- Up to **3 sideloaded apps** and limited app IDs per free account.
- This is power-user territory. If you later want a tap-to-install / auto-update
  experience for non-technical staff, the only option is the paid Apple
  Developer Program (TestFlight) — an optional upgrade, never required to run
  the app.

**Android needs none of this** — just download the `.apk` and install it.
