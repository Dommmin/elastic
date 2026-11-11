# shellcheck shell=bash
# shellcheck disable=SC2034  # SUMMARY/ALERT/LIMIT_MIN czyta chaos.sh
#  bad-secret — search-consumer czyta DSN RabbitMQ z innego Secretu, z hasłem o jeden
#  znak złym (rotacja hasła, która nie dotarła wszędzie).
#
#  Secret `chaos-rotated-secrets` ma jeden klucz, MESSENGER_TRANSPORT_DSN. Pod startuje
#  (Secret i klucz istnieją, więc nie ma CreateContainerConfigError), ale broker odrzuca
#  logowanie i konsument pada. Wartości Secretu nie trafiają na ekran, do logów ani do
#  argumentów procesów: DSN powstaje w potoku, ląduje w pliku w katalogu 700 i idzie do
#  kubectl przez --from-file. Dotykamy WYŁĄCZNIE Deploymentu search-consumer
#  (search-metrics ma ten sam klucz, ale zostaje na marketplace-secrets).

SUMMARY="Indeks wyszukiwania się nie aktualizuje: search-consumer w pętli restartów, a kolejka search.product.sync rośnie"
ALERT="SearchConsumerDown"
LIMIT_MIN=8

BS_DEPLOY="search-consumer"
BS_CONTAINER="consumer"
BS_SECRET="chaos-rotated-secrets"
BS_KEY="MESSENGER_TRANSPORT_DSN"
# Wyrażenie reguły SearchConsumerDown z infra/obs/prometheus/rules/marketplace.yml
# (Messenger pobiera przez basic.get, więc kolumna consumers jest zawsze 0 i nie nadaje
# się na sygnał). Wynik = reguła płonie (nikt nie odpytuje kolejki); brak wyniku = ktoś pyta.
BS_RULE_EXPR='(sum(rate(rabbitmq_detailed_queue_get_empty_total{queue="search.product.sync"}[1m])) or vector(0)) + (sum(rate(rabbitmq_detailed_queue_get_ack_total{queue="search.product.sync"}[1m])) or vector(0)) == 0 and on() up{job="rabbitmq-queues"} == 1'

targets() { echo "deployment/${BS_DEPLOY}"; }

# bs_make_secret — Secret ze złym hasłem. Nic nie wypisuje na stdout/stderr.
# Ciało w podpowłoce z pułapką EXIT/INT/TERM: plik z DSN (prawdziwy login, hasło różne
# o jeden znak) znika także przy Ctrl-C w środku break, nie tylko na końcu funkcji.
bs_make_secret() (
  dir=""
  trap 'rm -rf "${dir}"' EXIT
  trap 'exit 130' INT TERM HUP
  rc=0
  dir="$(mktemp -d "${TMPDIR:-/tmp}/chaos-secret.XXXXXX")" || exit 1
  chmod 700 "${dir}"
  file="${dir}/${BS_KEY}"
  # Zamiana OSTATNIEGO znaku hasła (amqp://user:HASŁO@host/vhost); kod 3, gdy DSN nie pasuje.
  k -n "${NS}" get secret marketplace-secrets -o json 2>/dev/null \
    | jq -j --arg key "${BS_KEY}" '.data[$key] // empty | @base64d' 2>/dev/null \
    | perl -0777 -pe '
        BEGIN { $m = 0 }
        $m += s{^([a-z+]+://[^:/@]+:)(.+)(.)(@[^@]*)$}{ $1 . $2 . ($3 eq "x" ? "y" : "x") . $4 }es;
        END { $? = 3 unless $m }' > "${file}" 2>/dev/null
  if [[ "${PIPESTATUS[0]}" -ne 0 || "${PIPESTATUS[1]}" -ne 0 || "${PIPESTATUS[2]}" -ne 0 || ! -s "${file}" ]]; then
    echo "nie udało się zbudować DSN ze złym hasłem (brak klucza ${BS_KEY} albo nietypowy format)" >&2
    rc=1
  else
    k -n "${NS}" delete secret "${BS_SECRET}" --ignore-not-found >/dev/null 2>&1
    k -n "${NS}" create secret generic "${BS_SECRET}" --from-file="${BS_KEY}=${file}" --dry-run=client -o json 2>/dev/null \
      | jq '.metadata.labels = {"app.kubernetes.io/part-of": "chaos"}' \
      | k -n "${NS}" create -f - >/dev/null 2>&1 || rc=1
  fi
  exit "${rc}"
)

# bs_where -> „indeks_kontenera indeks_env” dla DSN w kontenerze consumer (z Deploymentu).
bs_where() {
  k -n "${NS}" get deployment "${BS_DEPLOY}" -o json | jq -r --arg c "${BS_CONTAINER}" --arg e "${BS_KEY}" '
    [.spec.template.spec.containers | to_entries[] | select(.value.name == $c)][0] as $ct
    | [$ct.value.env | to_entries[] | select(.value.name == $e)][0] as $ev
    | if $ct == null or $ev == null then empty else "\($ct.key) \($ev.key)" end'
}

# Nowy pod (szablon z chaos-rotated-secrets) wystartował i się wywrócił: restart albo CrashLoopBackOff.
bs_crashing() {
  k -n "${NS}" get pods -l "app=${BS_DEPLOY}" -o json | jq -e --arg s "${BS_SECRET}" '
    any(.items[];
      any(.spec.containers[].env[]?; .valueFrom.secretKeyRef.name == $s)
      and any(.status.containerStatuses[]?;
            .restartCount >= 1 or .state.waiting.reason == "CrashLoopBackOff"))' >/dev/null
}

break() {
  local ci ei
  read -r ci ei <<<"$(bs_where)"
  [[ -n "${ci:-}" && -n "${ei:-}" ]] || { echo "brak env ${BS_KEY} w kontenerze ${BS_CONTAINER} (${BS_DEPLOY})" >&2; return 1; }
  bs_make_secret || return 1
  k -n "${NS}" patch deployment "${BS_DEPLOY}" --type json -p "[
    {\"op\":\"test\",\"path\":\"/spec/template/spec/containers/${ci}/name\",\"value\":\"${BS_CONTAINER}\"},
    {\"op\":\"test\",\"path\":\"/spec/template/spec/containers/${ci}/env/${ei}/name\",\"value\":\"${BS_KEY}\"},
    {\"op\":\"replace\",\"path\":\"/spec/template/spec/containers/${ci}/env/${ei}/valueFrom/secretKeyRef/name\",\"value\":\"${BS_SECRET}\"}
  ]" >/dev/null || return 1
  wait_for 180 bs_crashing || { echo "nowy pod ${BS_DEPLOY} nie wywrócił się w 180 s" >&2; return 1; }
}

bs_ready() { [[ "$(deploy_ready "${BS_DEPLOY}")" == tak ]]; }

# Liczba odwołań do chaos-rotated-secrets w szablonie (env, envFrom, volumes).
bs_refs() {
  k -n "${NS}" get deployment "${BS_DEPLOY}" -o json 2>/dev/null | jq --arg s "${BS_SECRET}" '
    [.spec.template.spec | (.containers[], .initContainers[]?)
      | (.env[]?.valueFrom.secretKeyRef.name, .envFrom[]?.secretRef.name)
      | select(. == $s)] | length' 2>/dev/null || echo "?"
}

bs_polled() { [[ "$(prom_val "${BS_RULE_EXPR}")" == brak ]]; }

check() {
  wait_for 90 bs_ready || true
  expect "Deployment ${BS_DEPLOY}: rollout skończony, pod gotowy" "tak" "$(deploy_ready "${BS_DEPLOY}")"
  local refs; refs="$(bs_refs)"
  expect_hidden "search-consumer używa danych dostępowych jak przed awarią" \
    "żaden kontener ${BS_DEPLOY} nie czyta Secretu ${BS_SECRET}" "0" "${refs}"
  # Konsument odpytuje kolejkę dopiero po starcie, a rate() z 1 min i scrape Prometheusa
  # opóźniają sygnał o ok. 1-2 min: dajemy mu czas. Najpierw sanity: bez `up` RabbitMQ
  # „brak wyniku” byłby fałszywą zielenią.
  expect "Prometheus scrapuje RabbitMQ (up{job=\"rabbitmq-queues\"} = 1)" "1" "$(prom_val 'up{job="rabbitmq-queues"}')"
  wait_for 180 bs_polled || true
  expect "kolejka search.product.sync jest odpytywana (wyrażenie SearchConsumerDown bez wyniku)" "brak" "$(prom_val "${BS_RULE_EXPR}")"
}

# on_green — hak chaos.sh po zielonym check (blokada nasza): nieużywany Secret ze złym
# hasłem zdejmujemy, żeby po scenariuszu nie zostały obiekty part-of=chaos. Tylko gdy
# szablon go już nie czyta (inaczej skasowanie wywróciłoby pod na CreateContainerConfigError).
on_green() {
  [[ "$(bs_refs)" == 0 ]] || return 0
  k -n "${NS}" delete secret "${BS_SECRET}" --ignore-not-found --wait=false >/dev/null
}

# Przywrócenie szablonu z migawki, potem usunięcie Secretu (po przywróceniu nic go nie czyta).
# Idempotentne: brak Secretu to sukces. Job ma tylko `delete` na tym Secrecie.
undo() {
  snapshot_restore || return 1
  k -n "${NS}" delete secret "${BS_SECRET}" --ignore-not-found --wait=false >/dev/null
}
