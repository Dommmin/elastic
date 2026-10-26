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

echo -e "\n${BLD}  GlitchTip: sekrety z gen-env.sh${NC}"
# Klucze DSN muszą być UUID-em w hex (32 znaki), token API ma 64 znaki; DSN-y składają się
# z wygenerowanych kluczy (__REF:…__), a nie z wartości deweloperskich z .env.example.
GENV="${WORK}/.env"
val() { grep -m1 "^$1=" "${GENV}" | cut -d= -f2-; }
check "3 klucze DSN w formacie UUID hex (32 znaki)" "3" "$(grep -cE '^GLITCHTIP_KEY_(CATALOG|FRONTEND|SEARCH)=[0-9a-f]{32}$' "${GENV}")"
check "klucze DSN różne od siebie"          "3"  "$(grep -E '^GLITCHTIP_KEY_' "${GENV}" | cut -d= -f2 | sort -u | wc -l | tr -d ' ')"
check "token API: 64 znaki hex"             "1"  "$(grep -cE '^GLITCHTIP_API_TOKEN=[0-9a-f]{64}$' "${GENV}")"
check "SECRET_KEY GlitchTipa wygenerowany"  "1"  "$(grep -cE '^GLITCHTIP_SECRET_KEY=[0-9a-f]{64}$' "${GENV}")"
check "hasła admina i bazy wygenerowane"    "2"  "$(grep -cE '^GLITCHTIP_(ADMIN|DB)_PASSWORD=[0-9a-f]{48}$' "${GENV}")"
check "brak niepodstawionych __REF"         "0"  "$(grep -c '__REF' "${GENV}")"
check "DSN catalog = klucz catalog, projekt 1"   "http://$(val GLITCHTIP_KEY_CATALOG)@glitchtip:8000/1"  "$(val GLITCHTIP_DSN_CATALOG)"
check "DSN frontend = klucz frontend, projekt 2" "http://$(val GLITCHTIP_KEY_FRONTEND)@glitchtip:8000/2" "$(val GLITCHTIP_DSN_FRONTEND)"
check "DSN search = klucz search, projekt 3"     "http://$(val GLITCHTIP_KEY_SEARCH)@glitchtip:8000/3"   "$(val GLITCHTIP_DSN_SEARCH)"
check "żaden klucz nie jest deweloperski z .env.example" "0" \
  "$(grep -cE '^GLITCHTIP_KEY_.*=0c5a1d4e8b7f4a2c9e3d6f1a5b8c7d0' "${GENV}")"

echo -e "\n${BLD}  GlitchTip: strażnik bootstrapu (infra/glitchtip/guard.py)${NC}"
# Poza localhostem bootstrap nie może wystartować z kluczami, tokenem i hasłami z repo
# (są publiczne). Strażnik to czysty Python bez zależności, więc testujemy go na hoście.
guard() {  # $1 = plik .env z wartościami, $2 = GLITCHTIP_DOMAIN
  (set -a; . "$1"; set +a; GLITCHTIP_DOMAIN="$2" SECRET_KEY="${GLITCHTIP_SECRET_KEY:-}" \
    python3 "${ROOT}/infra/glitchtip/guard.py" >/dev/null 2>&1; echo $?)
}
sed 's/^GLITCHTIP_ADMIN_EMAIL=__SET_ME__$/GLITCHTIP_ADMIN_EMAIL=admin@example.com/' "${GENV}" > "${WORK}/prod.env"
check "wygenerowane wartości + domena produkcyjna → przechodzi"  "0" "$(guard "${WORK}/prod.env" https://errors.example.com)"
check "wartości deweloperskie + domena produkcyjna → odmowa"     "1" "$(guard "${ROOT}/.env.example" https://errors.example.com)"
check "wartości deweloperskie + localhost → przechodzi"          "0" "$(guard "${ROOT}/.env.example" http://localhost:18000)"
check "niewypełniony adres admina (__SET_ME__) → odmowa"          "1" "$(guard "${GENV}" https://errors.example.com)"

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
check "obrazy własne z ghcr.io/dommmin/elastic-*:abc123" "es-init es01 es02 es03 es-setup postgres rabbitmq catalog-app outbox-publisher catalog-worker search-consumer" \
  "$(q 'print(" ".join(n for n in ["es-init","es01","es02","es03","es-setup","postgres","rabbitmq","catalog-app","outbox-publisher","catalog-worker","search-consumer"] if c["services"][n]["image"].startswith("ghcr.io/dommmin/elastic-") and c["services"][n]["image"].endswith(":abc123")))')"
check "catalog-vite nie startuje" "nie" "$(q 'print("tak" if "catalog-vite" in c["services"] else "nie")')"
check "wszystkie porty tylko na 127.0.0.1" "0" \
  "$(q 'print(sum(1 for s in c["services"].values() for p in s.get("ports",[]) if p.get("host_ip")!="127.0.0.1"))')"
check "VITE_DEV_SERVER usunięty z catalog-app" "nie" "$(q 'print("tak" if "VITE_DEV_SERVER" in c["services"]["catalog-app"]["environment"] else "nie")')"
check "klucze Kibany z .env, nie stałe z repo" "0" \
  "$(q 'print(sum(1 for k,v in c["services"]["kibana"]["environment"].items() if "ENCRYPTIONKEY" in k and "local" in v))')"
check "catalog: APP_DEBUG=false" "false" "$(q 'print(c["services"]["catalog-app"]["environment"]["APP_DEBUG"])')"
check "catalog: DSN śledzenia błędów z .env (projekt 1)" "1" \
  "$(q 'import re; print(1 if re.fullmatch(r"http://[0-9a-f]{32}@glitchtip:8000/1", c["services"]["catalog-app"]["environment"]["SENTRY_LARAVEL_DSN"]) else 0)')"
check "tunel błędów: klucz frontendu z .env w catalog-app" "1" \
  "$(q 'import re; print(1 if re.fullmatch(r"[0-9a-f]{32}", c["services"]["catalog-app"]["environment"]["GLITCHTIP_FRONTEND_KEY"]) else 0)')"
check "ERROR_TRACKING_PROCESS w usługach prod" "catalog-app=http catalog-worker=queue outbox-publisher=outbox-publisher search-consumer=consumer" \
  "$(q 'print(" ".join("%s=%s" % (n, c["services"][n]["environment"].get("ERROR_TRACKING_PROCESS","")) for n in ["catalog-app","catalog-worker","outbox-publisher","search-consumer"]))')"

echo -e "\n  PASS=${PASS} FAIL=${FAIL}\n"
[ "${FAIL}" -eq 0 ]
