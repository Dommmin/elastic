# shellcheck shell=bash
#  tools/chaos/lib.sh — wspólne funkcje „zepsuj i napraw” (ETAP G4).
#
#  Ten sam plik działa w dwóch miejscach:
#   - na Macu operatora (kubectl przez tunel 127.0.0.1:26443, ~/.kube/elastic-vps.yaml),
#   - w Jobie `chaos-undo` w klastrze (CHAOS_IN_CLUSTER=1): kubeconfig składany z tokenu
#     ServiceAccount `chaos-undo`, bo kubectl 1.36 w obrazie alpine/k8s jako uid 65534
#     nie podejmuje konfiguracji in-cluster sam (fakty z kroku 0, spec § 10).
#
#  Zgodność: bash 3.2 (macOS) i bash 5 (obraz Joba). Bez mapfile, declare -A, ${v,,}.
#  Funkcje expect/expect_match/wait_for skopiowane z tools/ckad/lib.sh (nie sourcujemy
#  go: lib CKAD zakłada lab i kubeconfig ucznia).

set -uo pipefail

CHAOS_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." 2>/dev/null && pwd)"
NS="marketplace"
KUBECTL_BIN="${KUBECTL:-/opt/homebrew/bin/kubectl}"
KUBECONFIG_VPS="${KUBECONFIG_VPS:-${HOME}/.kube/elastic-vps.yaml}"
SCORES_FILE="${CHAOS_SCORES_FILE:-${HOME}/.chaos-scores.tsv}"
CHAOS_IMAGE="alpine/k8s:1.36.6"
LOCK_CM="chaos-lock"
SCRIPTS_CM="chaos-scripts"
UNDO_JOB="chaos-undo"
# Alerty, które nie mówią nic o bieżącym zdrowiu sklepu: Watchdog i InfoInhibitor
# płoną zawsze (z chartu), KubeJobFailed dotyczy historycznych nieudanych Jobów.
PREFLIGHT_IGNORED_ALERTS="Watchdog InfoInhibitor KubeJobFailed"

PASS=0
FAIL=0
# shellcheck disable=SC2034  # BLD/DIM używają chaos.sh i selftest.sh
{ GREEN=$'\033[0;32m'; RED=$'\033[0;31m'; DIM=$'\033[2m'; BLD=$'\033[1m'; RESET=$'\033[0m'; }

# ---------------------------------------------------------------- kubectl ----
if [[ "${CHAOS_IN_CLUSTER:-0}" == "1" ]]; then
  KUBECTL_BIN="kubectl"
  # Kubeconfig z plików ServiceAccount, w zapisywalnym /tmp (rootfs tylko do odczytu).
  _sa=/var/run/secrets/kubernetes.io/serviceaccount
  CHAOS_KUBECONFIG="${TMPDIR:-/tmp}/chaos-kubeconfig"
  if [[ -r "${_sa}/token" ]]; then
    cat > "${CHAOS_KUBECONFIG}" <<EOF
apiVersion: v1
kind: Config
clusters:
- name: in-cluster
  cluster:
    server: https://${KUBERNETES_SERVICE_HOST:-10.43.0.1}:${KUBERNETES_SERVICE_PORT:-443}
    certificate-authority: ${_sa}/ca.crt
users:
- name: chaos-undo
  user:
    tokenFile: ${_sa}/token
contexts:
- name: in-cluster
  context: { cluster: in-cluster, user: chaos-undo, namespace: ${NS} }
current-context: in-cluster
EOF
  fi
  k() { "${KUBECTL_BIN}" --kubeconfig "${CHAOS_KUBECONFIG}" --request-timeout=30s "$@"; }
else
  tunnel_up() {
    nc -z 127.0.0.1 26443 2>/dev/null && return 0
    ssh -o BatchMode=yes -o ConnectTimeout=10 -o ExitOnForwardFailure=yes -fN elastic-vps-k8s 2>/dev/null
    local _i
    for _i in 1 2 3 4 5; do nc -z 127.0.0.1 26443 2>/dev/null && return 0; sleep 1; done
    return 1
  }
  k() {
    nc -z 127.0.0.1 26443 2>/dev/null || tunnel_up || true
    "${KUBECTL_BIN}" --kubeconfig "${KUBECONFIG_VPS}" --request-timeout=30s "$@"
  }
fi

# ------------------------------------------------------------- asercje -------
# expect <opis> <oczekiwane> <otrzymane>
expect() {
  if [[ "$2" == "$3" ]]; then
    PASS=$((PASS + 1)); printf '  %s✓%s %s\n' "${GREEN}" "${RESET}" "$1"
  else
    FAIL=$((FAIL + 1)); printf '  %s✗%s %s  %s(oczekiwano: %s, otrzymano: %s)%s\n' "${RED}" "${RESET}" "$1" "${DIM}" "$2" "${3:-<puste>}" "${RESET}"
  fi
}

# expect_match <opis> <regex> <otrzymane>
expect_match() {
  if [[ "$3" =~ $2 ]]; then
    PASS=$((PASS + 1)); printf '  %s✓%s %s\n' "${GREEN}" "${RESET}" "$1"
  else
    FAIL=$((FAIL + 1)); printf '  %s✗%s %s  %s(oczekiwano dopasowania: %s, otrzymano: %s)%s\n' "${RED}" "${RESET}" "$1" "${DIM}" "$2" "${3:-<puste>}" "${RESET}"
  fi
}

# wait_for <sekundy> <polecenie...> — ponawia polecenie co 2 s, aż zwróci 0.
wait_for() {
  local deadline=$((SECONDS + $1)); shift
  until "$@" >/dev/null 2>&1; do
    (( SECONDS >= deadline )) && return 1
    sleep 2
  done
}

die() { printf '%s✗%s %s\n' "${RED}" "${RESET}" "$*" >&2; exit 1; }

# ------------------------------------------------- sygnały: Prometheus/AM -----
# Przez proxy API Kubernetesa (jak verify.sh): bez port-forwardu, sam tunel.
prom_q() { # prom_q <PromQL> -> surowy JSON /api/v1/query
  k get --raw "/api/v1/namespaces/monitoring/services/kps-prometheus:9090/proxy/api/v1/query?query=$(jq -rn --arg q "$1" '$q|@uri')"
}
prom_val() { # prom_val <PromQL> -> pierwsza wartość albo „brak”
  local v; v="$(prom_q "$1" 2>/dev/null | jq -r '.data.result[0].value[1] // "brak"' 2>/dev/null)"
  echo "${v:-brak}"
}
alert_firing() { # alert_firing <nazwa> -> tak|nie (Alertmanager, state=active)
  local n
  n="$(k get --raw "/api/v1/namespaces/monitoring/services/kps-alertmanager:9093/proxy/api/v2/alerts?active=true&filter=$(jq -rn --arg f "alertname=\"$1\"" '$f|@uri')" 2>/dev/null \
    | jq '[.[] | select(.status.state == "active")] | length' 2>/dev/null)"
  [[ "${n:-0}" -ge 1 ]] 2>/dev/null && echo tak || echo nie
}
# Płonące alerty dotyczące sklepu: grupa reguł `marketplace` albo etykieta
# namespace="marketplace", bez PREFLIGHT_IGNORED_ALERTS. Wypisuje nazwy (po jednej
# w linii); kod 2, gdy API Prometheusa nie odpowiada.
marketplace_alerts() {
  local raw
  raw="$(k get --raw '/api/v1/namespaces/monitoring/services/kps-prometheus:9090/proxy/api/v1/rules?type=alert' 2>/dev/null)" || return 2
  # Pusta albo błędna odpowiedź (timeout proxy) to „nie wiem”, nie „brak alertów”.
  # Brak grupy `marketplace` (reguły się nie załadowały) to też „nie wiem”.
  jq -e '.status == "success" and (.data.groups | type == "array") and any(.data.groups[]; .name == "marketplace")' <<<"${raw}" >/dev/null 2>&1 || return 2
  jq -r --arg ign " ${PREFLIGHT_IGNORED_ALERTS} " '
    .data.groups[] | .name as $g | .rules[] | .alerts[]?
    | select(.state == "firing")
    | select($g == "marketplace" or .labels.namespace == "marketplace")
    | .labels.alertname as $n | select(($ign | contains(" " + $n + " ")) | not)
    | (.labels.job_name // .labels.deployment // .labels.statefulset // .labels.pod) as $o
    | $n + (if $o then " (" + $o + ")" else "" end)
  ' <<<"${raw}" || return 2
}

# alert_state_marketplace <alertname> -> tak|nie|err: czy alert płonie (state=firing)
# w regułach sklepu (grupa `marketplace` albo label namespace="marketplace"), według
# Prometheusa. „err” = API nie odpowiedziało poprawnie (nie mylić z „nie”). Alert o tej
# samej nazwie z innego namespace'u (generyczne reguły kube-prometheus) nie liczy się.
alert_state_marketplace() {
  local raw n
  raw="$(k get --raw '/api/v1/namespaces/monitoring/services/kps-prometheus:9090/proxy/api/v1/rules?type=alert' 2>/dev/null)" || { echo err; return 0; }
  jq -e '.status == "success" and any(.data.groups[]; .name == "marketplace")' <<<"${raw}" >/dev/null 2>&1 || { echo err; return 0; }
  n="$(jq --arg a "$1" '[.data.groups[] | .name as $g | .rules[] | .alerts[]?
        | select(.state == "firing" and .labels.alertname == $a)
        | select($g == "marketplace" or .labels.namespace == "marketplace")] | length' <<<"${raw}" 2>/dev/null)"
  [[ "${n}" =~ ^[0-9]+$ ]] || { echo err; return 0; }
  [[ "${n}" -ge 1 ]] && echo tak || echo nie
}

# alert_for_seconds <alertname> -> `for:` reguły w sekundach (pusto, gdy API nie odpowiada
# albo reguły nie ma).
alert_for_seconds() {
  local i v
  for i in 1 2 3 4 5; do
    v="$(k get --raw '/api/v1/namespaces/monitoring/services/kps-prometheus:9090/proxy/api/v1/rules?type=alert' 2>/dev/null \
      | jq -r --arg n "$1" '[.data.groups[].rules[] | select(.name == $n) | .duration] | first // empty' 2>/dev/null)"
    [[ "${v}" =~ ^[0-9]+$ ]] && { echo "${v}"; return 0; }
    sleep 5
  done
  return 1
}

# wait_quiet [limit_s=600] — czeka, aż sklep będzie „zdrowy do awarii”: brak płonących
# alertów marketplace, ES green, Deploymenty i StatefulSety gotowe (te same warunki co
# preflight w chaos.sh). Po resecie alerty (OutboxStale, KubePodCrashLooping) gasną
# minutami, a preflight odmawia kolejnej awarii. Błąd API Prometheusa to „nie wiem,
# czekam dalej”, nigdy „brak alertów”. Wymaga dwóch czystych odczytów z rzędu (20 s).
# Kod 0 = cicho, 1 = limit czasu (na stderr: co jeszcze przeszkadza).
wait_quiet() {
  local limit="${1:-600}" deadline clean=0 why alerts rc n last=0
  deadline=$((SECONDS + limit))
  while :; do
    why=""
    alerts="$(marketplace_alerts)"; rc=$?
    if [[ ${rc} -ne 0 ]]; then why="Prometheus nie odpowiada (nie wiem, czy są alerty)"
    elif [[ -n "${alerts}" ]]; then why="alerty: $(echo "${alerts}" | sort -u | tr '\n' ',' | sed 's/,$//')"
    elif [[ "$(k -n "${NS}" get elasticsearch marketplace -o jsonpath='{.status.health}' 2>/dev/null)" != green ]]; then why="ES nie jest green"
    else
      # Listy bierzemy RAZ i do niczego nie przepuszczamy pustej: błąd API albo pusta
      # lista to „nie wiem” (pętla po pustej liście wyglądałaby jak „wszystko gotowe”).
      local deps sts
      deps="$(k -n "${NS}" get deployments -o jsonpath='{.items[*].metadata.name}' 2>/dev/null)" || deps=""
      sts="$(k -n "${NS}" get statefulsets -o jsonpath='{.items[*].metadata.name}' 2>/dev/null)" || sts=""
      if [[ -z "${deps}" ]]; then why="API nie zwraca Deploymentów"
      elif [[ -z "${sts}" ]]; then why="API nie zwraca StatefulSetów"
      else
        for n in ${deps}; do
          [[ "$(deploy_ready "${n}")" == tak ]] || why="Deployment ${n} niegotowy"
        done
        for n in ${sts}; do
          [[ "$(sts_ready "${n}")" == tak ]] || why="StatefulSet ${n} niegotowy"
        done
      fi
    fi
    if [[ -z "${why}" ]]; then
      clean=$((clean + 1))
      [[ ${clean} -ge 2 ]] && return 0
      sleep 20; continue
    fi
    clean=0
    if (( SECONDS >= deadline )); then echo "  wait_quiet: limit ${limit} s — ${why}" >&2; return 1; fi
    if (( SECONDS - last >= 60 )); then echo "  ${DIM}czekam na ciszę (${why})${RESET}"; last=${SECONDS}; fi
    sleep 15
  done
}

# --------------------------------------------------------- stan obiektów -----
rmq() { k -n "${NS}" exec rabbitmq-0 -c rabbitmq -- rabbitmqctl -q "$@"; }

# Zdarzenie outbox bez zmiany danych: zapis pierwszego produktu jego własną nazwą
# (`updateWithOutbox` zawsze emituje zdarzenie) — wzorzec z verify.sh fazy `stack`.
touch_product() {
  local pid
  pid="$(k exec -n "${NS}" deploy/catalog-app -- php artisan tinker --execute "\$p = App\\Models\\Product::query()->orderBy(\"id\")->first(); echo \$p->id;" 2>/dev/null | grep -oE '[0-9]+$' | tail -1)"
  [[ -n "${pid}" ]] || return 1
  k exec -n "${NS}" deploy/catalog-app -- php artisan tinker --execute "\$p = App\\Models\\Product::query()->findOrFail(${pid}); \$p->updateWithOutbox(['name' => \$p->name], 'product.updated');" >/dev/null 2>&1
}

# deploy_ready <nazwa> -> tak|nie: rollout skończony i wszystko gotowe
# (updatedReplicas == replicas == readyReplicas == spec.replicas, observedGeneration aktualne).
deploy_ready() {
  k -n "${NS}" get deployment "$1" -o json 2>/dev/null | jq -r '
    (.status.observedGeneration // 0) as $og | (.metadata.generation // -1) as $g
    | (.spec.replicas // 1) as $want | (.status.replicas // 0) as $r
    | (.status.updatedReplicas // 0) as $u | (.status.readyReplicas // 0) as $rd
    | if $og == $g and $u == $r and $r == $rd and $rd == $want then "tak" else "nie" end
  ' 2>/dev/null || echo nie
}

# sts_ready <nazwa> -> tak|nie
sts_ready() {
  k -n "${NS}" get statefulset "$1" -o json 2>/dev/null | jq -r '
    if (.status.observedGeneration // 0) == .metadata.generation
       and (.status.readyReplicas // 0) == (.spec.replicas // 1) then "tak" else "nie" end
  ' 2>/dev/null || echo nie
}

# recent_restarts <app> <sekundy> -> liczba kontenerów podów app=<app>, które
# zakończyły się (lastState.terminated.finishedAt) w ostatnich N sekundach.
# Liczone z czasu, nie z licznika restartCount: licznik nie maleje po naprawie.
recent_restarts() {
  k -n "${NS}" get pods -l "app=$1" -o json 2>/dev/null | jq --argjson s "$2" '
    [ .items[].status.containerStatuses[]?
      | .lastState.terminated.finishedAt // empty
      | select((now - fromdateiso8601) < $s) ] | length
  ' 2>/dev/null || echo 0
}

# generation <rodzaj/nazwa> -> metadata.generation
generation() { k -n "${NS}" get "$1" -o jsonpath='{.metadata.generation}' 2>/dev/null; }

# ------------------------------------------------------------- migawka -------
# snapshot_get <rodzaj/nazwa>... -> lista (JSON, czyli też poprawny YAML) oczyszczona
# z pól, których `kubectl replace` nie przyjmie albo które przypisuje serwer.
# Adnotacji last-applied celowo NIE usuwamy: deploy.sh robi client-side apply, a replace
# bez niej zostawiłby obiekt bez bazy trzystronnego scalania (apply przestałby usuwać
# pola skasowane z manifestu). Pusta lista celów -> {"kind":"List","items":[]}.
snapshot_get() {
  if [[ $# -eq 0 ]]; then
    echo '{"apiVersion":"v1","kind":"List","items":[]}'; return 0
  fi
  k -n "${NS}" get "$@" -o json | jq '
    (if .kind == "List" then .items else [.] end)
    | map(del(.status,
              .metadata.resourceVersion, .metadata.uid, .metadata.generation,
              .metadata.creationTimestamp, .metadata.managedFields, .metadata.selfLink,
              .metadata.annotations["deployment.kubernetes.io/revision"])
          | if (.metadata.annotations // {}) == {} then del(.metadata.annotations) else . end)
    | {apiVersion: "v1", kind: "List", items: .}
  '
}

# snapshot_restore — PUT obiektów z migawki w blokadzie. Ten sam szablon co na żywo =
# brak zmiany spec = brak nowego rolloutu (idempotentne).
#  - Przez `kubectl replace --raw` (surowy PUT), nie `replace -f`: zwykły replace PRZEPISUJE
#    adnotację last-applied na pełny obiekt (gdy już istnieje), co psuje późniejszy
#    client-side apply w deploy.sh i podbija generation przy każdym undo.
#  - Adnotacje zarządzane przez kontrolery (deployment.kubernetes.io/revision) bierzemy
#    z obiektu na żywo: dla Deploymentu KAŻDA zmiana adnotacji podbija generation.
#  - Obiekt, którego nie ma (scenariusz go usunął), jest tworzony (surowy POST).
SERVER_ANNOTATIONS='["deployment.kubernetes.io/revision"]'

# api_path <apiVersion> <kind> [nazwa] -> ścieżka REST w NS (liczba mnoga jak w API).
api_path() {
  local plural; plural="$(printf '%s' "$2" | tr '[:upper:]' '[:lower:]')"
  case "${plural}" in
    *s|*sh|*ch|*x) plural="${plural}es" ;;
    *y) plural="${plural%y}ies" ;;
    *) plural="${plural}s" ;;
  esac
  local base="/apis/$1"; [[ "$1" == v1 ]] && base="/api/v1"
  printf '%s/namespaces/%s/%s%s' "${base}" "${NS}" "${plural}" "${3:+/$3}"
}

snapshot_restore() {
  local snap n refs live merged i item tmp rc=0
  local lrc
  snap="$(lock_get snapshot.yaml)"; lrc=$?
  case "${lrc}" in
    2) echo "  błąd API przy odczycie migawki — nic nie przywrócone" >&2; return 1 ;;
    1) echo "  (brak blokady — nie ma czego przywracać)"; return 0 ;;
  esac
  # Blokada jest, a klucza nie ma: break przerwany przed zapisem migawki, nic nie zepsuto.
  [[ -n "${snap}" ]] || { echo "  (brak migawki w blokadzie)"; return 0; }
  n="$(jq '.items | length' <<<"${snap}" 2>/dev/null)"
  [[ "${n:-0}" -gt 0 ]] || return 0
  refs="$(jq -r '.items[] | "\(.kind)/\(.metadata.name)"' <<<"${snap}")"
  # shellcheck disable=SC2086  # refs = lista rodzaj/nazwa
  live="$(k -n "${NS}" get ${refs} --ignore-not-found -o json)" || return 1
  [[ -n "${live}" ]] || live='{"items":[]}'
  merged="$(jq --argjson live "${live}" --argjson keep "${SERVER_ANNOTATIONS}" '
    ($live | if has("items") then .items else [.] end) as $l
    | .items | map(. as $o
        | ($l | map(select(.kind == $o.kind and .metadata.name == $o.metadata.name)) | first) as $cur
        | if $cur == null then {missing: true, obj: .}
          else {missing: false, obj: (reduce $keep[] as $a (.;
                 if ($cur.metadata.annotations // {})[$a] != null
                 then .metadata.annotations[$a] = $cur.metadata.annotations[$a] else . end))}
          end)' <<<"${snap}")" || return 1
  tmp="$(mktemp "${TMPDIR:-/tmp}/chaos-obj.XXXXXX")" || return 1
  for ((i = 0; i < n; i++)); do
    item="$(jq -c ".[${i}]" <<<"${merged}")"
    jq '.obj' <<<"${item}" > "${tmp}"
    local av kd nm
    av="$(jq -r '.apiVersion' "${tmp}")"; kd="$(jq -r '.kind' "${tmp}")"; nm="$(jq -r '.metadata.name' "${tmp}")"
    if [[ "$(jq -r '.missing' <<<"${item}")" == true ]]; then
      k create --raw "$(api_path "${av}" "${kd}")" -f "${tmp}" >/dev/null || rc=1
    else
      k replace --raw "$(api_path "${av}" "${kd}" "${nm}")" -f "${tmp}" >/dev/null || rc=1
    fi
  done
  rm -f "${tmp}"
  return "${rc}"
}

# ------------------------------------------------------------- blokada -------
# lock_create <s> <ttl_min> — atomowe `kubectl create`: AlreadyExists = trwa inny scenariusz.
lock_create() {
  local now; now="$(date +%s)"
  k -n "${NS}" create configmap "${LOCK_CM}" \
    --from-literal=scenario="$1" --from-literal=started="${now}" \
    --from-literal=deadline="$((now + $2 * 60))" --from-literal=ttl="$2" \
    --dry-run=client -o json \
    | jq '.metadata.labels = {"app.kubernetes.io/part-of": "chaos", "chaos.lab/lock": "true"}' \
    | k -n "${NS}" create -f - >/dev/null
}
lock_exists() { [[ "$(lock_state)" == jest ]]; }
# lock_state -> jest|brak|err (err = API nie odpowiedziało; nie mylić z brakiem)
lock_state() {
  local out
  out="$(k -n "${NS}" get configmap "${LOCK_CM}" --ignore-not-found -o name 2>/dev/null)" || { echo err; return; }
  [[ -n "${out}" ]] && echo jest || echo brak
}
# lock_get <klucz> -> wartość. Kody: 0 = blokada jest (wartość może być pusta, gdy brak
# klucza), 1 = blokady nie ma, 2 = błąd API. Błąd API NIGDY nie wygląda jak brak blokady:
# ścieżki, które kasują Job albo blokadę, muszą to rozróżniać.
lock_get() {
  local out
  out="$(k -n "${NS}" get configmap "${LOCK_CM}" --ignore-not-found -o json 2>/dev/null)" || return 2
  [[ -n "${out}" ]] || return 1
  jq -r --arg k "$1" '.data[$k] // empty' <<<"${out}" 2>/dev/null || return 2
}
# lock_set <klucz> <plik|wartość>
lock_set() {
  local patch
  if [[ -f "$2" ]]; then
    patch="$(jq -n --arg k "$1" --rawfile v "$2" '{data: {($k): $v}}')"
  else
    patch="$(jq -n --arg k "$1" --arg v "$2" '{data: {($k): $v}}')"
  fi
  k -n "${NS}" patch configmap "${LOCK_CM}" --type merge -p "${patch}" >/dev/null
}
lock_delete() { k -n "${NS}" delete configmap "${LOCK_CM}" --ignore-not-found --wait=false >/dev/null; }

# -------------------------------------------------------- Job wycofania ------
# CHAOS_SCENARIOS_DIR: katalog scenariuszy (testy wstrzykują tu scenariusz-atrapę).
CHAOS_SCENARIOS_DIR="${CHAOS_SCENARIOS_DIR:-${CHAOS_ROOT}/tools/chaos/scenarios}"
scenario_file() { echo "${CHAOS_SCENARIOS_DIR}/$1.sh"; }

# rearm_undo <s> — po NIEUDANYM undo: blokada zostaje, deadline za 60 s i świeży Job
# chaos-undo (nowy pod czyta nowy deadline), żeby klaster wrócił sam mimo błędu.
rearm_undo() {
  lock_set deadline "$(( $(date +%s) + 60 ))" || return 1
  undo_job_create "$1" 1
}

# undo_job_create <s> <ttl_min> — RBAC z repo, ConfigMap `chaos-scripts`, Job `chaos-undo`,
# na końcu ownerReference skryptów na Job (TTL Joba sprząta też skrypty). Skrypty przed
# Jobem: pod nie czeka na brakujący wolumen (FailedMount w zdarzeniach).
undo_job_create() {
  local s="$1" ttl="$2" tmp uid deadline_s
  deadline_s=$((ttl * 60 + 600))
  k apply -f "${CHAOS_ROOT}/deploy/chaos/undo-rbac.yaml" >/dev/null || return 1
  # Pozostałość po poprzednim przebiegu (zakończony Job czeka na TTL) — Job jest niezmienny.
  undo_job_delete
  tmp="$(mktemp -d "${TMPDIR:-/tmp}/chaos.XXXXXX")" || return 1
  # Punkt wejścia Joba: śpi do `deadline` z blokady (restart poda nie wydłuża ttl),
  # wycofuje, zdejmuje blokadę. Brak blokady albo inny scenariusz = nic do zrobienia.
  cat > "${tmp}/undo-entry.sh" <<EOF
#!/usr/bin/env bash
CHAOS_IN_CLUSTER=1
source /scripts/lib.sh
source /scripts/${s}.sh
set +e
# lock_state z ponowieniami: chwilowy błąd API nie może wyglądać jak brak blokady.
state() { local i st; for i in 1 2 3 4 5 6; do st="\$(lock_state)"; [[ "\${st}" != err ]] && { echo "\${st}"; return; }; sleep 10; done; echo err; }
st="\$(state)"
[[ "\${st}" == err ]] && { echo "API niedostępne — kończę błędem (backoffLimit ponowi)"; exit 1; }
[[ "\${st}" == brak ]] && { echo "brak blokady — nic do wycofania"; exit 0; }
sc="\$(lock_get scenario)"
[[ -n "\${sc}" && "\${sc}" != "${s}" ]] && { echo "blokada scenariusza \${sc}, nie ${s} — nic nie robię"; exit 0; }
read_deadline() { local i d; for i in 1 2 3 4 5 6; do d="\$(lock_get deadline)"; [[ "\${d}" =~ ^[0-9]+\$ ]] && { echo "\${d}"; return 0; }; sleep 10; done; return 1; }
deadline="\$(read_deadline)" || { echo "nie odczytałem deadline z blokady — kończę błędem (backoffLimit ponowi)"; exit 1; }
left=\$(( \${deadline:-0} - \$(date +%s) ))
echo "chaos-undo ${s}: śpię \${left} s do wycofania"
# sleep w tle + wait: TERM (usunięcie Joba przez check/reset) przerywa od razu.
trap 'echo "przerwany (Job usunięty)"; exit 0' TERM INT
if (( left > 0 )); then sleep "\${left}" & wait \$!; fi
st="\$(state)"
[[ "\${st}" == brak ]] && { echo "blokada zniknęła w trakcie (check/reset) — koniec"; exit 0; }
for i in 1 2 3; do
  if undo && lock_delete; then echo "chaos-undo ${s}: wycofane, blokada zdjęta"; exit 0; fi
  echo "próba \${i}: undo albo lock_delete nie przeszło"; sleep 10
done
exit 1
EOF
  k -n "${NS}" create configmap "${SCRIPTS_CM}" \
    --from-file=lib.sh="${CHAOS_ROOT}/tools/chaos/lib.sh" \
    --from-file="${s}.sh"="$(scenario_file "${s}")" \
    --from-file=undo-entry.sh="${tmp}/undo-entry.sh" \
    --dry-run=client -o json \
    | jq '.metadata.labels = {"app.kubernetes.io/part-of": "chaos"}' \
    | k -n "${NS}" create -f - >/dev/null
  local rc=$?
  rm -rf "${tmp}"
  [[ ${rc} -eq 0 ]] || return 1
  k -n "${NS}" create -f - >/dev/null <<EOF || return 1
apiVersion: batch/v1
kind: Job
metadata:
  name: ${UNDO_JOB}
  labels: { app: chaos-undo, app.kubernetes.io/part-of: chaos, chaos.lab/scenario: ${s} }
spec:
  backoffLimit: 2
  ttlSecondsAfterFinished: 3600
  activeDeadlineSeconds: ${deadline_s}
  template:
    metadata:
      labels: { app: chaos-undo, app.kubernetes.io/part-of: chaos }
    spec:
      serviceAccountName: chaos-undo
      automountServiceAccountToken: true
      restartPolicy: Never
      securityContext:
        runAsNonRoot: true
        runAsUser: 65534
        runAsGroup: 65534
        seccompProfile: { type: RuntimeDefault }
      containers:
      - name: undo
        image: ${CHAOS_IMAGE}
        command: ["bash", "/scripts/undo-entry.sh"]
        env:
        - { name: HOME, value: /tmp }
        - { name: CHAOS_IN_CLUSTER, value: "1" }
        resources:
          requests: { cpu: 50m, memory: 64Mi }
          limits: { memory: 128Mi }
        securityContext:
          allowPrivilegeEscalation: false
          readOnlyRootFilesystem: true
          capabilities: { drop: [ALL] }
        volumeMounts:
        - { name: scripts, mountPath: /scripts, readOnly: true }
        - { name: tmp, mountPath: /tmp }
      volumes:
      - name: scripts
        configMap: { name: ${SCRIPTS_CM} }
      - name: tmp
        emptyDir: { sizeLimit: 16Mi }
EOF
  # ownerReference na Job: TTL Joba (ttlSecondsAfterFinished) sprząta też skrypty.
  uid="$(k -n "${NS}" get job "${UNDO_JOB}" -o jsonpath='{.metadata.uid}')" || return 1
  k -n "${NS}" patch configmap "${SCRIPTS_CM}" --type merge -p \
    "{\"metadata\":{\"ownerReferences\":[{\"apiVersion\":\"batch/v1\",\"kind\":\"Job\",\"name\":\"${UNDO_JOB}\",\"uid\":\"${uid}\"}]}}" >/dev/null
}

# undo_job_delete — Job (z podami) i ConfigMap skryptów; brak = sukces.
undo_job_delete() {
  k -n "${NS}" delete job "${UNDO_JOB}" --ignore-not-found --cascade=background --wait=true --timeout=60s >/dev/null 2>&1
  k -n "${NS}" delete configmap "${SCRIPTS_CM}" --ignore-not-found --wait=true --timeout=30s >/dev/null 2>&1
  return 0
}

# undo_job_state -> active|succeeded|failed|brak
undo_job_state() {
  k -n "${NS}" get job "${UNDO_JOB}" -o json 2>/dev/null | jq -r '
    if (.status.succeeded // 0) > 0 then "succeeded"
    elif any(.status.conditions[]?; .type == "Failed" and .status == "True") then "failed"
    else "active" end' 2>/dev/null || echo brak
}

# -------------------------------------------------------------- wyniki -------
score_add() { # score_add <s> <sekundy> <ok|reset>
  [[ "${CHAOS_NO_SCORE:-0}" == "1" ]] && return 0
  printf '%s\t%s\t%s\t%s\n' "$(date '+%Y-%m-%d %H:%M')" "$1" "$2" "$3" >> "${SCORES_FILE}"
}
