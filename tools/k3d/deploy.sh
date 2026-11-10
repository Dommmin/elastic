#!/usr/bin/env bash
#  tools/k3d/deploy.sh <overlay> — wdraża overlay z deploy/overlays/ na k3d `elastic-g5` (ETAP G5).
#  Kolejność jak w tools/k3s/deploy.sh: infrastruktura -> ES gotowy -> Job migrate -> aplikacje.
#  Job jest niezmienny, więc stary kasujemy przed apply. Zakłada działający klaster
#  (make g5-up), obrazy (make g5-images) i Secrety (tools/k3d/secrets.sh — odpalany tu).
set -uo pipefail
# shellcheck source=tools/k3d/lib.sh
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
# shellcheck source=tools/k3d/counts.sh
. "$(dirname "${BASH_SOURCE[0]}")/counts.sh"   # old_state / old_queues_total (strażnik prune)

OVERLAY="${1:-}"
[ -n "$OVERLAY" ] || die "użycie: deploy.sh <overlay> (np. g5-local)"
DIR="$REPO_ROOT/deploy/overlays/$OVERLAY"
[ -d "$DIR" ] || die "brak overlayu $DIR"
cluster_exists || die "klaster $CLUSTER nie istnieje (make g5-up)"

# Preflight: ipBlock polityki rabbitmq-ha-egress-api musi pokrywać żywą podsieć sieci k3d,
# inaczej peer discovery RabbitMQ traci dostęp do API (objaw mylący: węzły nie dołączają).
preflight_subnet() {
  local live cidr
  live="$(net_subnet)"
  [ -n "$live" ] || die "brak sieci Dockera $G5_NET (make g5-up)"
  # Fail closed: błąd renderu/rubiego albo brak polityki w overlayu = die (nie „pominięty”).
  local rendered
  rendered="$(render "$DIR")" || die "preflight podsieci: render $OVERLAY nie przeszedł"
  cidr="$(printf '%s\n' "$rendered" | ruby -ryaml -e 'YAML.load_stream($stdin.read).compact.each { |d| puts d.dig("spec","egress",0,"to",0,"ipBlock","cidr") if d["kind"]=="NetworkPolicy" && d.dig("metadata","name")=="rabbitmq-ha-egress-api" }')" \
    || die "preflight podsieci: nie udało się odczytać ipBlock z renderu"
  [ -n "$cidr" ] || die "preflight podsieci: brak rabbitmq-ha-egress-api (albo ipBlock) w renderze overlayu $OVERLAY"
  [ "$cidr" = "$live" ] || die "ipBlock rabbitmq-ha-egress-api ($cidr) != podsieć sieci $G5_NET ($live) — popraw deploy/k8s/rabbitmq-cluster/networkpolicies.yaml albo odtwórz klaster (make g5-down g5-up)"
}
preflight_subnet

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

# Shovele migracji (component=migration) celowo poza wszystkimi etapami: stosuje je
# tools/k3d/migrate.sh w kroku 3 (po przełączeniu wydawcy na rabbitmq-ha).
log "1/3 infrastruktura"
apply_sel 'app.kubernetes.io/component notin (app,migrate,migration)'
# StatefulSety z renderu (g5-local-ha nie ma już starego rabbitmq).
STS="$(ruby -ryaml -e 'YAML.load_stream(File.read(ARGV[0])).compact.each { |d| puts d.dig("metadata","name") if d["kind"] == "StatefulSet" }' "$WORK/rendered.yaml")" \
  || die "nie udało się odczytać StatefulSetów z renderu"
[ -n "$STS" ] || die "brak StatefulSetów w renderze $OVERLAY"
for sts in $STS; do
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

# RabbitmqCluster nie ma StatefulSetu w renderze (tworzy go operator), więc rollout status go nie
# widzi: czekamy jawnie na AllReplicasReady i na Ready=True wszystkich CR-ów topologii. Bez tego
# migrate.sh startuje na klastrze, który jeszcze się formuje (wyszło na przebiegu od zera).
if grep -q '^kind: RabbitmqCluster' "$WORK/rendered.yaml"; then
  wait_for 600 allready || die "rabbitmq-ha nie ma AllReplicasReady w 10 min"
  # Permission pojawia się razem z User; operator topologii potrafi spróbować przed powstaniem
  # użytkownika w brokerze (`vhost_or_user_not_found`) i potem długo nie ponawia (RUNBOOK #108).
  # Zmiana adnotacji to zdarzenie aktualizacji, które wyzwala kolejną próbę.
  TOPO="exchanges.rabbitmq.com,queues.rabbitmq.com,bindings.rabbitmq.com,users.rabbitmq.com,permissions.rabbitmq.com"
  topology_not_ready() {
    kk get "$TOPO" -o jsonpath='{range .items[*]}{.kind}.rabbitmq.com/{.metadata.name} {.status.conditions[?(@.type=="Ready")].status}{"\n"}{end}' \
      | awk '$2 != "True" { print tolower($1) }'
  }
  topology_settled=
  for _ in $(seq 1 20); do
    nr="$(topology_not_ready)" || die "nie udało się odczytać CR-ów topologii"
    if [ -z "$nr" ]; then topology_settled=1; break; fi
    for obj in $nr; do kk annotate "$obj" "g5-nudge=$(date +%s)" --overwrite >/dev/null || die "annotate $obj nie powiodło się"; done
    sleep 15
  done
  [ -n "$topology_settled" ] || die "CR-y topologii nie mają Ready=True w 5 min: $(topology_not_ready | tr '\n' ' ')"
fi

log "2/3 Job migrate"
kk delete job migrate --ignore-not-found --wait=true >/dev/null
apply_sel 'app.kubernetes.io/component=migrate'
if ! wait_job migrate 600; then
  kk logs job/migrate --all-containers --tail=50 >&2 || true
  die "Job migrate nie zakończył się sukcesem"
fi

log "3/3 aplikacje"
apply_sel 'app.kubernetes.io/component=app'
DEPLOYS="$(kk get deploy -l app.kubernetes.io/component=app -o name)" || die "nie udało się pobrać listy Deploymentów"
[ -n "$DEPLOYS" ] || die "brak Deploymentów aplikacji po apply"
for d in $DEPLOYS; do
  kk rollout status "$d" --timeout=300s || die "rollout $d nie skończył się"
done
# prune.txt overlayu: obiekty do skasowania (apply sam niczego nie usuwa).
# g5-local-ha kasuje tak stary RabbitMQ i Shovele migracji. Strażnik: stary węzeł
# z niepustymi kolejkami NIE jest kasowany (najpierw tools/k3d/migrate.sh).
PRUNE="$DIR/prune.txt"
if [ -f "$PRUNE" ]; then
  if grep -qx 'statefulset/rabbitmq' "$PRUNE"; then
    st="$(old_state)" || die "nie da się sprawdzić starego rabbitmq przed kasowaniem"
    if [ "$st" = present ]; then
      left="$(old_queues_total)" || die "nie da się policzyć wiadomości w starym rabbitmq — nie kasuję"
      is_num "$left" || die "nieczytelna liczba wiadomości w starym rabbitmq ($left) — nie kasuję"
      [ "$left" -eq 0 ] || die "stary rabbitmq ma $left wiadomości w kolejkach — najpierw tools/k3d/migrate.sh"
    fi
  fi
  log "prune ($PRUNE)"
  grep -v '^[[:space:]]*\(#\|$\)' "$PRUNE" | while read -r obj; do
    kk delete "$obj" --ignore-not-found --wait=true | sed 's/^/    /' || exit 1
  done || die "prune nie przeszedł"
fi
log "wdrożono overlay $OVERLAY"
