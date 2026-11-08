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

# gens <s> -> „rodzaj/nazwa=generation ...” celów scenariusza. Kod 1, gdy którejś
# generation nie da się odczytać (błąd API): dwa puste odczyty nie mogą „się zgadzać”.
gens() {
  ( # shellcheck source=/dev/null
    source "$(scenario_file "$1")"
    local t g
    for t in $(targets); do
      g="$(generation "${t}")"
      [[ "${g}" =~ ^[0-9]+$ ]] || { printf '%s=? ' "${t}"; exit 1; }
      printf '%s=%s ' "${t}" "${g}"
    done )
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
    a="$(gens "$1")" || { sleep 2; continue; }
    sleep 6
    b="$(gens "$1")" || continue
    [[ "${a}" == "${b}" ]] && observed_all "$1" && return 0
  done
  return 1
}

# selftest_one <s> — ustawia globalne `stage` (puste = OK). Funkcja z return zamiast
# łańcucha elif: słowo kluczowe break jest tu zakazane (scenariusz je przesłania).
selftest_one() {
  local s="$1" tmp rc out g1 g2
  stage=""
  # Alerty po poprzednim scenariuszu gasną minutami, a preflight odmawia awarii przy
  # płonących. Czekamy (limit 10 min); błąd API = czekamy dalej, nie „cicho”.
  wait_quiet 600 || { stage="alerty/stan sklepu nie ucichły w 10 min"; return; }
  bash "${CHAOS}" break "${s}" 30 || { stage="break"; return; }
  # Czerwony check po break musi być DIAGNOZĄ (linia CHORY), nie dowolnym błędem
  # (tunel padł, die): kod ≠ 0 bez CHORY nie dowodzi, że awaria jest widoczna.
  tmp="$(mktemp "${TMPDIR:-/tmp}/chaos-selftest.XXXXXX")"
  CHAOS_KEEP_LOCK=1 bash "${CHAOS}" check "${s}" 2>&1 | tee "${tmp}"
  rc=${PIPESTATUS[0]}
  out="$(cat "${tmp}")"; rm -f "${tmp}"
  [[ ${rc} -ne 0 ]] || { stage="check zielony po break"; return; }
  [[ "${out}" == *CHORY* ]] || { stage="check po break: kod ${rc} bez CHORY (błąd, nie diagnoza)"; return; }
  CHAOS_KEEP_LOCK=1 bash "${CHAOS}" reset || { stage="reset 1"; return; }
  settle "${s}" || echo "  (uwaga: cele nie ustabilizowały się w 90 s)"
  g1="$(gens "${s}")" || { stage="nie odczytałem generation po reset 1 (${g1})"; return; }
  bash "${CHAOS}" reset || { stage="reset 2"; return; }
  settle "${s}" || true
  g2="$(gens "${s}")" || { stage="nie odczytałem generation po reset 2 (${g2})"; return; }
  echo "  generation po reset 1: ${g1:-<brak celów>}"
  echo "  generation po reset 2: ${g2:-<brak celów>}"
  [[ "${g1}" == "${g2}" ]] || { stage="reset 2 zmienił generation"; return; }
  # Po undo workload dochodzi do siebie z opóźnieniem (np. outbox-publisher po
  # crashloopie wstaje z backoffem i drenuje zaległe wpisy), więc zielony check
  # ma do 2,5 min — jak uczeń odpytujący make chaos-check.
  if ! wait_for 150 bash "${CHAOS}" check "${s}"; then
    bash "${CHAOS}" check "${s}" || true
    stage="check czerwony po reset"
  fi
}

ok=0; bad=0; report=""
for s in "${list[@]}"; do
  echo "${BLD}=== ${s}${RESET}"
  t0=${SECONDS}
  selftest_one "${s}"
  if [[ -z "${stage}" ]]; then
    ok=$((ok + 1)); report+="OK ${s} ($((SECONDS - t0)) s)"$'\n'
    continue
  fi
  bad=$((bad + 1)); report+="BAD ${s} (${stage})"$'\n'
  # Nie zostawiamy produkcji zepsutej: siłowy reset, jeśli trwa TEN scenariusz. Wynik
  # sprzątania jest widoczny — nieudany reset to głośne ostrzeżenie, nie ciche „|| true”.
  cur="$(lock_get scenario)"; lrc=$?
  if [[ ${lrc} -eq 2 ]]; then
    echo "${RED}${BLD}!! nie wiem, czy trwa ${s} (błąd API przy odczycie blokady) — sprawdź: make chaos-status${RESET}" >&2
    report+="!! ${s}: stan blokady nieznany po BAD (make chaos-status)"$'\n'
  elif [[ ${lrc} -eq 0 && "${cur}" == "${s}" ]]; then
    if ! bash "${CHAOS}" reset; then
      echo "${RED}${BLD}!! PRODUKCJA MOŻE BYĆ ZEPSUTA: reset po BAD ${s} nie przeszedł. make chaos-status, potem make chaos-reset albo karta scenariusza.${RESET}" >&2
      report+="!! ${s}: reset po BAD NIE przeszedł"$'\n'
    fi
  fi
done

echo
printf '%s' "${report}"
echo "selftest chaos: OK=${ok} BAD=${bad}"
[[ ${bad} -eq 0 ]]
