# shellcheck shell=bash
# 04. Init container przygotowuje treść — sprawdzarka (docs/ckad/04-init-container-tresc.md)

check() {
  local d="deploy/site"
  expect "Deployment site istnieje" "site" "$(jp "${d}" '{.metadata.name}')"
  expect "kontener nginx:1.29 (bez przepakowywania obrazu)" "nginx:1.29" "$(jp "${d}" '{.spec.template.spec.containers[0].image}')"
  expect "jest initContainer z busybox:1.37" "busybox:1.37" "$(jp "${d}" '{.spec.template.spec.initContainers[0].image}')"
  expect "wolumen typu emptyDir" "yes" \
    "$(jp "${d}" '{.spec.template.spec.volumes[*].emptyDir}' | grep -q . && echo yes || echo no)"
  expect "nginx montuje wolumen w /usr/share/nginx/html" "/usr/share/nginx/html" \
    "$(jp "${d}" '{.spec.template.spec.containers[0].volumeMounts[0].mountPath}')"
  expect "Service site istnieje" "site" "$(jp svc/site '{.metadata.name}')"

  wait_for 90 bash -c "[[ \$(kubectl --kubeconfig '${OPERATOR_KUBECONFIG}' -n '${NS}' get ${d} -o jsonpath='{.status.availableReplicas}') == 1 ]]"
  expect "Deployment dostępny (1 replika)" "1" "$(jp "${d}" '{.status.availableReplicas}')"

  local out=""
  wait_for 60 bash -c "kubectl --kubeconfig '${OPERATOR_KUBECONFIG}' -n '${NS}' get endpoints site -o jsonpath='{.subsets[*].addresses[*].ip}' | grep -q ."
  out="$(ko -n "${NS}" run "tmp-wget-${RANDOM}" --rm -i --restart=Never --image=busybox:1.37 -- wget -qO- -T 5 "http://site.${NS}.svc" 2>/dev/null)"
  expect_match "Service zwraca treść przygotowaną przez init (Strona z initContainera)" 'Strona z initContainera' "${out}"
}
