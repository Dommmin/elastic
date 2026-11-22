#!/usr/bin/env bash
# ============================================================================
#  export-events.sh — eksport zdarzeń user.* z GŁÓWNEGO klastra do NDJSON (ETAP 14b, zadanie 9)
#  Tylko odczyt: _pit + search_after po (@timestamp, _shard_doc), porcje po 5000.
#  Wynik: apps/catalog/storage/app/private/lab/events.ndjson (katalog w .gitignore);
#  pierwsza linia to nagłówek {"_lab_events": {"count": N, "from": …, "to": …}}.
#  Wymaga działającego głównego klastra (make lab-mode-off); hasło elastic z .env, nie jest wypisywane.
#  Pola źródła (@timestamp, event.type, user.id, search.query) mają te same nazwy co w mapowaniu lab;
#  dodatkowo event.id (keyword), żeby hash linii (stałe _id przy imporcie) odróżniał zdarzenia z tej samej sekundy.
# ============================================================================
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 1

step() { printf '\n━━ %s\n' "$*"; }
die()  { printf 'BŁĄD: %s\n' "$*" >&2; exit 1; }
command -v jq >/dev/null || die "brak jq"
[ -f .env ] || die "brak .env"

MAIN_URL="${MAIN_ES_URL:-http://localhost:19200}"
INDEX="events-user-default"
OUT="${LAB_EVENTS_FILE:-apps/catalog/storage/app/private/lab/events.ndjson}"
CHUNK=5000
PW="$(grep -E '^ELASTIC_PASSWORD=' .env | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' || true)"
[ -n "${PW}" ] || die "brak ELASTIC_PASSWORD w .env"
case "${PW}" in *\"*|*\\*) die "ELASTIC_PASSWORD zawiera cudzysłów lub ukośnik: nieobsługiwane w konfiguracji curl -K";; esac

main_es() { curl -sS -f -K <(printf 'user = "elastic:%s"\n' "${PW}") "$@"; }

mkdir -p "$(dirname "${OUT}")"
BODY="$(mktemp)"
PIT=""
cleanup() {
  if [ -n "${PIT}" ]; then
    main_es -X DELETE "${MAIN_URL}/_pit" -H 'Content-Type: application/json' \
      -d "$(jq -nc --arg id "${PIT}" '{id: $id}')" >/dev/null 2>&1 || true
  fi
  rm -f "${BODY}"
}
trap cleanup EXIT

step "Eksport ${INDEX} z ${MAIN_URL} (tylko odczyt)"
started=$(date +%s)
PIT="$(main_es -X POST "${MAIN_URL}/${INDEX}/_pit?keep_alive=5m" | jq -r '.id')"
[ -n "${PIT}" ] && [ "${PIT}" != null ] || die "nie udało się otworzyć PIT"

after='null'
count=0
while :; do
  req="$(jq -nc --arg pit "${PIT}" --argjson size "${CHUNK}" --argjson after "${after}" '
    {size: $size, pit: {id: $pit, keep_alive: "5m"},
     sort: [{"@timestamp": "asc"}, {"_shard_doc": "asc"}],
     _source: ["@timestamp", "event.type", "event.id", "user.id", "search.query"],
     track_total_hits: false}
    + (if $after == null then {} else {search_after: $after} end)')"
  resp="$(main_es -X POST "${MAIN_URL}/_search" -H 'Content-Type: application/json' -d "${req}")"
  n="$(jq '.hits.hits | length' <<<"${resp}")"
  [ "${n}" -gt 0 ] || break
  jq -c '.hits.hits[]._source' <<<"${resp}" >> "${BODY}"
  count=$((count + n))
  after="$(jq -c '.hits.hits[-1].sort' <<<"${resp}")"
  PIT="$(jq -r '.pit_id // empty' <<<"${resp}")"
  printf '  %d dokumentów\r' "${count}"
done
echo

[ "${count}" -gt 0 ] || die "indeks ${INDEX} nie zwrócił dokumentów"
from="$(head -1 "${BODY}" | jq -r '."@timestamp"')"
to="$(tail -1 "${BODY}" | jq -r '."@timestamp"')"
{ jq -nc --argjson c "${count}" --arg f "${from}" --arg t "${to}" '{_lab_events: {count: $c, from: $f, to: $t}}'; cat "${BODY}"; } > "${OUT}"
echo "  zapisano ${count} zdarzeń (${from} → ${to}) do ${OUT} w $(( $(date +%s) - started )) s"
