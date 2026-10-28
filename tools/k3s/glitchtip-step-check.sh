#!/usr/bin/env bash
# ============================================================================
#  glitchtip-step-check — krok GlitchTipa z tools/k3s/deploy.sh bez klastra (ETAP 11b)
#
#  Wycina funkcję glitchtip_step z deploy.sh i uruchamia z atrapami kk/apply_sel:
#  sprawdza kolejność (db-setup -> glitchtip -> setup) i że porażka przerywa krok
#  (nie nakłada kolejnych zasobów na zepsutej bazie).
# ============================================================================
set -uo pipefail
cd "$(dirname "$0")/../.."
PASS=0; FAIL=0
ok()   { echo "  ✓ $1"; PASS=$((PASS+1)); }
fail() { echo "  ✗ $1"; FAIL=$((FAIL+1)); }

FUNC=$(awk '/^gt_fail\(\)/{print; next} /^glitchtip_step\(\) \{/{f=1} f{print} f&&/^\}/{exit}' tools/k3s/deploy.sh)
[ -n "$FUNC" ] || { echo "  ✗ nie znaleziono glitchtip_step w deploy.sh"; exit 1; }

run() {  # run <polecenie kk, przy którym kończymy błędem | ""> -> kod wyjścia; log w $LOG
  LOG=$(mktemp)
  FAIL_ON="$1" LOG="$LOG" bash -c '
    NS=(-n marketplace)
    kk() { echo "kk $*" >> "$LOG"; [[ -n "$FAIL_ON" && "$*" == *"$FAIL_ON"* ]] && return 1; return 0; }
    apply_sel() { echo "apply $1" >> "$LOG"; [[ -n "$FAIL_ON" && "$1" == *"$FAIL_ON"* ]] && { "$2" "apply nie przeszedł"; return 1; }; return 0; }
    '"$FUNC"'
    glitchtip_step' >/dev/null 2>&1
}

echo "Krok GlitchTipa w deploy.sh"
run ""; rc=$?
[ "$rc" -eq 0 ] && ok "komplet kroków przechodzi" || fail "pełny przebieg zwrócił $rc"
ORDER=$(grep -o 'component=errors[a-z-]*\|job/glitchtip-[a-z-]*\|deploy/glitchtip\|delete job' "$LOG" | tr '\n' ' ')
EXPECT="delete job component=errors-init job/glitchtip-db-setup component=errors deploy/glitchtip component=errors-setup job/glitchtip-setup "
[ "$ORDER" = "$EXPECT" ] && ok "kolejność: usuń Joby -> db-setup -> glitchtip -> setup" || fail "kolejność: '$ORDER'"

run "job/glitchtip-db-setup"; rc=$?
[ "$rc" -ne 0 ] && ok "porażka db-setup przerywa krok" || fail "krok poszedł dalej mimo porażki db-setup"
grep -q 'component=errors$\|deploy/glitchtip' "$LOG" && fail "po porażce db-setup nałożono Deployment glitchtip" || ok "po porażce db-setup nie nakładamy GlitchTipa na zepsutą bazę"

run "rollout status"; rc=$?
[ "$rc" -ne 0 ] && ok "porażka rollout glitchtip przerywa krok" || fail "krok poszedł dalej mimo porażki rollout"
grep -q 'component=errors-setup' "$LOG" && fail "bootstrap uruchomiony bez działającego GlitchTipa" || ok "bootstrap nie rusza bez działającego GlitchTipa"

echo; echo "  PASS=${PASS} FAIL=${FAIL}"
[ "$FAIL" -eq 0 ]
