# shellcheck shell=bash
# 21. ServiceAccount bez tokenu — sprawdzarka (docs/ckad/21-serviceaccount-bez-tokenu.md)

check() {
  local p="pod/reporter-pod"
  expect "SA reporter ma automountServiceAccountToken=false" "false" "$(jp sa/reporter '{.automountServiceAccountToken}')"
  expect "pod używa SA reporter" "reporter" "$(jp "${p}" '{.spec.serviceAccountName}')"
  expect "pod nie włącza tokenu z powrotem (automount nie true)" "no" \
    "$([[ "$(jp "${p}" '{.spec.automountServiceAccountToken}')" == "true" ]] && echo yes || echo no)"
  expect "brak wolumenu kube-api-access w spec poda" "0" \
    "$(jp "${p}" '{.spec.volumes[*].name}' | tr ' ' '\n' | grep -c '^kube-api-access')"

  wait_for 60 bash -c "[[ \$(kubectl --kubeconfig '${OPERATOR_KUBECONFIG}' -n '${NS}' get ${p} -o jsonpath='{.status.conditions[?(@.type==\"Ready\")].status}') == True ]]"
  expect "pod Ready" "True" "$(jp "${p}" '{.status.conditions[?(@.type=="Ready")].status}')"
  expect "w kontenerze brak katalogu tokenu" "brak" \
    "$(ko -n "${NS}" exec reporter-pod -- ls /var/run/secrets/kubernetes.io/serviceaccount >/dev/null 2>&1 && echo jest || echo brak)"
}
