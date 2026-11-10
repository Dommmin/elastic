#!/usr/bin/env bash
#  tools/k3d/down.sh — usuwa klaster k3d `elastic-g5` (ETAP G5). Idempotentny.
set -uo pipefail
# shellcheck source=tools/k3d/lib.sh
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

command -v k3d >/dev/null 2>&1 || die "brak k3d (brew install k3d)"
if cluster_exists; then
  k3d cluster delete "$CLUSTER" || die "k3d cluster delete nie powiodło się"
else
  log "klastra $CLUSTER nie ma — nic do usunięcia"
fi
