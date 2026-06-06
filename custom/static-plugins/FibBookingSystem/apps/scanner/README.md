# FIB Ticket Scanner (Operator App)

Vue 3 single-page app for scanning booking tickets at the door. Full concept,
security model and operations guide: [docs/SCANNER.md](../../docs/SCANNER.md).

## Commands

Run from the project root (preferred):

```bash
make scanner-install      # npm install
make scanner-dev          # Vite dev server on :5173, proxies /api to the dev shop
make scanner-build        # production build → dist/
make scanner-ngrok        # public HTTPS tunnel (phone camera testing)
```

Or directly in this directory: `npm run dev | build | test`.

## Dev URLs

| URL | Purpose |
| --- | --- |
| http://scanner.booking.docker | served by the dev-stack ingress (`make up` builds `dist/` automatically) |
| http://127.0.0.1:8096 | localhost fallback — **secure context**, camera works |
| `make scanner-ngrok` | public HTTPS for real phones |

## Tests

```bash
npm test                  # vitest — 17 tests on src/api.js
```

Covers QR token extraction/validation (length limits, foreign payloads) and
the in-memory session handling (nothing persisted at rest).

## Security posture (summary)

- OAuth password grant against the Shopware Admin API; the scan endpoint
  requires the ACL privilege `fib_booking.ticket_scan` (least-privilege
  scanner role, no full admins)
- tokens live in memory only — closing the tab ends the session
- strict CSP (`default-src 'self'`), client-side token validation before any
  request

Details and threat model: [docs/SECURITY.md](../../docs/SECURITY.md).
