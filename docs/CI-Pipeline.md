# CI Pipeline

`.github/workflows/ci.yml` runs on every PR and push to `trunk`.

## Jobs

| Job | Checks | Duration (approx.) |
|---|---|---|
| **Static Quality** | PHP-CS-Fixer (dry-run) + PHPStan level 6 | ~2 min |
| **PHPUnit (unit)** | Plugin unit suite, no database | ~2 min |
| **PHPUnit (integration)** | Full Shopware install against a MariaDB service, plugin installed + activated, integration suite | ~8 min |
| **Composer Audit** | Known vulnerabilities in `composer.lock` | ~1 min |
| **Docker Image Build** | Smoke build of the production image (no push) | ~10 min |
| **Report CI** | Gate — red as soon as any job fails | — |

Reusable shell scripts live in `.github/ci/`:

- `bootstrap-shopware.sh` — Shopware install + plugin activation (integration job)
- `gate.sh` — generic job-result gate (report-ci job)
- `wiki-sync.sh` — assembles and pushes wiki pages

## Reproduce locally

CI uses the same make targets as local development:

```bash
make php-cs-fixer-check
make phpstan
make test-unit
make test-integration   # needs a running stack (make up)
```

## Image publishing

`.github/workflows/docker-publish.yml` builds and pushes the production
image to GHCR — on pushes to `trunk` (`latest` + `sha-…`) and on `v*` tags
(semver tag). See [Deployment](Deployment).

## Wiki sync

`.github/workflows/wiki-sync.yml` syncs `docs/` and the plugin docs to this
repository's GitHub wiki on every push to `trunk`.
