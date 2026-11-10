#!/usr/bin/env bash
#  tools/k3d/secrets.sh — Secrety sklepu na k3d `elastic-g5` (ETAP G5).
#  Te same klucze co tools/k3s/secrets.sh (VPS), ale hasła LOSOWE: generowane przy
#  pierwszym uruchomieniu i nigdy nie nadpisywane (istniejąca wartość wygrywa).
#  Dodatkowo: MESSENGER_TRANSPORT_DSN_HA (host rabbitmq-ha), Secret
#  rabbitmq-ha-marketplace-user i rabbitmq-shovel-uris (dla shovela, Task 3).
#  Nie wypisuje żadnej wartości.
set -uo pipefail
# shellcheck source=tools/k3d/lib.sh
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

cluster_exists || die "klaster $CLUSTER nie istnieje (make g5-up)"

# Stan Secretów pobieramy RAZ z góry, do zmiennych powłoki (nie do plików). stdout i stderr
# osobno: stderr służy tylko do rozpoznania NotFound, więc ostrzeżenie klienta k8s nie psuje JSON-a.
# NotFound = Secret naprawdę nie istnieje; inny błąd API albo JSON, którego nie da się
# zdekodować = die (nigdy „brak”), żeby nie wygenerować nowego hasła nad zainicjowanym.
# SECRETS_FETCH_CMD: podmiana pobierania w testach (domyślnie przez kk).
fetch_secret() { kk get secret "$1" -o json 2>"$2"; }
SECRETS_FETCH_CMD="${SECRETS_FETCH_CMD:-fetch_secret}"
ERRF="$(mktemp)"; trap 'rm -f "$ERRF"' EXIT
snapshot() {   # snapshot <secret> — ustawia SNAP_<nazwa> na linie "klucz base64(wartość)"
  local name="$1" out lines var
  var="SNAP_${name//-/_}"
  if out="$($SECRETS_FETCH_CMD "$name" "$ERRF")"; then
    lines="$(printf '%s' "$out" | ruby -rjson -e 'd = JSON.parse(STDIN.read); abort("brak obiektu data/metadata") unless d.is_a?(Hash) && d["kind"] == "Secret"; (d["data"] || {}).each { |k, v| puts "#{k} #{v}" }' 2>/dev/null)" \
      || die "Secret $name: odpowiedź API nie daje się zdekodować (nie traktuję jako brak)"
    printf -v "$var" '%s' "$lines"
  elif grep -q 'NotFound' "$ERRF"; then
    printf -v "$var" '%s' ''
  else
    die "nie udało się odczytać Secretu $name (API niedostępne?): $(head -1 "$ERRF")"
  fi
}
for s in marketplace-secrets marketplace-es-elastic-user rabbitmq-ha-marketplace-user; do snapshot "$s"; done
# Namespace dopiero po odczycie stanu (błąd API ma zginąć na snapshocie, nie na apply).
kc apply -f "$REPO_ROOT/deploy/k8s/namespace.yaml" >/dev/null || die "namespace $NS nie powstał"

# existing <secret> <klucz> — dotychczasowa wartość (pusta tylko, gdy klucza naprawdę brak).
existing() {
  local var="SNAP_${1//-/_}" b64
  b64="$(printf '%s\n' "${!var}" | awk -v k="$2" '$1 == k { print $2 }')"
  [ -n "$b64" ] && printf '%s' "$b64" | base64 -d
  return 0
}
# keep <secret> <klucz> <nowa> — istniejąca wartość albo nowa.
keep() {
  local cur
  cur="$(existing "$1" "$2")"
  if [ -n "$cur" ]; then printf '%s' "$cur"; else printf '%s' "$3"; fi
}
rand() { openssl rand -hex 24; }
# apply_secret <nazwa> [--type=…] <klucz=wartość>… — create + apply (idempotentne).
apply_secret() {
  local name="$1"; shift
  local args=() a
  for a in "$@"; do
    case "$a" in
      --type=*) args+=("$a") ;;
      *=*) args+=("--from-literal=$a") ;;
    esac
  done
  kk create secret generic "$name" --dry-run=client -o yaml "${args[@]}" | kk apply -f - >/dev/null \
    || die "secret $name nie powstał"
}

M=marketplace-secrets
POSTGRES_PASSWORD="$(keep $M POSTGRES_PASSWORD "$(rand)")"
CATALOG_DB_PASSWORD="$(keep $M CATALOG_DB_PASSWORD "$(rand)")"
SEARCHSVC_DB_PASSWORD="$(keep $M SEARCHSVC_DB_PASSWORD "$(rand)")"
RABBITMQ_PASSWORD="$(keep $M RABBITMQ_PASSWORD "$(rand)")"
REDIS_PASSWORD="$(keep $M REDIS_PASSWORD "$(rand)")"
ES_CATALOG_PASSWORD="$(keep $M ES_CATALOG_PASSWORD "$(rand)")"
ES_SEARCHSVC_PASSWORD="$(keep $M ES_SEARCHSVC_PASSWORD "$(rand)")"
CATALOG_APP_KEY="$(keep $M CATALOG_APP_KEY "base64:$(openssl rand -base64 32)")"
SEARCH_APP_SECRET="$(keep $M SEARCH_APP_SECRET "$(rand)")"
FAKE_GATEWAY_WEBHOOK_SECRET="$(keep $M FAKE_GATEWAY_WEBHOOK_SECRET "$(rand)")"
ELASTIC_PASSWORD="$(keep marketplace-es-elastic-user elastic "$(rand)")"
# Hasło użytkownika `marketplace` na nowym klastrze RabbitMQ (rabbitmq-ha).
HA_PASSWORD="$(keep rabbitmq-ha-marketplace-user password "$(rand)")"

RABBITMQ_USER=marketplace
apply_secret $M \
  POSTGRES_PASSWORD="$POSTGRES_PASSWORD" \
  CATALOG_DB_PASSWORD="$CATALOG_DB_PASSWORD" \
  SEARCHSVC_DB_PASSWORD="$SEARCHSVC_DB_PASSWORD" \
  RABBITMQ_PASSWORD="$RABBITMQ_PASSWORD" \
  REDIS_PASSWORD="$REDIS_PASSWORD" \
  ES_CATALOG_PASSWORD="$ES_CATALOG_PASSWORD" \
  ES_SEARCHSVC_PASSWORD="$ES_SEARCHSVC_PASSWORD" \
  CATALOG_APP_KEY="$CATALOG_APP_KEY" \
  SEARCH_APP_SECRET="$SEARCH_APP_SECRET" \
  FAKE_GATEWAY_WEBHOOK_SECRET="$FAKE_GATEWAY_WEBHOOK_SECRET" \
  DATABASE_URL="postgresql://searchsvc:${SEARCHSVC_DB_PASSWORD}@postgres:5432/searchsvc?serverVersion=18.4&charset=utf8" \
  MESSENGER_TRANSPORT_DSN="amqp://${RABBITMQ_USER}:${RABBITMQ_PASSWORD}@rabbitmq:5672/%2f" \
  MESSENGER_TRANSPORT_DSN_HA="amqp://${RABBITMQ_USER}:${HA_PASSWORD}@rabbitmq-ha:5672/%2f"

apply_secret marketplace-es-elastic-user elastic="$ELASTIC_PASSWORD"
apply_secret es-user-catalog --type=kubernetes.io/basic-auth username=catalog password="$ES_CATALOG_PASSWORD" roles=catalog_app
apply_secret es-user-searchsvc --type=kubernetes.io/basic-auth username=searchsvc password="$ES_SEARCHSVC_PASSWORD" roles=search_service
apply_secret rabbitmq-ha-marketplace-user username="$RABBITMQ_USER" password="$HA_PASSWORD"
apply_secret rabbitmq-shovel-uris \
  srcUri="amqp://${RABBITMQ_USER}:${RABBITMQ_PASSWORD}@rabbitmq:5672/%2f" \
  destUri="amqp://${RABBITMQ_USER}:${HA_PASSWORD}@rabbitmq-ha:5672/%2f"

log "secrets: OK (marketplace-secrets, marketplace-es-elastic-user, es-user-catalog, es-user-searchsvc, rabbitmq-ha-marketplace-user, rabbitmq-shovel-uris)"
