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

WORKDIR /app

# --- ustawienia dla procesów długo działających -----------------------------
# Konsument żyje godzinami. Domyślne limity PHP są pisane pod krótkie żądania
# HTTP i tu nie pasują.
ENV PHP_MEMORY_LIMIT=256M \
    PHP_MAX_EXECUTION_TIME=0

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
RUN composer dump-autoload --optimize --classmap-authoritative \
 && php bin/console cache:warmup

CMD ["php", "bin/console", "messenger:consume", "sync", "--time-limit=3600", "--memory-limit=256M"]
