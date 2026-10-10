#!/usr/bin/env bash
# ============================================================================
#  tools/k3s/verify.sh <faza> — weryfikacja ETAPU D2 (k3s), z MACA
#
#  Fazy odpowiadają taskom z docs/09-PLAN-ETAP-D2-K3S.md; każda pisana PRZED
#  taskiem (czerwona), po nim zielona. Ten sam styl co tools/vps/verify.sh.
#
#    cluster    k3s Ready, wersja, bez Traefika/ServiceLB, API przez tunel,
#               kubectl z Maca przez tunel, DNS i Service z poda   (Task 1)
#    exposure   z internetu tylko 22/tcp — także porty k8s         (Task 1+)
#    eck        operator ECK 3.5.0 działa, CRD zarejestrowane        (Task 2)
#    all        wszystkie fazy (bez reboot)
#
#  kubectl z Maca: tunel SSH elastic-vps-k8s (26443 -> 127.0.0.1:6443),
#  kubeconfig ~/.kube/elastic-vps.yaml. Tunel startuje sam, jeśli go nie ma.
# ============================================================================
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
HOST="${VPS_HOST:-elastic-vps}"
K3S_VERSION="v1.36.5+k3s1"
# Jawna ścieżka: w PATH wygrywa stary kubectl 1.33 z /usr/local/bin,
# a skew kubectl<->serwer to maks. ±1 wersja minor (serwer: 1.36).
KUBECTL_BIN="${KUBECTL:-/opt/homebrew/bin/kubectl}"
KUBECONFIG_VPS="${KUBECONFIG_VPS:-$HOME/.kube/elastic-vps.yaml}"
RED='\033[0;31m'; GRN='\033[0;32m'; BLD='\033[1m'; DIM='\033[2m'; NC='\033[0m'
PASS=0; FAIL=0

check() { # check <opis> <oczekiwane> <otrzymane>
  if [ "$2" = "$3" ]; then
    echo -e "  ${GRN}✓${NC} $1"; PASS=$((PASS+1))
  else
    echo -e "  ${RED}✗${NC} $1  ${DIM}(oczekiwano: $2, otrzymano: $3)${NC}"; FAIL=$((FAIL+1))
  fi
}
remote() { ssh -o BatchMode=yes -o ConnectTimeout=10 "${HOST}" "$@" 2>/dev/null; }
server_ip() { ssh -G "${HOST}" | awk '/^hostname / {print $2}'; }

tunnel_up() {
  nc -z 127.0.0.1 26443 2>/dev/null && return 0
  ssh -o BatchMode=yes -o ExitOnForwardFailure=yes -fN elastic-vps-k8s 2>/dev/null
  for _ in 1 2 3 4 5; do nc -z 127.0.0.1 26443 2>/dev/null && return 0; sleep 1; done
  return 1
}
k() { "${KUBECTL_BIN}" --kubeconfig "${KUBECONFIG_VPS}" --request-timeout=15s "$@" 2>/dev/null; }

# -------------------------------------------------------------- cluster -----
phase_cluster() {
  echo -e "\n${BLD}  cluster — k3s na ${HOST}${NC}"
  check "k3s zainstalowany: ${K3S_VERSION}" "${K3S_VERSION}" "$(remote 'k3s --version 2>/dev/null | head -1 | awk "{print \$3}"')"
  # API MUSI słuchać na interfejsie węzła, nie tylko na 127.0.0.1: pody łączą
  # się z nim przez Service 10.43.0.1, DNAT-owany na adres węzła — "127.0.0.1"
  # z wnętrza poda to loopback poda (RUNBOOK #036). Z internetu 6443 zamyka
  # ufw — sprawdza to faza exposure (skan z zewnątrz).
  check "API k3s słucha (6443)" "1" "$(remote "sudo -n ss -tlnH 'sport = :6443' | wc -l | awk '{print (\$1>0)?1:0}'")"
  check "tunel SSH do API (localhost:26443)" "0" "$(tunnel_up; echo $?)"
  check "kubectl z Maca: node Ready" "True" \
    "$(k get nodes -o jsonpath='{.items[0].status.conditions[?(@.type=="Ready")].status}')"
  # "brak API" zamiast 0: bez klastra `grep -c` zwraca 0 i test byłby
  # fałszywie zielony (tak było w pierwszej wersji).
  # (grep -c przy 0 trafień kończy się kodem 1 — stąd `|| true` w środku)
  check "Traefik wyłączony" "0" "$(if k get deploy -n kube-system -o name > /tmp/k3s-deploy.$$; then grep -c traefik /tmp/k3s-deploy.$$ || true; else echo 'brak API'; fi)"
  check "ServiceLB wyłączony (brak svclb-*)" "0" "$(if k get ds -n kube-system -o name > /tmp/k3s-ds.$$; then grep -c svclb /tmp/k3s-ds.$$ || true; else echo 'brak API'; fi)"
  rm -f /tmp/k3s-deploy.$$ /tmp/k3s-ds.$$
  check "domyślna StorageClass: local-path" "local-path" \
    "$(k get sc -o jsonpath='{.items[?(@.metadata.annotations.storageclass\.kubernetes\.io/is-default-class=="true")].metadata.name}')"
  # Pod -> DNS -> Service: przy ufw `default deny` bez zezwolenia dla sieci
  # podów (10.42/16) i usług (10.43/16) to potrafi cicho nie działać.
  local out
  out="$(k run verify-net --rm -i --restart=Never --image=busybox:1.37 --command -- \
        sh -c 'nslookup kubernetes.default.svc.cluster.local >/dev/null && echo DNS_OK; wget -q -T 5 --no-check-certificate -O /dev/null https://kubernetes.default.svc 2>&1; echo HTTP_RC=$?' 2>&1)"
  # `kubectl run -i` potrafi wypisać wyjście 2x (attach + logi) — liczy się "jest".
  check "pod: DNS (kubernetes.default)" "1" "$(grep -q DNS_OK <<<"${out}" && echo 1 || echo 0)"
  # 401/403 bez tokenu to OK — liczy się, że połączenie do Service'u doszło.
  check "pod: połączenie z Service'em API" "1" "$(grep -cE 'HTTP_RC=0|401|403|Forbidden|Unauthorized' <<<"${out}" | awk '{print ($1>0)?1:0}')"
}

# ------------------------------------------------------------- exposure -----
# Jak w D1: skan Z ZEWNĄTRZ. k3s (jak Docker) pisze własne reguły iptables —
# NodePort albo hostPort ominąłby ufw.
phase_exposure() {
  local ip; ip="$(server_ip)"
  echo -e "\n${BLD}  exposure — ${ip} (z zewnątrz)${NC}"
  check "22/tcp otwarty" "otwarty" "$(nc -z -G 3 "${ip}" 22 >/dev/null 2>&1 && echo otwarty || echo zamknięty)"
  for port in 80 443 2379 5432 5601 6443 8080 9200 10250 15672 30000 30080 32767; do
    check "${port}/tcp zamknięty" "zamknięty" "$(nc -z -G 3 "${ip}" "${port}" >/dev/null 2>&1 && echo OTWARTY || echo zamknięty)"
  done
}

# ------------------------------------------------------------------ eck -----
phase_eck() {
  tunnel_up
  echo -e "\n${BLD}  eck — operator Elastica${NC}"
  check "CRD elasticsearches.elasticsearch.k8s.elastic.co" "1" \
    "$(k get crd elasticsearches.elasticsearch.k8s.elastic.co -o name | wc -l | tr -d ' ')"
  check "CRD kibanas.kibana.k8s.elastic.co" "1" "$(k get crd kibanas.kibana.k8s.elastic.co -o name | wc -l | tr -d ' ')"
  check "operator: elastic-operator-0 Running" "Running" "$(k get pod elastic-operator-0 -n elastic-system -o jsonpath='{.status.phase}')"
  check "operator: wersja 3.5.0" "docker.elastic.co/eck/eck-operator:3.5.0" \
    "$(k get sts elastic-operator -n elastic-system -o jsonpath='{.spec.template.spec.containers[0].image}')"
  check "operator: zero restartów" "0" "$(k get pod elastic-operator-0 -n elastic-system -o jsonpath='{.status.containerStatuses[0].restartCount}')"
}

case "${1:-}" in
  cluster)  phase_cluster ;;
  exposure) phase_exposure ;;
  eck)      phase_eck ;;
  all)      phase_cluster; phase_exposure; phase_eck ;;
  *) echo "użycie: $0 {cluster|exposure|eck|all}" >&2; exit 2 ;;
esac

echo -e "\n  PASS=${PASS} FAIL=${FAIL}\n"
[ "${FAIL}" -eq 0 ]
