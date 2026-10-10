#!/usr/bin/env bash
# ============================================================================
#  install-k3s.sh — k3s na VPS obok Dockera (ETAP D2, Task 1)
#
#  Uruchamiany na serwerze jako root, w tle (lekcja z RUNBOOK #034):
#    scp tools/k3s/install-k3s.sh elastic-vps:/tmp/
#    ssh elastic-vps 'sudo -n sh -c "nohup bash /tmp/install-k3s.sh > /var/log/install-k3s.log 2>&1 < /dev/null &"'
#  Idempotentny: ponowne uruchomienie z tą samą wersją niczego nie zmienia.
#
#  Co i DLACZEGO:
#    - wersja PRZYPIĘTA (kanał stable z dnia planu), nie "latest" — aktualizacja
#      klastra to świadoma operacja, nie efekt uboczny reinstalacji;
#    - bez Traefika (ingress) i ServiceLB (load balancer na portach hosta):
#      oba otwierałyby porty 80/443 na węźle, a my dostajemy się przez SSH;
#    - ufw: ruch Z SIECI PODÓW (10.42/16) i USŁUG (10.43/16) do hosta.
#      Pod -> Service API (10.43.0.1) jest DNAT-owany na adres węzła:6443,
#      a `ufw default deny incoming` by go odrzucił — CoreDNS i operatory
#      nie dogadałyby się z API. To NIE otwiera niczego na internet;
#    - Docker (D1) działa dalej: k3s ma własny containerd (/run/k3s/containerd).
# ============================================================================
set -euo pipefail

K3S_VERSION="v1.36.5+k3s1"
DEPLOY_USER=deploy

log() { echo "[install-k3s] $*"; }
[ "$(id -u)" -eq 0 ] || { echo "Uruchom jako root (sudo)." >&2; exit 1; }

log "1/4 konfiguracja /etc/rancher/k3s/config.yaml"
install -d -m 755 /etc/rancher/k3s
cat > /etc/rancher/k3s/config.yaml <<'CONF'
# ETAP D2 — tools/k3s/install-k3s.sh
disable:
  - traefik
  - servicelb
write-kubeconfig-mode: "0600"
# Strefa czasowa kontrolera nie ma znaczenia — CronJob dostaje własne
# `timeZone` w manifeście (pg-backup: Europe/Warsaw).
CONF

log "2/4 ufw: sieć podów i usług (ruch wewnątrz klastra)"
ufw allow from 10.42.0.0/16 to any comment 'k3s pods' >/dev/null
ufw allow from 10.43.0.0/16 to any comment 'k3s services' >/dev/null

log "3/4 k3s ${K3S_VERSION}"
if k3s --version 2>/dev/null | grep -q "${K3S_VERSION}"; then
  log "    już zainstalowany — pomijam"
else
  curl -sfL https://get.k3s.io | INSTALL_K3S_VERSION="${K3S_VERSION}" sh -s - server
fi
systemctl enable --now k3s

for _ in $(seq 1 60); do
  k3s kubectl get nodes 2>/dev/null | grep -q ' Ready' && break
  sleep 2
done
k3s kubectl get nodes

log "4/4 kubeconfig dla ${DEPLOY_USER}"
install -d -m 700 -o "${DEPLOY_USER}" -g "${DEPLOY_USER}" "/home/${DEPLOY_USER}/.kube"
install -m 600 -o "${DEPLOY_USER}" -g "${DEPLOY_USER}" /etc/rancher/k3s/k3s.yaml "/home/${DEPLOY_USER}/.kube/config"

log "GOTOWE"
