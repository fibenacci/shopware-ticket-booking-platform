# Code quality — static analysis, code style and mess detection.
# Order matches the CI quality job: php-cs-fixer → phpstan → phpmd.

PHPMD_PATHS := custom/static-plugins/FibBookingSystem/src,custom/static-plugins/FibBookingDemoData/src,custom/static-plugins/FibBookingTheme/src

.PHONY: phpstan php-cs-fixer php-cs-fixer-check phpmd quality

quality: php-cs-fixer-check phpstan phpmd ## Run the full quality chain (same order as CI)

##@ Code quality

phpstan: ## Run PHPStan (level max)
	@$(PHP_RUN) vendor/bin/phpstan analyse -c .build/phpstan.neon --no-progress --memory-limit=1G || { echo "❌ PHPStan check failed"; exit 1; }
	@echo "✅ PHPStan check passed"

php-cs-fixer: ## Run PHP-CS-Fixer (auto-fix)
	@$(PHP_RUN) vendor/bin/php-cs-fixer fix --config=.build/php-cs-fixer.php --allow-risky=yes --using-cache=no --no-interaction || { echo "❌ PHP-CS-Fixer run failed"; exit 1; }
	@echo "✅ PHP-CS-Fixer run finished"

php-cs-fixer-check: ## Check PHP-CS-Fixer rules (dry-run); same scope as CI
	@$(PHP_RUN) vendor/bin/php-cs-fixer fix --config=.build/php-cs-fixer.php --allow-risky=yes --dry-run --diff --using-cache=no --no-interaction || { echo "❌ PHP-CS-Fixer check failed"; exit 1; }
	@echo "✅ PHP-CS-Fixer check passed"

phpmd: ## Run PHPMD (mess detection, .build/phpmd.xml)
	@$(PHP_RUN) vendor/bin/phpmd $(PHPMD_PATHS) text .build/phpmd.xml --exclude '*/Resources/*' || { echo "❌ PHPMD check failed"; exit 1; }
	@echo "✅ PHPMD check passed"
