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

# SHOP_DOMAIN comes from compose.yaml — required, no silent fallback:
# a missing value means the stack is misconfigured and must fail HERE,
# not produce a shop on a surprise domain. (VIRTUAL_HOST is reserved for
# the proxy's docker-gen and must only ever appear on the ingress.)
DOMAIN="${SHOP_DOMAIN:?SHOP_DOMAIN is not set — define it on the shopware service in compose.yaml}"
# https is canonical (camera/secure-context parity with prod — certs come
# from docker/dev-certs.sh); a plain-http domain stays as fallback.
LOCAL_URL="https://${DOMAIN}"
FALLBACK_URL="http://${DOMAIN}"

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

install_plugin() {
    local plugin="$1"
    if bin/console plugin:install --activate "$plugin" --no-interaction >/dev/null 2>&1; then
        print_success "$plugin installed + activated"
    else
        bin/console plugin:update "$plugin" --no-interaction >/dev/null 2>&1 || true
        bin/console plugin:activate "$plugin" --no-interaction >/dev/null 2>&1 || true
        print_success "$plugin already installed (update + activation ensured)"
    fi
}

print_step "Installing + activating plugins..."
install_plugin FibBookingSystem
install_plugin FibBookingDemoData
# Open-source platform plugins — prod installs these via the deployment
# helper (.shopware-project.yml); keep dev at parity.
install_plugin FroshAltchaCaptcha
install_plugin FroshTools
install_plugin FroshPlatformMailArchive
install_plugin FroshPlatformHtmlMinify
install_plugin SwagPayPal

print_step "Activating bot protection (honeypot + ALTCHA, see docs/Bot-Protection.md)..."
sh bin/activate-bot-protection.sh >/dev/null && print_success "Captchas active" \
    || print_warning "Captcha activation failed — run 'sh bin/activate-bot-protection.sh' manually"

print_step "Seeding booking demo data (products, slots, packages, homepage calendar)..."
bin/console fib-booking:demodata --no-interaction || print_warning "Demo data seeding failed — run 'make seed-booking' manually"

print_step "Setting up admin user (admin / shopware)..."
bin/console user:create admin --admin --password=shopware --email=admin@localhost.local --firstName=Admin --lastName=User >/dev/null 2>&1 \
    || bin/console user:change-password admin --password=shopware >/dev/null 2>&1 \
    || true
print_success "Admin user configured"

print_step "Updating sales channel domain to ${DOMAIN}..."
bin/console sales-channel:update:domain "${DOMAIN}" --no-interaction >/dev/null || true
# sales-channel:update:domain only swaps the host and keeps a stale port —
# promote ONE http domain to the canonical https URL (skip when it exists) …
mysql -hmariadb -uroot -proot shopware -e "
    UPDATE sales_channel_domain SET url='${LOCAL_URL}'
    WHERE url LIKE 'http%' AND url != '${LOCAL_URL}'
      AND NOT EXISTS (SELECT 1 FROM (SELECT url FROM sales_channel_domain) d WHERE d.url='${LOCAL_URL}')
    LIMIT 1;" 2>/dev/null || true
# … and make sure the plain-http fallback domain exists alongside it.
mysql -hmariadb -uroot -proot shopware -e "
    INSERT INTO sales_channel_domain (id, sales_channel_id, language_id, url, currency_id, snippet_set_id, created_at)
    SELECT UNHEX(REPLACE(UUID(),'-','')), sales_channel_id, language_id, '${FALLBACK_URL}', currency_id, snippet_set_id, NOW()
    FROM sales_channel_domain
    WHERE url='${LOCAL_URL}'
      AND NOT EXISTS (SELECT 1 FROM (SELECT url FROM sales_channel_domain) d WHERE d.url='${FALLBACK_URL}')
    LIMIT 1;" 2>/dev/null || true
print_success "Sales channel domains updated (${LOCAL_URL} + ${FALLBACK_URL})"

print_step "Installing assets + compiling theme..."
bin/console assets:install --no-interaction >/dev/null
bin/console theme:compile --no-interaction >/dev/null
print_success "Assets installed, theme compiled"

print_step "Clearing cache..."
bin/console cache:clear --no-interaction >/dev/null
print_success "Cache cleared"

echo ""
echo -e "${GREEN}${BOLD}✅ Setup complete — ${CYAN}${LOCAL_URL}${NC}"
