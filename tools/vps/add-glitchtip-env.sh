#!/usr/bin/env bash
# ============================================================================
#  add-glitchtip-env.sh <szablon> <.env> [adres-admina] — GlitchTip w ISTNIEJĄCYM .env
#
#  gen-env.sh nie nadpisuje istniejącego pliku (utrata .env = utrata haseł do baz), więc
#  serwer, który działał przed GlitchTipem, nie dostaje nowych sekretów z szablonu. Ten
#  pomocnik dopisuje do istniejącego .env tylko to, czego brakuje:
#    - sekcję GLITCHTIP_* z szablonu (wygenerowane klucze DSN, token, hasła, DSN-y),
#    - GLITCHTIP_VERSION i MAILPIT_UI_PORT, jeśli ich nie ma,
#    - profil `errors` w COMPOSE_PROFILES.
#  Reszty pliku nie rusza. Przed zmianą robi kopię `<.env>.bak.<czas>` (600). Jest
#  idempotentny: gdy GLITCHTIP_KEY_CATALOG już jest, kończy bez zmian. Nie wypisuje
#  żadnej wygenerowanej wartości.
#
#  Adres admina (3. argument) trafia do GLITCHTIP_ADMIN_EMAIL; bez niego zostaje
#  `__SET_ME__` i guard.py odmówi startu, dopóki go nie wpiszesz.
# ============================================================================
set -euo pipefail

TEMPLATE="${1:?użycie: add-glitchtip-env.sh <szablon> <.env> [adres-admina]}"
TARGET="${2:?użycie: add-glitchtip-env.sh <szablon> <.env> [adres-admina]}"
ADMIN_EMAIL="${3:-}"
HERE="$(cd "$(dirname "$0")" && pwd)"

[ -f "${TARGET}" ] || { echo "add-glitchtip-env: brak pliku ${TARGET} (nowy serwer: gen-env.sh)." >&2; exit 1; }

if grep -q '^GLITCHTIP_KEY_CATALOG=' "${TARGET}"; then
  echo "add-glitchtip-env: ${TARGET} ma już sekcję GlitchTipa — bez zmian."
  exit 0
fi

umask 077
WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT

# Wygenerowany "wzorcowy" plik z szablonu; bierzemy z niego tylko brakujące linie.
"${HERE}/gen-env.sh" "${TEMPLATE}" "${WORK}/generated.env" >/dev/null

STAMP="$(date +%Y%m%d-%H%M%S)"
cp -p "${TARGET}" "${TARGET}.bak.${STAMP}"
chmod 600 "${TARGET}.bak.${STAMP}"

{
  printf '\n# --- ŚLEDZENIE BŁĘDÓW (GlitchTip, ETAP 11b) — dopisane przez add-glitchtip-env.sh %s ---\n' "${STAMP}"
  grep -E '^GLITCHTIP_' "${WORK}/generated.env" | grep -v '^GLITCHTIP_VERSION='
  for key in GLITCHTIP_VERSION MAILPIT_UI_PORT; do
    grep -q "^${key}=" "${TARGET}" || grep -m1 "^${key}=" "${WORK}/generated.env"
  done
} > "${WORK}/append.env"

if [ -n "${ADMIN_EMAIL}" ]; then
  sed -i.bak "s|^GLITCHTIP_ADMIN_EMAIL=.*|GLITCHTIP_ADMIN_EMAIL=${ADMIN_EMAIL}|" "${WORK}/append.env"
fi

# Profil `errors` w COMPOSE_PROFILES (bez duplikatu).
if grep -q '^COMPOSE_PROFILES=' "${TARGET}"; then
  if ! grep -E '^COMPOSE_PROFILES=' "${TARGET}" | tr ',=' '\n\n' | grep -qx errors; then
    sed -i.bak 's|^COMPOSE_PROFILES=\(.*\)$|COMPOSE_PROFILES=\1,errors|' "${TARGET}"
    rm -f "${TARGET}.bak"
  fi
else
  echo 'COMPOSE_PROFILES=errors' >> "${WORK}/append.env"
fi

cat "${WORK}/append.env" >> "${TARGET}"
chmod 600 "${TARGET}"
echo "add-glitchtip-env: dopisano sekcję GlitchTipa do ${TARGET} (kopia: ${TARGET}.bak.${STAMP})."
[ -n "${ADMIN_EMAIL}" ] || echo "add-glitchtip-env: wpisz GLITCHTIP_ADMIN_EMAIL w ${TARGET} (teraz __SET_ME__)."
