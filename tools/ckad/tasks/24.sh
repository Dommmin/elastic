# shellcheck shell=bash
# 24. Certificate (cert-manager) — sprawdzarka (docs/ckad/24-certificate-cert-manager.md)

check() {
  local c="certificate/lab-cert"
  expect "Certificate lab-cert istnieje" "lab-cert" "$(jp "${c}" '{.metadata.name}')"
  expect "dnsNames zawiera lab.marketplace.test" "lab.marketplace.test" "$(jp "${c}" '{.spec.dnsNames[0]}')"
  expect "issuerRef: marketplace-ca" "marketplace-ca" "$(jp "${c}" '{.spec.issuerRef.name}')"
  expect "issuerRef.kind: ClusterIssuer" "ClusterIssuer" "$(jp "${c}" '{.spec.issuerRef.kind}')"
  expect "secretName: lab-cert-tls" "lab-cert-tls" "$(jp "${c}" '{.spec.secretName}')"
  wait_for 60 bash -c "[[ \$(kubectl --kubeconfig '${OPERATOR_KUBECONFIG}' -n '${NS}' get ${c} -o jsonpath='{.status.conditions[?(@.type==\"Ready\")].status}') == True ]]"
  expect "Certificate Ready=True" "True" "$(jp "${c}" '{.status.conditions[?(@.type=="Ready")].status}')"
  expect "Secret lab-cert-tls typu kubernetes.io/tls" "kubernetes.io/tls" "$(jp secret/lab-cert-tls '{.type}')"
}
