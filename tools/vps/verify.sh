#!/usr/bin/env bash
# ============================================================================
#  verify.sh <faza> — weryfikacja ETAPU D, uruchamiana z MACA
#
#  Każda faza odpowiada taskowi z docs/08-PLAN-ETAP-D-VPS.md i jest
#  pisana PRZED wykonaniem taska (czerwona), a po nim ma być zielona.
#
#    images     obrazy w GHCR, publiczne, linux/amd64        (Task 3)
#    access     serwer osiągalny, sprzęt zgodny z wymaganiami (Task 4-5)
#    all        wszystkie fazy po kolei
#
#  Serwer: alias SSH `elastic-vps` (~/.ssh/config). Tag obrazów: TAG=<sha>
#  (domyślnie HEAD z origin/main).
# ============================================================================
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
HOST="${VPS_HOST:-elastic-vps}"
REGISTRY="ghcr.io/dommmin"
IMAGES=(elasticsearch postgres rabbitmq catalog search)
RED='\033[0;31m'; GRN='\033[0;32m'; BLD='\033[1m'; DIM='\033[2m'; NC='\033[0m'
PASS=0; FAIL=0

check() { # check <opis> <oczekiwane> <otrzymane>
  if [ "$2" = "$3" ]; then
    echo -e "  ${GRN}✓${NC} $1"; PASS=$((PASS+1))
  else
    echo -e "  ${RED}✗${NC} $1  ${DIM}(oczekiwano: $2, otrzymano: $3)${NC}"; FAIL=$((FAIL+1))
  fi
}
atleast() { # atleast <opis> <minimum> <otrzymane>
  if [ -n "$3" ] && [ "$3" -ge "$2" ] 2>/dev/null; then
    echo -e "  ${GRN}✓${NC} $1 ${DIM}($3)${NC}"; PASS=$((PASS+1))
  else
    echo -e "  ${RED}✗${NC} $1  ${DIM}(minimum: $2, otrzymano: ${3:-brak})${NC}"; FAIL=$((FAIL+1))
  fi
}
remote() { ssh -o BatchMode=yes -o ConnectTimeout=10 "${HOST}" "$@" 2>/dev/null; }

# --------------------------------------------------------------- images -----
phase_images() {
  local tag="${TAG:-$(git -C "${ROOT}" rev-parse origin/main 2>/dev/null)}"
  echo -e "\n${BLD}  images — ${REGISTRY}/elastic-*:${tag:0:12}${NC}"
  # Pusty DOCKER_CONFIG = brak zalogowania. Jeśli obraz da się pobrać tak,
  # to da się go pobrać na serwerze bez żadnego tokenu (= paczka publiczna).
  local anon; anon="$(mktemp -d)"
  for name in "${IMAGES[@]}"; do
    local platforms
    platforms=$(DOCKER_CONFIG="${anon}" docker manifest inspect "${REGISTRY}/elastic-${name}:${tag}" 2>/dev/null \
      | python3 -c '
import json, sys
m = json.load(sys.stdin)
ps = [f"{x["platform"]["os"]}/{x["platform"]["architecture"]}" for x in m.get("manifests", []) if x.get("platform", {}).get("os") != "unknown"]
print(",".join(ps) if ps else "linux/amd64?")' 2>/dev/null)
    check "elastic-${name}: publiczny, linux/amd64" "linux/amd64" "${platforms:-niedostępny}"
  done
  rm -rf "${anon}"
}

# --------------------------------------------------------------- access -----
phase_access() {
  echo -e "\n${BLD}  access — ${HOST}${NC}"
  check "SSH kluczem (bez hasła)" "0" "$(remote true; echo $?)"
  atleast "vCPU" 4 "$(remote nproc)"
  atleast "RAM [GB]" 23 "$(remote "awk '/MemTotal/ {printf \"%d\", \$2/1024/1024 + 0.5}' /proc/meminfo")"
  atleast "wolne na / [GB]" 140 "$(remote "df -BG --output=avail / | tail -1 | tr -dc 0-9")"
  check "architektura" "x86_64" "$(remote uname -m)"
  check "system" "ubuntu" "$(remote ". /etc/os-release && echo \$ID")"
}

case "${1:-}" in
  images) phase_images ;;
  access) phase_access ;;
  all)    phase_images; phase_access ;;
  *) echo "użycie: $0 {images|access|all}" >&2; exit 2 ;;
esac

echo -e "\n  PASS=${PASS} FAIL=${FAIL}\n"
[ "${FAIL}" -eq 0 ]
