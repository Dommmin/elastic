#!/usr/bin/env bash
# ============================================================================
#  fanout-measure.sh — koszt fan-outu oceny kuriera dla zapytań z DoD (ETAP 13b, POMIARY §17.4)
#    1. baseline: `seller360:query:verify` (took p50/p99 z RUNS powtórzeń zapytania dodPreset) bez fan-outu;
#    2. POST /_chaos {"bump_courier":"courier-3"} — rejestr zmienia ocenę i rated_at;
#    3. w tle te same RUNS zapytań przez catalog, a równolegle `seller360:courier:poll --once`
#       (_update_by_query na seller-360-write i seller-360-public-write, ~2,5 tys. dokumentów na indeks);
#    4. wypisuje updated, took_ms, conflicts oraz p50/p99 bez i w trakcie fan-outu.
#  RUNS (domyślnie 100) i COURIER (domyślnie courier-3) z otoczenia. Zapytania w tle startują ZANIM ruszy poll,
#  a skrypt pilnuje, żeby się z nim nakładały: gdy skończą się wcześniej, przy małym RUNS wynik jest tylko orientacyjny
#  i skrypt o tym ostrzega. Nic nie jest wypisywane poza liczbami (dane dostępowe zostają w kontenerach).
# ============================================================================
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 1

DC="${DC:-docker compose}"
RUNS="${RUNS:-100}"
COURIER="${COURIER:-courier-3}"

step() { printf '\n━━ %s\n' "$*"; }
verify() { ${DC} exec -T catalog-app php artisan seller360:query:verify --runs="$1" </dev/null | grep '^took z'; }
now_ms() { python3 -c 'import time; print(int(time.time()*1000))'; }

step "Baseline: ${RUNS} zapytań bez fan-outu"
BASE="$(verify "${RUNS}")"
echo "  ${BASE}"

step "Zmiana oceny ${COURIER} w rejestrze"
${DC} exec -T search-consumer curl -sS --fail-with-body -X POST registry-mock:8080/_chaos -d "{\"bump_courier\":\"${COURIER}\"}" </dev/null >/dev/null
echo "  ok"

step "Fan-out z zapytaniami w tle (partie po ${RUNS}, aż poll się skończy)"
TMP="$(mktemp)"; STOP="$(mktemp -u)"; trap 'rm -f "${TMP}" "${STOP}"' EXIT
# partie zapytań: każda linia "start_ms end_ms p50 p99"; pętla działa, dopóki nie pojawi się plik STOP
(
  while [ ! -e "${STOP}" ]; do
    s="$(now_ms)"; line="$(verify "${RUNS}")"; e="$(now_ms)"
    echo "${s} ${e} $(sed -E 's/.*p50 ([0-9]+) ms, p99 ([0-9]+) ms.*/\1 \2/' <<<"${line}")" >>"${TMP}"
  done
) &
BG=$!
sleep 2   # rozgrzewka: pierwsza partia już działa (start artisana ~0,5 s)
P0="$(now_ms)"
POLL="$(${DC} exec -T seller360-scheduler php bin/console seller360:courier:poll --once </dev/null 2>/dev/null | tail -1)"
P1="$(now_ms)"
sleep 1   # partia, która zahaczyła o koniec fan-outu, musi się dokończyć
touch "${STOP}"; wait "${BG}"

# partie nakładające się na okno fan-outu [P0, P1]
DURING="$(awk -v a="${P0}" -v b="${P1}" '$1 < b && $2 > a {print}' "${TMP}")"
[ -n "${DURING}" ] || { echo "  BŁĄD: żadna partia zapytań nie nałożyła się na fan-out" >&2; exit 1; }
P50="$(awk '{print $3}' <<<"${DURING}" | sort -n | tail -1)"
P99="$(awk '{print $4}' <<<"${DURING}" | sort -n | tail -1)"
step "Podsumowanie"
echo "  bez fan-outu:   ${BASE}"
echo "  w trakcie:      najgorsza z $(wc -l <<<"${DURING}" | tr -d ' ') nakładających się partii po ${RUNS}: p50 ${P50} ms, p99 ${P99} ms"
echo "  fan-out:        ${POLL}"
echo "  okno fan-outu:  $((P1 - P0)) ms (zegar hosta)"
