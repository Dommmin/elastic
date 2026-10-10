# ============================================================================
#  search-service (Symfony 8 + Messenger) — konsumenci zdarzeń
#
#  W odróżnieniu od `catalog` ten obraz służy głównie procesom CLI
#  (messenger:consume), a nie HTTP. Ale bazę mamy tę samą (FrankenPHP),
#  bo:
#    - jedna wersja PHP dla obu serwisów = mniej klasy problemów "u mnie działa"
#    - search-http (małe API administracyjne) użyje tego samego obrazu
#
#  UWAGA na ext-amqp: Symfony Messenger w transporcie AMQP wymaga rozszerzenia
#  `amqp` (nie php-amqplib!). Bez niego DSN amqp:// wywali się dopiero
#  w runtime, komunikatem o nieznanym transporcie.
# ============================================================================

ARG FRANKENPHP_VERSION=1.12.7
ARG PHP_VERSION=8.5

FROM dunglas/frankenphp:${FRANKENPHP_VERSION}-php${PHP_VERSION} AS base

RUN install-php-extensions \
      pdo_pgsql \
      redis \
      amqp \
      intl \
      opcache \
      pcntl \
      sockets \
      zip \
      @composer

# procps daje `pgrep`, którego używa HEALTHCHECK poniżej — nie jest domyślnie
# w tym obrazie. Przyda się też do debugowania (`ps aux` w kontenerze).
RUN apt-get update && apt-get install -y --no-install-recommends procps \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# --- ustawienia dla procesów długo działających -----------------------------
# Konsument żyje godzinami. Domyślne limity PHP są pisane pod krótkie żądania
# HTTP i tu nie pasują.
ENV PHP_MEMORY_LIMIT=256M \
    PHP_MAX_EXECUTION_TIME=0

# Obraz bazowy dunglas/frankenphp ma wbudowany HEALTHCHECK sprawdzający
# Admin API Caddy'ego na porcie 2019. Te kontenery NIE uruchamiają serwera
# HTTP FrankenPHP — to gołe procesy CLI (messenger:consume) — więc ten port
# nigdy się nie otworzy i kontener byłby wiecznie "unhealthy" mimo poprawnej
# pracy. Zastępujemy sprawdzeniem, że proces konsumenta faktycznie żyje.
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
  CMD pgrep -f "messenger:consume" || exit 1

FROM base AS dev
ENV APP_ENV=dev \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=1
# Kod montowany jako volume — patrz compose.yaml.
# Komenda przychodzi z compose (messenger:consume ...), bo każdy konsument
# obsługuje inną kolejkę z tego samego obrazu.
CMD ["php", "-a"]

FROM base AS prod
ENV APP_ENV=prod \
    PHP_OPCACHE_VALIDATE_TIMESTAMPS=0

COPY apps/search/composer.json apps/search/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY apps/search/ ./
# Mapowanie indeksu dla `search:index:create` — lokalnie montowane z repo.
COPY infra/elasticsearch/mappings /infra/elasticsearch/mappings
# Pusty .env: Symfony (Dotenv::bootEnv) RZUCA wyjątkiem, gdy pliku nie ma,
# a apps/search/.env jest gitignorowany — w czystym klonie (CI) go nie ma.
# Wszystkie wartości przychodzą ze zmiennych środowiskowych z compose;
# "Real environment variables win over .env files" (komentarz w samym .env).
#
# cache:warmup kompiluje kontener DI i wymaga, żeby zmienne z %env()%
# ISTNIAŁY (np. Doctrine `resolve:DATABASE_URL`, routing `DEFAULT_URI`).
# Atrapy z search.build.env są montowane TYLKO na czas tej komendy —
# nic nie zostaje w obrazie. Prawdziwe wartości przychodzą w runtime:
# %env()% jest rozwiązywane przy starcie, nie zamrażane w cache
# (inaczej niż config:cache w Laravelu — patrz catalog-entrypoint.sh).
RUN --mount=type=bind,source=infra/php/search.build.env,target=/tmp/build.env \
    touch .env \
 && composer dump-autoload --optimize --classmap-authoritative \
 && (set -a && . /tmp/build.env && set +a && php bin/console cache:warmup)

# "product_sync", NIE "sync" — patrz messenger.yaml (kolizja z wbudowanym
# pseudo-transportem synchronicznym Symfony).
CMD ["php", "bin/console", "messenger:consume", "product_sync", "--time-limit=3600", "--memory-limit=256M"]
