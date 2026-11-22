#!/usr/bin/env bash
# ============================================================================
#  Inicjalizacja bezpieczeństwa klastra (kontener jednorazowy `es-setup`)
#
#  CO ROBI:
#   1. ustawia hasło wbudowanego użytkownika kibana_system
#   2. tworzy ROLE o minimalnych uprawnieniach dla obu aplikacji
#   3. tworzy użytkowników aplikacyjnych
#
#  DLACZEGO tak, a nie "wszystko na użytkowniku elastic":
#  Użytkownik `elastic` to superuser. Aplikacja, która go używa, może skasować
#  cały klaster jednym requestem. Zasada najmniejszych uprawnień mówi:
#  catalog CZYTA, search-service PISZE — i nic ponad to.
#
#  W ETAPIE 12 zamienimy użytkowników na API keys (właściwy sposób
#  uwierzytelniania aplikacji) i dodamy DLS/FLS.
#
#  Skrypt jest idempotentny — PUT na rolę/użytkownika nadpisuje definicję.
# ============================================================================
set -euo pipefail

ES=http://es01:9200
AUTH="elastic:${ELASTIC_PASSWORD}"

log() { echo "[setup] $*"; }

# --- czekaj na klaster (healthcheck już przeszedł, ale bądźmy odporni) -------
log "Czekam na Elasticsearch pod ${ES}..."
for i in $(seq 1 60); do
  if curl -fsS -u "${AUTH}" "${ES}/_cluster/health" >/dev/null 2>&1; then
    log "Elasticsearch odpowiada."
    break
  fi
  if [ "$i" = "60" ]; then
    log "BŁĄD: Elasticsearch nie odpowiedział w ciągu 60 prób."
    exit 1
  fi
  sleep 2
done

# --- 1. hasło dla kibana_system ---------------------------------------------
# kibana_system to wbudowany użytkownik SERWISOWY. Kibana loguje się nim do ES.
# Nie ma uprawnień do danych użytkownika — tylko do swoich indeksów systemowych.
log "Ustawiam hasło kibana_system..."
curl -fsS -u "${AUTH}" -X POST "${ES}/_security/user/kibana_system/_password" \
  -H 'Content-Type: application/json' \
  -d "{\"password\":\"${KIBANA_SYSTEM_PASSWORD}\"}" >/dev/null
log "  OK"

# --- 2. rola dla aplikacji catalog (Laravel) — TYLKO ODCZYT -----------------
# D-03: Laravel nigdy nie pisze do Elasticsearcha. Ta rola to egzekwuje.
# Ćwiczenie w ETAPIE 2: spróbuj z tego konta usunąć indeks. Dostaniesz 403.
log "Tworzę rolę catalog_app (read-only)..."
curl -fsS -u "${AUTH}" -X PUT "${ES}/_security/role/catalog_app" \
  -H 'Content-Type: application/json' -d '{
  "cluster": ["monitor"],
  "indices": [
    {
      "names": ["products-*", "offers-*", "seller-360*", "search-stats-*"],
      "privileges": ["read", "view_index_metadata"]
    }
  ]
}' >/dev/null
log "  OK"

# --- 3. rola dla search-service (Symfony) — ZAPIS I ZARZĄDZANIE -------------
# Jedyny serwis, który pisze do ES. Potrzebuje też zarządzać szablonami
# i politykami ILM, bo mapowania są kodem (zasada z 01-INFRASTRUKTURA.md).
# cancel_task (ETAP 10): Ctrl-C w `search:index:migrate reindex --wait` woła
# `_tasks/<id>/_cancel` — bez tego przywileju ES odpowiada 403.
# ETAP 12: manage_transform (transformy lejka i popularności), manage_enrich
# (polityki enrich) oraz indeks product-popularity* (wynik transformu).
# ETAP 13: complaints* — import reklamacji (complaints, complaints-unmatched, _reindex, delete-by-query);
# manage_enrich wykonuje też politykę seller-by-nip.
# ETAP 13b: courier-ratings (jawna nazwa, bez wzorca) — oceny kurierów z rejestru; `_update_by_query` fan-outu
# na seller-360* mieści się w "all", a odczyt `_tasks/<id>` w `monitor`.
log "Tworzę rolę search_service (write + manage)..."
curl -fsS -u "${AUTH}" -X PUT "${ES}/_security/role/search_service" \
  -H 'Content-Type: application/json' -d '{
  "cluster": [
    "monitor",
    "manage_index_templates",
    "manage_ilm",
    "manage_pipeline",
    "cancel_task",
    "manage_transform",
    "manage_enrich"
  ],
  "indices": [
    {
      "names": [
        "products-*", "offers-*", "seller-360*",
        "alerts-*", "events-*", "search-stats-*", "seller-sales-*", "logs-app-*",
        "product-popularity*", "complaints*"
      ],
      "privileges": ["all"]
    },
    {
      "names": ["courier-ratings"],
      "privileges": ["read", "write", "view_index_metadata"]
    }
  ]
}' >/dev/null
log "  OK"

# --- 3b. rola widoku sprzedawcy (ETAP 13, Task 15) — TYLKO ODCZYT seller-360-public -------
# Licencja basic nie ma FLS/DLS, więc izolację robi osobny alias `seller-360-public`
# (bez billing i complaints), a ta rola widzi tylko jego. Brak `monitor` i brak
# view_index_metadata: konto nie wie nawet, że istnieje `seller-360`.
log "Tworzę rolę seller_stats_view (read seller-360-public)..."
curl -fsS -u "${AUTH}" -X PUT "${ES}/_security/role/seller_stats_view" \
  -H 'Content-Type: application/json' -d '{
  "cluster": [],
  "indices": [
    {
      "names": ["seller-360-public"],
      "privileges": ["read"]
    }
  ]
}' >/dev/null
log "  OK"

# --- 4. użytkownicy aplikacyjni ---------------------------------------------
log "Tworzę użytkownika catalog..."
curl -fsS -u "${AUTH}" -X PUT "${ES}/_security/user/catalog" \
  -H 'Content-Type: application/json' \
  -d "{\"password\":\"${ES_CATALOG_PASSWORD}\",\"roles\":[\"catalog_app\"],\"full_name\":\"Catalog app (Laravel)\"}" >/dev/null
log "  OK"

log "Tworzę użytkownika searchsvc..."
curl -fsS -u "${AUTH}" -X PUT "${ES}/_security/user/searchsvc" \
  -H 'Content-Type: application/json' \
  -d "{\"password\":\"${ES_SEARCHSVC_PASSWORD}\",\"roles\":[\"search_service\"],\"full_name\":\"Search service (Symfony)\"}" >/dev/null
log "  OK"

# Hasło bywa puste na świeżym checkoucie bez wpisu w .env: wtedy konta nie tworzymy
# (blok statystyk pokaże „chwilowo niedostępne”), a reszta skryptu działa dalej.
if [ -n "${ES_CATALOG_SELLER_VIEW_PASSWORD:-}" ]; then
  log "Tworzę użytkownika catalog_seller_view..."
  curl -fsS -u "${AUTH}" -X PUT "${ES}/_security/user/catalog_seller_view" \
    -H 'Content-Type: application/json' \
    -d "{\"password\":\"${ES_CATALOG_SELLER_VIEW_PASSWORD}\",\"roles\":[\"seller_stats_view\"],\"full_name\":\"Catalog: widok sprzedawcy\"}" >/dev/null
  log "  OK"
else
  log "Pomijam catalog_seller_view: brak ES_CATALOG_SELLER_VIEW_PASSWORD."
fi

# --- 5. rejestracja repozytorium snapshotów (przyda się w ETAPIE 10) --------
log "Rejestruję repozytorium snapshotów 'local-fs'..."
curl -fsS -u "${AUTH}" -X PUT "${ES}/_snapshot/local-fs" \
  -H 'Content-Type: application/json' \
  -d '{"type":"fs","settings":{"location":"/snapshots","compress":true}}' >/dev/null || \
  log "  (pominięte — repozytorium może już istnieć)"
log "  OK"

log "--------------------------------------------------------------"
log "Inicjalizacja zakończona."
log "  Kibana:  http://localhost:5601   (elastic / \$ELASTIC_PASSWORD)"
log "  ES:      http://localhost:9200"
log "--------------------------------------------------------------"
