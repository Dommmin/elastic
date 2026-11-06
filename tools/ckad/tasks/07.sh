# shellcheck shell=bash
# 07. Rolling update i rollback — sprawdzarka (docs/ckad/07-rolling-update-i-rollback.md)

check() {
  local d="deploy/web-roll"
  expect "Deployment web-roll istnieje" "web-roll" "$(jp "${d}" '{.metadata.name}')"
  expect "końcowy obraz to nginx:1.28 (po rollbacku)" "nginx:1.28" "$(jp "${d}" '{.spec.template.spec.containers[0].image}')"
  expect "repliki: 3" "3" "$(jp "${d}" '{.spec.replicas}')"
  expect "strategia RollingUpdate" "RollingUpdate" "$(jp "${d}" '{.spec.strategy.type}')"
  expect "maxSurge = 1" "1" "$(jp "${d}" '{.spec.strategy.rollingUpdate.maxSurge}')"
  expect "maxUnavailable = 0" "0" "$(jp "${d}" '{.spec.strategy.rollingUpdate.maxUnavailable}')"

  wait_for 90 bash -c "[[ \$(kubectl --kubeconfig '${OPERATOR_KUBECONFIG}' -n '${NS}' get ${d} -o jsonpath='{.status.availableReplicas}') == 3 ]]"
  expect "3 repliki dostępne" "3" "$(jp "${d}" '{.status.availableReplicas}')"
  expect "bieżąca rewizja to 3 (1 -> 2 -> undo do 1 = rewizja 3)" "3" "$(jp "${d}" '{.metadata.annotations.deployment\.kubernetes\.io/revision}')"

  local rs
  rs="$(ko -n "${NS}" get rs -o json 2>/dev/null | jq -r '[.items[] | select(.metadata.ownerReferences[]?.name=="web-roll")]
    | map(.spec.template.spec.containers[0].image + "|" + (.metadata.annotations["kubernetes.io/change-cause"] // "")) | .[]' 2>/dev/null)"
  expect_match "historia zawiera rewizję nginx:1.29 z change-cause" '(^|[[:space:]])nginx:1\.29\|[^|]+' "$(echo "${rs}" | tr '\n' ' ')"
  expect "w historii są dokładnie 2 ReplicaSety (1.28 i 1.29)" "2" "$(echo "${rs}" | grep -c .)"
}
