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
    [[ -z "${FUSE_TTL+x}" ]] || echo "FUSE_TTL nie istnieje od G4-poprawek (ttl liczy się od wejścia awarii) — usuń"
    [[ "${BREAK_BUDGET_S:-600}" =~ ^[1-9][0-9]*$ ]] || echo "BREAK_BUDGET_S nie jest liczbą ≥ 1: '${BREAK_BUDGET_S:-}'"
    [[ "${FUSE_LIMIT_S:-180}" =~ ^[1-9][0-9]*$ ]] || echo "FUSE_LIMIT_S nie jest liczbą ≥ 1: '${FUSE_LIMIT_S:-}'"
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
# Adres węzła nie może być wpisany na sztywno: NetworkPolicy składa lib.sh z EndpointSlice.
if grep -qE '([0-9]{1,3}\.){3}[0-9]{1,3}' "${rbac}"; then bad "${rbac}: adres IP na sztywno (NetworkPolicy składa undo_netpol_apply)"; else ok "${rbac}: bez adresów IP na sztywno"; fi
# Obraz Joba (token z PUT na Deploymenty) przypięty po digeście, nie po samym tagu.
img="$(set +u; source tools/chaos/lib.sh; echo "${CHAOS_IMAGE}")"
[[ "${img}" =~ @sha256:[0-9a-f]{64}$ ]] && ok "obraz Joba przypięty po digeście" || bad "CHAOS_IMAGE bez @sha256: (${img})"

grep -q 'part-of: chaos-rbac' "${rbac}" && ! grep -qE 'part-of: chaos *[},]?$|part-of: chaos }' "${rbac}" \
  && ok "${rbac}: etykiety part-of=chaos-rbac" || bad "${rbac}: obiekty statyczne mają mieć part-of=chaos-rbac"

# --- Role chaos-undo = dokładnie to, czego używa undo ------------------------
# W obie strony: każdy potrzebny (zasób, nazwa, czasownik) jest w Role (inaczej
# auto-wycofanie w Jobie pada na Forbidden), i nic ponad to (nadmiarowe prawa).
#  - targets(): snapshot_restore robi GET po nazwie i PUT (update);
#  - rmq() (exec rabbitmq-0): pods get + pods/exec create;
#  - es-node-down: annotate ES (get, patch), scale STS (get sts, get+patch /scale — get celowo, Ruling 17);
#  - bad-secret: delete Secretu; Job: get i delete blokady.
if command -v ruby >/dev/null 2>&1; then
  needs="$(
    for f in tools/chaos/scenarios/*.sh; do
      ( set +u
        # shellcheck source=/dev/null
        source tools/chaos/lib.sh
        # shellcheck source=/dev/null
        source "${f}"
        n="$(basename "${f}" .sh)"
        for t in $(targets); do echo "${n} ${t} get"; echo "${n} ${t} update"; done
        if [[ -n "${ES_CR:-}" ]]; then
          echo "${n} elasticsearch/${ES_CR} get"; echo "${n} elasticsearch/${ES_CR} patch"
        fi
        if [[ -n "${ES_STS:-}" ]]; then
          echo "${n} statefulset/${ES_STS} get"; echo "${n} statefulsets/scale/${ES_STS} get"; echo "${n} statefulsets/scale/${ES_STS} patch"
        fi
        [[ -n "${BS_SECRET:-}" ]] && echo "${n} secret/${BS_SECRET} delete"
        : )
    done
    grep -q 'exec rabbitmq-0' tools/chaos/lib.sh && { echo "rmq pod/rabbitmq-0 get"; echo "rmq pods/exec/rabbitmq-0 create"; }
    ( set +u
      # shellcheck source=/dev/null
      source tools/chaos/lib.sh
      echo "job configmap/${LOCK_CM} get"; echo "job configmap/${LOCK_CM} delete" )
  )"
  missing="$(NEEDS="${needs}" ruby -ryaml -e '
    short = { "deploy" => "deployments", "sts" => "statefulsets", "netpol" => "networkpolicies",
              "es" => "elasticsearches", "cm" => "configmaps", "po" => "pods", "pod" => "pods" }
    plural = lambda { |k|
      k = k.downcase.split(".").first
      next short[k] if short[k]
      k =~ /(s|sh|ch|x)$/ ? k + "es" : (k.end_with?("y") ? k[0..-2] + "ies" : k + "s")
    }
    role = YAML.load_stream(File.read(ARGV[0])).find { |d| d && d["kind"] == "Role" }
    granted = {}
    role["rules"].each { |r| r["resources"].each { |res| (r["resourceNames"] || ["*"]).each { |nm|
      r["verbs"].each { |v| granted["#{res}/#{nm} #{v}"] = true } } } }
    need = {}
    ENV["NEEDS"].split("\n").reject(&:empty?).each do |line|
      s, ref, verb = line.split(" ")
      parts = ref.split("/")
      # pods/exec/<nazwa>, statefulsets/scale/<nazwa> = podzasób
      res, name = parts.size == 3 ? [parts[0] + "/" + parts[1], parts[2]] : [plural.call(parts[0]), parts[1]]
      key = "#{res}/#{name} #{verb}"
      need[key] = true
      puts "brak: #{s}: #{key}" unless granted[key]
    end
    (granted.keys - need.keys).each { |k| puts "nadmiar: #{k}" }' "${rbac}")"
  if [[ -z "${missing}" ]]; then ok "Role chaos-undo = dokładnie czasowniki undo (targets, exec rabbitmq-0, ES, Secret, blokada)"
  else bad "Role chaos-undo niezgodna z undo: $(echo "${missing}" | tr '\n' ';')"; fi
else
  echo "  - pomijam zgodność Role (brak ruby)"
fi

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
