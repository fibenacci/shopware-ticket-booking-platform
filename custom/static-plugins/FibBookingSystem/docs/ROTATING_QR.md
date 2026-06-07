# Rotating QR codes (time-based / TOTP)

Per-ticket, operator-configurable security feature against ticket sharing: the
QR a holder presents is not a static token but a short-lived code derived from
the ticket's secret and the current time window (TOTP, RFC 6238 — the
"rotating barcode" mechanism transit/event apps use). A screenshot is
worthless after the window passes.

## Model

- **Config (operator switch, off by default):** `fib_booking_product_config`
  `rotating_qr_enabled` + `rotating_qr_interval` (seconds, 10–120, default 30).
  Set per product in the admin "Booking & Tickets" tab.
- **Snapshot:** both fields are stamped onto the ticket at issue
  (`fib_booking_ticket.rotating_qr_enabled` / `rotating_qr_interval`), like the
  validity model — later config changes never alter already-sold tickets.
- **Secret:** the HMAC key IS the ticket's existing scan token, already stored
  encrypted at rest (`scan_token_cipher`). No second secret to provision; the
  raw secret never reaches a client.
- **Single-use guard:** `fib_booking_ticket.last_rotating_window` — a code for
  a window already accepted on this ticket cannot be redeemed again (kills
  real-time relaying of a screenshotted code).

## Code & wire format

`RotatingCodeService` (pure, unit-tested): code = first 16 hex of
`HMAC-SHA256(scanToken, pack('J', window))`, `window = floor(now / interval)`.
The QR encodes `FIBR1:<ticketNumber>:<code>`. The window is NEVER trusted from
the client — the server recomputes the HMAC for the candidate windows and
finds the match.

## Verification (server-side, online)

The scanner is already online (Admin API), so verification is server-side and
strongest. `RotatingScanVerifier` decrypts the token, accepts the current
window ±1 (clock drift), and `TicketScanService::scanRotating()` then runs the
SAME verdict pipeline as a static scan and burns the window on a VALID entry.

- The scan controller routes by wire shape: `FIBR1:…` → rotating,
  64-hex → static.
- A rotating ticket's **static** token is rejected at the gate
  (`INVALID_CODE`) — only the live code scans, so rotation cannot be bypassed.
- Wrong/expired code, replayed window, non-rotating ticket → `INVALID_CODE`.

## Display

- **Account page / in-app (implemented):** rotating tickets render a polling
  container; `frontend.account.fib_booking.tickets.rotating_qr` returns a fresh
  QR image + the seconds left in the window for the OWNER only (ownership =
  transfer override or reservation customer). The secret stays server-side.
- **Mail / PDF:** no static QR for rotating tickets (a frozen code never
  scans) — a note points to the account/app for the live code.
- **Wallet passes (Apple/Google):** no static barcode for rotating tickets +
  a note. **Native rotating barcodes are a deferred follow-up:**
  - Apple's *rotating barcodes* require Apple's special program/entitlement —
    not enableable from a plugin.
  - Google's `RotatingBarcode.totpDetails` is feasible but needs our TOTP
    aligned to Google's exact on-device computation (RFC-6238 digit
    truncation) plus live-device verification — not shipped unverified.

## Tests

- `RotatingCodeServiceTest` (unit): window stability, ±1 drift, rejection,
  wire round-trip, interval clamping, countdown.
- `TicketScanServiceTest` (integration): valid code burns the window, replay
  rejected, wrong code / stale static token / unknown ticket / non-rotating.
- `RotatingQrEndpointTest` (integration): owner gets a verifiable code,
  stranger and non-rotating get nothing.
- Scanner `api.test.js` (vitest): rotating wire accepted and forwarded.
