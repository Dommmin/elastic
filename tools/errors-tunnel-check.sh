#!/usr/bin/env bash
# ============================================================================
#  Test tunelu /_errors (ETAP 11b, ADR-0009) na JEDNORAZOWYM kontenerze:
#  obraz catalog + bieżący infra/caddy/Caddyfile zamontowany z repo, bez
#  przebudowy obrazu i bez ruszania działającego catalog-app.
#
#  Wymaga: działający GlitchTip (make up-errors) i sieć `marketplace`.
#  Sprawdza: 405 dla GET, 413 dla ciała > 1 MB, 204 przy pustym kluczu,
#  koperta z cudzym DSN ląduje w projekcie frontendu (cel na sztywno),
#  a przy martwym GlitchTipie odpowiedź przychodzi szybko (502, nie zawieszenie).
# ============================================================================
set -uo pipefail
cd "$(dirname "$0")/.."
[ -f .env ] && { set -a; . ./.env; set +a; }

IMAGE="${CATALOG_IMAGE:-marketplace-catalog-app:latest}"
NETWORK="${COMPOSE_PROJECT_NAME:-marketplace}_marketplace"
PORT="${TUNNEL_CHECK_PORT:-19443}"
NAME="errors-tunnel-check"
GT_PORT="${GLITCHTIP_PORT:-18000}"
FAIL=0

ok()   { echo "  ✓ $*"; }
fail() { echo "  ✗ $*"; FAIL=1; }

start_caddy() {  # $1 = klucz frontendu (pusty = tunel wyłączony), $2 = upstream
  docker rm -f "$NAME" >/dev/null 2>&1
  docker run -d --name "$NAME" --network "$NETWORK" -p "127.0.0.1:${PORT}:443" \
    -e SERVER_NAME=catalog.localhost \
    -e GLITCHTIP_FRONTEND_PROJECT_ID=2 \
    -e GLITCHTIP_FRONTEND_KEY="$1" \
    -e GLITCHTIP_UPSTREAM="$2" \
    -v "$PWD/infra/caddy/Caddyfile:/etc/frankenphp/Caddyfile:ro" \
    "$IMAGE" >/dev/null
  for _ in $(seq 1 30); do
    curl -sk --resolve "catalog.localhost:${PORT}:127.0.0.1" -o /dev/null "https://catalog.localhost:${PORT}/_errors" && return 0
    sleep 1
  done
  echo "Caddy nie wystartował:"; docker logs "$NAME" 2>&1 | tail -15; return 1
}

tunnel() {  # curl do tunelu; reszta argumentów przekazana do curl
  curl -sk --resolve "catalog.localhost:${PORT}:127.0.0.1" "$@" "https://catalog.localhost:${PORT}/_errors"
}

issue_count() {  # liczba issues w projekcie o danym slugu (API GlitchTipa)
  curl -s -H "Authorization: Bearer ${GLITCHTIP_API_TOKEN}" \
    "http://localhost:${GT_PORT}/api/0/projects/marketplace/$1/issues/?query=$2" \
    | python3 -c 'import sys,json; print(len(json.load(sys.stdin)))'
}

trap 'docker rm -f "$NAME" >/dev/null 2>&1' EXIT

echo "Tunel /_errors"
start_caddy "$GLITCHTIP_KEY_FRONTEND" "glitchtip:8000" || exit 1

code=$(tunnel -o /dev/null -w '%{http_code}' -X GET)
[ "$code" = "405" ] && ok "GET → 405" || fail "GET → $code (oczekiwano 405)"

head -c 2000000 /dev/zero | tr '\0' 'a' > /tmp/errors-tunnel-big.txt
code=$(tunnel -o /dev/null -w '%{http_code}' -X POST --data-binary @/tmp/errors-tunnel-big.txt)
[ "$code" = "413" ] && ok "ciało 2 MB → 413" || fail "ciało 2 MB → $code (oczekiwano 413)"

# Koperta w formacie Sentry, ale z DSN projektu `search`: tunel i tak wpisuje cel na sztywno.
MARKER="tunnel-check-$(date +%s)"
EVENT_ID=$(python3 -c 'import uuid; print(uuid.uuid4().hex)')
SENTAT=$(date -u +%Y-%m-%dT%H:%M:%SZ)
ENVELOPE=$(printf '{"event_id":"%s","sent_at":"%s","dsn":"http://%s@glitchtip:8000/3"}\n{"type":"event"}\n{"event_id":"%s","timestamp":"%s","platform":"javascript","level":"error","message":"%s","exception":{"values":[{"type":"Error","value":"%s"}]}}\n' \
  "$EVENT_ID" "$SENTAT" "$GLITCHTIP_KEY_SEARCH" "$EVENT_ID" "$SENTAT" "$MARKER" "$MARKER")
code=$(tunnel -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: text/plain;charset=UTF-8' --data-binary "$ENVELOPE")
[ "$code" = "200" ] && ok "koperta → 200" || fail "koperta → $code (oczekiwano 200)"

found_frontend=0; found_search=0
for _ in $(seq 1 30); do
  found_frontend=$(issue_count catalog-frontend "$MARKER")
  [ "$found_frontend" -ge 1 ] && break
  sleep 2
done
found_search=$(issue_count search "$MARKER")
[ "$found_frontend" -ge 1 ] && ok "zdarzenie w projekcie catalog-frontend" || fail "brak zdarzenia w catalog-frontend"
[ "$found_search" -eq 0 ] && ok "nic w projekcie search (cel na sztywno)" || fail "zdarzenie wylądowało w search: tunel jest otwartym przekaźnikiem"

echo "Tunel wyłączony (pusty klucz)"
start_caddy "" "glitchtip:8000" || exit 1
code=$(tunnel -o /dev/null -w '%{http_code}' -X POST --data-binary 'x')
[ "$code" = "204" ] && ok "pusty klucz → 204" || fail "pusty klucz → $code (oczekiwano 204)"

echo "GlitchTip nieosiągalny"
start_caddy "$GLITCHTIP_KEY_FRONTEND" "glitchtip-nie-istnieje:8000" || exit 1
read -r code total < <(tunnel -o /dev/null -w '%{http_code} %{time_total}\n' -X POST --data-binary 'x')
if [ "$code" = "502" ] && awk -v t="$total" 'BEGIN{exit !(t < 3)}'; then
  ok "martwy upstream → 502 w ${total}s"
else
  fail "martwy upstream → $code w ${total}s (oczekiwano 502 poniżej 3 s)"
fi

[ "$FAIL" -eq 0 ] && echo "OK: tunel /_errors działa" || { echo "BŁĄD: tunel /_errors"; exit 1; }
