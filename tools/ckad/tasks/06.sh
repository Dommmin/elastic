# shellcheck shell=bash
# 06. Budowanie obrazu — sprawdzarka LOKALNA, bez klastra (docs/ckad/06-budowanie-obrazu.md)

IMG="ckad/hello:1.0"
TAR="/tmp/ckad-hello-1.0.tar"

setup() {
  docker rmi -f "${IMG}" >/dev/null 2>&1 || true
  rm -f "${TAR}"
}

check() {
  expect "obraz ${IMG} istnieje lokalnie" "yes" \
    "$(docker image inspect "${IMG}" >/dev/null 2>&1 && echo yes || echo no)"
  expect_match "obraz działa jako użytkownik 1000 (config User)" '^1000(:.*)?$' \
    "$(docker image inspect "${IMG}" --format '{{.Config.User}}' 2>/dev/null)"
  expect "etykieta org.opencontainers.image.source ustawiona" "yes" \
    "$(docker image inspect "${IMG}" --format '{{index .Config.Labels "org.opencontainers.image.source"}}' 2>/dev/null | grep -q . && echo yes || echo no)"
  expect "proces w kontenerze ma UID 1000" "1000" \
    "$(docker run --rm "${IMG}" id -u 2>/dev/null)"
  expect "plik ${TAR} istnieje" "yes" "$([[ -s "${TAR}" ]] && echo yes || echo no)"
  expect "archiwum zawiera tag ${IMG}" "yes" \
    "$(tar -xOf "${TAR}" manifest.json 2>/dev/null | grep -q "${IMG}" && echo yes || echo no)"
}
