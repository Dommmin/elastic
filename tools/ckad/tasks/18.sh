# shellcheck shell=bash
# 18. ConfigMap jako env i jako plik — sprawdzarka (docs/ckad/18-configmap-env-wolumen.md)

check() {
  local p="pod/cfg-demo"
  expect "ConfigMap app-config ma LOG_LEVEL=debug" "debug" "$(jp cm/app-config '{.data.LOG_LEVEL}')"
  expect "ConfigMap app-config ma REGION=eu-west" "eu-west" "$(jp cm/app-config '{.data.REGION}')"
  expect_match "ConfigMap ma klucz app.properties z zawartością pliku" 'mode=production' \
    "$(jp cm/app-config '{.data.app\.properties}')"
  expect "MY_LEVEL pochodzi z klucza LOG_LEVEL (configMapKeyRef)" "LOG_LEVEL" \
    "$(jp "${p}" '{.spec.containers[0].env[?(@.name=="MY_LEVEL")].valueFrom.configMapKeyRef.key}')"
  expect "pod używa envFrom z app-config" "app-config" "$(jp "${p}" '{.spec.containers[0].envFrom[0].configMapRef.name}')"
  expect "ConfigMap zamontowany w /etc/app" "/etc/app" \
    "$(jp "${p}" '{.spec.containers[0].volumeMounts[?(@.mountPath=="/etc/app")].mountPath}')"

  wait_for 60 bash -c "[[ \$(kubectl --kubeconfig '${OPERATOR_KUBECONFIG}' -n '${NS}' get ${p} -o jsonpath='{.status.conditions[?(@.type==\"Ready\")].status}') == True ]]"
  expect "pod Ready" "True" "$(jp "${p}" '{.status.conditions[?(@.type=="Ready")].status}')"
  expect "w kontenerze MY_LEVEL=debug" "debug" "$(ko -n "${NS}" exec cfg-demo -- sh -c 'echo $MY_LEVEL' 2>/dev/null)"
  expect "w kontenerze REGION=eu-west (envFrom)" "eu-west" "$(ko -n "${NS}" exec cfg-demo -- sh -c 'echo $REGION' 2>/dev/null)"
  expect_match "w kontenerze plik /etc/app/app.properties" 'greeting=czesc z pliku' \
    "$(ko -n "${NS}" exec cfg-demo -- cat /etc/app/app.properties 2>/dev/null)"
}
