#!/usr/bin/env bash
#  tools/k3d/deploy.sh <overlay> — wdraża overlay z deploy/overlays/ na k3d `elastic-g5` (ETAP G5).
#  Kolejność jak w tools/k3s/deploy.sh: infrastruktura -> ES gotowy -> Job migrate -> aplikacje.
#  Job jest niezmienny, więc stary kasujemy przed apply. Zakłada działający klaster
#  (make g5-up), obrazy (make g5-images) i Secrety (tools/k3d/secrets.sh — odpalany tu).
set -uo pipefail
# shellcheck source=tools/k3d/lib.sh
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

OVERLAY="${1:-}"
[ -n "$OVERLAY" ] || die "użycie: deploy.sh <overlay> (np. g5-local)"
DIR="$REPO_ROOT/deploy/overlays/$OVERLAY"
[ -d "$DIR" ] || die "brak overlayu $DIR"
cluster_exists || die "klaster $CLUSTER nie istnieje (make g5-up)"

# Operatory (ECK dla obiektu Elasticsearch) — idempotentnie.
bash "$REPO_ROOT/tools/k3d/install-operators.sh" || die "instalacja operatorów nie powiodła się"
bash "$REPO_ROOT/tools/k3d/secrets.sh" || die "secrets.sh nie powiódł się"

WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT
render "$DIR" > "$WORK/rendered.yaml" || die "render $OVERLAY nie przeszedł"

# apply_sel <selektor> — apply części renderu; błąd apply nie ginie w filtrze.
apply_sel() {
  local out
  out="$(kc apply -f "$WORK/rendered.yaml" -l "$1" 2>&1)" || { echo "$out" >&2; die "apply -l '$1' nie przeszedł"; }
  grep -v unchanged <<<"$out" || true
}

log "1/3 infrastruktura"
apply_sel 'app.kubernetes.io/component notin (app,migrate)'
for sts in postgres rabbitmq; do
  kk rollout status "sts/$sts" --timeout=300s || die "sts/$sts nie wstał"
done
# Czekamy, aż ECK przetworzy aktualną spec (observedGeneration == generation) i ES jest Ready.
es_settled() {
  local gen obs phase health
  read -r gen obs phase health <<<"$(kk get elasticsearch marketplace \
    -o jsonpath='{.metadata.generation} {.status.observedGeneration} {.status.phase} {.status.health}' 2>/dev/null)"
  [ -n "$gen" ] && [ "$gen" = "$obs" ] && [ "$phase" = "Ready" ] && { [ "$health" = "green" ] || [ "$health" = "yellow" ]; }
}
wait_for 900 es_settled || die "ES nie ustabilizował się w 15 min"

log "2/3 Job migrate"
kk delete job migrate --ignore-not-found --wait=true >/dev/null
apply_sel 'app.kubernetes.io/component=migrate'
if ! kk wait job/migrate --for=condition=complete --timeout=600s >/dev/null; then
  kk logs job/migrate --all-containers --tail=50 >&2 || true
  die "Job migrate nie zakończył się sukcesem"
fi

log "3/3 aplikacje"
apply_sel 'app.kubernetes.io/component=app'
for d in $(kk get deploy -l app.kubernetes.io/component=app -o name); do
  kk rollout status "$d" --timeout=300s || die "rollout $d nie skończył się"
done
log "wdrożono overlay $OVERLAY"
