#!/bin/sh
# ============================================================================
#  Generuje /etc/rabbitmq/definitions.json z szablonu + użytkownika z env,
#  potem oddaje sterowanie oryginalnemu entrypointowi obrazu.
#
#  Ten sam algorytm co tools/render-rabbitmq-definitions.py
#  (rabbit_password_hashing_sha256):
#    base64( sól[4 bajty] + sha256( sól + hasło ) )
#  Dlaczego użytkownik MUSI być w definicjach: patrz docstring tamtego
#  skryptu (load_definitions wyłącza tworzenie RABBITMQ_DEFAULT_USER).
# ============================================================================
set -eu

DEFS=/etc/rabbitmq/definitions.json
TEMPLATE=/etc/rabbitmq/definitions.template.json

if [ -e "${DEFS}" ]; then
  # Lokalny dev: plik zamontowany z repo (read-only) — nie ruszamy.
  exec docker-entrypoint.sh "$@"
fi

: "${RABBITMQ_USER:?brak RABBITMQ_USER}"
: "${RABBITMQ_PASSWORD:?brak RABBITMQ_PASSWORD}"

TMP=$(mktemp -d)
head -c 4 /dev/urandom > "${TMP}/salt"
{ cat "${TMP}/salt"; printf '%s' "${RABBITMQ_PASSWORD}"; } | openssl dgst -sha256 -binary > "${TMP}/digest"
HASH=$(cat "${TMP}/salt" "${TMP}/digest" | base64 | tr -d '\n')
rm -rf "${TMP}"

jq --arg user "${RABBITMQ_USER}" --arg hash "${HASH}" '
  .users = [{
    name: $user, password_hash: $hash,
    hashing_algorithm: "rabbit_password_hashing_sha256", tags: ["administrator"]
  }]
  | .permissions = [{ user: $user, vhost: "/", configure: ".*", write: ".*", read: ".*" }]
' "${TEMPLATE}" > "${DEFS}"
chown rabbitmq:rabbitmq "${DEFS}"
chmod 600 "${DEFS}"
echo "[marketplace] definitions.json wygenerowany (użytkownik: ${RABBITMQ_USER})"

exec docker-entrypoint.sh "$@"
