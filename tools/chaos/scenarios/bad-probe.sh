# shellcheck shell=bash
# shellcheck disable=SC2034  # SUMMARY/ALERT/LIMIT_MIN czyta chaos.sh
#  bad-probe — readiness `search-metrics` przestawiona na ścieżkę, której aplikacja nie zna.
#
#  Przy 1 replice i maxUnavailable 0 (25% zaokrąglone w dół) STARY pod dalej serwuje,
#  a pod nowego ReplicaSetu wisi 0/1: rollout stoi, metryki dalej są (stary pod),
#  alert KubePodNotReady przychodzi dopiero po 15 min. Spec § 10, ruling 8.

SUMMARY="Rollout search-metrics stoi: nowy pod wisi 0/1, a kubectl rollout status nie kończy się"
ALERT="KubePodNotReady"
LIMIT_MIN=15

BP_DEPLOY="search-metrics"
BP_BAD_PATH="/healthz-old"

targets() { echo "deployment/${BP_DEPLOY}"; }

# Indeks i nazwa kontenera z readiness httpGet (z Deploymentu, nie na sztywno).
bp_container() {
  k -n "${NS}" get deployment "${BP_DEPLOY}" -o json | jq -r '
    [.spec.template.spec.containers | to_entries[] | select(.value.readinessProbe.httpGet != null)][0]
    | "\(.key) \(.value.name)"'
}

bp_readiness_path() {
  k -n "${NS}" get deployment "${BP_DEPLOY}" -o json 2>/dev/null \
    | jq -r '[.spec.template.spec.containers[] | .readinessProbe.httpGet.path // empty][0] // empty'
}

# Pod najnowszego ReplicaSetu (rewizja = adnotacja Deploymentu) działa, ale nie jest gotowy.
bp_new_pod_unready() {
  local rev hash
  rev="$(k -n "${NS}" get deployment "${BP_DEPLOY}" -o jsonpath='{.metadata.annotations.deployment\.kubernetes\.io/revision}')"
  [[ -n "${rev}" ]] || return 1
  hash="$(k -n "${NS}" get rs -l "app=${BP_DEPLOY}" -o json | jq -r --arg r "${rev}" '
    .items[] | select(.metadata.annotations["deployment.kubernetes.io/revision"] == $r)
    | .metadata.labels["pod-template-hash"]' | head -1)"
  [[ -n "${hash}" ]] || return 1
  k -n "${NS}" get pods -l "app=${BP_DEPLOY},pod-template-hash=${hash}" -o json | jq -e '
    any(.items[]; .status.phase == "Running"
        and any(.status.containerStatuses[]?; .started == true and .ready == false))' >/dev/null
}

break() {
  local idx name
  read -r idx name <<<"$(bp_container)"
  [[ -n "${name:-}" && "${name}" != null ]] || { echo "brak kontenera z readiness httpGet w ${BP_DEPLOY}" >&2; return 1; }
  k -n "${NS}" patch deployment "${BP_DEPLOY}" --type json -p "[
    {\"op\":\"test\",\"path\":\"/spec/template/spec/containers/${idx}/name\",\"value\":\"${name}\"},
    {\"op\":\"replace\",\"path\":\"/spec/template/spec/containers/${idx}/readinessProbe/httpGet/path\",\"value\":\"${BP_BAD_PATH}\"}
  ]" >/dev/null || return 1
  # Czekamy na objaw: pod nowego ReplicaSetu wystartował i nie jest gotowy.
  wait_for 180 bp_new_pod_unready || { echo "pod nowego ReplicaSetu nie pojawił się jako 0/1 w 180 s" >&2; return 1; }
}

bp_ready() { [[ "$(deploy_ready "${BP_DEPLOY}")" == tak ]]; }

check() {
  local path code ready_eps
  # Rollout po naprawie potrzebuje chwili (nowy pod musi przejść readiness).
  wait_for 60 bp_ready || true
  expect "Deployment ${BP_DEPLOY}: rollout skończony, wszystkie repliki gotowe" "tak" "$(deploy_ready "${BP_DEPLOY}")"
  path="$(bp_readiness_path)"
  expect "readiness nie wskazuje ${BP_BAD_PATH}" "inna niż ${BP_BAD_PATH}" \
    "$([[ "${path}" == "${BP_BAD_PATH}" || -z "${path}" ]] && echo "${path}" || echo "inna niż ${BP_BAD_PATH}")"
  code="$(k -n "${NS}" exec "deploy/${BP_DEPLOY}" -- curl -s -o /dev/null -m 5 -w '%{http_code}' "http://localhost:80${path:-/}" 2>/dev/null)"
  expect "ścieżka readiness ${path:-?} odpowiada 200 z wnętrza poda" "200" "${code}"
  ready_eps="$(k -n "${NS}" get endpointslice -l "kubernetes.io/service-name=${BP_DEPLOY}" -o json 2>/dev/null \
    | jq '[.items[].endpoints[]? | select(.conditions.ready == true)] | length' 2>/dev/null)"
  expect_match "EndpointSlice usługi ${BP_DEPLOY} ma gotowy endpoint" '^[1-9]' "${ready_eps:-0}"
}

undo() { snapshot_restore; }
