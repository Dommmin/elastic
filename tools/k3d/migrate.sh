#!/usr/bin/env bash
#  tools/k3d/migrate.sh [--load=N] [--pause=S] [--interactive]
#  Migracja sklepu ze starego RabbitMQ (StatefulSet `rabbitmq`) na `rabbitmq-ha`
#  przez Shovel, z dowodem liczbami (ETAP G5, spec sekcja 5, kroki 2–6).
#
#  Start: wdrożony overlay g5-local (krok 1: oba klastry działają, aplikacje na starym).
#    2. wydawca na nowy klaster: catalog-app i outbox-publisher dostają host rabbitmq-ha
#       i hasło użytkownika z rabbitmq-ha-marketplace-user (kk patch --type=json plikami
#       overlayu g5-local-ha) + rollout. Od tej chwili nowe zdarzenia idą tylko do rabbitmq-ha.
#    3. Shovel CR-y (deploy/k8s/rabbitmq-cluster/migration) przenoszą zaległości starych
#       kolejek do rabbitmq-ha; czekamy, aż stare kolejki są puste.
#    4. konsument na nowy klaster: kk patch (MESSENGER_TRANSPORT_DSN z klucza _HA, plik
#       overlayu) + rollout; resztki, które stary konsument oddał przy wyłączaniu (i kopie
#       wracające z delay_*), dosuwa nowy przebieg Shoveli.
#    5. sprawdzenie: outbox opublikowany, processed_events == outbox_total,
#       es_count == produkty w bazie katalogu, stare kolejki = 0, DLQ = 0 (oba klastry).
#    6. repo = prawda: kc diff overlayu g5-local-ha bez różnic (poza Jobem migrate,
#       odtwarzanym przy każdym deploy.sh) — `make g5-deploy o=g5-local-ha` usunie
#       już tylko stary RabbitMQ (prune.txt).
#  Po każdym kroku linia tools/k3d/counts.sh (kolumny opisane tam).
#
#  --load=N       migracja pod ruchem: przed krokiem 2 seed --n=N --fresh (N produktów =
#                 N zdarzeń zaległych w kolejce; --fresh czyści outbox i produkty katalogu,
#                 liczenie od zera), potem ruch sklepu (seed.sh --traffic: product.updated
#                 ~20/s) od przed krokiem 2 do po kroku 4 — skrypt sprawdza, że trwał.
#  --pause=S      pauza po każdym kroku (domyślnie 5 s), --interactive: czeka na Enter.
#  Kod 0 tylko, gdy liczby z kroku 5 się zgadzają i diff z kroku 6 jest pusty.
#  Zgodność: bash 3.2.
set -uo pipefail
# shellcheck source=tools/k3d/counts.sh
. "$(dirname "${BASH_SOURCE[0]}")/counts.sh"

LOAD=0
PAUSE=5
INTERACTIVE=""
for a in "$@"; do
  case "$a" in
    --load=*) LOAD="${a#--load=}" ;;
    --pause=*) PAUSE="${a#--pause=}" ;;
    --interactive) INTERACTIVE=1 ;;
    *) die "nieznany argument: $a (użycie: migrate.sh [--load=N] [--pause=S] [--interactive])" ;;
  esac
done
is_num "$LOAD" || die "--load musi być liczbą"
is_num "$PAUSE" || die "--pause musi być liczbą"
cluster_exists || die "klaster $CLUSTER nie istnieje (make g5-up)"

LOCAL="$REPO_ROOT/deploy/overlays/g5-local"
HA="$REPO_ROOT/deploy/overlays/g5-local-ha"
CONSUMER_PATCH="$HA/search-consumer-env.yaml"
WORK="$(mktemp -d)"
TRAFFIC_ON=""
cleanup() {
  # Ruch sklepu nie może przeżyć skryptu (przerwanie, die): inaczej liczby nigdy się nie zbiegną.
  if [ -n "$TRAFFIC_ON" ]; then kk delete job marketplace-traffic --ignore-not-found --wait=false >/dev/null 2>&1; fi
  rm -rf "$WORK"
}
trap cleanup EXIT

render "$LOCAL" > "$WORK/local.yaml" || die "render g5-local nie przeszedł"
render "$HA" > "$WORK/ha.yaml" || die "render g5-local-ha nie przeszedł"
SHOVELS="$(ruby -ryaml -e 'YAML.load_stream(File.read(ARGV[0])).compact.each { |d| puts d.dig("metadata","name") if d["kind"] == "Shovel" }' "$WORK/local.yaml")" \
  || die "nie udało się odczytać Shoveli z renderu g5-local"
[ -n "$SHOVELS" ] || die "brak Shoveli w renderze g5-local"

COUNTS=""
show() {   # show <etykieta> — linia counts.sh z etykietą; COUNTS = sama linia
  COUNTS="$(counts_line)" || die "counts: nie udało się ustalić liczb ($1)"
  printf '[g5] counts %-22s %s\n' "$1" "$COUNTS"
}
col() { printf '%s\n' "$COUNTS" | awk -v i="$1" '{ print $i }'; }
step_pause() {
  if [ -n "$INTERACTIVE" ]; then printf '[g5] Enter = dalej… '; read -r _; else sleep "$PAUSE"; fi
}
traffic_active() { [ "$(kk get job marketplace-traffic -o jsonpath='{.status.active}' 2>/dev/null)" = "1" ]; }

# --- warunki startu (krok 1 = wdrożony g5-local) ---
[ "$(old_state)" = present ] || die "brak starego sts/rabbitmq — migracja już zrobiona albo wdrożony nie ten overlay"
kk rollout status sts/rabbitmq --timeout=60s >/dev/null || die "stary rabbitmq nie jest gotowy"
[ "$(kk get rabbitmqcluster rabbitmq-ha -o jsonpath='{.status.conditions[?(@.type=="AllReplicasReady")].status}')" = "True" ] \
  || die "rabbitmq-ha nie ma AllReplicasReady (make g5-deploy o=g5-local)"
[ "$(kk get secret rabbitmq-shovel-uris -o jsonpath='{.metadata.labels.rabbitmq\.com/topology-operator}')" = "true" ] \
  || die "Secret rabbitmq-shovel-uris bez etykiety topology-operator (tools/k3d/secrets.sh)"
show "start"

if [ "$LOAD" -gt 0 ]; then
  log "obciążenie: seed --n=$LOAD --fresh (zaległości w kolejce), potem ruch sklepu"
  bash "$REPO_ROOT/tools/k3d/seed.sh" --n="$LOAD" --fresh || die "seed nie przeszedł"
  TRAFFIC_ON=1
  bash "$REPO_ROOT/tools/k3d/seed.sh" --traffic || die "ruch sklepu nie wystartował"
  sleep 10
  show "obciążenie trwa"
fi

# --- krok 2: wydawca (i /health katalogu) na rabbitmq-ha ---
# Te same pliki JSON6902 co w overlayu g5-local-ha: stan na żywo = stan z repo.
log "krok 2: catalog-app i outbox-publisher -> rabbitmq-ha (kk patch plikami overlayu g5-local-ha)"
# Idempotentnie: `add .../env/-` drugi raz dopisałby duplikat RABBITMQ_HOST (ponowne
# uruchomienie po przerwanej migracji), więc już przełączony Deployment pomijamy.
for d in outbox-publisher catalog-app; do
  if [ "$(kk get "deploy/$d" -o jsonpath='{.spec.template.spec.containers[0].env[?(@.name=="RABBITMQ_HOST")].value}')" = rabbitmq-ha ]; then
    log "krok 2: $d już na rabbitmq-ha — pomijam patch"; continue
  fi
  kk patch "deploy/$d" --type=json --patch-file "$HA/$d-env.yaml" >/dev/null || die "patch $d"
done
for d in outbox-publisher catalog-app; do
  kk rollout status "deploy/$d" --timeout=300s || die "rollout $d"
done
show "po kroku 2"
step_pause

# --- krok 3: Shovele ---
apply_shovels() {
  kc apply -f "$WORK/local.yaml" -l app.kubernetes.io/component=migration | sed 's/^/    /' \
    || die "apply Shoveli nie przeszedł"
}
running_shovels() {   # liczba shoveli działających na rabbitmq-ha (autodelete = znikają po przeniesieniu)
  local pod out
  pod="$(ha_pod)" || return 1
  out="$(kk exec "$pod" -c rabbitmq -- rabbitmqctl shovel_status -q --no-table-headers 2>/dev/null)" || return 1
  printf '%s\n' "$out" | awk 'NF { n++ } END { print n + 0 }'
}
old_empty() { [ "$(old_queues_total)" = "0" ]; }
log "krok 3: Shovel CR-y (stare kolejki -> rabbitmq-ha)"
show "przed Shovelami"
BEFORE_SHOVEL="$(col 5)"
apply_shovels
shovel_ready() { [ "$(kk get shovel "$1" -o jsonpath='{.status.conditions[?(@.type=="Ready")].status}' 2>/dev/null)" = True ]; }
for s in $SHOVELS; do
  wait_for 120 shovel_ready "$s" || die "Shovel $s nie jest Ready (kk describe shovel $s)"
done
wait_for 600 old_empty || die "stare kolejki nie opróżniły się w 10 min"
log "krok 3: w starych kolejkach było $BEFORE_SHOVEL wiadomości, teraz 0"
show "po kroku 3"
step_pause

# --- krok 4: konsument na rabbitmq-ha ---
log "krok 4: search-consumer -> rabbitmq-ha (kk patch: $CONSUMER_PATCH)"
kk patch deploy/search-consumer --type=json --patch-file "$CONSUMER_PATCH" >/dev/null || die "patch search-consumer"
kk rollout status deploy/search-consumer --timeout=300s || die "rollout search-consumer"
# Stary konsument przy wyłączaniu oddaje niepotwierdzone wiadomości (requeue), a delay_*
# po TTL wracają do kolejki źródłowej — Shovele z kroku 3 już się skasowały. Gdy w starych
# kolejkach coś jest, a żaden shovel nie działa: kasujemy CR-y i zakładamy od nowa
# (queue-length liczy się na nowo, przy starcie).
waited=0
while ! old_empty; do
  [ "$waited" -ge 300 ] && die "resztki w starych kolejkach nie zeszły w 5 min"
  # Błąd odczytu shovel_status = nic nie robimy w tym obrocie (nie zakładamy Shoveli w ciemno).
  if n="$(running_shovels)" && [ "$n" = "0" ]; then
    log "krok 4: w starych kolejkach $(old_queues_total) wiadomości — Shovele od nowa"
    for s in $SHOVELS; do kk delete shovel "$s" --ignore-not-found --wait=true >/dev/null; done
    apply_shovels
  fi
  sleep 5; waited=$((waited + 5))
done
show "po kroku 4"
if [ "$LOAD" -gt 0 ]; then
  traffic_active || die "ruch sklepu (Job marketplace-traffic) nie działał do końca kroku 4 — to nie jest migracja pod ruchem"
  log "obciążenie: ruch sklepu działał od przed krokiem 2 do po kroku 4 — zatrzymuję"
  kk delete job marketplace-traffic --cascade=foreground --wait=true >/dev/null || die "nie udało się zatrzymać ruchu sklepu"
  TRAFFIC_ON=""
  show "ruch zatrzymany"
fi
step_pause

# --- krok 5: dowód liczbami ---
log "krok 5: czekam na zbieżność liczb (do 10 min)"
PRODUCTS=""
converged() { counts_converged; }   # wspólna z verify.sh (counts.sh)
waited=0
until converged; do
  if [ "$waited" -ge 600 ]; then
    printf '[g5] counts %-22s %s (produkty w katalogu: %s)\n' "BEZ ZBIEŻNOŚCI" "$COUNTS" "$PRODUCTS"
    die "liczby się nie zgadzają (outbox_unpublished=0, processed_events=outbox_total, es_count=produkty, stare=0, DLQ=0)"
  fi
  sleep 10; waited=$((waited + 10))
done
printf '[g5] counts %-22s %s\n' "krok 5 (końcowe)" "$COUNTS"
log "krok 5: OK — outbox_unpublished=0, processed_events=outbox_total, es_count=$PRODUCTS (produkty w katalogu), stare kolejki=0, DLQ=0"

# --- krok 6: repo = prawda ---
# kc diff (dry-run po stronie serwera) z różnicą bez adnotacji last-applied-configuration:
# set env/patch nie aktualizują jej, apply overlayu tylko ją dopisze (bez zmiany szablonu poda).
cat > "$WORK/diff.sh" <<'SH'
#!/bin/sh
for d in "$1" "$2"; do
  for f in "$d"/*; do [ -f "$f" ] && sed -i.bak '/kubectl.kubernetes.io\/last-applied-configuration/{N;d;}' "$f" && rm -f "$f.bak"; done
done
diff -u -N "$1" "$2"
SH
chmod +x "$WORK/diff.sh"
log "krok 6: kc diff overlayu g5-local-ha (bez Joba migrate)"
# Poza porównaniem zostają CR-y operatorów (rabbitmq.com, k8s.elastic.co): migracja ich nie zmienia
# (żywe przełączenie dotyka Deploymentów), a API/webhooki dopisują do nich wartości domyślne
# (autoDelete, tags, resources, metadata, generation) przy pierwszej aktualizacji, więc na świeżym
# klastrze każdy z nich różniłby się od repo bez żadnej zmiany merytorycznej (RUNBOOK #109).
ruby -ryaml -e 'docs = YAML.load_stream(File.read(ARGV[0])).compact.reject { |d| d["apiVersion"].to_s =~ /(rabbitmq\.com|k8s\.elastic\.co)\// }
  puts docs.map { |d| d.to_yaml }.join' "$WORK/ha.yaml" > "$WORK/ha-core.yaml" || die "nie udało się przefiltrować renderu"
[ -s "$WORK/ha-core.yaml" ] || die "przefiltrowany render jest pusty"
KUBECTL_EXTERNAL_DIFF="$WORK/diff.sh" kc diff -f "$WORK/ha-core.yaml" -l 'app.kubernetes.io/component!=migrate' > "$WORK/diff.out" 2>&1
rc=$?
case "$rc" in
  0) log "krok 6: OK — stan na żywo = g5-local-ha; make g5-deploy o=g5-local-ha usunie tylko stary RabbitMQ (prune.txt)" ;;
  1) sed 's/^/    /' "$WORK/diff.out" | head -60 >&2; die "stan na żywo różni się od g5-local-ha (wyżej)" ;;
  *) sed 's/^/    /' "$WORK/diff.out" | head -20 >&2; die "kc diff nie przeszedł (kod $rc)" ;;
esac
echo "$COUNTS"
