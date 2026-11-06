# shellcheck shell=bash
# 14. Debug: CrashLoopBackOff — sprawdzarka (docs/ckad/14-debug-crashloop.md)

setup() {
  ko -n "${NS}" apply -f - >/dev/null <<'YAML'
apiVersion: v1
kind: ConfigMap
metadata:
  name: broken-api-config
data:
  API_MODE: production
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: broken-api
spec:
  replicas: 1
  selector:
    matchLabels: { app: broken-api }
  template:
    metadata:
      labels: { app: broken-api }
    spec:
      containers:
        - name: api
          image: busybox:1.37
          command:
            - sh
            - -c
            - |
              if [ -z "$API_MODE" ]; then echo "FATAL: brak zmiennej API_MODE" >&2; exit 1; fi
              echo "api startuje w trybie $API_MODE"; sleep 3600
YAML
}

check() {
  local d="deploy/broken-api"
  expect "Deployment broken-api istnieje" "broken-api" "$(jp "${d}" '{.metadata.name}')"
  expect_match "polecenie kontenera nie zostało zmienione (nadal sprawdza API_MODE)" 'API_MODE' \
    "$(jp "${d}" '{.spec.template.spec.containers[0].command}')"

  local src
  src="$(jp "${d}" '{.spec.template.spec.containers[0].envFrom[*].configMapRef.name} {.spec.template.spec.containers[0].env[?(@.name=="API_MODE")].valueFrom.configMapKeyRef.name}')"
  expect_match "API_MODE pochodzi z ConfigMapy broken-api-config" 'broken-api-config' "${src}"
  expect "ConfigMapa nie została zmieniona" "production" "$(jp cm/broken-api-config '{.data.API_MODE}')"

  wait_for 90 bash -c "[[ \$(kubectl --kubeconfig '${OPERATOR_KUBECONFIG}' -n '${NS}' get ${d} -o jsonpath='{.status.readyReplicas}') == 1 && \$(kubectl --kubeconfig '${OPERATOR_KUBECONFIG}' -n '${NS}' get pods -l app=broken-api -o name | wc -l) -eq 1 ]]"
  expect "Deployment ma 1 gotowy pod" "1" "$(jp "${d}" '{.status.readyReplicas}')"
  expect "pody broken-api: 0 restartów" "0" \
    "$(jp pods '{.items[*].status.containerStatuses[0].restartCount}' -l app=broken-api | tr ' ' '\n' | awk '{s+=$1} END{print s+0}')"
}
