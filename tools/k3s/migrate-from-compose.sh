#!/usr/bin/env bash
# ============================================================================
#  migrate-from-compose.sh — dane z D1 (Compose, "blue") do k3s ("green")
#  ETAP D2, Task 7. Uruchamiany NA SERWERZE jako deploy:
#    scp tools/k3s/migrate-from-compose.sh elastic-vps:/tmp/ && ssh elastic-vps 'bash /tmp/migrate-from-compose.sh'
#
#  Compose działa dalej i niczego nie traci — czytamy z niego (dump, snapshot).
#  W k8s na czas migracji zatrzymani są ci, którzy PISZĄ (outbox-publisher,
#  search-consumer): zdarzenie w trakcie restore'u utworzyłoby indeks
#  `products-search` z automatycznym mapowaniem (lekcja z D1, Task 10).
#
#  Postgres: pg_dump (format custom) -> pg_restore --clean do postgres-0.
#    Właściciele obiektów zostają (catalog, searchsvc) — użytkownicy o tych
#    nazwach istnieją już w k8s (skrypt init z obrazu), więc NIE --no-owner.
#  ES: snapshot w Compose -> kopia plików repozytorium do PVC es-snapshots
#    -> repozytorium tylko do odczytu w k8s -> restore products-v1 z aliasem.
#    Ten sam indeks (te same segmenty) = ten sam wynik eval co w Compose.
# ============================================================================
set -euo pipefail
cd /opt/marketplace
set -a; . ./.env; set +a
NS=(-n marketplace)
log() { echo "[migrate] $*"; }

es_compose() { curl -fsS -u "elastic:${ELASTIC_PASSWORD}" -H 'Content-Type: application/json' "http://localhost:${ES_PORT}$1" "${@:2}"; }
ES_K8S_IP="$(kubectl "${NS[@]}" get svc marketplace-es-http -o jsonpath='{.spec.clusterIP}')"
es_k8s() { curl -fsS -u "elastic:${ELASTIC_PASSWORD}" -H 'Content-Type: application/json' "http://${ES_K8S_IP}:9200$1" "${@:2}"; }

log "1/5 k8s: zatrzymuję piszących (publisher, consumer)"
kubectl "${NS[@]}" scale deploy outbox-publisher search-consumer --replicas=0
kubectl "${NS[@]}" wait pod -l 'app in (outbox-publisher,search-consumer)' --for=delete --timeout=120s 2>/dev/null || true

log "2/5 Postgres: świeży dump z Compose"
./pg-backup.sh
for db in "${CATALOG_DB}" "${SEARCHSVC_DB}"; do
  dump="$(ls -t /var/backups/marketplace/${db}-*.dump | head -1)"
  log "    pg_restore ${db} <- $(basename "${dump}")"
  kubectl "${NS[@]}" exec -i postgres-0 -- \
    pg_restore -U "${POSTGRES_USER}" -d "${db}" --clean --if-exists --single-transaction < "${dump}"
done

log "3/5 ES: snapshot products-v1 w Compose"
SNAP="d1-migracja-$(date +%Y%m%d-%H%M%S)"
es_compose "/_snapshot/fs-backup/${SNAP}?wait_for_completion=true" -X PUT \
  -d '{"indices":"products-v1","include_global_state":false}' >/dev/null

log "4/5 ES: kopia repozytorium do PVC k8s + restore"
PV="$(kubectl "${NS[@]}" get pvc es-snapshots -o jsonpath='{.spec.volumeName}')"
DEST="/var/lib/rancher/k3s/storage/${PV}_marketplace_es-snapshots/d1-import"
sudo -n rsync -a --delete /var/lib/docker/volumes/marketplace_es-snapshots/_data/ "${DEST}/"
# Pliki repozytorium muszą być czytelne dla uid 1000 (elasticsearch w podzie).
sudo -n chown -R 1000:1000 "${DEST}"
# readonly: k8s ES tylko CZYTA cudze repozytorium. Dwa klastry piszące do
# jednego repo fs to przepis na uszkodzone metadane snapshotów.
es_k8s /_snapshot/d1-import -X PUT \
  -d '{"type":"fs","settings":{"location":"/snapshots/d1-import","readonly":true}}' >/dev/null
es_k8s /products-v1 -X DELETE >/dev/null 2>&1 || true
es_k8s "/_snapshot/d1-import/${SNAP}/_restore?wait_for_completion=true" -X POST \
  -d '{"indices":"products-v1","include_aliases":true,"include_global_state":false}' >/dev/null
es_k8s "/_cluster/health/products-v1?wait_for_status=green&timeout=120s" >/dev/null
log "    products-search: $(es_k8s /products-search/_count | sed -E 's/.*"count":([0-9]+).*/\1/') dokumentów"

log "5/5 k8s: wznawiam piszących"
kubectl "${NS[@]}" scale deploy outbox-publisher search-consumer --replicas=1
kubectl "${NS[@]}" rollout status deploy/outbox-publisher --timeout=180s
kubectl "${NS[@]}" rollout status deploy/search-consumer --timeout=180s
log "GOTOWE"
