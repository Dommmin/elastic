# shellcheck shell=bash
# 28. NetworkPolicy ingress — sprawdzarka (docs/ckad/28-networkpolicy-ingress.md)

setup() {
  ko -n "${NS_B}" create deployment api --image=nginx:1.29 --replicas=2 --port=80 >/dev/null
  ko -n "${NS_B}" expose deployment api --port=80 >/dev/null
  ko -n "${NS_B}" rollout status deploy/api --timeout=120s >/dev/null
}

# t28_get <etykiety> — kod odpowiedzi w stdout: "OK" gdy HTTP 200, "FAIL" przy timeoucie.
t28_get() {
  local out
  out="$(ko -n "${NS_B}" run "tmp-28-${RANDOM}" --rm -i -q --restart=Never --labels="$1" --image=busybox:1.37 --pod-running-timeout=90s \
    -- sh -c 'wget -qO- -T 4 http://api >/dev/null 2>&1 && echo OK || echo FAIL' 2>/dev/null)"
  echo "${out}" | grep -Eo 'OK|FAIL' | tail -1
}
t28_ok() { [[ "$(t28_get role=frontend)" == OK ]]; }

check() {
  local json
  json="$(ko -n "${NS_B}" get netpol -o json 2>/dev/null)"
  expect "polityka default-deny: pusty podSelector, Ingress, brak reguł ingress" "1" \
    "$(echo "${json}" | jq '[.items[] | select((.spec.podSelector|length)==0 and (.spec.policyTypes|index("Ingress")!=null) and ((.spec.ingress//[])|length)==0)] | length>0 | if . then 1 else 0 end')"
  expect "polityka dla app=api: from podSelector role=frontend" "frontend" \
    "$(echo "${json}" | jq -r '[.items[] | select(.spec.podSelector.matchLabels.app=="api") | .spec.ingress[]?.from[]?.podSelector.matchLabels.role] | first // ""')"
  expect "polityka dla app=api: tylko port 80/TCP" "80/TCP" \
    "$(echo "${json}" | jq -r '[.items[] | select(.spec.podSelector.matchLabels.app=="api") | .spec.ingress[]?.ports[]? | "\(.port)/\(.protocol//"TCP")"] | unique | join(",")')"

  wait_for 40 t28_ok
  expect "pod z role=frontend łączy się z api:80" "OK" "$(t28_get role=frontend)"
  expect "pod bez etykiety NIE łączy się z api:80 (timeout)" "FAIL" "$(t28_get app=intruder)"
}
