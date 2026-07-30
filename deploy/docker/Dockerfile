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
#   - Nothing outside this repository is needed to build it. Everything comes from the clone and
#     from Packagist, so `git clone && docker compose build` works for anybody.

FROM php:8.4-fpm-alpine AS base

# Security patches for whatever the base image shipped with, before anything else is installed.
#
# The base image is rebuilt on its own schedule, so between rebuilds it carries whatever was current
# when it was cut. Taking the upgrade here means a fix lands the next time this image is built rather
# than the next time upstream gets round to it — which is how CVE-2026-33630 in c-ares was blocking the
# build with a fix available two patch versions ahead.
#
# It does mean two builds of the same commit on different days can contain different package versions.
# That is the right trade for security patches, and it does not affect the reproducibility that matters:
# the release artifact is the source tarball, which is byte-identical regardless.
RUN apk upgrade --no-cache

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
