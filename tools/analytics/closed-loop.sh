#!/usr/bin/env bash
#  tools/analytics/closed-loop.sh — pętla zamknięta (ETAP 12b, spec §8 krok 5).
#
#  Dla dnia 1..N: symulacja dnia d (ranking z popularnością policzoną z dni 1..d-1,
#  boost B) → czekanie na ingest → search:popularity:refresh (okno kończy się o 00:00
#  dnia d+1) → search:eval na zestawie popularity-queries → wiersz CSV.
#  Wynik: docs/data/etap-12/closed-loop-<wariant>-<znacznik>.csv (do przewodnika, commitowalny).
#
#  Użycie:  tools/analytics/closed-loop.sh [variant=raw|ips] [days=30] [boost=2] [seed=1]
#                                          [sessions=300] [as_of=YYYY-MM-DD] [--flat] [--yes]
#  Przez make: make closed-loop variant=ips days=30 boost=2 [flat=1] [yes=1]
#
#  UWAGA: przed przebiegiem CZYŚCI streamy analityczne (events-user-default,
#  events-impression-default) — kasuje też dane demo z ETAPU 12a (Task 12 symuluje je
#  od nowa) — i zeruje popularność w product-popularity oraz w produktach.
#  Bez --yes pyta o potwierdzenie ("tak"). Przy Ctrl-C przerywa, nie zostawia procesów w tle;
#  stan ES zostaje taki, jak w chwili przerwania (powtórka zaczyna od czyszczenia).
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 1

DC="${DC:-docker compose}"
QUEUE="search.analytics.ingest"
QUERIES="/tests/relevance/popularity-queries.yaml"
STREAMS="events-user-default,events-impression-default"
DRAIN_TIMEOUT="${DRAIN_TIMEOUT:-600}"

die()  { printf '✗ %s\n' "$*" >&2; exit 1; }
info() { printf '%s\n' "$*"; }

variant=ips days=30 boost=2 seed=1 sessions=300 as_of="" flat="" yes=""
for arg in "$@"; do
  case "${arg}" in
    variant=*)  variant="${arg#*=}" ;;
    days=*)     days="${arg#*=}" ;;
    boost=*)    boost="${arg#*=}" ;;
    seed=*)     seed="${arg#*=}" ;;
    sessions=*) sessions="${arg#*=}" ;;
    as_of=*)    as_of="${arg#*=}" ;;
    --flat)     flat="--flat-attractiveness" ;;
    --yes)      yes=1 ;;
    *)          die "nieznany argument: ${arg} (variant= days= boost= seed= sessions= as_of= --flat --yes)" ;;
  esac
done

[[ "${variant}" == raw || "${variant}" == ips ]] || die "variant=raw|ips (podano '${variant}')"
[[ "${days}" =~ ^[0-9]+$ && "${days}" -ge 1 ]] || die "days ≥ 1 (podano '${days}')"
[[ "${seed}" =~ ^[0-9]+$ && "${sessions}" =~ ^[0-9]+$ ]] || die "seed i sessions to liczby całkowite"
[[ "${boost}" =~ ^[0-9]+(\.[0-9]+)?$ ]] || die "boost ≥ 0 (podano '${boost}')"
if [[ -z "${as_of}" ]]; then as_of="$(date -u +%Y-%m-%d)"; fi
[[ "${as_of}" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}$ ]] || die "as_of=YYYY-MM-DD"

# day_end D — północ po dniu D (D=N → as_of): as_of − (N − D) dni; BSD i GNU date
day_end() { python3 -c 'import datetime,sys; print((datetime.date.fromisoformat(sys.argv[1]) - datetime.timedelta(days=int(sys.argv[2]))).isoformat())' "${as_of}" "$((days - $1))"; }

# es METODA ŚCIEŻKA [JSON] — zapytanie do ES z kontenera search-consumer (dane dostępowe
# zostają w jego środowisku, nie trafiają do wyjścia)
es() {
  ${DC} exec -T search-consumer sh -c \
    'curl -sS --fail-with-body -u "$ELASTICSEARCH_USER:$ELASTICSEARCH_PASSWORD" -H "Content-Type: application/json" -X "$0" "$ELASTICSEARCH_HOST$1" ${2:+-d "$2"}' \
    "$@" </dev/null
}

# queue_busy — zwraca 0, gdy w kolejce ingestu są wiadomości (ready lub unacked).
# Tylko odczyt (rabbitmqctl list_queues); nigdy „get" — konsumuje.
queue_busy() {
  local ready unacked
  read -r ready unacked < <(${DC} exec -T rabbitmq rabbitmqctl list_queues -q name messages_ready messages_unacknowledged </dev/null \
    | awk -v q="${QUEUE}" '$1 == q { print $2, $3 }') || return 0
  [[ -n "${ready:-}" && "${ready}" == 0 && "${unacked}" == 0 ]] && return 1
  return 0
}

# wait_drain — kolejka pusta w dwóch kolejnych odczytach (nie ma ścigania się z dostawą), potem refresh streamów
wait_drain() {
  local start="${SECONDS}" calm=0
  while (( calm < 2 )); do
    if queue_busy; then calm=0; else calm=$((calm + 1)); fi
    (( SECONDS - start < DRAIN_TIMEOUT )) || die "kolejka ${QUEUE} nie opróżniła się w ${DRAIN_TIMEOUT} s"
    (( calm >= 2 )) || sleep 2
  done
  es POST "/${STREAMS}/_refresh" >/dev/null
}

cleanup_on_int() { printf '\n✗ Przerwano (Ctrl-C) — ES zostaje w stanie z chwili przerwania; powtórka zacznie od czyszczenia.\n' >&2; exit 130; }
trap cleanup_on_int INT TERM

csv="docs/data/etap-12/closed-loop-${variant}-$(date -u +%Y%m%dT%H%M%SZ).csv"

info "Pętla zamknięta: wariant=${variant}, dni=${days}, boost=${boost}, seed=${seed}, sesji/dzień=${sessions}, as_of=${as_of}${flat:+, kontrola: płaska atrakcyjność}"
info "Skasuje wszystkie dokumenty w ${STREAMS} (także dane demo 12a) i wyzeruje popularność produktów."
if [[ -z "${yes}" ]]; then
  printf 'Kontynuować? Wpisz "tak": '
  read -r answer || answer=""
  [[ "${answer}" == tak ]] || { info "Przerwano — nic nie zmieniono."; exit 0; }
fi

info "── czyszczenie streamów analitycznych ──────────────────"
wait_drain   # żeby spóźnione zdarzenia poprzedniego przebiegu nie trafiły po czyszczeniu
es POST "/${STREAMS}/_delete_by_query?refresh=true&conflicts=proceed" '{"query":{"match_all":{}}}' \
  | python3 -c 'import json,sys; r=json.load(sys.stdin); print("  usunięto:", r.get("deleted"), "z", r.get("total"), "; błędy:", len(r.get("failures", [])), "; konflikty:", r.get("version_conflicts")); sys.exit(1 if r.get("failures") or r.get("deleted",0) < r.get("total",0) else 0)' \
  || die "czyszczenie streamów niepełne"

info "── zerowanie popularności ──────────────────────────────"
${DC} exec -T search-consumer php bin/console search:popularity:refresh --reset </dev/null || die "reset popularności nie przeszedł"

mkdir -p "$(dirname "${csv}")"
echo "dzień,wariant,ndcg_mean,min,max" > "${csv}"

for ((d = 1; d <= days; d++)); do
  info "── dzień ${d}/${days} ─────────────────────────────────────"
  ${DC} exec -T -e "SEARCH_POPULARITY_BOOST=${boost}" catalog-app php artisan analytics:simulate \
    --days="${days}" --day="${d}" --as-of="${as_of}" --seed="${seed}" --sessions-per-day="${sessions}" ${flat} </dev/null \
    || die "symulacja dnia ${d} nie przeszła"
  wait_drain
  ${DC} exec -T search-consumer php bin/console search:popularity:refresh \
    --variant="${variant}" --as-of="$(day_end "${d}")" --window-days=30 </dev/null \
    || die "refresh popularności po dniu ${d} nie przeszedł"
  out="$(${DC} exec -T -e "SEARCH_POPULARITY_BOOST=${boost}" catalog-app php artisan search:eval \
    --queries="${QUERIES}" --tie-shuffles=10 </dev/null)" || die "search:eval po dniu ${d} nie przeszedł"
  mean="$(awk -F= '$1 == "NDCG" { print $2 }' <<<"${out}" | tr -d '\r')"
  min="$(awk -F= '$1 == "NDCG_MIN" { print $2 }' <<<"${out}" | tr -d '\r')"
  max="$(awk -F= '$1 == "NDCG_MAX" { print $2 }' <<<"${out}" | tr -d '\r')"
  [[ -n "${mean}" && -n "${min}" && -n "${max}" ]] || die "nie znaleziono NDCG/NDCG_MIN/NDCG_MAX w wyjściu search:eval"
  fmt() { awk -v v="$1" 'BEGIN { printf "%.4f", v + 0 }'; }
  echo "${d},${variant},$(fmt "${mean}"),$(fmt "${min}"),$(fmt "${max}")" | tee -a "${csv}" >/dev/null
  info "  dzień ${d}: nDCG@10 $(fmt "${mean}") (min $(fmt "${min}"), max $(fmt "${max}"))"
done

info "Gotowe → ${csv}"
