#!/usr/bin/env bash
# ============================================================================
#  Detekcja nadużyć (ETAP 15) — uruchamiane Z HOSTA przez `make detection-setup`
#
#  Skrypt jest PODZIELONY NA SEKCJE i idempotentny (PUT nadpisuje definicje,
#  zasoby stanowe — data stream, indeksy — tworzone tylko gdy ich brak).
#  Nie mylić z setup-security.sh (FLS/DLS z ETAPU 13) — to osobny skrypt.
#
#  SEKCJA 1 (ES): polityka ILM `events-security` (delete po 30 dniach — krótka
#  retencja = mniej danych osobowych), pipeline `events-security`
#  (event.ingested + enrich product.popularity; błąd wzbogacenia nie odrzuca
#  zdarzenia), szablon, data stream `events-security-default`, indeksy
#  `security-rules` (percolator) i `alerts-security`.
#  Wymaga polityki enrich `product-popularity-policy` z `make analytics-setup`.
#
#  SEKCJA 2 (reguły percolatora): `security:rules:load` w kontenerze search-consumer (progi p95 z product-popularity).
#  SEKCJA 3 (Kibana: reguła ES|QL, data views): PLACEHOLDER — wypełnia Task 6.
#
#  Replik: 1 w klastrze wielowęzłowym, 0 przy jednym węźle (liczone z _cat/nodes).
#  Dane dostępowe: ELASTIC_PASSWORD czytane w kontenerze es01; skrypt niczego
#  nie wypisuje.
# ============================================================================
set -euo pipefail

DC="${DC:-docker compose}"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/detection"

log() { printf '[detection] %s\n' "$*"; }
die() { printf '[detection] BŁĄD: %s\n' "$*" >&2; exit 1; }

# esa METODA ŚCIEŻKA [PLIK_JSON] — wywołanie ES jako `elastic` z wnętrza es01
esa() {
  local method="$1" path="$2" file="${3:-}"
  if [ -n "${file}" ]; then
    ${DC} exec -T es01 sh -c \
      'curl -sS --fail-with-body -u "elastic:$ELASTIC_PASSWORD" -H "Content-Type: application/json" -X "$0" "http://localhost:9200$1" --data-binary @-' \
      "${method}" "${path}" <"${file}"
  else
    ${DC} exec -T es01 sh -c \
      'curl -sS --fail-with-body -u "elastic:$ELASTIC_PASSWORD" -X "$0" "http://localhost:9200$1"' \
      "${method}" "${path}"
  fi
}

# esa_exists ŚCIEŻKA — 0, gdy zasób istnieje (HTTP 200)
esa_exists() {
  local code
  code="$(${DC} exec -T es01 sh -c \
    'curl -s -o /dev/null -w "%{http_code}" -u "elastic:$ELASTIC_PASSWORD" "http://localhost:9200$0"' "$1")"
  [ "${code}" = "200" ]
}

NODES="$(esa GET '/_cat/nodes?h=name' | grep -c . || true)"
REPLICAS=1
[ "${NODES}" -le 1 ] && REPLICAS=0
log "Węzłów: ${NODES} → replik: ${REPLICAS}"

TMP="$(mktemp)"; trap 'rm -f "${TMP}"' EXIT

# ============================================================================
#  SEKCJA 1 — data stream events-security, security-rules, alerts-security
# ============================================================================
# Pipeline z procesorem enrich nie powstanie bez wykonanej polityki — jasny błąd zamiast 400.
POLICIES="$(esa GET '/_enrich/policy/product-popularity-policy' \
  | python3 -c 'import json,sys; print(len(json.load(sys.stdin).get("policies", [])))')"
[ "${POLICIES}" -ge 1 ] || die "brak polityki enrich product-popularity-policy — najpierw make analytics-setup"

log "[1] ILM events-security (hot: rollover 1d/1gb, delete po 30d)"
esa PUT /_ilm/policy/events-security "${DIR}/ilm-events-security.json" >/dev/null

log "[1] Pipeline events-security (event.ingested, product.popularity)"
esa PUT /_ingest/pipeline/events-security "${DIR}/pipeline-events-security.json" >/dev/null

log "[1] Szablon events-security (dynamic: strict)"
REPLICAS="${REPLICAS}" python3 -c '
import json, os, sys
t = json.load(open(sys.argv[1]))
t["template"]["settings"]["index.number_of_replicas"] = int(os.environ["REPLICAS"])
print(json.dumps(t))' "${DIR}/template-events-security.json" >"${TMP}"
esa PUT /_index_template/events-security "${TMP}" >/dev/null

ds=events-security-default
if esa_exists "/_data_stream/${ds}"; then
  log "[1] Data stream ${ds} już istnieje"
else
  log "[1] Tworzę data stream ${ds}"
  esa PUT "/_data_stream/${ds}" >/dev/null
fi
# Backing indeksy istniejącego streamu doprowadzamy do mapowania z pliku (jak w analityce).
python3 -c 'import json,sys; print(json.dumps(json.load(open(sys.argv[1]))["template"]["mappings"]))' \
  "${DIR}/template-events-security.json" >"${TMP}"
esa PUT "/${ds}/_mapping" "${TMP}" >/dev/null

for idx in security-rules alerts-security; do
  if esa_exists "/${idx}"; then
    log "[1] Indeks ${idx} już istnieje"
  else
    log "[1] Tworzę indeks ${idx} (replik: ${REPLICAS})"
    REPLICAS="${REPLICAS}" python3 -c '
import json, os, sys
t = json.load(open(sys.argv[1]))
t["settings"]["index.number_of_replicas"] = int(os.environ["REPLICAS"])
print(json.dumps(t))' "${DIR}/index-${idx}.json" >"${TMP}"
    esa PUT "/${idx}" "${TMP}" >/dev/null
  fi
done
log "[1] OK"

# ============================================================================
#  SEKCJA 2 — reguły percolatora (pliki detection/rules → security-rules)
# ============================================================================
# Komenda działa w search-consumer (montuje detection/ i ma klienta ES); liczy percentyl 95
# popularności z product-popularity, więc po zmianie popularności warto ją uruchomić ponownie.
log "[2] Ładuję reguły percolatora (security:rules:load)"
${DC} exec -T search-consumer php bin/console security:rules:load | sed 's/^/[detection]   /'
log "[2] OK"

# ============================================================================
#  SEKCJA 3 — Kibana: reguła ES|QL, data views (PLACEHOLDER, Task 6)
# ============================================================================
log "[3] Kibana: pominięta (dopisuje Task 6)"
log "OK"
