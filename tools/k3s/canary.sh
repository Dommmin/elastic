#!/usr/bin/env bash
# ============================================================================
#  tools/k3s/canary.sh — canary catalog-app na k3s (ETAP G2)
#
#    make k3s-canary tag=<SHA>      # 1 pod nowej wersji obok stabilnych
#    make k3s-canary-status         # pody po track, obrazy, udział w ruchu
#    make k3s-canary-abort          # usuń canary (ruch wraca do stabilnych)
#    make k3s-deploy tag=<SHA>      # PROMOCJA: zwykłe wdrożenie (usuwa canary)
#
#  Jak to działa: Service catalog-app wybiera pody po `app: catalog-app`, a oba
#  Deploymenty mają tę etykietę (z różnym `track`). kube-proxy rozkłada nowe
#  połączenia losowo po wszystkich gotowych podach, więc canary dostaje
#  ~1/(N+1) ruchu, gdzie N = repliki stabilne (HPA: 1..3). Bez Ingressu z wagami
#  i bez service mesha — dlatego udział zależy od liczby podów, nie od procentu.
#
#  OGRANICZENIA (przewodnik etap-g2, sekcja 8):
#    - tylko zmiany BEZ migracji (albo migracje expand/contract): canary dzieli
#      bazę ze stabilną wersją, a Job migrate działa dopiero przy promocji;
#    - nie dla zmian kontraktu projekcji (search-consumer zostaje przy starej
#      wersji) — patrz komentarz w deploy.sh o mieszaniu wersji.
# ============================================================================
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
kk() { /opt/homebrew/bin/kubectl --kubeconfig "${HOME}/.kube/elastic-vps.yaml" --request-timeout=60s -n marketplace "$@"; }
nc -z 127.0.0.1 26443 2>/dev/null || ssh -fN elastic-vps-k8s

case "${1:-}" in
  start)
    TAG="${2:-}"
    [[ "${TAG}" =~ ^[0-9a-f]{40}$ ]] || { echo "Podaj pełny SHA: make k3s-canary tag=<SHA>" >&2; exit 1; }
    kk get deploy catalog-app -o yaml | ruby -E UTF-8:UTF-8 "${ROOT}/tools/k3s/canary-render.rb" "${TAG}" | kk apply -f -
    kk rollout status deploy/catalog-app-canary --timeout=300s
    "$0" status
    ;;
  status)
    echo "Deploymenty:"
    kk get deploy -l app.kubernetes.io/component -o custom-columns='NAME:.metadata.name,READY:.status.readyReplicas,IMAGE:.spec.template.spec.containers[0].image' \
      | grep -E 'NAME|catalog-app' || true
    echo; echo "Pody za Service catalog-app (TRACK = stable/canary):"
    kk get pods -l app=catalog-app -L track -o wide | awk '{print $1, $2, $3, $6, $NF}' | column -t
    stable=$(kk get pods -l app=catalog-app,track=stable --field-selector=status.phase=Running -o name | wc -l | tr -d ' ')
    canary=$(kk get pods -l app=catalog-app,track=canary --field-selector=status.phase=Running -o name | wc -l | tr -d ' ')
    [ "${canary}" -gt 0 ] && echo && echo "Canary dostaje ~$(( 100 * canary / (stable + canary) ))% nowych połączeń (${canary} z $((stable + canary)) podów)."
    echo
    echo "Porównanie w Prometheusie (make k3s-prometheus):"
    echo "  sum by (track) (rate(caddy_http_request_duration_seconds_count{job=\"caddy\",server!=\"metrics\"}[5m]))"
    echo "  sum by (track) (rate(caddy_http_request_duration_seconds_count{job=\"caddy\",server!=\"metrics\",code=~\"5..\"}[5m]))"
    ;;
  abort)
    kk delete deploy/catalog-app-canary --ignore-not-found --wait=true
    echo "Canary usunięty — ruch tylko do stabilnych podów."
    ;;
  *)
    echo "użycie: $0 start <SHA> | status | abort" >&2; exit 1 ;;
esac
