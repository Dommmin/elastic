# shellcheck shell=bash
#  tools/ckad/lib.sh — wspólne funkcje laboratorium CKAD (ETAP G3).
#
#  Dwa kubeconfigi, celowo rozdzielone:
#   - UCZEŃ (~/.kube/<lab>.yaml): ServiceAccount z rolą `admin` tylko w dwóch
#     namespace'ach labu. Tym ćwiczysz i tym wykonują się rozwiązania w selfteście.
#     Pomyłka w zadaniu fizycznie nie dotknie sklepu, monitoringu ani certyfikatów.
#   - OPERATOR (~/.kube/elastic-vps.yaml): tylko do zakładania labu i do sprawdzeń,
#     które wymagają więcej niż admin w namespace (np. `auth can-i --as=...`).
#
#  Zmienne:
#   LAB          prefiks labu, domyślnie `ckad-lab` (namespace'y: $LAB i $LAB-b);
#                subagenci/równoległe testy dostają własne prefiksy (np. ckad-d1).

set -uo pipefail

CKAD_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
LAB="${LAB:-ckad-lab}"
export LAB
NS="${LAB}"
NS_B="${LAB}-b"
OPERATOR_KUBECONFIG="${OPERATOR_KUBECONFIG:-${HOME}/.kube/elastic-vps.yaml}"
STUDENT_KUBECONFIG="${STUDENT_KUBECONFIG:-${HOME}/.kube/${LAB}.yaml}"
SCORES_FILE="${CKAD_SCORES_FILE:-${HOME}/.ckad-scores.tsv}"

# Etykieta infrastruktury labu: reset kasuje wszystko, co jej NIE ma.
INFRA_LABEL="ckad.lab/infra"

ko() { kubectl --kubeconfig "${OPERATOR_KUBECONFIG}" "$@"; }   # operator
kc() { kubectl --kubeconfig "${STUDENT_KUBECONFIG}" "$@"; }    # uczeń (domyślny ns = $NS)

# jp <zasób> <jsonpath> [-n ns] — pole zasobu albo pusty napis, gdy zasobu nie ma.
jp() { local res="$1" path="$2"; shift 2; ko -n "${NS}" get ${res} "$@" -o "jsonpath=${path}" 2>/dev/null; }

# Pierwszy kontener o nazwie $2 w spec poda/szablonu zasobu $1 (jsonpath filtr).
ctr() { printf '{.spec.template.spec.containers[?(@.name=="%s")]%s}' "$1" "$2"; }

PASS=0
FAIL=0
GREEN=$'\033[0;32m'; RED=$'\033[0;31m'; DIM=$'\033[2m'; RESET=$'\033[0m'

# expect <opis> <oczekiwane> <otrzymane>
expect() {
  if [[ "$2" == "$3" ]]; then
    PASS=$((PASS + 1)); printf '  %s✓%s %s\n' "${GREEN}" "${RESET}" "$1"
  else
    FAIL=$((FAIL + 1)); printf '  %s✗%s %s  %s(oczekiwano: %s, otrzymano: %s)%s\n' "${RED}" "${RESET}" "$1" "${DIM}" "$2" "${3:-<puste>}" "${RESET}"
  fi
}

# expect_match <opis> <regex> <otrzymane>
expect_match() {
  if [[ "$3" =~ $2 ]]; then
    PASS=$((PASS + 1)); printf '  %s✓%s %s\n' "${GREEN}" "${RESET}" "$1"
  else
    FAIL=$((FAIL + 1)); printf '  %s✗%s %s  %s(oczekiwano dopasowania: %s, otrzymano: %s)%s\n' "${RED}" "${RESET}" "$1" "${DIM}" "$2" "${3:-<puste>}" "${RESET}"
  fi
}

# wait_for <sekundy> <polecenie...> — ponawia polecenie co 2 s, aż zwróci 0.
# Sprawdzarki czekają krótko na rzeczy asynchroniczne (rollout, Job, endpointy),
# żeby wynik nie zależał od tego, czy uczeń kliknął check sekundę za wcześnie.
wait_for() {
  local deadline=$((SECONDS + $1)); shift
  until "$@" >/dev/null 2>&1; do
    (( SECONDS >= deadline )) && return 1
    sleep 2
  done
}

# Ścieżka pliku zadania (docs) i sprawdzarki (tasks) po numerze NN.
task_doc() { ls "${CKAD_ROOT}/docs/ckad/$1"-*.md 2>/dev/null | head -1; }
task_script() { echo "${CKAD_ROOT}/tools/ckad/tasks/$1.sh"; }

require_lab() {
  [[ -f "${STUDENT_KUBECONFIG}" ]] || { echo "Brak ${STUDENT_KUBECONFIG}. Najpierw: make ckad-lab-up${LAB:+ LAB=${LAB}}" >&2; exit 1; }
}

# Kasuje wszystko, co zadania mogły stworzyć w obu namespace'ach labu, oprócz
# infrastruktury (etykieta ckad.lab/infra). Czeka na zniknięcie podów, żeby
# następne zadanie nie trafiło na konflikt nazw.
lab_reset() {
  local kinds="deploy,rs,sts,ds,job,cronjob,pod,svc,ing,netpol,cm,secret,pvc,sa,role,rolebinding,hpa,pdb,resourcequota,limitrange,certificate"
  local ns
  for ns in "${NS}" "${NS_B}"; do
    # `default` (SA) i `kube-root-ca.crt` odtwarza kontroler — kasowanie ich tylko
    # na chwilę psuje tworzenie podów, więc zostają.
    ko -n "${ns}" delete "${kinds}" -l "!${INFRA_LABEL}" \
      --field-selector 'metadata.name!=default,metadata.name!=kube-root-ca.crt' \
      --ignore-not-found --wait=true --timeout=90s >/dev/null 2>&1 || true
    # Helm trzyma stan wydań w sekretach (już skasowanych wyżej) — nic więcej.
  done
  wait_for 60 bash -c "! kubectl --kubeconfig '${OPERATOR_KUBECONFIG}' -n '${NS}' get pods -l '!${INFRA_LABEL}' -o name 2>/dev/null | grep -q . && ! kubectl --kubeconfig '${OPERATOR_KUBECONFIG}' -n '${NS_B}' get pods -l '!${INFRA_LABEL}' -o name 2>/dev/null | grep -q ." || true
}

# Wypisuje polecenia z bloków ```bash wewnątrz <details> dokumentu zadania
# (sekcja „Rozwiązanie”). Selftest wykonuje je jako uczeń.
task_solution() {
  awk '
    /<details>/ { inside = 1; next }
    /<\/details>/ { inside = 0; next }
    inside && /^```bash/ { code = 1; next }
    inside && code && /^```/ { code = 0; next }
    inside && code { print }
  ' "$1"
}

# Limit minut z nagłówka zadania: „**Limit:** 6 min”.
task_minutes() { sed -n 's/.*\*\*Limit:\*\* *\([0-9][0-9]*\) *min.*/\1/p' "$1" | head -1; }
