# shellcheck shell=bash
# shellcheck disable=SC2034  # SUMMARY/ALERT/LIMIT_MIN czyta chaos.sh
#  queue-blocked — użytkownik RabbitMQ `marketplace` traci uprawnienie write do exchange
#  `marketplace.events` (jedynego, na który publikuje outbox-publisher).
#
#  Zmiana `set_permissions`: configure i read bez zmian, write = regex wykluczający dokładnie
#  ten exchange. Publisher dostaje odmowę (ACCESS_REFUSED) przy publikacji, wiersze outboxa
#  zostają nieopublikowane i starzeją się. Nic nie jest kasowane ani konsumowane z żadnej
#  kolejki; konsument (basic.get) potrzebuje tylko read, a retry/failure Messengera idą
#  przez inne exchange (marketplace.delays, failed), więc nic innego nie traci dostępu.
#  Pierwotne uprawnienia zapisujemy w `extra` blokady (JSON); undo je przywraca, a gdy
#  `extra` brakuje, wraca do `.* .* .*` (wartości sprzed G4: lista z `list_permissions`).
#  Dlaczego nie `max-length 0` + `reject-publish`: kolejka quorum przyjmuje jedną
#  wiadomość do pustej kolejki, a konsument opróżnia ją w ~100 ms, więc nack nie przychodzi
#  (spec § 3.5). Bez targets(): stan to uprawnienia brokera, undo woła rmq przez exec.

SUMMARY="Zmiany produktów nie docierają do wyszukiwarki, a najstarsze zdarzenie w outbox starzeje się coraz bardziej"
ALERT="OutboxStale"
LIMIT_MIN=7

QB_USER="marketplace"
QB_VHOST="/"
QB_WRITE_BAD='^(?!marketplace\.events$).*$'
QB_DEFAULT='{"c":".*","w":".*","r":".*"}'
QB_AGE_METRIC="catalog_outbox_oldest_pending_age_seconds"

targets() { :; }

qb_age_over() { awk -v v="$(prom_val "${QB_AGE_METRIC}")" -v t="$1" 'BEGIN { exit !(v ~ /^[0-9.]+$/ && v + 0 > t) }'; }
qb_age_under() { awk -v v="$(prom_val "${QB_AGE_METRIC}")" -v t="$1" 'BEGIN { exit !(v ~ /^[0-9.]+$/ && v + 0 < t) }'; }

# qb_perms -> {"c":..,"w":..,"r":..} dla użytkownika w vhoście (pusty = nie odczytano).
qb_perms() {
  rmq list_user_permissions "${QB_USER}" --formatter json 2>/dev/null \
    | jq -c --arg v "${QB_VHOST}" '[.[] | select(.vhost == $v)][0] | select(. != null) | {c: .configure, w: .write, r: .read}' 2>/dev/null
}

# Zapisane uprawnienia z blokady, a gdy ich brak albo są uszkodzone: domyślne.
qb_saved() {
  local x; x="$(lock_get extra)"
  # Zapisane `w` równe awaryjnemu regexowi to uszkodzenie (break nie powinien tego zapisać):
  # nie przywracamy awarii, tylko wartość domyślną.
  # Każde z c/w/r musi być NIEPUSTYM napisem: w jq "" jest prawdą, a set_permissions
  # z pustym regexem odebrałby wszystkie uprawnienia.
  jq -e --arg bad "${QB_WRITE_BAD}" '
    all(.c, .w, .r; type == "string" and length > 0) and (.w != $bad)' <<<"${x}" >/dev/null 2>&1 \
    && jq -c . <<<"${x}" || echo "${QB_DEFAULT}"
}

qb_apply() { # qb_apply <json c/w/r>
  local c w r
  c="$(jq -r .c <<<"$1")"; w="$(jq -r .w <<<"$1")"; r="$(jq -r .r <<<"$1")"
  rmq set_permissions -p "${QB_VHOST}" "${QB_USER}" "${c}" "${w}" "${r}" >/dev/null
}

qb_write_ok() { [[ "$(qb_perms | jq -r .w 2>/dev/null)" != "${QB_WRITE_BAD}" ]]; }

break() {
  local orig c r
  orig="$(qb_perms)"
  [[ -n "${orig}" ]] || { echo "nie odczytałem uprawnień użytkownika ${QB_USER}" >&2; return 1; }
  # Uprawnienia już zawężone (zostałe po nieudanym wycofaniu): klaster nie jest zdrowy,
  # a zapisanie ich jako „oryginału” sprawiłoby, że undo przywróciłoby awarię.
  [[ "$(jq -r .w <<<"${orig}")" != "${QB_WRITE_BAD}" ]] \
    || { echo "uprawnienia ${QB_USER} są już zawężone (write: ${QB_WRITE_BAD}) — klaster nie jest w stanie wyjściowym" >&2; return 1; }
  lock_set extra "${orig}" || { echo "nie zapisałem uprawnień do blokady" >&2; return 1; }
  c="$(jq -r .c <<<"${orig}")"; r="$(jq -r .r <<<"${orig}")"
  rmq set_permissions -p "${QB_VHOST}" "${QB_USER}" "${c}" "${QB_WRITE_BAD}" "${r}" >/dev/null || return 1
  # Bez ruchu w outbox nie ma czego publikować.
  touch_product || { echo "touch_product nie wygenerował zdarzenia outbox" >&2; return 1; }
  wait_for 180 qb_age_over 60 || { echo "wiek najstarszego wpisu outbox nie przekroczył 60 s w 180 s" >&2; return 1; }
}

check() {
  local want got
  want="$(qb_saved)"; got="$(qb_perms)"
  # Uczeń może przywrócić `.*` zamiast oryginału: wystarczy, że write znów obejmuje exchange.
  expect_hidden "outbox-publisher może publikować zdarzenia" \
    "użytkownik ${QB_USER} ma write do marketplace.events" "tak" "$(qb_write_ok && echo tak || echo nie)"
  expect_hidden "konfiguracja brokera jak przed awarią" \
    "uprawnienia ${QB_USER} jak przed awarią ($(jq -r '[.c,.w,.r]|join(" ")' <<<"${want}"))" "${want}" "${got}"
  wait_for 240 qb_age_under 60 || true
  expect "najstarszy wpis outbox młodszy niż 60 s (jest: $(prom_val "${QB_AGE_METRIC}"))" "tak" \
    "$(qb_age_under 60 && echo tak || echo nie)"
}

# Idempotentne: ustawia zapisane (albo domyślne) uprawnienia, potem weryfikuje.
undo() {
  local want; want="$(qb_saved)"
  qb_apply "${want}" || return 1
  [[ "$(qb_perms)" == "${want}" ]] || { echo "uprawnienia ${QB_USER} nie wróciły do ${want}" >&2; return 1; }
}
