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

# migration_field POLE <JSON z GET /INDEKS/_mapping — `_meta.migration.POLE` (puste,
# gdy indeks nie jest celem migracji). Błąd ES albo nie-JSON = kod 1 (fail-closed).
migration_field() {
  python3 -c '
import json, sys
try:
    data = json.load(sys.stdin)
except ValueError:
    sys.exit(1)
if not isinstance(data, dict) or "error" in data or len(data) != 1:
    sys.exit(1)
meta = next(iter(data.values())).get("mappings", {}).get("_meta", {}).get("migration", {})
value = meta.get(sys.argv[1], "")
if value:
    print(value)' "$1"
}

# migration_source <_mapping — źródło migracji (wznowienie po Ctrl-C)
migration_source() { migration_field source; }

# resume_decision STARY ZAPIS <_mapping celu zapisu — co ma zrobić `run`:
#   STARY == ZAPIS, switch-read dokończony albo nie zaczęty → "fresh"  (kod 0)
#   STARY == ZAPIS, switch_read_started_at bez switched_read_at → odmowa (kod 1): proces padł
#     po przepięciu odczytu, przed krokami po nim; to nie spoczynek — nowa migracja vN+2
#     wystartowałaby z gc_deletes migracji i bez znacznika końca. Naprawa: switch-read.
#   _meta.migration.source celu == STARY   → "resume" (kod 0; migracja przerwana po switch-write)
#   cokolwiek innego (obce źródło, brak _meta, błąd ES, nie-JSON) → powód odmowy, kod 1
resume_decision() {
  local old="$1" write="$2" mapping src started finished
  mapping="$(cat)"
  if [[ "${old}" == "${write}" ]]; then
    started="$(migration_field switch_read_started_at <<<"${mapping}")" \
      && finished="$(migration_field switched_read_at <<<"${mapping}")" \
      || { echo "nie umiem odczytać _meta ${write} (sprawdź make es-health)"; return 1; }
    if [[ -n "${started}" && -z "${finished}" ]]; then
      echo "switch-read na ${write} zaczął się (${started}), ale nie skończył — uruchom search:index:migrate switch-read, żeby dokończyć (docker compose exec search-consumer php bin/console search:index:migrate switch-read)"
      return 1
    fi
    echo fresh; return 0
  fi
  src="$(migration_source <<<"${mapping}")" || { echo "nie umiem odczytać _meta ${write} — make reindex-abort"; return 1; }
  if [[ -n "${src}" && "${src}" == "${old}" ]]; then echo resume; return 0; fi
  echo "aliasy wskazują różne indeksy (read='${old}', write='${write}'), a ${write} nie jest celem migracji z ${old} — make reindex-abort"
  return 1
}

# eval_frozen_ok KOD — czy kod search:eval na ZAMROŻONYM starym indeksie (wznowienie)
# jest do przyjęcia: 0 albo 2 (SearchEvalCommand::TIE_UNSTABLE — remis z różnymi
# ocenami, bo usunięte produkty zostały w starym indeksie bez oceny). Reszta = awaria.
eval_frozen_ok() { [[ "$1" == 0 || "$1" == 2 ]]; }

# migration_switch_write_at <_mapping — chwila switch-write porzuconego indeksu (abort)
migration_switch_write_at() { migration_field switch_write_at; }

# since_minus ISO SEKUNDY — ISO minus SEKUNDY, w UTC (`+00:00`). Początek okna
# nadrabiania po abort. Data bez strefy, śmieci albo zły margines = kod 1.
since_minus() {
  python3 -c '
import datetime, sys
iso, margin = sys.argv[1], sys.argv[2]
if not margin.isdigit():
    sys.exit(1)
try:
    t = datetime.datetime.fromisoformat(iso.replace("Z", "+00:00"))
except ValueError:
    sys.exit(1)
if t.tzinfo is None:
    sys.exit(1)
print((t - datetime.timedelta(seconds=int(margin))).astimezone(datetime.timezone.utc).isoformat(timespec="seconds"))' "$1" "$2"
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
        if "parent_task_id" not in task and (" to " + target) in task.get("description", ""):
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

# sc ARGS… — php bin/console w DZIAŁAJĄCYM search-consumer (krótkie kroki)
sc() { ${DC} exec -T search-consumer php bin/console "$@"; }

# scr ARGS… — php bin/console w OSOBNYM, jednorazowym kontenerze search-consumer
# (długie kroki). search-consumer kończy się co godzinę (`--time-limit=3600`,
# restart: unless-stopped), a razem z nim ginie każde `exec` (kod 137 — Task 12,
# abort zabity po 40 min). `run --rm --no-deps` ma ten sam obraz, env i wolumen,
# ale własny cykl życia; --no-deps: nie budzi zależności, stos już działa.
# </dev/null: run czyta stdin i zjadłby dane podane skryptowi potokiem (jak w 1b7df42).
scr() { ${DC} run --rm --no-deps -T search-consumer php bin/console "$@" </dev/null; }

# sync_queue_depth — liczba wiadomości w search.product.sync (tylko odczyt:
# rabbitmqctl list_queues; nigdy „get” przez narzędzie admina — domyślnie konsumuje).
sync_queue_depth() {
  ${DC} exec -T rabbitmq rabbitmqctl list_queues -q name messages </dev/null 2>/dev/null \
    | awk '$1 == "search.product.sync" { print $2 }'
}

# es METODA ŚCIEŻKA [JSON] — wywołanie ES z danymi dostępowymi z kontenera
es() {
  ${DC} exec -T search-consumer sh -c \
    'curl -sS --fail-with-body -u "$ELASTICSEARCH_USER:$ELASTICSEARCH_PASSWORD" -H "Content-Type: application/json" -X "$0" "$ELASTICSEARCH_HOST$1" ${2:+-d "$2"}' \
    "$1" "$2" "${3:-}"
}

# alias_target ALIAS — indeks(y) pod aliasem, jeden na linię.
# Brak aliasu (404) = pusto, kod 0. Błąd transportu/ES/parsowania = kod 1 (fail-closed).
alias_target() {
  local body rc=0
  body="$(es GET "/_alias/$1" 2>/dev/null)" || rc=$?
  printf '%s' "${body}" | ALIAS_RC="${rc}" python3 -c '
import json, os, sys
rc = int(os.environ["ALIAS_RC"])
try:
    data = json.load(sys.stdin)
except ValueError:
    sys.exit(1)
if not isinstance(data, dict):
    sys.exit(1)
if "error" in data:
    sys.exit(0 if data.get("status") == 404 else 1)
if rc != 0:
    sys.exit(1)
for name in sorted(data):
    print(name)'
}

# alias_targets — cele obu aliasów (do preflightu). Fail-closed: błąd odczytu
# któregokolwiek aliasu albo brak celu odczytu/zapisu = kod 1.
alias_targets() {
  local r w
  r="$(alias_target "${READ_ALIAS}")" || return 1
  w="$(alias_target "${WRITE_ALIAS}")" || return 1
  [[ -n "${r}" && -n "${w}" ]] || return 1
  printf '%s\n%s\n' "${r}" "${w}" | sort -u
}

# preflight_live INDEKS — preflight na żywych aliasach
preflight_live() {
  local targets
  targets="$(alias_targets)" || { echo "preflight: nie umiem odczytać aliasów — odmawiam" >&2; return 1; }
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
  ids="$(reindex_task_ids "${target}")" || die "nie umiem odczytać zadań _reindex"
  [[ -n "${ids}" ]] || { info "  brak trwającego _reindex do ${target}"; return 0; }
  for id in ${ids}; do
    info "  anuluję zadanie ${id}"
    es POST "/_tasks/${id}/_cancel" >/dev/null || true
  done
  while :; do
    ids="$(reindex_task_ids "${target}")" || die "nie umiem odczytać zadań _reindex"
    [[ -n "${ids}" ]] || break
    tries=$((tries + 1))
    [[ ${tries} -le 60 ]] || die "_reindex do ${target} nie zatrzymał się w 120 s"
    sleep 2
  done
  info "  _reindex do ${target} zatrzymany"
}
