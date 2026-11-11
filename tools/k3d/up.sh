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

# Sieć Dockera z przypiętą podsiecią: polityka rabbitmq-ha-egress-api zakłada $G5_SUBNET
# (adres API = węzeł server na 6443). Bez przypięcia Docker wybiera kolejną wolną /16,
# a na tej maszynie są sieci innych projektów — odtworzony klaster mógłby dostać inną.
check_subnet() {
  local got
  got="$(net_subnet)"
  [ "$got" = "$G5_SUBNET" ] || die "sieć $G5_NET ma podsieć '${got}', a polityka sieciowa zakłada $G5_SUBNET. Usuń klaster (make g5-down) i utwórz go od nowa albo zmień G5_SUBNET w tools/k3d/lib.sh i ipBlock w deploy/k8s/rabbitmq-cluster/networkpolicies.yaml"
}

# G5_SUBNET (lib.sh) musi być tym samym, co ipBlock polityki API — inaczej klaster
# wstałby na podsieci, z którą peer discovery RabbitMQ nie działa.
POLICY_FILE="${G5_POLICY_FILE:-$REPO_ROOT/deploy/k8s/rabbitmq-cluster/networkpolicies.yaml}"
POLICY_CIDR="$(grep -E '^[[:space:]]*- to:|ipBlock' "$POLICY_FILE" 2>/dev/null | grep -Eo 'cidr: *[0-9./]+' | sed 's/cidr: *//' | tr '\n' ' ' | sed 's/ $//')"
[ "$POLICY_CIDR" = "$G5_SUBNET" ] \
  || die "G5_SUBNET ($G5_SUBNET, tools/k3d/lib.sh) != ipBlock w $POLICY_FILE ('${POLICY_CIDR}') — ustaw oba na tę samą podsieć"

if cluster_exists; then
  check_subnet
  if nodes_ready; then
    log "klaster $CLUSTER już działa"
  else
    log "klaster $CLUSTER istnieje, ale nie wszystkie węzły są Ready — sprawdzam pamięć i startuję"
    check_memory
    k3d cluster start "$CLUSTER" || die "k3d cluster start nie powiodło się"
  fi
else
  check_memory
  if [ -z "$(net_subnet)" ]; then
    log "tworzę sieć $G5_NET ($G5_SUBNET)"
    docker network create --subnet "$G5_SUBNET" "$G5_NET" >/dev/null \
      || die "docker nie utworzył sieci $G5_NET z podsiecią $G5_SUBNET (koliduje z inną siecią? sprawdź: docker network ls; docker network inspect)"
  fi
  check_subnet
  log "tworzę klaster $CLUSTER"
  k3d cluster create "$CLUSTER" --servers 1 --agents 2 --network "$G5_NET" \
    --image "$K3S_IMAGE" --no-lb \
    --k3s-arg "--disable=traefik@server:0" || die "k3d cluster create nie powiodło się"
fi

wait_for 300 nodes_ready || { kc get nodes >&2; die "3 węzły nie są Ready po 300 s"; }
kc get nodes
log "klaster $CLUSTER gotowy (kontekst $CTX)"
