#!/usr/bin/env bash
# ============================================================================
#  ingress-check.sh — Ingress k3s BEZ klastra (ETAP G2): make k3s-ingress-check
#
#  Renderuje chart Traefika z deploy/ingress/traefik-values.yaml (helm template,
#  wersja z install-ingress.sh) i sprawdza decyzje, których złamanie otworzyłoby
#  port na publicznym adresie VPS albo dało Ingress bez certyfikatu.
# ============================================================================
set -euo pipefail
cd "$(dirname "$0")/../.."
HELM="${HELM:-helm}"
version=$(sed -n 's/^TRAEFIK_CHART_VERSION="\([^"]*\)".*/\1/p' tools/k3s/install-ingress.sh)
"${HELM}" repo add traefik https://traefik.github.io/charts >/dev/null 2>&1 || true
"${HELM}" repo update traefik >/dev/null 2>&1 || true
traefik=$("${HELM}" template traefik traefik/traefik --namespace ingress --version "${version}" \
  --values deploy/ingress/traefik-values.yaml --api-versions monitoring.coreos.com/v1)

report=$(TRAEFIK="${traefik}" ruby -E UTF-8:UTF-8 -ryaml -e '# encoding: utf-8
  out = []
  ok = ->(c, m) { out << [c ? "ok" : "FAIL", m] }
  t = YAML.load_stream(ENV["TRAEFIK"]).compact
  svc = t.find { |d| d["kind"] == "Service" && d.dig("spec", "ports").to_a.any? { |p| p["port"] == 443 } }
  ok.(svc && svc.dig("spec", "type") == "ClusterIP", "Traefik: Service ClusterIP (nic na publicznym adresie węzła)")
  dep = t.find { |d| d["kind"] == "Deployment" }
  ps = dep.dig("spec", "template", "spec")
  ok.(!ps["hostNetwork"] && ps["containers"].all? { |c| (c["ports"] || []).none? { |p| p["hostPort"] } }, "Traefik: bez hostNetwork i hostPort")
  ok.(ps.dig("securityContext", "runAsNonRoot") == true && ps["containers"].all? { |c| c.dig("securityContext", "readOnlyRootFilesystem") == true }, "Traefik: non-root, read-only (profil restricted)")
  args = ps["containers"].flat_map { |c| c["args"] || [] }
  ok.(args.any? { |a| a.include?("web.http.redirections.entryPoint.scheme=https") }, "Traefik: HTTP -> HTTPS (stały redirect z entrypointu web)")
  ok.(args.none? { |a| a.include?("providers.kubernetescrd") }, "Traefik: bez CRD IngressRoute — tylko standardowy Ingress")
  ic = t.find { |d| d["kind"] == "IngressClass" }
  ok.(ic && ic.dig("metadata", "name") == "traefik" && ic.dig("metadata", "annotations", "ingressclass.kubernetes.io/is-default-class") == "true", "IngressClass traefik jako domyślna")
  ok.(t.any? { |d| d["kind"] == "ServiceMonitor" }, "Traefik: ServiceMonitor (metryki do Prometheusa z G1)")

  docs = YAML.load_stream(File.read("deploy/ingress/ingresses.yaml")).compact + YAML.load_stream(File.read("deploy/ingress/issuers.yaml")).compact
  certs = docs.select { |d| d["kind"] == "Certificate" }
  docs.select { |d| d["kind"] == "Ingress" }.each do |ing|
    ns = ing.dig("metadata", "namespace"); name = "#{ns}/#{ing.dig("metadata", "name")}"
    ok.(ing.dig("spec", "ingressClassName") == "traefik", "Ingress #{name}: ingressClassName traefik")
    hosts = ing.dig("spec", "rules").map { |r| r["host"] }
    ok.(hosts.all? { |h| h.end_with?(".marketplace.test") }, "Ingress #{name}: hosty w .marketplace.test (RFC 2606, nigdy w publicznym DNS)")
    (ing.dig("spec", "tls") || []).each do |tls|
      cert = certs.find { |c| c.dig("metadata", "namespace") == ns && c.dig("spec", "secretName") == tls["secretName"] }
      ok.(!cert.nil?, "Ingress #{name}: Secret #{tls["secretName"]} ma Certificate w TYM SAMYM namespace")
      ok.(cert && (tls["hosts"] - cert.dig("spec", "dnsNames")).empty?, "Ingress #{name}: certyfikat obejmuje #{tls["hosts"].join(", ")}")
      ok.(cert && cert.dig("spec", "issuerRef", "name") == "marketplace-ca", "Ingress #{name}: certyfikat z CA marketplace-ca")
    end
    ok.((ing.dig("spec", "tls") || []).flat_map { |x| x["hosts"] }.sort == hosts.sort, "Ingress #{name}: TLS dla każdego hosta")
  end
  ca = certs.find { |c| c.dig("metadata", "name") == "marketplace-ca" }
  ok.(ca && ca.dig("spec", "isCA") == true && ca.dig("metadata", "namespace") == "cert-manager", "CA: Certificate marketplace-ca (isCA) w namespace cert-manager")
  puts out.map { |s, m| "#{s}|#{m}" }')

FAIL=0
echo "Ingress (Traefik ${version}, deploy/ingress)"
while IFS="|" read -r st msg; do
  if [ "${st}" = ok ]; then echo "  ✓ ${msg}"; else echo "  ✗ ${msg:-$st}"; FAIL=1; fi
done <<<"${report}"
[ "${FAIL}" -eq 0 ] && echo "OK" || { echo "BŁĄD: ingress"; exit 1; }
