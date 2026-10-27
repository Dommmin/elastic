#!/usr/bin/env bash
# ============================================================================
#  deploy-check.sh — test deploy.sh bez serwera (atrapy ssh/scp w PATH)
#
#    bash tools/vps/deploy-check.sh
#
#  Sprawdza dwa zachowania, które kiedyś zawiodły (RUNBOOK #075):
#    1. gdy na serwerze działa k3s, deploy Compose odmawia ZANIM cokolwiek skopiuje;
#    2. pierwszy `up` obejmuje węzły klastra ES, inaczej es01 nie zdrowieje.
# ============================================================================
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
PASS=0; FAIL=0
ok()   { echo "  ✓ $1"; PASS=$((PASS+1)); }
fail() { echo "  ✗ $1"; FAIL=$((FAIL+1)); }

mkdir -p "$TMP/bin"
# ssh: pytanie o k3s zwraca K3S_RC; skrypt zdalny (stdin) ląduje w REMOTE_SCRIPT.
cat > "$TMP/bin/ssh" <<'STUB'
#!/usr/bin/env bash
echo "ssh $*" >> "$CALLS"
case "$*" in
  *"is-active"*) exit "${STUB_K3S_RC:-3}" ;;
  *"bash -s"*)   cat > "$REMOTE_SCRIPT"; exit 0 ;;
esac
exit 0
STUB
cat > "$TMP/bin/scp" <<'STUB'
#!/usr/bin/env bash
echo "scp $*" >> "$CALLS"
STUB
chmod +x "$TMP/bin/ssh" "$TMP/bin/scp"

TAG=0123456789abcdef0123456789abcdef01234567
run() {  # run <K3S_RC> [ENV=VAL...] -> kod wyjścia deploy.sh; CALLS/REMOTE_SCRIPT czyszczone
  local rc="$1"; shift
  : > "$TMP/calls"; rm -f "$TMP/remote.sh"
  env "$@" PATH="$TMP/bin:$PATH" CALLS="$TMP/calls" REMOTE_SCRIPT="$TMP/remote.sh" STUB_K3S_RC="$rc" \
    bash "$ROOT/tools/vps/deploy.sh" "$TAG" >"$TMP/out" 2>&1
}

echo "Strażnik k3s"
run 0; rc=$?
[ "$rc" -ne 0 ] && ok "k3s działa -> deploy odmawia (kod $rc)" || fail "k3s działa, a deploy poszedł dalej"
grep -q '^scp ' "$TMP/calls" && fail "przed odmową skopiowano pliki" || ok "przed odmową nic nie skopiowano"
grep -qi 'k3s' "$TMP/out" && ok "komunikat wspomina k3s" || fail "brak komunikatu o k3s"

run 3; rc=$?
[ "$rc" -eq 0 ] && ok "k3s nieaktywny -> deploy przechodzi" || fail "k3s nieaktywny, a deploy padł (kod $rc): $(tail -2 "$TMP/out")"

run 255; rc=$?
[ "$rc" -ne 0 ] && ok "nie da się ustalić stanu k3s (ssh 255) -> deploy odmawia" || fail "brak łączności, a deploy poszedł dalej"

run 0 ALLOW_COMPOSE_WITH_K3S=1; rc=$?
[ "$rc" -eq 0 ] && ok "ALLOW_COMPOSE_WITH_K3S=1 omija strażnika" || fail "obejście nie działa (kod $rc)"

echo "Węzły klastra w pierwszym up"
run 3
FIRST_UP=$(grep -n 'up -d --wait --remove-orphans catalog-app' "$TMP/remote.sh" | head -1)
[ -n "$FIRST_UP" ] || fail "nie znaleziono pierwszego up w skrypcie zdalnym"
echo "$FIRST_UP" | grep -q 'CLUSTER_NODES' && ok "pierwszy up dostaje \$CLUSTER_NODES" || fail "pierwszy up bez węzłów klastra: $FIRST_UP"
grep -q 'COMPOSE_PROFILES=.*cluster' "$TMP/remote.sh" && ok "węzły dodawane tylko przy profilu cluster w .env" || fail "brak warunku na profil cluster"

echo; echo "  PASS=${PASS} FAIL=${FAIL}"
[ "$FAIL" -eq 0 ]
