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
#
# UWAGA na DWA błędy, które tu były i które naprawiłem (zostawiam opis,
# bo to klasyka Caddy/TLS i sam się na to złapałem):
#
# 1. `curl -f` bez `-L` NIE traktuje przekierowania 308 (http->https) jako
#    błędu — kończy się sukcesem, nawet gdy PHP pod spodem rzuca 500.
#    Kontener wychodził "healthy", mimo że aplikacja realnie nie działała
#    (np. brakujące migracje). Naprawa: dodać `-L`, żeby curl poszedł za
#    przekierowaniem i realnie ocenił status odpowiedzi.
# 2. `curl http://localhost/` wysyła Host: localhost. Caddy przekierowuje
#    na `https://localhost/`, ale lokalny certyfikat CA jest wystawiony dla
#    identyfikatora z $SERVER_NAME (catalog.localhost), nie dla "localhost".
#    SNI się nie zgadza -> handshake pada z "tlsv1 alert internal error".
#    Naprawa: `--resolve` + URL na DOKŁADNIE tę nazwę hosta, którą ma
#    skonfigurowany Caddyfile — wtedy SNI pasuje do certyfikatu.
#
# `/up` to WBUDOWANY health check Laravela (bootstrap/app.php: health: '/up')
# — czysta liveness (PHP wstał, framework się zbootstrapował), bez zależności
# od DB/sesji/Vite. Świadomie NIE używamy tu `/health` (ETAP 6): ten endpoint
# sprawdza PG/Redis/ES/RabbitMQ, a "sprawdzaj zależności w Docker HEALTHCHECK"
# to prosta droga do lawiny restartów, gdy jedna z nich spowolni na chwilę.
# `/health` służy monitoringowi/load balancerowi (readiness), nie orkiestracji
# kontenera (liveness) — to świadomie dwa różne pytania.
HEALTHCHECK --interval=15s --timeout=5s --start-period=40s --retries=5 \
  CMD curl -fsSkL --resolve "${SERVER_NAME:-catalog.localhost}:443:127.0.0.1" \
      "https://${SERVER_NAME:-catalog.localhost}/up" -o /dev/null || exit 1

# ----------------------------------------------------------------- dev ------
FROM base AS dev

ENV APP_ENV=local \
    # W dev OPcache MUSI sprawdzać daty plików, inaczej nie zobaczysz swoich zmian.
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=1

COPY infra/caddy/Caddyfile /etc/frankenphp/Caddyfile

# Kod montowany jako volume (patrz compose.yaml) — nic nie kopiujemy.
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]

# ------------------------------------------------------------- vite (dev) ---
# Osobny kontener dla `npm run dev` (HMR). Bazuje na TYM SAMYM obrazie co
# `base` (PHP 8.5.9), a nie na czystym node:alpine, z jednego konkretnego
# powodu: `@laravel/vite-plugin-wayfinder` przy starcie Vite shelluje do
# `php artisan wayfinder:generate`, żeby wygenerować typowane helpery tras
# w TS. Bez PHP w tym samym kontenerze Vite pada od razu przy starcie
# ("php: not found"). Node dokładamy przez NodeSource — nie da się po prostu
# skopiować binarki z obrazu node:alpine, bo Alpine (musl) i Debian (glibc,
# baza FrankenPHP) mają niekompatybilne libc.
FROM base AS vite

ARG NODE_VERSION

# Nadpisujemy HEALTHCHECK odziedziczony z `base` (curl na Caddy'ego przez
# HTTPS) — ten kontener nie uruchamia Caddy'ego/FrankenPHP w ogóle, tylko
# `npm run dev` na porcie 5173 zwykłym HTTP. Ta sama klasa błędu co poniżej
# w search.Dockerfile: sprawdzaj to, co kontener FAKTYCZNIE robi.
#
# UWAGA: `/` na Vite w integracji z Laravelem zwraca 404 CELOWO — Vite tu
# jest czystym serwerem assetów/HMR, stronę główną renderuje PHP przez Caddy.
# `/@vite/client` to skrypt HMR, który Vite zawsze serwuje — dobry cel testu.
HEALTHCHECK --interval=15s --timeout=5s --start-period=30s --retries=5 \
  CMD curl -fsS http://localhost:5173/@vite/client -o /dev/null || exit 1

RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates curl gnupg \
 && curl -fsSL https://deb.nodesource.com/setup_$(echo "${NODE_VERSION}" | cut -d. -f1).x | bash - \
 && apt-get install -y --no-install-recommends nodejs \
 && rm -rf /var/lib/apt/lists/*

CMD ["sh", "-c", "npm install && npm run dev -- --host 0.0.0.0"]

# ------------------------------------------------------ vendor (prod) -------
# Kod + zależności BEZ dev. Pierwsze w kolejności, bo potrzebuje go build
# assetów (patrz niżej).
FROM base AS vendor

WORKDIR /app
# Najpierw manifesty — warstwa z zależnościami cache'uje się, dopóki się nie zmienią.
COPY apps/catalog/composer.json apps/catalog/composer.lock ./
RUN composer install \
      --no-dev --no-scripts --no-autoloader \
      --prefer-dist --no-interaction
COPY apps/catalog/ ./
# `package:discover` normalnie odpala composer (post-autoload-dump), ale
# --no-scripts go wyłącza. Bez niego Laravel nie zna providerów z paczek —
# m.in. komendy `wayfinder:generate`, której potrzebuje build assetów.
RUN composer dump-autoload --optimize --classmap-authoritative \
 && php artisan package:discover --ansi

# ------------------------------------------------------- assets (prod) ------
# NIE node:alpine (tak było — i build padał na czystym klonie): plugin
# @laravel/vite-plugin-wayfinder przy `npm run build` też woła
# `php artisan wayfinder:generate`, więc potrzebny jest PHP + vendor/ +
# kod aplikacji. Lokalnie "działało", bo `npm run build` szło na hoście,
# gdzie PHP i vendor/ są. Etap `vite` = base + Node, dokładnie to, czego trzeba.
FROM vite AS assets

COPY --from=vendor /app /app
RUN npm ci && npm run build

# ---------------------------------------------------------------- prod ------
FROM base AS prod

# SHA commita, z którego zbudowano obraz (CI: --build-arg APP_VERSION=<sha>).
# Trafia do nagłówka X-App-Version (Caddyfile) — po wdrożeniu i rollbacku
# widać z zewnątrz, która wersja faktycznie odpowiada.
ARG APP_VERSION=unknown
ENV APP_VERSION=${APP_VERSION}

ENV APP_ENV=production \
    # W produkcji PHP nie sprawdza dat plików — kod jest niezmienny w obrazie.
    # To jedna z najtańszych optymalizacji, jakie istnieją.
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0

COPY infra/caddy/Caddyfile /etc/frankenphp/Caddyfile
COPY --from=vendor /app /app
COPY --from=assets /app/public/build ./public/build
# Zapytania kontrolne dla `search:eval` — lokalnie montowane z repo.
COPY tests/relevance /tests/relevance
COPY --chmod=0755 infra/php/catalog-entrypoint.sh /usr/local/bin/catalog-entrypoint.sh

# route:cache i view:cache NIE zależą od zmiennych środowiskowych — mogą
# powstać w buildzie. config:cache — NIE (patrz catalog-entrypoint.sh).
RUN php artisan route:cache \
 && php artisan view:cache \
 && chown -R www-data:www-data storage bootstrap/cache

ENTRYPOINT ["catalog-entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/frankenphp/Caddyfile"]
