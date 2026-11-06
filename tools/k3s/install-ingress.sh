#!/usr/bin/env bash
# ============================================================================
#  tools/k3s/install-ingress.sh — cert-manager + Traefik + CA + Ingressy na k3s
#  (ETAP G2, docs/13-PLAN-ETAP-G-K8S.md). Uruchamiany z MACA przez tunel:
#
#    make k3s-ingress
#
#  Idempotentny: drugi raz = `helm upgrade` z tymi samymi wartościami i
#  `kubectl apply` obiektów z deploy/ingress.
#
#  Kolejność ma znaczenie:
#    1. cert-manager (z CRD) — bez CRD obiekty Certificate/ClusterIssuer są „nieznanym typem”
#    2. Traefik — po nim istnieje IngressClass `traefik`
#    3. issuers.yaml — CA; czekamy, aż Certificate marketplace-ca będzie Ready,
#       bo ClusterIssuer CA bez Secretu CA nie wystawi niczego
#    4. ingresses.yaml — certyfikaty hostów i Ingressy
#  Wymaga G1 (CRD ServiceMonitor) — oba charty zgłaszają metryki do Prometheusa.
# ============================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
CERT_MANAGER_VERSION="v1.21.2"   # wydany 2026-09-11
TRAEFIK_CHART_VERSION="42.1.3"   # Traefik v3.7.16, wydany 2026-10-22
KUBECONFIG_VPS="${HOME}/.kube/elastic-vps.yaml"
kk() { /opt/homebrew/bin/kubectl --kubeconfig "${KUBECONFIG_VPS}" --request-timeout=60s "$@"; }
hh() { /opt/homebrew/bin/helm --kubeconfig "${KUBECONFIG_VPS}" "$@"; }
log() { echo "[k3s-ingress] $*"; }

nc -z 127.0.0.1 26443 2>/dev/null || ssh -fN elastic-vps-k8s

log "1/4 helm: cert-manager ${CERT_MANAGER_VERSION}"
hh repo add jetstack https://charts.jetstack.io >/dev/null 2>&1 || true
hh repo add traefik https://traefik.github.io/charts >/dev/null 2>&1 || true
hh repo update jetstack traefik >/dev/null
hh upgrade --install cert-manager jetstack/cert-manager \
  --namespace cert-manager --create-namespace --version "${CERT_MANAGER_VERSION}" \
  --values "${ROOT}/deploy/ingress/cert-manager-values.yaml" \
  --wait --timeout 10m

log "2/4 helm: traefik ${TRAEFIK_CHART_VERSION}"
hh upgrade --install traefik traefik/traefik \
  --namespace ingress --create-namespace --version "${TRAEFIK_CHART_VERSION}" \
  --values "${ROOT}/deploy/ingress/traefik-values.yaml" \
  --wait --timeout 10m

log "3/4 CA marketplace.test (cert-manager)"
kk apply -f "${ROOT}/deploy/ingress/issuers.yaml"
kk -n cert-manager wait certificate/marketplace-ca --for=condition=Ready --timeout=120s

log "4/4 certyfikaty hostów i Ingressy"
kk apply -f "${ROOT}/deploy/ingress/ingresses.yaml"
kk -n marketplace wait certificate/marketplace-tls --for=condition=Ready --timeout=120s
kk -n monitoring wait certificate/grafana-tls --for=condition=Ready --timeout=120s
kk get ingress -A

log "OK. Dostęp z Maca: make k3s-ingress-tunnel (wypisze wpis do /etc/hosts i ścieżkę CA)"
