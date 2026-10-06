#!/usr/bin/env bash
# ============================================================================
#  make search-proof — DOWÓD z DoD ETAPU 7 (docs/06-PLAN-WDROZENIA.md):
#  "kliknięcie facetu NIE wywołuje niepotrzebnych agregacji w ES (dowód
#  w slowlogu)".
#
#  Odtwarza curlem DOKŁADNIE te żądania, które wysyła przeglądarka na stronie
#  /search (te same nagłówki Inertii co @inertiajs/vue3), i po każdym kroku
#  pokazuje, jakie zapytania trafiły do Elasticsearcha — ze slowloga z progiem
#  0ms, czyli KAŻDE zapytanie, nie tylko wolne.
#
#  Bez przeglądarki, bez zgadywania: jeśli kontroler kiedyś zacznie liczyć coś
#  na zapas, zobaczysz to tutaj jako dodatkową linię.
# ============================================================================
set -euo pipefail

BASE="https://${SERVER_NAME:-catalog.localhost}:${CATALOG_HTTPS_PORT}"
CURL=(curl -sSk --max-time 60)
JSON='python3 -c'

since() { date -u +%Y-%m-%dT%H:%M:%SZ; }

show_queries() {
  local from="$1"
  sleep 2   # slowlog ląduje w logach node'a asynchronicznie
  docker compose logs --no-log-prefix --since "$from" es01 es02 es03 2>/dev/null \
    | grep 'index_search_slowlog' \
    | grep '"user.name":"catalog"' \
    | python3 tools/slowlog-summary.py
  echo ""
}

inertia_get() {
  local url="$1"; shift
  "${CURL[@]}" "$BASE$url" -H 'X-Inertia: true' -H "X-Inertia-Version: $VERSION" \
    -H 'X-Requested-With: XMLHttpRequest' "$@"
}

echo "Włączam slowlog (próg 0ms) na products-search..."
make --no-print-directory es-slowlog-on >/dev/null

# --- 1. pierwsze wejście na stronę (zwykły GET, HTML) ------------------------
echo -e "\n━━ 1. Pierwsze wejście: GET /search?q=laptop ━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "   Oczekiwane: JEDNO zapytanie — wyniki + facety. Histogramu NIE ma (defer)."
T=$(since)
HTML=$("${CURL[@]}" "$BASE/search?q=laptop")
PAGE=$(echo "$HTML" | python3 -c '
import sys, re, html
m = re.search(r"<script data-page=\"app\" type=\"application/json\">(.*?)</script>", sys.stdin.read(), re.S)
print(m.group(1) if m else "{}")')
VERSION=$(echo "$PAGE" | $JSON 'import json,sys; print(json.load(sys.stdin)["version"])')
BRAND=$(echo "$PAGE" | $JSON 'import json,sys; print(json.load(sys.stdin)["props"]["facets"]["brand"][0]["key"])')
CURSOR=$(echo "$PAGE" | $JSON 'import json,sys; print(json.load(sys.stdin)["scrollProps"]["results"]["nextPage"])')
show_queries "$T"

# --- 2. automatyczny request po deferred prop --------------------------------
echo "━━ 2. Inertia dociąga deferred prop: only=priceHistogram ━━━━━━━━━━━━━━━━━━"
echo "   Oczekiwane: JEDNO zapytanie — sam histogram. ŻADNYCH wyników ani facetów."
T=$(since)
inertia_get "/search?q=laptop" \
  -H 'X-Inertia-Partial-Component: Search' -H 'X-Inertia-Partial-Data: priceHistogram' >/dev/null
show_queries "$T"

# --- 3. klik w facet (marka) -------------------------------------------------
echo "━━ 3. Klik w facet marki \"$BRAND\": only=results,facets,filters + reset ━━━━━━"
echo "   Oczekiwane: JEDNO zapytanie — wyniki + facety. Histogramu NIE ma."
T=$(since)
inertia_get "/search?q=laptop&brand%5B%5D=$(python3 -c 'import urllib.parse,sys; print(urllib.parse.quote(sys.argv[1]))' "$BRAND")" \
  -H 'X-Inertia-Partial-Component: Search' -H 'X-Inertia-Partial-Data: results,facets,filters' \
  -H 'X-Inertia-Reset: results' >/dev/null
show_queries "$T"

# --- 4. przewinięcie listy (infinite scroll) ---------------------------------
echo "━━ 4. Przewinięcie: <InfiniteScroll> prosi o kolejną stronę (cursor) ━━━━━━━━"
echo "   Oczekiwane: JEDNO zapytanie — kolejna strona BEZ agregacji (facety się nie zmieniły)."
T=$(since)
inertia_get "/search?q=laptop&cursor=$CURSOR" \
  -H 'X-Inertia-Partial-Component: Search' -H 'X-Inertia-Partial-Data: results' \
  -H 'X-Inertia-Infinite-Scroll-Merge-Intent: append' >/dev/null
show_queries "$T"

echo "Wyłączam slowlog-wszystkiego..."
make --no-print-directory es-slowlog-off >/dev/null
echo "Gotowe."
