#!/usr/bin/env bash
# ============================================================================
#  fls-demo.sh — demo DLS/FLS dla seller-360 na jednorazowym węźle z TRIALEM (ETAP 13, spec §11.2)
#
#  Główny stos działa na licencji BASIC, która nie ma zabezpieczeń na poziomie dokumentu i pola.
#  Skrypt uruchamia więc osobny, jednowęzłowy ES (-Xmx768m) z licencją trial, ładuje 200 dokumentów
#  seller-360 z głównego klastra i sprawdza, co widzą dwaj użytkownicy:
#    seller_a   — rola seller_self: DLS (tylko własny dokument, szablon na metadata.seller_id)
#                 i FLS (bez billing.* i complaints.recent_texts),
#    consultant — rola consultant: pełny odczyt.
#  Wybór obrazu: domyślnie obraz projektu (marketplace/elasticsearch:${ES_VERSION}), bo ma plugin analysis-stempel
#  i synonimy, których wymaga szablon; zwykły obraz Elastica nie przyjąłby analizatorów pl_*. Nadpisanie: DEMO_IMAGE.
#  Zawsze sprząta (trap EXIT). Hasła demo są losowane w locie, nie trafiają do plików ani na wyjście, ani do argumentów procesów (docker -e z eksportu, jq env, curl -K).
#  Kod wyjścia 0 tylko, gdy wszystkie asercje przeszły.
# ============================================================================
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 1

START=$(date +%s)
NAME="seller360-trial-$$"
SAMPLE=200
MIN_FREE_MB=2048
ROLES="tools/seller360/fls-roles.json"
TEMPLATE="infra/elasticsearch/seller360/template-seller-360.json"

step() { printf '\n━━ %s\n' "$*"; }
die()  { printf 'BŁĄD: %s\n' "$*" >&2; exit 1; }
envval() { grep -E "^$1=" .env | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }

command -v jq >/dev/null || die "brak jq"
command -v docker >/dev/null || die "brak docker"
[ -f .env ] || die "brak .env (dane głównego klastra)"

ES_VERSION="$(envval ES_VERSION)"
IMAGE="${DEMO_IMAGE:-marketplace/elasticsearch:${ES_VERSION}}"
MAIN_URL="http://localhost:$(envval ES_PORT)"
MAIN_PW="$(envval ELASTIC_PASSWORD)"
case "${MAIN_PW}" in *\"*|*\\*) die "hasło głównego klastra zawiera cudzysłów lub ukośnik: nieobsługiwane w konfiguracji curl -K";; esac

# --- 1. pamięć: ta maszyna jest współdzielona z innymi projektami, nie ruszamy cudzych kontenerów ---
step "Kontrola pamięci (zapas minimum ${MIN_FREE_MB} MB)"
TOTAL_MB=$(( $(docker info --format '{{.MemTotal}}') / 1024 / 1024 ))
USED_MB=$(docker stats --no-stream --format '{{.MemUsage}}' | awk '{
  split($1, a, /[A-Za-z]+/); u=$1; sub(/^[0-9.]+/, "", u); v=a[1]
  if (u=="GiB") v*=1024; else if (u=="KiB"||u=="kB") v/=1024; else if (u=="B") v/=1048576
  s+=v } END { printf "%d", s }')
FREE_MB=$(( TOTAL_MB - USED_MB ))
echo "  Docker: ${TOTAL_MB} MB, użyte przez kontenery: ${USED_MB} MB, zapas: ${FREE_MB} MB"
if [ "${FREE_MB}" -lt "${MIN_FREE_MB}" ]; then
  die "za mało pamięci (zapas ${FREE_MB} MB < ${MIN_FREE_MB} MB); zwolnij pamięć i uruchom ponownie (cudzych kontenerów nie zatrzymuję)"
fi

# --- 2. węzeł trial ---
ELASTIC_PW="$(openssl rand -hex 16)"
SELLER_PW="$(openssl rand -hex 16)"
CONSULT_PW="$(openssl rand -hex 16)"
SAMPLE_FILE=""
trap '[ -z "${SAMPLE_FILE}" ] || rm -f "${SAMPLE_FILE}"; docker rm -f "${NAME}" >/dev/null 2>&1 || true' EXIT
export ELASTIC_PASSWORD="${ELASTIC_PW}" SELLER_PW CONSULT_PW

step "Start węzła ${NAME} (${IMAGE}, -Xmx768m)"
docker image inspect "${IMAGE}" >/dev/null 2>&1 || die "brak obrazu ${IMAGE} (make build albo ustaw DEMO_IMAGE)"
docker run -d --rm --name "${NAME}" \
  -e discovery.type=single-node \
  -e ES_JAVA_OPTS="-Xms768m -Xmx768m" \
  -e ELASTIC_PASSWORD \
  -e xpack.security.enabled=true \
  -e xpack.security.http.ssl.enabled=false \
  -e xpack.security.transport.ssl.enabled=false \
  -p 127.0.0.1::9200 \
  "${IMAGE}" >/dev/null
PORT="$(docker port "${NAME}" 9200/tcp | head -1 | sed 's/.*://')"
TRIAL="http://127.0.0.1:${PORT}"

# kopie haseł nie trafiają do argumentów procesów: curl dostaje je przez -K z deskryptora
es()   { local u="$1"; shift; curl -sS -K <(printf 'user = "%s"\n' "${u}") -H 'Content-Type: application/json' "$@"; }
tr_el() { es "elastic:${ELASTIC_PW}" "$@"; }
main() { es "elastic:${MAIN_PW}" "$@"; }

printf '  czekam na klaster'
for i in $(seq 1 90); do
  H="$(tr_el "${TRIAL}/_cluster/health?wait_for_status=yellow&timeout=1s" 2>/dev/null | jq -r '.status // empty' 2>/dev/null || true)"
  if [ "${H}" = "green" ] || [ "${H}" = "yellow" ]; then echo " ${H}"; break; fi
  printf '.'; sleep 2
  [ "${i}" -lt 90 ] || die "klaster nie wstał w 180 s"
done

step "Licencja trial"
tr_el -X POST "${TRIAL}/_license/start_trial?acknowledge=true" | jq -c '{trial_was_started, type}'
[ "$(tr_el "${TRIAL}/_license" | jq -r '.license.type')" = "trial" ] || die "licencja nie jest trial"

# --- 3. indeks i próbka ---
step "Szablon, indeks seller-360-v1 i próbka ${SAMPLE} dokumentów"
jq '.template.settings["index.number_of_replicas"]=0' "${TEMPLATE}" \
  | tr_el -X PUT "${TRIAL}/_index_template/seller-360" --data-binary @- | jq -c .
tr_el -X PUT "${TRIAL}/seller-360-v1" -d '{"aliases":{"seller-360":{}}}' | jq -c .

# próbka: pierwsze 200 dokumentów (rosnąco po seller_id) z sekcją billing; _id nie nadaje się do sortowania (fielddata wyłączone)
SAMPLE_FILE="$(mktemp "${TMPDIR:-/tmp}/s360-sample.XXXXXX")"
main "${MAIN_URL}/seller-360/_search?size=${SAMPLE}&sort=seller_id:asc" -d '{"query":{"exists":{"field":"billing.overdue_cents"}}}' > "${SAMPLE_FILE}" \
  || die "nie udało się pobrać próbki z ${MAIN_URL}"
GOT="$(jq '.hits.hits | length' "${SAMPLE_FILE}")"
[ "${GOT}" -eq "${SAMPLE}" ] || die "próbka ma ${GOT} dokumentów, oczekiwano ${SAMPLE}"
# A: pierwszy w próbce z zaległością > 0 i z recent_texts (inaczej asercje o ukrytych polach byłyby trywialne), B: pierwszy inny
A="$(jq -r '[.hits.hits[] | select(._source.billing.overdue_cents > 0 and ((._source.complaints.recent_texts // []) | length > 0))][0]._id' "${SAMPLE_FILE}")"
[ -n "${A}" ] && [ "${A}" != "null" ] || die "w próbce nie ma sprzedawcy z billing.overdue_cents > 0 i complaints.recent_texts"
B="$(jq -r --arg a "${A}" '[.hits.hits[] | select(._id != $a)][0]._id' "${SAMPLE_FILE}")"
jq -c '.hits.hits[] | {index:{_index:"seller-360-v1",_id:._id}}, ._source' "${SAMPLE_FILE}" \
  | tr_el -X POST "${TRIAL}/_bulk?refresh=true" --data-binary @- | jq -c '{errors, items: (.items|length)}'
[ "$(tr_el "${TRIAL}/seller-360/_count" | jq .count)" -eq "${SAMPLE}" ] || die "bulk nie załadował ${SAMPLE} dokumentów"
echo "  sprzedawca A: ${A}, sprzedawca B: ${B}"

# --- 4. role i użytkownicy ---
step "Role i użytkownicy"
for role in $(jq -r 'keys[]' "${ROLES}"); do
  jq -c ".${role}" "${ROLES}" | tr_el -X PUT "${TRIAL}/_security/role/${role}" --data-binary @- | jq -c "{role: \"${role}\", result: .role}"
done
jq -nc --arg a "${A}" '{password:env.SELLER_PW, roles:["seller_self"], metadata:{seller_id:$a}}' \
  | tr_el -X PUT "${TRIAL}/_security/user/seller_a" --data-binary @- | jq -c '{user: "seller_a", created}'
jq -nc '{password:env.CONSULT_PW, roles:["consultant"]}' \
  | tr_el -X PUT "${TRIAL}/_security/user/consultant" --data-binary @- | jq -c '{user: "consultant", created}'

sa() { es "seller_a:${SELLER_PW}" "$@"; }
co() { es "consultant:${CONSULT_PW}" "$@"; }

# --- 5. asercje ---
step "Asercje"
FAILS=0
check() {  # check OPIS WARTOŚĆ_FAKTYCZNA WARTOŚĆ_OCZEKIWANA
  if [ "$2" = "$3" ]; then printf '  OK    %s\n' "$1"; else printf '  FAIL  %s (jest: %s, oczekiwano: %s)\n' "$1" "$2" "$3"; FAILS=$((FAILS+1)); fi
}

resp_ok() { jq -r '(has("error") | not) and ((._shards.failed // 0) == 0) and (._shards.successful // 0) >= 1' <<<"$1"; }

R="$(sa "${TRIAL}/seller-360/_search" -d '{"query":{"match_all":{}},"size":10}')"
check "seller_a: odpowiedź _search bez błędu" "$(resp_ok "${R}")" "true"
check "seller_a: match_all daje 1 trafienie" "$(jq '.hits.total.value' <<<"${R}")" "1"
check "seller_a: jedyny _id to A (${A})" "$(jq -r '.hits.hits[0]._id' <<<"${R}")" "${A}"
check "seller_a: dokument ma _source" "$(jq '(.hits.hits | length == 1) and (.hits.hits[0]._source != null)' <<<"${R}")" "true"
check "seller_a: dokument bez klucza billing" "$(jq '.hits.hits[0]._source | has("billing")' <<<"${R}")" "false"
check "seller_a: complaints widoczne, ale bez recent_texts" "$(jq '.hits.hits[0]._source.complaints | (type == "object") and (has("recent_texts") | not)' <<<"${R}")" "true"
check "seller_a: profile widoczne (FLS nie ucina reszty)" "$(jq '.hits.hits[0]._source | has("profile")' <<<"${R}")" "true"

R="$(sa "${TRIAL}/seller-360/_search" -d '{"size":0,"track_total_hits":true,"query":{"range":{"billing.overdue_cents":{"gt":0}}}}')"
check "seller_a: range billing.overdue_cents > 0, odpowiedź bez błędu" "$(resp_ok "${R}")" "true"
check "seller_a: range billing.overdue_cents > 0 daje 0 trafień" "$(jq '.hits.total.value' <<<"${R}")" "0"

R="$(sa "${TRIAL}/seller-360/_search" -d '{"size":0,"aggs":{"s":{"sum":{"field":"billing.overdue_cents"}}}}')"
check "seller_a: agregacja sum, odpowiedź bez błędu i z wynikiem" "$(jq -r '(has("error") | not) and ((._shards.failed // 0) == 0) and (.aggregations.s | type == "object" and has("value"))' <<<"${R}")" "true"
check "seller_a: sum billing.overdue_cents to 0 albo null" "$(jq -r '.aggregations.s.value | (. == null or . == 0)' <<<"${R}")" "true"

CODE="$(sa -o /dev/null -w '%{http_code}' "${TRIAL}/seller-360/_doc/${B}")"
check "seller_a: _doc/${B} (cudzy sprzedawca) daje 404" "${CODE}" "404"
CODE="$(sa -o /dev/null -w '%{http_code}' "${TRIAL}/seller-360/_doc/${A}")"
check "seller_a: _doc/${A} (własny) daje 200" "${CODE}" "200"

R="$(co "${TRIAL}/seller-360/_search" -d '{"size":0,"track_total_hits":true,"query":{"exists":{"field":"billing.overdue_cents"}}}')"
check "consultant: widzi ${SAMPLE} dokumentów z billing" "$(jq '.hits.total.value' <<<"${R}")" "${SAMPLE}"
R="$(co "${TRIAL}/seller-360/_search" -d '{"size":1,"query":{"term":{"_id":"'"${B}"'"}}}')"
check "consultant: dokument A ma billing.overdue_cents > 0 (dane do ukrycia istnieją)" "$(co "${TRIAL}/seller-360/_doc/${A}" | jq '._source.billing.overdue_cents > 0')" "true"
check "consultant: dokument A ma complaints.recent_texts (dane do ukrycia istnieją)" "$(co "${TRIAL}/seller-360/_doc/${A}" | jq '(._source.complaints.recent_texts // []) | length > 0')" "true"
check "consultant: dokument B zawiera klucz billing" "$(jq '.hits.hits[0]._source | has("billing")' <<<"${R}")" "true"

ELAPSED=$(( $(date +%s) - START ))
step "Wynik"
echo "  czas: ${ELAPSED} s, niepowodzeń: ${FAILS}"
[ "${FAILS}" -eq 0 ] || exit 1
echo "  wszystkie asercje OK"
