#!/usr/bin/env bash
# ============================================================================
#  secrets.sh — Secrety Kubernetesa z /opt/marketplace/.env (ETAP D2, Task 3)
#
#  Uruchamiany NA SERWERZE jako deploy (kubeconfig ~/.kube/config):
#    scp tools/k3s/secrets.sh elastic-vps:/tmp/ && ssh elastic-vps 'bash /tmp/secrets.sh'
#
#  Te same hasła co w D1 (decyzja K-7): migracja danych z Compose bez zmiany
#  haseł w bazach. Idempotentny: `create --dry-run=client -o yaml | apply`
#  tworzy albo aktualizuje. Nie wypisuje żadnej wartości.
#
#  Secret w Kubernetesie to base64, NIE szyfrowanie — każdy z prawem `get
#  secrets` w namespace widzi hasła. Na jednoosobowym klastrze to OK; w zespole
#  dochodzi RBAC, szyfrowanie etcd w spoczynku i np. Sealed Secrets/SOPS.
# ============================================================================
set -euo pipefail
cd /opt/marketplace
set -a; . ./.env; set +a
NS=marketplace

apply() { kubectl apply -f - >/dev/null; }

kubectl get ns "${NS}" >/dev/null 2>&1 || kubectl create ns "${NS}" >/dev/null

# --- 1. hasła aplikacji i usług (jedno miejsce, mapowane w manifestach) -----
kubectl -n "${NS}" create secret generic marketplace-secrets --dry-run=client -o yaml \
  --from-literal=POSTGRES_PASSWORD="${POSTGRES_PASSWORD}" \
  --from-literal=CATALOG_DB_PASSWORD="${CATALOG_DB_PASSWORD}" \
  --from-literal=SEARCHSVC_DB_PASSWORD="${SEARCHSVC_DB_PASSWORD}" \
  --from-literal=RABBITMQ_PASSWORD="${RABBITMQ_PASSWORD}" \
  --from-literal=REDIS_PASSWORD="${REDIS_PASSWORD}" \
  --from-literal=ES_CATALOG_PASSWORD="${ES_CATALOG_PASSWORD}" \
  --from-literal=ES_SEARCHSVC_PASSWORD="${ES_SEARCHSVC_PASSWORD}" \
  --from-literal=CATALOG_APP_KEY="${CATALOG_APP_KEY}" \
  --from-literal=SEARCH_APP_SECRET="${SEARCH_APP_SECRET}" \
  --from-literal=DATABASE_URL="postgresql://${SEARCHSVC_DB_USER}:${SEARCHSVC_DB_PASSWORD}@postgres:5432/${SEARCHSVC_DB}?serverVersion=18.4.0&charset=utf8" \
  --from-literal=MESSENGER_TRANSPORT_DSN="amqp://${RABBITMQ_USER}:${RABBITMQ_PASSWORD}@rabbitmq:5672/%2f" \
  | apply

# --- 2. hasło `elastic` — ECK używa istniejącego Secretu <nazwa>-es-elastic-user
#        zamiast generować własne (to samo hasło co w D1 -> restore snapshotu
#        i narzędzia działają bez zmian).
kubectl -n "${NS}" create secret generic marketplace-es-elastic-user --dry-run=client -o yaml \
  --from-literal=elastic="${ELASTIC_PASSWORD}" | apply

# --- 3. użytkownicy aplikacji ES (file realm) — w D1 robił to es-setup przez API
for user in catalog:ES_CATALOG_PASSWORD:catalog_app searchsvc:ES_SEARCHSVC_PASSWORD:search_service; do
  IFS=: read -r name var role <<<"${user}"
  kubectl -n "${NS}" create secret generic "es-user-${name}" --type=kubernetes.io/basic-auth --dry-run=client -o yaml \
    --from-literal=username="${name}" --from-literal=password="${!var}" --from-literal=roles="${role}" | apply
done

echo "secrets: OK (marketplace-secrets, marketplace-es-elastic-user, es-user-catalog, es-user-searchsvc)"
