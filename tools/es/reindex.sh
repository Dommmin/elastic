#!/usr/bin/env bash
#  tools/es/reindex.sh — reindeks bez przestoju (ETAP 10, spec 5.3/8.1).
#    run [--source=es|pg] [--slices=auto] [--rps=-1]   pełna migracja vN → vN+1
#    abort                                              wycofanie po switch-write + nadrobienie okna
#    cleanup                                            usuń indeksy bez aliasu (po potwierdzeniu)
#    break                                              dowód bramki: niepełny indeks MUSI jej nie przejść
#  Fail-closed: każdy krok, który zawiedzie, zatrzymuje skrypt.
set -euo pipefail
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 1

STEP_NAMES=(); STEP_SECS=()
OUT_FILE="$(mktemp)"
cleanup_tmp() { rm -f "${OUT_FILE}"; }
trap cleanup_tmp EXIT

# step NAZWA KOMENDA… — mierzy czas, wypisuje na żywo, zostawia wyjście w OUT_FILE
step() {
  local name="$1" start rc=0; shift
  info "── ${name} ────────────────────────────────────────────"
  start="${SECONDS}"
  set +e
  "$@" 2>&1 | tee "${OUT_FILE}"
  rc="${PIPESTATUS[0]}"
  set -e
  STEP_NAMES+=("${name}"); STEP_SECS+=("$((SECONDS - start))")
  info "   (${name}: $(fmt_secs $((SECONDS - start))), kod ${rc})"
  return "${rc}"
}

cmd_run() {
  local source=es slices=auto rps=-1 arg
  for arg in "$@"; do
    case "${arg}" in
      --source=*) source="${arg#*=}" ;;
      --slices=*) slices="${arg#*=}" ;;
      --rps=*)    rps="${arg#*=}" ;;
      *) die "nieznany argument: ${arg}" ;;
    esac
  done
  [[ "${source}" == es || "${source}" == pg ]] || die "source=es|pg (podano: ${source})"

  local old write target gate ndcg_old ndcg_new
  old="$(alias_target "${READ_ALIAS}")"; write="$(alias_target "${WRITE_ALIAS}")"
  [[ -n "${old}" && "${old}" == "${write}" ]] || die "aliasy muszą wskazywać ten sam indeks (read='${old}', write='${write}') — migracja w toku? make reindex-abort"

  trap 'echo; echo "przerwano — jeśli _reindex trwał, anuluję"; [[ -n "${target:-}" ]] && cancel_reindex_into "${target}"; echo "Stan: make es-health; wycofanie: make reindex-abort"; exit 130' INT TERM

  step prepare sc search:index:migrate prepare || die "prepare nie przeszedł"
  target="$(kv_get TARGET <"${OUT_FILE}")"
  [[ -n "${target}" ]] || die "prepare nie wypisał TARGET="

  step switch-write sc search:index:migrate switch-write || die "switch-write nie przeszedł (cel ${target}); make reindex-abort, jeśli aliasy się zmieniły"

  if [[ "${source}" == es ]]; then
    step reindex sc search:index:migrate reindex --wait "--slices=${slices}" "--rps=${rps}" \
      || die "reindeks nie przeszedł (cel ${target}). Wznowienie: powtórz reindeks; wycofanie: make reindex-abort"
  else
    step backfill sc search:index:backfill "--index=${target}" \
      || die "backfill nie przeszedł (cel ${target}). Wycofanie: make reindex-abort"
  fi

  step finalize sc search:index:migrate finalize || die "finalize nie przeszedł (cel ${target})"

  step gate sc search:index:gate "--index=${target}" || true
  gate="$(kv_get GATE <"${OUT_FILE}")"
  [[ "${gate}" == PASS ]] || die "bramka: GATE=${gate:-brak} — nie przełączam odczytu. make reindex-abort"

  step "ndcg ${old}" ${DC} exec -T catalog-app php artisan search:eval "--index=${old}" || die "search:eval ${old} nie przeszedł"
  ndcg_old="$(ndcg_value <"${OUT_FILE}")" || die "brak NDCG= dla ${old}"
  step "ndcg ${target}" ${DC} exec -T catalog-app php artisan search:eval "--index=${target}" || die "search:eval ${target} nie przeszedł"
  ndcg_new="$(ndcg_value <"${OUT_FILE}")" || die "brak NDCG= dla ${target}"
  if ndcg_regressed "${ndcg_old}" "${ndcg_new}"; then
    die "nDCG spadł: ${old}=${ndcg_old} → ${target}=${ndcg_new} (próg 0.01) — nie przełączam odczytu. make reindex-abort"
  fi

  step switch-read sc search:index:migrate switch-read || die "switch-read odmówił (odczyt zostaje na ${old})"

  summary "${old}" "${target}" "${source}" "${slices}" "${rps}" "${ndcg_old}" "${ndcg_new}"
}

summary() {
  local old="$1" new="$2" source="$3" slices="$4" rps="$5" n_old="$6" n_new="$7" i total=0
  echo
  echo "── PODSUMOWANIE (do wklejenia w POMIARY) ──────────────"
  echo "reindeks ${old} → ${new}; source=${source} slices=${slices} rps=${rps}"
  for i in "${!STEP_NAMES[@]}"; do
    printf '| %-28s | %6s s |\n' "${STEP_NAMES[$i]}" "${STEP_SECS[$i]}"
    total=$((total + STEP_SECS[i]))
  done
  printf '| %-28s | %6s s |\n' "razem" "${total}"
  echo "nDCG@10: ${old}=${n_old} ${new}=${n_new}"
  echo "rozmiary (_cat/indices):"
  es GET "/_cat/indices/${old},${new}?h=index,docs.count,store.size&s=index" || true
}

cmd_abort() {
  local write
  write="$(alias_target "${WRITE_ALIAS}")"
  [[ -n "${write}" ]] || die "alias ${WRITE_ALIAS} nie istnieje"
  info "── 1/4 anulowanie _reindex do ${write} ──"
  cancel_reindex_into "${write}"
  info "── 2/4 search:index:migrate abort ──"
  sc search:index:migrate abort | tee "${OUT_FILE}"
  info "   okno od: $(kv_get REPROJECT_NEEDED_SINCE <"${OUT_FILE}")"
  info "── 3/4 backfill ${WRITE_ALIAS} (produkty z catalogu — nowe i zmienione w oknie) ──"
  sc search:index:backfill "--index=${WRITE_ALIAS}"
  info "── 4/4 reproject: ID z porzuconego ${write} → ${WRITE_ALIAS} (nadrabia usunięcia: 404 → delete) ──"
  sc search:reproject "--ids-from=${write}" "--write-to=${WRITE_ALIAS}"
  info "Gotowe. ${write} zostaje do diagnostyki (usunie go make reindex-cleanup)."
}

cmd_cleanup() {
  local preview idx answer
  preview="$(sc search:index:migrate cleanup)"
  echo "${preview}"
  local candidates; candidates="$(echo "${preview}" | cleanup_candidates)"
  [[ -n "${candidates}" ]] || { info "Nic do usunięcia."; return 0; }
  for idx in ${candidates}; do preflight_live "${idx}" || die "odmowa: ${idx}"; done
  printf 'Usunąć powyższe indeksy NA ZAWSZE? Wpisz "tak": '
  read -r answer || answer=""
  [[ "${answer}" == tak ]] || { info "Przerwano — nic nie usunięto."; return 0; }
  sc search:index:migrate cleanup --yes
}

cmd_break() {
  brk=""
  local ts read_target count max out gate rc=0
  ts="$(date +%s)"; brk="products-break-${ts}"
  read_target="$(alias_target "${READ_ALIAS}")"
  [[ -n "${read_target}" ]] || die "alias ${READ_ALIAS} nie istnieje"
  preflight_live "${brk}" || die "odmowa"
  # Zawsze sprzątamy indeks testowy; usuwamy WYŁĄCZNIE po nazwie (nigdy aliasy).
  trap '[[ -z "${brk}" ]] || es DELETE "/${brk}" >/dev/null 2>&1 || true; cleanup_tmp' EXIT
  trap 'exit 130' INT TERM
  count="$(es GET "/${read_target}/_count" | python3 -c 'import json,sys; print(json.load(sys.stdin)["count"])')"
  max=$((count * 30 / 100)); [[ ${max} -ge 1 ]] || max=1
  info "── ${read_target}: ${count} dok.; kopiuję max_docs=${max} (~30 %) do ${brk} (bez aliasów) ──"
  es POST "/_reindex?wait_for_completion=true&refresh=true" \
    "{\"max_docs\":${max},\"source\":{\"index\":\"${read_target}\"},\"dest\":{\"index\":\"${brk}\"}}" \
    | python3 -c 'import json,sys; r=json.load(sys.stdin); print("  skopiowano created=%s total=%s failures=%s" % (r.get("created"), r.get("total"), len(r.get("failures", []))))'
  info "── bramka na niepełnym indeksie (oczekiwane GATE=FAIL) ──"
  set +e; out="$(sc search:index:gate "--index=${brk}" 2>&1)"; rc=$?; set -e
  echo "${out}"
  gate="$(echo "${out}" | kv_get GATE)"
  if [[ "${gate}" == FAIL && ${rc} -ne 0 ]]; then
    info "✓ bramka odmówiła niepełnemu indeksowi (GATE=FAIL) — działa."
    return 0
  fi
  die "bramka NIE odmówiła niepełnemu indeksowi (GATE=${gate:-brak}, kod ${rc}) — nie wolno jej ufać"
}

case "${1:-}" in
  run)     shift; cmd_run "$@" ;;
  abort)   cmd_abort ;;
  cleanup) cmd_cleanup ;;
  break)   cmd_break ;;
  *) die "użycie: reindex.sh run [--source=es|pg] [--slices=auto] [--rps=-1] | abort | cleanup | break" ;;
esac
