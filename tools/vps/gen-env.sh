#!/usr/bin/env bash
# ============================================================================
#  gen-env.sh <szablon> <cel> — .env z losowymi sekretami (ETAP D)
#
#  Każde `__GENERATE__` w szablonie dostaje osobną losową wartość:
#    *_APP_KEY  -> base64:<32 losowe bajty>   (format APP_KEY Laravela)
#    *_KEY      -> 64 znaki hex               (Kibana wymaga min. 32)
#    pozostałe  -> 48 znaków hex              (bezpieczne w DSN-ach)
#
#  Nigdy nie nadpisuje istniejącego pliku: utrata .env na serwerze = utrata
#  haseł do baz z danymi. Wtedy exit 1 i nic się nie dzieje.
#  Nie wypisuje żadnej wygenerowanej wartości.
# ============================================================================
set -euo pipefail

TEMPLATE="${1:?użycie: gen-env.sh <szablon> <cel>}"
TARGET="${2:?użycie: gen-env.sh <szablon> <cel>}"

if [ -e "${TARGET}" ]; then
  echo "gen-env: ${TARGET} już istnieje — nie nadpisuję (sekrety by przepadły)." >&2
  exit 1
fi

umask 077
TMP="$(mktemp "${TARGET}.XXXXXX")"
trap 'rm -f "${TMP}"' EXIT

while IFS= read -r line || [ -n "${line}" ]; do
  if [[ "${line}" =~ ^([A-Z0-9_]+)=__GENERATE__$ ]]; then
    key="${BASH_REMATCH[1]}"
    case "${key}" in
      *_APP_KEY) value="base64:$(openssl rand -base64 32)" ;;
      *_KEY)     value="$(openssl rand -hex 32)" ;;
      *)         value="$(openssl rand -hex 24)" ;;
    esac
    printf '%s=%s\n' "${key}" "${value}"
  else
    printf '%s\n' "${line}"
  fi
done < "${TEMPLATE}" > "${TMP}"

chmod 600 "${TMP}"
mv "${TMP}" "${TARGET}"
trap - EXIT
echo "gen-env: zapisano ${TARGET} ($(grep -c '' "${TARGET}") linii, uprawnienia 600)."
