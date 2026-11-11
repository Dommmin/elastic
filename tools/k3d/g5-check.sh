#!/usr/bin/env bash
#  tools/k3d/g5-check.sh — kontrola overlayów G5 BEZ klastra (ETAP G5).
#  Renderuje overlaye (render z lib.sh) i woła g5-check.rb. Dodatkowo pilnuje,
#  że skrypty tools/k3d/ nie wołają gołego klienta k8s (lekcja G4: goły klient
#  na Macu celuje w obcy klaster) — wszystko przez kk/kc/render z lib.sh.
set -uo pipefail
# shellcheck source=tools/k3d/lib.sh
. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
cd "$REPO_ROOT" || die "brak katalogu repo"
FAIL=0
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT

# Overlaye: oba obowiązkowe. g5-local (stan wyjściowy migracji) idzie pierwszy, bo
# g5-local-ha (stan końcowy) porównujemy z jego renderem.
for ov in g5-local g5-local-ha; do
  dir="deploy/overlays/$ov"
  if [ ! -d "$dir" ]; then echo "✗ brak overlayu $dir"; FAIL=1; continue; fi
  if ! render "$dir" > "$TMP/$ov.yaml" 2> "$TMP/$ov.err"; then
    echo "✗ render $ov nie przeszedł:"; head -5 "$TMP/$ov.err"; FAIL=1; continue
  fi
  if [ "$ov" = "g5-local-ha" ]; then
    if [ -s "$TMP/g5-local.yaml" ]; then
      ruby tools/k3d/g5-check.rb "$TMP/$ov.yaml" "$ov" "$TMP/g5-local.yaml" || FAIL=1
    else
      echo "✗ brak renderu g5-local do porównania ze stanem końcowym"; FAIL=1
    fi
  else
    ruby tools/k3d/g5-check.rb "$TMP/$ov.yaml" "$ov" || FAIL=1
  fi
  # Zgodność topologii CR-ów z definitions.template.json (Task 4) — tylko overlaye z klastrem operatora.
  if grep -q 'name: rabbitmq-ha$' "$TMP/$ov.yaml"; then
    echo "Topologia RabbitMQ ($ov): zgodność z infra/rabbitmq/definitions.template.json"
    ruby tools/k3d/topology-parity.rb "$TMP/$ov.yaml" infra/rabbitmq/definitions.template.json || FAIL=1
  fi
done

# Przegląd: goły klient k8s tylko w lib.sh (wzorzec z nawiasem, żeby nie łapał samego siebie).
echo "Skrypty tools/k3d/ bez gołego klienta k8s"
hits="$(grep -n 'kubect[l] ' tools/k3d/*.sh | grep -v '^tools/k3d/lib.sh:' || true)"
if [ -n "$hits" ]; then echo "✗ goły klient w:"; echo "$hits"; FAIL=1; else echo "  ✓ brak"; fi

[ "$FAIL" -eq 0 ] || exit 1
echo "g5-check.sh: OK"
