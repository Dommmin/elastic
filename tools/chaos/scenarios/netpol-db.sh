# shellcheck shell=bash
# shellcheck disable=SC2034  # SUMMARY/ALERT/LIMIT_MIN czyta chaos.sh
#  netpol-db — z NetworkPolicy `postgres-ingress` znika `catalog-worker` z listy klientów
#  wpuszczanych na 5432 (ktoś „porządkował” listę).
#
#  Zmieniamy JEDEN element listy `app In [...]`, reszta polityki zostaje. Polityka nie
#  zrywa NAWIĄZANYCH połączeń (kube-router przepuszcza ruch ESTABLISHED): działający
#  worker trzymałby swoje połączenie z Postgresem aż do planowego restartu
#  (`--max-time=3600`). Dlatego break po zmianie polityki restartuje pod workera
#  (delete poda, Deployment tworzy nowy) — jak planowy restart albo wdrożenie. Nowy
#  worker dostaje `Connection refused` i wpada w CrashLoopBackOff; sklep (catalog-app)
#  i baza działają. CatalogFailedJobs (failed_jobs > 0) tu nie zapłonie: worker nie
#  pobiera jobów, więc żaden nie trafia do failed_jobs (sprawdzone na klastrze).

SUMMARY="Joby w kolejce stoją: pod catalog-worker po restarcie nie wstaje i restartuje się w kółko"
ALERT="KubePodCrashLooping"
LIMIT_MIN=8

ND_POLICY="postgres-ingress"
ND_CLIENT="catalog-worker"
ND_PATH="/spec/ingress/0/from/0/podSelector/matchExpressions/0"

targets() { echo "networkpolicy/${ND_POLICY}"; }

# Test połączenia (wspólny dla break i check): świeże TCP z poda workera do postgres:5432.
nd_conn() {
  k -n "${NS}" exec "deploy/${ND_CLIENT}" -c worker -- \
    php -r 'exit(@fsockopen("postgres",5432,$e,$s,3)?0:1);' >/dev/null 2>&1
}
nd_conn_blocked() { ! nd_conn; }

# Lista wartości z pierwszego matchExpressions (JSON, zwarty).
nd_values() {
  k -n "${NS}" get networkpolicy "${ND_POLICY}" -o json 2>/dev/null \
    | jq -c '.spec.ingress[0].from[0].podSelector.matchExpressions[0].values // empty'
}

# Ile razy klient jest na liście (pusty wynik = brak polityki/listy).
nd_count() { nd_values | jq --arg c "${ND_CLIENT}" '[.[] | select(. == $c)] | length' 2>/dev/null; }

break() {
  local before after key
  before="$(nd_values)"
  key="$(k -n "${NS}" get networkpolicy "${ND_POLICY}" -o jsonpath='{.spec.ingress[0].from[0].podSelector.matchExpressions[0].key}')"
  [[ "${key}" == app && -n "${before}" ]] || { echo "${ND_POLICY}: pierwsze matchExpressions nie jest listą app In [...]" >&2; return 1; }
  [[ "$(jq --arg c "${ND_CLIENT}" '[.[] | select(. == $c)] | length' <<<"${before}")" == 1 ]] \
    || { echo "${ND_POLICY}: ${ND_CLIENT} nie występuje na liście dokładnie raz" >&2; return 1; }
  after="$(jq -c --arg c "${ND_CLIENT}" 'map(select(. != $c))' <<<"${before}")"
  # `test` całej listy: zmiana między odczytem a zapisem = patch odrzucony, nic nie zepsute.
  k -n "${NS}" patch networkpolicy "${ND_POLICY}" --type json -p "[
    {\"op\":\"test\",\"path\":\"${ND_PATH}/values\",\"value\":${before}},
    {\"op\":\"replace\",\"path\":\"${ND_PATH}/values\",\"value\":${after}}
  ]" >/dev/null || return 1
  # Polityka weszła: nowe połączenie z poda workera jest odrzucane (kube-router, kilka s).
  wait_for 90 nd_conn_blocked || { echo "połączenie catalog-worker -> postgres:5432 nadal działa po 90 s" >&2; return 1; }
  # Restart workera (bez zmiany Deploymentu): stare połączenie znika, nowe nie powstaje.
  k -n "${NS}" delete pod -l "app=${ND_CLIENT}" --wait=false >/dev/null || return 1
  wait_for 180 nd_crashing || { echo "nowy pod ${ND_CLIENT} nie wywrócił się w 180 s" >&2; return 1; }
}

# Pod workera utworzony po break (bez deletionTimestamp) już się wywrócił.
nd_crashing() {
  k -n "${NS}" get pods -l "app=${ND_CLIENT}" -o json | jq -e '
    any(.items[]; .metadata.deletionTimestamp == null
      and any(.status.containerStatuses[]?;
            .restartCount >= 1 or .state.waiting.reason == "CrashLoopBackOff"))' >/dev/null
}

nd_healthy() { [[ "$(deploy_ready "${ND_CLIENT}")" == tak ]] && nd_conn; }

check() {
  # Po naprawie polityki worker wstaje dopiero przy następnej próbie kubeleta
  # (CrashLoopBackOff: do 5 min); `kubectl delete pod` skraca to do sekund.
  wait_for 300 nd_healthy || true
  expect "Deployment ${ND_CLIENT}: pod gotowy" "tak" "$(deploy_ready "${ND_CLIENT}")"
  expect "catalog-worker otwiera połączenie z postgres:5432" "tak" "$(nd_conn && echo tak || echo nie)"
  expect_hidden "ruch do bazy jak przed awarią" "${ND_POLICY}: ${ND_CLIENT} na liście klientów 5432" "tak" \
    "$([[ "$(nd_count)" =~ ^[1-9] ]] && echo tak || echo nie)"
}

undo() { snapshot_restore; }
