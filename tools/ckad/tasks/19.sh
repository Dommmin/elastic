# shellcheck shell=bash
# 19. Secret jako env i jako plik — sprawdzarka (docs/ckad/19-secret-env-plik.md)

check() {
  local p="pod/secret-demo"
  expect "Secret db-credentials typu Opaque" "Opaque" "$(jp secret/db-credentials '{.type}')"
  expect "klucz username=app" "app" "$(jp secret/db-credentials '{.data.username}' | base64 -d 2>/dev/null)"
  expect "klucz password=S3cr3t-Pass" "S3cr3t-Pass" "$(jp secret/db-credentials '{.data.password}' | base64 -d 2>/dev/null)"
  expect "DB_PASSWORD z secretKeyRef (klucz password)" "password" \
    "$(jp "${p}" '{.spec.containers[0].env[?(@.name=="DB_PASSWORD")].valueFrom.secretKeyRef.key}')"
  expect "wolumen z Secretu db-credentials" "db-credentials" "$(jp "${p}" '{.spec.volumes[*].secret.secretName}')"
  expect "defaultMode 0400 (256)" "256" "$(jp "${p}" '{.spec.volumes[*].secret.defaultMode}')"

  wait_for 60 bash -c "[[ \$(kubectl --kubeconfig '${OPERATOR_KUBECONFIG}' -n '${NS}' get ${p} -o jsonpath='{.status.conditions[?(@.type==\"Ready\")].status}') == True ]]"
  expect "pod Ready" "True" "$(jp "${p}" '{.status.conditions[?(@.type=="Ready")].status}')"
  expect "w kontenerze DB_PASSWORD" "S3cr3t-Pass" "$(ko -n "${NS}" exec secret-demo -- sh -c 'echo $DB_PASSWORD' 2>/dev/null)"
  local mp
  mp="$(jp "${p}" '{.spec.containers[0].volumeMounts[0].mountPath}')"
  expect "plik password w kontenerze ma tryb 400" "400" \
    "$(ko -n "${NS}" exec secret-demo -- stat -L -c '%a' "${mp}/password" 2>/dev/null)"
}
