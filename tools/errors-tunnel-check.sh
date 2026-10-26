#!/usr/bin/env bash
# ============================================================================
#  Test tunelu POST /_errors na ŻYWYM catalog-app (ETAP 11b, ADR-0009).
#
#  Tunel to trasa Laravela (ErrorTunnelController); jej logikę pokrywają testy
#  Pest (tests/Feature/ErrorTracking/ErrorTunnelTest.php). Ten skrypt sprawdza
#  to, czego one nie widzą: że Caddy przepuszcza trasę, ciało > 1 MB odpada
#  przed PHP, koperta naprawdę ląduje w projekcie catalog-frontend (cel z
#  configu mimo cudzego klucza w query i nagłówku), a limit liczby żądań działa
#  w stosie. Wymaga: make up-apps, make up-errors.
#
#  Test limitu kończy się czekaniem, aż okno się wyczyści, żeby kolejne uruchomienia
#  (np. make errors-smoke) nie dostały 429.
# ============================================================================
set -uo pipefail
cd "$(dirname "$0")/.."
[ -f .env ] && { set -a; . ./.env; set +a; }

PORT="${CATALOG_HTTPS_PORT:-8443}"
GT_PORT="${GLITCHTIP_PORT:-18000}"
FAIL=0

ok()   { echo "  ✓ $*"; }
fail() { echo "  ✗ $*"; FAIL=1; }

tunnel() {  # curl do tunelu; reszta argumentów przekazana do curl
  curl -sk --resolve "catalog.localhost:${PORT}:127.0.0.1" "$@" "https://catalog.localhost:${PORT}/_errors"
}

issue_count() {  # liczba issues w projekcie o danym slugu (API GlitchTipa)
  curl -s -H "Authorization: Bearer ${GLITCHTIP_API_TOKEN}" \
    "http://localhost:${GT_PORT}/api/0/projects/marketplace/$1/issues/?query=$2" \
    | python3 -c 'import sys,json; print(len(json.load(sys.stdin)))'
}

envelope() {  # $1 = znacznik; koperta z DSN projektu `search` (cudzy klucz)
  local event_id sent_at
  event_id=$(python3 -c 'import uuid; print(uuid.uuid4().hex)')
  sent_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)
  printf '{"event_id":"%s","sent_at":"%s","dsn":"http://%s@glitchtip:8000/3"}\n{"type":"event"}\n{"event_id":"%s","timestamp":"%s","platform":"javascript","level":"error","message":"%s","exception":{"values":[{"type":"Error","value":"%s"}]}}\n' \
    "$event_id" "$sent_at" "$GLITCHTIP_KEY_SEARCH" "$event_id" "$sent_at" "$1" "$1"
}

wait_for_issue() {  # $1 = projekt, $2 = znacznik; czeka do 60 s
  local n=0
  for _ in $(seq 1 30); do
    n=$(issue_count "$1" "$2")
    [ "$n" -ge 1 ] && break
    sleep 2
  done
  echo "$n"
}

echo "Tunel POST /_errors (żywy catalog-app)"

code=$(tunnel -o /dev/null -w '%{http_code}' -X GET)
[ "$code" = "405" ] && ok "GET → 405" || fail "GET → $code (oczekiwano 405)"

# 1,5 MB: powyżej limitu kontrolera (1 MB), poniżej sufitu Caddy (2 MB) → 413 z aplikacji.
head -c 1500000 /dev/zero | tr '\0' 'a' > /tmp/errors-tunnel-big.txt
code=$(tunnel -o /dev/null -w '%{http_code}' -X POST --data-binary @/tmp/errors-tunnel-big.txt)
[ "$code" = "413" ] && ok "ciało 1,5 MB → 413" || fail "ciało 1,5 MB → $code (oczekiwano 413)"

# 3 MB: powyżej sufitu Caddy, odpada zanim PHP je zbuforuje (kod 4xx, zwykle 400).
head -c 3000000 /dev/zero | tr '\0' 'a' > /tmp/errors-tunnel-huge.txt
code=$(tunnel -o /dev/null -w '%{http_code}' -X POST --data-binary @/tmp/errors-tunnel-huge.txt)
case "$code" in 4??) ok "ciało 3 MB → $code (odrzucone przez Caddy)";; *) fail "ciało 3 MB → $code (oczekiwano 4xx)";; esac

code=$(tunnel -o /dev/null -w '%{http_code}' -X POST -H 'Content-Encoding: gzip' --data-binary 'x')
[ "$code" = "415" ] && ok "Content-Encoding: gzip → 415" || fail "Content-Encoding: gzip → $code (oczekiwano 415)"

# Koperta z DSN projektu `search`, z kluczem `search` w query i nagłówku: cel zostaje
# na sztywno w configu, więc zdarzenie ma wylądować w catalog-frontend, nie w search.
MARKER="tunnel-check-$(date +%s)"
code=$(curl -sk --resolve "catalog.localhost:${PORT}:127.0.0.1" -o /dev/null -w '%{http_code}' -X POST \
  -H 'Content-Type: text/plain;charset=UTF-8' \
  -H "X-Sentry-Auth: Sentry sentry_version=7, sentry_key=${GLITCHTIP_KEY_SEARCH}" \
  --data-binary "$(envelope "$MARKER")" \
  "https://catalog.localhost:${PORT}/_errors?sentry_key=${GLITCHTIP_KEY_SEARCH}&glitchtip_key=${GLITCHTIP_KEY_SEARCH}")
[ "$code" = "200" ] && ok "koperta z cudzym kluczem w query i nagłówku → 200" || fail "koperta → $code (oczekiwano 200)"
found_frontend=$(wait_for_issue catalog-frontend "$MARKER")
found_search=$(issue_count search "$MARKER")
[ "$found_frontend" -ge 1 ] && ok "zdarzenie w projekcie catalog-frontend" || fail "brak zdarzenia w catalog-frontend"
[ "$found_search" -eq 0 ] && ok "nic w projekcie search (cel z configu)" || fail "zdarzenie wylądowało w search: tunel jest otwartym przekaźnikiem"

# Limit na IP (20/min): z jednego hosta 25 żądań musi dać co najmniej jedno 429 z Retry-After.
got429=0; retry_after=""
for _ in $(seq 1 25); do
  resp=$(tunnel -o /dev/null -D - -w 'CODE:%{http_code}' -X POST -H 'Content-Type: text/plain' --data-binary 'x')
  if echo "$resp" | grep -q 'CODE:429'; then
    got429=1
    retry_after=$(echo "$resp" | tr -d '\r' | awk -F': ' 'tolower($1)=="retry-after"{print $2}')
    break
  fi
done
[ "$got429" = "1" ] && ok "limit liczby żądań działa (429, Retry-After=${retry_after:-brak})" || fail "25 żądań z rzędu i ani jednego 429: brak limitu"
[ -n "$retry_after" ] || fail "429 bez nagłówka Retry-After"

# Okno limitu trwa minutę; czekamy, aż się wyczyści, żeby nie psuć kolejnych uruchomień.
for _ in $(seq 1 30); do
  code=$(tunnel -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: text/plain' --data-binary 'x')
  [ "$code" != "429" ] && break
  sleep 3
done

[ "$FAIL" -eq 0 ] && echo "OK: tunel /_errors działa" || { echo "BŁĄD: tunel /_errors"; exit 1; }
