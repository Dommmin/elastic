#!/bin/sh
# ============================================================================
#  Baza i użytkownik GlitchTipa — IDEMPOTENTNIE.
#
#  Dlaczego osobny skrypt, skoro jest infra/postgres/init/01-databases.sh:
#  skrypty z docker-entrypoint-initdb.d działają tylko przy PIERWSZEJ
#  inicjalizacji wolumenu, a pg-data już istnieje. Ten krok można uruchamiać
#  wielokrotnie (make up-errors) na dowolnym wolumenie.
#  01-databases.sh tworzy tę samą bazę na świeżym wolumenie.
# ============================================================================
set -eu

: "${GLITCHTIP_DB:?}" "${GLITCHTIP_DB_USER:?}" "${GLITCHTIP_DB_PASSWORD:?}"

echo "[glitchtip-db] Rola '${GLITCHTIP_DB_USER}' i baza '${GLITCHTIP_DB}'..."

psql -v ON_ERROR_STOP=1 -d postgres <<SQL
DO \$\$
BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '${GLITCHTIP_DB_USER}') THEN
    CREATE ROLE ${GLITCHTIP_DB_USER} LOGIN PASSWORD '${GLITCHTIP_DB_PASSWORD}';
  ELSE
    ALTER ROLE ${GLITCHTIP_DB_USER} PASSWORD '${GLITCHTIP_DB_PASSWORD}';
  END IF;
END
\$\$;
SELECT 'CREATE DATABASE ${GLITCHTIP_DB} OWNER ${GLITCHTIP_DB_USER}'
 WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '${GLITCHTIP_DB}')\gexec
GRANT ALL PRIVILEGES ON DATABASE ${GLITCHTIP_DB} TO ${GLITCHTIP_DB_USER};
SQL

# Postgres >= 15: uprawnienia do schematu public nie są domyślne.
psql -v ON_ERROR_STOP=1 -d "${GLITCHTIP_DB}" <<SQL
GRANT ALL ON SCHEMA public TO ${GLITCHTIP_DB_USER};
ALTER SCHEMA public OWNER TO ${GLITCHTIP_DB_USER};
SQL

echo "[glitchtip-db] Gotowe."
