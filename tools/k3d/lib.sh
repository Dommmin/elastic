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
CTX="k3d-elastic-g5"
NS="marketplace"
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." 2>/dev/null && pwd)"

# kk: w namespace sklepu; kc: bez namespace (węzły, operatory, CRD).
kk() { kubectl --context "$CTX" -n "$NS" "$@"; }
kc() { kubectl --context "$CTX" "$@"; }

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

# docker_free_mb — wolna pamięć Docker VM w MB (kolumna `available` z `free -m`).
docker_free_mb() {
  docker run --rm --privileged --pid=host alpine:3.20 \
    nsenter -t 1 -m sh -c 'free -m' 2>/dev/null \
    | awk '/^Mem:/ { print $7 }'
}
