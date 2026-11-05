# encoding: utf-8
# ============================================================================
#  ckad-check.rb — asercje ETAPU G2 (CKAD w manifestach) BEZ klastra
#
#    kubectl kustomize deploy/k8s | ruby tools/k3s/ckad-check.rb
#
#  Wołany z tools/k3s/manifests-check.sh (CI: prod-compose.yml). Każda sekcja
#  sprawdza jedną decyzję z docs/13-PLAN-ETAP-G-K8S.md (G2) na WYRENDEROWANYCH
#  manifestach — tym, co naprawdę trafia do klastra.
#
#  Wyjątki są jawne (EXCEPTIONS niżej), każdy z powodem. Nowy Deployment bez
#  securityContext albo bez reguły NetworkPolicy oblewa test, zamiast cicho
#  dostać domyślne (czyli najszersze) uprawnienia.
# ============================================================================
require "yaml"

DOCS = YAML.load_stream(STDIN.read).compact
OUT = []
def ok(cond, msg)
  OUT << [cond ? "ok" : "FAIL", msg]
end
def find(kind, name)
  DOCS.find { |d| d["kind"] == kind && d.dig("metadata", "name") == name }
end

# ---------------------------------------------------------------------------
# Obciążenia: wszystko, co ma szablon poda (bez CRD ECK — tym zarządza operator).
POD_PATHS = {
  "Deployment" => %w[spec template],
  "StatefulSet" => %w[spec template],
  "Job" => %w[spec template],
  "CronJob" => %w[spec jobTemplate spec template],
}.freeze
WORKLOADS = DOCS.select { |d| POD_PATHS.key?(d["kind"]) }.map do |d|
  tpl = d.dig(*POD_PATHS[d["kind"]])
  { kind: d["kind"], name: d.dig("metadata", "name"), labels: tpl.dig("metadata", "labels") || {}, spec: tpl["spec"] }
end

# Wyjątki od profilu „restricted” — każdy z powodem (przewodnik etap-g2, sekcja 3).
EXCEPTIONS = {
  # Entrypoint (infra/rabbitmq/entrypoint.sh) zapisuje definitions.json do /etc/rabbitmq
  # i robi chown, a docker-entrypoint.sh zrzuca uprawnienia przez su-exec — potrzebuje roota
  # i CHOWN/SETUID/SETGID. G5 zastępuje ten StatefulSet operatorem (pody jako uid 999).
  "rabbitmq" => %i[root caps readonly],
  # Dumpy idą na hostPath /var/backups/marketplace-k8s (własność root, 0755), który
  # `make vps-backup-pull` czyta jak w D1. Root bez żadnych capabilities pisze tylko
  # do katalogów roota — reszta profilu (drop ALL, read-only, seccomp) obowiązuje.
  "pg-backup" => %i[root],
}.freeze
def exempt?(name, what)
  EXCEPTIONS.fetch(name, []).include?(what)
end

def containers(spec)
  (spec["initContainers"] || []) + (spec["containers"] || [])
end

# ---------------------------------------------------------------------------
# 1. ServiceAccount per aplikacja, bez tokena API
sa_names = DOCS.select { |d| d["kind"] == "ServiceAccount" }.map { |d| d.dig("metadata", "name") }
WORKLOADS.each do |w|
  sa = w[:spec]["serviceAccountName"]
  ok(sa && sa != "default", "#{w[:kind]}/#{w[:name]}: własny ServiceAccount (jest: #{sa || 'default'})")
  ok(sa.nil? || sa_names.include?(sa), "#{w[:kind]}/#{w[:name]}: ServiceAccount #{sa} istnieje w manifestach")
  ok(w[:spec]["automountServiceAccountToken"] == false, "#{w[:kind]}/#{w[:name]}: automountServiceAccountToken: false (nikt w marketplace nie rozmawia z API)")
end
DOCS.select { |d| d["kind"] == "ServiceAccount" && d.dig("metadata", "namespace").to_s.then { |n| n.empty? || n == "marketplace" } }.each do |sa|
  name = sa.dig("metadata", "name")
  next if name == "deployer" # konto dla narzędzi (deploy), nie dla podów — token wystawia `kubectl create token`
  ok(sa["automountServiceAccountToken"] == false, "ServiceAccount/#{name}: automountServiceAccountToken: false także na koncie")
end

# ---------------------------------------------------------------------------
# 2. securityContext (profil restricted z Pod Security Standards)
WORKLOADS.each do |w|
  id = "#{w[:kind]}/#{w[:name]}"
  psc = w[:spec]["securityContext"] || {}
  ok(psc.dig("seccompProfile", "type") == "RuntimeDefault", "#{id}: seccompProfile RuntimeDefault")
  if exempt?(w[:name], :root)
    ok(psc["runAsNonRoot"] != true, "#{id}: root jako jawny wyjątek (EXCEPTIONS)")
  else
    ok(psc["runAsNonRoot"] == true, "#{id}: runAsNonRoot: true")
    ok(psc["runAsUser"].is_a?(Integer) && psc["runAsUser"] > 0, "#{id}: runAsUser ustawiony liczbowo (runAsNonRoot nie sprawdzi nazwy użytkownika z obrazu)")
  end
  containers(w[:spec]).each do |c|
    cid = "#{id} [#{c['name']}]"
    sc = c["securityContext"] || {}
    ok(sc["allowPrivilegeEscalation"] == false, "#{cid}: allowPrivilegeEscalation: false")
    unless exempt?(w[:name], :caps)
      ok(sc.dig("capabilities", "drop") == ["ALL"], "#{cid}: capabilities.drop [ALL]")
      added = sc.dig("capabilities", "add") || []
      ok((added - ["NET_BIND_SERVICE"]).empty?, "#{cid}: dodane tylko NET_BIND_SERVICE (jest: #{added.inspect})")
    end
    ok(exempt?(w[:name], :readonly) || sc["readOnlyRootFilesystem"] == true, "#{cid}: readOnlyRootFilesystem: true")
  end
end

# NET_BIND_SERVICE tylko tam, gdzie jest frankenphp (binarka ma file capability
# cap_net_bind_service=ep: bez niej w zbiorze ograniczającym exec kończy się EPERM).
WORKLOADS.each do |w|
  containers(w[:spec]).each do |c|
    added = c.dig("securityContext", "capabilities", "add") || []
    next if added.empty?
    cmd = Array(c["command"]) + Array(c["args"])
    frankenphp = cmd.empty? || cmd.first == "frankenphp"
    ok(frankenphp && c["image"].to_s.match?(/elastic-(catalog|search)/), "#{w[:kind]}/#{w[:name]} [#{c['name']}]: NET_BIND_SERVICE tylko dla procesu frankenphp")
  end
end

# Pod Security Admission: ostrzeżenia i audyt dla profilu restricted.
ns = find("Namespace", "marketplace")
labels = ns&.dig("metadata", "labels") || {}
%w[warn audit].each do |mode|
  ok(labels["pod-security.kubernetes.io/#{mode}"] == "restricted", "Namespace marketplace: pod-security.kubernetes.io/#{mode}=restricted")
end
ok(!labels.key?("pod-security.kubernetes.io/enforce"), "Namespace marketplace: bez enforce (wyjątki: rabbitmq, pg-backup, init kontenery ECK)")

# ---------------------------------------------------------------------------
# 3. startupProbe zamiast initialDelaySeconds dla wolno startujących
{ "Deployment/catalog-app" => "catalog", "Deployment/glitchtip" => "glitchtip", "StatefulSet/rabbitmq" => "rabbitmq" }.each do |ref, cname|
  kind, name = ref.split("/")
  w = WORKLOADS.find { |x| x[:kind] == kind && x[:name] == name }
  c = w && w[:spec]["containers"].find { |x| x["name"] == cname }
  ok(c && c["startupProbe"], "#{ref}: startupProbe")
  next unless c
  %w[livenessProbe readinessProbe].each do |p|
    next unless c[p]
    ok(!c[p].key?("initialDelaySeconds"), "#{ref}: #{p} bez initialDelaySeconds (start pilnuje startupProbe)")
  end
end

puts OUT.map { |s, m| "#{s}|#{m}" }
