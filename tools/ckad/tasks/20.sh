# shellcheck shell=bash
# 20. SecurityContext — sprawdzarka (docs/ckad/20-securitycontext.md)

check() {
  local p="pod/hardened" ps='{.spec.securityContext' cs='{.spec.containers[0].securityContext'
  expect "runAsUser 1000" "1000" "$(jp "${p}" "${ps}.runAsUser}")"
  expect "runAsGroup 3000" "3000" "$(jp "${p}" "${ps}.runAsGroup}")"
  expect "fsGroup 2000" "2000" "$(jp "${p}" "${ps}.fsGroup}")"
  expect "runAsNonRoot true" "true" "$(jp "${p}" "${ps}.runAsNonRoot}")"
  expect "seccompProfile RuntimeDefault" "RuntimeDefault" "$(jp "${p}" "${ps}.seccompProfile.type}")"
  expect "readOnlyRootFilesystem true" "true" "$(jp "${p}" "${cs}.readOnlyRootFilesystem}")"
  expect "allowPrivilegeEscalation false" "false" "$(jp "${p}" "${cs}.allowPrivilegeEscalation}")"
  expect "capabilities drop ALL" "ALL" "$(jp "${p}" "${cs}.capabilities.drop[0]}")"

  wait_for 60 bash -c "[[ \$(kubectl --kubeconfig '${OPERATOR_KUBECONFIG}' -n '${NS}' get ${p} -o jsonpath='{.status.conditions[?(@.type==\"Ready\")].status}') == True ]]"
  expect "pod Ready" "True" "$(jp "${p}" '{.status.conditions[?(@.type=="Ready")].status}')"
  expect_match "id w kontenerze: uid=1000 gid=3000" 'uid=1000.*gid=3000' "$(ko -n "${NS}" exec hardened -- id 2>/dev/null)"
}
