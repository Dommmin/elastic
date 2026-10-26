#!/usr/bin/env bash
# ============================================================================
#  Test setup_db.py z OBRAZU GlitchTipa na żywym Postgresie (ETAP 11b).
#
#  setup_db.py zakłada rolę i bazę `glitchtip` idempotentnie (skrypty z
#  docker-entrypoint-initdb.d działają tylko na świeżym wolumenie). Sprawdzamy
#  na TYMCZASOWEJ bazie, że: pierwszy przebieg tworzy, drugi nic nie psuje i
#  zmienia hasło, rola loguje się swoim hasłem i jest właścicielem bazy oraz
#  schematu public. Nie dotyka prawdziwej bazy `glitchtip`.
#  Wymaga: działający Postgres stacku (make up) i zbudowany obraz GlitchTipa.
# ============================================================================
set -uo pipefail
cd "$(dirname "$0")/.."
[ -f .env ] && { set -a; . ./.env; set +a; }

PROJECT="${COMPOSE_PROJECT_NAME:-marketplace}"
IMAGE="${GLITCHTIP_IMAGE:-marketplace/glitchtip:${GLITCHTIP_VERSION:-6.2.6}}"
NETWORK="${PROJECT}_marketplace"
SUFFIX="$(date +%s)"
DB="gt_check_${SUFFIX}"
USER_NAME="gt_check_${SUFFIX}"
PASS1="pass1_$(openssl rand -hex 6)"
PASS2="pass2_$(openssl rand -hex 6)"
FAIL=0

ok()   { echo "  ✓ $*"; }
fail() { echo "  ✗ $*"; FAIL=1; }
psql_su() { docker exec -e PGPASSWORD="${POSTGRES_PASSWORD}" "${PROJECT}-postgres-1" psql -U "${POSTGRES_USER}" -d "${1:-postgres}" -tAc "$2"; }
cleanup() {
  psql_su postgres "DROP DATABASE IF EXISTS \"${DB}\" WITH (FORCE)" >/dev/null 2>&1
  psql_su postgres "DROP ROLE IF EXISTS \"${USER_NAME}\"" >/dev/null 2>&1
}
trap cleanup EXIT

run_setup() {  # $1 = hasło roli
  docker run --rm --network "${NETWORK}" --entrypoint python \
    -e PGHOST=postgres -e PGUSER="${POSTGRES_USER}" -e PGPASSWORD="${POSTGRES_PASSWORD}" \
    -e GLITCHTIP_DB="${DB}" -e GLITCHTIP_DB_USER="${USER_NAME}" -e GLITCHTIP_DB_PASSWORD="$1" \
    "${IMAGE}" /opt/marketplace/setup_db.py >/dev/null 2>&1
}

echo "setup_db.py (${IMAGE})"
docker image inspect "${IMAGE}" >/dev/null 2>&1 || { echo "Brak obrazu ${IMAGE} (docker compose --profile errors build glitchtip)"; exit 2; }

run_setup "${PASS1}" && ok "pierwszy przebieg kończy się sukcesem" || fail "pierwszy przebieg padł"
[ "$(psql_su postgres "select count(*) from pg_database where datname='${DB}'")" = "1" ] && ok "baza istnieje" || fail "baza nie powstała"
[ "$(psql_su postgres "select pg_catalog.pg_get_userbyid(datdba) from pg_database where datname='${DB}'")" = "${USER_NAME}" ] && ok "rola jest właścicielem bazy" || fail "właściciel bazy to nie ${USER_NAME}"
[ "$(psql_su "${DB}" "select nspowner::regrole from pg_namespace where nspname='public'")" = "${USER_NAME}" ] && ok "rola jest właścicielem schematu public" || fail "schemat public ma innego właściciela"

run_setup "${PASS2}" && ok "drugi przebieg (idempotencja) kończy się sukcesem" || fail "drugi przebieg padł"
login() { docker run --rm --network "${NETWORK}" --entrypoint python -e P="$1" "${IMAGE}" -c "
import os, psycopg
psycopg.connect(host='postgres', dbname='${DB}', user='${USER_NAME}', password=os.environ['P']).execute('select 1')" >/dev/null 2>&1; }
login "${PASS2}" && ok "rola loguje się nowym hasłem" || fail "nowe hasło nie działa"
login "${PASS1}" && fail "stare hasło nadal działa" || ok "stare hasło przestało działać"

# Puste hasło roli (np. brak GLITCHTIP_DB_PASSWORD w .env na VPS) to odmowa, nie rola bez hasła.
docker run --rm --network "${NETWORK}" --entrypoint python \
  -e PGHOST=postgres -e PGUSER="${POSTGRES_USER}" -e PGPASSWORD="${POSTGRES_PASSWORD}" \
  -e GLITCHTIP_DB="${DB}_empty" -e GLITCHTIP_DB_USER="${USER_NAME}_empty" -e GLITCHTIP_DB_PASSWORD="" \
  "${IMAGE}" /opt/marketplace/setup_db.py >/dev/null 2>&1
[ "$?" = "1" ] && ok "puste hasło → odmowa (exit 1)" || fail "puste hasło nie zostało odrzucone"
[ "$(psql_su postgres "select count(*) from pg_roles where rolname='${USER_NAME}_empty'")" = "0" ] && ok "rola z pustym hasłem nie powstała" || fail "rola z pustym hasłem powstała"
psql_su postgres "DROP ROLE IF EXISTS \"${USER_NAME}_empty\"" >/dev/null 2>&1

[ "${FAIL}" -eq 0 ] && echo "OK: setup_db.py" || { echo "BŁĄD: setup_db.py"; exit 1; }
