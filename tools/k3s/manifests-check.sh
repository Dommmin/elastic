#!/usr/bin/env bash
# ============================================================================
#  manifests-check.sh — kontrola manifestów k8s BEZ klastra (ETAP 11b)
#
#  Renderuje `kubectl kustomize deploy/k8s` (po stronie klienta) i sprawdza, że
#  każdy Deployment aplikacji ma tag `process` dla śledzenia błędów
#  (ERROR_TRACKING_PROCESS). Bez niego, po wpięciu DSN-ów, worker kolejki, publisher
#  outboxu i konsument byliby w GlitchTipie tagowani domyślnie (`http` / `cli`),
#  więc filtr po procesie przestałby cokolwiek mówić.
# ============================================================================
set -uo pipefail
cd "$(dirname "$0")/../.."
FAIL=0

rendered=$(kubectl kustomize deploy/k8s 2>&1) || { echo "kubectl kustomize nie przeszedł:"; echo "$rendered" | head -5; exit 1; }

# Wartość ERROR_TRACKING_PROCESS w Deploymencie o danej nazwie (pusta, gdy brak).
process_of() {
  echo "$rendered" | awk -v want="$1" '
    /^---$/ { name=""; kind="" }
    /^kind: / { kind=$2 }
    /^metadata:/ { inmeta=1; next }
    inmeta && /^  name: / { if (name=="") name=$2; inmeta=0 }
    kind=="Deployment" && name==want && /- name: ERROR_TRACKING_PROCESS/ { getline; sub(/^ *value: */,""); gsub(/"/,""); print; exit }'
}

check() {  # $1 = Deployment, $2 = oczekiwany proces
  local got
  got=$(process_of "$1")
  if [ "$got" = "$2" ]; then echo "  ✓ $1: ERROR_TRACKING_PROCESS=$got"; else echo "  ✗ $1: ERROR_TRACKING_PROCESS='$got', oczekiwano '$2'"; FAIL=1; fi
}

echo "Manifesty k8s (kubectl kustomize deploy/k8s)"
check catalog-app http
check outbox-publisher outbox-publisher
check catalog-worker queue
check search-consumer consumer

[ "$FAIL" -eq 0 ] && echo "OK: manifesty k8s" || { echo "BŁĄD: manifesty k8s"; exit 1; }
