# shellcheck shell=bash
# shellcheck disable=SC2034  # SUMMARY/ALERT/LIMIT_MIN czyta chaos.sh
#  disk-full — `disk_free_limit` RabbitMQ podniesiony ponad wolne miejsce na dysku (1000GB).
#
#  Broker zachowuje się dokładnie jak przy prawie pełnym dysku: zgłasza alarm `disk`
#  i blokuje połączenia publikujące, więc `outbox:publish` nie ma jak wysłać zdarzeń.
#  Prawdziwego zapełnienia wolumenu nie robimy: `local-path` nie egzekwuje rozmiaru PVC,
#  więc zapchalibyśmy dysk węzła ze sklepem i Elasticsearchem (spec § 4).
#  Limit ustawiony `rabbitmqctl` żyje do restartu poda (wtedy wraca wartość z konfiguracji
#  w `deploy/k8s/rabbitmq.yaml`/obrazu) — restart poda też jest dopuszczalną naprawą.
#  Pierwotny limit zapisujemy w `extra` blokady; undo ustawia go z powrotem.
#  Bez targets(): stan to ustawienie brokera, undo woła rmq przez exec.

SUMMARY="Zmiany produktów nie docierają do wyszukiwarki, a najstarsze zdarzenie w outbox starzeje się coraz bardziej"
ALERT="OutboxStale"
LIMIT_MIN=8

DF_BAD_LIMIT="1000GB"
DF_DEFAULT_LIMIT="1000000000"
DF_AGE_METRIC="catalog_outbox_oldest_pending_age_seconds"

targets() { :; }

df_alarms() { k -n "${NS}" exec rabbitmq-0 -c rabbitmq -- rabbitmq-diagnostics -q alarms 2>/dev/null; }
# Brak alarmów: wyraźne „reported no alarms”; błąd odczytu nie jest „brakiem alarmów”.
df_clear() { df_alarms | grep -q 'reported no alarms'; }
df_alarm_on() { df_alarms | grep -qi 'disk'; }
df_limit() { rmq eval 'rabbit_disk_monitor:get_disk_free_limit().' 2>/dev/null | tr -d '[:space:]'; }
# df_sane <limit>: liczba bajtów poniżej progu awarii (1000GB). Limit równy awaryjnemu
# (albo większy) to uszkodzenie: nie przywracamy awarii, tylko wartość domyślną.
df_sane() { [[ "$1" =~ ^[0-9]{1,15}$ ]] && (( $1 < 1000000000000 )); }
df_age_under() { awk -v v="$(prom_val "${DF_AGE_METRIC}")" -v t="$1" 'BEGIN { exit !(v ~ /^[0-9.]+$/ && v + 0 < t) }'; }

break() {
  local orig
  orig="$(df_limit)"
  # Limit względny albo nieodczytany: wracamy do wartości domyślnej z konfiguracji.
  df_sane "${orig}" || orig="${DF_DEFAULT_LIMIT}"
  lock_set extra "${orig}" || { echo "nie zapisałem pierwotnego limitu do blokady" >&2; return 1; }
  rmq set_disk_free_limit "${DF_BAD_LIMIT}" >/dev/null || return 1
  touch_product || { echo "touch_product nie wygenerował zdarzenia outbox" >&2; return 1; }
  wait_for 120 df_alarm_on || { echo "RabbitMQ nie zgłosił alarmu dysku w 120 s" >&2; return 1; }
}

check() {
  wait_for 60 df_clear || true
  expect "RabbitMQ bez alarmów" "tak" "$(df_clear && echo tak || echo nie)"
  wait_for 90 df_age_under 60 || true
  expect "najstarszy wpis outbox młodszy niż 60 s (jest: $(prom_val "${DF_AGE_METRIC}"))" "tak" \
    "$(df_age_under 60 && echo tak || echo nie)"
}

# Idempotentne: ustawia zapisany limit (albo domyślny), czeka aż alarm zniknie.
# Do limitu ≤ 60 s, żeby w Jobie (~600 s po przebudzeniu) zostać z zapasem.
undo() {
  local orig
  orig="$(lock_get extra)"
  df_sane "${orig}" || orig="${DF_DEFAULT_LIMIT}"
  rmq set_disk_free_limit "${orig}" >/dev/null || return 1
  wait_for 60 df_clear || { echo "alarm dysku nie zniknął w 60 s po przywróceniu limitu ${orig}" >&2; return 1; }
}
