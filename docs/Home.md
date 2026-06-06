# FIB Booking System — Documentation

Shopware 6.7 project containing the **FibBookingSystem** plugin — a
reservation and booking system with holds, reservations, tickets and QR codes.

## Pages

| Page | Content |
|---|---|
| [Local Development](Local-Development) | `make up`, stack services, daily workflows |
| [Tests and Quality](Tests-and-Quality) | PHPUnit, PHPStan, PHP-CS-Fixer, Playwright |
| [CI Pipeline](CI-Pipeline) | GitHub Actions jobs and what they check |
| [Deployment](Deployment) | Docker production deployment (image, compose, releases) |
| [Plugin: FibBookingSystem](Plugin-FibBookingSystem) | Plugin overview (from the plugin README) |
| [Plugin: Architecture Plan](Plugin-Architecture-Plan) | Architecture and implementation plan of the plugin |
| [Plugin: Ticketing Plan](Plugin-Ticketing-Plan) | Ticketing platform plan (wallet, scanner, security) |
| [Plugin: Wallet](Plugin-Wallet) | Apple/Google Wallet setup and operations |
| [Plugin: Scanner](Plugin-Scanner) | Operator scanner app (Vue) |
| [Plugin: Security](Plugin-Security) | Threat model and hardening |

## Sources

These wiki pages are synced automatically from the repository
(`.github/workflows/wiki-sync.yml`):

- `docs/*.md` → project docs
- `custom/static-plugins/FibBookingSystem/README.md` → plugin overview
- `custom/static-plugins/FibBookingSystem/docs/*.md` → plugin docs

Make changes **in the repository**, not in the wiki — they are overwritten
on the next push to `trunk`.
