#!/usr/bin/env bash
#  tools/chaos/proof.sh fuse|alert <s> — dowody na produkcji dla fazy `chaos` w verify.sh.
#
#   fuse <s>   bezpiecznik: break z ttl 1 min, NIC nie robimy — Job chaos-undo sam
#              wycofuje awarię i zdejmuje blokadę (≤ 3 min), check zielony, Job zakończony
#              sukcesem. Dowód, że auto-wycofanie działa dla czasowników tego scenariusza.
#   alert <s>  alert: break, czekamy aż Alertmanager pokaże ALERT scenariusza (limit:
#              `for:` reguły z Prometheusa + 3 min), potem reset. ALERT bierzemy ze
#              scenariusza, nie z tego pliku.
#
#  Kody: 0 = dowód przeszedł, 1 = nie przeszedł, 3 = pominięty (brak ALERT albo ALERT
#  zależy od ruchu). Przed breakiem czeka na ciszę (wait_quiet).
source "$(dirname "$0")/lib.sh"
CHAOS="${CHAOS_ROOT}/tools/chaos/chaos.sh"
export CHAOS_NO_SCORE=1

mode="${1:-}"; s="${2:-}"
[[ -n "${s}" && -f "$(scenario_file "${s}")" ]] || die "użycie: proof.sh fuse|alert <scenariusz>"

# Zawsze zostawiamy produkcję zdrową: siłowy reset, jeśli blokada nadal jest nasza.
cleanup() {
  [[ "$(lock_get scenario 2>/dev/null)" == "${s}" ]] && bash "${CHAOS}" reset >/dev/null 2>&1
  return 0
}
trap cleanup EXIT

proof_fuse() {
  wait_quiet 600 || { echo "  ${RED}✗${RESET} sklep nie ucichł w 10 min — nie psuję"; return 1; }
  bash "${CHAOS}" break "${s}" 1 >/dev/null || { echo "  ${RED}✗${RESET} break ${s} (ttl 1) odmówił"; return 1; }
  local t0=${SECONDS}
  echo "  awaria założona, ttl 1 min, nie ruszam niczego"
  local st
  st="$(lock_state)"
  while [[ "${st}" != brak ]] && (( SECONDS - t0 < 180 )); do
    sleep 5
    st="$(lock_state)"
  done
  if [[ "${st}" != brak ]]; then
    echo "  ${RED}✗${RESET} blokada nadal jest po 180 s (Job: $(undo_job_state))"; return 1
  fi
  local took=$((SECONDS - t0))
  # Blokada zeszła, więc check niczego już nie zdejmuje — tylko ocenia zdrowie.
  # (do 2 min zapasu: po undo pody jeszcze wstają, to nie jest „ręczna naprawa”)
  if ! wait_for 120 bash "${CHAOS}" check "${s}"; then
    echo "  ${RED}✗${RESET} blokada zdjęta po ${took} s, ale check czerwony"; return 1
  fi
  local js; js="$(undo_job_state)"
  [[ "${js}" == succeeded ]] || { echo "  ${RED}✗${RESET} zdrowo po ${took} s, ale Job: ${js} (oczekiwano succeeded)"; return 1; }
  echo "  ${GREEN}✓${RESET} ${s}: zdrowo bez akcji po ${took} s, Job succeeded"
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
  local for_s cap ttl
  # Gdy Prometheus nie poda `for:` (5 prób), zakładamy 900 s — najdłuższe `for` wśród
  # scenariuszy (KubePodCrashLooping 15 min), żeby limit nie był za krótki.
  for_s="$(alert_for_seconds "${alert}")" || for_s=900
  cap=$((for_s + 180))
  ttl=$(( (cap + 59) / 60 + 3 ))
  wait_quiet 600 || { echo "  ${RED}✗${RESET} sklep nie ucichł w 10 min — nie psuję"; return 1; }
  bash "${CHAOS}" break "${s}" "${ttl}" >/dev/null || { echo "  ${RED}✗${RESET} break ${s} odmówił"; return 1; }
  local t0=${SECONDS} fired=nie
  echo "  awaria założona; czekam na ${alert} (for ${for_s} s, limit ${cap} s)"
  # alert_firing: błąd API = „nie”, czyli czekamy dalej; potwierdzenie tylko z odpowiedzi.
  while [[ "${fired}" != tak ]] && (( SECONDS - t0 < cap )); do
    [[ "$(alert_firing "${alert}")" == tak ]] && fired=tak || sleep 15
  done
  local took=$((SECONDS - t0))
  bash "${CHAOS}" reset >/dev/null || echo "  ${RED}!${RESET} reset zwrócił błąd (cleanup spróbuje ponownie)"
  if [[ "${fired}" == tak ]]; then
    echo "  ${GREEN}✓${RESET} ${s}: ${alert} płonie po ${took} s (for ${for_s} s)"
  else
    echo "  ${RED}✗${RESET} ${s}: ${alert} NIE zapłonął w ${cap} s"; return 1
  fi
}

case "${mode}" in
  fuse)  proof_fuse ;;
  alert) proof_alert ;;
  *) die "użycie: proof.sh fuse|alert <scenariusz>" ;;
esac
