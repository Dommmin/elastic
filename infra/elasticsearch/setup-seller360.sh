#!/usr/bin/env bash
# ============================================================================
#  Read model seller-360 (ETAP 13) — uruchamiane Z HOSTA przez `make seller360-setup`
#
#  Idempotentne: PUT szablonów i skryptu nadpisuje definicje z plików, indeksy
#  z aliasami powstają tylko wtedy, gdy alias odczytu jeszcze nie istnieje
#  (po przebudowie na v2 alias wskazuje już inny indeks i skrypt go nie rusza).
#
#  Szablony: seller-360-v* (6 sekcji) i seller-360-public-v* (bez billing i complaints).
#  Skrypt zapisany: seller360-apply-section (strażnik wersji sekcji).
#  Reklamacje (Task 9): szablony complaints (default_pipeline) i complaints-unmatched (bez niego),
#  polityka enrich seller-by-nip (tworzona raz, _execute przy każdym uruchomieniu), pipeline
#  complaints-resolve-seller. Polityki nie da się zmienić w miejscu — przebudowa: nagłówek setup-analytics.sh.
#  Świeżość (Task 10): pola p99 w mapowaniu alerts-analytics (dynamic: strict), import dashboardu
#  infra/kibana/seller360-dashboard.ndjson (overwrite=true) i reguła infra/kibana/rules/seller360-lag.json
#  (idempotentnie po nazwie: jest → PUT, brak → POST). Wymaga wcześniejszego `make analytics-setup`
#  (indeks alerts-analytics i connectory Kibany o stałych ID).
#  Aliasy: seller-360 (odczyt) + seller-360-write (is_write_index) → seller-360-v1,
#          seller-360-public + seller-360-public-write → seller-360-public-v1.
#
#  Replik: 1 w klastrze wielowęzłowym, 0 przy jednym węźle — liczone z _cat/nodes.
#  Dane dostępowe: ELASTIC_PASSWORD czytane w kontenerze es01; skrypt niczego nie wypisuje.
# ============================================================================
set -euo pipefail

DC="${DC:-docker compose}"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/seller360"

log() { printf '[seller360] %s\n' "$*"; }

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

log "Skrypt seller360-apply-section"
esa PUT /_scripts/seller360-apply-section "${DIR}/script-apply-section.json" >/dev/null

# ensure_family NAZWA — szablon, potem indeks v1 z aliasami (gdy alias odczytu nie istnieje)
ensure_family() {
  local name="$1" file="$2"
  log "Szablon ${name}-v* (dynamic: strict)"
  REPLICAS="${REPLICAS}" python3 -c '
import json, os, sys
t = json.load(open(sys.argv[1]))
t["template"]["settings"]["index.number_of_replicas"] = int(os.environ["REPLICAS"])
print(json.dumps(t))' "${DIR}/${file}" >"${TMP}"
  esa PUT "/_index_template/${name}" "${TMP}" >/dev/null

  if esa_exists "/_alias/${name}"; then
    log "Alias ${name} już istnieje — indeksu nie tworzę"
  else
    log "Tworzę ${name}-v1 z aliasami ${name} i ${name}-write"
    printf '{"aliases":{"%s":{},"%s-write":{"is_write_index":true}}}' "${name}" "${name}" >"${TMP}"
    esa PUT "/${name}-v1" "${TMP}" >/dev/null
  fi
}

ensure_family seller-360 template-seller-360.json
ensure_family seller-360-public template-seller-360-public.json

# --- Reklamacje (Task 9) -----------------------------------------------------
# Kolejność jest wymuszona przez ES: pipeline z procesorem enrich powstaje dopiero po _execute polityki,
# a polityka potrzebuje istniejącego indeksu źródłowego (seller-360-v1 z góry).
POLICY=seller-by-nip
POLICY_JSON="$(esa GET "/_enrich/policy/${POLICY}")"
if printf '%s' "${POLICY_JSON}" | python3 -c 'import json,sys; sys.exit(0 if json.load(sys.stdin)["policies"] else 1)'; then
  log "Polityka ${POLICY} już istnieje (polityk nie da się aktualizować w miejscu)"
else
  log "Tworzę politykę enrich ${POLICY}"
  esa PUT "/_enrich/policy/${POLICY}" "${DIR}/enrich-policy-${POLICY}.json" >/dev/null
fi
log "_execute ${POLICY} (indeks enrich z bieżącego seller-360)"
esa POST "/_enrich/policy/${POLICY}/_execute?wait_for_completion=true" >/dev/null

log "Pipeline complaints-resolve-seller (enrich → seller_id/region, brak dopasowania → reroute)"
esa PUT /_ingest/pipeline/complaints-resolve-seller "${DIR}/pipeline-complaints-resolve-seller.json" >/dev/null

log "Szablony complaints (z default_pipeline) i complaints-unmatched (bez — inaczej pętla reroute)"
REPLICAS="${REPLICAS}" python3 -c '
import json, os, sys
t = json.load(open(sys.argv[1]))
t["template"]["settings"]["index.number_of_replicas"] = int(os.environ["REPLICAS"])
print(json.dumps(t))' "${DIR}/template-complaints.json" >"${TMP}"
esa PUT /_index_template/complaints "${TMP}" >/dev/null
REPLICAS="${REPLICAS}" python3 -c '
import json, os, sys
t = json.load(open(sys.argv[1]))
t["index_patterns"] = ["complaints-unmatched"]
t["template"]["settings"]["index.number_of_replicas"] = int(os.environ["REPLICAS"])
del t["template"]["settings"]["index.default_pipeline"]
print(json.dumps(t))' "${DIR}/template-complaints.json" >"${TMP}"
esa PUT /_index_template/complaints-unmatched "${TMP}" >/dev/null
for idx in complaints complaints-unmatched; do
  if esa_exists "/${idx}"; then
    log "Indeks ${idx} już istnieje"
  else
    log "Tworzę indeks ${idx}"
    esa PUT "/${idx}" >/dev/null
  fi
done

# --- Świeżość (Task 10): dashboard i reguła w Kibanie ------------------------
KIBANA_URL="${KIBANA_URL:-http://kibana:5601}"
KIBANA_DIR="$(cd "${DIR}/../../kibana" && pwd)"
die() { printf '[seller360] BŁĄD: %s\n' "$*" >&2; exit 1; }

kba() {
  local method="$1" path="$2" file="${3:-}"
  if [ -n "${file}" ]; then
    ${DC} exec -T es01 sh -c \
      'curl -sS --fail-with-body -u "elastic:$ELASTIC_PASSWORD" -H "kbn-xsrf: setup-seller360" -H "Content-Type: application/json" -X "$0" "$1$2" --data-binary @-' \
      "${method}" "${KIBANA_URL}" "${path}" <"${file}"
  else
    ${DC} exec -T es01 sh -c \
      'curl -sS --fail-with-body -u "elastic:$ELASTIC_PASSWORD" -H "kbn-xsrf: setup-seller360" -X "$0" "$1$2"' \
      "${method}" "${KIBANA_URL}" "${path}"
  fi
}

esa_exists "/alerts-analytics" || die "brak indeksu alerts-analytics — uruchom najpierw make analytics-setup"

log "Mapowanie alerts-analytics: pola p99 lagu (idempotentne)"
printf '{"properties":{"profile_p99":{"type":"double"},"billing_p99":{"type":"double"},"offers_p99":{"type":"double"}}}' >"${TMP}"
esa PUT /alerts-analytics/_mapping "${TMP}" >/dev/null

log "Import seller360-dashboard.ndjson (overwrite=true)"
RESULT="$(${DC} exec -T es01 sh -c \
  'curl -sS --fail-with-body -u "elastic:$ELASTIC_PASSWORD" -H "kbn-xsrf: setup-seller360" -X POST "$0/api/saved_objects/_import?overwrite=true" -F "file=@-;filename=seller360-dashboard.ndjson"' \
  "${KIBANA_URL}" <"${KIBANA_DIR}/seller360-dashboard.ndjson")"
printf '%s' "${RESULT}" | python3 -c '
import json, sys
r = json.load(sys.stdin)
if not r.get("success"):
    print("[seller360] BŁĄD importu:", json.dumps(r.get("errors"), ensure_ascii=False), file=sys.stderr)
    sys.exit(1)
print("[seller360] Zaimportowano obiektów: %d" % r["successCount"])'

RULE="${KIBANA_DIR}/rules/seller360-lag.json"
NAME="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["name"])' "${RULE}")"
RID="$(kba GET "/api/alerting/rules/_find?per_page=100" | python3 -c '
import json, sys
name = sys.argv[1]
ids = [r["id"] for r in json.load(sys.stdin)["data"] if r["name"] == name]
print(ids[0] if ids else "")' "${NAME}")"
if [ -n "${RID}" ]; then
  log "Reguła „${NAME}”: aktualizuję (${RID})"
  python3 -c '
import json, sys
r = json.load(open(sys.argv[1]))
print(json.dumps({k: r[k] for k in ("name", "tags", "schedule", "params", "actions", "notify_when")}))' "${RULE}" >"${TMP}"
  kba PUT "/api/alerting/rule/${RID}" "${TMP}" >/dev/null
else
  log "Reguła „${NAME}”: tworzę"
  kba POST "/api/alerting/rule" "${RULE}" >/dev/null
fi
log "Dashboard: http://localhost:${KIBANA_PORT:-5601}/app/dashboards#/view/seller360-freshness"

log "Gotowe."
