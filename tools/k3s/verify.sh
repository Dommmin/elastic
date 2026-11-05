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
#    config     Secrety i ConfigMap w namespace marketplace          (Task 3)
#    es         ECK: ES green 3 nody, pluginy, role aplikacji, Kibana  (Task 4)
#    data       Postgres, RabbitMQ, Redis: gotowe, hasła, trwałość PVC (Task 5)
#    apps       Deploymenty, Job migracji, /up + X-App-Version, indeks (Task 6)
#    stack      parytet z D1: dane = Compose, eval, E2E zmiana ceny    (Task 7)
#    backup     repo + SLM w ECK, snapshot świeży, CronJob pg-backup   (Task 8)
#    monitoring kube-prometheus-stack: cele, reguły, ścieżka alertu (G1)
#    hardening  uid nie-root, read-only FS, brak tokena, PSA, RBAC (G2)
#    netpol     NetworkPolicy na żywych podach: trasy OPEN/BLOCKED  (G2)
#    ingress    Traefik + cert-manager, *.marketplace.test:8443    (G2)
#    hpa        obciążenie przez Ingress -> HPA dodaje repliki      (G2)
#    canary     drugi Deployment za Service, podział ruchu         (G2)
#    g2         hardening + netpol + ingress + hpa + canary
#    reboot     RESTARTUJE serwer: k3s i cały namespace wstają same     (Task 9)
#               — tylko jawnie, NIE wchodzi w `all`
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
# pf_start zostawiał port-forward na 38080 po skończeniu skryptu, a to ten sam
# port co `make k3s-ui` ("address already in use"). Sprzątamy przy wyjściu.
# Wzorzec to kolejność argumentów z pf_start ("port-forward -n ..."), więc
# forward z działającego `make k3s-ui` ("-n ... port-forward") zostaje.
trap 'pkill -f "port-forward -n marketplace svc/" 2>/dev/null || true' EXIT

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
  # ConnectTimeout: w trakcie bootu serwera TCP już przyjmuje połączenie, a sshd
  # jeszcze nie — bez limitu `ssh -f` wisiał minutami i blokował pętlę czekania.
  ssh -o BatchMode=yes -o ConnectTimeout=10 -o ExitOnForwardFailure=yes -fN elastic-vps-k8s 2>/dev/null
  for _ in 1 2 3 4 5; do nc -z 127.0.0.1 26443 2>/dev/null && return 0; sleep 1; done
  return 1
}
# Tunel potrafi paść w trakcie długiej weryfikacji (np. pod obciążeniem
# serwera) — wtedy każde `kubectl` zwraca pusto, a test wygląda na błąd
# danych. Przed każdym wywołaniem: jeśli portu nie ma, wznów tunel.
# Przy błędzie: świeży tunel i jedna ponowna próba. Bez tego przejściowy
# "TLS handshake timeout" (4 vCPU pod obciążeniem 6 JVM-ów) dawał pusty
# wynik, który wyglądał jak błąd danych.
# macOS nie ma `timeout`. `kubectl exec` przez tunel potrafił zawisnąć na
# ZESTAWIANIU strumienia (zanim komenda trafiła do poda) — `timeout` w podzie
# i --request-timeout tego nie łapią. Limit po stronie Maca: zabij po N s.
with_timeout() { # with_timeout <sekundy> <komenda...>
  local secs="$1"; shift
  "$@" & local pid=$!
  # Strażnik MUSI pisać do /dev/null: inaczej dziedziczy stdout podstawienia
  # $(…), a bash czeka z $(…), aż WSZYSCY piszący zamkną to wyjście — każde
  # wywołanie trwało wtedy pełne ${secs} sekund (sleep żył dalej).
  ( sleep "${secs}"; kill -9 "${pid}" 2>/dev/null ) >/dev/null 2>&1 & local watchdog=$!
  wait "${pid}" 2>/dev/null; local rc=$?
  kill "${watchdog}" 2>/dev/null; wait "${watchdog}" 2>/dev/null
  return "${rc}"
}
k() {
  local out rc attempt
  for attempt in 1 2; do
    nc -z 127.0.0.1 26443 2>/dev/null || tunnel_up
    out="$(with_timeout 120 "${KUBECTL_BIN}" --kubeconfig "${KUBECONFIG_VPS}" --request-timeout=20s "$@" 2>/dev/null)"; rc=$?
    [ "${rc}" -eq 0 ] && break
    [ "${attempt}" -eq 1 ] && { pkill -f "ssh .*-fN elastic-vps-k8s" 2>/dev/null; sleep 1; tunnel_up; }
  done
  [ -n "${out}" ] && printf '%s\n' "${out}"
  return "${rc}"
}

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
  # Bez `kubectl run -i --rm`: w tle (with_timeout) nie ma terminala do
  # podłączenia, więc -i wisiało do limitu i było ponawiane (~10 min fazy).
  # Utwórz -> poczekaj na koniec -> logi -> usuń.
  local out
  k delete pod verify-net -n default --ignore-not-found --wait=true >/dev/null
  k run verify-net -n default --restart=Never --image=busybox:1.37 --command -- \
    sh -c 'nslookup kubernetes.default.svc.cluster.local >/dev/null && echo DNS_OK; wget -q -T 5 --no-check-certificate -O /dev/null https://kubernetes.default.svc 2>&1; echo HTTP_RC=$?' >/dev/null
  k wait pod/verify-net -n default --for=jsonpath='{.status.phase}'=Succeeded --timeout=90s >/dev/null
  out="$(k logs verify-net -n default)"
  k delete pod verify-net -n default --wait=false >/dev/null
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
  # 9100 = node-exporter (ETAP G1): sieć hosta, zamknięty tylko przez ufw.
  for port in 80 443 2379 5432 5601 6443 8080 9100 9187 9200 10250 15672 15692 30000 30080 32767; do
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
  # Restarty same w sobie nie są błędem (restart serwera = restart poda);
  # błędem jest pętla restartów albo brak gotowości.
  check "operator: Ready, bez CrashLoopBackOff" "True" \
    "$(k get pod elastic-operator-0 -n elastic-system -o jsonpath='{.status.conditions[?(@.type=="Ready")].status}{.status.containerStatuses[0].state.waiting.reason}')"
}

# --------------------------------------------------------------- config -----
# Sprawdzamy KLUCZE, nigdy wartości — sekrety nie lądują w terminalu.
secret_keys() { k get secret "$1" -n marketplace -o go-template='{{range $k, $v := .data}}{{$k}} {{end}}' | tr ' ' '\n' | grep -v '^$' | sort | tr '\n' ' ' | sed 's/ $//'; }
phase_config() {
  tunnel_up
  echo -e "\n${BLD}  config — Secrety i ConfigMap${NC}"
  check "Secret marketplace-secrets: 11 kluczy" \
    "CATALOG_APP_KEY CATALOG_DB_PASSWORD DATABASE_URL ES_CATALOG_PASSWORD ES_SEARCHSVC_PASSWORD MESSENGER_TRANSPORT_DSN POSTGRES_PASSWORD RABBITMQ_PASSWORD REDIS_PASSWORD SEARCHSVC_DB_PASSWORD SEARCH_APP_SECRET" \
    "$(secret_keys marketplace-secrets)"
  check "Secret marketplace-es-elastic-user: elastic" "elastic" "$(secret_keys marketplace-es-elastic-user)"
  check "Secret es-user-catalog: basic-auth + roles" "password roles username" "$(secret_keys es-user-catalog)"
  check "Secret es-user-searchsvc: basic-auth + roles" "password roles username" "$(secret_keys es-user-searchsvc)"
  check "Secret es-app-roles: roles.yml" "roles.yml" "$(secret_keys es-app-roles)"
  check "ConfigMap marketplace-config-<hash>" "1" "$(k get cm -n marketplace -o name | grep -c 'marketplace-config-')"
  check "kustomize build bez błędów" "0" "$("${KUBECTL_BIN}" kustomize "${ROOT}/deploy/k8s" >/dev/null 2>&1; echo $?)"
  # Hasło `elastic` w k8s = to z .env serwera (K-7): porównanie skrótów
  # SHA-256 po stronie serwera — wartość nie opuszcza serwera.
  check "hasło elastic = to samo co w D1" "tak" "$(remote 'cd /opt/marketplace && a=$(grep ^ELASTIC_PASSWORD= .env | cut -d= -f2- | tr -d "\\n" | sha256sum); b=$(kubectl -n marketplace get secret marketplace-es-elastic-user -o jsonpath={.data.elastic} | base64 -d | sha256sum); [ "$a" = "$b" ] && echo tak || echo nie')"
}

# ------------------------------------------------------------------- es -----
# Zapytania do ES w k8s wykonujemy NA SERWERZE, przez ClusterIP Service'u
# (host widzi sieć usług przez kube-proxy). Hasła czytane z .env na miejscu.
es_k8s() { # es_k8s <user: elastic|catalog|searchsvc> <ścieżka> [metoda] [body]
  remote "cd /opt/marketplace && set -a && . ./.env && set +a && \
    case '$1' in elastic) P=\$ELASTIC_PASSWORD ;; catalog) P=\$ES_CATALOG_PASSWORD ;; searchsvc) P=\$ES_SEARCHSVC_PASSWORD ;; esac; \
    IP=\$(kubectl -n marketplace get svc marketplace-es-http -o jsonpath='{.spec.clusterIP}'); \
    curl -s -m 30 -u '$1':\"\$P\" -X ${3:-GET} -H 'Content-Type: application/json' \"http://\$IP:9200$2\" ${4:+-d '$4'}"
}
phase_es() {
  tunnel_up
  echo -e "\n${BLD}  es — Elasticsearch i Kibana przez ECK${NC}"
  check "Elasticsearch: health green" "green" "$(k get elasticsearch marketplace -n marketplace -o jsonpath='{.status.health}')"
  check "Elasticsearch: 3 nody dostępne" "3" "$(k get elasticsearch marketplace -n marketplace -o jsonpath='{.status.availableNodes}')"
  check "Elasticsearch: wersja 9.5.1" "9.5.1" "$(k get elasticsearch marketplace -n marketplace -o jsonpath='{.status.version}')"
  # Tag WDROŻONY (z Deploymentu catalog-app), nie ten z repo: deploy.sh
  # podmienia tag w kopii manifestów, a w repo zostaje wersja referencyjna.
  local want; want="$(k get deploy catalog-app -n marketplace -o jsonpath='{.spec.template.spec.containers[0].image}' | sed 's/.*://')"
  check "pody ES na obrazie z GHCR (ten sam tag co aplikacje)" "3" \
    "$(k get pods -n marketplace -l elasticsearch.k8s.elastic.co/cluster-name=marketplace -o jsonpath='{range .items[*]}{.spec.containers[0].image}{"\n"}{end}' | grep -c "elastic-elasticsearch:${want}")"
  local plugins; plugins="$(es_k8s elastic '/_cat/plugins?h=component')"
  check "plugin analysis-stempel na 3 nodach" "3" "$(grep -c analysis-stempel <<<"${plugins}")"
  check "plugin analysis-icu na 3 nodach" "3" "$(grep -c analysis-icu <<<"${plugins}")"
  check "analizator polski: butów -> but" "but" \
    "$(es_k8s elastic '/_analyze' POST '{"analyzer":"polish","text":"butów"}' | python3 -c 'import json,sys; print(json.load(sys.stdin)["tokens"][0]["token"])' 2>/dev/null)"
  check "hasło elastic z D1 działa" "elastic" "$(es_k8s elastic '/_security/_authenticate' | python3 -c 'import json,sys; print(json.load(sys.stdin)["username"])' 2>/dev/null)"
  check "user catalog: rola catalog_app (file realm)" "catalog_app" \
    "$(es_k8s catalog '/_security/_authenticate' | python3 -c 'import json,sys; print(",".join(json.load(sys.stdin)["roles"]))' 2>/dev/null)"
  # D-03: Laravel nie pisze do ES — próba utworzenia indeksu MUSI dostać 403.
  check "user catalog: zapis zabroniony (403)" "403" \
    "$(es_k8s catalog '/products-zakaz-test' PUT | python3 -c 'import json,sys; print(json.load(sys.stdin).get("status"))' 2>/dev/null)"
  check "user searchsvc: rola search_service" "search_service" \
    "$(es_k8s searchsvc '/_security/_authenticate' | python3 -c 'import json,sys; print(",".join(json.load(sys.stdin)["roles"]))' 2>/dev/null)"
  check "repozytorium snapshotów: /snapshots zapisywalne (verify)" "3" \
    "$(es_k8s elastic '/_snapshot/verify-tmp' PUT '{"type":"fs","settings":{"location":"/snapshots/verify-tmp"}}' >/dev/null; es_k8s elastic '/_snapshot/verify-tmp/_verify' POST | python3 -c 'import json,sys; print(len(json.load(sys.stdin)["nodes"]))' 2>/dev/null; es_k8s elastic '/_snapshot/verify-tmp' DELETE >/dev/null)"
  check "Kibana: health green" "green" "$(k get kibana marketplace -n marketplace -o jsonpath='{.status.health}')"
}

# ----------------------------------------------------------------- data -----
# Hasła czytane w kontenerze z jego env. `timeout 60` W PODZIE: `--request-timeout`
# nie obejmuje strumieniowego `exec`, a np. `rabbitmqctl` zaraz po restarcie
# node'a potrafił wisieć 15+ minut i blokował całą weryfikację.
kx() { k exec -n marketplace "$1" -- timeout 60 sh -c "$2"; }
phase_data() {
  tunnel_up
  echo -e "\n${BLD}  data — StatefulSety${NC}"
  for sts in postgres rabbitmq redis; do
    check "StatefulSet ${sts}: 1/1 gotowy" "1" "$(k get sts "${sts}" -n marketplace -o jsonpath='{.status.readyReplicas}')"
    check "PVC data-${sts}-0: Bound" "Bound" "$(k get pvc "data-${sts}-0" -n marketplace -o jsonpath='{.status.phase}')"
  done
  check "postgres: bazy catalog i searchsvc (skrypt init z obrazu)" "catalog searchsvc" \
    "$(kx postgres-0 'psql -U "$POSTGRES_USER" -tAc "select datname from pg_database where datname in ('"'"'catalog'"'"','"'"'searchsvc'"'"') order by 1"' | tr '\n' ' ' | sed 's/ $//')"
  check "postgres: catalog loguje się swoim hasłem" "1" \
    "$(kx postgres-0 'PGPASSWORD="$CATALOG_DB_PASSWORD" psql -h 127.0.0.1 -U "$CATALOG_DB_USER" -d "$CATALOG_DB" -tAc "select 1"')"
  check "rabbitmq: logowanie hasłem z Secretu" "0" \
    "$(kx rabbitmq-0 'rabbitmqctl authenticate_user "$RABBITMQ_USER" "$RABBITMQ_PASSWORD" >/dev/null 2>&1; echo $?')"
  check "rabbitmq: kolejka search.product.sync (topologia z obrazu)" "1" \
    "$(kx rabbitmq-0 'rabbitmqctl list_queues name -q 2>/dev/null' | grep -c '^search.product.sync$')"
  check "rabbitmq: stała nazwa node'a" "rabbit@rabbitmq-0" "$(kx rabbitmq-0 "rabbitmqctl -q eval 'node().' 2>/dev/null" | tr -d \"\'\ )"
  check "redis: PING z hasłem" "PONG" "$(kx redis-0 'redis-cli -a "$REDIS_PASSWORD" --no-auth-warning ping')"

  # Trwałość: zapis -> usunięcie POD-a -> StatefulSet tworzy go od nowa z TYM
  # SAMYM PVC -> zapis nadal jest. W compose odpowiednik: `docker compose rm`
  # + `up` (nazwany wolumen przeżywa), tu kontroler robi to sam.
  local marker="verify-$(date +%s)"
  kx postgres-0 "psql -U \"\$POSTGRES_USER\" -d postgres -qc \"create table if not exists _verify(v text); insert into _verify values ('${marker}')\"" >/dev/null
  k delete pod postgres-0 -n marketplace --wait=true >/dev/null
  k wait pod/postgres-0 -n marketplace --for=condition=Ready --timeout=180s >/dev/null
  check "postgres: dane przeżyły usunięcie poda" "${marker}" \
    "$(kx postgres-0 "psql -U \"\$POSTGRES_USER\" -d postgres -tAc \"select v from _verify where v='${marker}'\"")"
  kx postgres-0 'psql -U "$POSTGRES_USER" -d postgres -qc "drop table _verify"' >/dev/null

  # Skutek uboczny tego testu = prawdziwa mini-awaria: headless Service bez
  # gotowego poda nie ma rekordu DNS, więc aplikacje dostają "could not
  # translate host name postgres", padają i są restartowane przez kubelet
  # (z narastającym back-offem). Mierzymy, po ilu sekundach wszystko wraca.
  # Liczba aplikacji z klastra, nie stała: po ETAPIE F jest ich 5 (kiedyś 3), a zahardkodowane
  # "3" kończyło oczekiwanie, gdy 2 z 5 jeszcze leżały w back-offie (RUNBOOK #079).
  local t0 back="" want; t0=$(date +%s)
  want="$(k get deploy -n marketplace -l app.kubernetes.io/component=app --no-headers | wc -l | tr -d ' ')"
  while [ $(( $(date +%s) - t0 )) -le 300 ]; do
    if [ "$(k get deploy -n marketplace -l app.kubernetes.io/component=app -o jsonpath='{range .items[*]}{.status.conditions[?(@.type=="Available")].status}{"\n"}{end}' | grep -c True)" = "${want}" ] && [ "${want}" -ge 1 ]; then
      back=$(( $(date +%s) - t0 )); break
    fi
    sleep 5
  done
  check "aplikacje same wróciły po restarcie bazy (${back:-timeout} s)" "tak" "$([ -n "${back}" ] && echo tak || echo nie)"
}

# ----------------------------------------------------------------- apps -----
# Dostęp do catalog-app z Maca: `kubectl port-forward` przez tunel do API —
# k8s-owy odpowiednik `LocalForward` z D1, bez dotykania serwera.
pf_start() { # pf_start <svc> <port-lokalny> <port-svc>
  nc -z 127.0.0.1 "$2" 2>/dev/null && return 0
  ( "${KUBECTL_BIN}" --kubeconfig "${KUBECONFIG_VPS}" port-forward -n marketplace "svc/$1" "$2:$3" >/dev/null 2>&1 & )
  for _ in $(seq 1 15); do nc -z 127.0.0.1 "$2" 2>/dev/null && return 0; sleep 1; done
  return 1
}
phase_apps() {
  tunnel_up
  echo -e "\n${BLD}  apps — aplikacje w k8s${NC}"
  local tag; tag="$(k get deploy catalog-app -n marketplace -o jsonpath='{.spec.template.spec.containers[0].image}' | sed 's/.*://')"
  for d in catalog-app outbox-publisher catalog-worker catalog-scheduler search-consumer; do
    check "Deployment ${d}: Available" "True" \
      "$(k get deploy "${d}" -n marketplace -o jsonpath='{.status.conditions[?(@.type=="Available")].status}')"
  done
  check "Job migrate: Complete" "True" "$(k get job migrate -n marketplace -o jsonpath='{.status.conditions[?(@.type=="Complete")].status}')"
  check "wszystkie aplikacje na tym samym tagu" "1" \
    "$(k get deploy -n marketplace -l app.kubernetes.io/component=app -o jsonpath='{range .items[*]}{.spec.template.spec.containers[0].image}{"\n"}{end}' | sed 's/.*://' | sort -u | wc -l | tr -d ' ')"
  check "port-forward svc/catalog-app -> localhost:38080" "0" "$(pf_start catalog-app 38080 80; echo $?)"
  check "GET /up: 200" "200" "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:38080/up)"
  check "X-App-Version = tag wdrożenia" "${tag}" "$(curl -sI http://127.0.0.1:38080/up | awk -F': ' 'tolower($1)=="x-app-version" {print $2}' | tr -d '\r')"
  check "alias products-search istnieje (indeks z Joba)" "200" \
    "$(es_k8s elastic '/_alias/products-search' >/dev/null; remote "cd /opt/marketplace && set -a && . ./.env && set +a && IP=\$(kubectl -n marketplace get svc marketplace-es-http -o jsonpath='{.spec.clusterIP}') && curl -s -o /dev/null -w '%{http_code}' -u elastic:\"\$ELASTIC_PASSWORD\" http://\$IP:9200/_alias/products-search")"
  check "search-consumer: Ready, bez CrashLoopBackOff" "True" \
    "$(k get pods -n marketplace -l app=search-consumer -o jsonpath='{.items[0].status.conditions[?(@.type=="Ready")].status}{.items[0].status.containerStatuses[0].state.waiting.reason}')"
}

# ---------------------------------------------------------------- stack -----
eval_k8s()     { k exec -n marketplace deploy/catalog-app -- php artisan search:eval 2>/dev/null | grep -oE 'nDCG@10: [0-9.]+' | awk '{print $2}'; }
eval_compose() { remote 'cd /opt/marketplace && docker compose exec -T catalog-app php artisan search:eval </dev/null 2>/dev/null' | grep -oE 'nDCG@10: [0-9.]+' | awk '{print $2}'; }
# concat_ws(chr(32), …) zamiast ' ' — bez apostrofów, które rozjeżdżały się
# w trzech warstwach cytowania (Mac -> ssh/kubectl -> sh -c -> psql).
PG_COUNTS_SQL='select concat_ws(chr(32), (select count(*) from products), (select count(*) from offers), (select count(*) from brands), (select count(*) from sellers))'
pg_counts_k8s()     { kx postgres-0 "psql -U \"\$POSTGRES_USER\" -d catalog -tAc \"${PG_COUNTS_SQL}\""; }
pg_counts_compose() { remote "cd /opt/marketplace && docker compose exec -T postgres psql -U postgres -d catalog -tAc \"${PG_COUNTS_SQL}\" </dev/null"; }
phase_stack() {
  tunnel_up
  echo -e "\n${BLD}  stack — parytet z D1 (Compose)${NC}"
  # Przed przełączeniem porównujemy z Compose; po przełączeniu (Compose
  # zatrzymany) — z wartościami z migracji: liczby nie mogą spaść.
  local compose_up; compose_up="$(remote 'docker ps -q --filter label=com.docker.compose.project=marketplace --filter label=com.docker.compose.service=postgres | wc -l' | tr -d ' ')"
  local c; [ "${compose_up}" = "1" ] && c="$(pg_counts_compose)"
  if [ -n "${c}" ]; then
    check "Postgres: products/offers/brands/sellers = Compose (${c})" "${c}" "$(pg_counts_k8s)"
  else
    check "Postgres: co najmniej 1500 4517 24 60 (Compose zatrzymany)" "tak" \
      "$(pg_counts_k8s | awk '{print ($1>=1500 && $2>=4517 && $3>=24 && $4>=60) ? "tak" : "nie: " $0}')"
  fi
  check "ES: products-search 1500 dokumentów" "1500" \
    "$(es_k8s elastic '/products-search/_count' | python3 -c 'import json,sys; print(json.load(sys.stdin)["count"])' 2>/dev/null)"
  local ek ec; ek="$(eval_k8s)"; [ "${compose_up}" = "1" ] && ec="$(eval_compose)"
  [ -n "${ec}" ] && check "eval k8s = eval Compose (${ec})" "${ec}" "${ek:-brak}"
  check "eval OK (>= 0.80)" "tak" "$(python3 -c 'import sys; print("tak" if float(sys.argv[1]) >= 0.80 else "nie")' "${ek:-0}" 2>/dev/null)"
  # E2E ścieżką użytkownika (RUNBOOK #031): catalog -> outbox -> publisher ->
  # RabbitMQ -> consumer -> ES. Wszystko w k8s. NIE zmienia danych: zapisuje produkt jego
  # własnymi wartościami (`updateWithOutbox` zawsze emituje zdarzenie), a o przejściu całej ścieżki
  # świadczy wzrost `_version` dokumentu w ES (zewnętrzna wersja = czas projekcji w mikrosekundach).
  # Wcześniej test zmieniał cenę przez Offer::updateWithOutbox, którego portal sprzedawcy już nie ma
  # (RUNBOOK #079), i zmieniał dane produkcyjne.
  local pid v0 v1 t0 waited=""
  pid="$(k exec -n marketplace deploy/catalog-app -- php artisan tinker --execute "\$p = App\\Models\\Product::query()->orderBy(\"id\")->first(); echo \$p->id;" 2>/dev/null | grep -oE '[0-9]+$' | tail -1)"
  es_version() { es_k8s elastic "/products-search/_doc/${pid:-0}" | python3 -c 'import sys,json; print(json.load(sys.stdin).get("_version",0))' 2>/dev/null; }
  v0="$(es_version)"
  k exec -n marketplace deploy/catalog-app -- php artisan tinker --execute "\$p = App\\Models\\Product::query()->findOrFail(${pid:-0}); \$p->updateWithOutbox(['name' => \$p->name], 'product.updated');" >/dev/null 2>&1
  t0=$(date +%s)
  while [ $(( $(date +%s) - t0 )) -le 10 ]; do
    v1="$(es_version)"
    if [ "${v1:-0}" -gt "${v0:-0}" ] 2>/dev/null; then waited=$(( $(date +%s) - t0 )); break; fi
    sleep 1
  done
  check "E2E: zdarzenie produktu dochodzi do ES k8s w <= 10 s (${waited:-timeout} s)" "tak" "$([ -n "${waited}" ] && echo tak || echo nie)"
}

# --------------------------------------------------------------- backup -----
phase_backup() {
  tunnel_up
  echo -e "\n${BLD}  backup — w Kubernetesie${NC}"
  check "ES k8s: repozytorium fs-backup" "/snapshots/fs-backup" \
    "$(es_k8s elastic '/_snapshot/fs-backup' | python3 -c 'import json,sys; print(json.load(sys.stdin)["fs-backup"]["settings"]["location"])' 2>/dev/null)"
  check "ES k8s: ostatni snapshot SLM udany i < 26 h" "tak" "$(es_k8s elastic '/_slm/policy/nightly' | python3 -c '
import json, sys, time
p = json.load(sys.stdin)["nightly"]
ok = p.get("last_success", {}).get("time", 0) / 1000
bad = p.get("last_failure", {}).get("time", 0) / 1000
print("tak" if ok and ok > bad and time.time() - ok < 26 * 3600 else "nie")' 2>/dev/null)"
  check "CronJob pg-backup: 30 3 * * * Europe/Warsaw" "30 3 * * * Europe/Warsaw" \
    "$(k get cronjob pg-backup -n marketplace -o jsonpath='{.spec.schedule} {.spec.timeZone}')"
  check "ostatni Job pg-backup: Complete" "True" \
    "$(k get jobs -n marketplace -l app=pg-backup --sort-by=.metadata.creationTimestamp -o jsonpath='{.items[-1:].status.conditions[?(@.type=="Complete")].status}' 2>/dev/null || k get jobs -n marketplace --sort-by=.metadata.creationTimestamp -o jsonpath='{range .items[*]}{.metadata.name} {.status.conditions[?(@.type=="Complete")].status}{"\n"}{end}' | awk '/pg-backup/ {s=$2} END {print s}')"
  check "dumpy obu baz < 26 h w /var/backups/marketplace-k8s" "2" \
    "$(remote "find /var/backups/marketplace-k8s -name '*.dump' -mmin -1560 -size +1k | sed -E 's|.*/||; s/-[0-9]{4}-.*//' | sort -u | wc -l" | tr -d ' ')"
}

# --------------------------------------------------------------- reboot -----
phase_reboot() {
  tunnel_up
  echo -e "\n${BLD}  reboot — ${HOST} (restart serwera!)${NC}"
  local before after t0 up=""
  before="$(eval_k8s)"
  echo -e "  ${DIM}eval przed restartem: ${before:-?}${NC}"
  remote 'sudo -n systemctl reboot' || true
  pkill -f "ssh .*-fN elastic-vps-k8s" 2>/dev/null
  sleep 30
  t0=$(date +%s)
  while [ $(( $(date +%s) - t0 )) -le 900 ]; do
    # NIE ufamy samemu .status.health z zasobu Elasticsearch: zaraz po
    # restarcie operator pokazuje OSTATNI ZNANY stan (green sprzed restartu),
    # podczas gdy pody ES dopiero startują (RUNBOOK #039). Liczą się pody
    # Ready + endpointy Service'u, przez który chodzą aplikacje.
    if [ "$(k get elasticsearch marketplace -n marketplace -o jsonpath='{.status.health}')" = "green" ] \
       && [ "$(k get pods -n marketplace -l elasticsearch.k8s.elastic.co/cluster-name=marketplace -o jsonpath='{range .items[*]}{.status.conditions[?(@.type=="Ready")].status}{"\n"}{end}' | grep -c True)" = "3" ] \
       && [ "$(k get deploy -n marketplace -o jsonpath='{range .items[*]}{.status.conditions[?(@.type=="Available")].status}{"\n"}{end}' | grep -c True)" = "4" ]; then
      up=$(( $(date +%s) - t0 + 30 )); break
    fi
    sleep 10
  done
  check "po restarcie: pody ES Ready 3/3, green, 4 Deploymenty Available (${up:-timeout} s)" "tak" "$([ -n "${up}" ] && echo tak || echo nie)"
  check "uptime serwera < 20 min (restart naprawdę był)" "tak" "$(remote "awk '{print (\$1<1200)?\"tak\":\"nie\"}' /proc/uptime")"
  check "Compose (D1) NIE wstał (stop przeżywa restart)" "0" "$(remote 'docker ps -q --filter label=com.docker.compose.project=marketplace | wc -l' | tr -d ' ')"
  after="$(eval_k8s)"
  check "eval identyczny jak przed restartem (${before:-?} -> ${after:-?})" "${before:-brak}" "${after:-brak}"
}

# ----------------------------------------------------------- monitoring -----
# ETAP G1. Zapytania przez proxy API Kubernetesa (`get --raw .../services/
# <svc>:<port>/proxy/...`): bez port-forwardu i bez exec — działa przez sam
# tunel do API. To samo, co robi `kubectl proxy`, tylko jednorazowo.
prom_q() { # prom_q <PromQL> -> surowy JSON odpowiedzi /api/v1/query
  k get --raw "/api/v1/namespaces/monitoring/services/kps-prometheus:9090/proxy/api/v1/query?query=$(jq -rn --arg q "$1" '$q|@uri')"
}
prom_val() { prom_q "$1" | jq -r '.data.result[0].value[1] // "brak"'; }
am_alerts() { # am_alerts <alertname> -> liczba aktywnych alertów w Alertmanagerze
  k get --raw "/api/v1/namespaces/monitoring/services/kps-alertmanager:9093/proxy/api/v2/alerts?filter=$(jq -rn --arg f "alertname=\"$1\"" '$f|@uri')" \
    | jq 'length'
}
phase_monitoring() {
  tunnel_up
  echo -e "\n${BLD}  monitoring — kube-prometheus-stack (ETAP G1)${NC}"
  check "pody monitoring: wszystkie Ready" "0" \
    "$(k get pods -n monitoring -o json | jq '[.items[] | select(.status.phase!="Succeeded") | select(any(.status.conditions[]?; .type=="Ready" and .status!="True"))] | length')"
  # Cele z ETAPU 11 + eksporter Postgresa: każdy job ma >=1 cel i wszystkie up.
  for job in caddy catalog rabbitmq rabbitmq-queues search glitchtip postgres; do
    check "cel job=${job}: up (min po celach = 1)" "1" "$(prom_val "min(up{job=\"${job}\"})")"
  done
  check "żaden cel w klastrze nie jest down" "0" "$(prom_val 'count(up == 0) or vector(0)')"
  check "18 reguł alertów z grupy marketplace załadowanych" "18" \
    "$(k get --raw '/api/v1/namespaces/monitoring/services/kps-prometheus:9090/proxy/api/v1/rules?type=alert' \
      | jq '[.data.groups[] | select(.name=="marketplace") | .rules[]] | length')"
  check "eksporter Postgresa widzi bazę (pg_up)" "1" "$(prom_val 'pg_up{job="postgres"}')"
  check "metryki domenowe catalog (catalog_outbox_oldest_pending_age_seconds)" "tak" \
    "$([ "$(prom_val 'catalog_outbox_oldest_pending_age_seconds')" != brak ] && echo tak || echo nie)"
  # Dashboardy: API Grafany wymaga logowania. Obraz grafana jest distroless
  # (bez sh i wget), więc pytamy z kontenera sidecara, który ma Pythona
  # i to samo hasło admina w REQ_USERNAME/REQ_PASSWORD (używa ich do
  # przeładowania dashboardów). Hasło nie przechodzi przez terminal.
  check "Grafana: dashboardy marketplace-overview i marketplace-ordering" "2" \
    "$(k exec -n monitoring deploy/kps-grafana -c grafana-sc-dashboard -- python3 -c '
import os, base64, urllib.request
r = urllib.request.Request("http://localhost:3000/api/search?query=")
cred = (os.environ["REQ_USERNAME"] + ":" + os.environ["REQ_PASSWORD"]).encode()
r.add_header("Authorization", "Basic " + base64.b64encode(cred).decode())
print(urllib.request.urlopen(r).read().decode())' \
      | jq '[.[] | select(.uid=="marketplace-overview" or .uid=="marketplace-ordering")] | length')"

  # Cała ścieżka alertu: reguła -> Prometheus (firing) -> Alertmanager -> resolved.
  # Przez PLIK, nie `apply -f -`: k() woła kubectl przez with_timeout, czyli
  # w tle (`&`), a nieinteraktywny bash daje procesom w tle stdin z /dev/null —
  # heredoc nigdy nie dociera, apply tworzy zero obiektów, a k() chowa stderr.
  local rule=verify-g1-pipeline rule_file
  rule_file="$(mktemp)"
  cat > "${rule_file}" <<YAML
apiVersion: monitoring.coreos.com/v1
kind: PrometheusRule
metadata: { name: ${rule}, namespace: monitoring }
spec:
  groups:
    - name: verify
      rules:
        - alert: VerifyG1Pipeline
          expr: vector(1)
          labels: { severity: none }
YAML
  check "reguła testowa ${rule} utworzona" "0" "$(k apply -f "${rule_file}" >/dev/null; echo $?)"
  # Do 5 min: operator przepisuje regułę do ConfigMapy, kubelet odświeża
  # zamontowany wolumen co ~60 s, reloader przeładowuje Prometheusa, potem
  # jedna ocena (15 s). Zmierzone ręcznie 2026-11-05: ~65 s.
  local seen=""
  for i in $(seq 1 60); do [ "$(am_alerts VerifyG1Pipeline)" -ge 1 ] 2>/dev/null && { seen=$((i*5)); break; }; sleep 5; done
  check "alert testowy dotarł do Alertmanagera (${seen:-timeout} s)" "tak" "$([ -n "${seen}" ] && echo tak || echo nie)"
  # Rozwiązanie: NAJPIERW warunek przestaje być spełniony (wyrażenie bez
  # wyników), dopiero potem kasujemy regułę. Odwrotnie Prometheus nie wysyła
  # „resolved” — reguła znika razem ze stanem alertu, a Alertmanager zamyka
  # go dopiero po endsAt (~4 min od ostatniego wysłania). Zmierzone 2026-11-05.
  sed -i.bak 's/expr: vector(1)/expr: vector(1) unless vector(1)/' "${rule_file}"
  k apply -f "${rule_file}" >/dev/null
  local gone=""
  # Bez `seen` ten test byłby fałszywie zielony: alertu, który nie dotarł, nie
  # trzeba rozwiązywać.
  [ -n "${seen}" ] && for i in $(seq 1 60); do [ "$(am_alerts VerifyG1Pipeline)" = "0" ] && { gone=$((i*5)); break; }; sleep 5; done
  check "alert testowy rozwiązany, gdy warunek zniknął (${gone:-timeout} s)" "tak" "$([ -n "${gone}" ] && echo tak || echo nie)"
  k delete prometheusrule "${rule}" -n monitoring >/dev/null
  rm -f "${rule_file}" "${rule_file}.bak"

  local firing
  firing="$(k get --raw '/api/v1/namespaces/monitoring/services/kps-prometheus:9090/proxy/api/v1/alerts' \
    | jq -r '[.data.alerts[] | select(.state=="firing") | .labels.alertname] | unique | join(", ")')"
  echo -e "  ${DIM}alerty firing teraz: ${firing:-brak} (Watchdog świeci zawsze — to test ścieżki alertów)${NC}"
}

# =================================================================== G2 =====
# Połączenie TCP z wnętrza poda: php jest w obrazach catalog i search.
# OPEN / BLOCKED (NetworkPolicy gubi pakiety -> timeout 3 s).
tcp_from() { # tcp_from <deploy> <host> <port>
  k -n marketplace exec "deploy/$1" -- php -r '$c=@fsockopen($argv[1],(int)$argv[2],$e,$m,3); echo $c?"OPEN":"BLOCKED";' "$2" "$3"
}

# ------------------------------------------------------------ hardening -----
phase_hardening() {
  tunnel_up
  echo -e "\n${BLD}  hardening — securityContext, ServiceAccount, PSA, RBAC (ETAP G2)${NC}"
  local d want
  for d in catalog-app:33 outbox-publisher:33 catalog-worker:33 catalog-scheduler:33 search-consumer:33 search-metrics:33 glitchtip:5000; do
    want="${d#*:}"; d="${d%%:*}"
    check "${d}: proces jako uid ${want} (nie root)" "${want}" "$(k -n marketplace exec "deploy/${d}" -- id -u | tr -d '\r')"
  done
  check "postgres-0: baza jako uid 70" "70" "$(k -n marketplace exec postgres-0 -c postgres -- id -u)"
  check "postgres-0: eksporter jako nobody (65534)" "65534" "$(k -n marketplace exec postgres-0 -c exporter -- id -u 2>/dev/null || k -n marketplace get pod postgres-0 -o jsonpath='{.spec.containers[?(@.name=="exporter")].securityContext.runAsUser}')"
  check "redis-0: jako uid 999" "999" "$(k -n marketplace exec redis-0 -- id -u)"
  check "catalog-app: system plików tylko do odczytu" "ro" \
    "$(k -n marketplace exec deploy/catalog-app -- sh -c 'touch /app/.verify 2>&1 | grep -q Read-only && echo ro || echo rw')"
  check "catalog-app: storage/ zapisywalny (emptyDir z init kontenera)" "ok" \
    "$(k -n marketplace exec deploy/catalog-app -- sh -c 'touch /app/storage/framework/.verify && rm /app/storage/framework/.verify && echo ok')"
  check "catalog-app: cache tras z obrazu przeniesiony (routes-v7.php)" "ok" \
    "$(k -n marketplace exec deploy/catalog-app -- sh -c 'test -f /app/bootstrap/cache/routes-v7.php && echo ok')"
  check "catalog-app: brak tokena API w podzie" "brak" \
    "$(k -n marketplace exec deploy/catalog-app -- sh -c 'test -e /var/run/secrets/kubernetes.io/serviceaccount/token && echo JEST || echo brak')"
  check "pody sklepu bez konta default (poza ECK)" "0" \
    "$(k -n marketplace get pods -o json | jq '[.items[] | select(.metadata.labels["common.k8s.elastic.co/type"]==null) | select(.status.phase=="Running") | select(.spec.serviceAccountName=="default")] | length')"
  check "catalog-app: startupProbe w żywym podzie" "/up" \
    "$(k -n marketplace get deploy catalog-app -o jsonpath='{.spec.template.spec.containers[0].startupProbe.httpGet.path}')"
  # PSA: suchy przebieg `enforce=restricted` wypisuje pody, które by nie przeszły.
  # Lista ma się ograniczać do znanych wyjątków (rabbitmq, pg-backup, ECK).
  # Ostrzeżenia idą na stderr, a k() go chowa — stąd kubectl wprost.
  local psa others
  psa="$("${KUBECTL_BIN}" --kubeconfig "${KUBECONFIG_VPS}" label --dry-run=server --overwrite ns marketplace pod-security.kubernetes.io/enforce=restricted 2>&1)"
  others="$(grep -oE 'Warning: [a-z0-9-]+' <<<"${psa}" | awk '{print $2}' | grep -vE '^(existing|rabbitmq-0|pg-backup-|marketplace-es-|marketplace-kb-)' | sort -u | tr '\n' ' ')"
  check "PSA restricted: łamią go tylko znane wyjątki (rabbitmq, pg-backup, ECK)" "" "${others% }"
  local sa=system:serviceaccount:marketplace:deployer
  check "RBAC deployer: patch deployments w marketplace" "yes" "$(k auth can-i patch deployments -n marketplace --as="${sa}")"
  check "RBAC deployer: delete jobs w marketplace" "yes" "$(k auth can-i delete jobs -n marketplace --as="${sa}")"
  check "RBAC deployer: BEZ pods/exec" "no" "$(k auth can-i create pods/exec -n marketplace --as="${sa}")"
  check "RBAC deployer: BEZ sekretów kube-system" "no" "$(k auth can-i list secrets -n kube-system --as="${sa}")"
  check "RBAC deployer: BEZ kasowania namespace" "no" "$(k auth can-i delete namespaces --as="${sa}")"
  # deployer może tworzyć Deploymenty, ale nie pody z uprawnieniami do węzła
  # (ValidatingAdmissionPolicy deployer-pod-guard). --dry-run=server przechodzi
  # przez admission, niczego nie tworząc.
  local pf; pf="$(mktemp)"
  cat > "${pf}" <<YAML
apiVersion: v1
kind: Pod
metadata: { name: verify-privileged, namespace: marketplace }
spec:
  containers:
    - { name: x, image: busybox:1.37, securityContext: { privileged: true } }
YAML
  check "deployer: pod privileged odrzucony przez admission (deployer-pod-guard)" "1" \
    "$("${KUBECTL_BIN}" --kubeconfig "${KUBECONFIG_VPS}" create --dry-run=server --as="${sa}" -f "${pf}" 2>&1 | grep -c 'privileged niedozwolone')"
  rm -f "${pf}"
  # Ta sama bramka dla CR-ów ECK: pod tworzy operator, ale z podTemplate od deployera.
  check "deployer: privileged w podTemplate Elasticsearch odrzucony" "1" \
    "$("${KUBECTL_BIN}" --kubeconfig "${KUBECONFIG_VPS}" -n marketplace patch elasticsearch marketplace --dry-run=server --as="${sa}" --type=json \
      -p '[{"op":"add","path":"/spec/nodeSets/0/podTemplate/spec/containers/0/securityContext","value":{"privileged":true}}]' 2>&1 | grep -c 'privileged niedozwolone')"
  check "ValidatingAdmissionPolicy: bez ostrzeżeń typów CEL" "0" \
    "$(k get validatingadmissionpolicy deployer-pod-guard -o json | jq '[.status.typeChecking.expressionWarnings[]?] | length')"
  check "konto catalog: zero uprawnień w API" "no" "$(k auth can-i list pods -n marketplace --as=system:serviceaccount:marketplace:catalog)"
}

# --------------------------------------------------------------- netpol -----
phase_netpol() {
  tunnel_up
  echo -e "\n${BLD}  netpol — NetworkPolicy na żywych podach (ETAP G2)${NC}"
  check "NetworkPolicy w marketplace (default-deny + reguły)" "1" \
    "$(k -n marketplace get networkpolicy default-deny -o name | wc -l | tr -d ' ')"
  # Trasy, które MAJĄ działać (z prawdziwych podów, przez Service DNS)
  check "catalog-app -> postgres:5432" "OPEN" "$(tcp_from catalog-app postgres 5432)"
  check "catalog-app -> redis:6379" "OPEN" "$(tcp_from catalog-app redis 6379)"
  check "catalog-app -> rabbitmq:5672" "OPEN" "$(tcp_from catalog-app rabbitmq 5672)"
  check "catalog-app -> ES:9200" "OPEN" "$(tcp_from catalog-app marketplace-es-http 9200)"
  check "catalog-worker -> catalog-app:80 (webhook bramki)" "OPEN" "$(tcp_from catalog-worker catalog-app 80)"
  check "search-consumer -> catalog-app:80 (projekcja)" "OPEN" "$(tcp_from search-consumer catalog-app 80)"
  check "search-consumer -> rabbitmq:5672" "OPEN" "$(tcp_from search-consumer rabbitmq 5672)"
  check "search-metrics -> postgres:5432" "OPEN" "$(tcp_from search-metrics postgres 5432)"
  # Trasy, których ma NIE być
  check "search-consumer -> redis:6379 zablokowane (brak hasła = brak trasy)" "BLOCKED" "$(tcp_from search-consumer redis 6379)"
  check "search-metrics -> rabbitmq:5672 zablokowane" "BLOCKED" "$(tcp_from search-metrics rabbitmq 5672)"
  check "catalog-scheduler -> ES:9200 zablokowane" "BLOCKED" "$(tcp_from catalog-scheduler marketplace-es-http 9200)"
  check "catalog-app -> internet (1.1.1.1:443) zablokowane" "BLOCKED" "$(tcp_from catalog-app 1.1.1.1 443)"
  check "catalog-app -> DNS działa (postgres się rozwiązuje)" "ok" \
    "$(k -n marketplace exec deploy/catalog-app -- php -r 'echo gethostbyname("postgres")!=="postgres"?"ok":"brak";')"
  # Obcy pod w namespace sklepu (jak `kubectl run` włamywacza albo debug).
  local out
  k -n marketplace delete pod verify-np --ignore-not-found --wait=true >/dev/null
  k -n marketplace run verify-np --restart=Never --image=busybox:1.37 --labels=run=verify-np --command -- \
    sh -c 'nslookup postgres >/dev/null 2>&1 && echo DNS_OK; nc -z -w 3 postgres 5432 && echo PG_OPEN || echo PG_BLOCKED; nc -z -w 3 catalog-app 80 && echo APP_OPEN || echo APP_BLOCKED' >/dev/null
  k -n marketplace wait pod/verify-np --for=jsonpath='{.status.phase}'=Succeeded --timeout=90s >/dev/null
  out="$(k -n marketplace logs verify-np)"
  k -n marketplace delete pod verify-np --wait=false >/dev/null
  check "obcy pod: DNS działa (allow-dns)" "1" "$(grep -c DNS_OK <<<"${out}")"
  check "obcy pod -> postgres:5432 zablokowane" "1" "$(grep -c PG_BLOCKED <<<"${out}")"
  check "obcy pod -> catalog-app:80 zablokowane" "1" "$(grep -c APP_BLOCKED <<<"${out}")"
  # Ruch z innych namespace, który MUSI przechodzić: scrape (monitoring) i ECK.
  check "Prometheus: żaden cel nie jest down (monitoring -> porty metryk)" "0" "$(prom_val 'count(up == 0) or vector(0)')"
  check "ECK widzi ES (elastic-system -> 9200): health green" "green" \
    "$(k -n marketplace get elasticsearch marketplace -o jsonpath='{.status.health}')"
  check "ECK: ostatnia spec przetworzona (observedGeneration = generation)" "tak" \
    "$(k -n marketplace get elasticsearch marketplace -o json | jq -r 'if .status.observedGeneration == .metadata.generation then "tak" else "nie" end')"
}

# -------------------------------------------------------------- ingress -----
INGRESS_PORT="${INGRESS_PORT:-8443}"
CA_FILE="${HOME}/.kube/marketplace-ca.crt"
icurl() { # icurl <host> <ścieżka> [opcje curl...]
  local host="$1" path="$2"; shift 2
  curl -s --max-time 15 --cacert "${CA_FILE}" --resolve "${host}:${INGRESS_PORT}:127.0.0.1" "$@" "https://${host}:${INGRESS_PORT}${path}"
}
phase_ingress() {
  tunnel_up
  echo -e "\n${BLD}  ingress — Traefik + cert-manager, *.marketplace.test (ETAP G2)${NC}"
  check "Traefik gotowy" "1" "$(k -n ingress get deploy traefik -o jsonpath='{.status.readyReplicas}')"
  check "Service traefik: ClusterIP (bez portu na węźle)" "ClusterIP" "$(k -n ingress get svc traefik -o jsonpath='{.spec.type}')"
  check "IngressClass traefik domyślna" "true" \
    "$(k get ingressclass traefik -o jsonpath='{.metadata.annotations.ingressclass\.kubernetes\.io/is-default-class}')"
  check "certyfikaty Ready (CA, marketplace-tls, grafana-tls)" "True True True" \
    "$(echo "$(k -n cert-manager get certificate marketplace-ca -o jsonpath='{.status.conditions[?(@.type=="Ready")].status}')" \
            "$(k -n marketplace get certificate marketplace-tls -o jsonpath='{.status.conditions[?(@.type=="Ready")].status}')" \
            "$(k -n monitoring get certificate grafana-tls -o jsonpath='{.status.conditions[?(@.type=="Ready")].status}')")"
  bash "${ROOT}/tools/k3s/ingress-tunnel.sh" >/dev/null 2>&1
  check "tunel :${INGRESS_PORT} -> Traefik" "0" "$(nc -z 127.0.0.1 "${INGRESS_PORT}"; echo $?)"
  check "shop: /up przez Ingress, certyfikat zaufany względem CA" "200" "$(icurl shop.marketplace.test /up -o /dev/null -w '%{http_code}')"
  check "shop: wystawca certyfikatu = marketplace.test CA" "1" \
    "$(echo | openssl s_client -connect "127.0.0.1:${INGRESS_PORT}" -servername shop.marketplace.test 2>/dev/null | openssl x509 -noout -issuer 2>/dev/null | grep -c 'marketplace.test CA')"
  check "shop: nagłówek X-App-Version (odpowiada catalog-app)" "1" "$(icurl shop.marketplace.test /up -D - -o /dev/null | grep -ci '^x-app-version:')"
  # Laravel za proxy (TRUSTED_PROXIES): linki w HTML mają być https z portem tunelu.
  check "shop: Laravel generuje linki https://…:${INGRESS_PORT} (zaufane proxy)" "tak" \
    "$(icurl shop.marketplace.test /search | grep -q "https://shop.marketplace.test:${INGRESS_PORT}" && echo tak || echo nie)"
  check "grafana: /api/health przez Ingress" "200" "$(icurl grafana.marketplace.test /api/health -o /dev/null -w '%{http_code}')"
  check "kibana: odpowiada przez Ingress (200/302/401)" "tak" \
    "$(icurl kibana.marketplace.test /api/status -o /dev/null -w '%{http_code}' | grep -qE '^(200|302|401)$' && echo tak || echo nie)"
  check "nieznany host -> 404 z Traefika (nie domyślny backend)" "404" \
    "$(curl -sk --max-time 10 --resolve "nope.marketplace.test:${INGRESS_PORT}:127.0.0.1" -o /dev/null -w '%{http_code}' "https://nope.marketplace.test:${INGRESS_PORT}/")"
  check "Prometheus: cel Traefika up" "1" "$(prom_val 'min(up{namespace="ingress"})')"
}

# ------------------------------------------------------------------ hpa -----
# Obciążenie z osobnego namespace `loadtest` PRZEZ Ingress (jak prawdziwy ruch):
# NetworkPolicy nie wpuszcza nikogo na skróty do catalog-app:80.
LOAD_IMAGE="curlimages/curl:8.17.0"
start_load() { # start_load <nazwa> <liczba żądań>
  local ip; ip="$(k -n ingress get svc traefik -o jsonpath='{.spec.clusterIP}')"
  k create namespace loadtest --dry-run=client -o yaml > /tmp/verify-ns.$$ && k apply -f /tmp/verify-ns.$$ >/dev/null; rm -f /tmp/verify-ns.$$
  k -n loadtest delete pod "$1" --ignore-not-found --wait=true >/dev/null
  k -n loadtest run "$1" --restart=Never --image="${LOAD_IMAGE}" --labels=app=loadgen --command -- \
    curl -sk --parallel --parallel-max 24 -o /dev/null --resolve "shop.marketplace.test:443:${ip}" \
    "https://shop.marketplace.test/search?q=verify[1-$2]" >/dev/null
}
phase_hpa() {
  tunnel_up
  echo -e "\n${BLD}  hpa — HorizontalPodAutoscaler catalog-app pod obciążeniem (ETAP G2)${NC}"
  check "HPA catalog-app: cel CPU 70%" "70" "$(k -n marketplace get hpa catalog-app -o jsonpath='{.spec.metrics[0].resource.target.averageUtilization}')"
  local cpu; cpu="$(k -n marketplace get hpa catalog-app -o jsonpath='{.status.currentMetrics[0].resource.current.averageUtilization}')"
  check "HPA widzi metrykę CPU (metrics-server), teraz ${cpu:-?}%" "tak" "$([[ "${cpu}" =~ ^[0-9]+$ ]] && echo tak || echo nie)"
  local before; before="$(k -n marketplace get deploy catalog-app -o jsonpath='{.status.readyReplicas}')"
  echo -e "  ${DIM}repliki przed testem: ${before}; start obciążenia (curl --parallel 24 przez Ingress)${NC}"
  start_load verify-hpa 200000
  local scaled="" i
  for i in $(seq 1 48); do
    [ "$(k -n marketplace get hpa catalog-app -o jsonpath='{.status.desiredReplicas}')" -ge 2 ] 2>/dev/null \
      && [ "$(k -n marketplace get deploy catalog-app -o jsonpath='{.status.readyReplicas}')" -ge 2 ] 2>/dev/null \
      && { scaled=$((i*5)); break; }
    sleep 5
  done
  local peak; peak="$(k -n marketplace get hpa catalog-app -o jsonpath='{.status.currentMetrics[0].resource.current.averageUtilization}')"
  check "HPA dodał repliki i są gotowe (${scaled:-timeout} s, CPU ${peak:-?}%)" "tak" "$([ -n "${scaled}" ] && echo tak || echo nie)"
  check "nowe pody gotowe przez startupProbe (Ready == desired)" "tak" \
    "$([ "$(k -n marketplace get deploy catalog-app -o jsonpath='{.status.readyReplicas}')" = "$(k -n marketplace get deploy catalog-app -o jsonpath='{.spec.replicas}')" ] && echo tak || echo nie)"
  check "Prometheus widzi HPA (kube-state-metrics)" "tak" \
    "$([ "$(prom_val 'kube_horizontalpodautoscaler_status_desired_replicas{horizontalpodautoscaler="catalog-app"}')" != brak ] && echo tak || echo nie)"
  k delete namespace loadtest --wait=false >/dev/null
  echo -e "  ${DIM}obciążenie zatrzymane; HPA zejdzie do 1 repliki po oknie stabilizacji (5 min, 1 pod/min)${NC}"
}

# --------------------------------------------------------------- canary -----
phase_canary() {
  tunnel_up
  echo -e "\n${BLD}  canary — drugi Deployment za tym samym Service (ETAP G2)${NC}"
  local tag; tag="$(k -n marketplace get deploy catalog-app -o jsonpath='{.spec.template.spec.containers[0].image}')"; tag="${tag##*:}"
  bash "${ROOT}/tools/k3s/canary.sh" start "${tag}" >/dev/null 2>&1
  check "canary gotowy (1/1)" "1" "$(k -n marketplace get deploy catalog-app-canary -o jsonpath='{.status.readyReplicas}')"
  local canary_ip; canary_ip="$(k -n marketplace get pods -l app=catalog-app,track=canary -o jsonpath='{.items[0].status.podIP}')"
  check "Service catalog-app ma w endpointach pod canary" "1" \
    "$(k -n marketplace get endpointslices -l kubernetes.io/service-name=catalog-app -o json | jq --arg ip "${canary_ip}" '[.items[].endpoints[]? | select(.addresses[] == $ip)] | length')"
  check "Deployment catalog-app NIE przejął poda canary (selektory rozłączne)" "0" \
    "$(k -n marketplace get pods -l app=catalog-app,track=stable -o json | jq --arg ip "${canary_ip}" '[.items[] | select(.status.podIP == $ip)] | length')"
  start_load verify-canary 600
  k -n loadtest wait pod/verify-canary --for=jsonpath='{.status.phase}'=Succeeded --timeout=180s >/dev/null
  sleep 35   # dwa scrape'y (15 s), żeby przyrost był w Prometheusie
  local q='sum by (track) (increase(caddy_http_request_duration_seconds_count{job="caddy",server!="metrics"}[3m]))'
  local res; res="$(prom_q "${q}")"
  local st cn
  st="$(jq -r '[.data.result[] | select(.metric.track=="stable") | .value[1] | tonumber] | add // 0 | floor' <<<"${res}")"
  cn="$(jq -r '[.data.result[] | select(.metric.track=="canary") | .value[1] | tonumber] | add // 0 | floor' <<<"${res}")"
  check "ruch dzieli się między tracki (stable ${st}, canary ${cn} żądań w 3 min)" "tak" \
    "$([ "${st:-0}" -ge 40 ] && [ "${cn:-0}" -ge 40 ] && echo tak || echo nie)"
  bash "${ROOT}/tools/k3s/canary.sh" abort >/dev/null 2>&1
  check "canary usunięty: w endpointach zostały tylko stabilne" "0" \
    "$(k -n marketplace get endpointslices -l kubernetes.io/service-name=catalog-app -o json | jq --arg ip "${canary_ip}" '[.items[].endpoints[]? | select(.addresses[] == $ip)] | length')"
  k delete namespace loadtest --wait=false >/dev/null
}

case "${1:-}" in
  cluster)  phase_cluster ;;
  monitoring) phase_monitoring ;;
  hardening) phase_hardening ;;
  netpol)   phase_netpol ;;
  ingress)  phase_ingress ;;
  hpa)      phase_hpa ;;
  canary)   phase_canary ;;
  g2)       phase_hardening; phase_netpol; phase_ingress; phase_hpa; phase_canary ;;
  exposure) phase_exposure ;;
  eck)      phase_eck ;;
  config)   phase_config ;;
  es)       phase_es ;;
  data)     phase_data ;;
  apps)     phase_apps ;;
  stack)    phase_stack ;;
  backup)   phase_backup ;;
  reboot)   phase_reboot ;;
  all)      phase_cluster; phase_exposure; phase_eck; phase_config; phase_es; phase_data; phase_apps; phase_stack; phase_backup ;;
  *) echo "użycie: $0 {cluster|exposure|eck|config|es|data|apps|stack|backup|monitoring|hardening|netpol|ingress|hpa|canary|g2|reboot|all}" >&2; exit 2 ;;
esac

echo -e "\n  PASS=${PASS} FAIL=${FAIL}\n"
[ "${FAIL}" -eq 0 ]
