#!/usr/bin/env bash
#  tools/k3d/up.sh — klaster k3d `elastic-g5` (1 server + 2 agenty), ETAP G5.
#  Idempotentny: działający klaster z 3 węzłami Ready = tylko sprawdzenie.
#  Odmawia tworzenia I startu zatrzymanego klastra, gdy Docker VM ma mniej wolnej
#  pamięci niż G5_MIN_FREE_MB (spec § 8).
set -uo pipefail
# shellcheck source=tools/k3d/lib.sh
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

K3S_IMAGE="rancher/k3s:v1.36.5-k3s1"
MIN_FREE_MB="${G5_MIN_FREE_MB:-6000}"

command -v k3d >/dev/null 2>&1 || die "brak k3d (brew install k3d)"

nodes_ready() {
  [ "$(kc get nodes --no-headers 2>/dev/null | awk '$2=="Ready"' | wc -l | tr -d ' ')" = "3" ]
}

# Odmowa, gdy brakuje pamięci; osobny błąd, gdy pamięci nie da się odczytać.
check_memory() {
  local free
  free="$(docker_free_mb)" || die "nie udało się odczytać pamięci Docker VM"
  case "$free" in
    ''|*[!0-9]*) die "nie udało się odczytać pamięci Docker VM (otrzymano: '${free}')" ;;
  esac
  if [ "$free" -lt "$MIN_FREE_MB" ]; then
    die "za mało pamięci w Docker VM: dostępne ${free} MB, wymagane ${MIN_FREE_MB} MB (zatrzymaj Compose: make down)"
  fi
  log "pamięć Docker VM: ${free} MB (próg ${MIN_FREE_MB} MB)"
}

if cluster_exists; then
  if nodes_ready; then
    log "klaster $CLUSTER już działa"
  else
    log "klaster $CLUSTER istnieje, ale nie wszystkie węzły są Ready — sprawdzam pamięć i startuję"
    check_memory
    k3d cluster start "$CLUSTER" || die "k3d cluster start nie powiodło się"
  fi
else
  check_memory
  log "tworzę klaster $CLUSTER"
  k3d cluster create "$CLUSTER" --servers 1 --agents 2 \
    --image "$K3S_IMAGE" --no-lb \
    --k3s-arg "--disable=traefik@server:0" || die "k3d cluster create nie powiodło się"
fi

wait_for 300 nodes_ready || { kc get nodes >&2; die "3 węzły nie są Ready po 300 s"; }
kc get nodes
log "klaster $CLUSTER gotowy (kontekst $CTX)"
