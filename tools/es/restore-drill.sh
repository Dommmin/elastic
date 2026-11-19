#!/usr/bin/env bash
#  tools/es/restore-drill.sh — drille restore snapshotów (ETAP 10, Task 11).
#
#    restore-drill.sh normal      --repo=local-fs|minio-s3
#        snapshot indeksu spod `products-search` → restore z rename_pattern do
#        products-restored-<ts> → porównanie liczby i próbki 100 _source (+ _version)
#        → usunięcie kopii i snapshotu. Aliasów nie rusza: bez przestoju.
#
#    restore-drill.sh destructive --repo=local-fs|minio-s3
#        ćwiczenie „skasowałem indeks": własna KOPIA (_reindex, wersje zewnętrzne
#        zachowane) products-drill-<ts> → snapshot → USUNIĘCIE kopii → restore pod tą
#        samą nazwą → porównanie ze stanem sprzed usunięcia. Prawdziwego indeksu
#        (celu aliasów) nie dotyka nigdy: bramka drill_destroy_guard.
#
#    restore-drill.sh slm-run
#        polityka SLM `local-nightly`: _execute + czekanie na wynik snapshotu.
#
#  Operacje snapshot/restore wymagają uprawnień klastra, których rola search_service
#  nie ma (i nie powinna mieć), więc skrypt woła ES jako `elastic` z wnętrza es01
#  (hasło zostaje w środowisku kontenera, nie trafia do wyjścia).
#  Funkcje czyste i bramki testuje tools/es/test.sh bez klastra.

_DRILL_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=tools/es/lib.sh
. "${_DRILL_DIR}/lib.sh"

# --- funkcje czyste / bramki ---------------------------------------------------

# drill_name_ok NAZWA — tylko products-drill-<cyfry> i products-restored-<cyfry>
# wolno kasować. Żadnych wildcardów, przecinków ani nazw produkcyjnych.
drill_name_ok() {
  [[ "$1" =~ ^products-(drill|restored)-[0-9]+$ ]]
}

# drill_destroy_guard NAZWA — nazwa ze wzorca ORAZ nie cel aliasów (fail-closed)
drill_destroy_guard() {
  drill_name_ok "$1" || { echo "drill: '$1' nie jest nazwą kopii drilla — odmawiam" >&2; return 1; }
  preflight_live "$1"
}

# slm_snapshot_name <JSON z _slm/policy/X/_execute — nazwa snapshotu
slm_snapshot_name() {
  python3 -c '
import json, sys
try:
    n = json.load(sys.stdin).get("snapshot_name")
except ValueError:
    n = None
if not n:
    sys.exit(1)
print(n)'
}

# snapshot_state <JSON z GET _snapshot/R/S — stan pierwszego snapshotu (puste, gdy brak)
snapshot_state() {
  python3 -c '
import json, sys
snaps = json.load(sys.stdin).get("snapshots", [])
print(snaps[0].get("state", "") if snaps else "")'
}

# --- rozmowa z klastrem jako `elastic` ------------------------------------------

# esa METODA ŚCIEŻKA [JSON]
esa() {
  ${DC} exec -T es01 sh -c \
    'curl -sS --fail-with-body -u "elastic:$ELASTIC_PASSWORD" -H "Content-Type: application/json" -X "$0" "http://localhost:9200$1" ${2:+-d "$2"}' \
    "$1" "$2" "${3:-}"
}

now() { python3 -c 'import time; print("%.1f" % time.time())'; }
elapsed() { python3 -c 'import sys; print("%.1fs" % (float(sys.argv[2]) - float(sys.argv[1])))' "$1" "$2"; }

# json_get WYRAŻENIE_PYTHON <JSON — np. json_get 'd["count"]'
json_get() { python3 -c 'import json,sys; d=json.load(sys.stdin); print(eval(sys.argv[1]))' "$1"; }

# source_index — jedyny indeks pod aliasem odczytu (inaczej odmowa)
source_index() {
  local t
  t="$(alias_target "${READ_ALIAS}")" || die "nie umiem odczytać aliasu ${READ_ALIAS}"
  [[ -n "${t}" && "$(wc -l <<<"${t}")" -eq 1 ]] || die "${READ_ALIAS} nie wskazuje dokładnie jednego indeksu: '${t}'"
  printf '%s' "${t}"
}

# sample_of INDEKS PLIK — 100 dokumentów (id z indeksu, mget) znormalizowanych do JSON
sample_of() {
  local index="$1" out="$2" ids
  ids="$(esa POST "/${index}/_search" '{"size":100,"_source":false,"sort":["_doc"]}' | json_get 'json.dumps([h["_id"] for h in d["hits"]["hits"]])')" \
    || die "nie umiem pobrać id próbki z ${index}"
  printf '%s' "${ids}" > "${out}.ids"
  esa POST "/${index}/_mget" "{\"ids\":${ids}}" | python3 -c '
import json, sys
docs = json.load(sys.stdin)["docs"]
norm = sorted(({"id": x["_id"], "found": x.get("found"), "version": x.get("_version"), "source": x.get("_source")} for x in docs), key=lambda x: x["id"])
json.dump(norm, sys.stdout, sort_keys=True)' > "${out}" || die "mget próbki z ${index} nie powiódł się"
}

# sample_by_ids INDEKS PLIK_IDS PLIK — jak wyżej, ale dla zadanych id (ta sama próbka po restore)
sample_by_ids() {
  local index="$1" idsfile="$2" out="$3"
  esa POST "/${index}/_mget" "{\"ids\":$(cat "${idsfile}")}" | python3 -c '
import json, sys
docs = json.load(sys.stdin)["docs"]
norm = sorted(({"id": x["_id"], "found": x.get("found"), "version": x.get("_version"), "source": x.get("_source")} for x in docs), key=lambda x: x["id"])
json.dump(norm, sys.stdout, sort_keys=True)' > "${out}" || die "mget próbki z ${index} nie powiódł się"
}

# compare_samples A B — zgodność id, _version i _source; wypisuje SAMPLE=OK <n>
compare_samples() {
  python3 -c '
import json, sys
a = json.load(open(sys.argv[1])); b = json.load(open(sys.argv[2]))
if not a:
    print("SAMPLE=FAIL pusta próbka"); sys.exit(1)
if a != b:
    diff = [x["id"] for x, y in zip(a, b) if x != y][:5]
    print("SAMPLE=FAIL różnice w dokumentach:", diff, "(%d vs %d)" % (len(a), len(b))); sys.exit(1)
if not all(x["found"] for x in a):
    print("SAMPLE=FAIL brak dokumentów"); sys.exit(1)
print("SAMPLE=OK %d" % len(a))' "$1" "$2"
}

count_of() { esa GET "/$1/_count" | json_get 'd["count"]'; }

# index_exists INDEKS — kod 0, gdy istnieje
# (GET _cat/indices: 404 dla brakującego; `curl -X HEAD` wisi w oczekiwaniu na ciało)
index_exists() { esa GET "/_cat/indices/$1?h=index" >/dev/null 2>&1; }

# snap_create REPO SNAP INDEKS — snapshot do końca, wypisuje stan; kod 1 gdy ≠ SUCCESS
snap_create() {
  local repo="$1" snap="$2" index="$3" state
  esa PUT "/_snapshot/${repo}/${snap}?wait_for_completion=true" \
    "{\"indices\":\"${index}\",\"include_global_state\":false}" >/dev/null || die "snapshot ${snap} w ${repo} nie powiódł się"
  state="$(esa GET "/_snapshot/${repo}/${snap}" | snapshot_state)"
  [[ "${state}" == "SUCCESS" ]] || die "snapshot ${snap}: stan ${state:-?}"
}

# snap_size REPO SNAP — „<bajty> B w <pliki> plikach (przyrost <bajty> B)"
snap_size() {
  esa GET "/_snapshot/$1/$2/_status" | python3 -c '
import json, sys
s = json.load(sys.stdin)["snapshots"][0]["stats"]
print("%d B (%.1f MB), plików %d; przyrost %.1f MB" % (s["total"]["size_in_bytes"], s["total"]["size_in_bytes"]/1048576, s["total"]["file_count"], s["incremental"]["size_in_bytes"]/1048576))'
}

# restore_as REPO SNAP INDEKS_ZRODLOWY NOWA_NAZWA — restore z rename_pattern, bez aliasów
restore_as() {
  local repo="$1" snap="$2" from="$3" to="$4"
  esa POST "/_snapshot/${repo}/${snap}/_restore?wait_for_completion=true" \
    "{\"indices\":\"${from}\",\"rename_pattern\":\"(.+)\",\"rename_replacement\":\"${to}\",\"include_aliases\":false,\"include_global_state\":false,\"index_settings\":{\"index.number_of_replicas\":0},\"ignore_index_settings\":[\"index.lifecycle.name\"]}" >/dev/null \
    || die "restore ${snap} → ${to} nie powiódł się"
  esa GET "/_cluster/health/${to}?wait_for_status=yellow&timeout=120s" >/dev/null || die "${to} nie osiągnął statusu yellow"
}

drop_index() {
  drill_destroy_guard "$1" || return 1
  esa DELETE "/$1?ignore_unavailable=true" >/dev/null
}
drop_snapshot() { esa DELETE "/_snapshot/$1/$2" >/dev/null 2>&1 || true; }

# --- scenariusze -----------------------------------------------------------------

repo_check() {
  [[ "$1" =~ ^[a-z0-9-]+$ ]] || die "zła nazwa repozytorium: '$1'"
  esa GET "/_snapshot/$1" >/dev/null 2>&1 || die "repozytorium '$1' nie istnieje (make es-snapshots-setup)"
}

run_normal() {
  local repo="$1" ts src snap restored t0 t1 t2 tmp ok=1
  repo_check "${repo}"
  ts="$(date +%s)"; snap="drill-${ts}"; restored="products-restored-${ts}"
  src="$(source_index)"
  tmp="$(mktemp -d)"
  # shellcheck disable=SC2064
  trap "drop_index '${restored}' 2>/dev/null; drop_snapshot '${repo}' '${snap}'; rm -rf '${tmp}'" EXIT
  drill_destroy_guard "${restored}" || die "bramka odrzuciła ${restored}"
  info "Drill (nieniszczący): repo=${repo} źródło=${src} → ${restored}"

  # liczba ze źródła PRZED snapshotem: snapshot to stan z chwili startu, a strumień
  # może dopisywać dokumenty w trakcie drilla
  local c_src c_res
  c_src="$(count_of "${src}")"
  t0="$(now)"; snap_create "${repo}" "${snap}" "${src}"; t1="$(now)"
  info "  snapshot ${snap}: $(elapsed "${t0}" "${t1}"), $(snap_size "${repo}" "${snap}")"
  restore_as "${repo}" "${snap}" "${src}" "${restored}"; t2="$(now)"
  info "  restore → ${restored}: $(elapsed "${t1}" "${t2}")"

  c_res="$(count_of "${restored}")"
  info "  liczba dokumentów: źródło=${c_src} kopia=${c_res}"
  [[ "${c_src}" == "${c_res}" ]] || { info "COUNT=FAIL"; ok=0; }
  sample_of "${src}" "${tmp}/a.json"
  sample_by_ids "${restored}" "${tmp}/a.json.ids" "${tmp}/b.json"
  compare_samples "${tmp}/a.json" "${tmp}/b.json" | sed 's/^/  /' || ok=0
  [[ ${ok} -eq 1 ]] && info "DRILL=PASS repo=${repo} snapshot=$(elapsed "${t0}" "${t1}") restore=$(elapsed "${t1}" "${t2}") docs=${c_res}" || { info "DRILL=FAIL repo=${repo}"; return 1; }
}

# reindex_wait TASK — czeka na koniec zadania; kod 1, gdy były błędy
reindex_wait() {
  local task="$1" out
  while :; do
    out="$(esa GET "/_tasks/${task}")" || die "nie umiem odczytać zadania ${task}"
    if [[ "$(json_get 'd.get("completed")' <<<"${out}")" == "True" ]]; then
      [[ "$(json_get 'd["response"].get("failures", []) == [] and not d["response"].get("timed_out") and d.get("error") is None' <<<"${out}")" == "True" ]] \
        || die "_reindex zakończył się błędami"
      json_get '"created=%d" % d["response"]["created"]' <<<"${out}"
      return 0
    fi
    sleep 3
  done
}

run_destructive() {
  local repo="$1" ts src snap copy t0 t1 t2 t3 tmp ok=1
  repo_check "${repo}"
  ts="$(date +%s)"; snap="drill-d-${ts}"; copy="products-drill-${ts}"
  src="$(source_index)"
  tmp="$(mktemp -d)"
  # shellcheck disable=SC2064
  trap "drop_index '${copy}' 2>/dev/null; drop_snapshot '${repo}' '${snap}'; rm -rf '${tmp}'" EXIT
  drill_destroy_guard "${copy}" || die "bramka odrzuciła ${copy}"
  info "Drill (niszczący): repo=${repo}; kopia ${copy} z ${src} — prawdziwy indeks nietknięty"

  # kopia: mapowanie i ustawienia z źródła (bez pól systemowych), repliki 0, wersje zewnętrzne zachowane
  esa GET "/${src}" | python3 -c '
import json, sys
idx = next(iter(json.load(sys.stdin).values()))
s = idx["settings"]["index"]
for k in ("uuid", "creation_date", "provided_name", "version", "routing", "lifecycle", "resize", "blocks", "history"):
    s.pop(k, None)
s["number_of_replicas"] = "0"
json.dump({"settings": {"index": s}, "mappings": idx["mappings"]}, sys.stdout)' > "${tmp}/create.json" || die "nie umiem zbudować definicji kopii"
  esa PUT "/${copy}" "$(cat "${tmp}/create.json")" >/dev/null || die "utworzenie ${copy} nie powiodło się"
  t0="$(now)"
  local task
  task="$(esa POST "/_reindex?wait_for_completion=false&slices=auto&refresh=true" \
    "{\"source\":{\"index\":\"${src}\"},\"dest\":{\"index\":\"${copy}\",\"version_type\":\"external\"}}" | json_get 'd["task"]')" || die "start _reindex nie powiódł się"
  info "  kopia przez _reindex (zadanie ${task})…"
  info "  $(reindex_wait "${task}"), czas $(elapsed "${t0}" "$(now)")"
  esa POST "/${copy}/_refresh" >/dev/null

  local c_before c_after
  c_before="$(count_of "${copy}")"
  sample_of "${copy}" "${tmp}/before.json"
  info "  kopia gotowa: ${c_before} dokumentów (źródło ma $(count_of "${src}"))"

  t0="$(now)"; snap_create "${repo}" "${snap}" "${copy}"; t1="$(now)"
  info "  snapshot ${snap}: $(elapsed "${t0}" "${t1}"), $(snap_size "${repo}" "${snap}")"

  drop_index "${copy}" || die "nie udało się usunąć ${copy}"
  index_exists "${copy}" && die "${copy} nadal istnieje po DELETE"
  info "  USUNIĘTO ${copy} (symulacja awarii)"

  t2="$(now)"; restore_as "${repo}" "${snap}" "${copy}" "${copy}"; t3="$(now)"
  info "  restore pod tą samą nazwą: $(elapsed "${t2}" "${t3}")"

  c_after="$(count_of "${copy}")"
  info "  liczba dokumentów: przed=${c_before} po restore=${c_after}"
  [[ "${c_before}" == "${c_after}" ]] || { info "COUNT=FAIL"; ok=0; }
  sample_by_ids "${copy}" "${tmp}/before.json.ids" "${tmp}/after.json"
  compare_samples "${tmp}/before.json" "${tmp}/after.json" | sed 's/^/  /' || ok=0
  [[ ${ok} -eq 1 ]] && info "DRILL=PASS repo=${repo} snapshot=$(elapsed "${t0}" "${t1}") restore=$(elapsed "${t2}" "${t3}") docs=${c_after}" || { info "DRILL=FAIL repo=${repo}"; return 1; }
}

run_slm() {
  local policy="local-nightly" out snap state tries=0 t0
  t0="$(now)"
  out="$(esa POST "/_slm/policy/${policy}/_execute")" || die "_execute polityki ${policy} nie powiódł się (make es-snapshots-setup)"
  snap="$(slm_snapshot_name <<<"${out}")" || die "brak nazwy snapshotu w odpowiedzi _execute"
  info "SLM ${policy}: uruchomiono snapshot ${snap}"
  while :; do
    state="$(esa GET "/_snapshot/local-fs/${snap}" 2>/dev/null | snapshot_state)" || state=""
    case "${state}" in
      SUCCESS) info "SLM=SUCCESS snapshot=${snap} czas=$(elapsed "${t0}" "$(now)")"; return 0 ;;
      FAILED|PARTIAL|INCOMPATIBLE) info "SLM=${state} snapshot=${snap}"; return 1 ;;
    esac
    tries=$((tries + 1)); [[ ${tries} -le 120 ]] || die "snapshot ${snap} nie zakończył się w 4 min"
    sleep 2
  done
}

main() {
  local mode="${1:-}" repo=""
  shift || true
  for a in "$@"; do
    case "${a}" in --repo=*) repo="${a#--repo=}" ;; *) die "nieznany argument: ${a}" ;; esac
  done
  case "${mode}" in
    normal|destructive)
      [[ -n "${repo}" ]] || die "użycie: restore-drill.sh ${mode} --repo=local-fs|minio-s3"
      [[ "${repo}" =~ ^[a-z0-9-]+$ ]] || die "zła nazwa repozytorium: '${repo}'"
      if [[ "${mode}" == normal ]]; then run_normal "${repo}"; else run_destructive "${repo}"; fi ;;
    slm-run) run_slm ;;
    *) die "użycie: restore-drill.sh normal|destructive --repo=NAZWA | slm-run" ;;
  esac
}

if [[ "${BASH_SOURCE[0]}" == "${0}" ]]; then
  set -uo pipefail
  main "$@"
fi
