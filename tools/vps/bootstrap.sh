#!/usr/bin/env bash
# ============================================================================
#  bootstrap.sh — pierwsze zabezpieczenie świeżego VPS-a (ETAP D, Task 6)
#
#  Uruchamiany JEDEN raz jako root, z Maca:
#    ssh root@<IP> 'bash -s' < tools/vps/bootstrap.sh
#  Idempotentny: drugie uruchomienie niczego nie psuje.
#
#  Co robi i DLACZEGO:
#    1. aktualizacje             — świeży obraz dostawcy bywa sprzed miesięcy
#    2. użytkownik deploy        — root nie loguje się przez SSH w ogóle
#    3. sshd: tylko klucze       — hasła = cel botów brute-force (są w logach
#                                  w ciągu minut od uruchomienia serwera)
#    4. ufw: tylko 22/tcp        — reszta usług słucha na 127.0.0.1 (tunel SSH)
#    5. fail2ban                 — banuje IP po serii nieudanych logowań
#    6. unattended-upgrades      — łatki bezpieczeństwa same, bez restartu
#    7. sysctl pod Elasticsearch — vm.max_map_count (bez tego ES nie wstanie),
#                                  vm.swappiness=1 (JVM w swapie = pauzy GC)
#    8. swap 2 GB                — bezpiecznik przed OOM-killerem, nie pamięć robocza
#
#  NIE wyłącza sesji, z której jest uruchamiany: reload sshd nie zrywa
#  istniejących połączeń. Odcięcie się = dopiero gdy zamkniesz starą sesję
#  bez sprawdzenia nowej (docs/08-PLAN-ETAP-D-VPS.md, Task 6, Krok 4).
# ============================================================================
set -euo pipefail

DEPLOY_USER=deploy
APP_DIR=/opt/marketplace

log() { echo -e "\n\033[1m[bootstrap] $*\033[0m"; }

[ "$(id -u)" -eq 0 ] || { echo "Uruchom jako root." >&2; exit 1; }
[ -s /root/.ssh/authorized_keys ] || { echo "Brak /root/.ssh/authorized_keys — nie ma czego skopiować dla ${DEPLOY_USER}." >&2; exit 1; }

export DEBIAN_FRONTEND=noninteractive

log "1/8 aktualizacje i pakiety"
apt-get update -q
apt-get -y -q -o Dpkg::Options::=--force-confold full-upgrade
apt-get -y -q install ufw fail2ban unattended-upgrades ca-certificates curl gnupg jq rsync

log "2/8 użytkownik ${DEPLOY_USER}"
if ! id "${DEPLOY_USER}" >/dev/null 2>&1; then
  adduser --disabled-password --gecos "" "${DEPLOY_USER}"
fi
install -d -m 700 -o "${DEPLOY_USER}" -g "${DEPLOY_USER}" "/home/${DEPLOY_USER}/.ssh"
install -m 600 -o "${DEPLOY_USER}" -g "${DEPLOY_USER}" /root/.ssh/authorized_keys "/home/${DEPLOY_USER}/.ssh/authorized_keys"
# sudo bez hasła: świadomy kompromis — automatyzacja przez SSH nie ma jak
# podać hasła. Ochroną jest to, że do konta da się wejść TYLKO kluczem.
echo "${DEPLOY_USER} ALL=(ALL) NOPASSWD:ALL" > "/etc/sudoers.d/90-${DEPLOY_USER}"
chmod 440 "/etc/sudoers.d/90-${DEPLOY_USER}"
visudo -cf "/etc/sudoers.d/90-${DEPLOY_USER}"
install -d -m 750 -o "${DEPLOY_USER}" -g "${DEPLOY_USER}" "${APP_DIR}"

log "3/8 sshd: tylko klucze, bez roota"
cat > /etc/ssh/sshd_config.d/10-hardening.conf <<'CONF'
# ETAP D — tools/vps/bootstrap.sh
PermitRootLogin no
PasswordAuthentication no
KbdInteractiveAuthentication no
PubkeyAuthentication yes
MaxAuthTries 3
LoginGraceTime 30
X11Forwarding no
# AllowTcpForwarding zostaje włączone — na nim działa tunel do UI (Task 9).
CONF
# Część obrazów (cloud-init) ma plik z `PasswordAuthentication yes`, który
# sortuje się PRZED naszym i wygrywa (sshd bierze PIERWSZĄ wartość).
for f in /etc/ssh/sshd_config.d/*.conf; do
  [ "$f" = /etc/ssh/sshd_config.d/10-hardening.conf ] && continue
  sed -i -E 's/^\s*(PasswordAuthentication|PermitRootLogin)\s+yes/# wyłączone przez bootstrap.sh: &/' "$f"
done
sshd -t   # składnia PRZED reloadem — błąd tutaj nie zamyka drzwi
systemctl reload ssh

log "4/8 firewall"
ufw default deny incoming
ufw default allow outgoing
ufw allow 22/tcp
ufw --force enable

log "5/8 fail2ban"
cat > /etc/fail2ban/jail.d/sshd.local <<'CONF'
[sshd]
enabled  = true
backend  = systemd
maxretry = 5
findtime = 10m
bantime  = 1h
CONF
systemctl enable --now fail2ban
systemctl restart fail2ban

log "6/8 automatyczne łatki bezpieczeństwa"
cat > /etc/apt/apt.conf.d/20auto-upgrades <<'CONF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
CONF
# Restart serwera zostaje decyzją człowieka (restart klastra ES to operacja,
# którą chcemy obserwować — RUNBOOK #021).
cat > /etc/apt/apt.conf.d/52unattended-no-reboot <<'CONF'
Unattended-Upgrade::Automatic-Reboot "false";
CONF

log "7/8 sysctl pod Elasticsearch"
cat > /etc/sysctl.d/99-elasticsearch.conf <<'CONF'
# ES mapuje pliki indeksu do pamięci (mmap). Domyślne 65530 to za mało —
# ES w trybie produkcyjnym odmawia startu (bootstrap check).
vm.max_map_count = 262144
# JVM wyswapowana na dysk = wielosekundowe pauzy GC i węzeł "znika" z klastra.
vm.swappiness = 1
CONF
sysctl --system >/dev/null

log "8/8 swap 2 GB"
if ! swapon --show | grep -q /swapfile; then
  fallocate -l 2G /swapfile
  chmod 600 /swapfile
  mkswap /swapfile >/dev/null
  swapon /swapfile
  grep -q '^/swapfile' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

timedatectl set-timezone Europe/Warsaw

log "GOTOWE. Teraz W DRUGIEJ sesji: ssh ${DEPLOY_USER}@<IP> sudo true — dopiero potem zamknij tę."
