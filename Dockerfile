# syntax=docker/dockerfile:1
#
# FirmsBase production image. ONE image, used for every ECS role
# (web / queue worker / scheduler / migration / maintenance) — the role is
# selected at `docker run`/ECS task `command` time by docker/entrypoint.sh,
# never by building a different image. See docs/ecs/container-architecture.md
# for the full rationale behind every decision in this file.
#
# NOTE: this Dockerfile has not been built with a real `docker build` in the
# environment this branch was authored in (no Docker daemon available in that
# sandbox — see docs/ecs/staging-readiness-report.md). It has been reviewed
# line-by-line against documented FrankenPHP/Composer/Laravel conventions and
# validated with `docker build --check`-equivalent static reasoning, but a
# real build on a Docker-capable host is a required step before this image is
# used for anything beyond review. Package names in `install-php-extensions`
# and the exact FrankenPHP base tag should be reconfirmed against the
# registry at that time.

ARG PHP_VERSION=8.3
ARG FRANKENPHP_BASE_TAG=1-php8.3-bookworm
# Vite 8 / laravel-vite-plugin 3.1 / @tailwindcss/oxide require Node >=20.19
# (see package.json "engines" and docs/ecs/ec2-dependency-audit.md) — this is
# a HARD requirement, not just a pin-for-consistency choice; the reference
# dev environment this branch was authored in only had Node 18.19 available,
# which is new-enough to generate package-lock.json but NOT new enough to
# run `npm run build`, so that step could not be locally verified here (see
# docs/ecs/staging-readiness-report.md).
ARG NODE_VERSION=20-bookworm-slim
ARG COMPOSER_VERSION=2.7

# ---------------------------------------------------------------------------
# Stage: frontend — compile Vite/Tailwind assets. Nothing from this stage
# except the compiled public/build output ships to the runtime image: no
# node_modules, no Node binary, no source JS/CSS.
# ---------------------------------------------------------------------------
# Named stage (not a direct `COPY --from=composer:${COMPOSER_VERSION}`) —
# BuildKit does not support ARG expansion in `--from=<external-image>:<tag>`,
# only in `--from=<stage-name>`. Discovered by a real `docker build` failure
# ("variable expansion is not supported for --from") on this exact line
# shape; this stage is the documented workaround.
FROM composer:${COMPOSER_VERSION} AS composer_bin

FROM node:${NODE_VERSION} AS frontend
WORKDIR /app
COPY package.json package-lock.json .npmrc ./
RUN npm ci --ignore-scripts
COPY vite.config.js ./
COPY resources/ resources/
COPY public/ public/
RUN npm run build

# ---------------------------------------------------------------------------
# Stage: vendor — install PHP dependencies. Split into two `composer install`
# invocations so Docker layer caching skips the (slow) dependency download
# whenever only application source changed, not composer.json/composer.lock.
# ---------------------------------------------------------------------------
FROM dunglas/frankenphp:${FRANKENPHP_BASE_TAG} AS vendor
COPY --from=composer_bin /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html

# Match the runtime extension set exactly (docs/ecs/container-architecture.md
# "PHP extensions"). install-php-extensions is bundled in the frankenphp base
# image per https://frankenphp.dev/docs/docker/.
RUN install-php-extensions \
    pdo_pgsql \
    pgsql \
    redis \
    bcmath \
    gd \
    intl \
    zip \
    opcache \
    pcntl \
    posix \
    sockets \
    soap \
    sodium \
    exif \
    igbinary

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --no-interaction \
    --no-ansi \
    --no-progress

COPY . .

# Regenerate the optimized autoloader and best-effort package discovery.
# Discovery is deliberately best-effort (|| true): it only primes
# bootstrap/cache/packages.php as a boot-time optimization. If it fails or is
# skipped, Laravel performs discovery at runtime instead — slower first boot,
# not a correctness issue. Discovery is run against a throwaway in-memory
# SQLite connection and a placeholder APP_KEY so it never touches a real
# database or a real secret; neither value is used again after this layer.
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative --no-interaction \
    && (APP_ENV=production \
        APP_KEY="base64:0000000000000000000000000000000000000000=" \
        DB_CONNECTION=sqlite \
        DB_DATABASE=":memory:" \
        php artisan package:discover --ansi || true)

# ---------------------------------------------------------------------------
# Stage: runtime — the image every ECS task actually runs.
# ---------------------------------------------------------------------------
FROM dunglas/frankenphp:${FRANKENPHP_BASE_TAG} AS runtime

ARG GIT_SHA=unknown
ARG BUILD_DATE=unknown
LABEL org.opencontainers.image.revision="${GIT_SHA}" \
      org.opencontainers.image.created="${BUILD_DATE}" \
      org.opencontainers.image.title="firmsbase-app" \
      org.opencontainers.image.description="FirmsBase application image — web/worker/scheduler/migrate/maintenance, selected by command" \
      org.opencontainers.image.source="https://github.com/Aramitusconnect/firmsbase"

RUN install-php-extensions \
    pdo_pgsql \
    pgsql \
    redis \
    bcmath \
    gd \
    intl \
    zip \
    opcache \
    pcntl \
    posix \
    sockets \
    soap \
    sodium \
    exif \
    igbinary

COPY docker/php/production.ini /usr/local/etc/php/conf.d/zz-firmsbase-production.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/zz-firmsbase-opcache.ini
COPY docker/web/Caddyfile /etc/caddy/Caddyfile

WORKDIR /var/www/html

# Application source. .dockerignore excludes .env*, .git, tests/, node_modules,
# storage/logs contents, and anything else that must never reach an image
# layer (see .dockerignore for the full, commented list).
COPY --chown=root:root . .
COPY --from=vendor --chown=root:root /var/www/html/vendor ./vendor
COPY --from=vendor --chown=root:root /var/www/html/bootstrap/cache ./bootstrap/cache
COPY --from=frontend --chown=root:root /app/public/build ./public/build

RUN groupadd --gid 1000 app \
    && useradd --uid 1000 --gid app --no-create-home --shell /usr/sbin/nologin app \
    # The WORKDIR itself (/var/www/html) is created by the frankenphp base
    # image before this stage's `COPY --chown=root:root . .` runs — COPY
    # only sets ownership on the content it copies IN, never on a
    # pre-existing destination directory, so the directory inode itself was
    # left at the base image's own default: 1777 (world-writable + sticky
    # bit) owned by www-data:www-data. Confirmed via a real `docker run`
    # (`stat -c '%a %U:%G' /var/www/html`) — everything actually copied
    # into it (public/, storage/, bootstrap/) was correctly root:root, only
    # the top directory inode was wrong. Non-recursive on purpose: every
    # subdirectory's ownership is already set correctly by its own COPY/
    # mkdir further down.
    && chown root:root /var/www/html \
    && chmod 0755 /var/www/html \
    && mkdir -p \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && chown -R app:app storage bootstrap/cache \
    # FrankenPHP/Caddy's own XDG config/data dirs (XDG_CONFIG_HOME=/config,
    # XDG_DATA_HOME=/data, set by the base image) are root-owned by default,
    # but the runtime user is non-root `app` — without this, Caddy logs
    # "permission denied" errors on every startup trying to autosave its
    # config and TLS storage bookkeeping (harmless functionally since
    # auto_https/admin are disabled in docker/web/Caddyfile, but noisy at
    # error level, which is exactly the kind of log line an alarm on
    # "any error-level log" would false-positive on). Found via a real
    # `docker run` of the web role, not static review.
    && chown -R app:app /config /data \
    && chmod -R 0775 storage bootstrap/cache \
    && chmod +x docker/entrypoint.sh docker/commands/*.sh

# The dunglas/frankenphp base image bakes in
# `HEALTHCHECK CMD curl -f http://localhost:2019/metrics` (Caddy's admin
# API). Confirmed via a real `docker inspect`/`docker run` that this is
# inherited into our image unless explicitly overridden. It would ALWAYS
# fail: docker/web/Caddyfile disables the admin endpoint entirely
# (`admin off`) for the one role that runs Caddy at all (web), and every
# other role (worker/scheduler/migrate/maintenance) never starts Caddy in
# the first place — they exec `php artisan ...` directly. Per
# docs/ecs/container-architecture.md, liveness/readiness for this
# application is `/up`/`/readyz` (web, checked at the ECS task-definition
# level — see infrastructure/ecs/modules/ecs_service) or plain
# process-alive (every other role, ECS's own default). Disabling the
# inherited check here makes the image's own baked-in health status
# neither report a false negative nor require every ECS task definition to
# remember to override it.
HEALTHCHECK NONE

USER app

EXPOSE 8080

ENTRYPOINT ["docker/entrypoint.sh"]
CMD ["web"]
