# Bot Protection — Registration Hardening

Defense-in-depth against automated mass registrations
(OWASP [OAT-019 "Account Creation"](https://owasp.org/www-project-automated-threats-to-web-applications/)).
No layer relies on user-visible captchas — conversion is unaffected.

## Layers

| # | Layer | Where | Stops |
|---|---|---|---|
| 1 | **Edge rate limit** | Traefik labels in `compose.prod.yaml` | Mass sign-ups from single IPs (~5/h per IP, burst 3) |
| 2 | **Honeypot** | `config/packages/bot_protection.yaml` | Naive form-filling bots |
| 3 | **ALTCHA** (invisible proof-of-work) | `config/packages/bot_protection.yaml` + [`frosh/altcha-captcha`](https://github.com/FriendsOfShopware/FroshAltchaCaptcha) | Distributed bots / headless browsers — every submit costs CPU |
| 4 | **Double opt-in** | `config/packages/bot_protection.yaml` | Makes accounts that still get through worthless (inactive until email confirmed) |

## Enforced via static system config

Layers 2–4 are defined as
[static system config](https://developer.shopware.com/docs/guides/hosting/configurations/shopware/static-system-config.html):
the YAML **overrides the database**, so the protections cannot be switched
off through the admin UI — not accidentally and not by a compromised admin
account. Note the admin settings pages still *display* the (ignored) DB
values; runtime always uses `config/packages/bot_protection.yaml`.

## ALTCHA

- Self-hosted proof-of-work captcha — no third party, no cookies, no
  user interaction (`invisible: true`, solves on submit). GDPR-clean.
- Requires `ALTCHA_SECRET_KEY` in production (`openssl rand -hex 32`,
  set in `.env.prod`). `compose.prod.yaml` refuses to start without it;
  dev falls back to a committed dummy secret.
- Logged-in customers skip the challenge (`whitelistCustomers: true`).

## Rate limit details

The dedicated Traefik router matches
`POST /account/register` and `POST /store-api/account/register` and applies
a `rateLimit` middleware (5/h average, burst 3, per client IP). Legitimate
users register once; only scripted sign-ups hit the limit. Tune in
`compose.prod.yaml` if carrier-grade NAT (many users behind one mobile IP)
ever becomes an issue.

## What is deliberately *not* used

- **Image/interaction captchas** (reCAPTCHA & co.) — conversion killer,
  third-party data transfer.
- **Disposable-email domain blocklists** — endless cat-and-mouse; double
  opt-in achieves the same effect without list maintenance. Can be added
  later as a supplementary filter if needed.
