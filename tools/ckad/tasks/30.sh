# shellcheck shell=bash
# 30. NetworkPolicy egress — sprawdzarka (docs/ckad/30-networkpolicy-egress.md)

setup() {
  ko -n "${NS}" apply -f - >/dev/null <<EOF
apiVersion: apps/v1
kind: Deployment
metadata: { name: allowed-app }
spec:
  replicas: 1
  selector: { matchLabels: { app: allowed } }
  template:
    metadata: { labels: { app: allowed } }
    spec: { containers: [ { name: nginx, image: "nginx:1.29", ports: [ { containerPort: 80 } ] } ] }
---
apiVersion: apps/v1
kind: Deployment
metadata: { name: other-app }
spec:
  replicas: 1
  selector: { matchLabels: { app: other } }
  template:
    metadata: { labels: { app: other } }
    spec: { containers: [ { name: nginx, image: "nginx:1.29", ports: [ { containerPort: 80 } ] } ] }
---
apiVersion: v1
kind: Service
metadata: { name: allowed-svc }
spec: { selector: { app: allowed }, ports: [ { port: 80, targetPort: 80 } ] }
---
apiVersion: v1
kind: Service
metadata: { name: other-svc }
spec: { selector: { app: other }, ports: [ { port: 80, targetPort: 80 } ] }
---
apiVersion: v1
kind: Pod
metadata: { name: restricted-client, labels: { app: restricted-client } }
spec: { containers: [ { name: c, image: "busybox:1.37", command: ["sleep", "3600"] } ] }
EOF
  ko -n "${NS}" rollout status deploy/allowed-app --timeout=120s >/dev/null
  ko -n "${NS}" rollout status deploy/other-app --timeout=120s >/dev/null
  ko -n "${NS}" wait --for=condition=Ready pod/restricted-client --timeout=120s >/dev/null
}

# t30_try <polecenie w podzie> — "OK" albo "FAIL".
t30_try() { ko -n "${NS}" exec restricted-client -- sh -c "$1 >/dev/null 2>&1 && echo OK || echo FAIL" 2>/dev/null | tail -1; }
t30_allowed() { [[ "$(t30_try 'wget -qO- -T 4 http://allowed-svc')" == OK ]]; }

check() {
  local json
  json="$(ko -n "${NS}" get netpol -o json 2>/dev/null)"
  expect "istnieje polityka z Egress dla app=restricted-client" "1" \
    "$(echo "${json}" | jq '[.items[] | select(.spec.podSelector.matchLabels.app=="restricted-client" and (.spec.policyTypes|index("Egress")!=null))] | length>0 | if . then 1 else 0 end')"

  wait_for 40 t30_allowed
  expect "restricted-client łączy się z allowed-svc:80 (przez DNS)" "OK" "$(t30_try 'wget -qO- -T 4 http://allowed-svc')"
  expect "DNS działa (nslookup FQDN allowed-svc)" "OK" "$(t30_try "nslookup allowed-svc.${NS}.svc.cluster.local")"
  expect "połączenie z other-svc zablokowane" "FAIL" "$(t30_try 'wget -qO- -T 4 http://other-svc')"
  expect "połączenie z internetem (1.1.1.1) zablokowane" "FAIL" "$(t30_try 'wget -qO- -T 4 http://1.1.1.1')"
}
