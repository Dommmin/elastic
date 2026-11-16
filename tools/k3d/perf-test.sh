#!/usr/bin/env bash
#  tools/k3d/perf-test.sh <nazwa> <argumenty perf-test…> — Job `perf-test-<nazwa>` na k3d (ETAP G5).
#
#  Uruchamia pivotalrabbitmq/perf-test (wersja przypięta niżej, obraz importuje images.sh)
#  przeciw rabbitmq-ha. Po zakończeniu wypisuje logi; kod wyjścia = kod Joba
#  (0 Complete, 1 Failed/limit czasu). Job jest odtwarzany przy każdym uruchomieniu.
#
#  Zmienne środowiska:
#    PERF_SECRET   Secret z kluczami username/password (domyślnie rabbitmq-ha-perftest-user =
#                  użytkownik g5-perftest, uprawnienia tylko do g5.*; demo streamu używa
#                  rabbitmq-ha-marketplace-user — publikuje przez marketplace.events)
#    PERF_TIMEOUT  limit czasu Joba w sekundach (domyślnie 300)
#    PERF_COUNTERS=1  na końcu logu linia `PERF-COUNTERS confirmed=M consumed=K` (patrz niżej)
#    PERF_AVOID_NODE=<węzeł>  nie planuj poda perf-test na tym węźle (pomiar failoveru zatrzymuje węzeł
#                  k3d: generator obciążenia nie może zginąć razem z nim)
#    PERF_NOLOGS=1 nie wypisuj logów (wywołujący sam je czyta: kk logs job/perf-test-<nazwa>)
#
#  Przykład: perf-test.sh smoke -x 1 -y 0 -C 100 -qq -u g5.smoke -p
#  Do adresu (-h/--uri) NIE przekazujemy nic: skrypt sam składa amqp://user:hasło@rabbitmq-ha:5672
#  z Secretu (hasło idzie przez env z Secretu, nie przez argv ani manifest).
#  Zgodność: bash 3.2.
# shellcheck source=tools/k3d/lib.sh
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

PERF_TEST_VERSION="2.25.0"   # jedno źródło: images.sh importuje ten tag, docs/07-WERSJE.md go opisuje
PERF_IMAGE="pivotalrabbitmq/perf-test:${PERF_TEST_VERSION}"

# Gdy sourcowany (images.sh) — tylko stałe.
[ "${BASH_SOURCE[0]}" = "$0" ] || return 0

NAME="${1:-}"
[ -n "$NAME" ] || die "użycie: perf-test.sh <nazwa> <argumenty perf-test…>"
shift
case "$NAME" in *[!a-z0-9-]*) die "nazwa Joba: tylko małe litery, cyfry i myślniki" ;; esac
SECRET="${PERF_SECRET:-rabbitmq-ha-perftest-user}"
LIMIT="${PERF_TIMEOUT:-300}"
AFFINITY=""
if [ -n "${PERF_AVOID_NODE:-}" ]; then
  AFFINITY="affinity: { nodeAffinity: { requiredDuringSchedulingIgnoredDuringExecution: { nodeSelectorTerms: [ { matchExpressions: [ { key: kubernetes.io/hostname, operator: NotIn, values: [\"$PERF_AVOID_NODE\"] } ] } ] } } }"
fi
JOB="perf-test-$NAME"
cluster_exists || die "klaster $CLUSTER nie istnieje (make g5-up)"

# Argumenty jako tablica JSON (ruby: poprawne cytowanie dowolnych znaków).
ARGS_JSON="$(ruby -rjson -e 'puts JSON.generate(ARGV)' -- "$@")" || die "nie udało się zakodować argumentów"

# Wejście w kube-router ma wyścig: świeży pod jest REJECT-owany przez pierwsze sekundy
# (RUNBOOK #097), więc przed startem perf-test czekamy na trasę do rabbitmq-ha:5672 (limit 60 s).
# PERF_COUNTERS=1: perf-test z metrykami Prometheus (-mpr, port 8080 w podzie) + odpytywanie ich
# co 0,25 s; na końcu linia `PERF-COUNTERS confirmed=M consumed=K` z OSTATNIEGO udanego odczytu.
# M = licznik próbek perftest_confirm_latency_seconds_count (jedna próbka na potwierdzoną
# wiadomość); rośnie monotonicznie, więc to dolne ograniczenie liczby potwierdzonych
# (spóźnione najwyżej o 0,25 s) — dowód „w kolejce >= potwierdzone” zostaje ważny.
# K = licznik perftest_latency_seconds_count (jedna próbka na odebraną wiadomość z
# znacznikiem czasu, który perf-test dokleja domyślnie).
# perftest_published/consumed to STAWKI (gauge), a logów (confirmed: N msg/s) nie da się
# zsumować dokładnie (zaokrąglone stawki).
read -r -d '' WAIT <<'SH'
for i in $(seq 1 60); do
  (exec 3<>/dev/tcp/rabbitmq-ha/5672) 2>/dev/null && break
  [ "$i" = 60 ] && { echo "brak trasy do rabbitmq-ha:5672 po 60 s" >&2; exit 1; }
  sleep 1
done
URI="amqp://${RMQ_USER}:${RMQ_PASS}@rabbitmq-ha:5672/%2f"
if [ "${PERF_COUNTERS:-}" != 1 ]; then
  exec java -jar /perf_test/perf-test.jar --uri "$URI" "$@"
fi
java -jar /perf_test/perf-test.jar --uri "$URI" -mpr "$@" &
JP=$!
last=""
while kill -0 "$JP" 2>/dev/null; do
  s="$( (exec 4<>/dev/tcp/127.0.0.1/8080; printf 'GET /metrics HTTP/1.0\r\n\r\n' >&4; cat <&4) 2>/dev/null |
    awk '/^perftest_confirm_latency_seconds_count/ {c=$2} /^perftest_latency_seconds_count/ {l=$2} END {if (c != "" || l != "") printf "%d %d\n", c, l}')"
  [ -n "$s" ] && last="$s"
  sleep 0.25
done
wait "$JP"; rc=$?
set -- $last
echo "PERF-COUNTERS confirmed=${1:-0} consumed=${2:-0}"
exit "$rc"
SH
WAIT_JSON="$(ruby -rjson -e 'puts JSON.generate(ARGV[0])' -- "$WAIT")"

kk delete job "$JOB" --ignore-not-found --wait=true >/dev/null || die "nie udało się skasować starego Joba $JOB"
cat <<YAML | kk apply -f - >/dev/null || die "apply Joba $JOB nie przeszedł"
apiVersion: batch/v1
kind: Job
metadata:
  name: $JOB
  labels: { app: perf-test, app.kubernetes.io/component: perf-test }
spec:
  backoffLimit: 0
  ttlSecondsAfterFinished: 3600
  template:
    metadata:
      # app: perf-test wpuszcza NetworkPolicy rabbitmq-ha-ingress (5672, 5552).
      labels: { app: perf-test }
    spec:
      restartPolicy: Never
      $AFFINITY
      automountServiceAccountToken: false
      securityContext:
        runAsNonRoot: true
        runAsUser: 2000
        seccompProfile: { type: RuntimeDefault }
      containers:
        - name: perf-test
          image: $PERF_IMAGE
          imagePullPolicy: IfNotPresent
          command: ["bash", "-c", $WAIT_JSON, "perf-test"]
          args: $ARGS_JSON
          env:
            - { name: JAVA_TOOL_OPTIONS, value: "-Xmx160m" }
            - { name: PERF_COUNTERS, value: "${PERF_COUNTERS:-0}" }
            - name: RMQ_USER
              valueFrom: { secretKeyRef: { name: $SECRET, key: username } }
            - name: RMQ_PASS
              valueFrom: { secretKeyRef: { name: $SECRET, key: password } }
          resources:
            requests: { cpu: 100m, memory: 256Mi }
            limits: { memory: 320Mi }
          securityContext:
            allowPrivilegeEscalation: false
            capabilities: { drop: [ALL] }
            readOnlyRootFilesystem: true
          volumeMounts:
            - { name: tmp, mountPath: /tmp }
      volumes:
        - { name: tmp, emptyDir: {} }
YAML

wait_job "$JOB" "$LIMIT"; rc=$?
if [ "${PERF_NOLOGS:-}" != "1" ]; then
  kk logs "job/$JOB" 2>&1 | tail -n 60
fi
[ "$rc" -eq 0 ] || log "Job $JOB nie zakończył się sukcesem (Failed albo limit ${LIMIT}s)" >&2
exit "$rc"
