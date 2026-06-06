# Shopware console — thin wrappers around the dev container for the
# day-to-day commands. Anything else: make console c="<command>".

.PHONY: console migrate plugin-refresh plugin-list dal-refresh scheduled-tasks system-config

##@ Shopware console

console: ## Run any console command, e.g. make console c="plugin:list"
	@docker exec -it fib-shopware bin/console $(c)

migrate: ## Run all pending migrations
	@docker exec fib-shopware bin/console database:migrate --all --no-interaction
	@echo "✅ Migrations executed"

plugin-refresh: ## Re-scan plugin folders (after composer require)
	@docker exec fib-shopware bin/console plugin:refresh --no-interaction
	@echo "✅ Plugins refreshed — install/activate via: make console c=\"plugin:install --activate <Name>\""

plugin-list: ## List all plugins with their state
	@docker exec fib-shopware bin/console plugin:list

dal-refresh: ## Rebuild DAL indexes (products, categories, …)
	@docker exec fib-shopware bin/console dal:refresh:index
	@echo "✅ DAL indexes rebuilt"

scheduled-tasks: ## Run due scheduled tasks once
	@docker exec fib-shopware bin/console scheduled-task:run --time-limit=60 --memory-limit=256M

system-config: ## Show a config value, e.g. make system-config k="core.listing.productsPerPage"
	@docker exec fib-shopware bin/console system:config:get $(k)
