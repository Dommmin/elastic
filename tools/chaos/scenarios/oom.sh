# shellcheck shell=bash
# shellcheck disable=SC2034  # SUMMARY/ALERT/LIMIT_MIN czyta chaos.sh
#  oom — limit pamięci kontenera `worker` (catalog-worker) zbity do 40Mi.
#
#  Worker (`queue:work`) potrzebuje więcej niż 40 MiB już na starcie frameworka, więc
#  jądro zabija go (OOMKilled, kod 137), kubelet restartuje, pod wchodzi w CrashLoopBackOff.
#  Sklep dalej serwuje strony (catalog-app), ale kolejka jobów (blokady sprzedawców,
#  zamówienia, saga) stoi. Alert KubePodCrashLooping przychodzi po kilku minutach.

SUMMARY="Joby w kolejce stoją: pod catalog-worker restartuje się w kółko, a kolejka jobów rośnie"
ALERT="KubePodCrashLooping"
LIMIT_MIN=8

OOM_DEPLOY="catalog-worker"
OOM_CONTAINER="worker"
OOM_BAD_LIMIT="40Mi"
# Request nie może przekraczać limitu (API odrzuciłoby zmianę), a oryginał to 64Mi.
OOM_BAD_REQUEST="32Mi"
OOM_MIN_MIB=128

targets() { echo "deployment/${OOM_DEPLOY}"; }

# oom_mib <ilość k8s> -> pełne MiB (Ki, Mi, Gi, Ti, k, M, G, T albo bajty).
oom_mib() {
  awk -v q="$1" 'BEGIN {
    if (match(q, /^[0-9.]+/) == 0) { print -1; exit }
    n = substr(q, 1, RLENGTH) + 0; u = substr(q, RLENGTH + 1)
    if (u == "Ki") b = n * 1024
    else if (u == "Mi") b = n * 1048576
    else if (u == "Gi") b = n * 1073741824
    else if (u == "Ti") b = n * 1099511627776
    else if (u == "k") b = n * 1000
    else if (u == "M") b = n * 1000000
    else if (u == "G") b = n * 1000000000
    else if (u == "T") b = n * 1000000000000
    else if (u == "") b = n
    else { print -1; exit }
    printf "%d\n", b / 1048576
  }'
}

oom_limit() { # limit pamięci kontenera worker (pusty, gdy brak)
  k -n "${NS}" get deployment "${OOM_DEPLOY}" -o json 2>/dev/null \
    | jq -r --arg c "${OOM_CONTAINER}" '.spec.template.spec.containers[] | select(.name == $c) | .resources.limits.memory // empty'
}

# Któryś pod catalog-worker ma w lastState zabicie przez OOM.
oom_killed() {
  k -n "${NS}" get pods -l "app=${OOM_DEPLOY}" -o json | jq -e '
    any(.items[].status.containerStatuses[]?; .lastState.terminated.reason == "OOMKilled")' >/dev/null
}

break() {
  k -n "${NS}" set resources "deployment/${OOM_DEPLOY}" -c "${OOM_CONTAINER}" \
    --limits="memory=${OOM_BAD_LIMIT}" --requests="memory=${OOM_BAD_REQUEST}" >/dev/null || return 1
  wait_for 180 oom_killed || { echo "żaden pod ${OOM_DEPLOY} nie dostał OOMKilled w 180 s" >&2; return 1; }
}

oom_ready() { [[ "$(deploy_ready "${OOM_DEPLOY}")" == tak ]]; }

check() {
  local lim mib
  # Po naprawie Recreate zastępuje pod: czekamy, aż nowy będzie gotowy.
  wait_for 120 oom_ready || true
  expect "Deployment ${OOM_DEPLOY}: rollout skończony, pod gotowy" "tak" "$(deploy_ready "${OOM_DEPLOY}")"
  lim="$(oom_limit)"
  mib="$(oom_mib "${lim:-0}")"
  expect_hidden "catalog-worker ma warunki do stabilnej pracy" \
    "limit pamięci kontenera ${OOM_CONTAINER} ≥ ${OOM_MIN_MIB}Mi (jest: ${lim:-brak})" "tak" \
    "$([[ "${mib}" =~ ^[0-9]+$ && "${mib}" -ge ${OOM_MIN_MIB} ]] && echo tak || echo nie)"
  # Tylko zakończenia z błędem (exitCode ≠ 0): planowy restart queue:work co godzinę to Completed.
  expect "brak awaryjnych restartów ${OOM_DEPLOY} w ostatnich 2 min" "0" "$(recent_restarts "${OOM_DEPLOY}" 120)"
}

undo() { snapshot_restore; }
