#!/usr/bin/env bash
#  tools/k3d/stream-demo.sh [N] — demo Streamu RabbitMQ (ETAP G5, spec sekcja 6).
#
#  Publikuje N (domyślnie 1000) zdarzeń `user.searched` przez exchange marketplace.events
#  (perf-test jako użytkownik marketplace) i pokazuje, czym stream różni się od kolejki:
#    - dwóch niezależnych czytelników z x-stream-offset=first dostaje KAŻDY całą historię,
#    - czytelnik ze znacznikiem czasu sprzed publikacji dostaje dokładnie tę partię,
#    - czytelnik z offsetem `next` (tylko nowe) dostaje 0,
#    - po wszystkich odczytach stream nadal trzyma te same wiadomości (nic nie znika po ack).
#  Liczby czytelników pochodzą z metryk perf-test (PERF-COUNTERS consumed=K, patrz perf-test.sh).
#  Stream trzyma wiadomości 7 dni, więc kolejne uruchomienia widzą też historię poprzednich:
#  oczekiwane „first” = stan streamu PRZED publikacją + N (pierwsze uruchomienie: baseline 0).
#
#  Ostatnia linia stdout (kontrakt dla verify.sh, pola po spacji):
#    STREAM-DEMO baseline=B published=N confirmed>=C first_a=X first_b=Y timestamp=Z next=W stream_after=S
#  Kod != 0, gdy którejś liczby nie da się ustalić. Sprząta po sobie: kopię zdarzeń w kolejce
#  search.analytics.ingest (nikt jej nie konsumuje) czyści tylko wtedy, gdy przed demem była pusta.
#  Zgodność: bash 3.2.
# shellcheck source=tools/k3d/lib.sh
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
# shellcheck source=tools/k3d/counts.sh
. "$(dirname "${BASH_SOURCE[0]}")/counts.sh"   # ha_pod, is_num

N="${1:-1000}"
is_num "$N" && [ "$N" -gt 0 ] || die "użycie: stream-demo.sh [N>0]"
cluster_exists || die "klaster $CLUSTER nie istnieje (make g5-up)"
PERF="$REPO_ROOT/tools/k3d/perf-test.sh"
STREAM="search.analytics.stream"
INGEST="search.analytics.ingest"

POD="$(ha_pod)" || die "żaden pod rabbitmq-ha nie odpowiada"
# qmsgs <kolejka> — liczba wiadomości (ready+unacked); pusty wynik = błąd.
qmsgs() {
  kk exec "$POD" -c rabbitmq -- rabbitmqctl list_queues -q --no-table-headers name messages 2>/dev/null \
    | awk -v q="$1" '$1 == q { print $2; found = 1 } END { exit found ? 0 : 1 }'
}
# counter <Job> <pole> — pole z linii PERF-COUNTERS w logu Joba.
counter() {
  kk logs "job/perf-test-$1" 2>/dev/null | sed -n "s/.*PERF-COUNTERS.* $2=\([0-9][0-9]*\).*/\1/p" | tail -n 1
}
# reader <nazwa> <offset> — czytelnik streamu (konsument bez publikowania). `-e ""` = domyślny
# exchange: perf-test nie deklaruje ani nie wiąże niczego (marketplace nie ma configure na exchange'ach).
# `-ew idle` kończy po 1 s ciszy; `-q 100` to prefetch wymagany przez konsumenta streamu.
reader() {
  PERF_SECRET=rabbitmq-ha-marketplace-user PERF_COUNTERS=1 PERF_NOLOGS=1 PERF_TIMEOUT=120 \
    bash "$PERF" "$1" -e "" -x 0 -y 1 -p -u "$STREAM" -q 100 -sco "$2" -ew idle
}

BASE="$(qmsgs "$STREAM")" && is_num "$BASE" || die "nie udało się odczytać liczby wiadomości w $STREAM"
INGEST_BEFORE="$(qmsgs "$INGEST")" && is_num "$INGEST_BEFORE" || die "nie udało się odczytać liczby wiadomości w $INGEST"
log "stream $STREAM przed demem: $BASE wiadomości"

# Znacznik czasu z zegara klastra (nie Maca — zegar VM Dockera może się różnić). Pauza przed nim
# i po nim: offset po czasie wybiera chunk, więc wcześniejsze wiadomości nie mogą dzielić chunka z pierwszą nową.
sleep 2
T0="$(kk exec "$POD" -c rabbitmq -- date -u +%Y-%m-%dT%H:%M:%SZ)" || die "nie udało się odczytać czasu z klastra"
case "$T0" in 20??-??-??T??:??:??Z) ;; *) die "dziwny znacznik czasu z klastra: $T0" ;; esac
sleep 1

log "publikuję $N zdarzeń user.searched przez marketplace.events"
PERF_SECRET=rabbitmq-ha-marketplace-user PERF_COUNTERS=1 PERF_NOLOGS=1 PERF_TIMEOUT=120 \
  bash "$PERF" stream-pub -e marketplace.events -t topic -k user.searched -x 1 -y 0 -C "$N" -c 100 -r 500 -p \
  || die "publikacja nie powiodła się"
# Liczba z metryk bywa spóźniona o ostatni odczyt (publikator kończy bez ciszy na końcu), więc
# autorytetem jest stan streamu: dokładnie BASE+N wiadomości (perf-test z -C wysyła ni mniej, ni więcej).
CONFIRMED="$(counter stream-pub confirmed)"
is_num "$CONFIRMED" || die "brak licznika potwierdzeń z publikatora"
PUBLISHED_IN="$(qmsgs "$STREAM")" && [ "$PUBLISHED_IN" -eq $((BASE + N)) ] \
  || die "w $STREAM jest ${PUBLISHED_IN:-?} wiadomości, oczekiwano $((BASE + N))"

# Dwóch czytelników od `first` równolegle (niezależne konsumpcje tego samego logu).
log "czytelnicy A i B: x-stream-offset=first (równolegle)"
reader stream-a first & PA=$!
reader stream-b first & PB=$!
wait "$PA" || die "czytelnik A nie zakończył się sukcesem"
wait "$PB" || die "czytelnik B nie zakończył się sukcesem"
log "czytelnik C: x-stream-offset=$T0 (znacznik czasu sprzed publikacji)"
reader stream-ts "$T0" || die "czytelnik C nie zakończył się sukcesem"
log "czytelnik D: x-stream-offset=next (tylko nowe)"
reader stream-next next || die "czytelnik D nie zakończył się sukcesem"

A="$(counter stream-a consumed)"; B="$(counter stream-b consumed)"
C="$(counter stream-ts consumed)"; D="$(counter stream-next consumed)"
AFTER="$(qmsgs "$STREAM")"
for v in "$A" "$B" "$C" "$D" "$AFTER"; do is_num "$v" || die "brak liczby z czytelnika (A=$A B=$B C=$C D=$D po=$AFTER)"; done

# Kolejka ingest dostała kopię tych samych zdarzeń (binding user.#), ale nikt jej nie czyta.
INGEST_AFTER="$(qmsgs "$INGEST")"
if [ "$INGEST_BEFORE" = 0 ] && is_num "$INGEST_AFTER" && [ "$INGEST_AFTER" -le "$N" ]; then
  kk exec "$POD" -c rabbitmq -- rabbitmqctl purge_queue "$INGEST" >/dev/null 2>&1 \
    && log "kopia zdarzeń w $INGEST ($INGEST_AFTER) wyczyszczona (przed demem była pusta)"
fi

log "stream po odczytach: $AFTER wiadomości (przed: $BASE, +$N) — odczyt niczego nie skasował"
printf 'STREAM-DEMO baseline=%s published=%s confirmed=%s first_a=%s first_b=%s timestamp=%s next=%s stream_after=%s\n' \
  "$BASE" "$N" "$CONFIRMED" "$A" "$B" "$C" "$D" "$AFTER"
