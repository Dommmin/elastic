# shellcheck shell=bash
# 08. Canary na dwóch Deploymentach — sprawdzarka (docs/ckad/08-canary-dwa-deploymenty.md)

check() {
  expect "shop-v1: 3 repliki" "3" "$(jp deploy/shop-v1 '{.spec.replicas}')"
  expect "shop-v2: 1 replika" "1" "$(jp deploy/shop-v2 '{.spec.replicas}')"
  expect "pody v1 mają etykietę app=shop" "shop" "$(jp deploy/shop-v1 '{.spec.template.metadata.labels.app}')"
  expect "pody v2 mają etykietę app=shop" "shop" "$(jp deploy/shop-v2 '{.spec.template.metadata.labels.app}')"
  expect "selektory Deploymentów się różnią" "different" \
    "$([[ "$(jp deploy/shop-v1 '{.spec.selector.matchLabels}')" != "$(jp deploy/shop-v2 '{.spec.selector.matchLabels}')" ]] && echo different || echo same)"
  expect "Service shop wybiera po app=shop" "shop" "$(jp svc/shop '{.spec.selector.app}')"
  expect "Service shop: port 80" "80" "$(jp svc/shop '{.spec.ports[0].port}')"

  wait_for 120 bash -c "[[ \$(kubectl --kubeconfig '${OPERATOR_KUBECONFIG}' -n '${NS}' get endpoints shop -o jsonpath='{.subsets[*].addresses[*].ip}' | wc -w) -ge 4 ]]"
  local names
  names="$(jp endpoints/shop '{.subsets[*].addresses[*].targetRef.name}')"
  expect "endpointy: 3 pody z shop-v1" "3" "$(echo "${names}" | tr ' ' '\n' | grep -c '^shop-v1-')"
  expect "endpointy: 1 pod z shop-v2" "1" "$(echo "${names}" | tr ' ' '\n' | grep -c '^shop-v2-')"

  local out
  out="$(ko -n "${NS}" run "tmp-shop-${RANDOM}" --rm -i --restart=Never --image=busybox:1.37 --command -- \
    sh -c 'i=0; while [ $i -lt 40 ]; do wget -qO- -T 2 http://shop; i=$((i+1)); done' 2>/dev/null)"
  expect_match "odpowiedzi zawierają wersję v1" 'v1' "${out}"
  expect_match "odpowiedzi zawierają wersję v2" 'v2' "${out}"
}
