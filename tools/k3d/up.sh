#!/usr/bin/env bash
#  tools/k3d/up.sh — klaster k3d `elastic-g5` (1 server + 2 agenty), ETAP G5.
#  Idempotentny: istniejący klaster = tylko sprawdzenie, że 3 węzły są Ready.
#  Odmawia startu, gdy Docker VM ma mniej wolnej pamięci niż G5_MIN_FREE_MB (spec § 8).
set -uo pipefail
# shellcheck source=tools/k3d/lib.sh
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

K3S_IMAGE="rancher/k3s:v1.36.5-k3s1"
MIN_FREE_MB="${G5_MIN_FREE_MB:-6000}"

command -v k3d >/dev/null 2>&1 || die "brak k3d (brew install k3d)"

nodes_ready() {
  [ "$(kc get nodes --no-headers 2>/dev/null | awk '$2=="Ready"' | wc -l | tr -d ' ')" = "3" ]
}

if k3d cluster list 2>/dev/null | awk 'NR>1 {print $1}' | grep -qx "$CLUSTER"; then
  log "klaster $CLUSTER już istnieje — sprawdzam węzły"
  # Po restarcie Dockera klaster bywa zatrzymany.
  k3d cluster start "$CLUSTER" >/dev/null 2>&1 || true
else
  free="$(docker_free_mb)"
  free="${free:-0}"
  if [ "$free" -lt "$MIN_FREE_MB" ]; then
    die "za mało pamięci w Docker VM: dostępne ${free} MB, wymagane ${MIN_FREE_MB} MB (zatrzymaj Compose: make down)"
  fi
  log "pamięć Docker VM: ${free} MB (próg ${MIN_FREE_MB} MB) — tworzę klaster $CLUSTER"
  k3d cluster create "$CLUSTER" --servers 1 --agents 2 \
    --image "$K3S_IMAGE" --no-lb \
    --k3s-arg "--disable=traefik@server:0" || die "k3d cluster create nie powiodło się"
fi

wait_for 300 nodes_ready || { kc get nodes >&2; die "3 węzły nie są Ready po 300 s"; }
kc get nodes
log "klaster $CLUSTER gotowy (kontekst $CTX)"
