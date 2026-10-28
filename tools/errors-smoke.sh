#!/usr/bin/env bash
# ============================================================================
#  make errors-smoke — wyjątki ze WSZYSTKICH źródeł docierają do GlitchTipa
#  (ETAP 11b, ADR-0009). Na żywym stosie: wywołuje błąd z żądania HTTP, z kolejki,
#  z aplikacji search i z przeglądarki, a potem pyta API GlitchTipa, czy każdy
#  jest issue we właściwym projekcie z tagami trace_id i process.
#
#  Wymaga: make up-apps (catalog-app, catalog-worker, search-consumer),
#  make up-errors oraz make errors-sourcemaps (build frontu ze stroną debug).
#  Opcjonalnie profil obs: wtedy sprawdza też trace.id w logach ES.
#
#  Na końcu zatrzymuje GlitchTip i mierzy, ile trwa odpowiedź z błędem
#  (awaria GlitchTipa nie może wydłużać żądań), po czym go wznawia.
# ============================================================================
set -uo pipefail
cd "$(dirname "$0")/.."
[ -f .env ] && { set -a; . ./.env; set +a; }

DC="docker compose"
GT_CONTAINER="${COMPOSE_PROJECT_NAME:-marketplace}-glitchtip-1"
. tools/lib/glitchtip-restore.sh
gt_install_exit_trap
HTTPS_PORT="${CATALOG_HTTPS_PORT:-8443}"
GT_PORT="${GLITCHTIP_PORT:-18000}"
MAILPIT_PORT="${MAILPIT_UI_PORT:-8025}"
M="smoke$(date +%s)"
FAIL=0

ok()   { echo "  ✓ $*"; }
fail() { echo "  ✗ $*"; FAIL=1; }
need() { command -v "$1" >/dev/null 2>&1 || { echo "Brak polecenia: $1"; exit 2; }; }
need curl; need python3; need node; need npm

gt() { curl -s -H "Authorization: Bearer ${GLITCHTIP_API_TOKEN}" "http://localhost:${GT_PORT}/api/0/$1"; }

# Id issue o danym tytule w projekcie (po chwili, bo zdarzenia przetwarza worker).
# Szukamy po samym znaczniku (bez dwukropków: API traktuje `a:b` jako filtr
# klucz:wartość), a właściwy issue wybieramy po pełnym tytule.
find_issue() {  # $1 = slug projektu, $2 = pełny tytuł (errors-smoke:<źródło>:<znacznik>)
  local id="" marker="${2##*:}"
  for _ in $(seq 1 45); do
    id=$(gt "projects/marketplace/$1/issues/?query=${marker}" | python3 -c '
import sys, json
want = sys.argv[1]
for issue in json.load(sys.stdin):
    if want in (issue.get("title") or "") or want in (issue.get("metadata", {}).get("value") or ""):
        print(issue["id"]); break' "$2" 2>/dev/null)
    [ -n "$id" ] && { echo "$id"; return 0; }
    sleep 2
  done
  return 1
}

# Wartość tagu z najnowszego zdarzenia issue.
tag_of() {  # $1 = id issue, $2 = klucz tagu
  gt "issues/$1/events/latest/" | python3 -c '
import sys, json
key = sys.argv[1]
tags = json.load(sys.stdin).get("tags") or []
print(next((t["value"] for t in tags if t["key"] == key), ""))' "$2"
}

check_issue() {  # $1 = opis, $2 = projekt, $3 = znacznik, $4 = oczekiwany process, [$5 = oczekiwany trace_id]
  local id process trace
  if ! id=$(find_issue "$2" "$3"); then fail "$1: brak issue w projekcie $2"; return; fi
  process=$(tag_of "$id" process)
  trace=$(tag_of "$id" trace_id)
  if [ -n "$4" ] && [ "$process" != "$4" ]; then fail "$1: tag process='$process', oczekiwano '$4'"; return; fi
  if [ "$2" != "catalog-frontend" ] && [ -z "$trace" ]; then fail "$1: brak tagu trace_id"; return; fi
  if [ -n "${5:-}" ] && [ "$trace" != "$5" ]; then fail "$1: trace_id='$trace', oczekiwano '$5'"; return; fi
  ok "$1 → issue $id w $2 (process=${process:-—}, trace_id=${trace:-—})"
}

echo "Przygotowanie"
curl -fsS -o /dev/null "http://localhost:${GT_PORT}/_health/" || { echo "GlitchTip nie odpowiada (make up-errors)"; exit 1; }
for svc in catalog-app catalog-worker search-consumer; do
  $DC ps --status running --services 2>/dev/null | grep -qx "$svc" || { echo "Nie działa $svc (make up-apps)"; exit 1; }
done
[ -f apps/catalog/public/hot ] && echo "  ! public/hot istnieje (działa serwer Vite): front pójdzie z dev servera, bez source map"
grep -q 'resources/js/pages/debug/ErrorsSmoke.vue' apps/catalog/public/build/manifest.json 2>/dev/null \
  || { echo "Front nie zawiera strony debug: uruchom make errors-sourcemaps"; exit 1; }
ok "stos działa, front zbudowany (znacznik: $M)"

echo "Żądanie HTTP (catalog)"
HEADERS=$(curl -sk -D - -o /dev/null --resolve "catalog.localhost:${HTTPS_PORT}:127.0.0.1" \
  "https://catalog.localhost:${HTTPS_PORT}/_debug/errors/http?m=${M}&email=jan.kowalski%40example.com&token=SECRETTOKEN123")
HTTP_TRACE=$(echo "$HEADERS" | tr -d '\r' | awk -F': ' 'tolower($1)=="x-trace-id"{print $2}')
echo "$HEADERS" | head -1 | grep -q " 500" && ok "HTTP 500, X-Trace-Id=$HTTP_TRACE" || fail "oczekiwano HTTP 500: $(echo "$HEADERS" | head -1)"

echo "Kolejka (catalog-worker)"
$DC exec -T catalog-app php artisan errors:smoke "${M}" --queue >/dev/null 2>&1 && ok "zadanie wrzucone" || fail "nie udało się wrzucić zadania"

echo "Search (Symfony)"
$DC exec -T search-consumer php bin/console app:errors:smoke "${M}" >/dev/null 2>&1
ok "wyjątek rzucony w search-consumer"

echo "Przeglądarka (Vue przez tunel /_errors)"
TMP=$(mktemp -d)
npm install --prefix "$TMP" playwright-core@1.64.0 --no-audit --no-fund --ignore-scripts >/dev/null 2>&1
cp tools/errors-smoke-browser.mjs "$TMP/"
(cd "$TMP" && node errors-smoke-browser.mjs "https://catalog.localhost:${HTTPS_PORT}/_debug/errors/frontend?m=${M}&email=jan.kowalski%40example.com&token=SECRETTOKEN123") \
  && ok "przeglądarka wysłała kopertę" || fail "przeglądarka nie wysłała koperty"

echo "Issues w GlitchTipie"
check_issue "HTTP"       catalog          "errors-smoke:http:${M}"     http   "$HTTP_TRACE"
check_issue "kolejka"    catalog          "errors-smoke:queue:${M}"    queue
check_issue "search"     search           "errors-smoke:search:${M}"   consumer
check_issue "przeglądarka" catalog-frontend "errors-smoke:frontend:${M}" ""

# Sekrety z adresu (e-mail i token w query stringu, jak w linku resetu hasła) nie mogą
# trafić do zdarzenia ani z backendu, ani z przeglądarki (ADR-0009, zasada 6).
for pair in "catalog:errors-smoke:http" "catalog-frontend:errors-smoke:frontend"; do
  proj="${pair%%:*}"; kind="${pair#*:}"
  sid=$(find_issue "$proj" "${kind}:${M}" || true)
  if [ -z "$sid" ]; then fail "wyciek URL ($proj): brak issue do sprawdzenia"; continue; fi
  if gt "issues/$sid/events/latest/" | grep -qE 'jan\.kowalski|jan%40|SECRETTOKEN123'; then
    fail "wyciek URL ($proj): e-mail albo token z adresu jest w zdarzeniu"
  else
    ok "zdarzenie z $proj bez e-maila i tokenu z adresu"
  fi
done

# Źródła w stack trace'ie frontu mają być zmapowane (Debug IDs), nie wzięte z bundla.
FID=$(find_issue catalog-frontend "errors-smoke:frontend:${M}" || true)
if [ -n "$FID" ] && gt "issues/$FID/events/latest/" | grep -q 'ErrorsSmoke.vue'; then
  ok "ramki frontu zmapowane do ErrorsSmoke.vue"
else
  fail "ramki frontu nie są zmapowane (make errors-sourcemaps; RUNBOOK #072)"
fi

echo "Korelacja z logami (opcjonalnie)"
if [ -n "$HTTP_TRACE" ] && $DC ps --status running --services 2>/dev/null | grep -qx filebeat; then
  sleep 5  # Filebeat wysyła partiami
  HITS=$(curl -s -u "elastic:${ELASTIC_PASSWORD}" -H 'Content-Type: application/json' "http://localhost:${ES_PORT:-9200}/logs-app-*/_count" \
    -d "{\"query\":{\"term\":{\"trace.id\":\"${HTTP_TRACE}\"}}}" | python3 -c 'import sys,json; print(json.load(sys.stdin).get("count",0))' 2>/dev/null)
  [ "${HITS:-0}" -ge 1 ] && ok "trace.id $HTTP_TRACE jest w logs-app-* (${HITS} wpisów)" || fail "trace.id $HTTP_TRACE nie ma w logs-app-*"
else
  echo "  - pominięto (profil obs nie działa)"
fi

echo "Mail o nowym issue (mailpit)"
MAILS=0
for _ in $(seq 1 60); do
  MAILS=$(curl -s "http://localhost:${MAILPIT_PORT}/api/v1/search?query=${M}" | python3 -c 'import sys,json; print(json.load(sys.stdin).get("messages_count",0))' 2>/dev/null)
  [ "${MAILS:-0}" -ge 1 ] && break
  sleep 3
done
[ "${MAILS:-0}" -ge 1 ] && ok "mailpit: ${MAILS} wiadomości z alertem" || fail "brak maila z alertem w mailpicie"

echo "Tunel i odporność"
bash tools/errors-tunnel-check.sh >/tmp/errors-tunnel.log 2>&1 && ok "tunel: 405/413/415/cel z configu/limit 429" || { fail "tunel (szczegóły: /tmp/errors-tunnel.log)"; tail -8 /tmp/errors-tunnel.log; }

# Awaria GlitchTipa nie może wydłużać żądań. Mierzymy względem ciepłej bazy: pierwszy
# błąd po bezczynności jest wolny sam z siebie (strona błędu APP_DEBUG), niezależnie od
# GlitchTipa. Najgorszy przypadek to NIE zatrzymany kontener (nazwa przestaje się
# rozwiązywać i wysyłka pada od razu), tylko zawieszony: połączenie przechodzi, odpowiedzi
# brak, a koszt to dokładnie http_timeout z config/sentry.php (1 s).
err_time() {  # czas żądania z błędem; $1 = kod oczekiwany
  curl -sk -o /dev/null -w '%{http_code} %{time_total}\n' --resolve "catalog.localhost:${HTTPS_PORT}:127.0.0.1" \
    "https://catalog.localhost:${HTTPS_PORT}/_debug/errors/http?m=perf${M}"
}
err_time >/dev/null; err_time >/dev/null
read -r _ base < <(err_time)
tunnel_time() {  # POST /_errors z poprawną kopertą; wypisuje kod i czas
  curl -sk -o /dev/null -w '%{http_code} %{time_total}\n' --resolve "catalog.localhost:${HTTPS_PORT}:127.0.0.1" \
    -X POST -H 'Content-Type: text/plain;charset=UTF-8' --data-binary '{}' "https://catalog.localhost:${HTTPS_PORT}/_errors"
}
gt_mark_touched
docker pause "$GT_CONTAINER" >/dev/null
# Trzy próby: pierwsza po zawieszeniu płaci za odrzucenie połączenia z puli keep-alive
# (zmierzone 2,1-3,1 s), kolejne stabilnie ok. 1,2 s (http_timeout + narzut). Jeden pomiar
# był loterią, więc oceniamy medianę (narzut poniżej 1,5 s) i najgorszą próbę (poniżej 4 s).
read -r code h1 < <(err_time); read -r _ h2 < <(err_time); read -r _ h3 < <(err_time)
hung=$(printf '%s\n' "$h1" "$h2" "$h3" | sort -n | sed -n 2p)
worst=$(printf '%s\n' "$h1" "$h2" "$h3" | sort -n | tail -1)
read -r tcode ttime < <(tunnel_time)
docker unpause "$GT_CONTAINER" >/dev/null
if [ "$tcode" = "502" ] && awk -v t="$ttime" 'BEGIN{exit !(t < 2.5)}'; then
  ok "tunel przy zawieszonym GlitchTipie: 502 w ${ttime}s"
else
  fail "tunel przy zawieszonym GlitchTipie: HTTP $tcode w ${ttime}s (oczekiwano 502 poniżej 2,5 s)"
fi
if [ "$code" = "500" ] && awk -v b="$base" -v h="$hung" -v w="$worst" 'BEGIN{exit !(h - b < 1.5 && w < 4)}'; then
  ok "GlitchTip zawieszony: 500, mediana ${hung}s (najgorsza ${worst}s) wobec ${base}s bazowo"
else
  fail "GlitchTip zawieszony: HTTP $code, mediana ${hung}s, najgorsza ${worst}s wobec ${base}s bazowo (narzut mediany poniżej 1,5 s, najgorsza poniżej 4 s)"
fi
$DC stop glitchtip >/dev/null 2>&1
read -r code _ < <(err_time)
read -r tcode _ < <(tunnel_time)
$DC start glitchtip >/dev/null 2>&1
[ "$tcode" = "502" ] && ok "tunel przy zatrzymanym GlitchTipie: 502" || fail "tunel przy zatrzymanym GlitchTipie: HTTP $tcode (oczekiwano 502)"
[ "$code" = "500" ] && ok "GlitchTip zatrzymany: żądanie z błędem nadal kończy się 500" || fail "GlitchTip zatrzymany: HTTP $code (oczekiwano 500)"
for _ in $(seq 1 40); do
  [ "$(docker inspect -f '{{.State.Health.Status}}' "$GT_CONTAINER" 2>/dev/null)" = healthy ] && break
  sleep 3
done

[ "$FAIL" -eq 0 ] && echo "OK: wyjątki ze wszystkich źródeł docierają do GlitchTipa" || { echo "BŁĄD: errors-smoke"; exit 1; }
