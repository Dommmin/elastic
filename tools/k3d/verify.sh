#!/usr/bin/env bash
#  tools/k3d/verify.sh — weryfikacja ETAPU G5 na k3d `elastic-g5` (kryteria 1–5 specu).
#
#  Styl jak tools/k3s/verify.sh: check "<opis>" "<oczekiwane>" "<faktyczne>", na końcu
#  `PASS=n FAIL=m`, kod != 0 przy FAIL. Zakłada stan końcowy (make g5-deploy o=g5-local-ha).
#
#    1  3 pody rabbitmq-ha Ready na 3 różnych węzłach, uid 999
#    2  wszystkie CR-y topologii Ready=True, 6 kolejek quorum + 1 stream
#    3  liczby „bez utraty” (counts.sh): brak zaległości, zgodność outbox/processed/ES
#    4  failover: producent z potwierdzeniami (-c 100) na kolejce quorum g5.failover, w trakcie
#       kasujemy pod LIDERA; w kolejce >= potwierdzone (utracone potwierdzone = 0), czas do nowego lidera
#    5  stream: stream-demo.sh (1000 zdarzeń user.searched, dwóch czytelników first, znacznik czasu, next)
#    6  pamięć: suma pamięci kontenerów (working set) <= 5700 MiB (R7: budżet maszyny)
#
#  Czas: 2–5 min (failover czeka na powrót poda i AllReplicasReady). Sprząta po sobie
#  (Joby perf-test, kolejka g5.failover, exchange g5.test); stream zostaje (retencja 7 dni).
#  Zgodność: bash 3.2.
set -uo pipefail
# shellcheck source=tools/k3d/lib.sh
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
# shellcheck source=tools/k3d/counts.sh
. "$(dirname "${BASH_SOURCE[0]}")/counts.sh"   # counts_converged, ha_pod, is_num

RED='\033[0;31m'; GRN='\033[0;32m'; BLD='\033[1m'; DIM='\033[2m'; NC='\033[0m'
PASS=0; FAIL=0
check() { # check <opis> <oczekiwane> <otrzymane>
  if [ "$2" = "$3" ]; then
    echo -e "  ${GRN}✓${NC} $1"; PASS=$((PASS+1))
  else
    echo -e "  ${RED}✗${NC} $1  ${DIM}(oczekiwano: $2, otrzymano: $3)${NC}"; FAIL=$((FAIL+1))
  fi
}
note() { echo -e "    ${DIM}· $*${NC}"; }
section() { echo -e "\n${BLD}$*${NC}"; }

cluster_exists || die "klaster $CLUSTER nie istnieje (make g5-up)"
PERF="$REPO_ROOT/tools/k3d/perf-test.sh"
SEL="app.kubernetes.io/name=rabbitmq-ha"

# now_ms, allready, rmq: lib.sh

# Sprzątanie testu failoveru (też przy przerwaniu): Joby, kolejka i exchange użytkownika g5-perftest.
cleanup_failover() {
  local pod
  kk delete job perf-test-failover --ignore-not-found --wait=false >/dev/null 2>&1
  pod="$(ha_pod 2>/dev/null)" || return 0
  rmq "$pod" rabbitmqctl delete_queue g5.failover >/dev/null
  rmq "$pod" rabbitmqctl eval 'rabbit_exchange:delete(rabbit_misc:r(<<"/">>, exchange, <<"g5.test">>), false, <<"g5-verify">>).' >/dev/null
}
trap 'cleanup_failover; kk delete job -l app=perf-test --ignore-not-found --wait=false >/dev/null 2>&1; kill $(jobs -p) 2>/dev/null' EXIT

# ---------------------------------------------------------------------------
section "1. Klaster rabbitmq-ha: 3 pody, 3 węzły, uid 999"
PODS="$(kk get pods -l "$SEL" -o jsonpath='{range .items[*]}{.metadata.name}{" "}{.spec.nodeName}{" "}{.status.containerStatuses[0].ready}{"\n"}{end}')"
check "3 pody rabbitmq-ha Ready" "3" "$(printf '%s\n' "$PODS" | grep -c ' true$')"
check "pody na 3 różnych węzłach (anty-afinity)" "3" "$(printf '%s\n' "$PODS" | awk 'NF { print $2 }' | sort -u | wc -l | tr -d ' ')"
UIDS=""
for i in 0 1 2; do UIDS="$UIDS $(rmq "rabbitmq-ha-server-$i" id -u)"; done
check "każdy pod działa jako uid 999" " 999 999 999" "$UIDS"
check "RabbitmqCluster: AllReplicasReady" "True" "$(kk get rabbitmqcluster rabbitmq-ha -o jsonpath='{.status.conditions[?(@.type=="AllReplicasReady")].status}')"

# ---------------------------------------------------------------------------
section "2. Topologia: CR-y Ready, 6 kolejek quorum + 1 stream"
NOT_READY=""; TOTAL=0
for kind in exchanges queues bindings users permissions; do
  rows="$(kk get "$kind.rabbitmq.com" -o jsonpath='{range .items[*]}{.metadata.name}{" "}{.status.conditions[?(@.type=="Ready")].status}{"\n"}{end}')"
  TOTAL=$((TOTAL + $(printf '%s\n' "$rows" | grep -c .)))
  NOT_READY="$NOT_READY$(printf '%s\n' "$rows" | awk -v k="$kind" 'NF && $2 != "True" { printf "%s/%s ", k, $1 }')"
done
check "wszystkie CR-y topologii ($TOTAL) mają Ready=True" "" "$NOT_READY"
types="$(kk get queues.rabbitmq.com -o jsonpath='{range .items[*]}{.spec.type}{"\n"}{end}' | sort | uniq -c | awk '{ printf "%s=%s ", $2, $1 }')"
check "CR Queue: 6 quorum + 1 stream" "quorum=6 stream=1 " "$types"
POD="$(ha_pod)" || die "żaden pod rabbitmq-ha nie odpowiada"
# Żywy broker: kolejki sklepu (bez g5.* testów i delay_* retry Messengera).
live="$(rmq "$POD" rabbitmqctl list_queues -q --no-table-headers name type | awk '$1 !~ /^(g5\.|delay_)/ { c[$2]++ } END { for (t in c) printf "%s=%s\n", t, c[t] }' | sort | tr '\n' ' ')"
check "broker: 6 kolejek quorum + 1 stream (bez g5.* i delay_*)" "quorum=6 stream=1 " "$live"

# ---------------------------------------------------------------------------
section "3. Bez utraty: outbox / processed_events / ES / stare kolejki / DLQ"
waited=0
until counts_converged; do
  [ "$waited" -ge 120 ] && break
  sleep 10; waited=$((waited + 10))
done
if counts_converged; then res="zbieżne"; else res="BEZ ZBIEŻNOŚCI"; fi
check "outbox_unpublished=0, processed_events=outbox_total, es_count=produkty, stare=0, DLQ=0" "zbieżne" "$res"
note "counts: $COUNTS (outbox_total outbox_unpublished processed_events es_count old dlq); produkty w katalogu: $PRODUCTS"

# ---------------------------------------------------------------------------
section "4. Failover: kasujemy pod lidera kolejki quorum pod obciążeniem (zero utraconych potwierdzonych)"
cleanup_failover
SENT=9000
# fo_leader, fo_depth: lib.sh (parametr: nazwa kolejki, domyślnie g5.failover)
# Producent z potwierdzeniami (-c 100 = max 100 niepotwierdzonych), bez konsumentów (-y 0, R3).
PERF_COUNTERS=1 PERF_NOLOGS=1 PERF_TIMEOUT=300 bash "$PERF" failover \
  -x 1 -y 0 -C "$SENT" -r 300 -c 100 -ct 60 -qq -u g5.failover -e g5.test -t direct -k g5.failover -ad false &
PERF_PID=$!
# Czekamy na kolejkę z ruchem (>= 1500 wiadomości, ok. 5 s po starcie producenta).
OLD=""; waited=0
while :; do
  d="$(fo_depth "$POD")"; OLD="$(fo_leader "$POD")"
  if [ -n "$OLD" ] && is_num "$d" && [ "$d" -ge 1500 ]; then break; fi
  [ "$waited" -ge 180 ] && break
  sleep 2; waited=$((waited + 2))
done
NEW=""; ELECT_MS=""
if [ -n "$OLD" ]; then
  # Do odczytów używamy poda INNEGO niż lider (ten za chwilę znika).
  OTHER="rabbitmq-ha-server-0"; [ "$OLD" = "$OTHER" ] && OTHER="rabbitmq-ha-server-1"
  note "lider g5.failover: $OLD, w kolejce $d wiadomości — kasuję pod"
  T0="$(now_ms)"
  kk delete pod "$OLD" --wait=false >/dev/null
  waited=0
  while [ "$waited" -lt 120 ]; do
    cand="$(fo_leader "$OTHER")"
    if [ -n "$cand" ] && [ "$cand" != "$OLD" ]; then NEW="$cand"; ELECT_MS=$(( $(now_ms) - T0 )); break; fi
    sleep 0.3; waited=$((waited + 1))
  done
fi
wait "$PERF_PID"; PERF_RC=$?
LOG="$(kk logs job/perf-test-failover 2>/dev/null)"
CONF="$(printf '%s\n' "$LOG" | sed -n 's/.*PERF-COUNTERS confirmed=\([0-9][0-9]*\).*/\1/p' | tail -n 1)"
NACKED="$(printf '%s\n' "$LOG" | sed -n 's/.*nacked: \([0-9][0-9]*\) msg\/s.*/\1/p' | awk '{ s += $1 } END { print s + 0 }')"
# Czekamy na powrót skasowanego poda (nie wolno zostawić klastra w połowie rollingu).
wait_for 300 allready; RECOVERED=$?
sleep 5
POD="$(ha_pod)" || POD="rabbitmq-ha-server-0"
DEPTH="$(fo_depth "$POD")"
check "producent z potwierdzeniami zakończył się sukcesem mimo kasowania lidera" "0" "$PERF_RC"
check "wybrano nowego lidera kolejki (inny niż $OLD)" "tak" "$([ -n "$NEW" ] && echo tak || echo "nie")"
if [ -n "$ELECT_MS" ]; then note "czas do nowego lidera: ${ELECT_MS} ms (lider: $OLD -> $NEW; odczyt co ok. 0,3 s + exec)"; fi
if is_num "${CONF:-}" && is_num "${DEPTH:-}"; then
  LOST=$(( CONF > DEPTH ? CONF - DEPTH : 0 ))
  check "utracone potwierdzone = 0 (w kolejce >= potwierdzone)" "0" "$LOST"
  note "wysłane (limit -C): $SENT, potwierdzone >= $CONF, w kolejce po teście: $DEPTH, nacked (z logu): $NACKED"
  check "potwierdzone > 0 (test faktycznie coś zmierzył)" "tak" "$([ "$CONF" -gt 0 ] && echo tak || echo nie)"
else
  check "odczyt liczby potwierdzonych i wiadomości w kolejce" "liczby" "potwierdzone='${CONF:-}' w kolejce='${DEPTH:-}'"
fi
check "pod lidera wrócił, AllReplicasReady" "0" "$RECOVERED"
cleanup_failover
POD="$(ha_pod)" || POD="rabbitmq-ha-server-0"
check "kolejka testowa g5.failover usunięta" "0" "$(rmq "$POD" rabbitmqctl list_queues -q name | grep -c '^g5\.')"

# ---------------------------------------------------------------------------
section "5. Stream: dwóch czytelników, znacznik czasu, next"
N=1000
SD_OUT="$(mktemp)"
bash "$REPO_ROOT/tools/k3d/stream-demo.sh" "$N" > "$SD_OUT" 2>&1; SD_RC=$?
LINE="$(grep '^STREAM-DEMO ' "$SD_OUT" | tail -n 1)"
sdv() { printf '%s\n' "$LINE" | tr ' ' '\n' | sed -n "s/^$1=//p"; }
check "stream-demo.sh zakończył się sukcesem" "0" "$SD_RC"
if [ "$SD_RC" -ne 0 ]; then tail -n 5 "$SD_OUT" | sed 's/^/    /'; fi
BASE="$(sdv baseline)"
if is_num "${BASE:-}"; then
  WANT=$((BASE + N))
  check "czytelnik A (first) dostał całą historię ($WANT = $BASE z poprzednich przebiegów + $N)" "$WANT" "$(sdv first_a)"
  check "czytelnik B (first) dostał to samo, niezależnie od A" "$WANT" "$(sdv first_b)"
  check "czytelnik ze znacznikiem czasu sprzed publikacji dostał dokładnie $N nowych" "$N" "$(sdv timestamp)"
  check "czytelnik z offsetem next dostał 0" "0" "$(sdv next)"
  check "stream po odczytach nadal trzyma $WANT wiadomości (odczyt niczego nie kasuje)" "$WANT" "$(sdv stream_after)"
else
  check "linia STREAM-DEMO z wynikami" "jest" "brak"
fi
rm -f "$SD_OUT"
kk delete job -l app=perf-test --ignore-not-found --wait=false >/dev/null 2>&1

# ---------------------------------------------------------------------------
section "6. Pamięć: suma kontenerów <= 5700 MiB (R7)"
# Working set podów z metrics-servera (k3s ma go domyślnie).
POD_MIB="$(kc top pods -A --no-headers 2>/dev/null | awk '
  { v = $NF; if (v ~ /Ki$/) { sub(/Ki/, "", v); s += v / 1024 } else if (v ~ /Mi$/) { sub(/Mi/, "", v); s += v } else if (v ~ /Gi$/) { sub(/Gi/, "", v); s += v * 1024 } }
  END { printf "%d", s }')"
# Węzły k3d jako kontenery Dockera (kubelet, containerd i pody razem) — to realny koszt dla maszyny.
NODE_MIB="$(docker stats --no-stream --format '{{.Name}} {{.MemUsage}}' 2>/dev/null | awk -v c="k3d-$CLUSTER" '
  index($1, c) == 1 { split($0, a, " "); v = a[2]; if (v ~ /GiB$/) { sub(/GiB/, "", v); s += v * 1024 } else if (v ~ /MiB$/) { sub(/MiB/, "", v); s += v } else if (v ~ /KiB$/) { sub(/KiB/, "", v); s += v / 1024 } }
  END { printf "%d", s }')"
if is_num "${POD_MIB:-}" && [ "${POD_MIB:-0}" -gt 0 ]; then
  check "working set wszystkich podów (top pods) <= 5700 MiB" "tak" "$([ "$POD_MIB" -le 5700 ] && echo tak || echo "nie ($POD_MIB MiB)")"
else
  check "odczyt top pods (metrics-server)" "liczba" "'${POD_MIB:-}'"
fi
if is_num "${NODE_MIB:-}" && [ "${NODE_MIB:-0}" -gt 0 ]; then
  check "3 kontenery węzłów k3d (docker stats) <= 5700 MiB" "tak" "$([ "$NODE_MIB" -le 5700 ] && echo tak || echo "nie ($NODE_MIB MiB)")"
else
  check "odczyt docker stats węzłów k3d" "liczba" "'${NODE_MIB:-}'"
fi
note "pody (top): ${POD_MIB:-?} MiB, węzły (docker stats): ${NODE_MIB:-?} MiB"

# ---------------------------------------------------------------------------
echo
echo -e "${BLD}PASS=$PASS FAIL=$FAIL${NC}"
[ "$FAIL" -eq 0 ]
