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
# Naprawa = ponowny `make k3s-deploy` (nowym albo poprzednim tagiem).
echo "[k3s-deploy] 3/3 aplikacje"
SEARCH_SEL='app.kubernetes.io/component=app,app=search-consumer'
OTHER_APPS_SEL='app.kubernetes.io/component=app,app!=search-consumer'

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

kk apply -f "${WORK}/rendered.yaml" -l "${OTHER_APPS_SEL}" | grep -v unchanged || true
for d in catalog-app outbox-publisher; do kk "${NS[@]}" rollout status "deploy/${d}" --timeout=300s; done

# Manifest ma `replicas: 1`, więc apply przywraca consumer (już nowy obraz).
kk apply -f "${WORK}/rendered.yaml" -l "${SEARCH_SEL}" | grep -v unchanged || true
kk "${NS[@]}" rollout status deploy/search-consumer --timeout=300s

echo "[k3s-deploy] OK — działa wersja ${TAG:0:12}"
kk "${NS[@]}" get deploy,sts -o wide | awk '{print $1, $2, $NF}' | column -t
