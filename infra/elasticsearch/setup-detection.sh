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
#  SEKCJA 3 (Kibana): konektor index → alerts-security, reguła ES|QL review-ip-burst, data views
#  `alerts-security` i `events-security-*`. Wymaga działającej Kibany.
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
#  SEKCJA 3 — Kibana: konektor index → alerts-security, reguła review-ip-burst, data views
# ============================================================================
# Ta sama reguła (plik infra/kibana/rules/security-review-ip-burst.json) jest źródłem tekstu ES|QL
# dla backfillu `security:detect` — Kibana liczy live, PHP ten sam tekst nad historią.
KIBANA_URL="${KIBANA_URL:-http://kibana:5601}"
RULES_DIR="$(cd "${DIR}/../../kibana" && pwd)/rules"

# kba METODA ŚCIEŻKA [PLIK_JSON] — wywołanie API Kibany jako `elastic` z es01
kba() {
  local method="$1" path="$2" file="${3:-}"
  if [ -n "${file}" ]; then
    ${DC} exec -T es01 sh -c \
      'curl -sS --fail-with-body -u "elastic:$ELASTIC_PASSWORD" -H "kbn-xsrf: setup-detection" -H "Content-Type: application/json" -X "$0" "$1$2" --data-binary @-' \
      "${method}" "${KIBANA_URL}" "${path}" <"${file}"
  else
    ${DC} exec -T es01 sh -c \
      'curl -sS --fail-with-body -u "elastic:$ELASTIC_PASSWORD" -H "kbn-xsrf: setup-detection" -X "$0" "$1$2"' \
      "${method}" "${KIBANA_URL}" "${path}"
  fi
}

# kba_code ŚCIEŻKA — kod HTTP GET-a do Kibany
kba_code() {
  ${DC} exec -T es01 sh -c \
    'curl -s -o /dev/null -w "%{http_code}" -u "elastic:$ELASTIC_PASSWORD" "$0$1"' "${KIBANA_URL}" "$1"
}

log "[3] Czekam na Kibanę (${KIBANA_URL})"
ready=""
for _ in $(seq 1 60); do
  [ "$(${DC} exec -T es01 sh -c 'curl -s -o /dev/null -w "%{http_code}" -u "elastic:$ELASTIC_PASSWORD" "$0/api/status"' "${KIBANA_URL}" || true)" = "200" ] && { ready=1; break; }
  sleep 5
done
[ -n "${ready}" ] || die "Kibana nie odpowiada pod ${KIBANA_URL} (docker compose up -d kibana)"

# connector ID PLIK — tworzy albo aktualizuje connector o stałym ID
connector() {
  local id="$1" file="$2"
  if [ "$(kba_code "/api/actions/connector/${id}")" = "200" ]; then
    log "[3] Connector ${id}: aktualizuję"
    python3 -c '
import json, sys
c = json.load(open(sys.argv[1]))
c.pop("connector_type_id", None)  # typu nie da się zmienić
print(json.dumps(c))' "${file}" >"${TMP}"
    kba PUT "/api/actions/connector/${id}" "${TMP}" >/dev/null
  else
    log "[3] Connector ${id}: tworzę"
    kba POST "/api/actions/connector/${id}" "${file}" >/dev/null
  fi
}
connector security-alerts-index "${RULES_DIR}/connector-security-index.json"

# rule PLIK — idempotentnie po nazwie: jest → PUT, brak → POST
rule() {
  local file="$1" name rid
  name="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["name"])' "${file}")"
  rid="$(kba GET "/api/alerting/rules/_find?per_page=100" | python3 -c '
import json, sys
name = sys.argv[1]
ids = [r["id"] for r in json.load(sys.stdin)["data"] if r["name"] == name]
print(ids[0] if ids else "")' "${name}")"
  if [ -n "${rid}" ]; then
    log "[3] Reguła „${name}”: aktualizuję (${rid})"
    python3 -c '
import json, sys
r = json.load(open(sys.argv[1]))
body = {k: r[k] for k in ("name", "tags", "schedule", "params", "actions", "notify_when")}
print(json.dumps(body))' "${file}" >"${TMP}"
    kba PUT "/api/alerting/rule/${rid}" "${TMP}" >/dev/null
  else
    log "[3] Reguła „${name}”: tworzę"
    kba POST "/api/alerting/rule" "${file}" >/dev/null
  fi
}
rule "${RULES_DIR}/security-review-ip-burst.json"

# data_view ID NAZWA WZORZEC — idempotentnie po stałym id (istnieje → nic)
data_view() {
  local id="$1" title="$2" pattern="$3"
  if [ "$(kba_code "/api/data_views/data_view/${id}")" = "200" ]; then
    log "[3] Data view ${id} już istnieje"
  else
    log "[3] Tworzę data view ${id} (${pattern})"
    python3 -c '
import json, sys
print(json.dumps({"data_view": {"id": sys.argv[1], "name": sys.argv[2], "title": sys.argv[3], "timeFieldName": "@timestamp"}}))' \
      "${id}" "${title}" "${pattern}" >"${TMP}"
    kba POST "/api/data_views/data_view" "${TMP}" >/dev/null
  fi
}
data_view alerts-security "Alerty bezpieczeństwa" "alerts-security"
data_view events-security "Zdarzenia bezpieczeństwa" "events-security-*"
log "[3] OK — reguły: http://localhost:${KIBANA_PORT:-5601}/app/management/insightsAndAlerting/triggersActions/rules"
log "OK"
