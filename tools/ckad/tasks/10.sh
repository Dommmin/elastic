# shellcheck shell=bash
# 10. Helm: instalacja i upgrade — sprawdzarka (docs/ckad/10-helm-install-upgrade.md)

check() {
  local rel
  rel="$(helm --kubeconfig "${OPERATOR_KUBECONFIG}" -n "${NS}" list -o json 2>/dev/null | jq -c '.[] | select(.name=="webapp")' 2>/dev/null)"
  expect "wydanie webapp istnieje w ${NS}" "webapp" "$(echo "${rel}" | jq -r '.name' 2>/dev/null)"
  expect "status wydania: deployed" "deployed" "$(echo "${rel}" | jq -r '.status' 2>/dev/null)"
  expect "rewizja wydania: 2" "2" "$(echo "${rel}" | jq -r '.revision' 2>/dev/null)"
  expect "chart: webapp" "webapp" "$(echo "${rel}" | jq -r '.chart' 2>/dev/null | sed 's/-[0-9.]*$//')"

  local d="deploy/webapp"
  wait_for 90 bash -c "[[ \$(kubectl --kubeconfig '${OPERATOR_KUBECONFIG}' -n '${NS}' get ${d} -o jsonpath='{.status.availableReplicas}') == 3 ]]"
  expect "Deployment webapp: 3 repliki (spec)" "3" "$(jp "${d}" '{.spec.replicas}')"
  expect "Deployment webapp: 3 repliki dostępne" "3" "$(jp "${d}" '{.status.availableReplicas}')"
  expect "obraz z instalacji zachowany po upgradzie (nginx:1.28)" "nginx:1.28" "$(jp "${d}" '{.spec.template.spec.containers[0].image}')"
  expect "Service webapp: port 8080 zachowany" "8080" "$(jp svc/webapp '{.spec.ports[0].port}')"
}
