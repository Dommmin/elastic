#!/usr/bin/env bash
#  tools/ckad/start.sh <NN> — czyści lab, przygotowuje zadanie, pokazuje treść, włącza stoper.
#    make ckad-start t=07
source "$(dirname "$0")/lib.sh"

t="${1:?Podaj numer zadania, np. 07}"
doc="$(task_doc "${t}")"; script="$(task_script "${t}")"
[[ -n "${doc}" && -f "${script}" ]] || { echo "Nie ma zadania ${t}." >&2; exit 1; }
require_lab

echo "Czyszczę lab ${LAB}…"
lab_reset
# shellcheck source=/dev/null
source "${script}"
if declare -F setup >/dev/null; then
  echo "Przygotowuję zadanie…"
  setup
fi

# Treść: od nagłówka do sekcji „Rozwiązanie” (bez niej, żeby nie podglądać).
echo
sed -n '1,/^## Rozwiązanie/p' "${doc}" | sed '$d'
echo
printf '%s\t%s\t%s\n' "${t}" "$(date +%s)" "${LAB}" > "${HOME}/.ckad-current"
echo "Stoper ruszył ($(task_minutes "${doc}") min). Ćwicz z: export KUBECONFIG=${STUDENT_KUBECONFIG}"
echo "Gotowe? make ckad-check t=${t}"
