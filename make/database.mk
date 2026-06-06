# Database & data — sales channel domain, demo data, messenger queue.

.PHONY: fix-domain demodata messenger-consume

##@ Database & data

fix-domain: ## Point the sales channel at $(DOMAIN)
	@docker exec fib-shopware bin/console sales-channel:update:domain "$(DOMAIN)" --no-interaction
	@docker exec fib-shopware bin/console cache:clear
	@echo "✅ Sales channel domain updated to $(DOMAIN)"

demodata: ## Generate demo data (products, categories, customers)
	@docker exec -e APP_ENV=prod fib-shopware bin/console framework:demodata || { echo "❌ Demo data generation failed"; exit 1; }
	@docker exec fib-shopware bin/console dal:refresh:index
	@echo "✅ Demo data generated"

messenger-consume: ## Drain messenger queues once (300s / 256MB limit)
	@docker exec fib-shopware bin/console messenger:consume async low_priority failed --time-limit=300 --memory-limit=256M -v
