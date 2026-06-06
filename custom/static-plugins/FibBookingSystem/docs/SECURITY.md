# Security Model

Threat model and hardening decisions for the ticketing platform. Reviewed
with a penetration-testing mindset: every externally reachable surface is
listed with its protections.

## Token architecture

| Artifact | Storage | Purpose |
|---|---|---|
| Scan token (256-bit random) | **never stored in plaintext** | proves ticket possession (QR content) |
| `scan_token_hash` (sha256) | `fib_booking_ticket` | constant lookup key for scans |
| `scan_token_cipher` (AES-256-GCM) | `fib_booking_ticket` | rebuilds QR payload for wallet passes |
| Wallet link signature | not stored (stateless HMAC) | authorizes pass downloads from e-mail |
| Token fingerprint (12 hex chars) | `fib_booking_scan_log` | audit correlation without exposing tokens |

Key derivation: all keys are derived from `APP_SECRET` via HKDF-SHA256 with
distinct domain-separation labels (`fib-booking-token-cipher`,
`fib-booking-wallet-link`) — compromising one derived key compromises neither
the app secret nor the other keys.

**Database-leak scenario**: an attacker with a full DB dump has hashes
(useless — preimage), ciphertexts (useless without `APP_SECRET`) and
fingerprints. No stored value allows forging a scannable QR code.

## Attack surface & mitigations

### Storefront booking JSON (`/fib-booking/availability|hold|cart/add`)
- strict input validation (typed extractors, date parsing, positive ints)
- `Cache-Control: no-store` — no cache poisoning/leakage
- sliding-window rate limits: 120/min/IP (read), 30/min/IP (write)
- hold creation is transactional with `SELECT … FOR UPDATE` (no overbooking)

### Wallet downloads (`/fib-booking/wallet/{ticketId}/…`)
- HMAC-SHA256 signature over `provider|ticketId|expiry`, `hash_equals`
  comparison, expiry enforced
- **uniform 404** for every failure mode (bad id, bad sig, expired, unknown
  ticket, unconfigured provider) — no oracle, no enumeration
- ticket ids are UUIDv4 — unguessable even without the signature
- 20/min/IP rate limit, `no-store`, `X-Robots-Tag: noindex`, `Referrer-Policy: no-referrer`

### Account area (`/account/fib-booking/tickets`)
- `_loginRequired` + ownership scoping (only the customer's own reservations
  are queried — by customer id from the session, never from request input)
- `_noStore` — never cached

### Scan API (`POST /api/_action/fib-booking/ticket/scan`)
- Admin API OAuth + dedicated ACL privilege `fib_booking.ticket_scan`
  (least-privilege scanner roles)
- token format validated with `^[0-9a-f]{64}$` before any DB access
- race-safe `FOR UPDATE` state transition — replays deterministically yield
  `already_scanned` with the first-scan timestamp
- 120/min rate limit keyed by actor+IP
- full audit trail in `fib_booking_scan_log` including failed attempts

### Scanner app
- tokens in memory only (no localStorage/cookies) — nothing to steal at rest
- strict CSP (`default-src 'self'`, no third-party origins), `frame-ancestors 'none'`
- client-side token validation before any request
- requires HTTPS in production (camera + credentials)

## Operational guidance

- **Secrets**: `APP_SECRET` must be long & random; rotating it invalidates
  wallet links + encrypted tokens (documented trade-off in WALLET.md).
- **Certificates**: keep the Apple `.p12` outside the web root, `chmod 600`,
  owned by the PHP user; the Google service account key lives in the system
  config — restrict admin ACLs for system config accordingly.
- **Monitoring**: alert on spikes of `not_found` verdicts in
  `fib_booking_scan_log` (brute-force attempts) and on 429 rates.
- **Scanner accounts**: one user per device, only `fib_booking.ticket_scan`,
  disable after events.

## Known limitations / future hardening

- Apple pass updates (push) are not implemented — passes are static snapshots.
- Offline scanning is not supported by design (online verification is the
  security guarantee).
- The scan endpoint trusts Shopware's OAuth layer for authentication; no
  additional mTLS device binding (possible future step for high-risk venues).
