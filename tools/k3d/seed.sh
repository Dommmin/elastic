#!/usr/bin/env bash
#  tools/k3d/seed.sh [--n=N] [--fresh] — Job `marketplace:seed` na k3d (ETAP G5).
#  Obraz catalog z klastra (ten sam co catalog-app), zdarzenia lądują w outboxie, outbox-publisher wysyła je do RabbitMQ.
#  Czeka na zakończenie Joba. Wyścig kube-router (RUNBOOK #097) łagodzi backoffLimit: 3.
set -uo pipefail
# shellcheck source=tools/k3d/lib.sh
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

N=200
FRESH=""
for a in "$@"; do
  case "$a" in
    --n=*) N="${a#--n=}" ;;
    --fresh) FRESH="--fresh" ;;
    *) die "nieznany argument: $a (użycie: seed.sh [--n=N] [--fresh])" ;;
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

kk delete job marketplace-seed --ignore-not-found --wait=true >/dev/null
# Spec jak w Jobie migrate: profil restricted, uid 33, etykieta app=migrate (NetworkPolicy
# wpuszcza ją do Postgresa). Bez publikacji: zdarzenia leżą w outboxie, a wysyła je
# outbox-publisher — dokładnie ta ścieżka, którą G5 ma obciążyć.
kk apply -f - >/dev/null <<YAML || die "nie udało się utworzyć Joba seed"
apiVersion: batch/v1
kind: Job
metadata:
  name: marketplace-seed
spec:
  backoffLimit: 3
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
              php artisan marketplace:seed --n=${N} ${FRESH} --no-publish --no-interaction
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

log "seed --n=$N ${FRESH} — czekam na Job (do 15 min)"
if ! wait_job marketplace-seed 900; then
  kk logs job/marketplace-seed --all-containers --tail=40 >&2 || true
  die "Job marketplace-seed nie zakończył się sukcesem"
fi
kk logs job/marketplace-seed --tail=3 | sed 's/^/    /'
log "seed zakończony"
