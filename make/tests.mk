# Tests — PHPUnit suites of the FibBookingSystem plugin.

.PHONY: test test-unit test-integration

##@ Tests

test: ## Run all PHPUnit tests (unit + integration)
	@$(PHP_RUN) vendor/bin/phpunit --configuration $(PLUGIN_DIR)/phpunit.xml.dist || { echo "❌ Tests failed"; exit 1; }
	@echo "✅ Tests passed"

test-unit: ## Run unit-level PHPUnit tests
	@$(PHP_RUN) vendor/bin/phpunit --configuration $(PLUGIN_DIR)/phpunit.xml.dist --testsuite unit || { echo "❌ Unit tests failed"; exit 1; }
	@echo "✅ Unit tests passed"

test-integration: ## Run integration-level PHPUnit tests (boots Shopware kernel + DB)
	@$(PHP_RUN) vendor/bin/phpunit --configuration $(PLUGIN_DIR)/phpunit.xml.dist --testsuite integration || { echo "❌ Integration tests failed"; exit 1; }
	@echo "✅ Integration tests passed"
