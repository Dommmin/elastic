#!/usr/bin/env bash
#  tools/chaos/chaos.sh — „zepsuj i napraw” na produkcji k3s (ETAP G4).
#
#    chaos.sh list                 # scenariusze i objawy (bez rozwiązań)
#    chaos.sh break <s> [ttl=15]   # preflight → blokada → migawka → Job wycofania → awaria
#    chaos.sh check <s>            # ✓/✗ wymagań „zdrowy”; zielony kończy scenariusz
#    chaos.sh reset                # siłowe wycofanie trwającego scenariusza
#    chaos.sh status               # co trwa, od kiedy, kiedy auto-wycofanie
#
#  Kody wyjścia: 0 = sukces/zielony, 1 = czerwony/odmowa.
#  Uwaga: scenariusz definiuje funkcję `break`, która przesłania słowo kluczowe —
#  w tym pliku (i w lib.sh) nie używamy `break` w pętlach.
source "$(dirname "$0")/lib.sh"

usage() { sed -n '3,9p' "$0" | sed 's/^#  \{0,1\}//'; exit 1; }

scenarios() { local f; for f in "${CHAOS_ROOT}"/tools/chaos/scenarios/*.sh; do basename "${f}" .sh; done; }

load_scenario() {
  local f; f="$(scenario_file "$1")"
  [[ -n "$1" && -f "${f}" ]] || die "nieznany scenariusz '${1}'. Dostępne: $(scenarios | tr '\n' ' ')"
  # shellcheck source=/dev/null
  source "${f}"
}

card() { echo "${CHAOS_ROOT}/docs/chaos/$1.md"; }

# Sekcje karty przed „## Pytania prowadzące”: tytuł, objaw, od czego zacząć.
print_card_head() {
  awk '/^## Pytania prowadzące/ { exit } { print }' "$(card "$1")"
}

cluster_reachable() { k get --raw /readyz >/dev/null 2>&1; }

preflight() {
  local bad="" n
  cluster_reachable || die "API k3s nie odpowiada. Uruchom: make k3s-tunnel"
  lock_exists && die "trwa scenariusz '$(lock_get scenario)'. Najpierw: make chaos-check s=$(lock_get scenario) albo make chaos-reset"
  [[ "$(k -n "${NS}" get elasticsearch marketplace -o jsonpath='{.status.health}' 2>/dev/null)" == green ]] \
    || bad+=$'\n'"  - Elasticsearch marketplace nie jest green"
  for n in $(k -n "${NS}" get deployments -o jsonpath='{.items[*].metadata.name}'); do
    [[ "$(deploy_ready "${n}")" == tak ]] || bad+=$'\n'"  - Deployment ${n} nie jest w pełni gotowy"
  done
  for n in $(k -n "${NS}" get statefulsets -o jsonpath='{.items[*].metadata.name}'); do
    [[ "$(sts_ready "${n}")" == tak ]] || bad+=$'\n'"  - StatefulSet ${n} nie jest w pełni gotowy"
  done
  local alerts rc
  alerts="$(marketplace_alerts)"; rc=$?
  if [[ ${rc} -ne 0 ]]; then
    bad+=$'\n'"  - Prometheus nie odpowiada (nie da się sprawdzić alertów)"
  elif [[ -n "${alerts}" ]]; then
    bad+=$'\n'"  - płoną alerty sklepu: $(echo "${alerts}" | sort -u | tr '\n' ',' | sed 's/,$//')"
  fi
  [[ -z "${bad}" ]] || die "preflight: system już jest chory, awaria pomyliłaby diagnozę:${bad}"
}

# Wycofanie przerwanego `break` (tunel padł, Ctrl-C, błąd kroku). Nigdy blokada bez Joba.
BREAK_ARMED=0
abort_break() {
  [[ ${BREAK_ARMED} -eq 1 ]] || return 0
  BREAK_ARMED=0
  trap - INT TERM HUP
  echo "${RED}✗${RESET} break przerwany — wycofuję (undo, Job, blokada)" >&2
  undo || echo "  undo nie przeszło — Job chaos-undo (jeśli powstał) albo make chaos-reset" >&2
  undo_job_delete
  lock_delete
}

cmd_break() {
  local s="${1:-}" ttl="${2:-15}"
  load_scenario "${s}"
  [[ "${ttl}" =~ ^[0-9]+$ && "${ttl}" -ge 1 ]] || die "ttl w minutach (liczba ≥ 1), dostałem '${ttl}'"
  preflight
  lock_create "${s}" "${ttl}" || die "nie udało się założyć blokady (trwa inny scenariusz?)"
  BREAK_ARMED=1
  trap 'abort_break; exit 1' INT TERM HUP
  local tmp; tmp="$(mktemp "${TMPDIR:-/tmp}/chaos-snap.XXXXXX")"
  # shellcheck disable=SC2046  # targets drukuje listę rodzaj/nazwa do rozdzielenia
  snapshot_get $(targets) > "${tmp}" || { rm -f "${tmp}"; abort_break; die "migawka nie powstała"; }
  lock_set snapshot.yaml "${tmp}" || { rm -f "${tmp}"; abort_break; die "zapis migawki do blokady nie przeszedł"; }
  rm -f "${tmp}"
  # Job PRZED awarią: jeśli dalej coś padnie (np. tunel), klaster i tak wróci sam po ttl.
  undo_job_create "${s}" "${ttl}" || { abort_break; die "Job chaos-undo nie powstał"; }
  # shellcheck disable=SC2104  # to funkcja scenariusza, nie słowo kluczowe
  break || { abort_break; die "awaria '${s}' nie weszła — wycofane"; }
  BREAK_ARMED=0
  trap - INT TERM HUP
  echo
  print_card_head "${s}"
  echo
  echo "${DIM}Auto-wycofanie za ${ttl} min (Job chaos-undo). Gdy naprawisz: make chaos-check s=${s}${RESET}"
}

cmd_check() {
  local s="${1:-}"
  load_scenario "${s}"
  echo "${BLD}=== check ${s}${RESET}"
  check
  local mine=0 started=""
  if [[ "$(lock_get scenario)" == "${s}" ]]; then mine=1; started="$(lock_get started)"; fi
  # CHAOS_KEEP_LOCK=1 (selftest): fałszywie zielony check nie może zjeść blokady z migawką.
  [[ "${CHAOS_KEEP_LOCK:-0}" == "1" ]] && mine=0
  if [[ ${FAIL} -eq 0 ]]; then
    if [[ ${mine} -eq 1 ]]; then
      local secs=$(( $(date +%s) - ${started:-$(date +%s)} ))
      undo_job_delete
      lock_delete
      score_add "${s}" "${secs}" ok
      echo "${GREEN}ZDROWY${RESET} — ${PASS}/${PASS} po $((secs / 60)) min $((secs % 60)) s (limit ${LIMIT_MIN} min). Blokada i Job zdjęte."
    else
      echo "${GREEN}ZDROWY${RESET} — ${PASS}/${PASS}"
    fi
    return 0
  fi
  echo "${RED}CHORY${RESET} — ${FAIL} z $((PASS + FAIL)) wymagań niespełnionych"
  return 1
}

cmd_reset() {
  local s
  cluster_reachable || die "API k3s nie odpowiada. Uruchom: make k3s-tunnel"
  s="$(lock_get scenario)"
  if [[ -z "${s}" ]]; then
    undo_job_delete
    echo "Nic nie trwa (pozostałości Joba/skryptów usunięte, jeśli były)."
    return 0
  fi
  load_scenario "${s}"
  local started; started="$(lock_get started)"
  # CHAOS_KEEP_LOCK=1 (selftest): samo undo, blokada i Job zostają — tak wygląda wyścig
  # „Job wycofuje stan, który uczeń już naprawił”; następny reset zrobi undo drugi raz.
  if [[ "${CHAOS_KEEP_LOCK:-0}" == "1" ]]; then
    undo || die "undo '${s}' nie przeszło"
    echo "Scenariusz '${s}': undo wykonane (blokada zostaje)."
    return 0
  fi
  # Najpierw Job: żeby nie wycofywał równolegle z nami.
  undo_job_delete
  undo || die "undo '${s}' nie przeszło — blokada zostaje; popraw i powtórz make chaos-reset"
  lock_delete
  score_add "${s}" "$(( $(date +%s) - ${started:-$(date +%s)} ))" reset
  echo "Scenariusz '${s}' wycofany siłowo (reset)."
}

cmd_status() {
  cluster_reachable || die "API k3s nie odpowiada. Uruchom: make k3s-tunnel"
  local s now started deadline
  s="$(lock_get scenario)"; now="$(date +%s)"
  if [[ -z "${s}" ]]; then
    echo "Nic nie trwa. Job chaos-undo: $(undo_job_state)"
    return 0
  fi
  started="$(lock_get started)"; deadline="$(lock_get deadline)"
  echo "Scenariusz:      ${s}"
  echo "Od startu:       $(( (now - started) / 60 )) min"
  echo "Do wycofania:    $(( (deadline - now + 59) / 60 )) min"
  echo "Job chaos-undo:  $(undo_job_state)"
}

cmd_list() {
  local s
  printf '%-16s %-22s %-6s %s\n' "SCENARIUSZ" "ALERT" "LIMIT" "OBJAW"
  for s in $(scenarios); do
    ( load_scenario "${s}"; printf '%-16s %-22s %-6s %s\n' "${s}" "${ALERT:--}" "${LIMIT_MIN}m" "${SUMMARY}" )
  done
}

case "${1:-}" in
  list)   cmd_list ;;
  break)  shift; cmd_break "$@" ;;
  check)  shift; cmd_check "$@" ;;
  reset)  cmd_reset ;;
  status) cmd_status ;;
  *)      usage ;;
esac
