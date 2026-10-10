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
    "${ROOT}/tools/vps/gen-env.sh" "${ROOT}/infra/systemd/pg-backup.sh" \
    "${ROOT}/infra/systemd/marketplace-pg-backup.service" \
    "${ROOT}/infra/systemd/marketplace-pg-backup.timer" "${HOST}:${DIR}/"

# Heredoc CYTOWANY (<<'REMOTE'): nic w środku nie jest rozwijane na Macu.
# Wcześniej był niecytowany i lokalny bash wykonywał backticki z KOMENTARZY
# (np. `bash -s`) na Macu — wdrożenie wisiało (RUNBOOK #035). Zmienne idą
# jawnie przez środowisko zdalnego basha.
ssh "${HOST}" "TAG='${TAG}' DIR='${DIR}' bash -s" <<'REMOTE'
set -euo pipefail
cd "${DIR}"
chmod +x gen-env.sh
[ -f .env ] || ./gen-env.sh .env.prod.example .env
sed -i -E "s/^IMAGE_TAG=.*/IMAGE_TAG=${TAG}/" .env

echo "[deploy] pull ${TAG:0:12}"
docker compose pull --quiet </dev/null
# Najpierw wszystko POZA outbox-publisherem: publisher czyta tabelę outbox,
# więc nowa wersja może ruszyć dopiero po migracjach. Przy pierwszym
# wdrożeniu tabeli nie ma wcale — publisher padałby, a `up --wait`
# kończyłby się błędem, zanim migracje by się wykonały (wyszło w próbie
# generalnej). Ta sama zasada co w każdym deployu: schemat przed kodem.
echo "[deploy] up --wait (bez outbox-publisher)"
docker compose up -d --wait --remove-orphans catalog-app search-consumer kibana </dev/null

echo "[deploy] migracje"
# </dev/null przy KAŻDYM exec: ten skrypt przychodzi do `bash -s` przez stdin,
# a `docker compose exec -T` czyta stdin — bez tego połykał resztę skryptu
# i bash kończył się po cichu po pierwszej migracji (RUNBOOK #035).
docker compose exec -T catalog-app php artisan migrate --force --no-interaction </dev/null
docker compose exec -T search-consumer php bin/console doctrine:migrations:migrate -n --allow-no-migration </dev/null

echo "[deploy] up --wait (całość)"
docker compose up -d --wait --remove-orphans </dev/null

set -a; . ./.env; set +a
if ! curl -fs -o /dev/null -u "elastic:${ELASTIC_PASSWORD}" "http://localhost:${ES_PORT}/_alias/products-search"; then
  echo "[deploy] pierwszy start: indeks products-v1 + alias"
  docker compose exec -T search-consumer php bin/console search:index:create </dev/null
fi

# --- backupy: konfiguracja w repo, instalowana przy każdym wdrożeniu --------
# Wszystko idempotentne (PUT nadpisuje tym samym, install/enable bez zmian).
echo "[deploy] backup ES: repozytorium fs + polityka SLM nightly"
es_put() {
  curl -fs -o /dev/null -u "elastic:${ELASTIC_PASSWORD}" -X PUT \
    -H 'Content-Type: application/json' "http://localhost:${ES_PORT}$1" -d "$2"
}
# /snapshots = wolumen es-snapshots, wspólny dla 3 nodów (path.repo).
# Przy nodach na RÓŻNYCH maszynach to musiałby być NFS/S3 — każdy node
# pisze swoje shardy do tego samego repozytorium.
es_put /_snapshot/fs-backup '{"type":"fs","settings":{"location":"/snapshots","compress":true}}'
# Harmonogram SLM jest w UTC: 01:00 UTC = 03:00 czasu polskiego latem.
es_put /_slm/policy/nightly '{
  "schedule": "0 0 1 * * ?",
  "name": "<nightly-{now/d}>",
  "repository": "fs-backup",
  "config": { "indices": ["*"], "include_global_state": true },
  "retention": { "expire_after": "7d", "min_count": 1, "max_count": 7 }
}'

echo "[deploy] backup PG: timer systemd (03:30)"
chmod +x pg-backup.sh
sudo -n install -d -o deploy -g deploy -m 750 /var/backups/marketplace
sudo -n install -m 644 marketplace-pg-backup.service marketplace-pg-backup.timer /etc/systemd/system/
sudo -n systemctl daemon-reload
sudo -n systemctl enable --now marketplace-pg-backup.timer >/dev/null 2>&1

docker image prune -f >/dev/null
echo "[deploy] OK — działa wersja ${TAG:0:12}"
docker compose ps --format 'table {{.Service}}\t{{.Image}}\t{{.Status}}'
REMOTE
