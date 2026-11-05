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

# ---------------------------------------------------------------------------
# 4. NetworkPolicy — mały ewaluator semantyki z dokumentacji Kubernetesa:
#    ruch przechodzi, gdy pozwala na niego ZARÓWNO strona wysyłająca (Egress
#    polityk wybierających nadawcę), jak i odbierająca (Ingress polityk
#    wybierających odbiorcę). Pod niewybrany przez żadną politykę danego typu
#    = brak ograniczeń w tym kierunku. Polityki się SUMUJĄ (OR), nie ma „deny”.
NETPOLS = DOCS.select { |d| d["kind"] == "NetworkPolicy" }
ok(NETPOLS.any? { |p| p.dig("spec", "podSelector") == {} && %w[Ingress Egress].all? { |t| (p.dig("spec", "policyTypes") || []).include?(t) } && p.dig("spec", "ingress").nil? && p.dig("spec", "egress").nil? },
   "NetworkPolicy: default-deny (pusty podSelector, Ingress+Egress, zero reguł)")

def selector_match?(sel, labels)
  return true if sel.nil? || sel == {}
  (sel["matchLabels"] || {}).all? { |k, v| labels[k] == v } &&
    (sel["matchExpressions"] || []).all? do |e|
      val = labels[e["key"]]
      case e["operator"]
      when "In" then e["values"].include?(val)
      when "NotIn" then !e["values"].include?(val)
      when "Exists" then labels.key?(e["key"])
      when "DoesNotExist" then !labels.key?(e["key"])
      end
    end
end

# Pod = { ns:, labels: } albo { ip: :internet }.
def peer_match?(peer, pod, policy_ns)
  if peer["ipBlock"]
    return pod[:ip] == :internet # w tym teście ipBlock opisuje tylko świat poza klastrem
  end
  return false if pod[:ip]
  ns_ok = if peer["namespaceSelector"]
            selector_match?(peer["namespaceSelector"], "kubernetes.io/metadata.name" => pod[:ns])
          else
            pod[:ns] == policy_ns
          end
  ns_ok && selector_match?(peer["podSelector"], pod[:labels])
end

def port_match?(ports, port, proto)
  return true if ports.nil? || ports.empty?
  ports.any? { |p| p["port"] == port && (p["protocol"] || "TCP") == proto }
end

# Czy kierunek `type` (Ingress/Egress) pozwala: `self_pod` = pod objęty polityką, `peer` = druga strona.
def direction_allows?(type, self_pod, peer, port, proto)
  return true if self_pod[:ip] || self_pod[:ns] != "marketplace" # polityki są tylko w marketplace
  pols = NETPOLS.select { |p| (p.dig("spec", "policyTypes") || []).include?(type) && selector_match?(p.dig("spec", "podSelector"), self_pod[:labels]) }
  return true if pols.empty?
  key, peers_key = type == "Ingress" ? %w[ingress from] : %w[egress to]
  pols.any? do |p|
    (p.dig("spec", key) || []).any? do |rule|
      peers = rule[peers_key]
      (peers.nil? || peers.any? { |pe| peer_match?(pe, peer, p.dig("metadata", "namespace") || "marketplace") }) &&
        port_match?(rule["ports"], port, proto)
    end
  end
end

def allowed?(src, dst, port, proto = "TCP")
  direction_allows?("Egress", src, dst, port, proto) && direction_allows?("Ingress", dst, src, port, proto)
end

pod_labels = WORKLOADS.map { |w| [w[:name], w[:labels]] }.to_h
mp = ->(name) { { ns: "marketplace", labels: pod_labels.fetch(name) { raise "brak obciążenia #{name} w manifestach" } } }
P = {
  "catalog-app" => mp.("catalog-app"),
  "catalog-app-canary" => { ns: "marketplace", labels: pod_labels["catalog-app"].merge("track" => "canary") },
  "outbox-publisher" => mp.("outbox-publisher"), "catalog-worker" => mp.("catalog-worker"),
  "catalog-scheduler" => mp.("catalog-scheduler"), "migrate" => mp.("migrate"),
  "search-consumer" => mp.("search-consumer"), "search-metrics" => mp.("search-metrics"),
  "postgres" => mp.("postgres"), "redis" => mp.("redis"), "rabbitmq" => mp.("rabbitmq"),
  "glitchtip" => mp.("glitchtip"), "glitchtip-db-setup" => mp.("glitchtip-db-setup"),
  "glitchtip-setup" => mp.("glitchtip-setup"), "pg-backup" => mp.("pg-backup"),
  # Pody tworzone przez ECK (etykiety jak na klastrze: kubectl get pod --show-labels)
  "es" => { ns: "marketplace", labels: { "common.k8s.elastic.co/type" => "elasticsearch", "elasticsearch.k8s.elastic.co/cluster-name" => "marketplace" } },
  "kibana" => { ns: "marketplace", labels: { "common.k8s.elastic.co/type" => "kibana", "kibana.k8s.elastic.co/name" => "marketplace" } },
  "debug-pod" => { ns: "marketplace", labels: { "run" => "debug" } }, # kubectl run w namespace sklepu
  "eck-operator" => { ns: "elastic-system", labels: { "control-plane" => "elastic-operator" } },
  "prometheus" => { ns: "monitoring", labels: { "app.kubernetes.io/name" => "prometheus" } },
  "traefik" => { ns: "ingress", labels: { "app.kubernetes.io/name" => "traefik" } },
  "loadgen" => { ns: "loadtest", labels: { "app" => "loadgen" } },
  "coredns" => { ns: "kube-system", labels: { "k8s-app" => "kube-dns" } },
  "default-pod" => { ns: "default", labels: { "run" => "x" } },
  "internet" => { ip: :internet },
}.freeze

ALLOWED = [
  # kto ma hasło do usługi, ten ma do niej trasę (env w manifestach) — i nic więcej
  *%w[catalog-app catalog-app-canary outbox-publisher].flat_map { |s| [[s, "postgres", 5432], [s, "redis", 6379], [s, "rabbitmq", 5672], [s, "es", 9200], [s, "glitchtip", 8000]] },
  ["catalog-worker", "postgres", 5432], ["catalog-worker", "redis", 6379], ["catalog-worker", "glitchtip", 8000],
  ["catalog-worker", "catalog-app", 80], # webhook bramki płatności (ETAP F)
  ["catalog-scheduler", "postgres", 5432], ["catalog-scheduler", "redis", 6379], ["catalog-scheduler", "glitchtip", 8000],
  ["migrate", "postgres", 5432], ["migrate", "es", 9200],
  ["search-consumer", "rabbitmq", 5672], ["search-consumer", "postgres", 5432], ["search-consumer", "es", 9200],
  ["search-consumer", "glitchtip", 8000], ["search-consumer", "catalog-app", 80], ["search-consumer", "catalog-app-canary", 80],
  ["search-metrics", "postgres", 5432], ["search-metrics", "glitchtip", 8000],
  ["glitchtip", "postgres", 5432], ["glitchtip-db-setup", "postgres", 5432], ["glitchtip-setup", "postgres", 5432],
  ["pg-backup", "postgres", 5432],
  ["kibana", "es", 9200], ["es", "es", 9300], ["es", "es", 9200], ["eck-operator", "es", 9200],
  ["prometheus", "catalog-app", 2020], ["prometheus", "catalog-app-canary", 2020], ["prometheus", "rabbitmq", 15692],
  ["prometheus", "postgres", 9187], ["prometheus", "search-metrics", 80], ["prometheus", "glitchtip", 8000],
  ["traefik", "catalog-app", 80], ["traefik", "catalog-app-canary", 80], ["traefik", "kibana", 5601],
].freeze

DENIED = [
  ["search-consumer", "redis", 6379, "search nie ma hasła do Redisa"],
  ["search-metrics", "rabbitmq", 5672, "metryki czytają tylko failure transport (Postgres)"],
  ["catalog-scheduler", "es", 9200, "scheduler nie ma użytkownika ES"],
  ["catalog-app", "internet", 443, "żaden pod sklepu nie wychodzi do internetu"],
  ["debug-pod", "postgres", 5432, "pod spoza listy (kubectl run) nie dosięga bazy"],
  ["debug-pod", "catalog-app", 80, "pod spoza listy nie dosięga aplikacji"],
  ["default-pod", "catalog-app", 80, "inny namespace nie dosięga aplikacji"],
  ["prometheus", "postgres", 5432, "monitoring dostaje tylko porty metryk"],
  ["prometheus", "rabbitmq", 15672, "UI RabbitMQ tylko przez port-forward"],
  ["traefik", "postgres", 5432, "ingress dostaje tylko HTTP aplikacji i Kibany"],
  ["traefik", "catalog-app", 2020, "metryki nie idą przez ingress"],
  ["loadgen", "catalog-app", 80, "generator obciążenia idzie przez ingress, nie na skróty"],
  ["kibana", "postgres", 5432, "Kibana mówi tylko z ES"],
  ["es", "postgres", 5432, "ES nie ma czego szukać w bazie"],
].freeze

ALLOWED.each { |s, d, port| ok(allowed?(P[s], P[d], port), "NetworkPolicy pozwala: #{s} -> #{d}:#{port}") }
DENIED.each { |s, d, port, why| ok(!allowed?(P[s], P[d], port), "NetworkPolicy blokuje: #{s} -> #{d}:#{port} (#{why})") }
%w[catalog-app search-consumer postgres es kibana migrate pg-backup glitchtip-setup].each do |s|
  ok(allowed?(P[s], P["coredns"], 53, "UDP") && allowed?(P[s], P["coredns"], 53, "TCP"), "NetworkPolicy pozwala: #{s} -> DNS (kube-system:53 UDP i TCP)")
end

# Każdy port każdego Service w marketplace ma KOGOŚ, kto może do niego mówić —
# inaczej port jest martwy (zapomniana reguła po dodaniu portu).
peers_all = P.values
# Porty celowo bez żadnej reguły: dostęp tylko przez `kubectl port-forward`
# (kubelet łączy się z wnętrza poda, polityki go nie dotyczą).
PORT_FORWARD_ONLY = { "rabbitmq" => [15672] }.freeze
DOCS.select { |d| d["kind"] == "Service" }.each do |svc|
  sel = svc.dig("spec", "selector") || {}
  targets = P.values.select { |p| p[:ns] == "marketplace" && selector_match?({ "matchLabels" => sel }, p[:labels]) }
  next if targets.empty?
  (svc.dig("spec", "ports") || []).each do |pt|
    port = pt["targetPort"].is_a?(Integer) ? pt["targetPort"] : pt["port"]
    if PORT_FORWARD_ONLY.fetch(svc.dig("metadata", "name"), []).include?(pt["port"])
      ok(targets.none? { |t| peers_all.any? { |src| src != t && allowed?(src, t, port) } }, "Service #{svc.dig('metadata', 'name')}:#{pt['port']} tylko przez port-forward (żadnej reguły)")
      next
    end
    reachable = targets.any? { |t| peers_all.any? { |src| src != t && allowed?(src, t, port) } }
    ok(reachable, "Service #{svc.dig('metadata', 'name')}:#{pt['port']} ma choć jednego dozwolonego klienta")
  end
end

puts OUT.map { |s, m| "#{s}|#{m}" }
