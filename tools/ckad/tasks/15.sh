# shellcheck shell=bash
# 15. ImagePullBackOff i zdarzenia — sprawdzarka (docs/ckad/15-imagepullbackoff-zdarzenia.md)

setup() {
  rm -f /tmp/ckad-15.txt
  ko -n "${NS}" create deployment pull-web --image=nginx:1.29.99-nie-ma --port=80 >/dev/null
  ko -n "${NS}" set image deployment/pull-web nginx=nginx:1.29.99-nie-ma >/dev/null 2>&1 || true
  ko -n "${NS}" patch deployment pull-web --type=json \
    -p '[{"op":"replace","path":"/spec/template/spec/containers/0/name","value":"web"}]' >/dev/null
}

check() {
  local d="deploy/pull-web"
  expect "obraz Deploymentu to nginx:1.29" "nginx:1.29" "$(jp "${d}" '{.spec.template.spec.containers[?(@.name=="web")].image}')"
  wait_for 120 bash -c "[[ \$(kubectl --kubeconfig '${OPERATOR_KUBECONFIG}' -n '${NS}' get ${d} -o jsonpath='{.status.availableReplicas}') == 1 && \$(kubectl --kubeconfig '${OPERATOR_KUBECONFIG}' -n '${NS}' get pods -l app=pull-web -o name | wc -l) -eq 1 ]]"
  expect "Deployment dostępny (1 pod)" "1" "$(jp "${d}" '{.status.availableReplicas}')"

  local txt=""
  [[ -f /tmp/ckad-15.txt ]] && txt="$(cat /tmp/ckad-15.txt)"
  expect_match "plik /tmp/ckad-15.txt zawiera powód ze zdarzeń" 'ErrImagePull|ImagePullBackOff|Failed to pull|not found|manifest unknown|Back-off pulling' "${txt}"
}
