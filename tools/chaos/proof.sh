#!/usr/bin/env bash
#  tools/chaos/proof.sh fuse|alert <s> — dowody na produkcji dla fazy `chaos` w verify.sh.
#
#   fuse <s>   bezpiecznik: break z ttl 1 min, NIC nie robimy — Job chaos-undo sam
#              wycofuje awarię i zdejmuje blokadę, check zielony, Job zakończony sukcesem.
#              Czas mierzymy od końca break do status.completionTime Joba (zegar klastra),
#              limit FUSE_LIMIT_S scenariusza (domyślnie 180 s; es-node-down dłużej, bo jego
#              undo czeka na green). Przerwa w tunelu wydłuża tylko obserwację, nie limit.
#   alert <s>  alert: break, czekamy aż Prometheus pokaże ALERT scenariusza jako firing
#              (limit: `for:` reguły + 3 min), potem reset. Moment zapłonu = activeAt + for
#              z Prometheusa, nie chwila, w której skrypt go zobaczył. Brak reguły = FAIL.
#              ALERT bierzemy ze scenariusza, nie z tego pliku.
#
#  Kody: 0 = dowód przeszedł, 1 = nie przeszedł, 3 = pominięty (brak ALERT albo ALERT
#  zależy od ruchu). Przed breakiem czeka na ciszę (wait_quiet).
source "$(dirname "$0")/lib.sh"
CHAOS="${CHAOS_ROOT}/tools/chaos/chaos.sh"
export CHAOS_NO_SCORE=1

mode="${1:-}"; s="${2:-}"
[[ -n "${s}" && -f "$(scenario_file "${s}")" ]] || die "użycie: proof.sh fuse|alert <scenariusz>"

# api_ok — API k3s odpowiada (tunel żyje). Przy awarii tunelu nie wolno liczyć czasu
# oczekiwania jako „alert nie zapłonął”: limit przedłużamy o czas niedostępności (≤ 10 min).
api_ok() { k get --raw /readyz >/dev/null 2>&1; }

# reset_retry — reset z ponowieniami (≤ 10 × 30 s): tunel potrafi paść akurat przy sprzątaniu.
reset_retry() {
  local i=0
  while (( i < 10 )); do
    bash "${CHAOS}" reset >/dev/null 2>&1 && return 0
    i=$((i + 1)); sleep 30
  done
  return 1
}

# Zawsze zostawiamy produkcję zdrową: siłowy reset, jeśli blokada nadal jest nasza.
cleanup() {
  [[ "$(lock_get scenario 2>/dev/null)" == "${s}" ]] && reset_retry
  return 0
}
trap cleanup EXIT

# iso_epoch <RFC3339> -> sekundy epoki (pusto przy błędzie).
iso_epoch() { jq -rn --arg t "$1" '$t | sub("\\.[0-9]+"; "") | fromdateiso8601' 2>/dev/null; }

proof_fuse() {
  wait_quiet 600 || { echo "  ${RED}✗${RESET} sklep nie ucichł w 10 min — nie psuję"; return 1; }
  local lim0
  # shellcheck source=/dev/null
  lim0="$( set +u; source "$(scenario_file "${s}")"; echo "${FUSE_LIMIT_S:-180}" )"
  bash "${CHAOS}" break "${s}" 1 >/dev/null || { echo "  ${RED}✗${RESET} break ${s} (ttl 1) odmówił"; return 1; }
  local t0=${SECONDS} t0e; t0e="$(date +%s)"
  echo "  awaria założona, ttl 1 min od wejścia awarii, nie ruszam niczego"
  # Czekamy na koniec Joba. „err” (tunel) to nie „Job nie zadziałał”: czas takiej
  # iteracji wydłuża OBSERWACJĘ (≤ 10 min), a ocena i tak idzie po completionTime.
  local js="" lost=0 it
  while [[ "${js}" != succeeded && "${js}" != failed ]] && (( SECONDS - t0 < lim0 + lost )); do
    sleep 5
    it=${SECONDS}
    js="$(undo_job_state)"
    if [[ "${js}" == err && ${lost} -lt 600 ]]; then lost=$((lost + SECONDS - it + 5)); fi
  done
  if [[ "${js}" != succeeded ]]; then
    echo "  ${RED}✗${RESET} Job chaos-undo: ${js:-?} po $((SECONDS - t0)) s (limit ${lim0} s, +${lost} s niedostępności API); blokada: $(lock_state)"; return 1
  fi
  local ct cte took
  ct="$(k -n "${NS}" get job "${UNDO_JOB}" -o jsonpath='{.status.completionTime}' 2>/dev/null)"
  cte="$(iso_epoch "${ct}")"
  [[ "${cte}" =~ ^[0-9]+$ ]] || { echo "  ${RED}✗${RESET} Job succeeded, ale nie odczytałem completionTime ('${ct}')"; return 1; }
  took=$(( cte - t0e ))
  (( took <= lim0 )) || { echo "  ${RED}✗${RESET} Job skończył po ${took} s od wejścia awarii (limit ${lim0} s)"; return 1; }
  # Job kończy się sukcesem tylko po undo && lock_delete, ale sprawdzamy wprost.
  [[ "$(lock_state)" == brak ]] || { echo "  ${RED}✗${RESET} Job succeeded, a blokada nadal jest (albo błąd odczytu)"; return 1; }
  # Blokada zeszła, więc check niczego już nie zdejmuje — tylko ocenia zdrowie.
  # (do 2 min zapasu: po undo pody jeszcze wstają, to nie jest „ręczna naprawa”)
  if ! wait_for 120 bash "${CHAOS}" check "${s}"; then
    echo "  ${RED}✗${RESET} Job wycofał po ${took} s, ale check czerwony"; return 1
  fi
  echo "  ${GREEN}✓${RESET} ${s}: Job wycofał bez akcji po ${took} s (limit ${lim0} s), check zielony"
}

proof_alert() {
  local alert traffic
  # shellcheck source=/dev/null
  alert="$( set +u; source "$(scenario_file "${s}")"; echo "${ALERT:-}" )"
  # shellcheck source=/dev/null
  traffic="$( set +u; source "$(scenario_file "${s}")"; echo "${ALERT_REQUIRES_TRAFFIC:-}" )"
  if [[ -z "${alert}" || -n "${traffic}" ]]; then
    echo "  ${DIM}- ${s}: pomijam (ALERT='${alert}', zależny od ruchu: '${traffic:-nie}')${RESET}"; return 3
  fi
  local for_s cap ttl frc
  # Bez `for:` z Prometheusa nie ma limitu: brak reguły to błąd scenariusza (literówka
  # w ALERT, reguła się nie załadowała), a nie powód, żeby zgadywać 900 s i czekać na nic.
  for_s="$(alert_for_seconds "${alert}")"; frc=$?
  case "${frc}" in
    1) echo "  ${RED}✗${RESET} ${s}: Prometheus nie ma reguły ${alert} — nie psuję"; return 1 ;;
    2) echo "  ${RED}✗${RESET} ${s}: Prometheus nie odpowiada (nie znam for: reguły ${alert}) — nie psuję"; return 1 ;;
  esac
  cap=$((for_s + 180))
  ttl=$(( (cap + 59) / 60 + 3 ))
  wait_quiet 600 || { echo "  ${RED}✗${RESET} sklep nie ucichł w 10 min — nie psuję"; return 1; }
  # Baza: alert nie może płonąć PRZED awarią (zalegający z poprzedniego scenariusza
  # dałby natychmiastowe, fałszywe PASS). „err” (API) = nie wiem, ponawiamy do 2 min.
  local base="err" tries=0
  while [[ "${base}" == err && ${tries} -lt 12 ]]; do
    base="$(alert_state_marketplace "${alert}")"
    [[ "${base}" == err ]] && { sleep 10; tries=$((tries + 1)); }
  done
  if [[ "${base}" != nie ]]; then
    echo "  ${RED}✗${RESET} ${s}: baza przed awarią: ${alert} = ${base} (płonie już albo nieznany) — nie psuję"; return 1
  fi
  bash "${CHAOS}" break "${s}" "${ttl}" >/dev/null || { echo "  ${RED}✗${RESET} break ${s} odmówił"; return 1; }
  local t0=${SECONDS} t0e fired=nie lost=0 st it
  t0e="$(date +%s)"
  echo "  baza: ${alert} nie płonie; awaria założona; czekam na ${alert} (for ${for_s} s, limit ${cap} s)"
  # Tylko alert z reguł sklepu (marketplace) liczy się jako dowód. „err” (API) to nie „nie”:
  # czas takiej iteracji wydłuża obserwację (≤ 10 min); o wyniku decyduje activeAt + for.
  while [[ "${fired}" != tak ]] && (( SECONDS - t0 < cap + lost )); do
    it=${SECONDS}
    st="$(alert_state_marketplace "${alert}")"
    if [[ "${st}" == tak ]]; then fired=tak
    else
      if [[ "${st}" == err && ${lost} -lt 600 ]]; then lost=$((lost + SECONDS - it + 15)); fi
      sleep 15
    fi
  done
  local took=$((SECONDS - t0)) rrc=0 fat="" fire_s
  [[ "${fired}" == tak ]] && fat="$(alert_fired_at "${alert}" "${for_s}")"
  reset_retry || rrc=1
  if [[ "${fired}" != tak ]]; then
    echo "  ${RED}✗${RESET} ${s}: ${alert} NIE zapłonął w ${cap} s (+${lost} s niedostępności API)"; return 1
  fi
  # Zapłon według Prometheusa; bez odczytu activeAt — chwila zobaczenia (górne oszacowanie).
  if [[ "${fat}" =~ ^[0-9]+$ ]]; then fire_s=$(( fat - t0e )); else fire_s=${took}; fi
  if (( fire_s > cap )); then
    echo "  ${RED}✗${RESET} ${s}: ${alert} zapłonął po ${fire_s} s od awarii (limit ${cap} s; zobaczony po ${took} s)"; return 1
  fi
  echo "  ${GREEN}✓${RESET} ${s}: ${alert} płonie po ${fire_s} s od awarii (for ${for_s} s; zobaczony po ${took} s)"
  if [[ ${rrc} -ne 0 ]]; then
    echo "  ${RED}✗${RESET} ${s}: alert potwierdzony, ale reset nie przeszedł po 10 próbach (Job chaos-undo wycofa po ttl)"; return 1
  fi
}

case "${mode}" in
  fuse)  proof_fuse ;;
  alert) proof_alert ;;
  *) die "użycie: proof.sh fuse|alert <scenariusz>" ;;
esac
