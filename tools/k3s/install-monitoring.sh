#!/usr/bin/env bash
# ============================================================================
#  tools/k3s/install-monitoring.sh — Prometheus/Grafana/Alertmanager na k3s
#  (ETAP G1, docs/13-PLAN-ETAP-G-K8S.md). Uruchamiany z MACA przez tunel:
#
#    make k3s-monitoring
#
#  Idempotentny: drugi raz = `helm upgrade` z tymi samymi wartościami (no-op)
#  i `kubectl apply` obiektów z deploy/monitoring.
#
#  Kroki:
#    1. namespace monitoring + Secret grafana-admin (losowe hasło TYLKO przy
#       pierwszym razie; Secret jest źródłem prawdy, nie .env na serwerze)
#    2. helm upgrade --install kube-prometheus-stack (wersja przypięta niżej)
#    3. ServiceMonitory, PrometheusRule i dashboardy z repo (deploy/monitoring)
# ============================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
CHART_VERSION="92.17.1"   # kube-prometheus-stack; operator v0.94.2
RELEASE="kps"             # krótka nazwa: trafia do nazw wszystkich obiektów
NS="monitoring"
KUBECONFIG_VPS="${HOME}/.kube/elastic-vps.yaml"
kk() { /opt/homebrew/bin/kubectl --kubeconfig "${KUBECONFIG_VPS}" --request-timeout=60s "$@"; }
hh() { /opt/homebrew/bin/helm --kubeconfig "${KUBECONFIG_VPS}" "$@"; }
log() { echo "[k3s-monitoring] $*"; }

nc -z 127.0.0.1 26443 2>/dev/null || ssh -fN elastic-vps-k8s

log "1/3 namespace ${NS} i Secret grafana-admin"
kk create namespace "${NS}" --dry-run=client -o yaml | kk apply -f - >/dev/null
if ! kk -n "${NS}" get secret grafana-admin >/dev/null 2>&1; then
  # Hasło nie pojawia się w terminalu ani w historii — odczyt: make k3s-grafana.
  pass="$(openssl rand -hex 16)"
  kk -n "${NS}" create secret generic grafana-admin \
    --from-literal=admin-user=admin \
    --from-literal=admin-password="${pass}" >/dev/null
  unset pass
  log "    utworzono Secret grafana-admin (losowe hasło)"
fi

log "2/3 helm: kube-prometheus-stack ${CHART_VERSION}"
hh repo add prometheus-community https://prometheus-community.github.io/helm-charts >/dev/null 2>&1 || true
hh repo update prometheus-community >/dev/null
# --wait: helm czeka na gotowość Deploymentów/StatefulSetów, a nie tylko na
# zapis obiektów do API. Bez tego krok 3 mógłby trafić przed CRD.
hh upgrade --install "${RELEASE}" prometheus-community/kube-prometheus-stack \
  --namespace "${NS}" --version "${CHART_VERSION}" \
  --values "${ROOT}/deploy/monitoring/values.yaml" \
  --wait --timeout 10m

log "3/3 obiekty marketplace: ServiceMonitory, PrometheusRule, dashboardy"
ruby "${ROOT}/tools/k3s/gen-prometheus-rule.rb" --check
kk kustomize --load-restrictor LoadRestrictionsNone "${ROOT}/deploy/monitoring" | kk apply -f -

log "OK. Dostęp (każde w osobnym terminalu):"
log "    make k3s-grafana       # http://localhost:33000"
log "    make k3s-prometheus    # http://localhost:39090"
log "    make k3s-alertmanager  # http://localhost:39093"
