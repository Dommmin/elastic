#!/usr/bin/env bash
# ============================================================================
#  Ponowienie DLQ przez dynamiczny shovel (ETAP 13, RUNBOOK z Task 12).
#
#  Użycie: tools/seller360/replay-dlq.sh <kolejka-dlq> <kolejka-docelowa>
#    np.   tools/seller360/replay-dlq.sh search.seller360.dlq search.seller360
#          tools/seller360/replay-dlq.sh search.analytics.ingest.dlq search.analytics.ingest
#
#  Przenosi wiadomości z DLQ do kolejki docelowej przez DOMYŚLNY exchange z routing key = nazwa kolejki,
#  więc trafiają tylko tam (nie do wszystkich kolejek związanych z marketplace.events — np.
#  search.product.sync nie dostaje duplikatów). `src-delete-after: queue-length` = shovel zabiera tyle
#  wiadomości, ile było w DLQ w chwili startu, i sam się kończy; parametr usuwamy na koniec.
#  Bezpieczne dla seller-360: każde zdarzenie niesie pełny stan sekcji i `version` (strażnik noop),
#  a zdarzenia analityki mają stałe `_id` (409 przy duplikacie).
#
#  Wtyczka rabbitmq_shovel: TYLKO W CZASIE DZIAŁANIA. Nie ma jej w infra/rabbitmq/enabled_plugins
#  (plik montowany tylko do odczytu, `rabbitmq-plugins enable` odmawia: "target node uses a different
#  path"), więc skrypt startuje ją w działającym węźle przez `rabbitmqctl eval` — po restarcie
#  RabbitMQ znika, a kolejne wywołanie skryptu uruchomi ją ponownie. Celowo nie włączamy jej na
#  stałe: ponowienie DLQ to rzadka operacja ręczna, a trwała wtyczka wymagałaby zmiany obrazu,
#  pliku enabled_plugins i manifestów k8s (rabbitmq.yaml, rabbitmq-ha.yaml). Nigdy nie używamy
#  `rabbitmqadmin get` (konsumuje wiadomości).
# ============================================================================
set -euo pipefail

DC="${DC:-docker compose}"
SRC="${1:?podaj kolejkę DLQ}"
DEST="${2:?podaj kolejkę docelową}"
NAME="replay-${SRC//./-}"

rmq() { ${DC} exec -T rabbitmq "$@"; }
depth() { rmq rabbitmqctl -t 60 list_queues -q name messages 2>/dev/null | awk -v q="$1" '$1==q {print $2}'; }

# 1. Wtyczka shovel w działającym węźle (bez zapisu do enabled_plugins); start_apps jest idempotentne.
rmq rabbitmqctl eval 'rabbit:start_apps(rabbit_plugins:dependencies(false, [rabbitmq_shovel], rabbit_plugins:list(rabbit_plugins:plugins_dir()))).' >/dev/null

before_src="$(depth "${SRC}")"; before_dest="$(depth "${DEST}")"
echo "[replay] ${SRC}: ${before_src:-?} wiadomości, ${DEST}: ${before_dest:-?} przed shovelem"

# 2. Dynamiczny shovel: DLQ -> domyślny exchange, routing key = kolejka docelowa.
rmq rabbitmqctl set_parameter shovel "${NAME}" "$(printf '{"src-protocol":"amqp091","src-uri":"amqp://","src-queue":"%s","src-delete-after":"queue-length","dest-protocol":"amqp091","dest-uri":"amqp://","dest-exchange":"","dest-exchange-key":"%s","ack-mode":"on-confirm"}' "${SRC}" "${DEST}")" >/dev/null

# 3. Czekamy, aż DLQ będzie pusta (shovel z queue-length kończy się sam), potem usuwamy parametr.
for _ in $(seq 1 300); do
  left="$(depth "${SRC}")"
  [ "${left:-0}" = "0" ] && break
  sleep 2
done
rmq rabbitmqctl clear_parameter shovel "${NAME}" >/dev/null 2>&1 || true

echo "[replay] ${SRC}: $(depth "${SRC}") wiadomości, ${DEST}: $(depth "${DEST}") po shovelu (parametr ${NAME} usunięty)"
