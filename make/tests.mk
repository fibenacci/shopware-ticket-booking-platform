# Tests — PHPUnit suites of the maintained plugins.

PHPUNIT_CONFIGS := $(PLUGIN_DIR)/phpunit.xml.dist custom/static-plugins/FibBookingDemoData/phpunit.xml.dist

.PHONY: test test-unit test-integration

##@ Tests

test: ## Run all PHPUnit suites of all maintained plugins
	@for config in $(PHPUNIT_CONFIGS); do \
		echo "▶ PHPUnit: $$config"; \
		$(PHP_RUN) vendor/bin/phpunit --configuration $$config || { echo "❌ Tests failed ($$config)"; exit 1; }; \
	done
	@echo "✅ Tests passed"

test-unit: ## Run unit-level PHPUnit tests (FibBookingSystem, no DB needed)
	@$(PHP_RUN) vendor/bin/phpunit --configuration $(PLUGIN_DIR)/phpunit.xml.dist --testsuite unit || { echo "❌ Unit tests failed"; exit 1; }
	@echo "✅ Unit tests passed"

test-integration: ## Run integration-level PHPUnit tests of all plugins (boots Shopware kernel + DB)
	@for config in $(PHPUNIT_CONFIGS); do \
		echo "▶ PHPUnit integration: $$config"; \
		$(PHP_RUN) vendor/bin/phpunit --configuration $$config --testsuite integration || { echo "❌ Integration tests failed ($$config)"; exit 1; }; \
	done
	@echo "✅ Integration tests passed"
