#!/usr/bin/env bash
# ============================================================================
#  rebuild-under-load.sh — przebudowa seller-360 bez przestoju pod ruchem (ETAP 13, Task 14, POMIARY §17.5)
#    1. w tle ruch: `marketplace:churn --rate=20 --duration=120` (zdarzenia offer.*) i `billing:churn`
#       (nowe faktury losowym syntetycznym sprzedawcom, zdarzenia billing.*; `seller360:simulate --billing`
#       odmawia drugiego przebiegu, gdy syntetyczni już są w bazie);
#    2. po rozgrzewce `seller360:rebuild` w seller360-scheduler (podwójny zapis, backfill, weryfikacja, aliasy);
#    3. koniec ruchu, SETTLE s (domyślnie 30) na dojście zdarzeń w locie;
#    4. `seller360:billing:parity`: sekcje billing kontra /api/internal/billing/accounts, wszystkie konta — 0 rozjazdów.
#  Zmienne: RATE (20), DURATION (120), BILLING_RATE (10), WARMUP (10), SETTLE (30), DELETE_OLD=1 (usuń stare indeksy).
#  Kod wyjścia ≠ 0, gdy przebudowa przerwana albo są rozjazdy. Dane dostępowe zostają w kontenerach.
# ============================================================================
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 1

DC="${DC:-docker compose}"
RATE="${RATE:-20}"
DURATION="${DURATION:-120}"
BILLING_RATE="${BILLING_RATE:-10}"
WARMUP="${WARMUP:-10}"
SETTLE="${SETTLE:-30}"
DELETE_OLD="${DELETE_OLD:-0}"

step() { printf '\n━━ %s\n' "$*"; }
now_s() { date +%s; }
aliases() {
  ${DC} exec -T es01 sh -c 'curl -sS -u "elastic:$ELASTIC_PASSWORD" "http://localhost:9200/_cat/aliases/seller-360*?h=alias,index,is_write_index"' </dev/null | sort
}

LOG="$(mktemp -d)"; trap 'rm -rf "${LOG}"' EXIT

step "Aliasy przed przebudową"
aliases | sed 's/^/  /'

step "Ruch w tle: churn ${RATE}/s i billing:churn ${BILLING_RATE}/s przez ${DURATION} s"
T0="$(now_s)"
${DC} exec -T catalog-app php artisan marketplace:churn --rate="${RATE}" --duration="${DURATION}" </dev/null >"${LOG}/churn.txt" 2>&1 &
CHURN=$!
${DC} exec -T catalog-app php artisan billing:churn --rate="${BILLING_RATE}" --duration="${DURATION}" </dev/null >"${LOG}/billing.txt" 2>&1 &
BILLING=$!
sleep "${WARMUP}"

step "seller360:rebuild (start po ${WARMUP} s ruchu)"
ARGS=()
[ "${DELETE_OLD}" = "1" ] && ARGS+=(--delete-old)
R0="$(now_s)"
set +e
${DC} exec -T seller360-scheduler php bin/console seller360:rebuild ${ARGS[@]+"${ARGS[@]}"} </dev/null 2>>"${LOG}/stderr.txt" | tee "${LOG}/rebuild.txt" | sed 's/^/  /'
REBUILD_RC=${PIPESTATUS[0]}
set -e
R1="$(now_s)"
[ "${R1}" -lt "$((T0 + DURATION))" ] && echo "  (przebudowa skończyła się w trakcie ruchu: $((T0 + DURATION - R1)) s przed jego końcem)"

step "Koniec ruchu"
wait "${CHURN}" || echo "  marketplace:churn zakończył się błędem"
wait "${BILLING}" || echo "  billing:churn zakończył się błędem"
echo "  churn:   $(tail -1 "${LOG}/churn.txt")"
echo "  billing: $(tail -1 "${LOG}/billing.txt")"

step "Odczekanie ${SETTLE} s na zdarzenia w locie, potem zgodność billingu"
sleep "${SETTLE}"
set +e
${DC} exec -T seller360-scheduler php bin/console seller360:billing:parity </dev/null 2>>"${LOG}/stderr.txt" | tee "${LOG}/parity.txt" | sed 's/^/  /'
PARITY_RC=${PIPESTATUS[0]}
set -e

step "Aliasy po przebudowie"
aliases | sed 's/^/  /'

step "Podsumowanie"
echo "  przebudowa: $((R1 - R0)) s (zegar hosta), kod ${REBUILD_RC}"
grep -E '^(status|phases_ms|backfill|counts_old)=' "${LOG}/rebuild.txt" | sed 's/^/  /'
echo "  billing:    $(grep -m1 '^accounts=' "${LOG}/parity.txt" || echo 'brak wyniku')"
WARN="$(grep -cE '"level_name":"(WARNING|ERROR|CRITICAL)"' "${LOG}/stderr.txt" || true)"
echo "  logi:       ${WARN} wpisów WARNING/ERROR na stderr komend"
[ "${WARN}" = "0" ] || grep -E '"level_name":"(WARNING|ERROR|CRITICAL)"' "${LOG}/stderr.txt" | head -5 | cut -c1-300 | sed 's/^/    /'
[ "${REBUILD_RC}" -eq 0 ] && [ "${PARITY_RC}" -eq 0 ]
