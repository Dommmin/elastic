#!/usr/bin/env bash
# ============================================================================
#  install-docker.sh — Docker Engine z OFICJALNEGO repo (ETAP D, Task 7)
#
#    ssh elastic-vps 'sudo bash -s' < tools/vps/install-docker.sh
#
#  Dlaczego nie `apt install docker.io` (paczka Ubuntu): bywa kilka wersji
#  za upstreamem, a nakładka compose.prod.yaml wymaga Compose >= 2.24
#  (tagi !reset / !override). Oficjalne repo = aktualny Engine + plugin Compose.
#
#  daemon.json: rotacja logów. Domyślny sterownik json-file NIE ma limitu —
#  gadatliwy kontener (np. ES przy problemach) potrafi zapchać dysk w kilka dni.
# ============================================================================
set -euo pipefail

[ "$(id -u)" -eq 0 ] || { echo "Uruchom przez sudo." >&2; exit 1; }
export DEBIAN_FRONTEND=noninteractive

. /etc/os-release
install -m 0755 -d /etc/apt/keyrings
curl -fsSL "https://download.docker.com/linux/${ID}/gpg" -o /etc/apt/keyrings/docker.asc
chmod a+r /etc/apt/keyrings/docker.asc
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/${ID} ${VERSION_CODENAME} stable" \
  > /etc/apt/sources.list.d/docker.list

apt-get update -q
apt-get -y -q install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

install -d /etc/docker
cat > /etc/docker/daemon.json <<'JSON'
{
  "log-driver": "json-file",
  "log-opts": { "max-size": "10m", "max-file": "3" },
  "live-restore": true
}
JSON
# live-restore: restart samego demona Dockera (np. przy aktualizacji)
# nie zabija kontenerów — klaster ES nie musi przechodzić restartu.

systemctl enable docker
systemctl restart docker
usermod -aG docker deploy

docker version --format 'Docker Engine {{.Server.Version}}'
docker compose version
