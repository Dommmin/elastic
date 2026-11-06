# shellcheck shell=bash
# 02. Job z kilkoma uzupełnieniami — sprawdzarka (docs/ckad/02-job-batch-count.md)

check() {
  local j="job/batch-count"
  expect "Job batch-count istnieje" "batch-count" "$(jp "${j}" '{.metadata.name}')"
  expect "completions = 4" "4" "$(jp "${j}" '{.spec.completions}')"
  expect "parallelism = 2" "2" "$(jp "${j}" '{.spec.parallelism}')"
  expect "backoffLimit = 2" "2" "$(jp "${j}" '{.spec.backoffLimit}')"
  expect_match "activeDeadlineSeconds ustawione (liczba > 0)" '^[1-9][0-9]*$' "$(jp "${j}" '{.spec.activeDeadlineSeconds}')"
  expect "obraz busybox:1.37" "busybox:1.37" "$(jp "${j}" '{.spec.template.spec.containers[0].image}')"
  expect "restartPolicy poda: Never albo OnFailure" "yes" \
    "$(jp "${j}" '{.spec.template.spec.restartPolicy}' | grep -Eq '^(Never|OnFailure)$' && echo yes || echo no)"

  wait_for 90 bash -c "[[ \$(kubectl --kubeconfig '${OPERATOR_KUBECONFIG}' -n '${NS}' get ${j} -o jsonpath='{.status.conditions[?(@.type==\"Complete\")].status}') == True ]]"
  expect "Job Complete" "True" "$(jp "${j}" '{.status.conditions[?(@.type=="Complete")].status}')"
  expect "udanych podów: 4" "4" "$(jp "${j}" '{.status.succeeded}')"
  expect "każdy z 4 podów wypisał batch-count-done" "4" \
    "$(ko -n "${NS}" logs -l job-name=batch-count --tail=-1 2>/dev/null | grep -c '^batch-count-done$')"
}
