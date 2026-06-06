# Stack lifecycle — bring the local Docker stack up/down.

.PHONY: preflight composer-install up stop down down-volumes logs ps shell

##@ Stack lifecycle

preflight: ## Prepare local files (env, lock files, vendor scaffold)
	@if [ ! -s .env.local ]; then \
		echo "Creating .env.local from .env.example"; \
		cp .env.example .env.local; \
	fi
	@if [ ! -f composer.lock ] || [ ! -f bin/console ]; then \
		echo "composer.lock or bin/ missing — bootstrapping project via $(CLI_IMAGE)"; \
		$(MAKE) composer-install; \
	fi

composer-install: ## Run composer install via the shopware-cli image (no host PHP needed)
	@docker run --rm -v "$$(pwd)":/app -w /app --entrypoint sh $(CLI_IMAGE) \
		-c "composer install --no-interaction" || { echo "❌ composer install failed"; exit 1; }
	@echo "✅ Composer dependencies installed"

up: preflight ## Start the full local stack (proxy + compose + setup)
	@echo "=== Starting Dinghy HTTP proxy ==="
	@docker network create proxy 2>/dev/null || true
	@docker start http-proxy 2>/dev/null || docker run -d --restart=always \
		--name http-proxy \
		--network proxy \
		-v /var/run/docker.sock:/tmp/docker.sock:ro \
		-v ~/.dinghy/certs:/etc/nginx/certs \
		-p 80:80 -p 443:443 \
		-e CONTAINER_NAME=http-proxy \
		codekitchen/dinghy-http-proxy
	@docker compose up -d --wait || docker compose up -d
	@echo "Running setup script..."
	@docker exec fib-shopware bash /setup-dev.sh
	@echo ""
	@echo "╔══════════════════════════════════════════════════════════════════╗"
	@printf "║  ✅  %-62s║\n" "FIB Booking System is ready!"
	@printf "║                                                                  ║\n"
	@printf "║  🌐  %-16shttp://%-39s║\n" "Frontend:" "$(DOMAIN)  (or http://127.0.0.1:8090)"
	@printf "║  🔐  %-16shttp://%-39s║\n" "Admin:" "$(DOMAIN)/admin"
	@printf "║  📧  %-16shttp://%-39s║\n" "Mailpit:" "mail.$(DOMAIN)"
	@printf "║  🗄   %-16shttp://%-39s║\n" "Adminer:" "adminer.$(DOMAIN)"
	@printf "║                                                                  ║\n"
	@printf "║  👤  %-16s%-46s║\n" "Username:" "admin"
	@printf "║  🔑  %-16s%-46s║\n" "Password:" "shopware"
	@echo "╚══════════════════════════════════════════════════════════════════╝"
	@echo ""

stop: ## Stop the Docker stack
	@docker compose stop || { echo "❌ Stop failed"; exit 1; }
	@echo "✅ Containers stopped"

down: ## Remove the Docker stack (keeps DB volume; use down-volumes for a clean slate)
	@docker compose down || { echo "❌ Down failed"; exit 1; }
	@echo "✅ Containers removed"

down-volumes: ## Remove the stack INCLUDING the database volume
	@docker compose down -v
	@echo "✅ Containers + volumes removed"

logs: ## Follow Shopware container logs
	@docker compose logs -f shopware

ps: ## Show stack status
	@docker compose ps

shell: ## Open a shell in the Shopware container
	@docker exec -it fib-shopware bash
