#!/usr/bin/env bash
#  tools/ckad/selftest.sh [NN ...] — dowód, że zadania nie kłamią.
#
#  Dla każdego zadania:
#   1. reset labu + setup zadania, sprawdzarka MUSI dać FAIL (nierozwiązane ≠ zielone),
#   2. rozwiązanie z dokumentu wykonane jako UCZEŃ, sprawdzarka MUSI dać PASS.
#  Rozwiązania używają nazw ckad-lab/ckad-lab-b; przy innym LAB są podmieniane.
#
#    make ckad-selftest            # wszystkie
#    make ckad-selftest t="07 12"  # wybrane
source "$(dirname "$0")/lib.sh"
require_lab

if [[ $# -gt 0 ]]; then
  tasks=("$@")
else
  tasks=()
  for f in "${CKAD_ROOT}"/tools/ckad/tasks/[0-9][0-9].sh; do tasks+=("$(basename "${f}" .sh)"); done
fi

ok=0; bad=0; report=""
for t in "${tasks[@]}"; do
  doc="$(task_doc "${t}")"; script="$(task_script "${t}")"
  echo "=== ${t}: $(head -1 "${doc}" | sed 's/^# *//')"
  lab_reset
  (
    set +u
    # shellcheck source=/dev/null
    source "${script}"
    if declare -F setup >/dev/null; then setup; fi
  ) || { report+="${t} SETUP-ERROR"$'\n'; bad=$((bad + 1)); continue; }

  if CKAD_NO_SCORE=1 bash "${CKAD_ROOT}/tools/ckad/check.sh" "${t}" >/dev/null 2>&1; then
    echo "  ✗ sprawdzarka zielona PRZED rozwiązaniem"; report+="${t} FALSE-PASS"$'\n'; bad=$((bad + 1)); continue
  fi

  solution="$(task_solution "${doc}" | NS="${NS}" NS_B="${NS_B}" perl -pe 's/ckad-lab-b/$ENV{NS_B}/g; s/ckad-lab(?![-\w])/$ENV{NS}/g')"
  if ! (cd "${CKAD_ROOT}" && KUBECONFIG="${STUDENT_KUBECONFIG}" bash -e -c "${solution}") > "/tmp/ckad-selftest-${LAB}-${t}.log" 2>&1; then
    echo "  ✗ rozwiązanie się wywróciło (log: /tmp/ckad-selftest-${LAB}-${t}.log)"; report+="${t} SOLUTION-ERROR"$'\n'; bad=$((bad + 1)); continue
  fi

  if CKAD_NO_SCORE=1 bash "${CKAD_ROOT}/tools/ckad/check.sh" "${t}"; then
    ok=$((ok + 1)); report+="${t} OK"$'\n'
  else
    report+="${t} FALSE-FAIL"$'\n'; bad=$((bad + 1))
  fi
done

echo
printf '%s' "${report}"
echo "selftest: OK=${ok} BŁĘDY=${bad}"
[[ ${bad} -eq 0 ]]
