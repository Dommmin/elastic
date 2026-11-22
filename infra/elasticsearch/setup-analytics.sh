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
#  `events-user-default`, `events-impression-default` i (ETAP 13)
#  `events-seller-default` (wiersz na parę zdarzenie × sprzedawca).
#
#  SEKCJA 2 (search-stats-daily, seller-sales-daily): indeks docelowy z jawnym mapowaniem i transform
#  ciągły (sync po event.ingested, delay 60s). Tworzony, gdy go brak; startowany,
#  gdy stoi. Zmiana pivotu wymaga usunięcia transformu i indeksu (patrz przewodnik
#  docs/blog/etap-12a-zdarzenia-i-lejek.md, ściąga).
#
#  SEKCJA 3 (popularność, spec §7): pusty indeks `product-popularity` (gdy go
#  brak), polityka enrich `product-popularity-policy` (gdy jej brak), `_execute`
#  polityki (zawsze — tworzy/odświeża indeks enrich), pipeline
#  `products-enrich-popularity` (PUT nadpisuje). Kolejność jest wymuszona przez
#  ES: pipeline z procesorem enrich nie powstanie bez wykonanej polityki,
#  a products-v6 (default_pipeline) bez pipeline'u odrzuca KAŻDY zapis — więc
#  ta sekcja musi przejść przed `make reindex` na v6. Idzie PRZED sekcją
#  Kibany, żeby niedostępna Kibana (die w sekcji 4) nie blokowała pipeline'u.
#  Polityki enrich nie da się zmienić w miejscu ani usunąć, dopóki odwołuje się
#  do niej pipeline (409). Skrypt tylko ostrzega o rozjeździe z plikiem.
#  Przebudowa polityki (zapisy produktów w tym czasie przejdą bez popularności,
#  nadrobi je `search:popularity:refresh`):
#    1. PUT _ingest/pipeline/products-enrich-popularity {"processors":[]}
#    2. DELETE _enrich/policy/product-popularity-policy
#    3. make analytics-setup   (utworzy politykę z pliku, wykona ją, przywróci pipeline)
#    4. search:popularity:refresh (Task 9) — dociągnie popularność do produktów
#
#  SEKCJA 4 (Kibana): import data views i dashboardu „Wyszukiwarka — analityka”
#  z infra/kibana/analytics-dashboard.ndjson (overwrite=true). Kibana wołana
#  z wnętrza es01 po sieci compose (KIBANA_URL, domyślnie http://kibana:5601).
#
#  SEKCJA 5 (anomalie, spec §9): indeks `alerts-analytics` (jawne mapowanie),
#  connectory Kibany (Index → alerts-analytics, Server log) o stałych ID oraz
#  dwie reguły `.es-query` (ES|QL) z infra/kibana/rules/. Idempotencja po NAZWIE
#  reguły (_find, dokładne dopasowanie): reguła istnieje → PUT nadpisuje definicję
#  z pliku (reguły jako kod, plik jest źródłem prawdy; ręczne zmiany w Kibanie
#  zostaną nadpisane), brak → POST. Connectory: GET po stałym ID → PUT albo POST.
#  Uwagi: skok zero results zwykle wyzwala też regułę CTR (wyszukania bez
#  wyników nie mają kliknięć) — to korelacja, nie błąd. Bazy (7 dni) nie mają
#  minimalnego wolumenu: przy prawie pustej historii baza jest niewiarygodna
#  (minimum dotyczy tylko okna bieżącego). Trwała anomalia daje jeden dokument
#  na regułę (alert o stałym ID, notify_when=onActionGroupChange), nie co minutę.
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
for name in events-user events-impression events-seller; do
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

  # Szablon dotyczy tylko NOWYCH indeksów; backing indeksy istniejącego data streamu
  # (PUT _mapping na nazwie streamu obejmuje wszystkie) trzeba doprowadzić do mapowania
  # z pliku (np. `seller.id` w events-user, ETAP 13): przy `dynamic: strict` dokument z nowym
  # polem inaczej zostałby odrzucony. Idempotentne.
  python3 -c 'import json,sys; print(json.dumps(json.load(open(sys.argv[1]))["template"]["mappings"]))' \
    "${DIR}/template-${name}.json" >"${TMP}"
  esa PUT "/${ds}/_mapping" "${TMP}" >/dev/null
done
log "[1] OK"

# ============================================================================
#  SEKCJA 2 — transform search-stats-daily (dzień × fraza)
# ============================================================================
# ensure_transform NAZWA — indeks docelowy (gdy brak), transform (gdy brak), start (gdy stoi)
ensure_transform() {
  local transform="$1"
  if esa_exists "/${transform}"; then
    log "[2] Indeks ${transform} już istnieje"
  else
    log "[2] Tworzę indeks ${transform} (jawne mapowanie, replik: ${REPLICAS})"
    REPLICAS="${REPLICAS}" python3 -c '
import json, os, sys
t = json.load(open(sys.argv[1]))
t["settings"]["index.number_of_replicas"] = int(os.environ["REPLICAS"])
print(json.dumps(t))' "${DIR}/index-${transform}.json" >"${TMP}"
    esa PUT "/${transform}" "${TMP}" >/dev/null
  fi

  if esa_exists "/_transform/${transform}"; then
    log "[2] Transform ${transform} już istnieje (definicji nie nadpisuję)"
  else
    log "[2] Tworzę transform ${transform}"
    esa PUT "/_transform/${transform}" "${DIR}/transform-${transform}.json" >/dev/null
  fi

  local state
  state="$(esa GET "/_transform/${transform}/_stats?filter_path=transforms.state" \
    | python3 -c 'import json,sys; print(json.load(sys.stdin)["transforms"][0]["state"])')"
  if [ "${state}" = "stopped" ]; then
    log "[2] Startuję transform ${transform}"
    esa POST "/_transform/${transform}/_start" >/dev/null
  else
    log "[2] Transform ${transform} w stanie: ${state}"
  fi
}

ensure_transform search-stats-daily
# ETAP 13: sprzedaż per sprzedawca z events-seller-* (źródło sekcji `sales` w seller-360).
# Szablon events-seller powstaje w sekcji 1; transform czyta data stream, więc musi być po niej.
ensure_transform seller-sales-daily
log "[2] OK"

# ============================================================================
#  SEKCJA 3 — popularność: indeks product-popularity, polityka enrich, pipeline
#  PRZED Kibaną: awaria Kibany nie może zablokować pipeline'u, bez którego
#  products-v6 odrzuca każdy zapis.
# ============================================================================
POP_INDEX=product-popularity
POP_POLICY=product-popularity-policy
POP_PIPELINE=products-enrich-popularity

if esa_exists "/${POP_INDEX}"; then
  log "[3] Indeks ${POP_INDEX} już istnieje"
else
  log "[3] Tworzę pusty indeks ${POP_INDEX} (replik: ${REPLICAS})"
  REPLICAS="${REPLICAS}" python3 -c '
import json, os, sys
t = json.load(open(sys.argv[1]))
t["settings"]["number_of_replicas"] = int(os.environ["REPLICAS"])
print(json.dumps(t))' "${DIR}/index-${POP_INDEX}.json" >"${TMP}"
  esa PUT "/${POP_INDEX}" "${TMP}" >/dev/null
fi

# GET nieistniejącej polityki to 200 z pustą listą — liczymy definicje.
POLICY_JSON="$(esa GET "/_enrich/policy/${POP_POLICY}")"
if printf '%s' "${POLICY_JSON}" | python3 -c 'import json,sys; sys.exit(0 if json.load(sys.stdin)["policies"] else 1)'; then
  log "[3] Polityka ${POP_POLICY} już istnieje (polityk nie da się aktualizować w miejscu)"
  printf '%s' "${POLICY_JSON}" | python3 -c '
import json, sys
live = json.load(sys.stdin)["policies"][0]["config"]["match"]
want = json.load(open(sys.argv[1]))["match"]
keys = ("indices", "match_field", "enrich_fields")
norm = lambda m: {k: sorted(m[k]) if isinstance(m[k], list) else [m[k]] for k in keys}
if norm(live) != norm(want):
    print("[analytics] [3] UWAGA: polityka w klastrze różni się od pliku — przebudowa: nagłówek skryptu", file=sys.stderr)' \
    "${DIR}/enrich-policy-${POP_INDEX}.json"
else
  log "[3] Tworzę politykę enrich ${POP_POLICY}"
  esa PUT "/_enrich/policy/${POP_POLICY}" "${DIR}/enrich-policy-${POP_INDEX}.json" >/dev/null
fi

log "[3] _execute ${POP_POLICY} (indeks enrich z bieżącego ${POP_INDEX})"
esa POST "/_enrich/policy/${POP_POLICY}/_execute?wait_for_completion=true" >/dev/null

log "[3] Pipeline ${POP_PIPELINE} (on_failure: zapis produktu nigdy nie jest odrzucany)"
esa PUT "/_ingest/pipeline/${POP_PIPELINE}" "${DIR}/pipeline-${POP_PIPELINE}.json" >/dev/null
log "[3] OK"

# ============================================================================
#  SEKCJA 4 — Kibana: data views + dashboard (saved objects NDJSON)
# ============================================================================
KIBANA_URL="${KIBANA_URL:-http://kibana:5601}"
NDJSON="$(cd "${DIR}/../../kibana" && pwd)/analytics-dashboard.ndjson"

# kbn_status — kod HTTP /api/status Kibany (z es01, po sieci compose)
kbn_status() {
  ${DC} exec -T es01 sh -c \
    'curl -s -o /dev/null -w "%{http_code}" -u "elastic:$ELASTIC_PASSWORD" "$0/api/status"' "${KIBANA_URL}"
}

log "[4] Czekam na Kibanę (${KIBANA_URL})"
for _ in $(seq 1 30); do
  [ "$(kbn_status)" = "200" ] && break
  sleep 5
done
[ "$(kbn_status)" = "200" ] || die "Kibana nie odpowiada 200 na /api/status — uruchom stos (make up)"

log "[4] Import $(basename "${NDJSON}") (overwrite=true)"
RESULT="$(${DC} exec -T es01 sh -c \
  'curl -sS --fail-with-body -u "elastic:$ELASTIC_PASSWORD" -H "kbn-xsrf: setup-analytics" -X POST "$0/api/saved_objects/_import?overwrite=true" -F "file=@-;filename=analytics-dashboard.ndjson"' \
  "${KIBANA_URL}" <"${NDJSON}")"
printf '%s' "${RESULT}" | python3 -c '
import json, sys
r = json.load(sys.stdin)
if not r.get("success"):
    print("[analytics] BŁĄD importu:", json.dumps(r.get("errors"), ensure_ascii=False), file=sys.stderr)
    sys.exit(1)
print("[analytics] [4] Zaimportowano obiektów: %d" % r["successCount"])'
log "[4] OK — dashboard: http://localhost:${KIBANA_PORT:-5601}/app/dashboards#/view/search-analytics"

# ============================================================================
#  SEKCJA 5 — anomalie: alerts-analytics, connectory i reguły Kibana Alerting
# ============================================================================
RULES_DIR="$(cd "${DIR}/../../kibana" && pwd)/rules"
ALERTS_INDEX=alerts-analytics

# kba METODA ŚCIEŻKA [PLIK_JSON] — wywołanie API Kibany jako `elastic` z es01
kba() {
  local method="$1" path="$2" file="${3:-}"
  if [ -n "${file}" ]; then
    ${DC} exec -T es01 sh -c \
      'curl -sS --fail-with-body -u "elastic:$ELASTIC_PASSWORD" -H "kbn-xsrf: setup-analytics" -H "Content-Type: application/json" -X "$0" "$1$2" --data-binary @-' \
      "${method}" "${KIBANA_URL}" "${path}" <"${file}"
  else
    ${DC} exec -T es01 sh -c \
      'curl -sS --fail-with-body -u "elastic:$ELASTIC_PASSWORD" -H "kbn-xsrf: setup-analytics" -X "$0" "$1$2"' \
      "${method}" "${KIBANA_URL}" "${path}"
  fi
}

# kba_code ŚCIEŻKA — kod HTTP GET-a do Kibany
kba_code() {
  ${DC} exec -T es01 sh -c \
    'curl -s -o /dev/null -w "%{http_code}" -u "elastic:$ELASTIC_PASSWORD" "$0$1"' "${KIBANA_URL}" "$1"
}

if esa_exists "/${ALERTS_INDEX}"; then
  log "[5] Indeks ${ALERTS_INDEX} już istnieje"
else
  log "[5] Tworzę indeks ${ALERTS_INDEX} (replik: ${REPLICAS})"
  REPLICAS="${REPLICAS}" python3 -c '
import json, os, sys
t = json.load(open(sys.argv[1]))
t["settings"]["index.number_of_replicas"] = int(os.environ["REPLICAS"])
print(json.dumps(t))' "${DIR}/index-${ALERTS_INDEX}.json" >"${TMP}"
  esa PUT "/${ALERTS_INDEX}" "${TMP}" >/dev/null
fi

# connector ID PLIK — tworzy albo aktualizuje connector o stałym ID
connector() {
  local id="$1" file="$2"
  if [ "$(kba_code "/api/actions/connector/${id}")" = "200" ]; then
    log "[5] Connector ${id}: aktualizuję"
    python3 -c '
import json, sys
c = json.load(open(sys.argv[1]))
c.pop("connector_type_id", None)  # typu nie da się zmienić
print(json.dumps(c))' "${file}" >"${TMP}"
    kba PUT "/api/actions/connector/${id}" "${TMP}" >/dev/null
  else
    log "[5] Connector ${id}: tworzę"
    kba POST "/api/actions/connector/${id}" "${file}" >/dev/null
  fi
}
connector analytics-alerts-index "${RULES_DIR}/connector-index.json"
connector analytics-server-log "${RULES_DIR}/connector-server-log.json"

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
    log "[5] Reguła „${name}”: aktualizuję (${rid})"
    python3 -c '
import json, sys
r = json.load(open(sys.argv[1]))
body = {k: r[k] for k in ("name", "tags", "schedule", "params", "actions", "notify_when")}
print(json.dumps(body))' "${file}" >"${TMP}"
    kba PUT "/api/alerting/rule/${rid}" "${TMP}" >/dev/null
  else
    log "[5] Reguła „${name}”: tworzę"
    kba POST "/api/alerting/rule" "${file}" >/dev/null
  fi
}
rule "${RULES_DIR}/zero-results-spike.json"
rule "${RULES_DIR}/ctr-drop.json"
log "[5] OK — reguły: http://localhost:${KIBANA_PORT:-5601}/app/management/insightsAndAlerting/triggersActions/rules"

log "Gotowe."
