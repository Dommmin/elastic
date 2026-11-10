# shellcheck shell=bash
#  tools/k3d/lib.sh — wspólne funkcje lokalnego klastra k3d (ETAP G5).
#
#  Sourcowany przez każdy skrypt w tools/k3d/ (i przez kolejne zadania G5).
#  Goły `kubectl` na Macu celuje w OBCY klaster (lekcja z G4), więc wszystko
#  idzie przez kk/kc z jawnym --context.
#
#  Zgodność: bash 3.2 (macOS). Bez mapfile, declare -A, ${v,,}.

set -uo pipefail

CLUSTER="elastic-g5"
# Nadpisanie kontekstu tylko przez G5_CTX (nie przez ogólne CTX z powłoki — na tej maszynie
# są inne klastry). Wartość spoza k3d-elastic-g5* wymaga G5_ALLOW_ANY_CTX=1 (testy).
CTX="${G5_CTX:-k3d-elastic-g5}"
case "$CTX" in
  k3d-elastic-g5*) ;;
  *) [ "${G5_ALLOW_ANY_CTX:-}" = "1" ] || { printf '[g5] BŁĄD: G5_CTX=%s nie wygląda na klaster G5 (k3d-elastic-g5*); ustaw G5_ALLOW_ANY_CTX=1, jeśli to celowe\n' "$CTX" >&2; exit 1; } ;;
esac
NS="marketplace"
# Podsieć sieci Dockera klastra — JEDNO źródło prawdy. up.sh tworzy sieć z tą podsiecią,
# deploy.sh i g5-check.rb porównują z nią ipBlock polityki rabbitmq-ha-egress-api
# (deploy/k8s/rabbitmq-cluster/networkpolicies.yaml). Zmiana = popraw też ten plik.
G5_SUBNET="172.22.0.0/16"   # stała, bez nadpisania z env (jedno źródło)
G5_NET="k3d-elastic-g5"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." 2>/dev/null && pwd)" \
  || { printf '[g5] BŁĄD: nie da się ustalić katalogu repo\n' >&2; exit 1; }

# kk: w namespace sklepu; kc: bez namespace (węzły, operatory, CRD).
kk() { kubectl --context "$CTX" -n "$NS" "$@"; }
kc() { kubectl --context "$CTX" "$@"; }

# render: renderuje overlay po stronie klienta (bez klastra — działa też w CI).
render() { kubectl kustomize "$@"; }

log() { printf '[g5] %s\n' "$*"; }
die() { printf '[g5] BŁĄD: %s\n' "$*" >&2; exit 1; }

# wait_for <limit_s> <cmd…> — ponawia co 5 s; 0 przy sukcesie, 1 po limicie.
wait_for() {
  local limit="$1"; shift
  local waited=0
  while ! "$@" >/dev/null 2>&1; do
    if [ "$waited" -ge "$limit" ]; then return 1; fi
    sleep 5
    waited=$((waited + 5))
  done
  return 0
}

# wait_job <nazwa> <limit_s> — czeka na Job: 0 = Complete, 1 = Failed albo limit (szybko przy Failed).
wait_job() {
  local name="$1" limit="$2" waited=0 st
  while :; do
    st="$(kk get job "$name" -o jsonpath='{range .status.conditions[*]}{.type}={.status} {end}' 2>/dev/null)"
    case "$st" in *Complete=True*) return 0 ;; *Failed=True*) return 1 ;; esac
    [ "$waited" -ge "$limit" ] && return 1
    sleep 5; waited=$((waited + 5))
  done
}

# cluster_exists — czy klaster k3d $CLUSTER istnieje (uruchomiony lub zatrzymany).
cluster_exists() {
  k3d cluster list 2>/dev/null | awk 'NR>1 {print $1}' | grep -qx "$CLUSTER"
}

# docker_free_mb — wolna pamięć Docker VM w MB (kolumna `available` z `free -m`,
# wybrana po nazwie nagłówka). Przy błędzie: komunikat docker-a na stderr, kod 1, pusty stdout.
docker_free_mb() {
  local out val
  out="$(docker run --rm --privileged --pid=host alpine:3.20 \
    nsenter -t 1 -m sh -c 'free -m')" || return 1
  val="$(printf '%s\n' "$out" | awk '
    NR==1 { for (i=1;i<=NF;i++) if ($i=="available") col=i; next }
    /^Mem:/ && col { print $(col+1); exit }')"
  [ -n "$val" ] || return 1
  printf '%s\n' "$val"
}

# net_subnet — podsieć(e) sieci Dockera $G5_NET (puste, gdy sieci nie ma).
net_subnet() {
  docker network inspect "$G5_NET" -f '{{range .IPAM.Config}}{{.Subnet}} {{end}}' 2>/dev/null | tr -s ' ' | sed 's/ $//'
}
