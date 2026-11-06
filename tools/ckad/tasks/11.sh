# shellcheck shell=bash
# 11. Kustomize: overlay prod — sprawdzarka (docs/ckad/11-kustomize-overlay-prod.md)

check() {
  local d="deploy/prod-site"
  expect "Deployment prod-site istnieje (namePrefix)" "prod-site" "$(jp "${d}" '{.metadata.name}')"
  expect "Service prod-site istnieje (namePrefix)" "prod-site" "$(jp svc/prod-site '{.metadata.name}')"
  expect "etykieta env=prod na Deploymencie" "prod" "$(jp "${d}" '{.metadata.labels.env}')"
  expect "etykieta env=prod na Service" "prod" "$(jp svc/prod-site '{.metadata.labels.env}')"
  expect "repliki: 3" "3" "$(jp "${d}" '{.spec.replicas}')"

  local cm
  cm="$(jp "${d}" '{.spec.template.spec.containers[0].envFrom[0].configMapRef.name}')"
  expect_match "Deployment używa ConfigMapy prod-site-config-<hash>" '^prod-site-config-[a-z0-9]{6,}$' "${cm}"
  expect "ConfigMapa wskazana przez Deployment istnieje z APP_MODE=production" "production" \
    "$(ko -n "${NS}" get cm "${cm:-none}" -o jsonpath='{.data.APP_MODE}' 2>/dev/null)"
  expect "brak ConfigMapy site-config bez hasha/prefiksu" "0" "$(ko -n "${NS}" get cm -o name 2>/dev/null | grep -cx 'configmap/site-config')"

  wait_for 90 bash -c "[[ \$(kubectl --kubeconfig '${OPERATOR_KUBECONFIG}' -n '${NS}' get ${d} -o jsonpath='{.status.availableReplicas}') == 3 ]]"
  expect "3 repliki dostępne" "3" "$(jp "${d}" '{.status.availableReplicas}')"
}
