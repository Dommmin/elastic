#!/usr/bin/env bash
#  tools/k3d/install-operators.sh — operatory na klastrze k3d `elastic-g5` (ETAP G5).
#  Teraz: ECK 3.5.0 z deploy/k8s/vendor/eck-3.5.0 (ten sam wendor co na VPS,
#  tools/k3s/install-eck.sh). Task 3 dopisuje cert-manager i operatory RabbitMQ.
#  Idempotentny. CRD przez --server-side: definicje ECK (~800 KB) nie mieszczą się
#  w adnotacji last-applied (limit 256 KB).
set -uo pipefail
# shellcheck source=tools/k3d/lib.sh
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

ECK_DIR="$REPO_ROOT/deploy/k8s/vendor/eck-3.5.0"
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

install_eck
