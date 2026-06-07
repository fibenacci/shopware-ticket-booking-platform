# CI image — extends dockware/shopware:6.7-latest with our codebase pre-installed,
# plugins activated, demo data seeded and the storefront pre-built. The
# bootstrap script then has to do nothing at container start beyond
# `compose up`: every CI run (and every local rehearsal after the first
# build) skips the slow composer/plugin:install/theme:compile phases.
#
# Build context: repo root (compose.ci.yml passes `context: ../..`).
#
# Layer order is tuned for cache reuse:
#   1. base                                              rebuilds: never
#   2. composer.json/.lock + custom/ + composer install  rebuilds: lock OR custom change
#      (custom/ has to be present BEFORE composer install because
#       composer.json declares path repositories under it)
#   3. config/ + src/ + Symfony-CI drop-in               rebuilds: src/ or config/ change
#   4. in-image setup (MariaDB up → migrate + plugin:install
#      + demodata + storefront build → MariaDB down)     rebuilds: with (2) or (3)
#
# MariaDB note: dockware ships its DB data in /var/lib/mysql. We start the
# service during the setup RUN so migrations/plugin rows/demo data land in
# the image layer; Docker's anonymous-volume init copies that directory into
# the fresh volume on every `compose up`.
FROM dockware/shopware:6.7-latest

USER root
WORKDIR /var/www/html

ENV COMPOSER_ALLOW_SUPERUSER=1
ENV PHP_VERSION=8.3
RUN update-alternatives --set php /usr/bin/php8.3

RUN set -eux; \
    zstd -d /var/www/html/shopware.tar.zst -o /var/www/html/shopware.tar; \
    tar -xf /var/www/html/shopware.tar -C /var/www/html; \
    rm /var/www/html/shopware.tar.zst /var/www/html/shopware.tar; \
    if [ -f /var/www/nvm.tar.zst ]; then \
        zstd -d /var/www/nvm.tar.zst -o /var/www/nvm.tar; \
        tar -xf /var/www/nvm.tar -C /var/www/.nvm; \
        rm /var/www/nvm.tar.zst /var/www/nvm.tar; \
    fi

ENV SHOPWARE_ES_ENABLED=0 \
    SHOPWARE_ES_INDEXING_ENABLED=0 \
    SHOPWARE_ADMIN_ES_ENABLED=0 \
    SHOPWARE_ES_THROW_EXCEPTION=0

ENV DATABASE_URL=mysql://root:root@127.0.0.1:3306/shopware \
    APP_URL=http://localhost:8080 \
    APP_SECRET=ci0123456789abcdef0123456789abcdef

ENV MERCURE_URL=http://localhost/.well-known/mercure \
    MERCURE_PUBLIC_URL=http://localhost/.well-known/mercure \
    MERCURE_JWT_SECRET=ci-build-dummy-secret-0123456789abcdef \
    MERCURE_SUBSCRIBER_JWT_SECRET=ci-build-dummy-subscriber-0123456789abcdef

COPY composer.json composer.lock symfony.lock ./
COPY custom ./custom
RUN composer install --no-interaction --prefer-dist --no-progress --no-scripts \
    && rm -rf /root/.composer/cache

RUN rm -rf ./config ./var/cache
COPY config ./config
COPY src ./src
COPY .github/ci/symfony-ci-overrides.yaml ./config/packages/zz-ci-overrides.yaml

SHELL ["/bin/bash", "-c"]
RUN set -eux; \
    service mysql start; \
    for _ in $(seq 1 30); do \
        mysqladmin ping -h 127.0.0.1 --silent && break || sleep 1; \
    done; \
    bin/console database:migrate --all --no-interaction || true; \
    bin/console plugin:refresh --no-interaction; \
    bin/console plugin:install --activate --no-interaction FibBookingSystem; \
    bin/console plugin:install --activate --no-interaction FibBookingDemoData; \
    bin/console plugin:install --activate --no-interaction FibBookingTheme; \
    bin/console theme:change --all FibBookingTheme --no-interaction; \
    bin/console fib-booking:demodata; \
    export NVM_DIR=/var/www/.nvm; \
    source "$NVM_DIR/nvm.sh"; \
    ./bin/build-storefront.sh; \
    service mysql stop; \
    chown -R www-data:www-data /var/www/html

RUN printf '%s\n' \
        "MERCURE_URL=${MERCURE_URL}" \
        "MERCURE_PUBLIC_URL=${MERCURE_PUBLIC_URL}" \
        "MERCURE_JWT_SECRET=${MERCURE_JWT_SECRET}" \
        "MERCURE_SUBSCRIBER_JWT_SECRET=${MERCURE_SUBSCRIBER_JWT_SECRET}" \
        > .env.local \
    && chown www-data:www-data .env.local
