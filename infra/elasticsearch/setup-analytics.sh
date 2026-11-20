#!/usr/bin/env bash
# ============================================================================
#  Analityka operacyjna (ETAP 12) — uruchamiane Z HOSTA przez `make analytics-setup`
#
#  Skrypt jest PODZIELONY NA SEKCJE i idempotentny (PUT nadpisuje definicję,
#  data stream tworzony tylko gdy go brak). Kolejne zadania ETAPU 12 dopisują
#  własne sekcje na końcu.
#
#  SEKCJA 1 (zdarzenia): polityka ILM `events-user`, pipeline `events-ingested`
#  (event.ingested = czas przyjęcia), szablony data streamów, data streamy
#  `events-user-default` i `events-impression-default`.
#
#  Replik: 1 w klastrze wielowęzłowym, 0 przy jednym węźle (inaczej indeks
#  zostałby żółty) — liczone z _cat/nodes.
#
#  Dane dostępowe: ELASTIC_PASSWORD czytane w kontenerze es01; skrypt niczego
#  nie wypisuje.
# ============================================================================
set -euo pipefail

DC="${DC:-docker compose}"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/analytics"

log() { printf '[analytics] %s\n' "$*"; }
die() { printf '[analytics] BŁĄD: %s\n' "$*" >&2; exit 1; }

# esa METODA ŚCIEŻKA [PLIK_JSON] — wywołanie ES jako `elastic` z wnętrza es01;
# treść idzie przez stdin (bez kłopotów z cudzysłowami)
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

# --- liczba replik zależnie od liczby węzłów ---------------------------------
NODES="$(esa GET '/_cat/nodes?h=name' | grep -c . || true)"
REPLICAS=1
[ "${NODES}" -le 1 ] && REPLICAS=0
log "Węzłów: ${NODES} → replik: ${REPLICAS}"

# template_with_replicas PLIK — JSON szablonu z podstawioną liczbą replik
template_with_replicas() {
  REPLICAS="${REPLICAS}" python3 -c '
import json, os, sys
t = json.load(open(sys.argv[1]))
t["template"]["settings"]["index.number_of_replicas"] = int(os.environ["REPLICAS"])
print(json.dumps(t))' "$1"
}

# ============================================================================
#  SEKCJA 1 — zdarzenia użytkownika i wyświetlenia (data streamy)
# ============================================================================
log "[1] ILM events-user (hot: rollover 1d/1gb, delete po 90d)"
esa PUT /_ilm/policy/events-user "${DIR}/ilm-events-user.json" >/dev/null

log "[1] Pipeline events-ingested (event.ingested)"
esa PUT /_ingest/pipeline/events-ingested "${DIR}/pipeline-events-ingested.json" >/dev/null

TMP="$(mktemp)"; trap 'rm -f "${TMP}"' EXIT
for name in events-user events-impression; do
  log "[1] Szablon ${name} (dynamic: strict)"
  template_with_replicas "${DIR}/template-${name}.json" >"${TMP}"
  esa PUT "/_index_template/${name}" "${TMP}" >/dev/null

  ds="${name}-default"
  if esa_exists "/_data_stream/${ds}"; then
    log "[1] Data stream ${ds} już istnieje"
  else
    log "[1] Tworzę data stream ${ds}"
    esa PUT "/_data_stream/${ds}" >/dev/null
  fi
done
log "[1] OK"

log "Gotowe."
