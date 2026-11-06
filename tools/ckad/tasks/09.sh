# shellcheck shell=bash
# 09. Blue/green — sprawdzarka (docs/ckad/09-blue-green.md)

setup() {
  ko -n "${NS}" apply -f - <<'EOY'
apiVersion: apps/v1
kind: Deployment
metadata:
  name: app-blue
  labels: { app: app, version: blue }
spec:
  replicas: 2
  selector:
    matchLabels: { app: app, version: blue }
  template:
    metadata:
      labels: { app: app, version: blue }
    spec:
      containers:
        - name: web
          image: busybox:1.37
          command: ["sh", "-c", "mkdir -p /www; echo blue > /www/index.html; exec httpd -f -p 8080 -h /www"]
          ports:
            - containerPort: 8080
---
apiVersion: v1
kind: Service
metadata:
  name: app
spec:
  selector: { app: app, version: blue }
  ports:
    - port: 80
      targetPort: 8080
EOY
  ko -n "${NS}" rollout status deployment/app-blue --timeout=120s
}

check() {
  expect "app-green: 2 repliki" "2" "$(jp deploy/app-green '{.spec.replicas}')"
  expect "pody green mają etykietę version=green" "green" "$(jp deploy/app-green '{.spec.template.metadata.labels.version}')"
  expect "app-blue nadal istnieje" "app-blue" "$(jp deploy/app-blue '{.metadata.name}')"
  expect "Service app wybiera version=green" "green" "$(jp svc/app '{.spec.selector.version}')"

  wait_for 90 bash -c "[[ \$(kubectl --kubeconfig '${OPERATOR_KUBECONFIG}' -n '${NS}' get endpoints app -o jsonpath='{.subsets[*].addresses[*].ip}' | wc -w) -eq 2 ]]"
  local names
  names="$(jp endpoints/app '{.subsets[*].addresses[*].targetRef.name}')"
  expect "endpointy: 2 pody app-green" "2" "$(echo "${names}" | tr ' ' '\n' | grep -c '^app-green-')"
  expect "endpointy: zero podów app-blue" "0" "$(echo "${names}" | tr ' ' '\n' | grep -c '^app-blue-')"

  local out
  out="$(ko -n "${NS}" run "tmp-bg-${RANDOM}" --rm -i --restart=Never --image=busybox:1.37 --command -- \
    sh -c 'i=0; while [ $i -lt 10 ]; do wget -qO- -T 2 http://app; i=$((i+1)); done' 2>/dev/null)"
  expect_match "odpowiedź to green" 'green' "${out}"
  expect "brak odpowiedzi blue" "0" "$(echo "${out}" | grep -c blue)"
}
