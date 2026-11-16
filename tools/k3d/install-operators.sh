#!/usr/bin/env bash
#  tools/k3d/install-operators.sh — operatory na klastrze k3d `elastic-g5` (ETAP G5).
#  Kolejność: ECK 3.5.0 (deploy/k8s/vendor/eck-3.5.0, ten sam wendor co na VPS,
#  tools/k3s/install-eck.sh) -> cert-manager v1.21.2 (helm, wersja jak na VPS,
#  tools/k3s/install-ingress.sh; potrzebny topology-operatorowi do certyfikatu
#  webhooka) -> RabbitMQ cluster-operator -> messaging-topology-operator.
#  Idempotentny. CRD przez --server-side: definicje ECK (~800 KB) nie mieszczą się
#  w adnotacji last-applied (limit 256 KB).
set -uo pipefail
# shellcheck source=tools/k3d/lib.sh
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

ECK_DIR="$REPO_ROOT/deploy/k8s/vendor/eck-3.5.0"
CERT_MANAGER_VERSION="v1.21.2"
RMQ_OP_DIR="$REPO_ROOT/deploy/k8s/vendor/rabbitmq-cluster-operator-2.23.2"
TOPOLOGY_DIR="$REPO_ROOT/deploy/k8s/vendor/messaging-topology-operator-1.20.5"
# helm z jawnym kontekstem (jak kk/kc: goły klient celuje w obcy klaster).
hh() { helm --kube-context "$CTX" "$@"; }
command -v helm >/dev/null 2>&1 || die "brak helm (brew install helm)"
cluster_exists || die "klaster $CLUSTER nie istnieje (make g5-up)"

install_eck() {
  log "ECK: CRD (server-side)"
  kc apply --server-side -f "$ECK_DIR/crds.yaml" >/dev/null || die "apply CRD ECK nie powiódł się"
  log "ECK: operator"
  kc apply -f "$ECK_DIR/operator.yaml" >/dev/null || die "apply operatora ECK nie powiódł się"
  kc -n elastic-system rollout status statefulset/elastic-operator --timeout=300s >/dev/null \
    || die "operator ECK nie wstał w 300 s"
  log "ECK gotowy"
}

# Bez ServiceMonitor (na k3d nie ma CRD Prometheus Operatora), w odróżnieniu od VPS.
install_cert_manager() {
  log "cert-manager $CERT_MANAGER_VERSION (helm)"
  hh repo add jetstack https://charts.jetstack.io >/dev/null 2>&1 || true
  hh repo update jetstack >/dev/null 2>&1 || log "uwaga: helm repo update nie powiódł się, używam lokalnego indeksu"
  hh upgrade --install cert-manager jetstack/cert-manager \
    --namespace cert-manager --create-namespace --version "$CERT_MANAGER_VERSION" \
    --set crds.enabled=true --set crds.keep=true \
    --wait --timeout 5m >/dev/null || die "helm cert-manager nie powiódł się"
  log "cert-manager gotowy"
}

# install_rmq_operator <nazwa> <plik> <deployment> — apply wendorowanego manifestu i czekanie na Available.
install_rmq_operator() {
  local name="$1" file="$2" dep="$3"
  log "$name: apply"
  kc apply --server-side --force-conflicts -f "$file" >/dev/null || die "apply $name nie powiódł się"
  kc -n rabbitmq-system wait --for=condition=Available "deployment/$dep" --timeout=300s >/dev/null \
    || die "$name nie jest Available po 300 s"
  log "$name gotowy"
}

install_eck
install_cert_manager
install_rmq_operator "RabbitMQ cluster-operator" "$RMQ_OP_DIR/cluster-operator.yml" rabbitmq-cluster-operator
# Webhook topology-operatora potrzebuje działającego webhooka cert-managera (--wait wyżej).
install_rmq_operator "messaging-topology-operator" "$TOPOLOGY_DIR/messaging-topology-operator-with-certmanager.yaml" messaging-topology-operator
