#!/usr/bin/env bash
# ============================================================================
#  verify.sh <faza> — weryfikacja ETAPU D, uruchamiana z MACA
#
#  Każda faza odpowiada taskowi z docs/08-PLAN-ETAP-D-VPS.md i jest
#  pisana PRZED wykonaniem taska (czerwona), a po nim ma być zielona.
#
#    images     obrazy w GHCR, publiczne, linux/amd64        (Task 3)
#    access     serwer osiągalny, sprzęt zgodny z wymaganiami (Task 4-5)
#    hardening  deploy+klucz, root/hasła wyłączone, ufw, fail2ban, sysctl (Task 6)
#    exposure   z internetu otwarty TYLKO port 22 (skan z Maca)    (Task 6+)
#    docker     Engine z oficjalnego repo, Compose >= 2.24, rotacja logów (Task 7)
#    stack      wdrożony stack: bez kodu na serwerze, ES green, smoke, eval, E2E (Task 8)
#    backup     snapshot ES (SLM) i dump PG świeże, timer aktywny          (Task 10)
#    reboot     RESTARTUJE serwer i sprawdza, że wszystko wstaje samo       (Task 10)
#               — tylko jawnie, NIE wchodzi w `all`
#    all        wszystkie fazy po kolei
#
#  Serwer: alias SSH `elastic-vps` (~/.ssh/config). Tag obrazów: TAG=<sha>
#  (domyślnie ostatni udany build CI — tools/vps/latest-image-tag.sh).
# ============================================================================
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
HOST="${VPS_HOST:-elastic-vps}"
REGISTRY="ghcr.io/dommmin"
IMAGES=(elasticsearch postgres rabbitmq catalog search)
RED='\033[0;31m'; GRN='\033[0;32m'; BLD='\033[1m'; DIM='\033[2m'; NC='\033[0m'
PASS=0; FAIL=0

check() { # check <opis> <oczekiwane> <otrzymane>
  if [ "$2" = "$3" ]; then
    echo -e "  ${GRN}✓${NC} $1"; PASS=$((PASS+1))
  else
    echo -e "  ${RED}✗${NC} $1  ${DIM}(oczekiwano: $2, otrzymano: $3)${NC}"; FAIL=$((FAIL+1))
  fi
}
atleast() { # atleast <opis> <minimum> <otrzymane>
  if [ -n "$3" ] && [ "$3" -ge "$2" ] 2>/dev/null; then
    echo -e "  ${GRN}✓${NC} $1 ${DIM}($3)${NC}"; PASS=$((PASS+1))
  else
    echo -e "  ${RED}✗${NC} $1  ${DIM}(minimum: $2, otrzymano: ${3:-brak})${NC}"; FAIL=$((FAIL+1))
  fi
}
remote() { ssh -o BatchMode=yes -o ConnectTimeout=10 "${HOST}" "$@" 2>/dev/null; }
as_user() { local u="$1"; shift; ssh -o BatchMode=yes -o ConnectTimeout=10 -o User="$u" "${HOST}" "$@" 2>/dev/null; }
server_ip() { ssh -G "${HOST}" | awk '/^hostname / {print $2}'; }

# --------------------------------------------------------------- images -----
phase_images() {
  local tag="${TAG:-$("${ROOT}/tools/vps/latest-image-tag.sh" 2>/dev/null)}"
  echo -e "\n${BLD}  images — ${REGISTRY}/elastic-*:${tag:0:12}${NC}"
  # Pusty DOCKER_CONFIG = brak zalogowania. Jeśli obraz da się pobrać tak,
  # to da się go pobrać na serwerze bez żadnego tokenu (= paczka publiczna).
  local anon; anon="$(mktemp -d)"
  for name in "${IMAGES[@]}"; do
    local platforms
    # -v zwraca deskryptor z platformą — także dla pojedynczego manifestu
    # (docker push obrazu z jedną architekturą nie tworzy listy manifestów).
    platforms=$(DOCKER_CONFIG="${anon}" docker manifest inspect -v "${REGISTRY}/elastic-${name}:${tag}" 2>/dev/null \
      | python3 -c '
import json, sys
d = json.load(sys.stdin)
d = d if isinstance(d, list) else [d]
ps = sorted({f"{x["Descriptor"]["platform"]["os"]}/{x["Descriptor"]["platform"]["architecture"]}" for x in d
             if x["Descriptor"].get("platform", {}).get("os") not in (None, "unknown")})
print(",".join(ps))' 2>/dev/null)
    check "elastic-${name}: publiczny, linux/amd64" "linux/amd64" "${platforms:-niedostępny}"
  done
  rm -rf "${anon}"
}

# --------------------------------------------------------------- access -----
phase_access() {
  echo -e "\n${BLD}  access — ${HOST}${NC}"
  check "SSH kluczem (bez hasła)" "0" "$(remote true; echo $?)"
  atleast "vCPU" 4 "$(remote nproc)"
  atleast "RAM [GB]" 23 "$(remote "awk '/MemTotal/ {printf \"%d\", \$2/1024/1024 + 0.5}' /proc/meminfo")"
  atleast "wolne na / [GB]" 80 "$(remote "df -BG --output=avail / | tail -1 | tr -dc 0-9")"
  check "architektura" "x86_64" "$(remote uname -m)"
  check "system" "ubuntu" "$(remote ". /etc/os-release && echo \$ID")"
}


# ------------------------------------------------------------ hardening -----
phase_hardening() {
  echo -e "\n${BLD}  hardening — ${HOST}${NC}"
  local login; login="$(as_user deploy true; echo $?)"
  check "deploy: logowanie kluczem"            "0"   "${login}"
  # Bez działającego konta NIE próbujemy dalej: każda kolejna próba to
  # nieudane logowanie, a fail2ban po 5 takich banuje IP (RUNBOOK #034).
  [ "${login}" = "0" ] || { echo -e "  ${DIM}(pozostałe sprawdzenia pominięte — nie ma jak się zalogować)${NC}"; return; }
  check "deploy: sudo bez hasła"               "0"   "$(as_user deploy sudo -n true; echo $?)"
  # Jedna próba roota = jedno nieudane logowanie (limit fail2ban: 5).
  check "root: logowanie odrzucone"            "255" "$(as_user root true; echo $?)"
  local sshd; sshd="$(as_user deploy sudo -n sshd -T 2>/dev/null)"
  check "sshd: passwordauthentication no"      "1"   "$(grep -c '^passwordauthentication no$' <<<"$sshd")"
  check "sshd: permitrootlogin no"             "1"   "$(grep -c '^permitrootlogin no$' <<<"$sshd")"
  check "ufw aktywny"                          "1"   "$(as_user deploy sudo -n ufw status | grep -c '^Status: active')"
  check "ufw: jedyna reguła ALLOW to 22/tcp"   "22/tcp" "$(as_user deploy sudo -n ufw status | awk '/ALLOW/ && !/\(v6\)/ {print $1}' | sort -u | tr '\n' ' ' | sed 's/ $//')"
  check "fail2ban: jail sshd"                  "0"   "$(as_user deploy sudo -n fail2ban-client status sshd >/dev/null; echo $?)"
  check "automatyczne łatki"                   "1"   "$(as_user deploy apt-config dump APT::Periodic::Unattended-Upgrade | grep -c '"1"')"
  atleast "vm.max_map_count (min. 262144)"    262144 "$(as_user deploy sysctl -n vm.max_map_count)"
  check "vm.swappiness"                        "1"   "$(as_user deploy sysctl -n vm.swappiness)"
  check "swap aktywny"                         "1"   "$(as_user deploy swapon --show --noheadings | grep -c /swapfile)"
  check "strefa czasowa"                       "Europe/Warsaw" "$(as_user deploy timedatectl show -p Timezone --value)"
  check "/opt/marketplace należy do deploy"    "deploy" "$(as_user deploy stat -c %U /opt/marketplace)"
}

# ------------------------------------------------------------- exposure -----
# Skan Z ZEWNĄTRZ (z Maca), a nie `ss -tlnp` na serwerze: Docker publikuje
# porty przez własne reguły iptables, z pominięciem ufw. Lista reguł ufw
# może być idealna, a port i tak otwarty — liczy się tylko to, co widać z sieci.
phase_exposure() {
  local ip; ip="$(server_ip)"
  echo -e "\n${BLD}  exposure — ${ip} (z zewnątrz)${NC}"
  check "22/tcp otwarty" "otwarty" "$(nc -z -G 3 "${ip}" 22 >/dev/null 2>&1 && echo otwarty || echo zamknięty)"
  for port in 80 443 5432 5601 5672 6379 8080 8443 9200 9201 9202 15672 18080 19200; do
    check "${port}/tcp zamknięty" "zamknięty" "$(nc -z -G 3 "${ip}" "${port}" >/dev/null 2>&1 && echo OTWARTY || echo zamknięty)"
  done
}

# --------------------------------------------------------------- docker -----
phase_docker() {
  echo -e "\n${BLD}  docker — ${HOST}${NC}"
  check "docker bez sudo (deploy w grupie docker)" "0" "$(remote docker info >/dev/null; echo $?)"
  check "Engine z download.docker.com (nie docker.io)" "1" "$(remote "apt-cache policy docker-ce | grep -c 'download.docker.com' | head -1" | awk '{print ($1>0)?1:0}')"
  # Compose >= 2.24: tagi !reset/!override w compose.prod.yaml
  check "Compose >= 2.24" "tak" "$(remote docker compose version --short | python3 -c 'import sys; v=[int(x) for x in sys.stdin.read().strip().lstrip("v").split(".")[:2]]; print("tak" if v>=[2,24] else "nie: %s" % v)' 2>/dev/null)"
  check "logi: json-file z max-size=10m, max-file=3" "json-file 10m 3" \
    "$(remote "docker info --format '{{.LoggingDriver}}'; jq -r '.\"log-opts\".\"max-size\", .\"log-opts\".\"max-file\"' /etc/docker/daemon.json" | tr '\n' ' ' | sed 's/ $//')"
  check "live-restore" "true" "$(remote docker info --format '{{.LiveRestoreEnabled}}')"
  check "hello-world" "0" "$(remote docker run --rm hello-world >/dev/null; echo $?)"
  local used; used="$(remote "df --output=pcent / | tail -1 | tr -dc 0-9")"
  check "dysk / zajęty < 80%" "tak" "$([ -n "${used}" ] && [ "${used}" -lt 80 ] && echo tak || echo "nie (${used:-?}%)")"
}

# ---------------------------------------------------------------- stack -----
# Komendy w /opt/marketplace: COMPOSE_FILE/COMPOSE_PROFILES są w .env serwera.
on_app() { remote "cd /opt/marketplace && $*"; }
es() { on_app 'set -a && . ./.env && set +a && curl -fsS -u "elastic:${ELASTIC_PASSWORD}" "http://localhost:${ES_PORT}'"$1"'"'; }

phase_stack() {
  echo -e "\n${BLD}  stack — ${HOST}:/opt/marketplace${NC}"
  check "brak kodu na serwerze (.git, apps/)" "brak" "$(on_app 'test -e .git -o -e apps && echo JEST || echo brak')"
  local tag; tag="$(on_app "grep '^IMAGE_TAG=' .env | cut -d= -f2")"
  check "obrazy własne = ghcr.io/dommmin/elastic-*:${tag:0:12}" "0" \
    "$(on_app "docker compose images --format json" | python3 -c '
import json, sys
tag = sys.argv[1]
raw = sys.stdin.read().strip()
rows = json.loads(raw) if raw.startswith("[") else [json.loads(l) for l in raw.splitlines() if l.strip()]
own = [r for r in rows if "elastic-" in r.get("Repository", "")]
bad = [r for r in own if not (r["Repository"].startswith("ghcr.io/dommmin/elastic-") and r["Tag"] == tag)]
print(len(bad) if own else "brak obrazów")' "${tag}")"

  local health; health="$(es /_cluster/health)"
  check "klaster ES: green" "green" "$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["status"])' "${health}" 2>/dev/null)"
  check "klaster ES: 3 nody" "3" "$(python3 -c 'import json,sys; print(json.loads(sys.argv[1])["number_of_nodes"])' "${health}" 2>/dev/null)"
  check "kontenery: zero unhealthy/restarting" "0" \
    "$(on_app "docker compose ps --format '{{.Service}} {{.Status}}'" | grep -cE 'unhealthy|Restarting')"
  local mem; mem="$(remote "free -m | awk '/Mem:/ {printf \"%d\", \$3*100/\$2}'")"
  check "RAM zajęty < 80%" "tak" "$([ -n "${mem}" ] && [ "${mem}" -lt 80 ] && echo tak || echo "nie (${mem:-?}%)")"

  # Z PLIKU, nie przez stdin (`bash -s < smoke-test.sh`): `docker compose
  # exec -T` w środku czyta stdin i połyka resztę skryptu (RUNBOOK #035).
  scp -q "${ROOT}/tools/smoke-test.sh" "${HOST}:/tmp/smoke-test.sh"
  local smoke; smoke="$(on_app 'set -a && . ./.env && set +a && bash /tmp/smoke-test.sh' 2>&1)"
  check "smoke-test.sh: wszystkie testy" "1" "$(grep -c 'Wszystkie testy przeszły' <<<"${smoke}")"

  local count; count="$(es /products-search/_count | python3 -c 'import json,sys; print(json.load(sys.stdin)["count"])' 2>/dev/null)"
  check "products-search: 1500 dokumentów" "1500" "${count}"
  local eval; eval="$(on_app 'docker compose exec -T catalog-app php artisan search:eval' 2>&1)"
  check "eval: zapytania o konkretny produkt = 1.000" "2" \
    "$(grep -cE '^\| (Wyman-Howell Laptop Ultra 14"|Bailey Ltd Smartfon Nova 128GB) +\| 1\.000' <<<"${eval}")"
  local mean; mean="$(grep -oE 'nDCG@10: [0-9.]+' <<<"${eval}" | awk '{print $2}')"
  check "eval: średnie nDCG@10 >= 0.80 (${mean:-?})" "tak" "$(python3 -c 'import sys; print("tak" if float(sys.argv[1]) >= 0.80 else "nie")' "${mean:-0}" 2>/dev/null)"

  # E2E ścieżką użytkownika (RUNBOOK #031): zmiana w catalog -> outbox ->
  # outbox-publisher -> RabbitMQ -> search-consumer -> ES. Cena losowa, żeby
  # nie trafić w wartość z poprzedniego przebiegu.
  local price=$(( (RANDOM % 90000) + 10000 )) pid t0 waited=""
  pid="$(on_app "docker compose exec -T catalog-app php artisan tinker --execute '\$o = App\\Models\\Offer::query()->orderBy(\"id\")->first(); \$o->updateWithOutbox([\"price_cents\" => ${price}]); echo \$o->product_id;'" 2>/dev/null | grep -oE '[0-9]+$' | tail -1)"
  t0=$(date +%s)
  while [ $(( $(date +%s) - t0 )) -le 10 ]; do
    if es "/products-search/_doc/${pid:-0}" 2>/dev/null | grep -q "${price}"; then waited=$(( $(date +%s) - t0 )); break; fi
    sleep 1
  done
  check "E2E: zmiana ceny w ES w <= 10 s (${waited:-timeout} s)" "tak" "$([ -n "${waited}" ] && echo tak || echo nie)"
}

# --------------------------------------------------------------- backup -----
eval_mean() { on_app 'docker compose exec -T catalog-app php artisan search:eval </dev/null' 2>/dev/null | grep -oE 'nDCG@10: [0-9.]+' | awk '{print $2}'; }

phase_backup() {
  echo -e "\n${BLD}  backup — ${HOST}${NC}"
  check "ES: repozytorium fs-backup" "fs" "$(es /_snapshot/fs-backup | python3 -c 'import json,sys; print(json.load(sys.stdin)["fs-backup"]["type"])' 2>/dev/null)"
  local slm; slm="$(es /_slm/policy/nightly 2>/dev/null)"
  check "ES: polityka SLM nightly" "nightly" "$(python3 -c 'import json,sys; print(list(json.loads(sys.argv[1]))[0])' "${slm}" 2>/dev/null)"
  check "ES: ostatni snapshot SLM udany i < 26 h" "tak" "$(python3 -c '
import json, sys, time
p = json.loads(sys.argv[1])["nightly"]
ok = p.get("last_success", {}).get("time", 0) / 1000
bad = p.get("last_failure", {}).get("time", 0) / 1000
print("tak" if ok and ok > bad and time.time() - ok < 26 * 3600 else "nie")' "${slm}" 2>/dev/null)"
  check "PG: timer marketplace-pg-backup aktywny" "active" "$(remote systemctl is-active marketplace-pg-backup.timer)"
  check "PG: dumpy obu baz < 26 h" "2" "$(remote "find /var/backups/marketplace -name '*.dump' -mmin -1560 -size +1k | sed -E 's/-[0-9]{4}-.*//' | sort -u | wc -l" | tr -d ' ')"
}

# --------------------------------------------------------------- reboot -----
# Najważniejszy test odporności: wyłączenie prądu w środku nocy. Po starcie
# nikt nie wpisze `docker compose up` — wszystko musi wstać samo
# (restart: unless-stopped), a klaster ES złożyć się z 3 nodów (RUNBOOK #021).
phase_reboot() {
  echo -e "\n${BLD}  reboot — ${HOST} (restart serwera!)${NC}"
  local before after t0 up=""
  before="$(eval_mean)"
  echo -e "  ${DIM}eval przed restartem: ${before:-?}${NC}"
  remote 'sudo -n systemctl reboot' || true
  sleep 20
  t0=$(date +%s)
  while [ $(( $(date +%s) - t0 )) -le 300 ]; do
    if [ "$(es /_cluster/health 2>/dev/null | python3 -c 'import json,sys; d=json.load(sys.stdin); print(d["status"], d["number_of_nodes"])' 2>/dev/null)" = "green 3" ] \
       && [ "$(on_app "docker compose ps --format '{{.Status}}'" 2>/dev/null | grep -cvE '\(healthy\)')" = "0" ]; then
      up=$(( $(date +%s) - t0 + 20 )); break
    fi
    sleep 5
  done
  check "po restarcie: ES green, 3 nody, wszystko healthy w <= 5 min (${up:-timeout} s)" "tak" "$([ -n "${up}" ] && echo tak || echo nie)"
  check "uptime serwera < 10 min (restart naprawdę był)" "tak" "$(remote "awk '{print (\$1<600)?\"tak\":\"nie\"}' /proc/uptime")"
  after="$(eval_mean)"
  check "eval identyczny jak przed restartem (${before:-?} -> ${after:-?})" "${before:-brak}" "${after:-brak}"
  scp -q "${ROOT}/tools/smoke-test.sh" "${HOST}:/tmp/smoke-test.sh"
  check "smoke po restarcie" "1" "$(on_app 'set -a && . ./.env && set +a && bash /tmp/smoke-test.sh' 2>&1 | grep -c 'Wszystkie testy przeszły')"
}

case "${1:-}" in
  images) phase_images ;;
  access)    phase_access ;;
  hardening) phase_hardening ;;
  exposure)  phase_exposure ;;
  docker)    phase_docker ;;
  stack)     phase_stack ;;
  backup)    phase_backup ;;
  reboot)    phase_reboot ;;
  all)       phase_images; phase_access; phase_hardening; phase_exposure; phase_docker; phase_stack; phase_backup ;;
  *) echo "użycie: $0 {images|access|hardening|exposure|docker|stack|backup|reboot|all}" >&2; exit 2 ;;
esac

echo -e "\n  PASS=${PASS} FAIL=${FAIL}\n"
[ "${FAIL}" -eq 0 ]
