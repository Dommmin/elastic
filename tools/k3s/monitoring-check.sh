#!/usr/bin/env bash
# ============================================================================
#  monitoring-check.sh — monitoring k3s BEZ klastra (ETAP G1): make k3s-monitoring-check
#
#  1. deploy/monitoring/prometheus-rule.yaml zsynchronizowany z regułami ETAPU 11
#  2. render sklepu, obiektów monitoringu i chartu (helm template, offline
#     po pierwszym pobraniu chartu) + asercje w monitoring-check.rb
# ============================================================================
set -euo pipefail
cd "$(dirname "$0")/../.."
CHART_VERSION="$(sed -n 's/^CHART_VERSION="\([^"]*\)".*/\1/p' tools/k3s/install-monitoring.sh)"
TMP="$(mktemp -d)"
trap 'rm -r "${TMP}"' EXIT

ruby tools/k3s/gen-prometheus-rule.rb --check
kubectl kustomize deploy/k8s > "${TMP}/app.yaml"
kubectl kustomize --load-restrictor LoadRestrictionsNone deploy/monitoring > "${TMP}/mon.yaml"
helm repo add prometheus-community https://prometheus-community.github.io/helm-charts >/dev/null 2>&1 || true
helm template kps prometheus-community/kube-prometheus-stack --version "${CHART_VERSION}" \
  --namespace monitoring --values deploy/monitoring/values.yaml > "${TMP}/chart.yaml" 2>"${TMP}/helm.err" || {
  helm repo update prometheus-community >/dev/null
  helm template kps prometheus-community/kube-prometheus-stack --version "${CHART_VERSION}" \
    --namespace monitoring --values deploy/monitoring/values.yaml > "${TMP}/chart.yaml"
}
ruby -E UTF-8:UTF-8 tools/k3s/monitoring-check.rb "${TMP}/app.yaml" "${TMP}/mon.yaml" "${TMP}/chart.yaml"
