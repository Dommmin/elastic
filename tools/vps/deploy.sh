#!/usr/bin/env bash
# ============================================================================
#  deploy.sh <tag> — wdrożenie wersji na VPS (ETAP D, Task 8)
#
#    make prod-deploy tag=<SHA commita>        # nowa wersja
#    make prod-deploy tag=<starszy SHA>        # rollback — ta sama komenda
#
#  Na serwer trafiają TYLKO: compose.yaml, compose.prod.yaml, szablon .env
#  i generator sekretów. Kod jest w obrazach (GHCR), sekrety w .env, który
#  powstaje na serwerze raz i nigdy nie jest nadpisywany.
#
#  Kroki po stronie serwera:
#    1. .env (tylko pierwszy raz) + IMAGE_TAG=<tag>
#    2. docker compose pull         — obrazy danej wersji
#    3. docker compose up -d --wait — podmiana kontenerów, czekanie na healthy
#    4. migracje (idempotentne)      — catalog (Laravel) i search (Doctrine)
#    5. indeks products-v1 + alias  — tylko jeśli aliasu jeszcze nie ma
#    6. docker image prune          — stare wersje nie zapychają dysku
# ============================================================================
set -euo pipefail

TAG="${1:?użycie: deploy.sh <tag (SHA commita)>}"
HOST="${VPS_HOST:-elastic-vps}"
DIR=/opt/marketplace
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

[[ "${TAG}" =~ ^[0-9a-f]{40}$|^main$ ]] || { echo "Tag ma być pełnym SHA (40 znaków hex) albo 'main'." >&2; exit 1; }

echo "[deploy] pliki -> ${HOST}:${DIR}"
scp -q "${ROOT}/compose.yaml" "${ROOT}/compose.prod.yaml" "${ROOT}/.env.prod.example" \
    "${ROOT}/tools/vps/gen-env.sh" "${HOST}:${DIR}/"

# shellcheck disable=SC2087  # rozwinięcie ${TAG} po stronie Maca jest celowe
ssh "${HOST}" bash -s <<REMOTE
set -euo pipefail
cd "${DIR}"
chmod +x gen-env.sh
[ -f .env ] || ./gen-env.sh .env.prod.example .env
sed -i -E 's/^IMAGE_TAG=.*/IMAGE_TAG=${TAG}/' .env

echo "[deploy] pull ${TAG:0:12}"
docker compose pull --quiet
# Najpierw wszystko POZA outbox-publisherem: publisher czyta tabelę outbox,
# więc nowa wersja może ruszyć dopiero po migracjach. Przy pierwszym
# wdrożeniu tabeli nie ma wcale — publisher padałby, a `up --wait`
# kończyłby się błędem, zanim migracje by się wykonały (wyszło w próbie
# generalnej). Ta sama zasada co w każdym deployu: schemat przed kodem.
echo "[deploy] up --wait (bez outbox-publisher)"
docker compose up -d --wait --remove-orphans catalog-app search-consumer kibana

echo "[deploy] migracje"
docker compose exec -T catalog-app php artisan migrate --force --no-interaction
docker compose exec -T search-consumer php bin/console doctrine:migrations:migrate -n --allow-no-migration

echo "[deploy] up --wait (całość)"
docker compose up -d --wait --remove-orphans

set -a; . ./.env; set +a
if ! curl -fsS -o /dev/null -u "elastic:\${ELASTIC_PASSWORD}" "http://localhost:\${ES_PORT}/_alias/products-search"; then
  echo "[deploy] pierwszy start: indeks products-v1 + alias"
  docker compose exec -T search-consumer php bin/console search:index:create
fi

docker image prune -f >/dev/null
echo "[deploy] OK — działa wersja ${TAG:0:12}"
docker compose ps --format 'table {{.Service}}\t{{.Image}}\t{{.Status}}'
REMOTE
