#!/usr/bin/env bash
# ============================================================================
#  compose-prod-check — czy nakładka prod daje stack, który da się uruchomić
#  na serwerze BEZ repo (ETAP D, Task 2).
#
#  Sprawdza wynik `docker compose config` (to, co compose faktycznie
#  uruchomi po scaleniu obu plików), a nie same pliki.
# ============================================================================
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
RED='\033[0;31m'; GRN='\033[0;32m'; BLD='\033[1m'; DIM='\033[2m'; NC='\033[0m'
PASS=0; FAIL=0
check() { if [ "$2" = "$3" ]; then echo -e "  ${GRN}✓${NC} $1"; PASS=$((PASS+1)); else echo -e "  ${RED}✗${NC} $1  ${DIM}(oczekiwano: $2, otrzymano: $3)${NC}"; FAIL=$((FAIL+1)); fi; }

WORK="$(mktemp -d)"; trap 'rm -rf "${WORK}"' EXIT

echo -e "\n${BLD}  gen-env.sh${NC}"
"${ROOT}/tools/vps/gen-env.sh" "${ROOT}/.env.prod.example" "${WORK}/.env" >/dev/null
check "brak __GENERATE__ po generowaniu"   "0"   "$(grep -c '=__GENERATE__$' "${WORK}/.env")"
check "uprawnienia 600"                    "600" "$(stat -f '%Lp' "${WORK}/.env" 2>/dev/null || stat -c '%a' "${WORK}/.env")"
check "APP_KEY w formacie Laravela"        "1"   "$(grep -cE '^CATALOG_APP_KEY=base64:.{44}$' "${WORK}/.env")"
check "każdy sekret inny"                  "0"   "$(grep -E '^[A-Z_]+=[0-9a-f]{48}$' "${WORK}/.env" | cut -d= -f2 | sort | uniq -d | wc -l | tr -d ' ')"
"${ROOT}/tools/vps/gen-env.sh" "${ROOT}/.env.prod.example" "${WORK}/.env" >/dev/null 2>&1
check "drugie wywołanie NIE nadpisuje (exit 1)" "1" "$?"

echo -e "\n${BLD}  docker compose config (compose.yaml + compose.prod.yaml)${NC}"
# Tylko 2 pliki compose + .env — dokładnie to, co będzie na serwerze.
cp "${ROOT}/compose.yaml" "${ROOT}/compose.prod.yaml" "${WORK}/"
CFG="${WORK}/config.json"
(cd "${WORK}" && IMAGE_TAG=abc123 docker compose -f compose.yaml -f compose.prod.yaml \
  --profile cluster --profile apps config --format json > "${CFG}" 2>"${WORK}/err")
check "config bez błędów" "0" "$?"
[ -s "${WORK}/err" ] && sed 's/^/      /' "${WORK}/err"

q() { python3 -c "import json,sys; c=json.load(open('${CFG}')); $1"; }
check "żadna usługa nie ma build:" "0" "$(q 'print(sum(1 for s in c["services"].values() if "build" in s))')"
check "zero bind-mountów (repo nie ma na serwerze)" "0" \
  "$(q 'print(sum(1 for s in c["services"].values() for v in s.get("volumes",[]) if v.get("type")=="bind"))')"
check "obrazy własne z ghcr.io/dommmin/elastic-*:abc123" "es-init es01 es02 es03 es-setup postgres rabbitmq catalog-app outbox-publisher search-consumer" \
  "$(q 'print(" ".join(n for n in ["es-init","es01","es02","es03","es-setup","postgres","rabbitmq","catalog-app","outbox-publisher","search-consumer"] if c["services"][n]["image"].startswith("ghcr.io/dommmin/elastic-") and c["services"][n]["image"].endswith(":abc123")))')"
check "catalog-vite nie startuje" "nie" "$(q 'print("tak" if "catalog-vite" in c["services"] else "nie")')"
check "wszystkie porty tylko na 127.0.0.1" "0" \
  "$(q 'print(sum(1 for s in c["services"].values() for p in s.get("ports",[]) if p.get("host_ip")!="127.0.0.1"))')"
check "VITE_DEV_SERVER usunięty z catalog-app" "nie" "$(q 'print("tak" if "VITE_DEV_SERVER" in c["services"]["catalog-app"]["environment"] else "nie")')"
check "klucze Kibany z .env, nie stałe z repo" "0" \
  "$(q 'print(sum(1 for k,v in c["services"]["kibana"]["environment"].items() if "ENCRYPTIONKEY" in k and "local" in v))')"
check "catalog: APP_DEBUG=false" "false" "$(q 'print(c["services"]["catalog-app"]["environment"]["APP_DEBUG"])')"

echo -e "\n  PASS=${PASS} FAIL=${FAIL}\n"
[ "${FAIL}" -eq 0 ]
