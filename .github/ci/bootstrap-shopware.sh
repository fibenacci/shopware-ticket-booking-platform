#!/usr/bin/env bash
set -euo pipefail

# CI bootstrap for the integration-test job: fresh Shopware install against the
# MariaDB service container, then install + activate the FibBookingSystem plugin.
# Runs with APP_ENV=prod (install-time); the test suite itself runs with APP_ENV=test.

echo "▶ Installing Shopware..."
APP_ENV=prod bin/console system:install --create-database --basic-setup --force --no-interaction

echo "▶ Installing + activating FibBookingSystem..."
APP_ENV=prod bin/console plugin:refresh --no-interaction
APP_ENV=prod bin/console plugin:install --activate FibBookingSystem --no-interaction
APP_ENV=prod bin/console cache:clear --no-interaction

echo "✅ Shopware bootstrapped"
