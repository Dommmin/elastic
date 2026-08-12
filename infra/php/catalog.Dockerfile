# ============================================================================
#  catalog (Laravel 13 + Inertia/Vue) na FrankenPHP
#
#  FrankenPHP zastępuje PARĘ nginx + php-fpm jednym procesem, bo jest modułem
#  serwera Caddy. Stąd: HTTP/2 i HTTP/3, automatyczne HTTPS, serwowanie plików
#  statycznych bez dotykania PHP — i opcjonalny worker mode (decyzja D-07b).
#
#  Cele budowania (build targets):
#    dev  — runtime + narzędzia, kod montowany jako volume (domyślny)
#    prod — kod i assety wkompilowane w obraz, OPcache bez walidacji plików
#
#  Budowanie wersji produkcyjnej:  docker build --target prod ...
# ============================================================================

ARG FRANKENPHP_VERSION=1.12.7
ARG PHP_VERSION=8.5
ARG NODE_VERSION=24.19.0

# ---------------------------------------------------------------- base ------
FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION} AS base

# install-php-extensions jest wbudowany w obrazy FrankenPHP — ogarnia
# zależności systemowe i kompilację, więc nie musimy ich wypisywać ręcznie.
RUN install-php-extensions \
      pdo_pgsql \
      redis \
      amqp \
      intl \
      opcache \
      pcntl \
      sockets \
      zip \
      bcmath \
      @composer

WORKDIR /app

# Healthcheck aplikacji — używany przez compose i przez `make smoke`.
HEALTHCHECK --interval=15s --timeout=5s --start-period=40s --retries=5 \
  CMD curl -fsS http://localhost/health || exit 1

# ----------------------------------------------------------------- dev ------
FROM base AS dev

ENV APP_ENV=local \
    # W dev OPcache MUSI sprawdzać daty plików, inaczej nie zobaczysz swoich zmian.
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=1

COPY infra/caddy/Caddyfile /etc/frankenphp/Caddyfile

# Kod montowany jako volume (patrz compose.yaml) — nic nie kopiujemy.
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

# ------------------------------------------------------- assets (prod) ------
FROM node:${NODE_VERSION}-alpine AS assets

WORKDIR /app
# Najpierw manifesty — warstwa z zależnościami cache'uje się, dopóki się nie zmienią.
COPY apps/catalog/package*.json ./
RUN npm ci
COPY apps/catalog/ ./
RUN npm run build

# ------------------------------------------------------ vendor (prod) -------
FROM base AS vendor

WORKDIR /app
COPY apps/catalog/composer.json apps/catalog/composer.lock ./
RUN composer install \
      --no-dev --no-scripts --no-autoloader \
      --prefer-dist --no-interaction

# ---------------------------------------------------------------- prod ------
FROM base AS prod

ENV APP_ENV=production \
    # W produkcji PHP nie sprawdza dat plików — kod jest niezmienny w obrazie.
    # To jedna z najtańszych optymalizacji, jakie istnieją.
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0

COPY infra/caddy/Caddyfile /etc/frankenphp/Caddyfile
COPY --from=vendor /app/vendor ./vendor
COPY apps/catalog/ ./
COPY --from=assets /app/public/build ./public/build

RUN composer dump-autoload --optimize --classmap-authoritative \
 && php artisan config:cache \
 && php artisan route:cache \
 && php artisan view:cache \
 && chown -R www-data:www-data storage bootstrap/cache

CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
