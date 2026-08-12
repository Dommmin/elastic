#!/usr/bin/env bash
# ============================================================================
#  make smoke — sprawdza, czy cały stack faktycznie DZIAŁA, a nie tylko "wstał"
#
#  Healthcheck mówi "kontener żyje". To za mało. Ten skrypt sprawdza rzeczy,
#  które muszą działać, żeby dało się przejść dalej — i jest jednocześnie
#  kryterium ukończenia (DoD) etapów 1-3 z docs/06-PLAN-WDROZENIA.md.
#
#  Uruchamiaj po każdej zmianie w infrastrukturze.
# ============================================================================
set -uo pipefail

RED='\033[0;31m'; GRN='\033[0;32m'; BLD='\033[1m'; DIM='\033[2m'; NC='\033[0m'
PASS=0; FAIL=0

ES="http://localhost:${ES_PORT:-9200}"
ADMIN="-u elastic:${ELASTIC_PASSWORD}"

check() { # check <opis> <oczekiwane> <otrzymane>
  if [ "$2" = "$3" ]; then
    echo -e "  ${GRN}✓${NC} $1"; PASS=$((PASS+1))
  else
    echo -e "  ${RED}✗${NC} $1  ${DIM}(oczekiwano: $2, otrzymano: $3)${NC}"; FAIL=$((FAIL+1))
  fi
}

contains() { # contains <opis> <szukany fragment> <tekst>
  if echo "$3" | grep -q "$2"; then
    echo -e "  ${GRN}✓${NC} $1"; PASS=$((PASS+1))
  else
    echo -e "  ${RED}✗${NC} $1  ${DIM}(brak '$2' w odpowiedzi)${NC}"; FAIL=$((FAIL+1))
  fi
}

http_code() { curl -sS -o /dev/null -w '%{http_code}' "$@"; }
tokens() { python3 -c "import json,sys; print(' '.join(t['token'] for t in json.load(sys.stdin)['tokens']))"; }

echo -e "\n${BLD}  Test dymny stacku${NC}"
echo "  ─────────────────────────────────────────────────────────────"

# ---------------------------------------------------------------- ETAP 1 ----
echo -e "\n  ${BLD}ETAP 1 — warstwa danych${NC}"

check "Postgres odpowiada" "0" \
  "$(docker compose exec -T postgres pg_isready -U "${POSTGRES_USER}" >/dev/null 2>&1; echo $?)"

DBS=$(docker compose exec -T postgres psql -U "${POSTGRES_USER}" -d postgres -tAc \
  "SELECT string_agg(datname, ',' ORDER BY datname) FROM pg_database WHERE datname IN ('${CATALOG_DB}','${SEARCHSVC_DB}');" 2>/dev/null | tr -d '[:space:]')
check "Obie bazy istnieją (rozdzielone per serwis)" "catalog,searchsvc" "$DBS"

check "Redis odpowiada na PING" "PONG" \
  "$(docker compose exec -T redis redis-cli -a "${REDIS_PASSWORD}" --no-auth-warning ping 2>/dev/null | tr -d '[:space:]')"

# ---------------------------------------------------------------- ETAP 2 ----
echo -e "\n  ${BLD}ETAP 2 — Elasticsearch${NC}"

HEALTH=$(curl -sS $ADMIN "$ES/_cluster/health" 2>/dev/null)
STATUS=$(echo "$HEALTH" | python3 -c "import json,sys; print(json.load(sys.stdin)['status'])" 2>/dev/null || echo "brak")
NODES=$(echo "$HEALTH"  | python3 -c "import json,sys; print(json.load(sys.stdin)['number_of_nodes'])" 2>/dev/null || echo "0")
if [ "$STATUS" = "green" ] || [ "$STATUS" = "yellow" ]; then
  echo -e "  ${GRN}✓${NC} Klaster odpowiada  ${DIM}(status: ${STATUS}, node'ów: ${NODES})${NC}"; PASS=$((PASS+1))
else
  echo -e "  ${RED}✗${NC} Klaster niezdrowy: ${STATUS}"; FAIL=$((FAIL+1))
fi

# TLS transportowy — bez niego klaster wielonodowy w ogóle nie wstanie
TLS=$(curl -sS $ADMIN "$ES/_nodes/settings?filter_path=nodes.*.settings.xpack.security.transport.ssl.enabled" 2>/dev/null)
contains "TLS transportowy (node <-> node) włączony" '"enabled":"true"' "$TLS"

# --- pluginy analizy --------------------------------------------------------
PLUGINS=$(curl -sS $ADMIN "$ES/_cat/plugins?h=component" 2>/dev/null)
contains "Plugin analysis-stempel zainstalowany" "analysis-stempel" "$PLUGINS"
contains "Plugin analysis-icu zainstalowany"     "analysis-icu"     "$PLUGINS"

# --- polski stemming: rdzeń musi być wspólny dla całej odmiany --------------
printf '%s' '{"analyzer":"polish","text":"but butów butami buty"}' > /tmp/_smoke_pl.json
STEM=$(curl -sS $ADMIN -X POST "$ES/_analyze" -H 'Content-Type: application/json' \
       --data-binary @/tmp/_smoke_pl.json 2>/dev/null | tokens)
check "Polski stemmer sprowadza odmianę do jednego rdzenia" "but but but but" "$STEM"

# --- ICU folding: diakrytyki -> ASCII ---------------------------------------
printf '%s' '{"tokenizer":"icu_tokenizer","filter":["icu_folding"],"text":"Łódź Gdańsk"}' > /tmp/_smoke_icu.json
FOLD=$(curl -sS $ADMIN -X POST "$ES/_analyze" -H 'Content-Type: application/json' \
       --data-binary @/tmp/_smoke_icu.json 2>/dev/null | tokens)
check "ICU folding normalizuje polskie znaki" "lodz gdansk" "$FOLD"
rm -f /tmp/_smoke_pl.json /tmp/_smoke_icu.json

# --- repozytorium snapshotów (ETAP 10) -------------------------------------
check "Repozytorium snapshotów zarejestrowane" "200" \
  "$(http_code $ADMIN "$ES/_snapshot/local-fs")"

# ------------------------------------------------- ETAP 2: uprawnienia ------
echo -e "\n  ${BLD}ETAP 2 — RBAC (zasada najmniejszych uprawnień)${NC}"

# catalog CZYTA i nie może pisać — to egzekwuje D-03 na poziomie klastra,
# a nie tylko "umową w zespole".
curl -sS $ADMIN -X PUT "$ES/products-smoke" >/dev/null 2>&1
check "catalog MOŻE czytać products-*" "200" \
  "$(http_code -u "catalog:${ES_CATALOG_PASSWORD}" "$ES/products-smoke/_search")"
check "catalog NIE MOŻE pisać (403)" "403" \
  "$(http_code -u "catalog:${ES_CATALOG_PASSWORD}" -X POST "$ES/products-smoke/_doc" \
     -H 'Content-Type: application/json' -d '{"x":1}')"
check "catalog NIE MOŻE usunąć indeksu (403)" "403" \
  "$(http_code -u "catalog:${ES_CATALOG_PASSWORD}" -X DELETE "$ES/products-smoke")"
check "searchsvc MOŻE pisać" "201" \
  "$(http_code -u "searchsvc:${ES_SEARCHSVC_PASSWORD}" -X POST "$ES/products-smoke/_doc" \
     -H 'Content-Type: application/json' -d '{"x":1}')"
curl -sS $ADMIN -X DELETE "$ES/products-smoke" >/dev/null 2>&1

# ---------------------------------------------------------------- ETAP 3 ----
echo -e "\n  ${BLD}ETAP 3 — RabbitMQ${NC}"

QUEUES=$(docker compose exec -T rabbitmq rabbitmqctl list_queues name type 2>/dev/null)
for q in search.product.sync search.analytics.ingest notifications.alerts; do
  contains "Kolejka ${q}" "$q" "$QUEUES"
done
contains "Kolejki DLQ istnieją" "search.product.sync.dlq" "$QUEUES"
contains "Kolejki są typu quorum (replikacja Raft)" "quorum" "$QUEUES"

EXCHANGES=$(docker compose exec -T rabbitmq rabbitmqctl list_exchanges name type 2>/dev/null)
contains "Exchange marketplace.events (topic)" "marketplace.events" "$EXCHANGES"
contains "Exchange marketplace.dlx (dead letter)" "marketplace.dlx" "$EXCHANGES"

# Routing działa? Publikujemy i sprawdzamy, czy wiadomość trafiła we właściwą kolejkę.
#
# UWAGA 1: rabbitmqadmin w RabbitMQ 4.x to WERSJA 2 — inna składnia niż stare
#   `publish exchange=... routing_key=...`. Stara forma zwraca błąd parsowania.
# UWAGA 2: licznik wiadomości jest EVENTUALLY CONSISTENT. `list_queues` pokazuje
#   nowy stan dopiero po kilku sekundach, więc odpytujemy w pętli zamiast
#   zgadywać `sleep`. Ta sama zasada dotyczy monitoringu głębokości kolejek:
#   nie panikuj, gdy licznik "nie nadąża".
qdepth() {
  docker compose exec -T rabbitmq rabbitmqctl list_queues name messages 2>/dev/null \
    | awk -v q="$1" '$1==q{print $2}'
}

wait_for_depth() { # wait_for_depth <kolejka> <oczekiwana wartość> [sekundy]
  local queue="$1" want="$2" limit="${3:-20}" got=""
  for _ in $(seq 1 "$limit"); do
    got=$(qdepth "$queue")
    [ "$got" = "$want" ] && { echo "$got"; return 0; }
    sleep 1
  done
  echo "$got"
}

docker compose exec -T rabbitmq rabbitmqctl purge_queue search.product.sync >/dev/null 2>&1
wait_for_depth search.product.sync 0 >/dev/null

docker compose exec -T rabbitmq rabbitmqadmin \
  --username "${RABBITMQ_USER}" --password "${RABBITMQ_PASSWORD}" \
  publish message --exchange marketplace.events --routing-key offer.price_changed \
  --payload '{"id":"smoke-test","type":"offer.price_changed"}' >/dev/null 2>&1

check "Routing 'offer.price_changed' -> search.product.sync" "1" \
  "$(wait_for_depth search.product.sync 1)"

# Klucz nieobjęty żadnym bindingiem NIE może trafić do kolejki produktowej.
docker compose exec -T rabbitmq rabbitmqadmin \
  --username "${RABBITMQ_USER}" --password "${RABBITMQ_PASSWORD}" \
  publish message --exchange marketplace.events --routing-key user.searched \
  --payload '{"id":"smoke-test-2","type":"user.searched"}' >/dev/null 2>&1

check "Routing 'user.searched' -> analytics (nie do produktów)" "1" \
  "$(wait_for_depth search.analytics.ingest 1)"

# sprzątanie
docker compose exec -T rabbitmq rabbitmqctl purge_queue search.product.sync >/dev/null 2>&1
docker compose exec -T rabbitmq rabbitmqctl purge_queue search.analytics.ingest >/dev/null 2>&1

# ------------------------------------------------------------------ KIBANA --
echo -e "\n  ${BLD}ETAP 2 — Kibana${NC}"
KB=$(curl -sS "http://localhost:${KIBANA_PORT:-5601}/api/status" 2>/dev/null)
contains "Kibana dostępna" '"level":"available"' "$KB"

# --- użytkownik RabbitMQ (patrz tools/render-rabbitmq-definitions.py) -------
RMQ_USERS=$(docker compose exec -T rabbitmq rabbitmqctl list_users 2>/dev/null)
contains "Użytkownik RabbitMQ istnieje" "${RABBITMQ_USER}" "$RMQ_USERS"

# --------------------------------------------------------------- podsumowanie
echo ""
echo "  ─────────────────────────────────────────────────────────────"
if [ "$FAIL" -eq 0 ]; then
  echo -e "  ${GRN}${BLD}Wszystkie testy przeszły (${PASS}).${NC}"
  echo -e "  ${DIM}ETAPY 1-3 spełniają definition of done.${NC}\n"
  exit 0
else
  echo -e "  ${RED}${BLD}Niepowodzeń: ${FAIL}${NC} (zaliczonych: ${PASS})\n"
  exit 1
fi
