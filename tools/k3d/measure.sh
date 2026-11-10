#!/usr/bin/env bash
#  tools/k3d/measure.sh queues|failover|prefetch — pomiary ETAPU G5 na k3d (spec sekcja 7).
#
#  Wyniki idą do docs/POMIARY.md (sekcja „G5 — RabbitMQ”). Każdy pomiar 3 razy: mediana,
#  rozrzut (min–max) i load average z `uptime` po każdym przebiegu. Maszyna jest współdzielona
#  z innymi projektami, więc liczby są orientacyjne.
#
#    queues    classic vs quorum: perf-test 60 s, wiadomość 1 KB, 2 producentów, 2 konsumentów,
#              publisher confirms (-c 200), prefetch konsumenta 100; przebiegi na zmianę
#              (classic, quorum, classic, …), żeby szum maszyny nie obciążył jednego typu.
#              Przepustowość = „receiving rate avg”; opóźnienie = od publikacji do odbioru
#              (consumer latency) i od publikacji do potwierdzenia (confirm latency).
#    failover  3 × kasowanie poda lidera + 1 × zatrzymanie węzła k3d (`docker stop`, potem
#              `docker start`), z lidera kolejki quorum, pod obciążeniem z confirmami (jak verify.sh,
#              sekcja 4). Liczby: potwierdzone (dolne ograniczenie z PERF-COUNTERS) vs w kolejce
#              po teście, czas do nowego lidera. Zatrzymywany jest agent-1: generator obciążenia jest pinowany
#              poza nim, a lider kolejki przenoszony na pod z tego węzła (transfer_leadership).
#    prefetch  (a) kolejka quorum wstępnie napełniona 30000 wiadomości, perf-test jako jeden
#              konsument z prefetch 1/10/50: przepustowość odbioru (główna miara) i czas opróżnienia (zawiera start Joba), kolumna
#              prefetch_count z `rabbitmqctl list_consumers` jako dowód, że wartość działa;
#              (b) stan wyjściowy search-consumer: reindeks 1500 zdarzeń (seed.sh --n=1500 --fresh)
#              do pustej kolejki, 3 ×. Messenger AMQP nie ma opcji prefetch (patrz POMIARY).
#
#  Zmienne: MEASURE_OUT — katalog na surowe logi i TSV (domyślnie mktemp). Sprząta tylko
#  kolejki i exchange g5.*. Zgodność: bash 3.2.
set -uo pipefail
# shellcheck source=tools/k3d/lib.sh
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
# shellcheck source=tools/k3d/counts.sh
. "$(dirname "${BASH_SOURCE[0]}")/counts.sh"   # counts_converged, ha_pod, is_num, psql_q, cfg

PERF="$REPO_ROOT/tools/k3d/perf-test.sh"
OUT="${MEASURE_OUT:-$(mktemp -d)}"
mkdir -p "$OUT"
RUNS=3
STOPPED_NODE=""

cluster_exists || die "klaster $CLUSTER nie istnieje (make g5-up)"
wait_for 180 allready || die "rabbitmq-ha nie jest AllReplicasReady (make g5-verify)"

load1() { uptime | sed -E 's/.*load averages?: *//' | awk '{ gsub(/,/, "", $1); print $1 }'; }

# stats <liczby…> → "mediana min max" (pusta lista → "- - -").
stats() {
  if [ "$#" -eq 0 ]; then printf -- '- - -'; return; fi
  printf '%s\n' "$@" | sort -n | awk '{ a[NR] = $1 } END {
    m = (NR % 2) ? a[(NR + 1) / 2] : (a[NR / 2] + a[NR / 2 + 1]) / 2
    printf "%s %s %s", m, a[1], a[NR] }'
}

# sweep_g5 <kolejki…> — usuwa kolejki testowe i exchange g5.test (tylko g5.*).
sweep_g5() {
  local pod q
  kk delete job -l app=perf-test --ignore-not-found --wait=true >/dev/null 2>&1
  pod="$(ha_pod 2>/dev/null)" || return 0
  for q in "$@"; do rmq "$pod" rabbitmqctl delete_queue "$q" >/dev/null; done
  rmq "$pod" rabbitmqctl eval 'rabbit_exchange:delete(rabbit_misc:r(<<"/">>, exchange, <<"g5.test">>), false, <<"g5-measure">>).' >/dev/null
}

node_ready() { [ "$(kc get node "$1" -o jsonpath='{.status.conditions[?(@.type=="Ready")].status}' 2>/dev/null)" = True ]; }

# Węzeł zatrzymany przez pomiar failoveru wraca także przy przerwaniu.
restore_node() {
  if [ -n "$STOPPED_NODE" ]; then
    docker start "$STOPPED_NODE" >/dev/null 2>&1
    STOPPED_NODE=""
  fi
}
trap 'restore_node; sweep_g5 g5.mq-classic g5.mq-quorum g5.failover g5.prefetch; kill $(jobs -p) 2>/dev/null' EXIT

# perf_field <log> <wzorzec sed z \(grupą\)> — pierwsza grupa z ostatniej pasującej linii.
perf_rate() { printf '%s\n' "$1" | sed -n "s/.*$2 avg: \([0-9][0-9]*\) msg\/s.*/\1/p" | tail -n 1; }
# perf_lat <log> <consumer|confirm> → "p50_ms p99_ms" z podsumowania (min/median/75th/95th/99th/max, µs).
perf_lat() {
  printf '%s\n' "$1" | grep "^id: .*, $2 latency min/median" | tail -n 1 \
    | sed -E 's/.* ([0-9]+(\/[0-9]+){5}) µs$/\1/' \
    | awk -F/ 'NF == 6 { printf "%.1f %.1f", $2 / 1000, $5 / 1000 }'
}

# ---------------------------------------------------------------------------
measure_queues() {
  local run kind q args log thr cl cf
  for kind in classic quorum; do : > "$OUT/queues-$kind.tsv"; done
  for run in $(seq 1 "$RUNS"); do
    for kind in classic quorum; do
      q="g5.mq-$kind"
      if [ "$kind" = classic ]; then args="-qa x-queue-type=classic"; else args="-qq"; fi
      sweep_g5 g5.mq-classic g5.mq-quorum
      log "queues: przebieg $run/$RUNS, $kind (60 s)"
      # shellcheck disable=SC2086
      PERF_NOLOGS=1 PERF_TIMEOUT=240 bash "$PERF" "mq-$kind" \
        -x 2 -y 2 -s 1024 -c 200 -q 100 -z 60 -f persistent $args \
        -u "$q" -e g5.test -t direct -k "$q" -ad false >/dev/null
      log="$(kk logs "job/perf-test-mq-$kind" 2>/dev/null)"
      printf '%s\n' "$log" > "$OUT/queues-$kind-run$run.log"
      thr="$(perf_rate "$log" 'receiving rate')"
      cl="$(perf_lat "$log" consumer)"
      cf="$(perf_lat "$log" confirm)"
      printf '%s %s %s %s\n' "$thr" "$cl" "$cf" "$(load1)" >> "$OUT/queues-$kind.tsv"
      echo "RUN queues $kind run=$run recv_msg_s=$thr sent_msg_s=$(perf_rate "$log" 'sending rate') consumer_p50_p99_ms=[$cl] confirm_p50_p99_ms=[$cf] load1=$(load1)"
    done
  done
  sweep_g5 g5.mq-classic g5.mq-quorum
  echo
  echo "| Typ kolejki | przepustowość odbioru, msg/s (mediana, min–max) | opóźnienie publikacja→odbiór p50 / p99, ms (mediany) | opóźnienie publikacja→potwierdzenie p50 / p99, ms (mediany) | load avg (1 min) w trakcie |"
  echo "|---|---|---|---|---|"
  for kind in classic quorum; do
    set -- $(stats $(awk '{ print $1 }' "$OUT/queues-$kind.tsv"))
    thr="$1 ($2–$3)"
    set -- $(stats $(awk '{ print $2 }' "$OUT/queues-$kind.tsv")); c50="$1"
    set -- $(stats $(awk '{ print $3 }' "$OUT/queues-$kind.tsv")); c99="$1"
    set -- $(stats $(awk '{ print $4 }' "$OUT/queues-$kind.tsv")); f50="$1"
    set -- $(stats $(awk '{ print $5 }' "$OUT/queues-$kind.tsv")); f99="$1"
    set -- $(stats $(awk '{ print $6 }' "$OUT/queues-$kind.tsv"))
    echo "| $kind | $thr | $c50 / $c99 | $f50 / $f99 | $1 ($2–$3) |"
  done
}

# ---------------------------------------------------------------------------
# fo_attempt <tryb pod|node> — jeden przebieg failoveru; ustawia FO_* (jedna linia TSV w $OUT).
# Kod 2 = nie udało się ustawić lidera na agencie (wywołujący powtarza).
SENT=9000
FO_NODE="k3d-elastic-g5-agent-1"
FO_MODES="${FO_MODES:-pod pod pod node}"   # np. FO_MODES=node: tylko zatrzymanie węzła
fo_attempt() {
  local mode="$1" pod other old new node d waited cand t0 elect conf nacked depth lost rc log recovered
  sweep_g5 g5.failover
  pod="$(ha_pod)" || return 1
  PERF_AVOID_NODE="$([ "$mode" = node ] && echo "$FO_NODE")" PERF_COUNTERS=1 PERF_NOLOGS=1 PERF_TIMEOUT=300 bash "$PERF" failover \
    -x 1 -y 0 -C "$SENT" -r 300 -c 100 -ct 60 -qq -u g5.failover -e g5.test -t direct -k g5.failover -ad false &
  local ppid=$!
  old=""; d=""; waited=0
  while :; do
    d="$(fo_depth "$pod" g5.failover)"; old="$(fo_leader "$pod" g5.failover)"
    if [ -n "$old" ] && is_num "$d" && [ "$d" -ge 1500 ]; then break; fi
    [ "$waited" -ge 180 ] && break
    sleep 2; waited=$((waited + 2))
  done
  [ -n "$old" ] || { kill "$ppid" 2>/dev/null; return 1; }
  node="$(kk get pod "$old" -o jsonpath='{.spec.nodeName}')"
  if [ "$mode" = node ]; then
    # Zatrzymujemy agent-1 (jak w poleceniu zadania); lider musi leżeć na jego podzie, a generator
    # obciążenia nie może tam być (PERF_AVOID_NODE przy starcie). Lider startuje na podzie z innego
    # węzła, więc przenosimy go: rabbitmq-queues transfer_leadership.
    local target full
    target="$(kk get pods -l "$G5_HA_SEL" -o jsonpath='{range .items[*]}{.metadata.name}{" "}{.spec.nodeName}{"\n"}{end}' | awk -v n="$FO_NODE" '$2 == n { print $1; exit }')"
    [ -n "$target" ] || { kill "$ppid" 2>/dev/null; return 1; }
    if [ "$old" != "$target" ]; then
      full="$(rmq "$pod" rabbitmqctl list_queues -q --no-table-headers name leader | awk '$1 == "g5.failover" { print $2 }' | sed -E "s/rabbitmq-ha-server-[0-9]+/$target/")"
      rmq "$pod" rabbitmq-queues transfer_leadership g5.failover "$full" >/dev/null
      waited=0
      while [ "$waited" -lt 30 ] && [ "$(fo_leader "$pod" g5.failover)" != "$target" ]; do sleep 1; waited=$((waited + 1)); done
      old="$(fo_leader "$pod" g5.failover)"
      [ "$old" = "$target" ] || { kill "$ppid" 2>/dev/null; wait "$ppid" 2>/dev/null; return 2; }
    fi
    node="$FO_NODE"
  fi
  other="rabbitmq-ha-server-0"; [ "$old" = "$other" ] && other="rabbitmq-ha-server-1"
  t0="$(now_ms)"
  if [ "$mode" = node ]; then
    STOPPED_NODE="$node"
    docker stop "$node" >/dev/null
  else
    kk delete pod "$old" --wait=false >/dev/null
  fi
  new=""; elect=""; waited=0
  while [ "$waited" -lt 400 ]; do
    cand="$(fo_leader "$other" g5.failover)"
    if [ -n "$cand" ] && [ "$cand" != "$old" ]; then new="$cand"; elect=$(( $(now_ms) - t0 )); break; fi
    sleep 0.3; waited=$((waited + 1))
  done
  wait "$ppid"; rc=$?
  log="$(kk logs job/perf-test-failover 2>/dev/null)"
  printf '%s\n' "$log" > "$OUT/failover-$mode-$(now_ms).log"
  conf="$(printf '%s\n' "$log" | sed -n 's/.*PERF-COUNTERS confirmed=\([0-9][0-9]*\).*/\1/p' | tail -n 1)"
  nacked="$(printf '%s\n' "$log" | sed -n 's/.*nacked: \([0-9][0-9]*\) msg\/s.*/\1/p' | awk '{ s += $1 } END { print s + 0 }')"
  # Przywrócenie: węzeł (tryb node), potem komplet replik.
  if [ "$mode" = node ]; then
    restore_node
    wait_for 300 node_ready "$node"
  fi
  wait_for 400 allready; recovered=$?
  # Po `docker start` węzły mogą dostać inne adresy IP: exec chwilę wywala się na certyfikacie kubeleta.
  wait_for 180 ha_pod
  sleep 5
  pod="$(ha_pod)" || pod="rabbitmq-ha-server-0"
  depth="$(fo_depth "$pod" g5.failover)"
  lost="?"
  if is_num "${conf:-}" && is_num "${depth:-}"; then lost=$(( conf > depth ? conf - depth : 0 )); fi
  FO_LINE="mode=$mode old=$old new=${new:-NONE} node=$node elect_ms=${elect:-NONE} sent_limit=$SENT confirmed_ge=${conf:-?} in_queue=${depth:-?} lost=$lost nacked=$nacked perf_rc=$rc recovered_rc=$recovered load1=$(load1)"
  printf '%s\t%s\t%s\t%s\t%s\n' "$mode" "${elect:-}" "${conf:-}" "${depth:-}" "$lost" >> "$OUT/failover.tsv"
  sweep_g5 g5.failover
  return 0
}

measure_failover() {
  local m i=0 try rc
  : > "$OUT/failover.tsv"
  for m in $FO_MODES; do
    i=$((i + 1))
    try=0
    while :; do
      try=$((try + 1))
      log "failover: $m, przebieg $i, próba $try"
      fo_attempt "$m"; rc=$?
      [ "$rc" -eq 0 ] && break
      [ "$rc" -eq 2 ] && [ "$try" -lt 5 ] && continue
      die "failover $m: brak wyniku (kod $rc)"
    done
    echo "RUN failover $FO_LINE"
  done
  echo
  echo "| Przebieg | Zdarzenie | Czas do nowego lidera, ms | Potwierdzone (dolne ogr.) | W kolejce po teście | Utracone potwierdzone |"
  echo "|---|---|---|---|---|---|"
  awk -F'\t' '{ printf "| %d | %s | %s | %s | %s | %s |\n", NR, ($1 == "pod" ? "kasowanie poda lidera" : "docker stop węzła z liderem"), $2, $3, $4, $5 }' "$OUT/failover.tsv"
  local pods
  pods="$(awk -F'\t' '$1 == "pod" { print $2 }' "$OUT/failover.tsv")"
  if [ -n "$pods" ]; then
    set -- $(stats $pods)
    echo
    echo "Czas do nowego lidera po skasowaniu poda: mediana $1 ms, min–max $2–$3 ms."
  fi
  pods="$(awk -F'\t' '$1 == "node" { print $2 }' "$OUT/failover.tsv")"
  if [ -n "$pods" ]; then
    set -- $(stats $pods)
    echo "Czas do nowego lidera po zatrzymaniu węzła: mediana $1 ms, min–max $2–$3 ms."
  fi
}

# ---------------------------------------------------------------------------
PF_N=30000
PF_Q="g5.prefetch"
PF_ARGS="-qq -u $PF_Q -e g5.test -t direct -k $PF_Q -ad false"

# pf_verify <P> — konsument z -q P na pustej kolejce; wypisuje prefetch_count z list_consumers.
pf_verify() {
  local p="$1" pod seen="" i
  pod="$(ha_pod)" || return 1
  # shellcheck disable=SC2086
  PERF_NOLOGS=1 PERF_TIMEOUT=90 bash "$PERF" prefetch-probe -x 0 -y 1 -q "$p" -z 20 $PF_ARGS >/dev/null &
  local ppid=$!
  for i in $(seq 1 40); do
    seen="$(rmq "$pod" rabbitmqctl list_consumers -q --no-table-headers queue_name prefetch_count | awk -v q="$PF_Q" '$1 == q { print $2; exit }')"
    [ -n "$seen" ] && break
    sleep 1
  done
  wait "$ppid" 2>/dev/null
  printf '%s' "${seen:-brak}"
}

pf_drain() { # pf_drain <P> → "czas_s rate"
  local p="$1" pod log rate depth t0 t1
  sweep_g5 "$PF_Q"
  pod="$(ha_pod)" || return 1
  # shellcheck disable=SC2086
  PERF_NOLOGS=1 PERF_TIMEOUT=240 bash "$PERF" prefetch-fill -x 1 -y 0 -C "$PF_N" -s 1024 -c 200 -f persistent $PF_ARGS >/dev/null \
    || { echo "napełnianie nie przeszło" >&2; return 1; }
  depth="$(fo_depth "$pod" "$PF_Q")"
  [ "$depth" = "$PF_N" ] || echo "UWAGA: w kolejce $depth zamiast $PF_N" >&2
  t0="$(now_ms)"
  # shellcheck disable=SC2086
  PERF_NOLOGS=1 PERF_TIMEOUT=300 bash "$PERF" prefetch-drain -x 0 -y 1 -D "$PF_N" -q "$p" $PF_ARGS >/dev/null \
    || { echo "opróżnianie nie przeszło" >&2; return 1; }
  t1="$(now_ms)"
  log="$(kk logs job/perf-test-prefetch-drain 2>/dev/null)"
  printf '%s\n' "$log" > "$OUT/prefetch-q$p-$t1.log"
  rate="$(perf_rate "$log" 'receiving rate')"
  printf '%s %s' "$(awk -v a="$t0" -v b="$t1" 'BEGIN { printf "%.1f", (b - a) / 1000 }')" "${rate:-0}"
}

# reindex_run → ustawia RT (czas w s: outbox pełny → kolejka pusta); kod != 0, gdy liczby nie są zbieżne.
# Bez podpowłoki, żeby COUNTS z counts_converged został widoczny dla wywołującego.
reindex_run() {
  local pod t0 t1 u d waited=0 conv=1
  pod="$(ha_pod)" || return 1
  bash "$REPO_ROOT/tools/k3d/seed.sh" --n=1500 --fresh >/dev/null 2>&1 || return 1
  t0="$(now_ms)"
  while [ "$waited" -lt 300 ]; do
    u="$(psql_q "$(cfg CATALOG_DB)" "select count(*) from outbox where published_at is null" 2>/dev/null)"
    d="$(fo_depth "$pod" search.product.sync)"
    if is_num "${u:-}" && is_num "${d:-}" && [ "$u" -eq 0 ] && [ "$d" -eq 0 ]; then break; fi
    sleep 1; waited=$((waited + 1))
  done
  t1="$(now_ms)"
  wait_for 90 counts_converged && conv=0
  RT="$(awk -v a="$t0" -v b="$t1" 'BEGIN { printf "%.1f", (b - a) / 1000 }')"
  return "$conv"
}

measure_prefetch() {
  local p run seen res t rate
  for p in 1 10 50; do : > "$OUT/prefetch-$p.tsv"; done
  for p in 1 10 50; do
    seen="$(pf_verify "$p")"
    echo "VERIFY prefetch=$p list_consumers.prefetch_count=$seen"
    [ "$seen" = "$p" ] || log "UWAGA: prefetch_count=$seen zamiast $p"
  done
  sweep_g5 "$PF_Q"
  for run in $(seq 1 "$RUNS"); do
    for p in 1 10 50; do
      log "prefetch: przebieg $run/$RUNS, -q $p ($PF_N wiadomości)"
      res="$(pf_drain "$p")" || die "pf_drain -q $p nie dał wyniku"
      set -- $res
      printf '%s %s %s\n' "$1" "$2" "$(load1)" >> "$OUT/prefetch-$p.tsv"
      echo "RUN prefetch -q $p run=$run drain_s=$1 recv_msg_s=$2 load1=$(load1)"
    done
  done
  sweep_g5 "$PF_Q"
  echo
  echo "| Prefetch (perf-test) | czas opróżnienia $PF_N wiadomości, s (mediana, min–max) | przepustowość odbioru, msg/s (mediana, min–max) | load avg (1 min) |"
  echo "|---|---|---|---|"
  for p in 1 10 50; do
    set -- $(stats $(awk '{ print $1 }' "$OUT/prefetch-$p.tsv")); t="$1 ($2–$3)"
    set -- $(stats $(awk '{ print $2 }' "$OUT/prefetch-$p.tsv")); rate="$1 ($2–$3)"
    set -- $(stats $(awk '{ print $3 }' "$OUT/prefetch-$p.tsv"))
    echo "| $p | $t | $rate | $1 ($2–$3) |"
  done

  echo
  measure_reindex
}

measure_reindex() {
  local run t row
  : > "$OUT/reindex.tsv"
  for run in $(seq 1 "$RUNS"); do
    log "reindeks: przebieg $run/$RUNS (seed --n=1500 --fresh -> pusta kolejka)"
    reindex_run; row=$?; t="$RT"
    echo "RUN reindex run=$run time_s=$t converged_rc=$row counts=[$COUNTS] load1=$(load1)"
    printf '%s %s\n' "$t" "$(load1)" >> "$OUT/reindex.tsv"
  done
  set -- $(stats $(awk '{ print $1 }' "$OUT/reindex.tsv")); t="$1 ($2–$3)"
  set -- $(stats $(awk '{ print $2 }' "$OUT/reindex.tsv"))
  echo
  echo "| search-consumer (bez opcji prefetch) | czas od końca seed do pustej kolejki, s (mediana, min–max) | load avg (1 min) |"
  echo "|---|---|---|"
  echo "| reindeks 1500 zdarzeń | $t | $1 ($2–$3) |"
}

case "${1:-}" in
  queues) measure_queues ;;
  failover) measure_failover ;;
  prefetch) measure_prefetch ;;
  reindex) measure_reindex ;;   # tylko część (b) pomiaru prefetch
  *) die "użycie: measure.sh queues|failover|prefetch|reindex" ;;
esac
log "surowe wyniki: $OUT"
