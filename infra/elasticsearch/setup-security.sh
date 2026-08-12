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
log "Tworzę rolę search_service (write + manage)..."
curl -fsS -u "${AUTH}" -X PUT "${ES}/_security/role/search_service" \
  -H 'Content-Type: application/json' -d '{
  "cluster": [
    "monitor",
    "manage_index_templates",
    "manage_ilm",
    "manage_pipeline"
  ],
  "indices": [
    {
      "names": [
        "products-*", "offers-*", "seller-360*",
        "alerts-*", "events-*", "search-stats-*", "logs-app-*"
      ],
      "privileges": ["all"]
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
