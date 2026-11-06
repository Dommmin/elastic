# shellcheck shell=bash
# 23. ResourceQuota i LimitRange — sprawdzarka (docs/ckad/23-resourcequota-limitrange.md)

jb() { local res="$1" path="$2"; ko -n "${NS_B}" get "${res}" -o "jsonpath=${path}" 2>/dev/null; }

check() {
  expect "quota team-b: requests.cpu 500m" "500m" "$(jb resourcequota/team-b '{.spec.hard.requests\.cpu}')"
  expect "quota team-b: limits.memory 512Mi" "512Mi" "$(jb resourcequota/team-b '{.spec.hard.limits\.memory}')"
  expect "quota team-b: pods 3" "3" "$(jb resourcequota/team-b '{.spec.hard.pods}')"
  expect "LimitRange: defaultRequest cpu 100m" "100m" "$(jb limitrange/team-b-defaults '{.spec.limits[0].defaultRequest.cpu}')"
  expect "LimitRange: defaultRequest memory 64Mi" "64Mi" "$(jb limitrange/team-b-defaults '{.spec.limits[0].defaultRequest.memory}')"
  expect "LimitRange: default cpu 200m" "200m" "$(jb limitrange/team-b-defaults '{.spec.limits[0].default.cpu}')"
  expect "LimitRange: default memory 128Mi" "128Mi" "$(jb limitrange/team-b-defaults '{.spec.limits[0].default.memory}')"
  expect "strażnik lab-guard nietknięty" "lab-guard" "$(jb resourcequota/lab-guard '{.metadata.name}')"

  wait_for 60 bash -c "[[ \$(kubectl --kubeconfig '${OPERATOR_KUBECONFIG}' -n '${NS_B}' get pod/quota-ok -o jsonpath='{.status.conditions[?(@.type==\"Ready\")].status}') == True ]]"
  expect "pod quota-ok Running i Ready" "True" "$(jb pod/quota-ok '{.status.conditions[?(@.type=="Ready")].status}')"
  expect "quota-ok mieści się: limit pamięci <= 512Mi" "yes" \
    "$(jb pod/quota-ok '{.spec.containers[0].resources.limits.memory}' | grep -Eq '^(64|128|256|512)Mi$' && echo yes || echo no)"
  expect "kwota policzyła pod (status.used.pods >= 1)" "yes" \
    "$([[ "$(jb resourcequota/team-b '{.status.used.pods}')" -ge 1 ]] && echo yes || echo no)"
  expect "pod przekraczający limit nie istnieje (został odrzucony)" "0" \
    "$(ko -n "${NS_B}" get pods -o jsonpath='{range .items[*]}{.spec.containers[0].resources.limits.memory}{"\n"}{end}' 2>/dev/null | grep -Ec '^(1Gi|1024Mi|[0-9]+Gi)$')"
}
