# ---------------------------------------------
# Build args (override at build time if needed)
# ---------------------------------------------
ARG ALPINE_VERSION=3.22
ARG PHP_VERSION=8.5
ARG NODE_VERSION=24

# =============================================
# 1) Composer stage (real PHP 8.5 — no php platform-req hack)
# =============================================
FROM php:${PHP_VERSION}-cli-alpine${ALPINE_VERSION} AS composer
WORKDIR /app
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN apk add --no-cache git unzip
COPY composer.json composer.lock* ./
ARG APP_ENV=production
ENV COMPOSER_ALLOW_SUPERUSER=1

# -----------------------------------------------------------------------------
# THERE IS NO GITHUB TOKEN HERE, AND THERE NEVER WAS ONE.
# -----------------------------------------------------------------------------
# This stage used to take a `GH_PACKAGES_TOKEN` build-arg, export it as `ENV
# GITHUB_TOKEN`, and hand it to `composer config --global --auth`. Docker warned about
# it on every build (SecretsUsedInArgOrEnv) and was right to: `ARG`/`ENV` values are
# recorded in the image's metadata — `docker history` prints them straight back — and
# the auth config writes the token into a filesystem layer, which this build exports to
# the GitHub Actions cache.
#
# But the warning was the smaller half of the story. `gh secret list` says
# GH_PACKAGES_TOKEN has never existed on this repository. The workflow was passing an
# EMPTY value, `[ -n "$GH_PACKAGES_TOKEN" ]` was false on every build since the first
# one, and composer never authenticated with anything. The plumbing did nothing except
# generate a security warning about a secret that was not there.
#
# It is not needed, either: composer.json declares no private repositories. A token
# would only lift the GitHub API rate limit — worth adding the day a build actually
# trips it, as a `--mount=type=secret` (never an ARG), and not before.
#
# Runtime provides ext-pcntl (built below); ignore only that ext for resolution, never
# the PHP version — the version is the check that actually matters.
#
# --no-scripts always: this stage has only composer.json/lock, no artisan, so
# post-autoload scripts (package:discover) would fail. They run at container boot.
RUN if [ "$APP_ENV" = "production" ]; then \
        composer install --no-dev --prefer-dist --no-progress --no-interaction --no-scripts --no-ansi --ignore-platform-req=ext-pcntl; \
    else \
        composer install --prefer-dist --no-progress --no-interaction --no-scripts --no-ansi --ignore-platform-req=ext-pcntl; \
    fi

# =============================================
# 2) Node build stage (compiles Vite assets)
# =============================================
FROM node:${NODE_VERSION}-alpine AS node_builder
WORKDIR /app
COPY package.json package-lock.json* .npmrc* ./
RUN if [ -f package-lock.json ]; then npm ci; else npm i; fi
COPY . ./
# `import 'ziggy-js'` resolves to vendor/tightenco/ziggy (tsconfig path). vendor is
# .dockerignored, so bring Ziggy in from the composer stage — both tsc and vite need it.
COPY --from=composer /app/vendor/tightenco/ziggy ./vendor/tightenco/ziggy
RUN npm run build

# =============================================
# 3) PHP-FPM + Nginx runtime (single container)
# =============================================
FROM php:${PHP_VERSION}-fpm-alpine${ALPINE_VERSION} AS runtime

# ---------- Packages ----------
RUN apk update && apk add --no-cache \
    nginx \
    supervisor \
    bash \
    curl \
    dos2unix \
    icu-libs \
    libjpeg-turbo \
    libpng \
    freetype \
    libzip \
    postgresql-libs \
    apache2-utils && \
    apk add --no-cache --virtual .build-deps \
    $PHPIZE_DEPS \
    linux-headers \
    icu-dev \
    libjpeg-turbo-dev \
    libpng-dev \
    freetype-dev \
    libzip-dev \
    postgresql-dev

# ---------- PHP Extensions ----------
RUN docker-php-ext-configure gd --with-freetype --with-jpeg && \
    docker-php-ext-install -j"$(nproc)" \
    bcmath \
    exif \
    gd \
    intl \
    pcntl \
    pdo_pgsql \
    zip

# ---------- PECL Extensions ----------
ARG INSTALL_XDEBUG=0
RUN pecl install redis igbinary msgpack \
    && docker-php-ext-enable redis igbinary msgpack \
    && if [ "$INSTALL_XDEBUG" = "1" ]; then pecl install xdebug && docker-php-ext-enable xdebug; fi

# Shrink final image
RUN apk del .build-deps

# ---------- Permissions / logging ----------
RUN mkdir -p /var/lib/nginx /var/log/nginx /run/nginx && \
    chown -R www-data:www-data /var/lib/nginx /var/log/nginx /run/nginx && \
    ln -sf /dev/stdout /var/log/nginx/access.log && \
    ln -sf /dev/stderr /var/log/nginx/error.log

# ---------- PHP-FPM tuning via env (conservative for the shared box) ----------
ENV FPM_PM_MAX_CHILDREN=8 \
    FPM_PM_START_SERVERS=2 \
    FPM_PM_MIN_SPARE_SERVERS=1 \
    FPM_PM_MAX_SPARE_SERVERS=3 \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/composer \
    PATH="/composer/vendor/bin:$PATH"

WORKDIR /var/www/app

# Vendor from composer stage, then full source, then built assets last so the
# compiled Vite output is never clobbered by the copied source tree.
COPY --from=composer /app/vendor ./vendor
COPY . ./
COPY --from=node_builder /app/public/build ./public/build

# The service worker, and it has to be copied EXPLICITLY.
#
# `npm run build` generates it (scripts/build-sw.mjs — the cache name is the build hash),
# and .gitignore excludes it precisely because it is generated, so `COPY . ./` above cannot
# bring it: it is not in the source tree. Copying only `public/build` therefore shipped an
# image with no /sw.js at all, which 404'd on every request, which meant the worker never
# registered — so the offline KEPT screen (S11), the one thing the product promises will
# work in a dead zone, has never once worked in production.
COPY --from=node_builder /app/public/sw.js ./public/sw.js

# Production uses compiled assets, not the Vite dev server
RUN rm -f public/hot

RUN mkdir -p storage/framework/{cache/data,sessions,testing,views} storage/app/public bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# ---------- Nginx / Supervisor / Entrypoints ----------
COPY deployment/docker/supervisord.conf /etc/supervisord.conf
COPY deployment/docker/nginx/conf/nginx.conf /etc/nginx/nginx.conf
COPY deployment/docker/nginx/conf/default.conf /etc/nginx/conf.d/default.conf
COPY deployment/docker/php/conf.d/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

COPY deployment/docker/start-container /usr/local/bin/start-container
COPY deployment/docker/docker-php-* /usr/local/bin/
RUN dos2unix /usr/local/bin/start-container /usr/local/bin/docker-php-entrypoint /usr/local/bin/docker-php-entrypoint-dev 2>/dev/null || true && \
    chmod +x /usr/local/bin/start-container /usr/local/bin/docker-php-entrypoint /usr/local/bin/docker-php-entrypoint-dev

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/start-container"]
