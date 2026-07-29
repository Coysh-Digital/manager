# syntax=docker/dockerfile:1
#
# Manager Self-Hosted.
#
# One image serves the web role, the queue worker and the scheduler; they differ only in the command
# they run. Building three would mean three things to keep in step and three to scan.
#
# Deliberate choices:
#
#   - nginx and php-fpm rather than an application server holding state between requests. A control
#     plane is somewhere a leaked variable between requests would be a security bug, not a
#     performance quirk.
#   - Runs as a non-root user.
#   - No Node in the final image. Assets are built and committed, so a deployment needs no
#     toolchain — and the image carries no npm dependency tree to audit.

FROM php:8.4-fpm-alpine AS base

# Runtime dependencies only. Each one is here because something needs it: postgresql-dev for pdo_pgsql,
# icu for intl, and the rest for the extension build in the next stage.
RUN apk add --no-cache \
        nginx \
        supervisor \
        postgresql-libs \
        icu-libs \
        libzip \
        libsodium \
        curl \
        tzdata \
    && rm -rf /var/cache/apk/*

RUN --mount=type=cache,target=/var/cache/apk \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        postgresql-dev \
        icu-dev \
        libzip-dev \
        linux-headers \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        pgsql \
        intl \
        zip \
        opcache \
        pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

# --------------------------------------------------------------------------------------------------
# Dependencies
# --------------------------------------------------------------------------------------------------
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# The shared protocol package, supplied as a named build context.
#
# Placed at /protocol so that the "../protocol" path repository in composer.json resolves from
# /app exactly as it does in development — /app/../protocol is /protocol. One declaration works in
# both places rather than two that can drift.
#
# Once manager-protocol is published, this becomes an ordinary Packagist requirement and both this
# and the additional context in compose.yaml go away.
COPY --from=protocol . /protocol

WORKDIR /app

COPY composer.json composer.lock ./

# --no-dev because development tooling has no business in a production image, and every package
# omitted is one fewer thing to scan and to patch.
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --no-scripts \
        --optimize-autoloader

# --------------------------------------------------------------------------------------------------
# Final
# --------------------------------------------------------------------------------------------------
FROM base AS final

ARG MANAGER_VERSION=dev
ENV MANAGER_VERSION=${MANAGER_VERSION}

# A dedicated unprivileged user. Nothing in the application needs root, and the container should not
# be one exploit away from having it.
RUN addgroup -g 1000 manager \
    && adduser -u 1000 -G manager -h /app -s /sbin/nologin -D manager

WORKDIR /app

COPY --chown=manager:manager . .
COPY --from=vendor --chown=manager:manager /app/vendor ./vendor

# Composer installs the protocol package as a symlink, so the target has to exist here too.
COPY --from=protocol --chown=manager:manager . /protocol

# Development files a production image has no use for. Removed rather than merely excluded so the
# build stays a single COPY and cannot silently miss something new.
#
# bootstrap/cache in particular: a package manifest generated with development dependencies present
# makes the image try to boot providers that are not installed.
RUN rm -rf \
        tests deploy .ddev docs \
        phpunit.xml phpstan.neon pint.json \
        package.json package-lock.json vite.config.js node_modules \
        resources/js resources/css \
    && rm -f bootstrap/cache/*.php

COPY deploy/docker/php.ini /usr/local/etc/php/conf.d/manager.ini
COPY deploy/docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-manager.conf
COPY deploy/docker/nginx.conf /etc/nginx/nginx.conf
COPY deploy/docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY --chmod=0755 deploy/docker/entrypoint.sh /usr/local/bin/entrypoint

RUN mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache /var/lib/nginx/tmp /run/nginx \
    && chown -R manager:manager storage bootstrap/cache /var/lib/nginx /run/nginx

USER manager

EXPOSE 8080

# Liveness only. Readiness is /ready, which an orchestrator should poll separately: restarting a
# container because Redis is briefly unavailable would turn a dependency blip into an outage.
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8080/up || exit 1

ENTRYPOINT ["entrypoint"]
CMD ["web"]
