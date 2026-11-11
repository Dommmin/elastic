#!/usr/bin/env bash
#  tools/k3d/counts.sh — liczby „bez utraty” migracji RabbitMQ (ETAP G5, spec sekcja 5).
#
#  Wypisuje JEDNĄ linię, sześć liczb oddzielonych spacją (kontrakt: migrate.sh,
#  późniejsze zadania G5 i przewodnik parsują ją po pozycji):
#
#    outbox_total outbox_unpublished processed_events es_count old_queues_total dlq_total
#
#  outbox_total        wiersze tabeli `outbox` w bazie katalogu (CATALOG_DB)
#  outbox_unpublished  z nich published_at IS NULL (jeszcze nie potwierdzone przez brokera)
#  processed_events    ile zdarzeń Z OUTBOXU ma znacznik w `processed_events` (SEARCHSVC_DB,
#                      handler ProductSyncHandler). Liczone po event_id, nie COUNT(*) tabeli:
#                      seed --fresh czyści outbox katalogu, ale nie bazę search, więc stare
#                      znaczniki z poprzednich przebiegów nie mogą zawyżyć wyniku. Unikalny
#                      klucz (event_id, handler) sprawia, że zdarzenie liczy się raz —
#                      processed_events == outbox_total znaczy: każde zdarzenie przetworzone
#                      dokładnie raz (duplikat z okna przełączenia skończyłby na „Duplikat, pomijam”).
#  es_count            _count aliasu products-search w Elasticsearchu
#  old_queues_total    suma wiadomości (ready + unacked) we WSZYSTKICH kolejkach starego
#                      rabbitmq (także delay_*). 0, gdy StatefulSetu rabbitmq już nie ma:
#                      deploy.sh kasuje go dopiero przy pustych kolejkach (strażnik), więc
#                      brak węzła = nic w nim nie zostało. Błąd odczytu przy istniejącym węźle = błąd skryptu.
#  dlq_total           suma wiadomości w kolejkach *.dlq na OBU klastrach
#
#  Kod != 0 i nic na stdout, gdy którejkolwiek liczby nie da się ustalić (nigdy „0 z braku danych”).
#  Nie wypisuje sekretów: hasło ES idzie do curla przez stdin (-K -), nie przez argv.
#
#  Sourcowany (migrate.sh, deploy.sh) daje funkcje; uruchomiony — wypisuje linię.
#  Zgodność: bash 3.2.
# shellcheck source=tools/k3d/lib.sh
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

# cfg <klucz> — wartość z deploy/k8s/config.env (to samo źródło co ConfigMap).
cfg() { awk -F= -v k="$1" '$1 == k { print $2; exit }' "$REPO_ROOT/deploy/k8s/config.env"; }

# psql_q <baza> <sql> — wynik zapytania (bez nagłówków) z postgres-0.
psql_q() {
  kk exec postgres-0 -c postgres -- psql -U "$(cfg POSTGRES_USER)" -d "$1" -v ON_ERROR_STOP=1 -Atqc "$2"
}

is_num() { case "${1:-}" in ''|*[!0-9]*) return 1 ;; esac; return 0; }

# rmq_sum_parse <sufiks nazwy | "" = wszystkie> — stdin: wyjście `list_queues name messages`.
# Fail closed: pasujący wiersz bez liczby (kolejka bez lidera / niedostępna) albo z
# nienumeryczną liczbą = kod 1 i nic na stdout (nigdy „0 z braku danych”).
# Dopasowanie po sufiksie przez substr, bez regexu (escape `\.` różni się w BSD awk i gawk).
rmq_sum_parse() {
  awk -v suf="$1" '
    NF == 0 { next }
    {
      m = 1
      if (suf != "") { n = length(suf); m = (length($1) >= n && substr($1, length($1) - n + 1) == suf) }
      if (!m) next
      if (NF != 2 || $2 !~ /^[0-9]+$/) { bad = 1; next }
      s += $2
    }
    END { if (bad) exit 1; print s + 0 }'
}

# rmq_sum <pod> <sufiks nazwy | ""> — suma kolumny messages w kolejkach pasujących do sufiksu.
rmq_sum() {
  local out
  out="$(kk exec "$1" -c rabbitmq -- rabbitmqctl list_queues -q --no-table-headers name messages 2>/dev/null)" || return 1
  printf '%s\n' "$out" | rmq_sum_parse "$2"
}

# old_state — present | absent (StatefulSet rabbitmq); błąd API = kod 1 i nic na stdout
# (nie „absent”: brak odpowiedzi API nie może udawać pustego starego węzła).
old_state() {
  local o
  o="$(kk get sts rabbitmq --ignore-not-found -o name)" || return 1
  if [ -n "$o" ]; then echo present; else echo absent; fi
}

# ha_pod — pierwszy pod rabbitmq-ha, który odpowiada na rabbitmqctl (do odczytów).
ha_pod() {
  local i
  for i in 0 1 2; do
    if kk exec "rabbitmq-ha-server-$i" -c rabbitmq -- rabbitmqctl -q status >/dev/null 2>&1; then
      echo "rabbitmq-ha-server-$i"; return 0
    fi
  done
  return 1
}

old_queues_total() {
  local st
  st="$(old_state)" || return 1
  case "$st" in
    absent) echo 0 ;;
    present) rmq_sum rabbitmq-0 '' ;;
    *) return 1 ;;
  esac
}

dlq_total() {
  local pod ha old=0 st
  pod="$(ha_pod)" || return 1
  ha="$(rmq_sum "$pod" '.dlq')" || return 1
  st="$(old_state)" || return 1
  if [ "$st" = present ]; then old="$(rmq_sum rabbitmq-0 '.dlq')" || return 1; fi
  is_num "$ha" && is_num "$old" || return 1
  echo $((ha + old))
}

catalog_products() { psql_q "$(cfg CATALOG_DB)" "select count(*) from products"; }

# processed_events — liczba event_id z outboxu obecnych w processed_events (przecięcie zbiorów).
processed_events() {
  local tmp n
  tmp="$(mktemp -d)" || return 1
  psql_q "$(cfg CATALOG_DB)" "select lower(event_id) from outbox" | sort -u > "$tmp/outbox" \
    && psql_q "$(cfg SEARCHSVC_DB)" "select lower(event_id) from processed_events where handler = 'App\\MessageHandler\\ProductSyncHandler'" \
      | sort -u > "$tmp/processed" \
    && n="$(comm -12 "$tmp/outbox" "$tmp/processed" | wc -l | tr -d ' ')"
  local rc=$?
  rm -rf "$tmp"
  [ "$rc" -eq 0 ] && echo "$n"
}

es_count() {
  local u p out
  u="$(kk get secret es-user-searchsvc -o jsonpath='{.data.username}' | base64 -d)" || return 1
  p="$(kk get secret es-user-searchsvc -o jsonpath='{.data.password}' | base64 -d)" || return 1
  [ -n "$u" ] && [ -n "$p" ] || return 1
  # Odświeżenie indeksu przed _count: inaczej liczba spóźnia się o refresh_interval.
  printf 'user = "%s:%s"\n' "$u" "$p" | kk exec -i marketplace-es-default-0 -c elasticsearch -- \
    curl -sf -o /dev/null -K - -XPOST http://localhost:9200/products-search/_refresh 2>/dev/null || return 1
  out="$(printf 'user = "%s:%s"\n' "$u" "$p" | kk exec -i marketplace-es-default-0 -c elasticsearch -- \
    curl -sf -K - http://localhost:9200/products-search/_count 2>/dev/null)" || return 1
  printf '%s' "$out" | sed -n 's/.*"count":\([0-9][0-9]*\).*/\1/p'
}

# counts_line — sześć liczb kontraktu; kod 1, gdy którejś brak.
counts_line() {
  local ot ou pe es oq dq
  ot="$(psql_q "$(cfg CATALOG_DB)" "select count(*) from outbox")"
  ou="$(psql_q "$(cfg CATALOG_DB)" "select count(*) from outbox where published_at is null")"
  pe="$(processed_events)"
  es="$(es_count)"
  oq="$(old_queues_total)"
  dq="$(dlq_total)"
  local v
  for v in "$ot" "$ou" "$pe" "$es" "$oq" "$dq"; do is_num "$v" || return 1; done
  echo "$ot $ou $pe $es $oq $dq"
}

# counts_converged — kryterium „bez utraty” (spec sekcja 1, pkt 3), jedno źródło dla migrate.sh
# i verify.sh: outbox_unpublished = 0, processed_events = outbox_total, es_count = produkty
# w bazie katalogu, stare kolejki = 0, DLQ = 0. Ustawia COUNTS (linia counts_line) i PRODUCTS
# (do komunikatu). Kod 1 także, gdy którejś liczby nie da się ustalić.
COUNTS=""; PRODUCTS=""
counts_converged() {
  COUNTS="$(counts_line)" || return 1
  PRODUCTS="$(catalog_products)" || return 1
  set -- $COUNTS
  [ "$2" -eq 0 ] && [ "$3" -eq "$1" ] && [ "$4" -eq "$PRODUCTS" ] && [ "$5" -eq 0 ] && [ "$6" -eq 0 ]
}

if [ "${BASH_SOURCE[0]}" = "$0" ]; then
  set -uo pipefail
  cluster_exists || die "klaster $CLUSTER nie istnieje (make g5-up)"
  counts_line || die "counts: nie udało się ustalić wszystkich liczb"
fi
