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

# --- GlitchTip na k3s (opcjonalny: aktywuje go Secret glitchtip-secrets z secrets.sh) --------
echo "GlitchTip na k3s"
glitchtip_report=$(echo "$rendered" | ruby -E UTF-8:UTF-8 -ryaml -e '# encoding: utf-8
  docs = YAML.load_stream(STDIN.read).compact
  find = ->(kind, name) { docs.find { |d| d["kind"] == kind && d.dig("metadata", "name") == name } }
  out = []
  ok  = ->(cond, msg) { out << [cond ? "ok" : "FAIL", msg] }

  dep = find.("Deployment", "glitchtip")
  ok.(!dep.nil?, "Deployment glitchtip istnieje")
  if dep
    c = dep.dig("spec", "template", "spec", "containers", 0)
    ok.(c["image"].include?("elastic-glitchtip"), "glitchtip: obraz elastic-glitchtip z CI (nie oficjalny, bo skrypty są w środku)")
    ok.(c.dig("resources", "limits", "memory") == "512Mi", "glitchtip: limit pamięci 512Mi (rekomendacja producenta dla all-in-one)")
    ok.(!c["readinessProbe"].nil? && !c["livenessProbe"].nil?, "glitchtip: sondy readiness i liveness")
    env = (c["env"] || []).to_h { |e| [e["name"], e] }
    ok.(env.dig("GLITCHTIP_STRICT_SECRETS", "value") == "true", "glitchtip: GLITCHTIP_STRICT_SECRETS=true (guard.py odmawia wartości z repo)")
    ok.(env.dig("GLITCHTIP_DOMAIN", "value") == "http://localhost:28000", "glitchtip: domena = adres w tunelu (localhost:28000)")
    ok.(env.dig("GLITCHTIP_RETENTION_DAYS", "value") == "14", "glitchtip: retencja 14 dni")
    from = (c["envFrom"] || []).map { |e| e.dig("secretRef", "name") }
    ok.(from.include?("glitchtip-secrets"), "glitchtip: sekrety z glitchtip-secrets (tworzy je secrets.sh, nie repo)")
  end
  ok.(!find.("Service", "glitchtip").nil?, "Service glitchtip (DSN-y wskazują glitchtip:8000)")
  %w[glitchtip-db-setup glitchtip-setup].each { |j| ok.(!find.("Job", j).nil?, "Job #{j}") }
  ok.(find.("Secret", "glitchtip-secrets").nil?, "w repo nie ma Secretu glitchtip-secrets (sekrety powstają na serwerze)")

  # SDK w aplikacjach: DSN z opcjonalnego Secretu, więc bez GlitchTipa nic się nie zmienia.
  { "catalog-app" => "SENTRY_LARAVEL_DSN", "outbox-publisher" => "SENTRY_LARAVEL_DSN",
    "catalog-worker" => "SENTRY_LARAVEL_DSN", "search-consumer" => "SENTRY_DSN" }.each do |name, var|
    d = find.("Deployment", name)
    e = d && (d.dig("spec", "template", "spec", "containers", 0, "env") || []).find { |x| x["name"] == var }
    ref = e && e.dig("valueFrom", "secretKeyRef")
    good = ref && ref["name"] == "glitchtip-secrets" && ref["optional"] == true
    ok.(good, "#{name}: #{var} z glitchtip-secrets (optional: true)")
  end
  puts out.map { |s, m| "#{s}|#{m}" }
' 2>&1)
while IFS='|' read -r st msg; do
  [ -z "$msg" ] && { echo "  ✗ $st"; FAIL=1; continue; }
  if [ "$st" = ok ]; then echo "  ✓ $msg"; else echo "  ✗ $msg"; FAIL=1; fi
done <<<"$glitchtip_report"

# Krok 1/3 deploy.sh nakłada "infrastrukturę" selektorem `component notin (...)`. Zasoby GlitchTipa
# nie mogą się w nim znaleźć, bo GlitchTip jest opcjonalny (bez Secretu Deploymenty by nie wstały).
infra_sel=$(grep -o "component notin ([^)]*)" tools/k3s/deploy.sh | head -1)
leaked=$(echo "$rendered" | INFRA_SEL="$infra_sel" ruby -E UTF-8:UTF-8 -ryaml -e '# encoding: utf-8
  excluded = ENV["INFRA_SEL"][/\((.*)\)/, 1].to_s.split(",").map(&:strip)
  docs = YAML.load_stream(STDIN.read).compact
  names = docs.select { |d| d.dig("metadata", "name").to_s.start_with?("glitchtip") && d["kind"] != "Secret" }
              .select { |d| !excluded.include?(d.dig("metadata", "labels", "app.kubernetes.io/component").to_s) }
              .map { |d| "#{d["kind"]}/#{d.dig("metadata", "name")}" }
  puts names.join(" ")')
if [ -z "$leaked" ]; then echo "  ✓ krok infrastruktury w deploy.sh nie obejmuje GlitchTipa ($infra_sel)"; else echo "  ✗ krok infrastruktury w deploy.sh nałożyłby GlitchTipa bezwarunkowo: $leaked"; FAIL=1; fi

[ "$FAIL" -eq 0 ] && echo "OK: manifesty k8s" || { echo "BŁĄD: manifesty k8s"; exit 1; }
