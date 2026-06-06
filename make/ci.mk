# CI helpers — used by .github/workflows/ci.yml; also runnable locally to
# rehearse the pipeline (the CI stack uses its own container name
# fib-shopware-ci and never touches the dev stack).

.PHONY: ci-bootstrap ci-phpunit ci-e2e ci-teardown

##@ CI (used by .github/workflows)

ci-bootstrap: ## Build + start the pre-baked CI stack (image bakes composer/plugins/demodata)
	@.github/ci/bootstrap.sh

ci-phpunit: ## Run PHPUnit (unit + integration) inside the CI container — writes JUnit XML
	@.github/ci/phpunit.sh

ci-e2e: ## Run the booking Playwright suite against the CI storefront (demo-data ids auto-resolved)
	@.github/ci/playwright.sh

ci-teardown: ## Stop the CI stack (pass WITH_LOGS=1 to dump service logs first)
	@if [ "$(WITH_LOGS)" = "1" ]; then \
		.github/ci/teardown.sh --with-logs; \
	else \
		.github/ci/teardown.sh; \
	fi
