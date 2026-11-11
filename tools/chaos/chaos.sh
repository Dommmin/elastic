#!/usr/bin/env bash
#  tools/chaos/chaos.sh — „zepsuj i napraw” na produkcji k3s (ETAP G4).
#
#    chaos.sh list                 # scenariusze i objawy (bez rozwiązań)
#    chaos.sh break <s> [ttl=15]   # preflight → blokada → migawka → Job wycofania → awaria
#    chaos.sh check <s> [--force]  # ✓/✗ wymagań „zdrowy”; zielony kończy scenariusz
#    chaos.sh reset [--force]      # siłowe wycofanie trwającego scenariusza
#    chaos.sh status               # co trwa, od kiedy, kiedy auto-wycofanie
#
#  check i reset odmawiają, gdy w innej powłoce trwa break (blokada w fazie
#  „breaking”); --force (make ... force=1) tylko gdy break padł w połowie.
#
#  Kody wyjścia: 0 = sukces/zielony, 1 = czerwony/odmowa.
#  Uwaga: scenariusz definiuje funkcję `break`, która przesłania słowo kluczowe —
#  w tym pliku (i w lib.sh) nie używamy `break` w pętlach.
source "$(dirname "$0")/lib.sh"

usage() { sed -n '3,12p' "$0" | sed 's/^#  \{0,1\}//'; exit 1; }

# --force wyjmujemy z argumentów (pusta tablica pod set -u w bash 3.2: ${a[@]+...}).
FORCE=0
_args=()
for _a in "$@"; do if [[ "${_a}" == --force ]]; then FORCE=1; else _args+=("${_a}"); fi; done
set -- ${_args[@]+"${_args[@]}"}

scenarios() { local f; for f in "${CHAOS_SCENARIOS_DIR}"/*.sh; do basename "${f}" .sh; done; }

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
  # Listy RAZ i z kontrolą: błąd API albo pusta lista to „nie wiem”, nie „wszystko
  # gotowe” (pętla po pustym wyniku przeszłaby po cichu — jak w wait_quiet).
  local deps sts
  deps="$(k -n "${NS}" get deployments -o jsonpath='{.items[*].metadata.name}' 2>/dev/null)" || deps=""
  sts="$(k -n "${NS}" get statefulsets -o jsonpath='{.items[*].metadata.name}' 2>/dev/null)" || sts=""
  [[ -n "${deps}" ]] || bad+=$'\n'"  - API nie zwróciło listy Deploymentów (błąd albo pusto) — nie wiem, czy są gotowe"
  [[ -n "${sts}" ]] || bad+=$'\n'"  - API nie zwróciło listy StatefulSetów (błąd albo pusto) — nie wiem, czy są gotowe"
  for n in ${deps}; do
    [[ "$(deploy_ready "${n}")" == tak ]] || bad+=$'\n'"  - Deployment ${n} nie jest w pełni gotowy"
  done
  for n in ${sts}; do
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
#  BREAK_ARMED=1  — blokada założona przez TEN proces (BREAK_OWNER), abort ma sprzątać;
#  BREAK_FAULT=1  — funkcja `break` scenariusza ruszyła, abort musi zrobić undo.
#  Przed awarią nic nie jest zepsute: abort tylko zdejmuje Job i blokadę (jeśli nasza).
BREAK_ARMED=0
BREAK_FAULT=0
BREAK_S=""
BREAK_OWNER=""
lock_set_retry() { local _i; for _i in 1 2 3; do lock_set "$@" && return 0; sleep 2; done; return 1; }
# owner_state_retry — lock_owner_state z ponowieniami (3 × 2 s): chwilowy błąd API nie
# może zdecydować o sprzątaniu.
owner_state_retry() {
  local _i o
  for _i in 1 2 3; do
    o="$(lock_owner_state "${BREAK_OWNER}")"
    [[ "${o}" != err ]] && { echo "${o}"; return 0; }
    sleep 2
  done
  echo err
}
# mark_aborted — faza „aborted” tylko na NASZEJ blokadzie (zapis warunkowy na owner), żeby
# make chaos-reset nie wymagał force=1. Komunikat mówi, co dalej, zależnie od wyniku.
mark_aborted() {
  if lock_set_if_owner "${BREAK_OWNER}" phase aborted; then
    echo "${DIM}Blokada w fazie aborted: make chaos-reset zadziała bez force=1.${RESET}" >&2
  else
    echo "${RED}!${RESET} nie zapisałem fazy aborted (API albo blokada już nie nasza). Jeśli make chaos-reset odmówi z powodu fazy breaking: make chaos-reset force=1" >&2
  fi
}
abort_break() {
  [[ ${BREAK_ARMED} -eq 1 ]] || return 0
  BREAK_ARMED=0
  # Kolejne Ctrl-C nie może przerwać wycofania w połowie (np. między undo a rearm).
  trap '' INT TERM HUP
  local own; own="$(owner_state_retry)"
  if [[ ${BREAK_FAULT} -eq 0 ]]; then
    echo "${RED}✗${RESET} break przerwany PRZED awarią — nic nie zepsute, sprzątam Job i blokadę" >&2
    case "${own}" in
      mine) finish_lock || true ;;
      err)
        # Nie wiemy, czy blokada jest nasza: niczego nie kasujemy. Nic nie jest zepsute,
        # więc blokada (i Job, jeśli zdążył powstać) to tylko wstrzymane wdrożenia.
        echo "${RED}!!${RESET} nie odczytałem blokady (błąd API) — Joba ani blokady nie kasuję. Nic nie jest zepsute; blokadę zdejmie Job w terminie albo: make chaos-reset" >&2
        mark_aborted ;;
      *) echo "${RED}!! blokada nie jest już tego break (${own}) — Joba ani blokady nie ruszam.${RESET}" >&2 ;;
    esac
    echo "${DIM}Sprawdź: make chaos-status${RESET}" >&2
    return 0
  fi
  echo "${RED}✗${RESET} break przerwany — wycofuję (undo, potem blokada i Job; Ctrl-C ignorowane do końca)" >&2
  if undo; then
    case "${own}" in
      mine) finish_lock || true ;;
      err)
        echo "${RED}!!${RESET} undo przeszło, ale nie odczytałem blokady (błąd API) — Joba ani blokady nie kasuję; Job zdejmie ją w terminie albo: make chaos-reset" >&2
        mark_aborted ;;
      *) echo "${RED}!! undo przeszło, ale blokada nie jest już tego break (${own}) — Joba ani blokady nie ruszam.${RESET}" >&2 ;;
    esac
    echo "${DIM}Sprawdź: make chaos-status${RESET}" >&2
    return 0
  fi
  # undo nie przeszło: produkcja może być zepsuta. Siatka zostaje: blokada (deploy.sh
  # i preflight odmawiają) + Job chaos-undo z deadline za 60 s. Faza „aborted”, żeby
  # make chaos-reset nie odmawiał jak przy trwającym break.
  if [[ "${own}" == mine || "${own}" == err ]]; then
    mark_aborted
    if rearm_undo "${BREAK_S}"; then
      echo "${RED}${BLD}!! undo NIE przeszło. Blokada chaos-lock zostaje, Job chaos-undo spróbuje za ~60 s.${RESET}" >&2
    else
      echo "${RED}${BLD}!! undo NIE przeszło i Joba chaos-undo nie udało się uzbroić. Blokada zostaje (jeśli API pozwoliło).${RESET}" >&2
    fi
  else
    echo "${RED}${BLD}!! undo NIE przeszło, a blokady tego break już nie ma (${own}) — auto-wycofania może NIE BYĆ.${RESET}" >&2
  fi
  echo "${RED}${BLD}!! Sprawdź: make chaos-status; napraw: make chaos-reset (albo karta scenariusza).${RESET}" >&2
}

cmd_break() {
  local s="${1:-}" ttl="${2:-15}" own
  load_scenario "${s}"
  [[ "${ttl}" =~ ^[0-9]+$ && "${ttl}" -ge 1 ]] || die "ttl w minutach (liczba ≥ 1), dostałem '${ttl}'"
  preflight
  local budget="${BREAK_BUDGET_S:-${BREAK_BUDGET_S_DEFAULT}}"
  BREAK_OWNER="$(date +%s)-$$-${RANDOM}"
  lock_create "${s}" "${ttl}" "${BREAK_OWNER}" "${budget}" || die "nie udało się założyć blokady (trwa inny scenariusz?)"
  BREAK_S="${s}"
  BREAK_FAULT=0
  BREAK_ARMED=1
  trap 'abort_break; exit 1' INT TERM HUP
  local tmp; tmp="$(mktemp "${TMPDIR:-/tmp}/chaos-snap.XXXXXX")"
  # shellcheck disable=SC2046  # targets drukuje listę rodzaj/nazwa do rozdzielenia
  snapshot_get $(targets) > "${tmp}" || { rm -f "${tmp}"; abort_break; die "migawka nie powstała"; }
  lock_set snapshot.yaml "${tmp}" || { rm -f "${tmp}"; abort_break; die "zapis migawki do blokady nie przeszedł"; }
  rm -f "${tmp}"
  # Job PRZED awarią: jeśli dalej coś padnie (np. tunel), klaster i tak wróci sam po ttl.
  undo_job_create "${s}" "${ttl}" "${budget}" || { abort_break; die "Job chaos-undo nie powstał"; }
  # Job, który istnieje, to jeszcze nie Job, który zadziała (obraz się nie ściąga, pod
  # Pending, webhook). Awaria wchodzi dopiero, gdy pod działa i odczytał blokadę.
  echo "${DIM}czekam, aż pod Joba chaos-undo wystartuje (≤ 120 s)…${RESET}"
  if ! wait_for 120 undo_job_started; then
    local info; info="$(undo_pod_info)"
    abort_break
    die "pod Joba chaos-undo nie wystartował w 120 s (pod: ${info}) — awarii NIE wprowadzono"
  fi
  # Druga powłoka (check/reset --force) mogła zdjąć albo podmienić blokadę.
  own="$(lock_owner_state "${BREAK_OWNER}")"
  [[ "${own}" == mine ]] || { abort_break; die "blokada nie jest już tego break (${own}) — awarii NIE wprowadzono"; }
  BREAK_FAULT=1
  # shellcheck disable=SC2104  # to funkcja scenariusza, nie słowo kluczowe
  break || { abort_break; die "awaria '${s}' nie weszła (stan wycofania wyżej)"; }
  # Blokada zniknęła albo zmieniła właściciela w trakcie: awaria bez siatki — wycofujemy od razu.
  own="$(lock_owner_state "${BREAK_OWNER}")"
  case "${own}" in
    mine) ;;
    err) echo "${RED}!${RESET} nie mogę potwierdzić blokady (błąd API) — bezpiecznikiem zostaje Job chaos-undo" >&2 ;;
    *) abort_break; die "blokada zniknęła albo zmieniła właściciela w trakcie break (${own}) — awaria wycofana" ;;
  esac
  # ttl liczy się od wejścia awarii, nie od założenia blokady: start poda Joba i długi
  # break (es-node-down) nie skracają czasu na diagnozę. Job czyta deadline co ≤ 15 s.
  lock_set_retry deadline "$(( $(date +%s) + ttl * 60 ))" \
    || echo "${RED}!${RESET} nie przesunąłem deadline — auto-wycofanie najpóźniej za $(( ttl + budget / 60 )) min (ttl + budżet break)" >&2
  lock_set_retry phase broken \
    || echo "${RED}!${RESET} nie zapisałem fazy broken w blokadzie — check/reset będą wymagały force=1" >&2
  BREAK_ARMED=0
  trap - INT TERM HUP
  echo
  print_card_head "${s}"
  echo
  echo "${DIM}Auto-wycofanie za ${ttl} min (Job chaos-undo). Gdy naprawisz: make chaos-check s=${s}${RESET}"
}

# refuse_if_breaking <faza> <scenariusz> — check/reset nie mogą wejść w trwający break
# z innej powłoki (zjadłyby jego Job i blokadę albo sprzątnęły to, co break właśnie
# założył). --force: break padł w połowie (np. kill -9) i faza „breaking” została.
refuse_if_breaking() {
  [[ "$1" == breaking && ${FORCE} -ne 1 ]] || return 0
  die "trwa break scenariusza '${2}' (blokada w fazie breaking) — poczekaj, aż się skończy. Gdy break padł w połowie: dodaj force=1 (make) albo --force."
}

cmd_check() {
  local s="${1:-}"
  load_scenario "${s}"
  # Tożsamość blokady PRZED asercjami: zielony check zdejmuje blokadę i Job tylko wtedy,
  # gdy po asercjach to nadal TA SAMA blokada (inaczej zjadłby break z innej powłoki,
  # który zaczął się w trakcie check).
  local id0 rc0 st0="" ph0="" sc0=""
  id0="$(lock_ident)"; rc0=$?
  if [[ ${rc0} -eq 0 ]]; then
    IFS='|' read -r _ st0 ph0 sc0 <<<"${id0}"
    refuse_if_breaking "${ph0}" "${sc0}"
  fi
  echo "${BLD}=== check ${s}${RESET}"
  check
  local mine=0 started="" id1 rc1
  if [[ ${rc0} -eq 0 && "${sc0}" == "${s}" ]]; then
    id1="$(lock_ident)"; rc1=$?
    if [[ ${rc1} -eq 0 && "${id1}" == "${id0}" ]]; then mine=1; started="${st0}"; fi
  fi
  [[ "${started}" =~ ^[0-9]+$ ]] || started=""
  # CHAOS_KEEP_LOCK=1 (selftest): fałszywie zielony check nie może zjeść blokady z migawką.
  [[ "${CHAOS_KEEP_LOCK:-0}" == "1" ]] && mine=0
  if [[ ${FAIL} -eq 0 ]]; then
    if [[ ${mine} -eq 1 ]]; then
      local secs=$(( $(date +%s) - ${started:-$(date +%s)} )) frc=0
      # Sprzątanie obiektów pomocniczych scenariusza (np. Secret bad-secret): w haku
      # on_green, nie w check — check tylko ocenia, nie zmienia klastra.
      if declare -F on_green >/dev/null; then
        on_green || echo "${RED}!${RESET} sprzątanie po scenariuszu (on_green) nie przeszło — resztki zdejmie make chaos-reset" >&2
      fi
      finish_lock; frc=$?
      score_add "${s}" "${secs}" ok
      case "${frc}" in
        0) echo "${GREEN}ZDROWY${RESET} — ${PASS}/${PASS} po $((secs / 60)) min $((secs % 60)) s (limit ${LIMIT_MIN} min). Blokada i Job zdjęte." ;;
        *) echo "${GREEN}ZDROWY${RESET} — ${PASS}/${PASS} po $((secs / 60)) min $((secs % 60)) s (limit ${LIMIT_MIN} min). ${RED}Sprzątanie niepełne (wyżej).${RESET}" ;;
      esac
    else
      echo "${GREEN}ZDROWY${RESET} — ${PASS}/${PASS}"
    fi
    return 0
  fi
  echo "${RED}CHORY${RESET} — ${FAIL} z $((PASS + FAIL)) wymagań niespełnionych"
  return 1
}

undo_job_not_active() { [[ "$(undo_job_state)" != active ]]; }

cmd_reset() {
  local s st lrc id0 st0 ph0 sc0
  cluster_reachable || die "API k3s nie odpowiada. Uruchom: make k3s-tunnel"
  st="$(lock_state)"
  case "${st}" in
    err) die "błąd API przy odczycie blokady — niczego nie ruszam (Job zostaje). Powtórz za chwilę." ;;
    brak)
      # Odczyt „brak” mógł się zestarzeć: break z innej powłoki zakłada blokadę i Job
      # w sekundach. Kasujemy tylko Job ZAKOŃCZONY (albo same skrypty bez Joba) i tylko
      # po ponownym potwierdzeniu braku blokady. Działający Job bez blokady kończy się
      # sam w ≤ 15 s (pętla widzi, że blokady nie ma), więc go nie dotykamy.
      local js; js="$(undo_job_state)"
      if [[ "${js}" == active ]]; then
        wait_for 45 undo_job_not_active || true
        js="$(undo_job_state)"
      fi
      case "${js}" in
        active) echo "Nic nie trwa. Job chaos-undo jeszcze działa — zakończy się sam (brak blokady); powtórz make chaos-reset za chwilę, żeby zdjąć resztki."; return 0 ;;
        err) die "błąd API przy odczycie Joba chaos-undo — niczego nie ruszam. Powtórz za chwilę." ;;
      esac
      [[ "$(lock_state)" == brak ]] || die "w trakcie reset pojawiła się blokada (break w innej powłoce?) — niczego nie ruszam. make chaos-status"
      undo_job_delete || die "nie usunąłem pozostałości Joba/skryptów chaos-undo (błąd API). Powtórz za chwilę."
      echo "Nic nie trwa (pozostałości Joba/skryptów usunięte, jeśli były)."
      return 0 ;;
  esac
  id0="$(lock_ident)"; lrc=$?
  [[ ${lrc} -eq 0 ]] || die "nie odczytałem blokady (kod ${lrc}) — niczego nie ruszam"
  IFS='|' read -r _ st0 ph0 sc0 <<<"${id0}"
  s="${sc0}"
  [[ -n "${s}" ]] || die "blokada bez scenariusza — niczego nie ruszam (make chaos-status)"
  refuse_if_breaking "${ph0}" "${s}"
  load_scenario "${s}"
  # CHAOS_KEEP_LOCK=1 (selftest): samo undo, blokada i Job zostają — tak wygląda wyścig
  # „Job wycofuje stan, który uczeń już naprawił”; następny reset zrobi undo drugi raz.
  if [[ "${CHAOS_KEEP_LOCK:-0}" == "1" ]]; then
    undo || die "undo '${s}' nie przeszło"
    echo "Scenariusz '${s}': undo wykonane (blokada zostaje)."
    return 0
  fi
  # Najpierw undo, dopiero po sukcesie Job i blokada. Równoległe undo Joba jest
  # nieszkodliwe (idempotentne), a Job skasowany przed nieudanym undo zostawiłby
  # produkcję zepsutą bez auto-wycofania.
  if ! undo; then
    if rearm_undo "${s}"; then
      die "undo '${s}' NIE przeszło. Blokada zostaje, Job chaos-undo spróbuje ponownie za ~60 s. Popraw ręcznie (karta) i powtórz make chaos-reset."
    fi
    die "undo '${s}' NIE przeszło i Joba chaos-undo nie udało się uzbroić — automatycznego wycofania może NIE BYĆ. Blokada zostaje. Napraw ręcznie wg karty."
  fi
  # Sprzątamy tylko blokadę, którą wycofaliśmy (nie nową z innej powłoki).
  if [[ "$(lock_ident)" != "${id0}" ]]; then
    echo "Scenariusz '${s}': undo wykonane, ale blokada zmieniła się w trakcie — Joba ani blokady nie ruszam (make chaos-status)."
    return 0
  fi
  local frc=0
  finish_lock; frc=$?
  [[ "${st0}" =~ ^[0-9]+$ ]] || st0="$(date +%s)"
  score_add "${s}" "$(( $(date +%s) - st0 ))" reset
  [[ ${frc} -eq 0 ]] || die "Scenariusz '${s}': undo wykonane, ale sprzątanie niepełne (wyżej) — make chaos-status"
  echo "Scenariusz '${s}' wycofany siłowo (reset)."
}

cmd_status() {
  cluster_reachable || die "API k3s nie odpowiada. Uruchom: make k3s-tunnel"
  local st id started phase s now deadline
  st="$(lock_state)"
  case "${st}" in
    err)
      echo "${RED}Blokada: NIE WIEM${RESET} — błąd API przy odczycie chaos-lock (nie znaczy: nic nie trwa)."
      echo "Job chaos-undo:  $(undo_job_state)"
      return 1 ;;
    brak)
      echo "Nic nie trwa. Job chaos-undo: $(undo_job_state)"
      return 0 ;;
  esac
  id="$(lock_ident)" || { echo "${RED}Blokada jest, ale nie odczytałem jej treści (błąd API).${RESET}"; return 1; }
  IFS='|' read -r _ started phase s <<<"${id}"
  deadline="$(lock_get deadline)"
  now="$(date +%s)"
  echo "Scenariusz:      ${s:-? (brak w blokadzie)}"
  echo "Faza:            ${phase:-?}"
  if [[ "${started}" =~ ^[0-9]+$ ]]; then
    echo "Od startu:       $(( (now - started) / 60 )) min"
  else
    echo "Od startu:       ? (brak started w blokadzie)"
  fi
  if [[ "${deadline}" =~ ^[0-9]+$ ]]; then
    if (( deadline >= now )); then echo "Do wycofania:    $(( (deadline - now + 59) / 60 )) min"
    else echo "${RED}Do wycofania:    po terminie o $(( (now - deadline) / 60 )) min${RESET}"; fi
  else
    echo "Do wycofania:    ? (brak deadline w blokadzie)"
  fi
  echo "Job chaos-undo:  $(undo_job_state), pod: $(undo_pod_info)"
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
