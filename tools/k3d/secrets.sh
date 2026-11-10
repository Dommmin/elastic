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
kc apply -f "$REPO_ROOT/deploy/k8s/namespace.yaml" >/dev/null || die "namespace $NS nie powstał"

# Stan Secretów pobieramy RAZ z góry do plików tymczasowych. NotFound = plik pusty
# (Secret naprawdę nie istnieje); każdy inny błąd API (timeout, zły kontekst) = die,
# żeby nie wygenerować nowego hasła nad już zainicjowanym (zablokowałoby Postgresa/ES).
SNAP="$(mktemp -d)"; trap 'rm -rf "$SNAP"' EXIT
snapshot() {
  local name="$1" out
  if out="$(kk get secret "$name" -o json 2>&1)"; then
    printf '%s' "$out" > "$SNAP/$name.json"
  elif printf '%s' "$out" | grep -q 'NotFound'; then
    : > "$SNAP/$name.json"
  else
    die "nie udało się odczytać Secretu $name (API niedostępne?): $(printf '%s' "$out" | head -1)"
  fi
}
for s in marketplace-secrets marketplace-es-elastic-user rabbitmq-ha-marketplace-user; do snapshot "$s"; done

# existing <secret> <klucz> — dotychczasowa wartość (pusta tylko, gdy Secretu/klucza naprawdę brak).
existing() {
  [ -s "$SNAP/$1.json" ] || return 0
  ruby -rjson -rbase64 -e 'v = JSON.parse(File.read(ARGV[0])).dig("data", ARGV[1]); print Base64.decode64(v) if v' "$SNAP/$1.json" "$2"
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
