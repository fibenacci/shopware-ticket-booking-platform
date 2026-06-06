# Scanner app — Vue 3 operator app for ticket scanning (apps/scanner).

SCANNER_DIR := $(PLUGIN_DIR)/apps/scanner

.PHONY: scanner-install scanner-dev scanner-build

##@ Scanner app

scanner-install: ## Install scanner app dependencies
	@cd $(SCANNER_DIR) && npm install
	@echo "✅ Scanner dependencies installed"

scanner-dev: ## Start the scanner dev server (proxies /api to booking.docker)
	@cd $(SCANNER_DIR) && npm run dev

scanner-build: ## Build the scanner app for production (apps/scanner/dist)
	@cd $(SCANNER_DIR) && npm run build
	@echo "✅ Scanner app built — deploy apps/scanner/dist behind HTTPS"
