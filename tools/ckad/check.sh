#!/usr/bin/env bash
#  tools/ckad/check.sh <NN> — sprawdza zadanie, pokazuje czas na tle limitu, zapisuje wynik.
#    make ckad-check t=07
#  Kod wyjścia: 0 = wszystkie wymagania spełnione.
source "$(dirname "$0")/lib.sh"

t="${1:?Podaj numer zadania, np. 07}"
doc="$(task_doc "${t}")"; script="$(task_script "${t}")"
[[ -n "${doc}" && -f "${script}" ]] || { echo "Nie ma zadania ${t}." >&2; exit 1; }

# shellcheck source=/dev/null
source "${script}"
echo "Zadanie ${t}: $(head -1 "${doc}" | sed 's/^# *//')"
check

limit="$(task_minutes "${doc}")"
elapsed=""
if [[ -f "${HOME}/.ckad-current" ]]; then
  read -r cur_t started cur_lab < "${HOME}/.ckad-current"
  if [[ "${cur_t}" == "${t}" && "${cur_lab}" == "${LAB}" ]]; then
    elapsed=$(( ($(date +%s) - started + 59) / 60 ))
  fi
fi

echo "  PASS=${PASS} FAIL=${FAIL}${elapsed:+  czas: ${elapsed}/${limit} min}"
if [[ -n "${elapsed}" && "${CKAD_NO_SCORE:-}" != 1 ]]; then
  result=$([[ ${FAIL} -eq 0 ]] && echo ok || echo fail)
  printf '%s\t%s\t%s\t%s\t%s\n' "$(date '+%F %T')" "${t}" "${result}" "${elapsed}" "${limit}" >> "${SCORES_FILE}"
  (( elapsed > limit )) && echo "  Za wolno: na egzaminie zostałbyś z tyłu. Powtórz za kilka dni."
fi
[[ ${FAIL} -eq 0 ]]
