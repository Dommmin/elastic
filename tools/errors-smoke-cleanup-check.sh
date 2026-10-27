#!/usr/bin/env bash
# ============================================================================
#  errors-smoke-cleanup-check — czy przerwany errors-smoke zostawia GlitchTip w
#  porządku (RUNBOOK #076). Atrapa `docker` w PATH, bez żywego stosu.
#
#    bash tools/errors-smoke-cleanup-check.sh
# ============================================================================
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
PASS=0; FAIL=0
ok()   { echo "  ✓ $1"; PASS=$((PASS+1)); }
fail() { echo "  ✗ $1"; FAIL=$((FAIL+1)); }

mkdir -p "$TMP/bin"
printf '#!/usr/bin/env bash\necho "docker $*" >> "$CALLS"\n' > "$TMP/bin/docker"
chmod +x "$TMP/bin/docker"

# scenario <nazwa> <ciało skryptu po załadowaniu biblioteki> -> kod wyjścia; CALLS w $TMP/calls
scenario() {
  : > "$TMP/calls"
  PATH="$TMP/bin:$PATH" CALLS="$TMP/calls" GT_CONTAINER=gt-1 ROOT="$ROOT" bash -c '
    . "$ROOT/tools/lib/glitchtip-restore.sh"
    gt_install_exit_trap
    '"$2"'
  ' >/dev/null 2>&1
}

echo "Sprzątanie GlitchTipa po errors-smoke"
scenario normal 'true'; rc=$?
[ ! -s "$TMP/calls" ] && ok "normalne wyjście bez dotykania GlitchTipa: zero wywołań docker" || fail "dotknięto dockera bez potrzeby: $(cat "$TMP/calls")"

scenario touched 'gt_mark_touched; docker pause gt-1'
grep -q 'docker unpause gt-1' "$TMP/calls" && ok "po pauzie wyjście robi unpause" || fail "brak unpause po wyjściu"
grep -q 'docker start gt-1' "$TMP/calls" && ok "po pauzie wyjście robi start (kontener mógł być zatrzymany)" || fail "brak start po wyjściu"

: > "$TMP/calls"
PATH="$TMP/bin:$PATH" CALLS="$TMP/calls" GT_CONTAINER=gt-1 ROOT="$ROOT" bash -c '
  . "$ROOT/tools/lib/glitchtip-restore.sh"; gt_install_exit_trap
  gt_mark_touched; docker pause gt-1; sleep 30' >/dev/null 2>&1 &
PID=$!
sleep 1; kill -TERM "$PID"; wait "$PID" 2>/dev/null
grep -q 'docker unpause gt-1' "$TMP/calls" && grep -q 'docker start gt-1' "$TMP/calls" \
  && ok "SIGTERM w środku pauzy: GlitchTip wznowiony" || fail "po SIGTERM GlitchTip został zawieszony: $(cat "$TMP/calls")"

echo; echo "  PASS=${PASS} FAIL=${FAIL}"
[ "$FAIL" -eq 0 ]
