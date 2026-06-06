# Ticketing Platform — Implementation Plan

Goal: evolve FibBookingSystem from a booking plugin into a complete,
self-contained ticketing platform (inspired by reservia.online) with a
strong security posture ("you build it, you run it"). Everything lives in
the plugin — no project-specific coupling.

## Capabilities

1. **Ticket shop** — customers buy bookable products/tickets through the
   normal Shopware checkout (already implemented: holds → reservation →
   QR ticket + ticket mail).
2. **Wallet passes** — customers add their ticket to **Apple Wallet**
   (`.pkpass`) or **Google Wallet** (Save link), from the ticket e-mail or
   from the customer account area.
3. **Scanner app** — operators scan QR codes at the door with a dedicated
   **Vue 3 app** (camera-based), backed by a hardened scan API with
   detailed verdicts and a full audit trail.

## Architecture

```
src/Core/Domain/Wallet/
  WalletLinkSigner.php           # HMAC-signed, expiring download URLs (stateless)
  AppleWalletPassGenerator.php   # pass.json + manifest + PKCS#7 detached signature
  GoogleWalletLinkGenerator.php  # RS256 "Save to Google Wallet" JWT links
  WalletPassService.php          # facade: ticket data + provider availability
src/Core/Domain/Ticket/
  TicketScanService.php          # scan verdicts + audit logging (race-safe)
src/Storefront/Controller/
  BookingWalletController.php    # signed pkpass/google downloads + account area
src/Api/Controller/
  BookingTicketScanController.php  # scan API v2 (verdict + ticket details + ACL)
apps/scanner/                    # Vue 3 + Vite scanner app (standalone)
```

### Wallet flow

- Ticket mail and account area render provider buttons only when the
  provider is configured (system config / env).
- Download URLs are **stateless HMAC-signed** (`ticketId`, `expiry`,
  `sha256-HMAC` derived from `APP_SECRET`): no login required from the
  e-mail, no token stored, not guessable, expiring (default 90 days).
- Account area additionally requires login + ticket ownership.
- Apple: `.pkpass` (eventTicket) signed with the Pass Type ID certificate
  (PKCS#7, OpenSSL). Configured via certificate path + password.
- Google: signed JWT link (`https://pay.google.com/gp/v/save/<jwt>`) using a
  Google service account; event ticket class/object are embedded in the JWT.

### Scan flow (v2)

- Verdicts instead of boolean: `valid`, `already_scanned`, `expired`,
  `revoked`, `not_found` (+ `scannedAt`, ticket/booking numbers on success).
- `SELECT ... FOR UPDATE` keeps double scans race-safe; the second scan of
  the same token deterministically returns `already_scanned`.
- Every attempt (including failures) is written to `fib_booking_scan_log`
  with verdict, acting user id, source, and a token **fingerprint** (first 12
  hex chars of the hash — never the token itself).
- Admin-API scope + dedicated ACL privilege; rate-limited.

### Scanner app

- Vue 3 + Vite, lives in `apps/scanner/`, talks to the Shopware Admin API.
- OAuth password grant; tokens kept **in memory only** (no localStorage —
  XSS cannot exfiltrate persisted credentials).
- Camera scanning via the `qr-scanner` library + manual entry fallback.
- Color-coded verdict screen, session-local scan history, auto re-login on
  401 (refresh-token rotation in memory).

## Security model (pen-test focus)

| Threat | Mitigation |
|---|---|
| Ticket forgery | QR contains a 256-bit random token; DB stores only `sha256(token)`; verdict compare via hash lookup |
| Replay (double entry) | Row-locked state transition `issued/sent → scanned`; second scan → `already_scanned` |
| Wallet URL guessing | HMAC-SHA256 signature over `ticketId\|expiry` with key derived from `APP_SECRET`; `hash_equals` compare; uniform 404 on any failure (no oracle) |
| Brute force / scraping | Sliding-window rate limits on hold/availability/cart/wallet/scan endpoints |
| Token leakage via logs | Scan log stores fingerprint only; tokens never logged or persisted in plaintext |
| Credential theft in scanner app | No persisted tokens; in-memory only; HTTPS required for camera anyway |
| Privilege escalation | Scan endpoint requires dedicated ACL privilege; account area enforces ownership |
| Cache leaks | All ticket/wallet routes send `Cache-Control: no-store` + `X-Robots-Tag: noindex` |
| Enumeration | UUIDv4 ids, uniform error responses, no existence oracles |

## Phases

1. **P1 — Scan API v2**: migration (`fib_booking_scan_log` +
   `scan_token_cipher`), `TicketScanService` with verdicts + audit, hardened
   controller, tests. **Status: done.**
2. **P2 — Wallet**: signer + Apple/Google generators, storefront controller,
   system config, tests. **Status: done.**
3. **P3 — Customer area + mail**: account ticket list with wallet buttons,
   wallet links in ticket mail. **Status: done.**
4. **P4 — Scanner app**: Vue app, make targets, docs. **Status: done.**
5. **P5 — Hardening**: rate limiters, validation sweep, security docs.
   **Status: done.**

6. **P6 — Booking calendar (CMS element)**: operator-defined slots
   ("Termine") with per-slot capacity, month calendar with red sold-out days,
   package products sharing one resource, slot generator command, CMS
   element/block for shopping experiences. See [CALENDAR.md](CALENDAR.md).
   **Status: done.**

Open follow-ups: admin module (resource/reservation/slot CRUD UI), Apple pass
push updates, scanner offline strategy decision, ticket revocation UI.

## Architecture notes (Shopware patterns)

- **Store API routes** (`Core/Content/Booking/SalesChannel/`):
  `AbstractBookingAvailabilityRoute` / `AbstractBookingHoldRoute` with the
  decoration pattern; storefront controllers delegate to them. Headless
  consumers use `/store-api/fib-booking/*`.
- **Domain exceptions**: `FibBookingException extends HttpException` with
  stable error codes (`FIB_BOOKING__*`) and static factories.
- **Demo data** lives in a separate dev/CI-only plugin
  (`FibBookingDemoData`) seeding exclusively through DAL repositories:
  `bin/console fib-booking:demodata`.

Status tracking lives in this file; details per feature in
`docs/WALLET.md`, `docs/SCANNER.md`, `docs/SECURITY.md`.
