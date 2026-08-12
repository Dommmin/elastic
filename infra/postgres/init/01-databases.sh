#!/usr/bin/env bash
# ============================================================================
#  Tworzenie dwóch niezależnych baz — po jednej na serwis.
#
#  DLACZEGO DWIE, a nie jedna wspólna:
#  "Shared database" to antywzorzec w architekturze serwisowej. Gdy dwa serwisy
#  czytają sobie nawzajem tabele, kontraktem między nimi staje się SCHEMAT BAZY
#  — i nie da się go już zmienić bez skoordynowanego deployu obu.
#  Kontraktem mają być ZDARZENIA (docs/02-APLIKACJE.md), nie tabele.
#
#  Jeden KONTENER z dwiema bazami to kompromis pod lokalny RAM. W produkcji
#  byłyby to dwie osobne instancje. Nazywamy to wprost, żeby nie udawać.
#
#  Skrypt uruchamia się TYLKO przy pierwszej inicjalizacji wolumenu.
#  Jeśli zmienisz go później: make nuke && make up
# ============================================================================
set -euo pipefail

create_db() {
  local db="$1" user="$2" pass="$3"
  echo "[init] Tworzę bazę '${db}' i użytkownika '${user}'..."
  psql -v ON_ERROR_STOP=1 --username "${POSTGRES_USER}" --dbname postgres <<-SQL
    CREATE USER ${user} WITH PASSWORD '${pass}';
    CREATE DATABASE ${db} OWNER ${user};
    GRANT ALL PRIVILEGES ON DATABASE ${db} TO ${user};
SQL
  # Od Postgresa 15 uprawnienia do schematu public nie są domyślne —
  # klasyczna pułapka przy migracji ze starszych wersji.
  psql -v ON_ERROR_STOP=1 --username "${POSTGRES_USER}" --dbname "${db}" <<-SQL
    GRANT ALL ON SCHEMA public TO ${user};
    ALTER SCHEMA public OWNER TO ${user};
SQL
}

create_db "${CATALOG_DB}"   "${CATALOG_DB_USER}"   "${CATALOG_DB_PASSWORD}"
create_db "${SEARCHSVC_DB}" "${SEARCHSVC_DB_USER}" "${SEARCHSVC_DB_PASSWORD}"

echo "[init] Gotowe. Bazy: ${CATALOG_DB}, ${SEARCHSVC_DB}"
