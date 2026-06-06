# Wallet Passes (Apple Wallet & Google Wallet)

Customers can add their ticket to Apple Wallet or Google Wallet — from the
ticket e-mail (no login required) or from the customer account area
(`/account/fib-booking/tickets`).

## How it works

```
Ticket mail / account area
        │  signed URL: /fib-booking/wallet/{ticketId}/apple.pkpass?exp=…&sig=…
        ▼
BookingWalletController ── verifies HMAC + expiry ──► WalletPassService
        │                                                   │ decrypts scan token (AES-256-GCM)
        │                                                   │ rebuilds QR payload
        ▼                                                   ▼
.pkpass download (Apple)                    Save-to-Google-Wallet redirect (JWT)
```

- **URLs are stateless**: `sig = HMAC-SHA256(provider|ticketId|exp)` with a key
  derived from `APP_SECRET` (HKDF). Nothing is stored, links expire (default
  90 days), and any failure returns a uniform 404.
- **QR payload reconstruction**: the scan token is stored AES-256-GCM-encrypted
  (`scan_token_cipher`); the verification hash remains the only lookup key.
  See [SECURITY.md](SECURITY.md) for the threat model.
- Buttons/links only render for providers that are actually configured.

## Configuration (Admin → Extensions → FIB Booking System)

### Apple Wallet

| Setting | Value |
|---|---|
| Pass Type Identifier | e.g. `pass.com.yourcompany.booking` (Apple Developer portal) |
| Apple Team Identifier | your 10-char team id |
| Pass certificate path | absolute server path to the `.p12` (keep outside the web root, `chmod 600`) |
| Pass certificate password | password of the `.p12` |
| WWDR certificate path | Apple WWDR intermediate as `.pem` ([download](https://www.apple.com/certificateauthority/)) |

The pass is an `eventTicket` with the QR code, event name, start time,
guest count and booking/ticket numbers. Icons: bundled defaults, replaceable
via "Custom pass icon path".

### Google Wallet

| Setting | Value |
|---|---|
| Issuer ID | from the [Google Pay & Wallet Console](https://pay.google.com/business/console) |
| Service account key (JSON) | full JSON key of a service account with the "Wallet Object Issuer" role |

The save link embeds the event ticket class + object in an RS256-signed JWT —
no API call happens at link-generation time; data reaches Google only when the
customer clicks "Save".

## Mail template variables

`fib_booking_ticket_mail` templates receive:

```twig
{{ wallet.appleUrl }}    {# null when Apple is not configured #}
{{ wallet.googleUrl }}   {# null when Google is not configured #}
{{ wallet.accountUrl }}  {# link to the account ticket area #}
```

## Operational notes (you build it, you run it)

- Certificate expiry: Apple pass certificates expire yearly — monitor and
  rotate; passes already in wallets keep working, new downloads fail.
- Key rotation: rotating `APP_SECRET` invalidates all outstanding wallet
  links **and** the encrypted tokens; tickets themselves stay scannable
  (hash lookup is independent).
- The wallet endpoints are rate-limited (20/min/IP) and send
  `Cache-Control: no-store`.
