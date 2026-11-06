#!/usr/bin/env bash
#  tools/ckad/exam.sh [n=16] [min=120] — symulacja egzaminu CKAD.
#
#  Losuje n zadań (proporcjonalnie po domenach nie trzeba — pula już odpowiada
#  wagom egzaminu), przygotowuje je naraz w labie i drukuje treści. Na egzaminie
#  zadania nie czyszczą się nawzajem, więc tu też nie: setup wszystkich na start,
#  jeden reset przed. Po czasie: make ckad-exam-check (albo ten skrypt z `check`).
#
#  Uwaga: zadania używają rozłącznych nazw zasobów, więc mogą współistnieć.
source "$(dirname "$0")/lib.sh"
require_lab

state="${HOME}/.ckad-exam"

if [[ "${1:-}" == "check" ]]; then
  [[ -f "${state}" ]] || { echo "Brak trwającego egzaminu." >&2; exit 1; }
  read -r started minutes < <(head -1 "${state}")
  total=0; passed=0
  while read -r t; do
    total=$((total + 1))
    if CKAD_NO_SCORE=1 bash "$(dirname "$0")/check.sh" "${t}"; then passed=$((passed + 1)); fi
    echo
  done < <(tail -n +2 "${state}")
  used=$(( ($(date +%s) - started + 59) / 60 ))
  pct=$(( passed * 100 / total ))
  echo "Egzamin: ${passed}/${total} zadań (${pct}%), czas ${used}/${minutes} min. Próg zdania CKAD: 66%."
  printf '%s\texam\t%s/%s\t%s\t%s\n' "$(date '+%F %T')" "${passed}" "${total}" "${used}" "${minutes}" >> "${SCORES_FILE}"
  rm -f "${state}"
  exit 0
fi

n="${1:-16}"; minutes="${2:-120}"
# Pary zadań, które nie mogą żyć naraz w jednym labie: 23 zakłada w ckad-lab-b
# kwotę `pods: 3`, a 26 i 28 uruchamiają tam pody (tymczasowe i testowe), które
# kwota by odrzuciła. Pojedyncze `ckad-start` zawsze zaczyna od czystego labu.
CONFLICTS=" 23:26 23:28 "
conflicts_with_picked() {
  local cand="$1" p
  for p in "${picked[@]:-}"; do
    [[ -z "${p}" ]] && continue
    [[ "${CONFLICTS}" == *" ${p}:${cand} "* || "${CONFLICTS}" == *" ${cand}:${p} "* ]] && return 0
  done
  return 1
}

# Bez mapfile: systemowy bash na macOS to 3.2.
picked=()
while IFS= read -r t; do
  (( ${#picked[@]} >= n )) && break
  conflicts_with_picked "${t}" || picked+=("${t}")
done < <(ls "${CKAD_ROOT}"/tools/ckad/tasks/[0-9][0-9].sh | xargs -n1 basename | sed 's/\.sh$//' | awk 'BEGIN{srand()} {print rand() "\t" $0}' | sort | cut -f2)
IFS=$'\n' picked=($(printf '%s\n' "${picked[@]}" | sort)); unset IFS

echo "Czyszczę lab ${LAB} i przygotowuję ${#picked[@]} zadań…"
lab_reset
for t in "${picked[@]}"; do
  (
    # shellcheck source=/dev/null
    source "$(task_script "${t}")"
    if declare -F setup >/dev/null; then setup; fi
  )
done

{ printf '%s\t%s\n' "$(date +%s)" "${minutes}"; printf '%s\n' "${picked[@]}"; } > "${state}"
for t in "${picked[@]}"; do
  echo "────────────────────────────────────────────────────────────"
  sed -n '1,/^## Rozwiązanie/p' "$(task_doc "${t}")" | sed '$d' | sed '/^## Po co/,$d'
done
echo "────────────────────────────────────────────────────────────"
echo "Masz ${minutes} min. export KUBECONFIG=${STUDENT_KUBECONFIG}"
echo "Koniec: bash tools/ckad/exam.sh check"
