#!/usr/bin/env bash
# ============================================================================
#  tools/k3s/ingress-tunnel.sh — https://*.marketplace.test:8443 z Maca (ETAP G2)
#
#    make k3s-ingress-tunnel
#
#  Traefik ma Service typu ClusterIP: adres 10.43.x.x istnieje tylko w klastrze,
#  ale węzeł (VPS) go zna — kube-proxy przekierowuje ruch z hosta tak samo jak
#  z podów. Tunel SSH: localhost:8443 na Macu -> <ClusterIP Traefika>:443 na VPS.
#  Na publicznym adresie VPS nadal nic nie słucha (faza exposure to sprawdza).
#
#  Adres bierzemy z API przy każdym uruchomieniu, nie na sztywno: po
#  reinstalacji chartu Service dostaje nowy ClusterIP.
# ============================================================================
set -euo pipefail
PORT="${INGRESS_PORT:-8443}"
CA_FILE="${HOME}/.kube/marketplace-ca.crt"
kk() { /opt/homebrew/bin/kubectl --kubeconfig "${HOME}/.kube/elastic-vps.yaml" --request-timeout=30s "$@"; }

nc -z 127.0.0.1 26443 2>/dev/null || ssh -fN elastic-vps-k8s

ip="$(kk -n ingress get svc traefik -o jsonpath='{.spec.clusterIP}')"
[ -n "${ip}" ] || { echo "Brak Service ingress/traefik — najpierw make k3s-ingress" >&2; exit 1; }

# Certyfikat CA (publiczny, bez klucza) — do zaufania w przeglądarce i dla curl --cacert.
kk -n cert-manager get secret marketplace-ca -o jsonpath='{.data.ca\.crt}' | base64 -d > "${CA_FILE}"

if nc -z 127.0.0.1 "${PORT}" 2>/dev/null; then
  echo "Port ${PORT} już nasłuchuje (tunel działa albo port zajęty)."
else
  # Alias elastic-vps, NIE elastic-vps-k8s: ten drugi ma w ~/.ssh/config
  # LocalForward 26443 (API), zajęty przez tunel `make k3s-tunnel` — z
  # ExitOnForwardFailure nowe połączenie padało na „Address already in use”.
  ssh -o ExitOnForwardFailure=yes -fN -L "127.0.0.1:${PORT}:${ip}:443" elastic-vps
  echo "Tunel: 127.0.0.1:${PORT} -> traefik ${ip}:443"
fi

cat <<EOF

  Jednorazowo na Macu (wymaga sudo — zrób to sam):
    echo "127.0.0.1 shop.marketplace.test grafana.marketplace.test kibana.marketplace.test" | sudo tee -a /etc/hosts
    sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain ${CA_FILE}

  https://shop.marketplace.test:${PORT}/search
  https://grafana.marketplace.test:${PORT}     (login: make k3s-grafana wypisuje hasło)
  https://kibana.marketplace.test:${PORT}      (elastic / make vps-secret k=ELASTIC_PASSWORD)

  Bez /etc/hosts:  curl --cacert ${CA_FILE} --resolve shop.marketplace.test:${PORT}:127.0.0.1 https://shop.marketplace.test:${PORT}/up
EOF
