# shellcheck shell=bash
# 13. Sondy: startup, readiness i liveness — sprawdzarka (docs/ckad/13-sondy-probe-web.md)

check() {
  local d="deploy/probe-web"
  local c='.spec.template.spec.containers[?(@.name=="web")]'
  expect "Deployment probe-web istnieje" "probe-web" "$(jp "${d}" '{.metadata.name}')"
  expect "obraz nginx:1.29" "nginx:1.29" "$(jp "${d}" "{${c}.image}")"
  expect "startupProbe: HTTP GET /" "/" "$(jp "${d}" "{${c}.startupProbe.httpGet.path}")"
  expect "startupProbe: port 80" "80" "$(jp "${d}" "{${c}.startupProbe.httpGet.port}")"
  expect "startupProbe: periodSeconds 2" "2" "$(jp "${d}" "{${c}.startupProbe.periodSeconds}")"
  expect "startupProbe: failureThreshold 15" "15" "$(jp "${d}" "{${c}.startupProbe.failureThreshold}")"
  expect "readinessProbe: HTTP GET /" "/" "$(jp "${d}" "{${c}.readinessProbe.httpGet.path}")"
  expect "readinessProbe: port 80" "80" "$(jp "${d}" "{${c}.readinessProbe.httpGet.port}")"
  expect "readinessProbe: periodSeconds 5" "5" "$(jp "${d}" "{${c}.readinessProbe.periodSeconds}")"
  expect "livenessProbe: TCP, port 80" "80" "$(jp "${d}" "{${c}.livenessProbe.tcpSocket.port}")"
  expect "livenessProbe: periodSeconds 10" "10" "$(jp "${d}" "{${c}.livenessProbe.periodSeconds}")"
  expect "livenessProbe: failureThreshold 3" "3" "$(jp "${d}" "{${c}.livenessProbe.failureThreshold}")"

  wait_for 90 bash -c "[[ \$(kubectl --kubeconfig '${OPERATOR_KUBECONFIG}' -n '${NS}' get ${d} -o jsonpath='{.status.readyReplicas}') == 1 ]]"
  expect "Deployment ma 1 gotowy pod" "1" "$(jp "${d}" '{.status.readyReplicas}')"
}
