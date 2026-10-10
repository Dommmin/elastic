#!/usr/bin/env bash
# ============================================================================
#  pg-backup.sh — dump obu baz do /var/backups/marketplace (ETAP D, Task 10)
#  Uruchamiany przez marketplace-pg-backup.timer (codziennie 03:30).
#
#  -Fc (custom format): skompresowany, odtwarzany przez pg_restore —
#  pozwala odtworzyć pojedynczą tabelę, w odróżnieniu od zwykłego SQL.
#  Zapis do .tmp + mv: przerwany dump nigdy nie udaje kompletnego.
# ============================================================================
set -euo pipefail
cd /opt/marketplace
DEST=/var/backups/marketplace
STAMP=$(date +%F-%H%M)
set -a; . ./.env; set +a

for db in "${CATALOG_DB}" "${SEARCHSVC_DB}"; do
  docker compose exec -T postgres pg_dump -U "${POSTGRES_USER}" -Fc "${db}" </dev/null > "${DEST}/${db}-${STAMP}.dump.tmp"
  mv "${DEST}/${db}-${STAMP}.dump.tmp" "${DEST}/${db}-${STAMP}.dump"
done

# Retencja 7 dni. Kopia poza serwerem: `make vps-backup-pull` na Macu.
find "${DEST}" -name '*.dump' -mtime +7 -delete
echo "pg-backup: OK ${STAMP}"
