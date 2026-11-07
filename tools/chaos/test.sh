#!/usr/bin/env bash
#  tools/chaos/test.sh — testy chaosu BEZ klastra (CI): składnia, kontrakt scenariuszy,
#  karty, render RBAC, aktualny spis w README.
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "${ROOT}" || exit 1
FAIL=0
ok()  { printf '  ✓ %s\n' "$1"; }
bad() { printf '  ✗ %s\n' "$1"; FAIL=1; }

echo "chaos: test offline"

# --- składnia ---------------------------------------------------------------
files="$(find tools/chaos -name '*.sh' | sort)"
[[ -n "${files}" ]] || bad "brak plików tools/chaos/**/*.sh"
for f in ${files}; do
  if bash -n "${f}" 2>/tmp/chaos-test-syntax.$$; then :; else bad "bash -n ${f}: $(head -1 /tmp/chaos-test-syntax.$$)"; fi
done
rm -f /tmp/chaos-test-syntax.$$
[[ ${FAIL} -eq 0 && -n "${files}" ]] && ok "bash -n: $(echo "${files}" | wc -l | tr -d ' ') plików"

if command -v shellcheck >/dev/null 2>&1; then
  # shellcheck disable=SC2086
  if shellcheck -S warning -x ${files}; then ok "shellcheck -S warning"; else bad "shellcheck -S warning"; fi
else
  echo "  - pomijam shellcheck (brak binarki; CI go ma)"
fi

# --- `break` jako słowo kluczowe ---------------------------------------------
# Scenariusz definiuje funkcję `break`, która przesłania słowo kluczowe: każda pętla
# z `break` po sourcowaniu scenariusza wywołałaby awarię. Dozwolone: definicja
# w scenariuszu i jedno wywołanie w chaos.sh.
misuse="$(perl -ne '
  next if /^\s*#/;
  next if $ARGV =~ m{/scenarios/} && /^break\(\)\s*\{/;
  next if $ARGV =~ m{/chaos\.sh$} && /^\s*break \|\| \{/;
  print "$ARGV:$.: $_" if /(^\s*|;\s*|&&\s*|\|\|\s*|\bthen\s+|\bdo\s+|\belse\s+|\{\s+)break(\s+[0-9]+)?\s*(;|\}|$)/;
  close ARGV if eof;' ${files})"
if [[ -z "${misuse}" ]]; then ok "brak słowa kluczowego break w skryptach"; else bad "break poza dozwolonymi miejscami:"; echo "${misuse}" | sed 's/^/      /'; fi

# --- kontrakt scenariuszy ---------------------------------------------------
scen=0
for f in tools/chaos/scenarios/*.sh; do
  [[ -e "${f}" ]] || { bad "brak scenariuszy w tools/chaos/scenarios/"; continue; }
  s="$(basename "${f}" .sh)"; scen=$((scen + 1))
  out="$(
    set +u
    # shellcheck source=/dev/null
    source tools/chaos/lib.sh
    # shellcheck source=/dev/null
    source "${f}"
    [[ -n "${SUMMARY:-}" ]] || echo "puste SUMMARY"
    [[ "${LIMIT_MIN:-}" =~ ^[0-9]+$ ]] || echo "LIMIT_MIN nie jest liczbą: '${LIMIT_MIN:-}'"
    declare -p ALERT >/dev/null 2>&1 || echo "brak zmiennej ALERT"
    for fn in targets break check undo; do declare -F "${fn}" >/dev/null || echo "brak funkcji ${fn}"; done
  )"
  if [[ -z "${out}" ]]; then ok "scenariusz ${s}: kontrakt"; else bad "scenariusz ${s}: $(echo "${out}" | tr '\n' ';')"; fi

  card="docs/chaos/${s}.md"
  if [[ ! -f "${card}" ]]; then bad "brak karty ${card}"; continue; fi
  order="$(grep -E '^## ' "${card}" | tr '\n' '|')"
  want='## Objaw|## Zacznij od|## Pytania prowadzące|## Po co to w życiu|## Rozwiązanie|'
  [[ "${order}" == "${want}" ]] && ok "karta ${s}: sekcje w kolejności" || bad "karta ${s}: sekcje '${order}', oczekiwano '${want}'"
  det="$(awk '
    /^## Rozwiązanie/ { sol = 1 }
    /<details>/ { if (!sol) early = 1; else after = 1 }
    END { print (early ? "przed" : "") (after ? "po" : "") }' "${card}")"
  [[ "${det}" == "po" ]] && ok "karta ${s}: <details> tylko po ## Rozwiązanie" || bad "karta ${s}: <details> źle (${det:-brak})"
  head -1 "${card}" | grep -q '^# ' || bad "karta ${s}: pierwsza linia nie jest tytułem '# ...'"
  grep -q '\*\*Limit:\*\*' "${card}" || bad "karta ${s}: brak **Limit:**"
done
[[ ${scen} -gt 0 ]] || bad "zero scenariuszy"

# --- RBAC Joba --------------------------------------------------------------
rbac="deploy/chaos/undo-rbac.yaml"
kubeconfig="${KUBECONFIG_VPS:-${HOME}/.kube/elastic-vps.yaml}"
if [[ -f "${kubeconfig}" ]] && nc -z 127.0.0.1 26443 2>/dev/null; then
  if out="$(kubectl --kubeconfig "${kubeconfig}" apply --dry-run=client -f "${rbac}" 2>&1)"; then
    ok "kubectl apply --dry-run=client ${rbac} ($(echo "${out}" | wc -l | tr -d ' ') obiekty)"
  else bad "kubectl apply --dry-run=client ${rbac}: ${out}"; fi
else
  # Bez API (CI) client dry-run nie ma discovery; render przez kustomize jest czysto lokalny.
  tmpk="$(mktemp -d)"; cp "${rbac}" "${tmpk}/r.yaml"; printf 'resources: [r.yaml]\n' > "${tmpk}/kustomization.yaml"
  if out="$(kubectl kustomize "${tmpk}" 2>&1)"; then
    ok "${rbac}: render (kubectl kustomize; bez API pomijam dry-run=client), $(echo "${out}" | grep -c '^kind:') obiekty"
  else bad "${rbac}: render: ${out}"; fi
  rm -rf "${tmpk}"
fi
grep -q 'part-of: chaos-rbac' "${rbac}" && ! grep -qE 'part-of: chaos *[},]?$|part-of: chaos }' "${rbac}" \
  && ok "${rbac}: etykiety part-of=chaos-rbac" || bad "${rbac}: obiekty statyczne mają mieć part-of=chaos-rbac"

# --- spis w README ----------------------------------------------------------
readme="docs/chaos/README.md"
marker='<!-- spis generowany: tools/chaos/index.sh -->'
if [[ -f "${readme}" ]] && grep -qF "${marker}" "${readme}"; then
  have="$(awk -v m="${marker}" 'f { print } $0 == m { f = 1 }' "${readme}" | sed '/^$/d')"
  want_t="$(bash tools/chaos/index.sh --print | sed '/^$/d')"
  [[ "${have}" == "${want_t}" ]] && ok "spis w README aktualny" || bad "spis w README nieaktualny: bash tools/chaos/index.sh"
else
  bad "brak ${readme} albo markera spisu"
fi

echo
[[ ${FAIL} -eq 0 ]] && echo "chaos test: PASS" || echo "chaos test: FAIL"
exit "${FAIL}"
