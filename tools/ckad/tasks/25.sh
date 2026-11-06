# shellcheck shell=bash
# 25. imagePullSecrets przez ServiceAccount — sprawdzarka (docs/ckad/25-imagepullsecrets-serviceaccount.md)

check() {
  local p="pod/puller-pod"
  expect "Secret regcred typu dockerconfigjson" "kubernetes.io/dockerconfigjson" "$(jp secret/regcred '{.type}')"
  expect_match "regcred dotyczy registry.example.test" 'registry\.example\.test' \
    "$(jp secret/regcred '{.data.\.dockerconfigjson}' | base64 -d 2>/dev/null)"
  expect "SA puller ma imagePullSecrets regcred" "regcred" "$(jp sa/puller '{.imagePullSecrets[0].name}')"
  expect "pod używa SA puller" "puller" "$(jp "${p}" '{.spec.serviceAccountName}')"
  expect "pod ma imagePullSecrets regcred (z SA)" "regcred" "$(jp "${p}" '{.spec.imagePullSecrets[0].name}')"
  wait_for 60 bash -c "[[ \$(kubectl --kubeconfig '${OPERATOR_KUBECONFIG}' -n '${NS}' get ${p} -o jsonpath='{.status.phase}') == Running ]]"
  expect "pod Running" "Running" "$(jp "${p}" '{.status.phase}')"
}
