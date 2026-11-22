#!/usr/bin/env bash
# ============================================================================
#  lab-up.sh — start węzła es-lab (ETAP 14, profil ml): kontrola pamięci, start, czekanie na zdrowie, licencja
# ============================================================================
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 1
# shellcheck source=tools/lab/lib.sh
source tools/lab/lib.sh

command -v docker >/dev/null || die "brak docker"
command -v jq >/dev/null || die "brak jq"

if [ -n "$(docker compose --profile ml ps --status running -q es-lab)" ]; then
  step "es-lab już działa: pomijam kontrolę pamięci"
else
  step "Kontrola pamięci (zapas minimum 3584 MB)"
  need_free_mb 3584
fi

step "Start es-lab"
docker compose --profile ml up -d es-lab

step "Czekanie na _cluster/health (do 180 s)"
ok=0
for _ in $(seq 1 90); do
  if [ "$(lab_es -o /dev/null -w '%{http_code}' "${LAB_URL}/_cluster/health?wait_for_status=yellow&timeout=1s" 2>/dev/null || true)" = 200 ]; then
    ok=1; break
  fi
  sleep 2
done
[ "${ok}" = 1 ] || die "es-lab nie osiągnął statusu yellow w 180 s (docker compose logs es-lab)"
lab_es "${LAB_URL}/_cluster/health" | jq -r '"  status: \(.status), węzły: \(.number_of_nodes)"'

step "Licencja"
# tuż po starcie licencja bywa jeszcze niedostępna (odpowiedź bez pola license)
for _ in $(seq 1 15); do
  [ "$(lab_es "${LAB_URL}/_license" | jq -r '.license.type // empty')" ] && break
  sleep 2
done
lab_es "${LAB_URL}/_license" | jq -r '"  typ: \(.license.type), status: \(.license.status)"'
