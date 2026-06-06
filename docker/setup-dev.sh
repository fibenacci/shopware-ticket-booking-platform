#!/bin/bash
set -e

# Dev setup script — executed by `make up` inside the fib-shopware container.
# Idempotent: safe to re-run; skips the fresh install when the DB already has tables.

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m'
BOLD='\033[1m'

DOMAIN="${VIRTUAL_HOST:-booking.docker}"
DOMAIN="${DOMAIN%%,*}"
LOCAL_URL="http://${DOMAIN}"

print_step()    { echo -e "${BLUE}▶${NC} ${BOLD}$1${NC}"; }
print_success() { echo -e "${GREEN}✓${NC} $1"; }
print_warning() { echo -e "${YELLOW}⚠${NC} $1"; }

cd /var/www/html

print_step "Waiting for MariaDB to be ready..."
for i in {1..30}; do
    if mysql -hmariadb -uroot -proot -e "SELECT 1" >/dev/null 2>&1; then
        print_success "MariaDB is ready"
        break
    fi
    echo "   Attempt $i/30..."
    sleep 2
done

print_step "Installing Composer dependencies..."
composer install --no-interaction --optimize-autoloader --quiet
print_success "Composer dependencies installed"

TABLE_COUNT=$(mysql -hmariadb -uroot -proot -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'shopware';" 2>/dev/null || echo "0")

if [ "$TABLE_COUNT" -lt 10 ]; then
    print_step "Fresh database detected — running system:install (this takes a few minutes)..."
    bin/console system:install --create-database --basic-setup --force --no-interaction
    print_success "Shopware installed"
else
    print_step "Database already installed ($TABLE_COUNT tables) — running migrations..."
    bin/console database:migrate --all --no-interaction >/dev/null || true
    print_success "Migrations executed"
fi

print_step "Refreshing plugins..."
bin/console plugin:refresh --no-interaction >/dev/null

print_step "Installing + activating FibBookingSystem..."
if bin/console plugin:install --activate FibBookingSystem --no-interaction >/dev/null 2>&1; then
    print_success "FibBookingSystem installed + activated"
else
    bin/console plugin:update FibBookingSystem --no-interaction >/dev/null 2>&1 || true
    bin/console plugin:activate FibBookingSystem --no-interaction >/dev/null 2>&1 || true
    print_success "FibBookingSystem already installed (update + activation ensured)"
fi

print_step "Setting up admin user (admin / shopware)..."
bin/console user:create admin --admin --password=shopware --email=admin@localhost.local --firstName=Admin --lastName=User >/dev/null 2>&1 \
    || bin/console user:change-password admin --password=shopware >/dev/null 2>&1 \
    || true
print_success "Admin user configured"

print_step "Updating sales channel domain to ${DOMAIN}..."
bin/console sales-channel:update:domain "${DOMAIN}" --no-interaction >/dev/null || true
# sales-channel:update:domain only swaps the host and keeps a stale port —
# normalize storefront domains to the plain local URL.
mysql -hmariadb -uroot -proot shopware -e "UPDATE sales_channel_domain SET url='${LOCAL_URL}' WHERE url LIKE 'http%';" 2>/dev/null || true
print_success "Sales channel domain updated"

print_step "Installing assets + compiling theme..."
bin/console assets:install --no-interaction >/dev/null
bin/console theme:compile --no-interaction >/dev/null
print_success "Assets installed, theme compiled"

print_step "Clearing cache..."
bin/console cache:clear --no-interaction >/dev/null
print_success "Cache cleared"

echo ""
echo -e "${GREEN}${BOLD}✅ Setup complete — ${CYAN}${LOCAL_URL}${NC}"
