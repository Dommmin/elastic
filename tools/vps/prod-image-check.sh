#!/usr/bin/env bash
# ============================================================================
#  prod-image-check — czy obrazy produkcyjne nadają się do PUBLICZNEGO rejestru
#
#  Obrazy z GHCR są publiczne, a serwer nie ma repo — więc obraz musi być
#  (a) samowystarczalny: cała konfiguracja w środku, nic z bind-mountów,
#  (b) czysty: zero sekretów, także ich pochodnych (hash hasła RabbitMQ),
#  (c) konfigurowany w RUNTIME: zmienne z compose muszą wygrywać.
#
#  Użycie:
#    tools/vps/prod-image-check.sh             # buduje z CZYSTEGO klonu i sprawdza
#    tools/vps/prod-image-check.sh --no-build  # sprawdza istniejące obrazy
#                                              # (CI: obrazy zbudowane wcześniej)
#  Prefiks i tag obrazów:  IMAGE_PREFIX (domyślnie marketplace-prodcheck/), IMAGE_TAG (domyślnie test)
#
#  Dlaczego czysty klon: to dokładnie to, co widzi CI — bez .env, vendor/,
#  node_modules/ i innych plików ignorowanych przez gita. Build z katalogu
#  roboczego mógłby przejść tylko dlatego, że lokalnie leży plik, którego
#  w repo nie ma.
# ============================================================================
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PREFIX="${IMAGE_PREFIX:-marketplace-prodcheck/}"
TAG="${IMAGE_TAG:-test}"
RED='\033[0;31m'; GRN='\033[0;32m'; BLD='\033[1m'; DIM='\033[2m'; NC='\033[0m'
PASS=0; FAIL=0

check() { # check <opis> <oczekiwane> <otrzymane>
  if [ "$2" = "$3" ]; then
    echo -e "  ${GRN}✓${NC} $1"; PASS=$((PASS+1))
  else
    echo -e "  ${RED}✗${NC} $1  ${DIM}(oczekiwano: $2, otrzymano: $3)${NC}"; FAIL=$((FAIL+1))
  fi
}

img() { echo "${PREFIX}$1:${TAG}"; }
# Czy ścieżka istnieje w obrazie (bez uruchamiania jego ENTRYPOINT-a).
has() { docker run --rm --entrypoint sh "$1" -c "test -e '$2' && echo tak || echo nie" 2>/dev/null; }

# Wersje z .env.example — jedno źródło prawdy, to samo czyta CI.
set -a; . "${ROOT}/.env.example"; set +a

if [ "${1:-}" != "--no-build" ]; then
  SRC="$(mktemp -d)"
  trap 'rm -rf "${SRC}"' EXIT
  echo -e "\n${BLD}  Czysty klon → ${SRC}${NC}"
  git clone -q "${ROOT}" "${SRC}"
  # Sprawdzamy NIEZACOMMITOWANE zmiany też: nakładamy diff katalogu roboczego.
  (cd "${ROOT}" && git diff HEAD --binary) | (cd "${SRC}" && git apply --allow-empty 2>/dev/null || true)
  (cd "${ROOT}" && git ls-files --others --exclude-standard -z) \
    | (cd "${ROOT}" && xargs -0 -I{} rsync -R {} "${SRC}/") 2>/dev/null || true

  echo -e "${BLD}  Build 6 obrazów (linux/amd64 w CI, natywnie lokalnie)${NC}"
  build() { # build <nazwa> <kontekst> <dockerfile> [target]
    local args=(--quiet -t "$(img "$1")" -f "${SRC}/$3"
      --build-arg "ES_VERSION=${ES_VERSION}" --build-arg "POSTGRES_VERSION=${POSTGRES_VERSION}"
      --build-arg "RABBITMQ_VERSION=${RABBITMQ_VERSION}" --build-arg "GLITCHTIP_VERSION=${GLITCHTIP_VERSION}" --build-arg "PHP_VERSION=${PHP_VERSION}"
      --build-arg "FRANKENPHP_VERSION=${FRANKENPHP_VERSION}" --build-arg "NODE_VERSION=${NODE_VERSION}")
    [ -n "${4:-}" ] && args+=(--target "$4")
    if docker build "${args[@]}" "${SRC}/$2" >/dev/null; then
      echo -e "  ${GRN}✓${NC} build $1"; PASS=$((PASS+1))
    else
      echo -e "  ${RED}✗${NC} build $1"; FAIL=$((FAIL+1))
    fi
  }
  build elasticsearch infra/elasticsearch infra/elasticsearch/Dockerfile
  build postgres      infra/postgres      infra/postgres/Dockerfile
  build rabbitmq      infra/rabbitmq      infra/rabbitmq/Dockerfile
  build glitchtip     infra/glitchtip     infra/glitchtip/Dockerfile
  build catalog       .                   infra/php/catalog.Dockerfile prod
  build search        .                   infra/php/search.Dockerfile  prod
fi

C="$(img catalog)"; S="$(img search)"; E="$(img elasticsearch)"; P="$(img postgres)"; R="$(img rabbitmq)"; G="$(img glitchtip)"

echo -e "\n${BLD}  (b) Zero sekretów w obrazach aplikacji${NC}"
check "catalog: brak /app/.env"            "nie" "$(has "$C" /app/.env)"
check "catalog: brak /app/node_modules"    "nie" "$(has "$C" /app/node_modules)"
check "search: brak .env.local"            "nie" "$(has "$S" /app/.env.local)"
check "search: brak /app/var/cache/dev"    "nie" "$(has "$S" /app/var/cache/dev)"
check "rabbitmq: brak gotowego definitions.json (hash hasła)" "nie" "$(has "$R" /etc/rabbitmq/definitions.json)"
check "glitchtip: brak plików .env w obrazie"       "nie" "$(has "$G" /opt/marketplace/.env)"

echo -e "\n${BLD}  (a) Konfiguracja w obrazie, nie w bind-mountach${NC}"
check "catalog: tests/relevance/queries.yaml"      "tak" "$(has "$C" /tests/relevance/queries.yaml)"
check "catalog: Faker (seed w prod)"               "tak" "$(has "$C" /app/vendor/fakerphp/faker)"
check "search: mapowanie products-v1.json"         "tak" "$(has "$S" /infra/elasticsearch/mappings/products-v1.json)"
check "elasticsearch: analysis/synonyms.txt"       "tak" "$(has "$E" /usr/share/elasticsearch/config/analysis/synonyms.txt)"
check "elasticsearch: init-storage.sh"             "tak" "$(has "$E" /usr/local/bin/init-storage.sh)"
check "elasticsearch: setup-security.sh"           "tak" "$(has "$E" /usr/local/bin/setup-security.sh)"
check "postgres: init/01-databases.sh"             "tak" "$(has "$P" /docker-entrypoint-initdb.d/01-databases.sh)"
check "rabbitmq: conf.d/10-marketplace.conf"       "tak" "$(has "$R" /etc/rabbitmq/conf.d/10-marketplace.conf)"
check "glitchtip: healthcheck.py"                    "tak" "$(has "$G" /opt/marketplace/healthcheck.py)"
check "glitchtip: guard.py"                          "tak" "$(has "$G" /opt/marketplace/guard.py)"
check "glitchtip: bootstrap.py"                      "tak" "$(has "$G" /opt/marketplace/bootstrap.py)"
check "glitchtip: setup_db.py"                       "tak" "$(has "$G" /opt/marketplace/setup_db.py)"

echo -e "\n${BLD}  (c) Konfiguracja czytana w runtime${NC}"
APP_KEY_TEST="base64:$(openssl rand -base64 32)"
DB_DEFAULT=$(docker run --rm -e DB_CONNECTION=pgsql -e APP_KEY="${APP_KEY_TEST}" "$C" \
  sh -c 'php artisan config:cache >/dev/null && php -r "\$c = require \"bootstrap/cache/config.php\"; echo \$c[\"database\"][\"default\"];"' 2>/dev/null)
check "catalog: DB_CONNECTION z env po config:cache" "pgsql" "${DB_DEFAULT}"

# GlitchTip: strażnik w obrazie odmawia wartości deweloperskich z repo także dla localhosta
# w trybie produkcyjnym (GLITCHTIP_STRICT_SECRETS), a przepuszcza własne.
GUARD_DEV=$(docker run --rm --entrypoint python -e GLITCHTIP_DOMAIN=http://localhost:28000 -e GLITCHTIP_STRICT_SECRETS=true \
  -e GLITCHTIP_API_TOKEN=7d1f3a9c5e2b48d6a0c4f8e1b3d5a7c9e2f4b6d8a0c1e3f5a7b9d1c3e5f70912 "$G" /opt/marketplace/guard.py >/dev/null 2>&1; echo $?)
GUARD_OWN=$(docker run --rm --entrypoint python -e GLITCHTIP_DOMAIN=http://localhost:28000 -e GLITCHTIP_STRICT_SECRETS=true \
  -e GLITCHTIP_API_TOKEN="$(openssl rand -hex 32)" -e GLITCHTIP_ADMIN_EMAIL=admin@example.com \
  -e GLITCHTIP_ADMIN_PASSWORD="$(openssl rand -hex 24)" -e SECRET_KEY="$(openssl rand -hex 32)" \
  -e GLITCHTIP_KEY_CATALOG="$(openssl rand -hex 16)" -e GLITCHTIP_KEY_FRONTEND="$(openssl rand -hex 16)" \
  -e GLITCHTIP_KEY_SEARCH="$(openssl rand -hex 16)" -e DATABASE_URL="postgresql://u:$(openssl rand -hex 12)@postgres:5432/db" \
  "$G" /opt/marketplace/guard.py >/dev/null 2>&1; echo $?)
check "glitchtip: guard.py odmawia tokenu deweloperskiego (STRICT)" "1" "${GUARD_DEV}"
check "glitchtip: guard.py przepuszcza własne wartości (STRICT)"    "0" "${GUARD_OWN}"
# Puste sekrety (stary .env na VPS) to odmowa, nie „brak problemu” (compose.prod.yaml nie używa `:?`).
GUARD_EMPTY=$(docker run --rm --entrypoint python -e GLITCHTIP_DOMAIN=http://localhost:28000 -e GLITCHTIP_STRICT_SECRETS=true \
  "$G" /opt/marketplace/guard.py >/dev/null 2>&1; echo $?)
SETUPDB_EMPTY=$(docker run --rm --entrypoint python -e PGUSER=u -e PGPASSWORD=p -e GLITCHTIP_DB=d -e GLITCHTIP_DB_USER=u \
  -e GLITCHTIP_DB_PASSWORD= "$G" /opt/marketplace/setup_db.py >/dev/null 2>&1; echo $?)
check "glitchtip: guard.py odmawia pustych sekretów (STRICT)"       "1" "${GUARD_EMPTY}"
check "glitchtip: setup_db.py odmawia pustego hasła bazy"           "1" "${SETUPDB_EMPTY}"
HC_RC=$(docker run --rm --entrypoint python "$G" /opt/marketplace/healthcheck.py >/dev/null 2>&1; echo $?)
check "glitchtip: healthcheck.py da się uruchomić (1 = brak serwera)" "1" "${HC_RC}"

SEARCH_RC=$(docker run --rm -e APP_ENV=prod -e APP_SECRET=x -e MAILER_DSN=null://null \
  -e DEFAULT_URI=http://localhost -e DATABASE_URL='postgresql://u:p@localhost:5432/db?serverVersion=18.4.0' \
  -e MESSENGER_TRANSPORT_DSN=amqp://u:p@localhost:5672/%2f -e ELASTICSEARCH_HOST=http://localhost:9200 \
  -e ELASTICSEARCH_USER=u -e ELASTICSEARCH_PASSWORD=p -e CATALOG_INTERNAL_BASE_URL=http://localhost \
  "$S" php bin/console about >/dev/null 2>&1; echo $?)
check "search: bin/console działa bez pliku .env z repo" "0" "${SEARCH_RC}"

# RabbitMQ: start z hasłem z env → hash liczony przy starcie, logowanie działa.
RMQ_PASS="$(openssl rand -hex 12)"
CID=$(docker run -d -e RABBITMQ_USER=checker -e RABBITMQ_PASSWORD="${RMQ_PASS}" "$R")
# Czekamy na log, NIE na `rabbitmq-diagnostics` w pętli: wywołanie CLI w trakcie
# bootu node'a potrafiło położyć kontener (sprawdzone — RUNBOOK ETAPU D).
for _ in $(seq 1 60); do
  docker logs "$CID" 2>&1 | grep -q "Server startup complete" && break; sleep 2
done
AUTH_RC=$(docker exec "$CID" rabbitmqctl authenticate_user checker "${RMQ_PASS}" >/dev/null 2>&1; echo $?)
QUEUE=$(docker exec "$CID" rabbitmqctl list_queues name -q 2>/dev/null | grep -c '^search.product.sync$')
docker rm -f "$CID" >/dev/null
check "rabbitmq: logowanie hasłem z env"         "0" "${AUTH_RC}"
check "rabbitmq: kolejka search.product.sync"    "1" "${QUEUE}"

echo -e "\n  PASS=${PASS} FAIL=${FAIL}\n"
[ "${FAIL}" -eq 0 ]
