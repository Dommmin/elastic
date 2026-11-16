#!/usr/bin/env bash
#  tools/k3d/seed.sh [--n=N] [--fresh] — Job `marketplace:seed` na k3d (ETAP G5).
#  tools/k3d/seed.sh --traffic          — Job `marketplace-traffic`: ciągły ruch sklepu.
#  Obraz catalog z klastra (ten sam co catalog-app), zdarzenia lądują w outboxie, outbox-publisher wysyła je do RabbitMQ.
#  seed: czeka na zakończenie Joba. Wyścig kube-router (RUNBOOK #097) łagodzi backoffLimit: 3.
#
#  --traffic: w pętli losowy produkt dostaje updateWithOutbox(…, 'product.updated') — ta sama
#  ścieżka co edycja produktu w panelu sprzedawcy (Panel\ProductController), ok. 20 zdarzeń/s.
#  Nie czeka (wraca, gdy Job ruszył); zatrzymanie: kk delete job marketplace-traffic.
#  Zabicie w środku jest bezpieczne: zmiana produktu i wiersz outboxu są w jednej transakcji.
#  Po co osobny tryb: seed w pętli się nie da — bez --fresh pada na unikalnych slugach
#  kategorii, a z --fresh czyści outbox (TRUNCATE), czyli kasuje dowód liczenia migracji.
#  Limit bezpieczeństwa: activeDeadlineSeconds 1800 (Job nie kręci się w nieskończoność).
set -uo pipefail
# shellcheck source=tools/k3d/lib.sh
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

N=200
FRESH=""
TRAFFIC=""
for a in "$@"; do
  case "$a" in
    --n=*) N="${a#--n=}" ;;
    --fresh) FRESH="--fresh" ;;
    --traffic) TRAFFIC=1 ;;
    *) die "nieznany argument: $a (użycie: seed.sh [--n=N] [--fresh] | seed.sh --traffic)" ;;
  esac
done
case "$N" in ''|*[!0-9]*) die "--n musi być liczbą" ;; esac
cluster_exists || die "klaster $CLUSTER nie istnieje (make g5-up)"

IMAGE="$(kk get deploy catalog-app -o jsonpath='{.spec.template.spec.containers[?(@.name=="catalog")].image}')"
[ -n "$IMAGE" ] || die "brak Deploymentu catalog-app (make g5-deploy o=g5-local)"

# ConfigMapa z Deploymentu catalog-app (nie z listy: deploy nie czyści starych wersji z hashem).
CM="$(kk get deploy catalog-app -o jsonpath='{.spec.template.spec.containers[?(@.name=="catalog")].envFrom[*].configMapRef.name}')" \
  || die "nie udało się odczytać catalog-app"
[ -n "$CM" ] || die "catalog-app nie wskazuje ConfigMapy w envFrom"

if [ -n "$TRAFFIC" ]; then
  JOB=marketplace-traffic
  DEADLINE="activeDeadlineSeconds: 1800"
  # HOME/XDG_*=/tmp: psysh (tinker) zapisuje konfigurację w $XDG_CONFIG_HOME (obraz: /config), a system plików jest tylko do odczytu.
  CMD='HOME=/tmp XDG_CONFIG_HOME=/tmp XDG_DATA_HOME=/tmp php artisan tinker --execute '"'"'while (true) { $p = App\Models\Product::inRandomOrder()->first(); if ($p) { $p->updateWithOutbox(["name" => $p->name], "product.updated"); } usleep(50000); }'"'"
else
  JOB=marketplace-seed
  DEADLINE=""
  CMD="php artisan marketplace:seed --n=${N} ${FRESH} --no-publish --no-interaction"
fi

kk delete job "$JOB" --ignore-not-found --wait=true >/dev/null
# Spec jak w Jobie migrate: profil restricted, uid 33, etykieta app=migrate (NetworkPolicy
# wpuszcza ją do Postgresa). Bez publikacji: zdarzenia leżą w outboxie, a wysyła je
# outbox-publisher — dokładnie ta ścieżka, którą G5 ma obciążyć.
kk apply -f - >/dev/null <<YAML || die "nie udało się utworzyć Joba seed"
apiVersion: batch/v1
kind: Job
metadata:
  name: ${JOB}
spec:
  backoffLimit: 3
  ${DEADLINE}
  ttlSecondsAfterFinished: 3600
  template:
    metadata:
      labels:
        app: migrate
    spec:
      restartPolicy: Never
      serviceAccountName: catalog
      automountServiceAccountToken: false
      securityContext:
        runAsNonRoot: true
        runAsUser: 33
        runAsGroup: 33
        fsGroup: 33
        seccompProfile: { type: RuntimeDefault }
      initContainers:
        - name: writable-dirs
          image: ${IMAGE}
          command: ["sh", "-c", "cp -R /app/storage/. /work/storage/ && cp -R /app/bootstrap/cache/. /work/bootstrap-cache/"]
          securityContext:
            allowPrivilegeEscalation: false
            readOnlyRootFilesystem: true
            capabilities: { drop: [ALL] }
          volumeMounts:
            - { name: storage, mountPath: /work/storage }
            - { name: bootstrap-cache, mountPath: /work/bootstrap-cache }
      containers:
        - name: seed
          image: ${IMAGE}
          securityContext:
            allowPrivilegeEscalation: false
            readOnlyRootFilesystem: true
            capabilities: { drop: [ALL] }
          command: ["sh", "-c"]
          args:
            - |
              ${CMD}
          envFrom:
            - configMapRef: { name: ${CM} }
          env:
            - name: APP_KEY
              valueFrom: { secretKeyRef: { name: marketplace-secrets, key: CATALOG_APP_KEY } }
            - name: DB_DATABASE
              valueFrom: { configMapKeyRef: { name: ${CM}, key: CATALOG_DB } }
            - name: DB_USERNAME
              valueFrom: { configMapKeyRef: { name: ${CM}, key: CATALOG_DB_USER } }
            - name: DB_PASSWORD
              valueFrom: { secretKeyRef: { name: marketplace-secrets, key: CATALOG_DB_PASSWORD } }
            - name: REDIS_PASSWORD
              valueFrom: { secretKeyRef: { name: marketplace-secrets, key: REDIS_PASSWORD } }
          volumeMounts:
            - { name: storage, mountPath: /app/storage }
            - { name: bootstrap-cache, mountPath: /app/bootstrap/cache }
            - { name: tmp, mountPath: /tmp }
      volumes:
        - { name: storage, emptyDir: {} }
        - { name: bootstrap-cache, emptyDir: {} }
        - { name: tmp, emptyDir: {} }
YAML

if [ -n "$TRAFFIC" ]; then
  traffic_active() { [ "$(kk get job "$JOB" -o jsonpath='{.status.active}' 2>/dev/null)" = "1" ]; }
  wait_for 120 traffic_active || die "Job $JOB nie wystartował"
  log "ruch sklepu: Job $JOB działa (stop: kk delete job $JOB)"
  exit 0
fi

log "seed --n=$N ${FRESH} — czekam na Job (do 15 min)"
if ! wait_job marketplace-seed 900; then
  kk logs job/marketplace-seed --all-containers --tail=40 >&2 || true
  die "Job marketplace-seed nie zakończył się sukcesem"
fi
kk logs job/marketplace-seed --tail=3 | sed 's/^/    /'
log "seed zakończony"
