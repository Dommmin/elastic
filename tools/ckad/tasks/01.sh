# shellcheck shell=bash
# 01. Sidecar, który czyta logi aplikacji — sprawdzarka (docs/ckad/01-sidecar-logow.md)

check() {
  local p="pod/log-shipper"
  expect "pod log-shipper istnieje" "log-shipper" "$(jp "${p}" '{.metadata.name}')"
  expect "shipper to natywny sidecar (initContainer z restartPolicy: Always)" "Always" \
    "$(jp "${p}" '{.spec.initContainers[?(@.name=="shipper")].restartPolicy}')"
  expect "kontener app z obrazem busybox:1.37" "busybox:1.37" \
    "$(jp "${p}" '{.spec.containers[?(@.name=="app")].image}')"
  expect "wolumen logs typu emptyDir" "yes" \
    "$(jp "${p}" '{.spec.volumes[?(@.name=="logs")].emptyDir}' | grep -q . && echo yes || echo no)"
  expect "app montuje logs w /var/log/app" "/var/log/app" \
    "$(jp "${p}" '{.spec.containers[?(@.name=="app")].volumeMounts[?(@.name=="logs")].mountPath}')"
  expect "shipper montuje logs w /var/log/app" "/var/log/app" \
    "$(jp "${p}" '{.spec.initContainers[?(@.name=="shipper")].volumeMounts[?(@.name=="logs")].mountPath}')"

  wait_for 60 bash -c "[[ \$(kubectl --kubeconfig '${OPERATOR_KUBECONFIG}' -n '${NS}' get ${p} -o jsonpath='{.status.conditions[?(@.type==\"Ready\")].status}') == True ]]"
  expect "pod Ready" "True" "$(jp "${p}" '{.status.conditions[?(@.type=="Ready")].status}')"

  wait_for 20 bash -c "kubectl --kubeconfig '${OPERATOR_KUBECONFIG}' -n '${NS}' logs log-shipper -c shipper --tail=5 | grep -Eq '[0-9]{2}:[0-9]{2}:[0-9]{2}'"
  expect_match "shipper wypisuje linie z pliku aplikacji (godziny)" '[0-9]{2}:[0-9]{2}:[0-9]{2}' \
    "$(ko -n "${NS}" logs log-shipper -c shipper --tail=5 2>/dev/null)"
}
