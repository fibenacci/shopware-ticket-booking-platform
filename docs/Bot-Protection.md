# Bot Protection — Registration Hardening

Defense-in-depth against automated mass registrations
(OWASP [OAT-019 "Account Creation"](https://owasp.org/www-project-automated-threats-to-web-applications/)).
No layer relies on user-visible captchas — conversion is unaffected.

## Layers

| # | Layer | Where | Stops |
|---|---|---|---|
| 1 | **Edge rate limit** | Traefik labels in `compose.prod.yaml` | Mass sign-ups from single IPs (~5/h per IP, burst 3) |
| 2 | **Honeypot** | `bin/activate-bot-protection.sh` (deploy hook) | Naive form-filling bots |
| 3 | **ALTCHA** (invisible proof-of-work) | `bin/activate-bot-protection.sh` + [`frosh/altcha-captcha`](https://github.com/FriendsOfShopware/FroshAltchaCaptcha) | Distributed bots / headless browsers — every submit costs CPU |
| 4 | **Double opt-in** | `config/packages/bot_protection.yaml` | Makes accounts that still get through worthless (inactive until email confirmed) |

## How the layers are enforced

**Double opt-in** (layer 4) is
[static system config](https://developer.shopware.com/docs/guides/hosting/configurations/shopware/static-system-config.html):
the YAML **overrides the database**, so it cannot be switched off through
the admin UI — not accidentally and not by a compromised admin account.
(The admin settings page still *displays* the ignored DB value; runtime
always uses `config/packages/bot_protection.yaml`.)

**Captchas** (layers 2+3) cannot use static config — Shopware's static
system config only accepts scalar values, and `activeCaptchasV2` is a JSON
object (setting it statically fails the container build). Instead,
`bin/activate-bot-protection.sh` writes the captcha configuration to the
database via `system:config:set --json`. The deployment helper runs it as a
`hooks.post` step (`.shopware-project.yml`) on **every deploy**, so a change
made in the admin UI survives at most until the next deployment.

## ALTCHA

- Self-hosted proof-of-work captcha — no third party, no cookies, no
  user interaction (`invisible: true`, solves on submit). GDPR-clean.
- Requires `ALTCHA_SECRET_KEY` in production (`openssl rand -hex 32`,
  set in `.env.prod`). `compose.prod.yaml` refuses to start without it;
  outside production the activation script generates a random per-run
  secret.
- Logged-in customers skip the challenge (`whitelistCustomers: true`).

## Rate limit details

The dedicated Traefik router matches
`POST /account/register` and `POST /store-api/account/register` and applies
a `rateLimit` middleware (5/h average, burst 3, per client IP). Legitimate
users register once; only scripted sign-ups hit the limit. Tune in
`compose.prod.yaml` if carrier-grade NAT (many users behind one mobile IP)
ever becomes an issue.

## Edge hardening (compose.prod.yaml)

Beyond the registration layers, the Traefik edge enforces:

- **Security headers** on every response (HSTS incl. preload, `nosniff`,
  `Referrer-Policy`, CSP `frame-ancestors 'self'`, `Permissions-Policy`).
  The scanner host allows `camera=(self)` — it scans QR codes; the shop
  host disables camera/microphone/geolocation entirely.
- **OAuth rate limit**: `POST /api/oauth/token` capped at 10/min per IP —
  blocks Admin-API credential brute force without ever bothering operators.
- **Admin IP allowlist (opt-in)**: set `ADMIN_ALLOWED_IPS` (CIDR list) in
  `.env.prod` to take `/admin` off the public internet. `/api` stays open:
  payment webhooks call it and it is OAuth-protected + rate limited.
- **JSON access log** (`traefik_logs` volume) — forensics + CrowdSec input.
- **Container hardening**: `no-new-privileges`, memory limits, capped log
  sizes, network segmentation (edge cannot reach DB/Redis), Docker socket
  behind a read-only proxy.

## CrowdSec (opt-in, recommended against distributed botnets)

IP-based rate limits cannot stop a botnet that sends 2 requests per IP.
CrowdSec tails the Traefik access log, detects behavioural patterns
(scanning, brute force, floods) and matches community blocklists:

```bash
docker compose -f compose.prod.yaml --env-file .env.prod --profile crowdsec up -d
# inspect decisions:
docker compose -f compose.prod.yaml exec crowdsec cscli decisions list
docker compose -f compose.prod.yaml exec crowdsec cscli metrics
```

Detection alone only *observes*. To **block**, add a bouncer — simplest on a
single host is the firewall bouncer (drops flagged IPs in front of Docker):
install `crowdsec-firewall-bouncer-iptables` on the host and point it at the
container LAPI (`cscli bouncers add host-firewall` → key into the bouncer
config). Alternative: the Traefik plugin bouncer
([crowdsec-bouncer-traefik-plugin](https://github.com/maxlerebourg/crowdsec-bouncer-traefik-plugin)).

## Admin accounts

Technical layers do not help against phished admin credentials: enforce
**2FA/TOTP for every admin user** (Shopware supports store extensions/SSO
for this) and give the scanner integration a **least-privilege ACL role**
(scan-related permissions only) instead of full admin rights.

## What is deliberately *not* used

- **Image/interaction captchas** (reCAPTCHA & co.) — conversion killer,
  third-party data transfer.
- **Disposable-email domain blocklists** — endless cat-and-mouse; double
  opt-in achieves the same effect without list maintenance. Can be added
  later as a supplementary filter if needed.
