# Scanner app — Vue 3 operator app for ticket scanning (apps/scanner).

SCANNER_DIR := $(PLUGIN_DIR)/apps/scanner
ADMIN_APP_DIR := $(PLUGIN_DIR)/src/Resources/app/administration

.PHONY: scanner-install scanner-dev scanner-build scanner-ngrok scanner-ngrok-stop admin-test

##@ Scanner app

scanner-install: ## Install scanner app dependencies
	@cd $(SCANNER_DIR) && npm install
	@echo "✅ Scanner dependencies installed"

scanner-dev: ## Start the scanner dev server (proxies /api to booking.docker)
	@cd $(SCANNER_DIR) && npm run dev

scanner-build: ## Build the scanner app for production (apps/scanner/dist)
	@cd $(SCANNER_DIR) && npm run build
	@echo "✅ Scanner app built — deploy apps/scanner/dist behind HTTPS"

scanner-ngrok: ## Expose the scanner app via a public ngrok HTTPS tunnel (phone camera testing)
	@token="$${NGROK_AUTHTOKEN:-$$(grep -E '^NGROK_AUTHTOKEN=.+' .env.local 2>/dev/null | cut -d= -f2-)}"; \
	if [ -z "$$token" ]; then \
		echo "❌ NGROK_AUTHTOKEN is not set. Add it to .env.local (free token:"; \
		echo "   https://dashboard.ngrok.com/get-started/your-authtoken) or export it."; \
		exit 1; \
	fi; \
	NGROK_AUTHTOKEN="$$token" docker compose --profile ngrok up -d ngrok
	@echo "▶ Waiting for the tunnel..."
	@url=""; \
	for _ in $$(seq 1 30); do \
		url="$$(docker logs fib-ngrok 2>&1 | grep -o 'url=https://[^ ]*' | tail -1 | cut -d= -f2)"; \
		[ -n "$$url" ] && break; \
		sleep 1; \
	done; \
	if [ -z "$$url" ]; then \
		echo "❌ Tunnel did not come up — check 'docker logs fib-ngrok'"; \
		exit 1; \
	fi; \
	echo ""; \
	echo "✅ Scanner app reachable at: $$url"; \
	echo "   HTTPS = secure context → phone camera scanning works."; \
	echo "   ⚠️  Publicly reachable: change the demo scanner credentials before sharing."; \
	echo "   Stop with: make scanner-ngrok-stop"

scanner-ngrok-stop: ## Stop the ngrok tunnel
	@docker compose --profile ngrok rm -sf ngrok >/dev/null 2>&1 || true
	@echo "✅ ngrok tunnel stopped"

admin-test: ## Run the admin extension's vitest unit tests (pure modules)
	@cd $(ADMIN_APP_DIR) && npm install --silent >/dev/null && npm test
	@echo "✅ Admin extension tests passed"
