#!/usr/bin/env bash
# ============================================================================
#  anomaly.sh [--reload] [--no-assert] — wykrywanie anomalii ML na zdarzeniach user.* (ETAP 14b, zadanie 9)
#  1. ładuje events.ndjson do lab-events-user (stałe _id z SHA-1 linii); gdy indeks już istnieje, NIE ładuje ponownie
#     (żeby nie wskrzesić dokumentów skasowanych przez inject-outage.sh); --reload kasuje indeks i ładuje od nowa
#  2. odtwarza job lab-user-activity i datafeed, uruchamia datafeed na całym zakresie danych, czeka na koniec
#  3. wypisuje top-5 rekordów anomalii (czas, typ, record_score, actual vs typical)
#  4. ASERCJA: rekord z record_score >= 50 dla user.clicked_result w oknie awarii (kod wyjścia 1, gdy brak);
#     --no-assert pomija ją (przebieg przed awarią: tylko lista rekordów)
#  5. porównanie z regułą progową ETAPU 12 (ctr-drop), liczoną tak, jakby "teraz" były kolejne godziny okna awarii
#  Okno awarii: OUTAGE_FROM / OUTAGE_HOURS (te same domyślne co w inject-outage.sh).
# ============================================================================
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 1
# shellcheck source=tools/lab/lib.sh
source tools/lab/lib.sh

command -v jq >/dev/null || die "brak jq"
command -v python3 >/dev/null || die "brak python3 (hasze _id do bulk)"
RELOAD=0; ASSERT=1
for a in "$@"; do
  case "${a}" in
    --reload) RELOAD=1;;
    --no-assert) ASSERT=0;;
    *) die "użycie: anomaly.sh [--reload] [--no-assert]";;
  esac
done

NDJSON="${LAB_EVENTS_FILE:-apps/catalog/storage/app/private/lab/events.ndjson}"
IDX="lab-events-user"
JOB="lab-user-activity"
OUTAGE_FROM="${OUTAGE_FROM:-2026-11-10T09:00:00Z}"
OUTAGE_HOURS="${OUTAGE_HOURS:-6}"
JSON_H='Content-Type: application/json'

# --- 1. dane -----------------------------------------------------------------
exists="$(lab_es -o /dev/null -w '%{http_code}' -I "${LAB_URL}/${IDX}")"
if [ "${RELOAD}" = 1 ] && [ "${exists}" = 200 ]; then
  step "--reload: kasuję ${IDX}"
  lab_es -X DELETE "${LAB_URL}/${IDX}" >/dev/null
  exists=404
fi
if [ "${exists}" != 200 ]; then
  [ -f "${NDJSON}" ] || die "brak ${NDJSON} (make lab-mode-off && make lab-export-events && make lab-mode-on && make lab-up)"
  step "Indeks ${IDX} i ładowanie ${NDJSON}"
  lab_es -X PUT "${LAB_URL}/${IDX}" -H "${JSON_H}" -d '{
    "settings": {"number_of_shards": 1, "number_of_replicas": 0},
    "mappings": {"properties": {
      "@timestamp": {"type": "date"},
      "event": {"properties": {"type": {"type": "keyword"}, "id": {"type": "keyword"}}},
      "user": {"properties": {"id": {"type": "keyword"}}},
      "search": {"properties": {"query": {"type": "keyword"}}}}}}' | jq -e '.acknowledged' >/dev/null
  work="$(mktemp -d)"
  trap 'rm -rf "${work}"' EXIT
  # bulk: linia akcji ze stałym _id (SHA-1 treści linii) + dokument; porcje po 10000 dokumentów
  tail -n +2 "${NDJSON}" | python3 -I -c '
import sys, hashlib, json
for line in sys.stdin:
    line = line.rstrip("\n")
    if not line:
        continue
    sys.stdout.write(json.dumps({"index": {"_id": hashlib.sha1(line.encode()).hexdigest()}}) + "\n" + line + "\n")
' | split -l 20000 - "${work}/bulk-"
  total=0
  for f in "${work}"/bulk-*; do
    r="$(lab_es -X POST "${LAB_URL}/${IDX}/_bulk" -H 'Content-Type: application/x-ndjson' --data-binary "@${f}")"
    [ "$(jq -r '.errors' <<<"${r}")" = false ] || die "bulk z błędami: $(jq -c '[.items[].index.error | select(. != null)][0]' <<<"${r}")"
    total=$((total + $(jq '.items | length' <<<"${r}")))
    printf '  załadowano %d\r' "${total}"
  done
  echo
  lab_es -X POST "${LAB_URL}/${IDX}/_refresh" >/dev/null
else
  step "Indeks ${IDX} istnieje: pomijam ładowanie (użyj --reload, żeby przywrócić dane sprzed awarii)"
fi
docs="$(lab_es "${LAB_URL}/${IDX}/_count" | jq -r '.count')"
clicks="$(lab_es "${LAB_URL}/${IDX}/_count" -H "${JSON_H}" -d '{"query":{"term":{"event.type":"user.clicked_result"}}}' | jq -r '.count')"
echo "  dokumentów: ${docs} (w tym user.clicked_result: ${clicks})"

# --- 2. job + datafeed ---------------------------------------------------------
step "Job ${JOB} i datafeed (odtwarzam od zera)"
if [ "$(lab_es -o /dev/null -w '%{http_code}' "${LAB_URL}/_ml/anomaly_detectors/${JOB}")" = 200 ]; then
  lab_es -X POST "${LAB_URL}/_ml/datafeeds/datafeed-${JOB}/_stop?force=true" >/dev/null || true
  lab_es -X DELETE "${LAB_URL}/_ml/datafeeds/datafeed-${JOB}?force=true" >/dev/null || true
  lab_es -X POST "${LAB_URL}/_ml/anomaly_detectors/${JOB}/_close?force=true" >/dev/null || true
  lab_es -X DELETE "${LAB_URL}/_ml/anomaly_detectors/${JOB}?force=true&wait_for_completion=true" >/dev/null
fi
lab_es -X PUT "${LAB_URL}/_ml/anomaly_detectors/${JOB}" -H "${JSON_H}" \
  --data-binary @infra/elasticsearch/lab/anomaly-job.json | jq -e '.job_id' >/dev/null || die "nie udało się utworzyć joba"
lab_es -X PUT "${LAB_URL}/_ml/datafeeds/datafeed-${JOB}" -H "${JSON_H}" \
  --data-binary @infra/elasticsearch/lab/anomaly-datafeed.json | jq -e '.datafeed_id' >/dev/null || die "nie udało się utworzyć datafeedu"
# znana luka w danych: 2026-10-21 00:00 → 2026-10-26 00:00 przez pięć dób user.added_to_cart i user.checked_out miały ~3 zdarzenia/h zamiast ~78/19
# (inny ruch generatora, nie awaria). Bez kalendarza te doby dostają record_score 90–99 i zjadają skalę normalizacji,
# przez co prawdziwe zero kliknięć (p ≈ 1e-6) dostaje tylko ~40. Zdarzenie kalendarza: pomiń wynik i aktualizację modelu.
step "Kalendarz lab-known-gaps (znana luka 2026-10-21 → 2026-10-26, skip_result + skip_model_update)"
lab_es -X DELETE "${LAB_URL}/_ml/calendars/lab-known-gaps" >/dev/null || true
lab_es -X PUT "${LAB_URL}/_ml/calendars/lab-known-gaps" -H "${JSON_H}" -d "$(jq -nc --arg j "${JOB}" '{job_ids: [$j], description: "znane luki w danych (nie awarie)"}')" \
  | jq -e '.calendar_id' >/dev/null || die "nie udało się utworzyć kalendarza"
lab_es -X POST "${LAB_URL}/_ml/calendars/lab-known-gaps/events" -H "${JSON_H}" -d '{"events": [{
  "description": "niski ruch generatora cart/checkout",
  "start_time": 1792540800000, "end_time": 1792972800000, "skip_result": true, "skip_model_update": true}]}' \
  | jq -e '.events | length == 1' >/dev/null || die "nie udało się dodać zdarzenia kalendarza"

open="$(lab_es -X POST "${LAB_URL}/_ml/anomaly_detectors/${JOB}/_open")"
jq -e '.opened == true' <<<"${open}" >/dev/null \
  || die "job się nie otworzył: ${open} (brak pamięci ML? zatrzymaj ELSER: POST _ml/trained_models/.elser_model_2/deployment/_stop; make lab-setup-elser uruchomi go ponownie)"

range="$(lab_es "${LAB_URL}/${IDX}/_search" -H "${JSON_H}" -d '{"size":0,"aggs":{"mn":{"min":{"field":"@timestamp"}},"mx":{"max":{"field":"@timestamp"}}}}')"
t_from="$(jq -r '.aggregations.mn.value_as_string' <<<"${range}")"
t_to_ms="$(jq -r '.aggregations.mx.value | floor + 3600000' <<<"${range}")"
echo "  zakres danych: ${t_from} → $(jq -nr --argjson ms "${t_to_ms}" '$ms/1000 | todate') (+1 h zapasu)"

step "Datafeed na całym zakresie"
lab_es -X POST "${LAB_URL}/_ml/datafeeds/datafeed-${JOB}/_start" -H "${JSON_H}" \
  -d "$(jq -nc --arg s "${t_from}" --argjson e "${t_to_ms}" '{start: $s, end: ($e | tostring)}')" | jq -e '.started == true' >/dev/null \
  || die "datafeed się nie uruchomił"
state=""
for _ in $(seq 1 180); do
  state="$(lab_es "${LAB_URL}/_ml/datafeeds/datafeed-${JOB}/_stats" | jq -r '.datafeeds[0].state')"
  [ "${state}" = stopped ] && break
  sleep 2
done
[ "${state}" = stopped ] || die "datafeed nie zakończył się w 360 s (stan: ${state})"
lab_es -X POST "${LAB_URL}/_ml/anomaly_detectors/${JOB}/_close" >/dev/null
lab_es "${LAB_URL}/_ml/anomaly_detectors/${JOB}/_stats" \
  | jq -r '.jobs[0] | "  przetworzone rekordy: \(.data_counts.processed_record_count), kubełki: \(.data_counts.bucket_count), pamięć modelu: \(.model_size_stats.model_bytes) B (\(.model_size_stats.memory_status))"'

# --- 3. top-5 --------------------------------------------------------------------
step "Top-5 rekordów anomalii (wg record_score)"
records="$(lab_es -X GET "${LAB_URL}/_ml/anomaly_detectors/${JOB}/results/records" -H "${JSON_H}" \
  -d '{"sort":"record_score","desc":true,"record_score":0,"page":{"from":0,"size":1000}}')"
jq -r '.records[:5][] | [(.timestamp/1000 | todate), .partition_field_value, (.record_score | .*10 | round / 10), (.actual[0]|tostring), (.typical[0] | .*10 | round / 10 | tostring)]
  | @tsv' <<<"${records}" | { printf '  %-22s %-22s %-6s %-7s %s\n' czas typ score actual typical; while IFS=$'\t' read -r t ty sc ac tp; do printf '  %-22s %-22s %-6s %-7s %s\n' "$t" "$ty" "$sc" "$ac" "$tp"; done; }
echo "  rekordów razem: $(jq '.count' <<<"${records}"), z record_score >= 50: $(jq '[.records[] | select(.record_score >= 50)] | length' <<<"${records}")"

# --- 4. asercja --------------------------------------------------------------------
w_from_ms="$(jq -nr --arg f "${OUTAGE_FROM}" '$f | fromdateiso8601 * 1000')"
w_to_ms=$((w_from_ms + OUTAGE_HOURS * 3600000))
hits="$(jq --argjson a "${w_from_ms}" --argjson b "${w_to_ms}" \
  '[.records[] | select(.record_score >= 50 and .partition_field_value == "user.clicked_result" and .timestamp >= $a and .timestamp < $b)]' <<<"${records}")"
n_hits="$(jq 'length' <<<"${hits}")"
if [ "${ASSERT}" = 1 ]; then
  step "Asercja: user.clicked_result w oknie awarii (${OUTAGE_FROM} + ${OUTAGE_HOURS} h), record_score >= 50"
  if [ "${n_hits}" -eq 0 ]; then
    echo "  BRAK takiego rekordu: awaria NIE została wykryta" >&2
    exit 1
  fi
  jq -r '.[] | "  OK: \(.timestamp/1000 | todate)  score \(.record_score | .*10 | round / 10)  actual \(.actual[0])  typical \(.typical[0] | .*10 | round / 10)"' <<<"${hits}"
else
  step "Asercja pominięta (--no-assert); rekordów ≥ 50 dla clicked_result w oknie: ${n_hits}"
fi

# --- 5. reguła progowa ETAPU 12 ------------------------------------------------------
step "Porównanie: reguła ctr-drop (ETAP 12) liczona na ${IDX}"
echo "  zapytanie z infra/kibana/rules/ctr-drop.json: godzina recent kontra 7 dni baseline; warunek recent_searches >= 30 AND recent_ctr < 0.5 * baseline_ctr"
echo "  (NOW() reguły zastąpione kolejnymi pełnymi godzinami okna awarii)"
fired=0
for ((h = 1; h <= OUTAGE_HOURS; h++)); do
  esql="$(jq -r '.params.esqlQuery.esql' infra/kibana/rules/ctr-drop.json)"
  now_iso="$(jq -nr --argjson ms $((w_from_ms + h * 3600000)) '$ms/1000 | todate')"
  esql="${esql//FROM events-user-\*/FROM ${IDX}}"
  esql="${esql//NOW() - 60 minutes/TO_DATETIME(\"${now_iso}\") - 60 minutes}"
  esql="${esql/| WHERE event.type IN/| WHERE @timestamp >= TO_DATETIME(\"${now_iso}\") - 7 days AND @timestamp < TO_DATETIME(\"${now_iso}\") AND event.type IN}"
  r="$(lab_es -X POST "${LAB_URL}/_query" -H "${JSON_H}" -d "$(jq -nc --arg q "${esql}" '{query: $q}')")"
  jq -e '.values' <<<"${r}" >/dev/null || die "ES|QL nie zadziałało: ${r}"
  # osobno surowe liczby godziny (żeby widać było, dlaczego reguła milczy lub strzela)
  raw="$(lab_es -X POST "${LAB_URL}/_query" -H "${JSON_H}" -d "$(jq -nc --arg now "${now_iso}" --arg i "${IDX}" '{query: ("FROM " + $i + " | WHERE @timestamp >= TO_DATETIME(\"" + $now + "\") - 60 minutes AND @timestamp < TO_DATETIME(\"" + $now + "\") AND event.type IN (\"user.searched\", \"user.clicked_result\") | STATS n = COUNT(*) BY event.type")}')")"
  counts="$(jq -r '[.values[] | "\(.[1])=\(.[0])"] | join(", ")' <<<"${raw}")"
  if [ "$(jq '.values | length' <<<"${r}")" -gt 0 ]; then verdict="ALARM"; fired=$((fired + 1)); else verdict="cisza"; fi
  printf '  %s: %-5s  (godzina przed: %s)\n' "${now_iso}" "${verdict}" "${counts:-brak zdarzeń}"
done
echo "  reguła progowa zaalarmowała w ${fired} z ${OUTAGE_HOURS} godzin okna; ML: ${n_hits} rekordów ≥ 50 dla user.clicked_result w oknie"
