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
# Tunel potrafi paść w trakcie długiej weryfikacji (np. pod obciążeniem
# serwera) — wtedy każde `kubectl` zwraca pusto, a test wygląda na błąd
# danych. Przed każdym wywołaniem: jeśli portu nie ma, wznów tunel.
# Przy błędzie: świeży tunel i jedna ponowna próba. Bez tego przejściowy
# "TLS handshake timeout" (4 vCPU pod obciążeniem 6 JVM-ów) dawał pusty
# wynik, który wyglądał jak błąd danych.
k() {
  local out rc attempt
  for attempt in 1 2; do
    nc -z 127.0.0.1 26443 2>/dev/null || tunnel_up
    out="$("${KUBECTL_BIN}" --kubeconfig "${KUBECONFIG_VPS}" --request-timeout=20s "$@" 2>/dev/null)"; rc=$?
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
    curl -s -u '$1':\"\$P\" -X ${3:-GET} -H 'Content-Type: application/json' \"http://\$IP:9200$2\" ${4:+-d '$4'}"
}
phase_es() {
  tunnel_up
  echo -e "\n${BLD}  es — Elasticsearch i Kibana przez ECK${NC}"
  check "Elasticsearch: health green" "green" "$(k get elasticsearch marketplace -n marketplace -o jsonpath='{.status.health}')"
  check "Elasticsearch: 3 nody dostępne" "3" "$(k get elasticsearch marketplace -n marketplace -o jsonpath='{.status.availableNodes}')"
  check "Elasticsearch: wersja 9.5.1" "9.5.1" "$(k get elasticsearch marketplace -n marketplace -o jsonpath='{.status.version}')"
  local want; want="$(awk '/elastic-elasticsearch/{getline; print $2}' "${ROOT}/deploy/k8s/kustomization.yaml")"
  check "pody ES na obrazie z GHCR (tag z kustomization)" "3" \
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
kx() { k exec -n marketplace "$1" -- sh -c "$2"; }   # hasła czytane w kontenerze z jego env
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
}

# ----------------------------------------------------------------- apps -----
# Dostęp do catalog-app z Maca: `kubectl port-forward` przez tunel do API —
# k8s-owy odpowiednik `LocalForward` z D1, bez dotykania serwera.
pf_start() { # pf_start <svc> <port-lokalny> <port-svc>
  nc -z 127.0.0.1 "$2" 2>/dev/null && return 0
  ( k port-forward -n marketplace "svc/$1" "$2:$3" >/dev/null 2>&1 & )
  for _ in $(seq 1 15); do nc -z 127.0.0.1 "$2" 2>/dev/null && return 0; sleep 1; done
  return 1
}
phase_apps() {
  tunnel_up
  echo -e "\n${BLD}  apps — aplikacje w k8s${NC}"
  local tag; tag="$(k get deploy catalog-app -n marketplace -o jsonpath='{.spec.template.spec.containers[0].image}' | sed 's/.*://')"
  for d in catalog-app outbox-publisher search-consumer; do
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
  check "search-consumer: zero restartów" "0" \
    "$(k get pods -n marketplace -l app=search-consumer -o jsonpath='{.items[0].status.containerStatuses[0].restartCount}')"
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
  local c; c="$(pg_counts_compose)"
  check "Postgres: products/offers/brands/sellers = Compose (${c})" "${c:-brak}" "$(pg_counts_k8s)"
  check "ES: products-search 1500 dokumentów" "1500" \
    "$(es_k8s elastic '/products-search/_count' | python3 -c 'import json,sys; print(json.load(sys.stdin)["count"])' 2>/dev/null)"
  local ek ec; ek="$(eval_k8s)"; ec="$(eval_compose)"
  check "eval k8s = eval Compose (${ec:-?})" "${ec:-brak}" "${ek:-brak}"
  check "eval OK (>= 0.80)" "tak" "$(python3 -c 'import sys; print("tak" if float(sys.argv[1]) >= 0.80 else "nie")' "${ek:-0}" 2>/dev/null)"
  # E2E ścieżką użytkownika (RUNBOOK #031): catalog -> outbox -> publisher ->
  # RabbitMQ -> consumer -> ES. Wszystko w k8s.
  local price=$(( (RANDOM % 90000) + 10000 )) pid t0 waited=""
  pid="$(k exec -n marketplace deploy/catalog-app -- php artisan tinker --execute "\$o = App\\Models\\Offer::query()->orderBy(\"id\")->first(); \$o->updateWithOutbox([\"price_cents\" => ${price}]); echo \$o->product_id;" 2>/dev/null | grep -oE '[0-9]+$' | tail -1)"
  t0=$(date +%s)
  while [ $(( $(date +%s) - t0 )) -le 10 ]; do
    if es_k8s elastic "/products-search/_doc/${pid:-0}" | grep -q "${price}"; then waited=$(( $(date +%s) - t0 )); break; fi
    sleep 1
  done
  check "E2E: zmiana ceny w ES k8s w <= 10 s (${waited:-timeout} s)" "tak" "$([ -n "${waited}" ] && echo tak || echo nie)"
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

case "${1:-}" in
  cluster)  phase_cluster ;;
  exposure) phase_exposure ;;
  eck)      phase_eck ;;
  config)   phase_config ;;
  es)       phase_es ;;
  data)     phase_data ;;
  apps)     phase_apps ;;
  stack)    phase_stack ;;
  backup)   phase_backup ;;
  all)      phase_cluster; phase_exposure; phase_eck; phase_config; phase_es; phase_data; phase_apps; phase_stack; phase_backup ;;
  *) echo "użycie: $0 {cluster|exposure|eck|config|es|data|apps|stack|backup|all}" >&2; exit 2 ;;
esac

echo -e "\n  PASS=${PASS} FAIL=${FAIL}\n"
[ "${FAIL}" -eq 0 ]
