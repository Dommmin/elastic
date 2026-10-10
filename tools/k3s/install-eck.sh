#!/usr/bin/env bash
# ============================================================================
#  install-eck.sh — operator ECK 3.5.0 na klastrze VPS (ETAP D2, Task 2)
#  Uruchamiany z MACA (kubectl przez tunel). Manifesty z repo:
#  deploy/k8s/vendor/eck-3.5.0/ — wersja przypięta, repo jest źródłem prawdy.
#
#  Operator = program działający W klastrze, który pilnuje zasobów typu
#  `Elasticsearch` i `Kibana`: tworzy StatefulSety, Service'y, certyfikaty,
#  hasło użytkownika `elastic`, robi rolling restart przy zmianie spec.
#  W D1 to samo robiły ręcznie es-init + es-setup + healthchecki w compose.
#
#  CRD przez --server-side: definicje ECK są duże (~800 KB), a zwykłe
#  `kubectl apply` zapisuje całą treść w adnotacji last-applied-configuration,
#  która ma limit 256 KB — klasyczny błąd "metadata.annotations: Too long".
# ============================================================================
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
DIR="${ROOT}/deploy/k8s/vendor/eck-3.5.0"
K=(/opt/homebrew/bin/kubectl --kubeconfig "${HOME}/.kube/elastic-vps.yaml")

nc -z 127.0.0.1 26443 2>/dev/null || ssh -fN elastic-vps-k8s

"${K[@]}" apply --server-side -f "${DIR}/crds.yaml"
"${K[@]}" apply -f "${DIR}/operator.yaml"
"${K[@]}" -n elastic-system rollout status statefulset/elastic-operator --timeout=180s
