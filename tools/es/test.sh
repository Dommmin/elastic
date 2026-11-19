#!/usr/bin/env bash
#  tools/es/test.sh — testy skryptów reindeksu BEZ klastra (CI): składnia, shellcheck,
#  parsowanie wyjść komend (fixtury), preflight na stubie aliasów, cele Make.
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "${ROOT}" || exit 1
FAIL=0
ok()  { printf '  ✓ %s\n' "$1"; }
bad() { printf '  ✗ %s\n' "$1"; FAIL=1; }
eq()  { [[ "$2" == "$3" ]] && ok "$1" || bad "$1: oczekiwano '$3', jest '$2'"; }

echo "es: test offline"
# shellcheck source=tools/es/lib.sh
. tools/es/lib.sh

# --- składnia ---------------------------------------------------------------
files="$(find tools/es -name '*.sh' | sort)"
for f in ${files}; do bash -n "${f}" 2>/dev/null && ok "bash -n ${f}" || bad "bash -n ${f}"; done
if command -v shellcheck >/dev/null 2>&1; then
  # shellcheck disable=SC2086
  shellcheck -S warning -x ${files} && ok "shellcheck -S warning" || bad "shellcheck -S warning"
else
  echo "  - pomijam shellcheck (brak binarki; CI go ma)"
fi

# --- kv_get: TARGET= / TASK= / GATE= / REPROJECT_NEEDED_SINCE= -------------
prep=$'[OK] products-v2 gotowy: 0 replik.\nTARGET=products-v2\n'
eq "TARGET=" "$(kv_get TARGET <<<"${prep}")" "products-v2"
eq "TASK=" "$(kv_get TASK <<<$'TASK=abc123:4567\npostęp: created=1')" "abc123:4567"
eq "GATE=PASS" "$(kv_get GATE <<<$'Bramka products-v2: zdrowie=green\nGATE=PASS')" "PASS"
eq "GATE=FAIL" "$(kv_get GATE <<<$'NIESPEŁNIONE: liczba\nGATE=FAIL')" "FAIL"
eq "REPROJECT_NEEDED_SINCE=" "$(kv_get REPROJECT_NEEDED_SINCE <<<$'x\nREPROJECT_NEEDED_SINCE=2026-11-19T10:00:00+00:00')" "2026-11-19T10:00:00+00:00"
eq "brak klucza = pusto" "$(kv_get TARGET <<<'nic')" ""
eq "klucz musi być na początku linii" "$(kv_get GATE <<<'xGATE=PASS')" ""

# --- NDCG: 1, 0.973, 1.0E-5 --------------------------------------------------
eq "NDCG=1" "$(ndcg_value <<<'NDCG=1')" "1.000000"
eq "NDCG=0.973" "$(ndcg_value <<<$'szum\nNDCG=0.973')" "0.973000"
eq "NDCG=1.0E-5" "$(ndcg_value <<<'NDCG=1.0E-5')" "0.000010"
ndcg_value <<<'NDCG=abc' >/dev/null 2>&1 && bad "NDCG=abc powinno odmówić" || ok "NDCG=abc odrzucone"
ndcg_value <<<'brak' >/dev/null 2>&1 && bad "brak NDCG powinien odmówić" || ok "brak NDCG odrzucony"
ndcg_regressed 0.973 0.95 && ok "0.973 → 0.95 = regresja" || bad "0.973 → 0.95 powinno być regresją"
ndcg_regressed 0.973 0.965 && bad "0.973 → 0.965 w tolerancji" || ok "0.973 → 0.965 w tolerancji"
ndcg_regressed 0.973 0.973 && bad "równe nie jest regresją" || ok "równe nie jest regresją"
ndcg_regressed 1.000000 0.000010 && ok "1 → 1.0E-5 = regresja" || bad "1 → 1.0E-5 powinno być regresją"
ndcg_regressed 0.5 0.99 && bad "poprawa nie jest regresją" || ok "poprawa nie jest regresją"

# --- zadania _reindex: rodzic tak, dziecko slice'a nie, cudzy cel nie --------
tasks='{"nodes":{"n1":{"tasks":{"n1:10":{"description":"reindex from [products-v1] to [products-v2][_doc]"},
 "n1:11":{"parent_task_id":"n1:10","description":"reindex from [products-v1] to [products-v2][_doc]"},
 "n1:12":{"description":"reindex from [products-v1] to [products-v3][_doc]"}}}}}'
eq "zadanie rodzic do celu" "$(running_reindex_ids products-v2 <<<"${tasks}")" "n1:10"
eq "brak zadań do innego celu" "$(running_reindex_ids products-v9 <<<"${tasks}")" ""
eq "puste nodes" "$(running_reindex_ids products-v2 <<<'{"nodes":{}}')" ""

# --- listing cleanup ---------------------------------------------------------
listing=$' * products-v1\n * products-v2\n\n [NOTE] Podgląd — nic nie usunięto.'
eq "kandydaci cleanup" "$(cleanup_candidates <<<"${listing}" | tr '\n' ' ')" "products-v1 products-v2 "

# --- preflight na stubie aliasów -------------------------------------------
preflight_index_free products-v1 products-v2 products-v2 2>/dev/null && ok "preflight: wolny indeks przechodzi" || bad "preflight: wolny indeks odrzucony"
preflight_index_free products-v2 products-v1 products-v2 2>/dev/null && bad "preflight: cel products-write przeszedł" || ok "preflight odrzuca cel products-write"
preflight_index_free products-v1 products-v1 products-v1 2>/dev/null && bad "preflight: cel products-search przeszedł" || ok "preflight odrzuca cel products-search"
preflight_index_free "" products-v1 2>/dev/null && bad "preflight: pusta nazwa przeszła" || ok "preflight odrzuca pustą nazwę"
# preflight_live na stubie alias_targets (bez ES)
alias_targets() { printf 'products-v1\nproducts-v2\n'; }
preflight_live products-v2 2>/dev/null && bad "preflight_live: aliasowany przeszedł" || ok "preflight_live odrzuca aliasowany"
preflight_live products-break-1 2>/dev/null && ok "preflight_live: products-break-1 wolny" || bad "preflight_live odrzucił wolny"

# --- cele Make ---------------------------------------------------------------
for t in reindex reindex-abort reindex-cleanup reindex-break; do
  grep -qE "^${t}:" Makefile && ok "Makefile: cel ${t}" || bad "Makefile: brak celu ${t}"
done
# Skrypt nie może wypisywać danych dostępowych.
grep -nE 'echo[^|]*(PASSWORD|ELASTICSEARCH_USER)|printf[^|]*(PASSWORD|ELASTICSEARCH_USER)' tools/es/*.sh | grep -v '^tools/es/test.sh' \
  && bad "skrypt wypisuje dane dostępowe" || ok "brak wypisywania danych dostępowych"

echo
[[ ${FAIL} -eq 0 ]] && echo "es test: PASS" || echo "es test: FAIL"
exit "${FAIL}"
