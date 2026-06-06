# syntax=docker/dockerfile:1.4
#
# Production image — official Shopware docker deployment pattern.
# Stage 1 builds the project with shopware-cli (composer install --no-dev,
# admin/storefront asset build for all extensions, source cleanup).
# Stage 2 is the slim runtime: PHP-FPM + Caddy listening on port 8000.
#
# At container start the docker-base entrypoint runs /setup, which detects
# vendor/bin/shopware-deployment-helper and lets it handle system install /
# migrations / plugin lifecycle (see .shopware-project.yml).
#
# Built and pushed by .github/workflows/docker-publish.yml; consumed by
# compose.prod.yaml.

ARG PHP_VERSION=8.3

FROM ghcr.io/shopware/shopware-cli:latest-php-${PHP_VERSION} AS build

ENV COMPOSER_ALLOW_SUPERUSER=1

COPY . /src
WORKDIR /src

RUN --mount=type=cache,target=/root/.composer \
    --mount=type=cache,target=/root/.npm \
    shopware-cli project ci /src

FROM ghcr.io/shopware/docker-base:${PHP_VERSION}-caddy

COPY --from=build --chown=82 --link /src /var/www/html
