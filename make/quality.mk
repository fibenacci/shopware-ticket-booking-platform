# Code quality — static analysis and code style.

.PHONY: phpstan php-cs-fixer php-cs-fixer-check

##@ Code quality

phpstan: ## Run PHPStan
	@$(PHP_RUN) vendor/bin/phpstan analyse -c .build/phpstan.neon --no-progress --memory-limit=1G || { echo "❌ PHPStan check failed"; exit 1; }
	@echo "✅ PHPStan check passed"

php-cs-fixer: ## Run PHP-CS-Fixer (auto-fix)
	@$(PHP_RUN) vendor/bin/php-cs-fixer fix --config=.build/php-cs-fixer.php --allow-risky=yes --using-cache=no --no-interaction || { echo "❌ PHP-CS-Fixer run failed"; exit 1; }
	@echo "✅ PHP-CS-Fixer run finished"

php-cs-fixer-check: ## Check PHP-CS-Fixer rules (dry-run); same scope as CI
	@$(PHP_RUN) vendor/bin/php-cs-fixer fix --config=.build/php-cs-fixer.php --allow-risky=yes --dry-run --diff --using-cache=no --no-interaction || { echo "❌ PHP-CS-Fixer check failed"; exit 1; }
	@echo "✅ PHP-CS-Fixer check passed"
