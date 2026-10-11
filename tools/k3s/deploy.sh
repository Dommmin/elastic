#!/usr/bin/env bash
# ============================================================================
#  tools/k3s/deploy.sh [tag] — wdrożenie wersji na k3s (ETAP D2, Task 6)
#
#    make k3s-deploy                 # ostatni udany build CI
#    make k3s-deploy tag=<SHA>       # konkretna wersja / rollback
#
#  Uruchamiany z MACA (kubectl przez tunel). Na serwer nic nie jest kopiowane:
#  manifesty idą przez API Kubernetesa, obrazy klaster pobiera z GHCR sam.
#
#  Kolejność — ta sama zasada co w D1 ("schemat przed kodem"), wyrażona
#  etykietami app.kubernetes.io/component:
#    1. infrastruktura (bez etykiety app/migrate): ES, bazy, kolejka, config
#       -> czekamy na gotowość StatefulSetów i ES green
#    2. Job `migrate` (component=migrate): usuń stary, utwórz nowy, czekaj
#       na Complete — Job jest niezmienny, `apply` z nowym obrazem by padł
#    3. aplikacje (component=app), w trzech ruchach:
#       search-consumer do 0 -> catalog-app + outbox-publisher -> search-consumer
#       (dlaczego — komentarz przy kroku 3/3)
# ============================================================================
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
TAG="${1:-$("${ROOT}/tools/vps/latest-image-tag.sh")}"
[[ "${TAG}" =~ ^[0-9a-f]{40}$ ]] || { echo "Tag ma być pełnym SHA (40 znaków hex)." >&2; exit 1; }

kk() { /opt/homebrew/bin/kubectl --kubeconfig "${HOME}/.kube/elastic-vps.yaml" --request-timeout=60s "$@"; }
NS=(-n marketplace)

nc -z 127.0.0.1 26443 2>/dev/null || ssh -fN elastic-vps-k8s

# Tag w KOPII manifestów — w repo zostaje wersja referencyjna.
WORK="$(mktemp -d)"; trap 'rm -rf "${WORK}"' EXIT
cp -R "${ROOT}/deploy/k8s/." "${WORK}/"
sed -i '' -E "s/^([[:space:]]*newTag:).*/\1 ${TAG}/" "${WORK}/kustomization.yaml"
kk kustomize "${WORK}" > "${WORK}/rendered.yaml"

echo "[k3s-deploy] 1/3 infrastruktura (${TAG:0:12})"
kk apply -f "${WORK}/rendered.yaml" -l 'app.kubernetes.io/component notin (app,migrate)' | grep -v unchanged || true
for sts in postgres rabbitmq redis; do kk "${NS[@]}" rollout status "sts/${sts}" --timeout=300s; done
# NIE wystarczy "health=green": zaraz po apply ECK jeszcze nie zaczął zmian,
# więc green pochodzi z POPRZEDNIEGO stanu — i aplikacje wdrażały się w trakcie
# rolling restartu ES (RUNBOOK #038). Czekamy, aż operator potwierdzi, że
# przetworzył nową spec (observedGeneration == generation) i skończył (Ready).
es_settled() {
  local s; s="$(kk "${NS[@]}" get elasticsearch marketplace \
    -o jsonpath='{.metadata.generation} {.status.observedGeneration} {.status.phase} {.status.health}')"
  read -r gen obs phase health <<<"${s}"
  [ "${gen}" = "${obs}" ] && [ "${phase}" = "Ready" ] && [ "${health}" = "green" ]
}
for _ in $(seq 1 90); do es_settled && break; sleep 10; done
es_settled || { echo "[k3s-deploy] ES nie ustabilizował się w 15 min" >&2; exit 1; }

echo "[k3s-deploy] 2/3 Job migrate"
kk "${NS[@]}" delete job migrate --ignore-not-found --wait=true
kk apply -f "${WORK}/rendered.yaml" -l 'app.kubernetes.io/component=migrate'
if ! kk "${NS[@]}" wait job/migrate --for=condition=complete --timeout=600s; then
  echo "[k3s-deploy] migracje NIE przeszły — logi:" >&2
  kk "${NS[@]}" logs job/migrate --all-containers --tail=50 >&2 || true
  exit 1
fi
kk "${NS[@]}" logs job/migrate --all-containers --tail=3 | sed 's/^/    /'

# Bez mieszania wersji catalog i search-consumer. Kontrakt projekcji zmienia
# się razem z oboma (np. Panel [2/8]: pole projected_at_us), a każda
# kombinacja stara+nowa psuje zdarzenia:
#   - stary consumer + nowy catalog: nowe pole trafia do _source, mapowanie
#     `dynamic: strict` odrzuca zapis (400) -> wiadomość do `failed`;
#   - nowy consumer + stary catalog: brak pola -> ponowienia przez ~7 s
#     (retry_strategy), a rollout catalogu trwa dłużej -> `failed`.
# Dlatego consumer stoi, dopóki catalog nie skończy rolloutu. Zdarzenia z tego
# okna czekają w RabbitMQ (search.product.sync) — po to jest kolejka — i nowy
# consumer zjada je po starcie. Gdy rollout catalogu padnie, skrypt kończy się
# z consumerem na 0: lepiej, żeby zdarzenia czekały, niż szły do `failed`.
# Naprawa = ponowny `make k3s-deploy` (nowym albo poprzednim tagiem); każdy
# błąd od tego miejsca kończy się komunikatem step3_fail, nigdy cichym "OK".
echo "[k3s-deploy] 3/3 aplikacje"
SEARCH_SEL='app.kubernetes.io/component=app,app=search-consumer'
OTHER_APPS_SEL='app.kubernetes.io/component=app,app!=search-consumer'

# Od skalowania do 0 każdy błąd w tym kroku może zostawić consumer na 0 replik:
# wyszukiwarka stoi, zdarzenia rosną w RabbitMQ. Nie może się to skończyć
# cichym "OK", więc każda porażka przechodzi tędy z instrukcją naprawy.
step3_fail() {
  echo "[k3s-deploy] BŁĄD w kroku 3/3: $1" >&2
  echo "    search-consumer może stać na 0 replik — ES nie dostaje zmian," >&2
  echo "    zdarzenia czekają w RabbitMQ (nic nie ginie). Naprawa (najpierw make k3s-tunnel):" >&2
  echo "      make k3s-deploy tag=${TAG}    # albo poprzedni tag" >&2
  echo "    Ręcznie tylko, gdy catalog-app i search-consumer mają TEN SAM obraz" >&2
  echo "    (inaczej mieszanie wersji -> zdarzenia w \`failed\`):" >&2
  echo "      make k3s k=\"-n marketplace get deploy catalog-app search-consumer -o wide\"" >&2
  echo "      make k3s k=\"-n marketplace scale deploy/search-consumer --replicas=1\"" >&2
  exit 1
}
# `grep -v unchanged` ma exit 1, gdy wszystko jest unchanged, a `|| true` za
# potokiem połykało też błąd samego apply (tunel, timeout 60 s). Dlatego
# najpierw wynik apply, potem filtr.
apply_apps() {
  local out
  out="$(kk apply -f "${WORK}/rendered.yaml" -l "$1" 2>&1)" \
    || { echo "${out}" >&2; step3_fail "kubectl apply -l '$1' nie przeszedł"; }
  grep -v unchanged <<<"${out}" || true
}

if kk "${NS[@]}" get deploy/search-consumer >/dev/null 2>&1; then
  echo "    search-consumer -> 0 replik (zdarzenia czekają w RabbitMQ)"
  kk "${NS[@]}" scale deploy/search-consumer --replicas=0
  for _ in $(seq 1 60); do
    [ -z "$(kk "${NS[@]}" get pods -l app=search-consumer -o name)" ] && break
    sleep 2
  done
  [ -z "$(kk "${NS[@]}" get pods -l app=search-consumer -o name)" ] \
    || { echo "[k3s-deploy] pody search-consumer nie zniknęły w 2 min" >&2; exit 1; }
fi

apply_apps "${OTHER_APPS_SEL}"
for d in catalog-app outbox-publisher; do
  kk "${NS[@]}" rollout status "deploy/${d}" --timeout=300s || step3_fail "rollout ${d} nie skończył się"
done

# Manifest ma `replicas: 1`, więc apply przywraca consumer (już nowy obraz).
apply_apps "${SEARCH_SEL}"
kk "${NS[@]}" rollout status deploy/search-consumer --timeout=300s \
  || step3_fail "rollout search-consumer nie skończył się"
# `rollout status` uznaje Deployment z 0 replik za wdrożony — sprawdzamy wprost,
# że consumer naprawdę działa w liczbie replik z manifestu.
state="$(kk "${NS[@]}" get deploy/search-consumer \
  -o jsonpath='{.spec.replicas} {.status.readyReplicas}')" \
  || step3_fail "nie da się odczytać stanu search-consumer"
read -r want ready <<<"${state}"
[ "${want:-0}" -ge 1 ] && [ "${ready:-0}" = "${want}" ] \
  || step3_fail "search-consumer gotowy ${ready:-0}/${want:-0} (manifest: replicas >= 1)"

echo "[k3s-deploy] OK — działa wersja ${TAG:0:12}"
kk "${NS[@]}" get deploy,sts -o wide | awk '{print $1, $2, $NF}' | column -t
