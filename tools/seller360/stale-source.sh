#!/usr/bin/env bash
# ============================================================================
#  make seller360-stale-source — DoD ETAPU 13: „opóźnienie jednego źródła nie psuje pozostałych sekcji”
#  (spec §9, „Opóźnione źródło”). Kroki:
#    0. wybór syntetycznego sprzedawcy z NIP-em (poza planted.json, żeby nie ruszać dowodu zapytania)
#       i świeża synchronizacja jego rejestru (registry ok, cache z czasem pobrania);
#    1. całkowita awaria mocka rejestru (POST /_chaos {"outage":true});
#    2. zdarzenie billingu (faktura przez IssueInvoiceHandler) i zmiana profilu (marketplace:seller-rename);
#    3. billing i profile zaktualizowane mimo awarii, registry ma status stale z opisem błędu;
#    4. lag rejestru rośnie, a profile i billing nie (lag = updated_at − source_ts, ruling R6);
#    5. koniec awarii, registry wraca do ok.
#  Kod wyjścia 1 przy pierwszym niespełnionym warunku. `trap` ZAWSZE wyłącza awarię i przywraca nazwę.
#
#  Rejestr synchronizuje pętla `seller360-registry` (sprzedawcy od najstarszej synchronizacji, 10/s, przy
#  otwartym bezpieczniku bez hamowania). Pozycja jednego sprzedawcy w przejściu jest przypadkowa (pełne
#  przejście 20 tys. sprzedawców to ~35 min przy zdrowym rejestrze), więc skrypt dla wybranego sprzedawcy woła
#  `seller360:registry:sync --seller=ID` (ten sam kod i ten sam bezpiecznik w Redisie co pętla). Globalny lag
#  z Redisa (`seller360:lag`) liczy zadanie `lag` schedulera co 30 s, więc między odczytami czekamy
#  WAIT_SECONDS (domyślnie 90 s = trzy cykle zadania lag) i jeszcze 35 s po ostatnim zapisie.
#
#  Zostawia po sobie jedną fakturę 12,34 zł (termin za 14 dni) u wybranego syntetycznego sprzedawcy; znika
#  przy `seller360:simulate --purge`. Dane dostępowe zostają w środowisku kontenerów, nic nie jest wypisywane.
# ============================================================================
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 1

DC="${DC:-docker compose}"
WAIT_SECONDS="${WAIT_SECONDS:-90}"
PLANTED="apps/catalog/storage/app/seller360/planted.json"

ok()   { printf '  ✓ %s\n' "$*"; }
step() { printf '\n━━ %s\n' "$*"; }
die()  { printf '  ✗ %s\n' "$*" >&2; exit 1; }

# es METODA ŚCIEŻKA [JSON] — ES z kontenera search-consumer (konto searchsvc z jego środowiska)
es() {
  ${DC} exec -T search-consumer sh -c \
    'curl -sS --fail-with-body -u "$ELASTICSEARCH_USER:$ELASTICSEARCH_PASSWORD" -H "Content-Type: application/json" -X "$0" "$ELASTICSEARCH_HOST$1" ${2:+-d "$2"}' \
    "$@" </dev/null
}
chaos() {
  ${DC} exec -T search-consumer curl -sS --fail-with-body -X POST registry-mock:8080/_chaos -d "$1" </dev/null >/dev/null
}
# lag_report — JSON z Redisa `seller360:lag` (hasło Redisa tylko w środowisku seller360-scheduler)
lag_report() {
  ${DC} exec -T seller360-scheduler php -r \
    '$r = new Redis(); $r->connect(getenv("REDIS_HOST")); $r->auth(getenv("REDIS_PASSWORD")); echo $r->get("seller360:lag") ?: "{}";' </dev/null
}
doc() { es GET "/seller-360/_doc/${SELLER}" | jq '._source'; }
# section_lag JSON SEKCJA — updated_at − source_ts w sekundach (ruling R6)
section_lag() { jq -r --arg s "$2" '.[$s]._meta | ((.updated_at | fromdateiso8601) - (.source_ts | fromdateiso8601))' <<<"$1"; }
sync_registry() {
  ${DC} exec -T seller360-registry php bin/console seller360:registry:sync --seller="${SELLER}" </dev/null >/dev/null 2>&1 || true
}
artisan() { ${DC} exec -T catalog-app php artisan "$@" </dev/null; }
# registry_since ISO — zapisy registry od początku awarii: liczba wg statusu i max lagu (R6, jak LagReport)
registry_since() {
  es POST "/seller-360/_search" "{\"size\":0,\"query\":{\"range\":{\"registry._meta.updated_at\":{\"gte\":\"$1\"}}},
    \"aggs\":{\"stale\":{\"filter\":{\"term\":{\"registry._meta.status\":\"stale\"}}},
    \"lag\":{\"max\":{\"script\":\"doc['registry._meta.updated_at'].value.toInstant().getEpochSecond() - doc['registry._meta.source_ts'].value.toInstant().getEpochSecond()\"}}}}" \
    | jq -c '{stale: .aggregations.stale.doc_count, max_lag: (.aggregations.lag.value // 0)}'
}
# wait_registry_ok — synchronizacja co 5 s, aż registry będzie ok (bezpiecznik po awarii trzyma 30 s); ~2 min
wait_registry_ok() {
  local status=""
  for _ in $(seq 1 24); do
    sync_registry
    status=$(doc | jq -r '.registry._meta.status')
    [[ "${status}" == ok ]] && return 0
    sleep 5
  done
  die "registry sprzedawcy ${SELLER} nie jest ok po ~2 min (status ${status}; rejestr zdrowy?)"
}

command -v jq >/dev/null || die "potrzebny jq"
[[ -f "${PLANTED}" ]] || die "brak ${PLANTED} — najpierw make seller360-simulate (…) --plant=5"

ORIG_NAME=""
cleanup() {
  local code=$?
  chaos '{"outage":false}' || printf '  ! nie udało się wyłączyć awarii: POST registry-mock:8080/_chaos {"outage":false}\n' >&2
  if [[ -n "${ORIG_NAME}" ]]; then
    artisan marketplace:seller-rename "${SELLER}" "${ORIG_NAME}" >/dev/null 2>&1 || true
  fi
  if [[ ${code} -eq 0 ]]; then printf '\nWSZYSTKIE KROKI ZIELONE (awaria wyłączona, nazwa przywrócona)\n'; else printf '\nPRZERWANE (awaria wyłączona)\n' >&2; fi
}
trap cleanup EXIT

T0=$(date +%s)
since() { echo "$(( $(date +%s) - T0 )) s"; }

# --- 0. wybór sprzedawcy i stan wyjściowy --------------------------------------------------------
step "0. Wybór syntetycznego sprzedawcy z NIP-em (poza planted.json) i świeży rejestr"
EXCLUDE=$(jq -c '[.planted[], .near_misses[].id] | map(tostring)' "${PLANTED}")
SELLER=$(es POST "/seller-360/_search" "{\"size\":1,\"_source\":false,\"sort\":[{\"seller_id\":\"asc\"}],
  \"query\":{\"bool\":{\"filter\":[{\"term\":{\"synthetic\":true}},{\"exists\":{\"field\":\"nip\"}},
  {\"exists\":{\"field\":\"profile.name\"}},{\"exists\":{\"field\":\"billing._meta\"}}],
  \"must_not\":[{\"ids\":{\"values\":${EXCLUDE}}}]}}}" | jq -r '.hits.hits[0]._id // empty')
[[ -n "${SELLER}" ]] || die "nie znalazłem syntetycznego sprzedawcy z NIP-em, profilem i billingiem"
wait_registry_ok
D0=$(doc)
ORIG_NAME=$(jq -r '.profile.name' <<<"${D0}")
B0=$(jq -r '.billing._meta.version' <<<"${D0}")
ok "sprzedawca ${SELLER} (NIP $(jq -r .nip <<<"${D0}")), „${ORIG_NAME}”, billing v${B0}, registry ok"

# --- 1. awaria -------------------------------------------------------------------------------------
step "1. Całkowita awaria rejestru"
OUTAGE_AT=$(date -u +%Y-%m-%dT%H:%M:%SZ)
chaos '{"outage":true}'
ok "registry-mock: outage=true (każde żądanie 3 s, potem 503; timeout klienta 2 s) [$(since)]"

# --- 2. zdarzenia z innych źródeł ------------------------------------------------------------------
step "2. Zdarzenie billingu i zmiana profilu"
artisan tinker --execute "app(Billing\\Application\\IssueInvoiceHandler::class)->handle(new Billing\\Application\\IssueInvoice(${SELLER}, 1234, new DateTimeImmutable('+14 days')));" >/dev/null
ok "faktura 12,34 zł wystawiona (billing.invoice_issued przez outbox)"
NEW_NAME="${ORIG_NAME} (awaria $(date +%H%M%S))"
artisan marketplace:seller-rename "${SELLER}" "${NEW_NAME}" >/dev/null
ok "zmiana nazwy na „${NEW_NAME}” (seller.renamed przez outbox)"

# --- 3. billing i profile świeże, registry stale ---------------------------------------------------
step "3. billing i profile aktualne mimo awarii, registry stale"
for _ in $(seq 1 60); do
  D=$(doc)
  if [[ "$(jq -r '.profile.name' <<<"${D}")" == "${NEW_NAME}" && "$(jq -r '.billing._meta.version' <<<"${D}")" -gt "${B0}" ]]; then break; fi
  sleep 1
done
[[ "$(jq -r '.profile.name' <<<"${D}")" == "${NEW_NAME}" ]] || die "profile nie zaktualizował się w 60 s"
[[ "$(jq -r '.billing._meta.version' <<<"${D}")" -gt "${B0}" ]] || die "billing nie zaktualizował się w 60 s"
ok "profile.name = „${NEW_NAME}”, billing v$(jq -r '.billing._meta.version' <<<"${D}") (open_invoices $(jq -r '.billing.open_invoices' <<<"${D}")) [$(since)]"

sync_registry
D1=$(doc)
[[ "$(jq -r '.registry._meta.status' <<<"${D1}")" == stale ]] || die "registry ma status $(jq -r '.registry._meta.status' <<<"${D1}"), oczekiwano stale"
[[ -n "$(jq -r '.registry._meta.error // empty' <<<"${D1}")" ]] || die "registry stale bez _meta.error"
[[ "$(jq -r '.registry.legal_name' <<<"${D1}")" == "$(jq -r '.registry.legal_name' <<<"${D0}")" ]] || die "registry zgubił dane z cache"
L1=$(section_lag "${D1}" registry)
ok "registry: stale, error „$(jq -r '.registry._meta.error' <<<"${D1}")”, dane z cache zostały, lag ${L1} s [$(since)]"
LAG_A=$(lag_report)
REG_A=$(registry_since "${OUTAGE_AT}")

# --- 4. lag rejestru rośnie, pozostałych nie --------------------------------------------------------
step "4. Lag: registry rośnie, profile i billing nie (czekam ${WAIT_SECONDS} s)"
sleep "${WAIT_SECONDS}"
sync_registry
D2=$(doc)
L2=$(section_lag "${D2}" registry)
LP=$(section_lag "${D2}" profile)
LB=$(section_lag "${D2}" billing)
[[ "$(jq -r '.registry._meta.status' <<<"${D2}")" == stale ]] || die "registry po ${WAIT_SECONDS} s nie jest stale"
(( L2 >= L1 + WAIT_SECONDS - 5 )) || die "lag registry sprzedawcy nie urósł: ${L1} s → ${L2} s"
(( LP < 60 )) || die "lag profile sprzedawcy ${LP} s (oczekiwano < 60 s)"
(( LB < 60 )) || die "lag billing sprzedawcy ${LB} s (oczekiwano < 60 s)"
ok "sprzedawca ${SELLER}: lag registry ${L1} s → ${L2} s, lag profile ${LP} s, billing ${LB} s [$(since)]"

sleep 35   # zadanie lag (co 30 s) musi zobaczyć ostatni zapis
LAG_B=$(lag_report)
REG_B=$(registry_since "${OUTAGE_AT}")
# Pętla przy otwartym bezpieczniku przepisuje wszystkich sprzedawców jako stale (~140/s), każdy z lagiem
# „od ostatniego udanego pobrania”, więc max lagu zapisów registry od początku awarii musi rosnąć.
jq -e -n --argjson a "${REG_A}" --argjson b "${REG_B}" '$b.max_lag > $a.max_lag and $b.stale > $a.stale' >/dev/null \
  || die "lag registry (zapisy od początku awarii) nie urósł: ${REG_A} → ${REG_B}"
ok "registry od początku awarii: ${REG_A} → ${REG_B} (pętla seller360-registry)"
# percentiles w ES to TDigest (przybliżenie): przy lagu billingu ~7,7 mln s (faktury generatora z datą wsteczną)
# szum to kilkanaście sekund, więc tolerancja 5 s albo 0,01% wartości, cokolwiek większe.
for s in profile billing; do
  jq -e -n --argjson a "${LAG_A}" --argjson b "${LAG_B}" --arg s "$s" \
    '$b[$s].p99 <= $a[$s].p99 + ([5, $a[$s].p99 * 0.0001] | max)' >/dev/null \
    || die "p99 lagu ${s} urósł w trakcie awarii rejestru: $(jq -n --argjson a "${LAG_A}" --argjson b "${LAG_B}" --arg s "$s" '"\($a[$s].p99) → \($b[$s].p99)"')"
done
ok "seller360:lag: profile p99 $(jq -n --argjson a "${LAG_A}" --argjson b "${LAG_B}" '"\($a.profile.p99|floor) → \($b.profile.p99|floor)"') s, billing p99 bez wzrostu [$(since)]"

# --- 5. koniec awarii ------------------------------------------------------------------------------
step "5. Koniec awarii: registry wraca do ok"
chaos '{"outage":false}'
wait_registry_ok   # przy otwartym bezpieczniku (30 s) dalej stale z cache, potem próba i ok
D3=$(doc)
ok "registry: ok, lag $(section_lag "${D3}" registry) s, error $(jq -r '.registry._meta.error' <<<"${D3}") [$(since)]"
