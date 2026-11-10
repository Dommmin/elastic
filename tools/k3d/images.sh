#!/usr/bin/env bash
#  tools/k3d/images.sh — buduje obrazy produkcyjne (catalog, search, postgres) lokalnie
#  i ładuje je do klastra `elastic-g5` przez `k3d image import` (ETAP G5).
#  Bez pulla z GHCR i bez rejestru; tag g5-local nadpisuje się w overlayu.
#  Hosta i węzły k3d są arm64 — budujemy natywnie.
set -uo pipefail
# shellcheck source=tools/k3d/lib.sh
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

TAG="g5-local"
REGISTRY="ghcr.io/dommmin"

command -v k3d >/dev/null 2>&1 || die "brak k3d (brew install k3d)"
k3d cluster list 2>/dev/null | awk 'NR>1 {print $1}' | grep -qx "$CLUSTER" \
  || die "klaster $CLUSTER nie istnieje (make g5-up)"

# Wersje z .env.example (jedno źródło prawdy, jak w images.yml).
ver() { grep -E "^$1=" "$REPO_ROOT/.env.example" | head -1 | cut -d= -f2; }
FRANKENPHP_VERSION="$(ver FRANKENPHP_VERSION)"
PHP_VERSION="$(ver PHP_VERSION)"
NODE_VERSION="$(ver NODE_VERSION)"
POSTGRES_VERSION="$(ver POSTGRES_VERSION)"

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

log "import do $CLUSTER"
k3d image import -c "$CLUSTER" \
  "${REGISTRY}/elastic-catalog:${TAG}" \
  "${REGISTRY}/elastic-search:${TAG}" \
  "${REGISTRY}/elastic-postgres:${TAG}" || die "k3d image import nie powiódł się"
log "obrazy zaimportowane"
