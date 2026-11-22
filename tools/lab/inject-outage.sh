#!/usr/bin/env bash
# ============================================================================
#  inject-outage.sh [od] [godziny] — symuluje awarię: kasuje user.clicked_result z okna czasowego (ETAP 14b, przewodnik 14b, krok 12)
#  Działa WYŁĄCZNIE na kopii `lab-events-user` na węźle es-lab (LAB_URL z lib.sh); główny klaster jest nietykalny:
#  skrypt odmawia, gdy docelowy host to nie host lab, albo gdy pod tym adresem istnieje indeks events-user-default.
#  Domyślnie: okno 6 h od 2026-11-10T09:00:00Z. Idempotentne: drugi przebieg kasuje 0 dokumentów.
#  Po awarii uruchom `make lab-anomaly`; do przywrócenia danych: bash tools/lab/anomaly.sh --reload
# ============================================================================
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 1
# shellcheck source=tools/lab/lib.sh
source tools/lab/lib.sh

command -v jq >/dev/null || die "brak jq"
FROM="${1:-${OUTAGE_FROM:-2026-11-10T09:00:00Z}}"
HOURS="${2:-${OUTAGE_HOURS:-6}}"
[[ "${HOURS}" =~ ^[0-9]+$ ]] && [ "${HOURS}" -gt 0 ] || die "godziny muszą być dodatnią liczbą całkowitą"
[[ "${FROM}" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$ ]] || die "początek okna w formacie 2026-11-10T09:00:00Z"

TARGET="${INJECT_ES_URL:-${LAB_URL}}"
[ "${TARGET}" = "${LAB_URL}" ] || die "odmawiam: ${TARGET} to nie węzeł lab (${LAB_URL}); skrypt nigdy nie dotyka głównego klastra"
case "${TARGET}" in *:19200|*:19200/*) die "odmawiam: port 19200 to główny klaster";; esac
[ "$(lab_es -o /dev/null -w '%{http_code}' -I "${TARGET}/events-user-default")" = 404 ] \
  || die "odmawiam: pod ${TARGET} istnieje events-user-default (to nie jest węzeł lab)"
[ "$(lab_es -o /dev/null -w '%{http_code}' -I "${TARGET}/lab-events-user")" = 200 ] \
  || die "brak indeksu lab-events-user (najpierw make lab-anomaly)"

step "Awaria: kasuję user.clicked_result w ${FROM} + ${HOURS} h (tylko lab-events-user na ${TARGET})"
body="$(jq -nc --arg f "${FROM}" --arg h "${HOURS}" '{query: {bool: {filter: [
  {term: {"event.type": "user.clicked_result"}},
  {range: {"@timestamp": {gte: $f, lt: ($f + "||+" + $h + "h")}}}]}}}')"
res="$(lab_es -X POST "${TARGET}/lab-events-user/_delete_by_query?refresh=true&conflicts=proceed" \
  -H 'Content-Type: application/json' -d "${body}")"
jq -e '.deleted != null' <<<"${res}" >/dev/null || die "delete_by_query nie powiodło się: ${res}"
echo "  skasowano: $(jq -r '.deleted' <<<"${res}") (konflikty wersji: $(jq -r '.version_conflicts' <<<"${res}"))"
