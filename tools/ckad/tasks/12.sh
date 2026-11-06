# shellcheck shell=bash
# 12. Wstrzymany rollout — sprawdzarka (docs/ckad/12-wstrzymany-rollout.md)

setup() {
  ko -n "${NS}" create deployment batch-api --image=nginx:1.28 --replicas=2
  ko -n "${NS}" rollout status deployment/batch-api --timeout=120s
}

check() {
  local d="deploy/batch-api"
  wait_for 90 bash -c "[[ \$(kubectl --kubeconfig '${OPERATOR_KUBECONFIG}' -n '${NS}' get ${d} -o jsonpath='{.status.availableReplicas}') == 2 && \$(kubectl --kubeconfig '${OPERATOR_KUBECONFIG}' -n '${NS}' get ${d} -o jsonpath='{.status.updatedReplicas}') == 2 ]]"
  expect "Deployment nie jest wstrzymany (resume)" "" "$(jp "${d}" '{.spec.paused}')"
  expect "obraz: nginx:1.29" "nginx:1.29" "$(jp "${d}" '{.spec.template.spec.containers[0].image}')"
  expect "requests cpu=50m" "50m" "$(jp "${d}" '{.spec.template.spec.containers[0].resources.requests.cpu}')"
  expect "requests memory=64Mi" "64Mi" "$(jp "${d}" '{.spec.template.spec.containers[0].resources.requests.memory}')"
  expect "limits cpu=100m" "100m" "$(jp "${d}" '{.spec.template.spec.containers[0].resources.limits.cpu}')"
  expect "limits memory=128Mi" "128Mi" "$(jp "${d}" '{.spec.template.spec.containers[0].resources.limits.memory}')"
  expect "zmienna LOG_LEVEL=debug" "debug" "$(jp "${d}" '{.spec.template.spec.containers[0].env[?(@.name=="LOG_LEVEL")].value}')"
  expect "bieżąca rewizja to dokładnie 2 (jedna nowa)" "2" "$(jp "${d}" '{.metadata.annotations.deployment\.kubernetes\.io/revision}')"
  expect "ReplicaSety Deploymentu: 2 (stary i jeden nowy)" "2" \
    "$(ko -n "${NS}" get rs -o json 2>/dev/null | jq '[.items[] | select(.metadata.ownerReferences[]?.name=="batch-api")] | length' 2>/dev/null)"
  expect "repliki dostępne: 2" "2" "$(jp "${d}" '{.status.availableReplicas}')"
}
