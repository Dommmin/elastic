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

# Overlaye: g5-local obowiązkowy; g5-local-ha dochodzi w Task 3 (wtedy też obowiązkowy).
for ov in g5-local g5-local-ha; do
  dir="deploy/overlays/$ov"
  if [ ! -d "$dir" ]; then
    if [ "$ov" = "g5-local" ]; then echo "✗ brak overlayu $dir"; FAIL=1; else echo "- $ov: jeszcze nie istnieje, pominięty"; fi
    continue
  fi
  if ! render "$dir" > "$TMP/$ov.yaml" 2> "$TMP/$ov.err"; then
    echo "✗ render $ov nie przeszedł:"; head -5 "$TMP/$ov.err"; FAIL=1; continue
  fi
  ruby tools/k3d/g5-check.rb "$TMP/$ov.yaml" "$ov" || FAIL=1
done

# Przegląd: goły klient k8s tylko w lib.sh (wzorzec z nawiasem, żeby nie łapał samego siebie).
echo "Skrypty tools/k3d/ bez gołego klienta k8s"
hits="$(grep -n 'kubect[l] ' tools/k3d/*.sh | grep -v '^tools/k3d/lib.sh:' || true)"
if [ -n "$hits" ]; then echo "✗ goły klient w:"; echo "$hits"; FAIL=1; else echo "  ✓ brak"; fi

[ "$FAIL" -eq 0 ] || exit 1
echo "g5-check.sh: OK"
