# shellcheck shell=bash
# 26. Service ClusterIP i DNS po FQDN — sprawdzarka (docs/ckad/26-service-clusterip-dns.md)

# http_from <namespace> <url> — GET z tymczasowego poda busybox (stdout = ciało).
t26_http() { ko -n "$1" run "tmp-26-${RANDOM}" --rm -i -q --restart=Never --image=busybox:1.37 --pod-running-timeout=90s -- wget -qO- -T 5 "$2" 2>/dev/null; }

check() {
  local d="deploy/catalog-mock" s="svc/catalog-mock"
  expect "Deployment catalog-mock istnieje" "catalog-mock" "$(jp "${d}" '{.metadata.name}')"
  expect "obraz nginx:1.29" "nginx:1.29" "$(jp "${d}" '{.spec.template.spec.containers[0].image}')"
  expect "port 80 w kontenerze nazwany http" "80" \
    "$(jp "${d}" '{.spec.template.spec.containers[0].ports[?(@.name=="http")].containerPort}')"
  wait_for 90 bash -c "[[ \$(kubectl --kubeconfig '${OPERATOR_KUBECONFIG}' -n '${NS}' get ${d} -o jsonpath='{.status.readyReplicas}') -ge 1 ]]"

  expect "Service catalog-mock istnieje" "catalog-mock" "$(jp "${s}" '{.metadata.name}')"
  expect "typ ClusterIP" "ClusterIP" "$(jp "${s}" '{.spec.type}')"
  expect "port Service'u 80" "80" "$(jp "${s}" '{.spec.ports[0].port}')"
  expect "targetPort po NAZWIE portu (http)" "http" "$(jp "${s}" '{.spec.ports[0].targetPort}')"

  wait_for 30 bash -c "[[ -n \$(kubectl --kubeconfig '${OPERATOR_KUBECONFIG}' -n '${NS}' get endpointslice -l kubernetes.io/service-name=catalog-mock -o jsonpath='{.items[*].endpoints[*].addresses[0]}') ]]"
  expect_match "Service ma endpointy (adresy podów)" '^[0-9]+\.[0-9]+' \
    "$(jp endpointslice '{.items[*].endpoints[*].addresses[0]}' -l kubernetes.io/service-name=catalog-mock)"

  local out
  out="$(t26_http "${NS_B}" "http://catalog-mock.${NS}.svc.cluster.local")"
  expect_match "z poda w ${NS_B} FQDN catalog-mock.${NS}.svc.cluster.local zwraca stronę nginx" 'Welcome to nginx' "${out}"
}
