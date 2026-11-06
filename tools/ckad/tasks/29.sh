# shellcheck shell=bash
# 29. Ingress z routingiem po ścieżkach — sprawdzarka (docs/ckad/29-ingress-sciezki.md)

setup() {
  ko -n "${NS}" create deployment api-svc-app --image=busybox:1.37 --replicas=1 --port=80 -- \
    sh -c 'mkdir -p /www/api && echo api-backend > /www/api/index.html && httpd -f -p 80 -h /www' >/dev/null
  ko -n "${NS}" create deployment web-svc-app --image=nginx:1.29 --replicas=1 --port=80 >/dev/null
  ko -n "${NS}" expose deployment api-svc-app --name=api-svc --port=80 >/dev/null
  ko -n "${NS}" expose deployment web-svc-app --name=web-svc --port=80 >/dev/null
  ko -n "${NS}" rollout status deploy/api-svc-app --timeout=120s >/dev/null
  ko -n "${NS}" rollout status deploy/web-svc-app --timeout=120s >/dev/null
}

# t29_body <ścieżka> — treść odpowiedzi Traefika (z poda w ${NS}) po HTTPS; HTTP przekierowuje na 443.
# Host przesyłamy nagłówkiem: routing Traefika idzie po nim, nie po adresie.
t29_body() {
  ko -n "${NS}" run "tmp-29-${RANDOM}" --rm -i -q --restart=Never --image=busybox:1.37 --pod-running-timeout=90s \
    -- wget -qO- -T 5 --no-check-certificate --header 'Host: ckad.marketplace.test' "https://traefik.ingress.svc.cluster.local$1" 2>/dev/null
}
t29_ready() { [[ "$(t29_body /api/)" == *api-backend* ]]; }

check() {
  local i="ingress/shop-ingress"
  expect "Ingress shop-ingress istnieje" "shop-ingress" "$(jp "${i}" '{.metadata.name}')"
  expect "ingressClassName traefik" "traefik" "$(jp "${i}" '{.spec.ingressClassName}')"
  expect "host ckad.marketplace.test" "ckad.marketplace.test" "$(jp "${i}" '{.spec.rules[0].host}')"
  local json; json="$(ko -n "${NS}" get ${i} -o json 2>/dev/null)"
  expect "/api (Prefix) -> api-svc:80" "api-svc:80" \
    "$(echo "${json}" | jq -r '[.spec.rules[].http.paths[] | select(.path=="/api" and .pathType=="Prefix") | "\(.backend.service.name):\(.backend.service.port.number)"] | first // ""')"
  expect "/ (Prefix) -> web-svc:80" "web-svc:80" \
    "$(echo "${json}" | jq -r '[.spec.rules[].http.paths[] | select(.path=="/" and .pathType=="Prefix") | "\(.backend.service.name):\(.backend.service.port.number)"] | first // ""')"
  local s
  for s in api-svc web-svc; do
    expect_match "Service ${s} ma endpointy" '^[0-9]+\.[0-9]+' \
      "$(jp endpointslice '{.items[*].endpoints[*].addresses[0]}' -l "kubernetes.io/service-name=${s}")"
  done

  # Żywy test przez Traefika (ns ingress): /api/ -> backend api (api-backend), / -> nginx.
  wait_for 40 t29_ready
  expect_match "przez Traefika: /api/ trafia do api-svc" 'api-backend' "$(t29_body /api/)"
  expect_match "przez Traefika: / trafia do web-svc (nginx)" 'Welcome to nginx' "$(t29_body /)"
}
