# =============================================================================
# 3bayti API — Production Dockerfile
# =============================================================================
#
# Single-image build (PHP-FPM + nginx in one container). This is the
# pattern DigitalOcean App Platform expects for PHP services.
#
# Build:
#   docker build -t 3bayti-api -f apps/api/Dockerfile apps/api
#
# Run:
#   docker run --rm -p 8080:8080 \
#     -e APP_ENV=prod \
#     -e DB_HOST=... -e DB_USERNAME=... -e DB_PASSWORD=... \
#     -e JWT_SECRET=... \
#     3bayti-api
#
# Multi-stage rationale
# ---------------------
# Stage 1 (composer): pulls dev-friendly composer image, runs install,
#   produces a vendor/ directory. We isolate this so the final image
#   doesn't include composer itself (~50 MB) — it's only needed to
#   produce vendor/, not at runtime.
#
# Stage 2 (runtime): PHP 8.3-fpm-alpine + nginx. Copies in vendor/
#   from stage 1 plus our app code. Configures FPM + nginx to talk
#   to each other via Unix socket. supervisord runs both processes.
#
# Why alpine (not Debian)
# -----------------------
# Smaller image (~80 MB vs ~250 MB), faster pulls, faster cold-starts.
# The PHP extensions we need (pdo_pgsql, opcache, intl) are all
# available via apk. If we needed something exotic we'd reconsider.
#
# Why not nginx-fpm split into two containers
# -------------------------------------------
# DO App Platform charges per-component, and this is a single PHP
# service — splitting nginx into a sidecar adds cost without benefit.
# In Kubernetes we'd split (multi-container pods are free); here we
# don't.
# =============================================================================

# -----------------------------------------------------------------------------
# Stage 1: composer install (dev-time only, not in final image)
# -----------------------------------------------------------------------------
FROM composer:2.7 AS composer-deps

WORKDIR /app

# Copy only composer files first — this layer caches as long as
# composer.json and composer.lock don't change. Subsequent builds
# that only change PHP source skip the slow composer install step.
COPY composer.json composer.lock* ./

# Install production dependencies. --no-dev drops PHPUnit / PHPStan
# / dev tools. --optimize-autoloader generates a class map for
# faster autoloading in prod (~30% faster than PSR-4 dynamic).
# --no-scripts because some packages run scripts (e.g. doctrine cache
# warmup) that need our app code which we haven't copied yet.
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-interaction \
    --no-progress \
    --optimize-autoloader \
    --prefer-dist

# -----------------------------------------------------------------------------
# Stage 2: runtime image
# -----------------------------------------------------------------------------
FROM php:8.3-fpm-alpine AS runtime

# Install system dependencies + PHP extensions.
#
# - postgresql-libs + postgresql-dev → required to build pdo_pgsql
# - icu-dev → required for intl extension (used by symfony/validator
#             internally for some constraints)
# - oniguruma-dev → mbstring extension dependency
# - nginx → frontend web server
# - supervisor → runs nginx + php-fpm side-by-side in one container
# - curl → for the Dockerfile HEALTHCHECK
# - tzdata → so the container's timezone matches our intent
RUN apk add --no-cache \
        nginx \
        supervisor \
        curl \
        tzdata \
        postgresql-libs \
        icu-libs \
        oniguruma \
    && apk add --no-cache --virtual .build-deps \
        postgresql-dev \
        icu-dev \
        oniguruma-dev \
        autoconf \
        g++ \
        make \
    && docker-php-ext-install \
        pdo \
        pdo_pgsql \
        intl \
        opcache \
        bcmath \
    && pecl install redis-6.0.2 \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /var/cache/apk/* /tmp/*

# Production PHP config — opcache tuned, no error display.
COPY apps/api/docker/php.ini /usr/local/etc/php/conf.d/zz-prod.ini

# Production PHP-FPM pool — listen on Unix socket, log to stderr,
# tuned worker counts.
COPY apps/api/docker/www.conf /usr/local/etc/php-fpm.d/www.conf

# nginx config — front-end for FPM.
COPY apps/api/docker/nginx.conf /etc/nginx/nginx.conf
COPY apps/api/docker/default.conf /etc/nginx/http.d/default.conf

# supervisord config — runs nginx + php-fpm.
COPY apps/api/docker/supervisord.conf /etc/supervisord.conf

# Entrypoint script handles dynamic config (e.g. reading PORT from
# App Platform) and waits for DB on startup.
COPY apps/api/docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

# Copy vendor/ from stage 1 — composer is no longer in the image.
COPY --from=composer-deps /app/vendor ./vendor

# Copy application code. .dockerignore (sibling to this file) excludes
# tests, dev-only fixtures, .env files, and other non-prod artifacts.
COPY apps/api/ ./

# Final permissions. www-data is the user FPM runs as.
RUN mkdir -p var/cache var/log \
    && chown -R www-data:www-data /var/www/html

# DO App Platform sets PORT in the environment; default 8080 for
# local docker run.
ENV PORT=8080
EXPOSE 8080

# HEALTHCHECK — the container orchestrator checks this. We hit
# /v3/health (liveness only — no DB dependency). 5s interval is
# aggressive enough to detect crashes quickly without saturating logs.
HEALTHCHECK --interval=10s --timeout=3s --start-period=10s --retries=3 \
    CMD curl -fsS "http://127.0.0.1:${PORT}/v3/health" || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
