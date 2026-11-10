#!/usr/bin/env bash
#  tools/k3d/images.sh — buduje obrazy produkcyjne (catalog, search, postgres, elasticsearch, rabbitmq) lokalnie
#  i ładuje je do klastra `elastic-g5` przez `k3d image import` (ETAP G5).
#  Bez pulla z GHCR i bez rejestru; tag g5-local nadpisuje się w overlayu.
#  Hosta i węzły k3d są arm64 — budujemy natywnie.
set -uo pipefail
# shellcheck source=tools/k3d/lib.sh
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

TAG="g5-local"
REGISTRY="ghcr.io/dommmin"

command -v k3d >/dev/null 2>&1 || die "brak k3d (brew install k3d)"
cluster_exists || die "klaster $CLUSTER nie istnieje (make g5-up)"

# Wersje z .env.example (jedno źródło prawdy, jak w images.yml). ENV_EXAMPLE: nadpisanie do testów.
ENV_EXAMPLE="${ENV_EXAMPLE:-$REPO_ROOT/.env.example}"
ver() {
  local v
  v="$(grep -E "^$1=" "$ENV_EXAMPLE" | head -1 | cut -d= -f2)"
  [ -n "$v" ] || die "brak klucza $1 w $ENV_EXAMPLE"
  printf '%s\n' "$v"
}
FRANKENPHP_VERSION="$(ver FRANKENPHP_VERSION)" || exit 1
PHP_VERSION="$(ver PHP_VERSION)" || exit 1
NODE_VERSION="$(ver NODE_VERSION)" || exit 1
POSTGRES_VERSION="$(ver POSTGRES_VERSION)" || exit 1
ES_VERSION="$(ver ES_VERSION)" || exit 1
RABBITMQ_VERSION="$(ver RABBITMQ_VERSION)" || exit 1

cd "$REPO_ROOT" || die "brak katalogu repo"

log "build catalog (prod)"
docker build -f infra/php/catalog.Dockerfile --target prod \
  --build-arg "FRANKENPHP_VERSION=${FRANKENPHP_VERSION}" \
  --build-arg "PHP_VERSION=${PHP_VERSION}" \
  --build-arg "NODE_VERSION=${NODE_VERSION}" \
  --build-arg "APP_VERSION=${TAG}" \
  -t "${REGISTRY}/elastic-catalog:${TAG}" . || die "build catalog nie powiódł się"

log "build search (prod)"
docker build -f infra/php/search.Dockerfile --target prod \
  --build-arg "FRANKENPHP_VERSION=${FRANKENPHP_VERSION}" \
  --build-arg "PHP_VERSION=${PHP_VERSION}" \
  -t "${REGISTRY}/elastic-search:${TAG}" . || die "build search nie powiódł się"

log "build postgres"
docker build --build-arg "POSTGRES_VERSION=${POSTGRES_VERSION}" \
  -t "${REGISTRY}/elastic-postgres:${TAG}" infra/postgres || die "build postgres nie powiódł się"

log "build elasticsearch"
docker build --build-arg "ES_VERSION=${ES_VERSION}" \
  -t "${REGISTRY}/elastic-elasticsearch:${TAG}" infra/elasticsearch || die "build elasticsearch nie powiódł się"

log "build rabbitmq"
docker build --build-arg "RABBITMQ_VERSION=${RABBITMQ_VERSION}" \
  -t "${REGISTRY}/elastic-rabbitmq:${TAG}" infra/rabbitmq || die "build rabbitmq nie powiódł się"

log "import do $CLUSTER"
k3d image import -c "$CLUSTER" \
  "${REGISTRY}/elastic-catalog:${TAG}" \
  "${REGISTRY}/elastic-search:${TAG}" \
  "${REGISTRY}/elastic-postgres:${TAG}" \
  "${REGISTRY}/elastic-elasticsearch:${TAG}" \
  "${REGISTRY}/elastic-rabbitmq:${TAG}" || die "k3d image import nie powiódł się"
log "obrazy zaimportowane"
