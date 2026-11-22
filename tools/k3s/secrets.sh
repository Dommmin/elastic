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
# Wspólny sekret /api/internal/* (RUNBOOK #127). Wymagany: catalog-app i search-consumer
# mają go jako nieopcjonalny klucz Secretu, więc bez niego pody nie wstaną. Dopisanie:
#   echo "INTERNAL_API_TOKEN=$(openssl rand -hex 32)" >> /opt/marketplace/.env
: "${INTERNAL_API_TOKEN:?brak INTERNAL_API_TOKEN w /opt/marketplace/.env (RUNBOOK #127)}"
# Hasło konta ES catalog_seller_view (ETAP 13, widok sprzedawcy). Wymagane jak wyżej: catalog-app ma je
# jako nieopcjonalny klucz Secretu, a ECK — Secret es-user-catalog-seller-view w fileRealm. Dopisanie:
#   echo "ES_CATALOG_SELLER_VIEW_PASSWORD=$(openssl rand -hex 24)" >> /opt/marketplace/.env
: "${ES_CATALOG_SELLER_VIEW_PASSWORD:?brak ES_CATALOG_SELLER_VIEW_PASSWORD w /opt/marketplace/.env (RUNBOOK #148)}"

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
  --from-literal=INTERNAL_API_TOKEN="${INTERNAL_API_TOKEN}" \
  --from-literal=ES_CATALOG_SELLER_VIEW_PASSWORD="${ES_CATALOG_SELLER_VIEW_PASSWORD}" \
  --from-literal=DATABASE_URL="postgresql://${SEARCHSVC_DB_USER}:${SEARCHSVC_DB_PASSWORD}@postgres:5432/${SEARCHSVC_DB}?serverVersion=18.4.0&charset=utf8" \
  --from-literal=MESSENGER_TRANSPORT_DSN="amqp://${RABBITMQ_USER}:${RABBITMQ_PASSWORD}@rabbitmq:5672/%2f" \
  ${FAKE_GATEWAY_WEBHOOK_SECRET:+--from-literal=FAKE_GATEWAY_WEBHOOK_SECRET="${FAKE_GATEWAY_WEBHOOK_SECRET}"} \
  | apply
# FAKE_GATEWAY_WEBHOOK_SECRET (ETAP F, RUNBOOK #085) jest opcjonalny: bez niego webhooki
# płatności są odrzucane, ale reszta sklepu działa. Dopisanie do .env na serwerze:
#   echo "FAKE_GATEWAY_WEBHOOK_SECRET=$(openssl rand -hex 32)" >> /opt/marketplace/.env

# --- 2. hasło `elastic` — ECK używa istniejącego Secretu <nazwa>-es-elastic-user
#        zamiast generować własne (to samo hasło co w D1 -> restore snapshotu
#        i narzędzia działają bez zmian).
kubectl -n "${NS}" create secret generic marketplace-es-elastic-user --dry-run=client -o yaml \
  --from-literal=elastic="${ELASTIC_PASSWORD}" | apply

# --- 3. użytkownicy aplikacji ES (file realm) — w D1 robił to es-setup przez API.
#        Nazwa Secretu: es-user-<użytkownik> z `_` zamienionym na `-` (nazwy obiektów k8s nie znają `_`).
for user in catalog:ES_CATALOG_PASSWORD:catalog_app searchsvc:ES_SEARCHSVC_PASSWORD:search_service \
            catalog_seller_view:ES_CATALOG_SELLER_VIEW_PASSWORD:seller_stats_view; do
  IFS=: read -r name var role <<<"${user}"
  kubectl -n "${NS}" create secret generic "es-user-${name//_/-}" --type=kubernetes.io/basic-auth --dry-run=client -o yaml \
    --from-literal=username="${name}" --from-literal=password="${!var}" --from-literal=roles="${role}" | apply
done

# --- 4. GlitchTip (opcjonalny, ETAP 11b): tylko gdy .env ma sekcję GLITCHTIP_* ----------------
#        (./add-glitchtip-env.sh ją dopisuje). Bez niej nic nie powstaje, a deploy.sh pomija
#        GlitchTipa. Wartości z .env, ten sam zestaw co w Compose (DSN-y i klucze są stałe).
if [ -n "${GLITCHTIP_KEY_CATALOG:-}" ] && [ -n "${GLITCHTIP_SECRET_KEY:-}" ]; then
  kubectl -n "${NS}" create secret generic glitchtip-secrets --dry-run=client -o yaml \
    --from-literal=DATABASE_URL="postgresql://${GLITCHTIP_DB_USER}:${GLITCHTIP_DB_PASSWORD}@postgres:5432/${GLITCHTIP_DB}" \
    --from-literal=SECRET_KEY="${GLITCHTIP_SECRET_KEY}" \
    --from-literal=GLITCHTIP_DB="${GLITCHTIP_DB}" \
    --from-literal=GLITCHTIP_DB_USER="${GLITCHTIP_DB_USER}" \
    --from-literal=GLITCHTIP_DB_PASSWORD="${GLITCHTIP_DB_PASSWORD}" \
    --from-literal=GLITCHTIP_ADMIN_EMAIL="${GLITCHTIP_ADMIN_EMAIL}" \
    --from-literal=GLITCHTIP_ADMIN_PASSWORD="${GLITCHTIP_ADMIN_PASSWORD}" \
    --from-literal=GLITCHTIP_API_TOKEN="${GLITCHTIP_API_TOKEN}" \
    --from-literal=GLITCHTIP_KEY_CATALOG="${GLITCHTIP_KEY_CATALOG}" \
    --from-literal=GLITCHTIP_KEY_FRONTEND="${GLITCHTIP_KEY_FRONTEND}" \
    --from-literal=GLITCHTIP_KEY_SEARCH="${GLITCHTIP_KEY_SEARCH}" \
    --from-literal=DSN_CATALOG="${GLITCHTIP_DSN_CATALOG}" \
    --from-literal=DSN_FRONTEND="${GLITCHTIP_DSN_FRONTEND}" \
    --from-literal=DSN_SEARCH="${GLITCHTIP_DSN_SEARCH}" \
    | apply
  GT=", glitchtip-secrets"
fi

echo "secrets: OK (marketplace-secrets, marketplace-es-elastic-user, es-user-catalog, es-user-searchsvc, es-user-catalog-seller-view${GT:-})"
