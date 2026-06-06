# Cache & assets — theme compilation, asset builds, watchers.

.PHONY: cache theme build-storefront build-admin watch-storefront watch-admin

##@ Cache & assets

cache: ## Clear Shopware cache
	@docker exec fib-shopware bin/console cache:clear || { echo "❌ Cache clear failed"; exit 1; }
	@echo "✅ Cache cleared"

theme: ## Compile the storefront theme
	@docker exec fib-shopware bin/console theme:compile || { echo "❌ Theme compilation failed"; exit 1; }
	@echo "✅ Theme compiled"

build-storefront: ## Build storefront assets
	@docker exec -it fib-shopware bash -c 'bin/build-storefront.sh'
	@echo "✅ Storefront built"

build-admin: ## Build administration assets
	@docker exec -it fib-shopware bash -c 'bin/build-administration.sh'
	@echo "✅ Administration built"

watch-storefront: ## Start the storefront watcher
	@docker exec -it fib-shopware bash -lc 'cd /var/www && make watch-storefront'

watch-admin: ## Start the admin watcher
	@docker exec -it fib-shopware bash -lc 'cd /var/www && make watch-admin'
