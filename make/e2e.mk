# E2E — Playwright tests of the FibBookingSystem plugin.

.PHONY: e2e-install e2e e2e-ui e2e-report

E2E_BASE_URL ?= http://$(DOMAIN)

##@ E2E (Playwright)

e2e-install: ## Install Playwright dependencies + Chromium (one-time per machine)
	@cd $(PLUGIN_DIR) && npm install && npx playwright install chromium
	@echo "✅ Playwright ready — run 'make e2e'"

e2e: ## Run Playwright E2E tests (set FIB_BOOKING_PRODUCT_ID / FIB_BOOKING_RESOURCE_ID)
	@cd $(PLUGIN_DIR) && BASE_URL="$(E2E_BASE_URL)" npx playwright test || { echo "❌ E2E tests failed"; exit 1; }
	@echo "✅ E2E tests passed"

e2e-ui: ## Open Playwright UI mode
	@cd $(PLUGIN_DIR) && BASE_URL="$(E2E_BASE_URL)" npx playwright test --ui

e2e-report: ## Open the most recent Playwright HTML report
	@cd $(PLUGIN_DIR) && npx playwright show-report
