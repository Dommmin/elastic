#!/usr/bin/env bash
#  tools/es/lib.sh — wspólne funkcje reindeksu bez przestoju (ETAP 10).
#  Funkcje CZYSTE (parsowanie wyjść, preflight) nie dotykają klastra — testuje je
#  tools/es/test.sh. Reszta rozmawia z ES przez kontener search-consumer: dane
#  dostępowe zostają w jego środowisku i nigdy nie trafiają do wyjścia skryptu.

DC="${DC:-docker compose}"
READ_ALIAS="products-search"
WRITE_ALIAS="products-write"

die()  { printf '✗ %s\n' "$*" >&2; exit 1; }
info() { printf '%s\n' "$*"; }

# --- funkcje czyste -----------------------------------------------------------

# kv_get KLUCZ <wyjście — ostatnia wartość linii KLUCZ=wartość (puste, gdy brak)
kv_get() {
  local key="$1"
  awk -v k="${key}=" 'index($0, k) == 1 { v = substr($0, length(k) + 1); sub(/\r$/, "", v) } END { if (v != "") print v }'
}

# ndcg_value <wyjście search:eval — liczba z linii NDCG= (PHP: 1, 0.973, 1.0E-5)
# Normalizuje do postaci dziesiętnej; wartość nienumeryczna = brak wyniku (kod 1).
ndcg_value() {
  local raw
  raw="$(kv_get NDCG)"
  [[ -n "${raw}" ]] || return 1
  awk -v v="${raw}" 'BEGIN { if (v !~ /^-?[0-9]+(\.[0-9]*)?([eE][-+]?[0-9]+)?$/) exit 1; printf "%.6f\n", v + 0 }'
}

# ndcg_regressed STARY NOWY — kod 0, gdy NOWY < STARY - 0.01 (regresja)
ndcg_regressed() {
  awk -v o="$1" -v n="$2" 'BEGIN { exit !(n + 0 < o - 0.01) }'
}

# running_reindex_ids CEL <JSON z _tasks — id zadań nadrzędnych _reindex do CEL.
# Dzieci slice'ów (mają parent_task_id) pomijamy: anuluje się rodzica.
running_reindex_ids() {
  python3 -c '
import json, sys
target = "[%s]" % sys.argv[1]
nodes = json.load(sys.stdin).get("nodes", {})
for node in nodes.values():
    for tid, task in node.get("tasks", {}).items():
        if "parent_task_id" not in task and target in task.get("description", ""):
            print(tid)
' "$1"
}

# cleanup_candidates <podgląd search:index:migrate cleanup — nazwy z listingu
cleanup_candidates() {
  sed -nE 's/^ *\* +(products-[A-Za-z0-9._-]+) *$/\1/p'
}

# preflight_index_free INDEKS CEL... — odmawia (kod 1), gdy INDEKS jest celem
# któregokolwiek z podanych celów aliasów (products-search / products-write).
preflight_index_free() {
  local index="$1"; shift
  local t
  [[ -n "${index}" ]] || { echo "preflight: pusta nazwa indeksu" >&2; return 1; }
  for t in "$@"; do
    if [[ "${t}" == "${index}" ]]; then
      echo "preflight: ${index} jest celem aliasu (${READ_ALIAS}/${WRITE_ALIAS}) — odmawiam operacji niszczącej" >&2
      return 1
    fi
  done
  return 0
}

fmt_secs() { printf '%ds' "$1"; }

# --- rozmowa z klastrem -------------------------------------------------------

# sc ARGS… — php bin/console w search-consumer
sc() { ${DC} exec -T search-consumer php bin/console "$@"; }

# es METODA ŚCIEŻKA [JSON] — wywołanie ES z danymi dostępowymi z kontenera
es() {
  ${DC} exec -T search-consumer sh -c \
    'curl -sS --fail-with-body -u "$ELASTICSEARCH_USER:$ELASTICSEARCH_PASSWORD" -H "Content-Type: application/json" -X "$0" "$ELASTICSEARCH_HOST$1" ${2:+-d "$2"}' \
    "$1" "$2" "${3:-}"
}

# alias_target ALIAS — indeks(y) pod aliasem, jeden na linię (pusto, gdy brak)
alias_target() {
  es GET "/_alias/$1" 2>/dev/null | python3 -c '
import json, sys
try:
    data = json.load(sys.stdin)
except ValueError:
    sys.exit(0)
for name in sorted(data):
    if "error" not in data:
        print(name)'
}

# alias_targets — wszystkie cele obu aliasów (do preflightu)
alias_targets() { { alias_target "${READ_ALIAS}"; alias_target "${WRITE_ALIAS}"; } | sort -u; }

# preflight_live INDEKS — preflight na żywych aliasach
preflight_live() {
  local targets
  targets="$(alias_targets)" || return 1
  # shellcheck disable=SC2086
  preflight_index_free "$1" ${targets}
}

# reindex_task_ids CEL — id trwających zadań _reindex do CEL
reindex_task_ids() {
  es GET "/_tasks?actions=indices:data/write/reindex&detailed=true" | running_reindex_ids "$1"
}

# cancel_reindex_into CEL — anuluje trwający _reindex do CEL i czeka do zatrzymania
cancel_reindex_into() {
  local target="$1" ids id tries=0
  ids="$(reindex_task_ids "${target}")"
  [[ -n "${ids}" ]] || { info "  brak trwającego _reindex do ${target}"; return 0; }
  for id in ${ids}; do
    info "  anuluję zadanie ${id}"
    es POST "/_tasks/${id}/_cancel" >/dev/null || true
  done
  while [[ -n "$(reindex_task_ids "${target}")" ]]; do
    tries=$((tries + 1))
    [[ ${tries} -le 60 ]] || die "_reindex do ${target} nie zatrzymał się w 120 s"
    sleep 2
  done
  info "  _reindex do ${target} zatrzymany"
}
