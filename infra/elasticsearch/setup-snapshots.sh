#!/usr/bin/env bash
# ============================================================================
#  Snapshoty na S3 (ETAP 10) — uruchamiane Z HOSTA przez `make es-snapshots-setup`
#
#  CO ROBI (idempotentnie):
#   1. wkłada klucze S3 do keystore'a KAŻDEGO node'a (es01..es03) i przeładowuje
#      je bez restartu (_nodes/reload_secure_settings)
#   2. rejestruje repozytorium `minio-s3` (type: s3) — S3 sprawdza przy tym
#      zapis/odczyt z każdego node'a (verify), więc brak klucza na jednym = błąd
#   3. tworzy lokalną politykę SLM `local-nightly` (repo local-fs, retencja)
#
#  DLACZEGO keystore, a nie zmienna środowiskowa: klucze to sekrety. Zmienna
#  środowiskowa kontenera widać w `docker inspect`; keystore jest zaszyfrowanym
#  plikiem w configu node'a, a ES nie pozwala czytać jego wartości z API.
#  PUŁAPKA: keystore leży w kontenerze, nie na wolumenie — po odtworzeniu
#  kontenera (docker compose up --force-recreate / zmiana obrazu) trzeba
#  uruchomić ten skrypt ponownie.
#
#  Endpoint (ze schematem http://) i path_style_access to ustawienia node'a (compose.yaml,
#  zmienne s3.client.default.*), nie repozytorium. `protocol` jest w 9.5 przestarzałe
#  (ostrzeżenie CRITICAL w logu deprecacji), więc go nie ustawiamy.
#
#  Dane dostępowe czytane są w kontenerach (ELASTIC_PASSWORD) lub z .env przez
#  `docker compose` — skrypt niczego nie wypisuje.
# ============================================================================
set -euo pipefail

DC="${DC:-docker compose}"
NODES=(es01 es02 es03)
REPO="${SNAPSHOT_S3_REPO:-minio-s3}"

log() { printf '[snapshots] %s\n' "$*"; }
die() { printf '[snapshots] BŁĄD: %s\n' "$*" >&2; exit 1; }

# esa METODA ŚCIEŻKA [JSON] — wywołanie ES jako `elastic` z wnętrza es01
esa() {
  ${DC} exec -T es01 sh -c \
    'curl -sS --fail-with-body -u "elastic:$ELASTIC_PASSWORD" -H "Content-Type: application/json" -X "$0" "http://localhost:9200$1" ${2:+-d "$2"}' \
    "$1" "$2" "${3:-}"
}

# --- 0. klucze z .env (nazwy zmiennych, nigdy wartości w wyjściu) -----------
# env_get KLUCZ — wartość z .env (bez `source`: wartości mogą zawierać znaki specjalne)
env_get() { grep -E "^$1=" .env 2>/dev/null | tail -n1 | cut -d= -f2- || true; }
ES_S3_ACCESS_KEY="${ES_S3_ACCESS_KEY:-$(env_get ES_S3_ACCESS_KEY)}"
ES_S3_SECRET_KEY="${ES_S3_SECRET_KEY:-$(env_get ES_S3_SECRET_KEY)}"
: "${ES_S3_ACCESS_KEY:?brak ES_S3_ACCESS_KEY w .env}"
: "${ES_S3_SECRET_KEY:?brak ES_S3_SECRET_KEY w .env}"
BUCKET="${MINIO_BUCKET:-$(env_get MINIO_BUCKET)}"
BUCKET="${BUCKET:-es-snapshots}"

# --- 1. keystore --------------------------------------------------------------
for n in "${NODES[@]}"; do
  log "Keystore ${n}: s3.client.default.{access,secret}_key"
  printf '%s' "${ES_S3_ACCESS_KEY}" | ${DC} exec -T "${n}" \
    bin/elasticsearch-keystore add --stdin --force s3.client.default.access_key >/dev/null
  printf '%s' "${ES_S3_SECRET_KEY}" | ${DC} exec -T "${n}" \
    bin/elasticsearch-keystore add --stdin --force s3.client.default.secret_key >/dev/null
done
log "Przeładowuję ustawienia bezpieczne (bez restartu)"
esa POST /_nodes/reload_secure_settings '{}' | python3 -c '
import json, sys
r = json.load(sys.stdin)
bad = {k: v.get("reload_exception") for k, v in r.get("nodes", {}).items() if v.get("reload_exception")}
if bad or r.get("_nodes", {}).get("failed"):
    print("reload nie powiódł się:", bad or r, file=sys.stderr); sys.exit(1)
print("[snapshots]   węzłów przeładowanych:", r["_nodes"]["successful"])'

# --- 2. repozytorium S3 --------------------------------------------------------
log "Rejestruję repozytorium '${REPO}' (bucket ${BUCKET})"
esa PUT "/_snapshot/${REPO}" "{\"type\":\"s3\",\"settings\":{\"bucket\":\"${BUCKET}\",\"base_path\":\"snapshots\"}}" >/dev/null \
  || die "rejestracja repozytorium nie powiodła się (czy MinIO działa? make es-snapshots-setup startuje go)"
esa POST "/_snapshot/${REPO}/_verify" >/dev/null || die "verify repozytorium ${REPO} nie przeszedł"
log "  OK (verify ze wszystkich node'ów)"

# --- 3. SLM lokalnie -----------------------------------------------------------
# Harmonogram w UTC. Nazwa z datą (<local-nightly-{now/d}>) + losowy sufiks ES.
# Retencja: 7 dni, ale zawsze min. 1 i maks. 7 snapshotów. Retencja jest egzekwowana
# osobnym zadaniem SLM (slm.retention_schedule, domyślnie 01:30 UTC).
log "Polityka SLM 'local-nightly' (repo local-fs)"
esa PUT /_slm/policy/local-nightly '{
  "schedule": "0 0 1 * * ?",
  "name": "<local-nightly-{now/d}>",
  "repository": "local-fs",
  "config": { "indices": ["products-*"], "include_global_state": false },
  "retention": { "expire_after": "7d", "min_count": 1, "max_count": 7 }
}' >/dev/null
log "  OK"
log "Gotowe. Test: make es-restore-drill repo=minio-s3 ; make es-slm-run"
