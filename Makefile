# FIB Booking System — main Makefile
# Targets are split by responsibility into make/*.mk and included below.

.DEFAULT_GOAL := help

# ── Shared variables ─────────────────────────────────────────────────────────
DOMAIN     ?= booking.docker
PLUGIN_DIR := custom/static-plugins/FibBookingSystem
CLI_IMAGE  := ghcr.io/shopware/shopware-cli:latest-php-8.3

# Execution prefix for PHP tooling. Locally we exec into the Shopware container;
# in CI (GitHub Actions sets CI=true) the runner already has PHP + vendor/, so the
# same vendor/bin command runs directly with no container.
PHP_RUN ?= $(if $(CI),,docker exec -w /var/www/html fib-shopware)

help: ## Show available targets
	@printf "\n\033[1mFIB Booking System — make targets\033[0m\n"
	@awk 'BEGIN {FS = ":.*##"} \
		/^##@/ {printf "\n\033[1;36m%s\033[0m\n", substr($$0, 5); next} \
		/^[A-Za-z0-9_.-]+:.*##/ {printf "  \033[36m%-28s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)
	@printf "\n"

.PHONY: help

# ── Modules ──────────────────────────────────────────────────────────────────
include make/stack.mk     # lifecycle: preflight, up, stop, down, logs, shell
include make/console.mk   # bin/console passthrough + common shopware commands
include make/assets.mk    # cache, theme, asset builds, watchers
include make/database.mk  # domain, demo data, messenger
include make/quality.mk   # phpstan, php-cs-fixer
include make/tests.mk     # phpunit (unit + integration)
include make/e2e.mk       # playwright
include make/scanner.mk   # vue scanner app
include make/ci.mk        # CI pipeline helpers (.github/ci)
