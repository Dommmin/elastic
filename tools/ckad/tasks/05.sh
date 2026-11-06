# shellcheck shell=bash
# 05. PVC + Deployment — sprawdzarka (docs/ckad/05-pvc-notes.md)

check() {
  local p="pvc/notes-data"
  expect "PVC notes-data istnieje" "notes-data" "$(jp "${p}" '{.metadata.name}')"
  expect "rozmiar 100Mi" "100Mi" "$(jp "${p}" '{.spec.resources.requests.storage}')"
  expect "accessMode ReadWriteOnce" "ReadWriteOnce" "$(jp "${p}" '{.spec.accessModes[0]}')"
  expect "StorageClass local-path" "local-path" "$(jp "${p}" '{.spec.storageClassName}')"

  local d="deploy/notes"
  expect "Deployment notes istnieje" "notes" "$(jp "${d}" '{.metadata.name}')"
  expect "Deployment używa PVC notes-data" "notes-data" \
    "$(jp "${d}" '{.spec.template.spec.volumes[?(@.persistentVolumeClaim)].persistentVolumeClaim.claimName}')"
  expect "wolumen zamontowany w /data" "/data" "$(jp "${d}" '{.spec.template.spec.containers[0].volumeMounts[0].mountPath}')"

  wait_for 90 bash -c "[[ \$(kubectl --kubeconfig '${OPERATOR_KUBECONFIG}' -n '${NS}' get ${d} -o jsonpath='{.status.availableReplicas}') == 1 ]]"
  expect "Deployment dostępny (1 replika)" "1" "$(jp "${d}" '{.status.availableReplicas}')"
  expect "PVC Bound" "Bound" "$(jp "${p}" '{.status.phase}')"

  local out=""
  out="$(ko -n "${NS}" exec "${d}" -- cat /data/starts.log 2>/dev/null)"
  expect_match "plik /data/starts.log istnieje na wolumenie i ma zapis startu" '^start ' "${out}"
}
