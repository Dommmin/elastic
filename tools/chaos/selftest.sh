#!/usr/bin/env bash
#  tools/chaos/selftest.sh [s ...] — dowód, że scenariusze nie kłamią (na produkcji!).
#
#  Dla każdego scenariusza:
#   1. break (ttl 30) — awaria musi wejść,
#   2. check MUSI dać FAIL (zepsute ≠ zdrowe),
#   3. reset z CHAOS_KEEP_LOCK=1 (undo, blokada z migawką zostaje), potem zwykły reset
#      (undo drugi raz z tej samej migawki + zdjęcie blokady i Joba): drugi undo nie może
#      zmienić metadata.generation obiektów z targets() (idempotentne undo = brak nowego
#      rolloutu); scenariusz bez targets musi tylko zakończyć drugi reset kodem 0,
#   4. check MUSI dać PASS.
#  Przed każdym break: wait_quiet (lib.sh) — czeka ≤ 10 min na wygaśnięcie alertów.
#  Wynik: OK <s> / BAD <s> (<etap>), kod 1 przy którymkolwiek BAD.
#
#    make chaos-selftest              # wszystkie
#    make chaos-selftest s=bad-probe  # wybrane
source "$(dirname "$0")/lib.sh"
CHAOS="${CHAOS_ROOT}/tools/chaos/chaos.sh"
export CHAOS_NO_SCORE=1

if [[ $# -gt 0 ]]; then
  list=("$@")
else
  list=()
  for f in "${CHAOS_SCENARIOS_DIR}"/*.sh; do list+=("$(basename "${f}" .sh)"); done
fi

gens() { # gens <s> -> „rodzaj/nazwa=generation ...” celów scenariusza
  ( # shellcheck source=/dev/null
    source "$(scenario_file "$1")"
    local t
    for t in $(targets); do printf '%s=%s ' "${t}" "$(generation "${t}")"; done )
}

# settle <s> — czeka (≤ 90 s), aż kontrolery skończą reagować na undo: generation celów
# stała przez 6 s i observedGeneration == generation. Inaczej drugi undo trafiłby
# w środek pracy kontrolera Deploymentu (który sam aktualizuje adnotację revision)
# i mierzylibyśmy jego zmianę, nie naszą.
observed_all() {
  ( # shellcheck source=/dev/null
    source "$(scenario_file "$1")"
    local t
    for t in $(targets); do
      [[ "$(k -n "${NS}" get "${t}" -o jsonpath='{.status.observedGeneration}' 2>/dev/null)" == "$(generation "${t}")" ]] \
        || [[ -z "$(k -n "${NS}" get "${t}" -o jsonpath='{.status.observedGeneration}' 2>/dev/null)" ]] || exit 1
    done )
}
settle() {
  local a b deadline=$((SECONDS + 90))
  while (( SECONDS < deadline )); do
    a="$(gens "$1")"; sleep 6; b="$(gens "$1")"
    [[ "${a}" == "${b}" ]] && observed_all "$1" && return 0
  done
  return 1
}

ok=0; bad=0; report=""
for s in "${list[@]}"; do
  echo "${BLD}=== ${s}${RESET}"
  stage=""
  t0=${SECONDS}
  # Alerty po poprzednim scenariuszu gasną minutami, a preflight odmawia awarii przy
  # płonących. Czekamy (limit 10 min); błąd API = czekamy dalej, nie „cicho”.
  if ! wait_quiet 600; then
    stage="alerty/stan sklepu nie ucichły w 10 min"
  elif ! bash "${CHAOS}" break "${s}" 30; then
    stage="break"
  elif CHAOS_KEEP_LOCK=1 bash "${CHAOS}" check "${s}"; then
    stage="check zielony po break"
  elif ! CHAOS_KEEP_LOCK=1 bash "${CHAOS}" reset; then
    stage="reset 1"
  else
    settle "${s}" || echo "  (uwaga: cele nie ustabilizowały się w 90 s)"
    g1="$(gens "${s}")"
    if ! bash "${CHAOS}" reset; then
      stage="reset 2"
    else
      settle "${s}" || true
      g2="$(gens "${s}")"
      echo "  generation po reset 1: ${g1:-<brak celów>}"
      echo "  generation po reset 2: ${g2:-<brak celów>}"
      if [[ "${g1}" != "${g2}" ]]; then
        stage="reset 2 zmienił generation"
      elif ! bash "${CHAOS}" check "${s}"; then
        stage="check czerwony po reset"
      fi
    fi
  fi
  if [[ -z "${stage}" ]]; then
    ok=$((ok + 1)); report+="OK ${s} ($((SECONDS - t0)) s)"$'\n'
  else
    bad=$((bad + 1)); report+="BAD ${s} (${stage})"$'\n'
    # Nie zostawiamy produkcji zepsutej: siłowy reset, jeśli trwa TEN scenariusz.
    [[ "$(lock_get scenario)" == "${s}" ]] && { bash "${CHAOS}" reset >/dev/null 2>&1 || true; }
  fi
done

echo
printf '%s' "${report}"
echo "selftest chaos: OK=${ok} BAD=${bad}"
[[ ${bad} -eq 0 ]]
