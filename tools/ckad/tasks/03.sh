# shellcheck shell=bash
# 03. CronJob + ręczne uruchomienie — sprawdzarka (docs/ckad/03-cronjob-report.md)

check() {
  local c="cronjob/report"
  expect "CronJob report istnieje" "report" "$(jp "${c}" '{.metadata.name}')"
  expect "harmonogram co 5 minut" "*/5 * * * *" "$(jp "${c}" '{.spec.schedule}')"
  expect "concurrencyPolicy = Forbid" "Forbid" "$(jp "${c}" '{.spec.concurrencyPolicy}')"
  expect "successfulJobsHistoryLimit = 2" "2" "$(jp "${c}" '{.spec.successfulJobsHistoryLimit}')"
  expect "failedJobsHistoryLimit = 1" "1" "$(jp "${c}" '{.spec.failedJobsHistoryLimit}')"
  expect_match "startingDeadlineSeconds ustawione (liczba > 0)" '^[1-9][0-9]*$' "$(jp "${c}" '{.spec.startingDeadlineSeconds}')"
  expect "obraz busybox:1.37" "busybox:1.37" "$(jp "${c}" '{.spec.jobTemplate.spec.template.spec.containers[0].image}')"

  local j="job/report-manual"
  expect "Job report-manual istnieje" "report-manual" "$(jp "${j}" '{.metadata.name}')"
  expect "report-manual pochodzi z CronJoba report" "report" "$(jp "${j}" '{.metadata.ownerReferences[0].name}')"
  wait_for 60 bash -c "[[ \$(kubectl --kubeconfig '${OPERATOR_KUBECONFIG}' -n '${NS}' get ${j} -o jsonpath='{.status.conditions[?(@.type==\"Complete\")].status}') == True ]]"
  expect "report-manual zakończony sukcesem" "True" "$(jp "${j}" '{.status.conditions[?(@.type=="Complete")].status}')"
  expect "report-manual: 1 udany pod" "1" "$(jp "${j}" '{.status.succeeded}')"
}
