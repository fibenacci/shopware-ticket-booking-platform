# Scanner App (Operators)

Vue 3 app for scanning tickets at the door: `apps/scanner/`. Camera-based QR
scanning with manual entry fallback, color-coded verdicts and a session-local
history.

## Run

```bash
make scanner-install   # one-time
make scanner-dev       # dev server on :5173, proxies /api to booking.docker
make scanner-build     # production build → apps/scanner/dist
```

Deploy `dist/` behind **HTTPS on the same origin as the Shopware Admin API**
(camera access requires a secure context; the CSP restricts `connect-src` to
`'self'`). A sub-path on the shop domain (e.g. `/scanner/`) or a dedicated
subdomain with a reverse proxy to `/api` both work.

## Login & permissions

The app authenticates against the Shopware Admin API (OAuth password grant).
Access is restricted to **specific users**: the scan endpoint requires the
ACL privilege `fib_booking.ticket_scan` — create a dedicated **scanner role**
with ONLY that privilege and one user per device/operator. Don't scan with
full admins. The static app itself is useless without such an account: every
request is authenticated and authorized server-side.

The demo data seeds this setup out of the box (verified: the user can scan,
but gets 403 on every other Admin API endpoint):

| | |
|---|---|
| Role | `Booking Scanner` — privileges: `fib_booking.ticket_scan` only |
| User | `scanner` / `fib-scanner-demo!` (**demo credentials** — change/disable in production, defined in the demo-data seeds JSON) |
| Entry point | "Open ticket scanner" button on the seeded homepage → `http://scanner.booking.docker` (served by the dev-stack ingress; the build is auto-created by `make up`). Camera testing needs a secure context — use `http://127.0.0.1:8096`. Adjust the URL in the CMS layout / seeds for production. |

Token handling: access + refresh tokens are kept **in memory only**. Closing
the tab ends the session; nothing is persisted on the device.

## Scan flow & verdicts

The app accepts the canonical QR payload
(`{"type":"fib_booking_ticket","ticketNumber":…,"scanToken":…}`) or a bare
64-hex token, validates it client-side and calls
`POST /api/_action/fib-booking/ticket/scan`.

| Verdict | Meaning | UI |
|---|---|---|
| `valid` | first scan, ticket now `scanned` | green — let them in |
| `already_scanned` | replay attempt; shows first-scan time | orange |
| `expired` | ticket past `expires_at` | red |
| `revoked` | actively revoked | red |
| `not_found` | unknown/foreign token | red |

Race safety: two devices scanning the same ticket simultaneously resolve into
exactly one `valid` and one `already_scanned` (row lock in the service).

Every attempt is recorded in `fib_booking_scan_log` (verdict, acting user,
token fingerprint, timestamp) — see [SECURITY.md](SECURITY.md).
