# shellcheck shell=bash
# 27. Service typu NodePort — sprawdzarka (docs/ckad/27-service-nodeport.md)

setup() {
  ko -n "${NS}" create deployment web-np --image=nginx:1.29 --replicas=2 --port=80 >/dev/null
  ko -n "${NS}" rollout status deploy/web-np --timeout=120s >/dev/null
}

check() {
  local s="svc/web-np"
  expect "Service web-np istnieje" "web-np" "$(jp "${s}" '{.metadata.name}')"
  expect "typ NodePort" "NodePort" "$(jp "${s}" '{.spec.type}')"
  expect "port 80" "80" "$(jp "${s}" '{.spec.ports[0].port}')"
  expect "targetPort 80" "80" "$(jp "${s}" '{.spec.ports[0].targetPort}')"
  expect_match "nodePort w zakresie 30000-32767 (przydzielony)" '^3[0-2][0-9]{3}$' "$(jp "${s}" '{.spec.ports[0].nodePort}')"
  expect "selektor wskazuje app=web-np" "web-np" "$(jp "${s}" '{.spec.selector.app}')"
  wait_for 30 bash -c "[[ -n \$(kubectl --kubeconfig '${OPERATOR_KUBECONFIG}' -n '${NS}' get endpointslice -l kubernetes.io/service-name=web-np -o jsonpath='{.items[*].endpoints[*].addresses[0]}') ]]"
  expect_match "Service ma endpointy" '^[0-9]+\.[0-9]+' \
    "$(jp endpointslice '{.items[*].endpoints[*].addresses[0]}' -l kubernetes.io/service-name=web-np)"
}
