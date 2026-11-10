# frozen_string_literal: true
# tools/k3d/g5-check.rb — asercje renderu overlayów ETAPU G5 (bez klastra).
#   ruby g5-check.rb <render.yaml> <overlay>
# Styl jak tools/k3s/ckad-check.rb: linie ✓/✗, kod wyjścia != 0 przy porażce.
require "yaml"

path, overlay = ARGV
abort "użycie: g5-check.rb <render.yaml> <overlay>" unless path && overlay
docs = YAML.load_stream(File.read(path)).compact
fails = 0
ok = lambda do |cond, msg|
  puts "  #{cond ? '✓' : '✗'} #{msg}"
  fails += 1 unless cond
end

WORKLOAD_KINDS = %w[Deployment StatefulSet DaemonSet Job CronJob Elasticsearch Kibana RabbitmqCluster].freeze
workloads = docs.select { |d| WORKLOAD_KINDS.include?(d["kind"]) }
names = workloads.map { |d| "#{d['kind']}/#{d.dig('metadata', 'name')}" }.sort

# Pamięć w limitach: Mi/Gi -> Mi.
to_mi = lambda do |v|
  case v.to_s
  when /\A(\d+)Gi\z/ then Regexp.last_match(1).to_i * 1024
  when /\A(\d+)Mi\z/ then Regexp.last_match(1).to_i
  else 0
  end
end

# Kontenery ze wszystkich obciążeń (łącznie z podTemplate ES).
containers = lambda do |d|
  specs =
    case d["kind"]
    when "Deployment", "StatefulSet", "DaemonSet", "Job" then [d.dig("spec", "template", "spec")]
    when "CronJob" then [d.dig("spec", "jobTemplate", "spec", "template", "spec")]
    when "Elasticsearch" then d.dig("spec", "nodeSets").map { |n| n.dig("podTemplate", "spec") }
    else []
    end
  specs.compact.flat_map { |s| s["containers"] || [] }
end

puts "Overlay #{overlay}"
case overlay
when "g5-local"
  want = %w[Deployment/catalog-app Deployment/outbox-publisher Deployment/search-consumer Elasticsearch/marketplace
            Job/migrate RabbitmqCluster/rabbitmq-ha StatefulSet/postgres StatefulSet/rabbitmq].sort
  ok.(names == want, "obciążenia = #{want.join(', ')} (jest: #{names.join(', ')})")

  es = docs.find { |d| d["kind"] == "Elasticsearch" }
  sets = es ? es.dig("spec", "nodeSets") : []
  ok.(sets.size == 1 && sets[0]["count"] == 1, "Elasticsearch: jeden nodeSet, count: 1")
  heap = sets.flat_map { |n| n.dig("podTemplate", "spec", "containers") || [] }
             .flat_map { |c| c["env"] || [] }.find { |e| e["name"] == "ES_JAVA_OPTS" }
  ok.(heap && heap["value"] == "-Xms512m -Xmx512m", "Elasticsearch: heap -Xms512m -Xmx512m")
  es_c = sets.flat_map { |n| n.dig("podTemplate", "spec", "containers") || [] }.first
  ok.(es_c && es_c.dig("resources", "limits", "memory") == "1536Mi", "Elasticsearch: limit pamięci 1536Mi")
  ok.(!docs.any? { |d| d["kind"] == "Kibana" }, "bez Kibany")
  ok.(docs.any? { |d| d["kind"] == "Service" && d.dig("metadata", "name") == "catalog-app" }, "Service catalog-app (read-back search-consumera)")

  %w[catalog-worker catalog-scheduler search-metrics glitchtip redis].each do |n|
    ok.(!names.any? { |x| x.end_with?("/#{n}") }, "bez #{n}")
  end
  ok.(!docs.any? { |d| d["kind"] == "HorizontalPodAutoscaler" }, "bez HPA")
  ok.(!docs.any? { |d| d["kind"] == "CronJob" && d.dig("metadata", "name") == "pg-backup" }, "bez CronJob pg-backup")
  ok.(!docs.any? { |d| d["kind"] == "Job" && d.dig("metadata", "name").to_s.start_with?("glitchtip") }, "bez Jobów glitchtip")

  # RabbitmqCluster: operator sam generuje StatefulSet, więc limit z spec.resources liczymy × replicas.
  rmq = docs.find { |d| d["kind"] == "RabbitmqCluster" && d.dig("metadata", "name") == "rabbitmq-ha" }
  rmq_mi = rmq ? to_mi.(rmq.dig("spec", "resources", "limits", "memory")) * rmq.dig("spec", "replicas").to_i : 0
  total = workloads.flat_map { |d| containers.(d) }.sum { |c| to_mi.(c.dig("resources", "limits", "memory")) } + rmq_mi
  ok.(total <= 6144, "suma limitów pamięci kontenerów #{total} Mi <= 6144 Mi (6 Gi; k3d i operatory poza renderem)")

  # Obrazy aplikacji: wyłącznie tag g5-local (initContainers też).
  imgs = workloads.flat_map do |d|
    specs = case d["kind"]
            when "Elasticsearch" then d.dig("spec", "image")
            else nil
            end
    pod = d.dig("spec", "template", "spec")
    list = pod ? (pod["containers"] || []) + (pod["initContainers"] || []) : []
    [specs] + list.map { |c| c["image"] }
  end.compact.select { |i| i.start_with?("ghcr.io/dommmin/") }
  bad = imgs.reject { |i| i.end_with?(":g5-local") }
  ok.(!imgs.empty? && bad.empty?, "obrazy aplikacji z tagiem g5-local#{bad.empty? ? '' : " (zły: #{bad.uniq.join(', ')})"}")

  # --- rabbitmq-ha (operator RabbitMQ) ---
  ok.(rmq && rmq.dig("spec", "replicas") == 3, "RabbitmqCluster rabbitmq-ha: replicas: 3")
  aa = rmq && rmq.dig("spec", "affinity", "podAntiAffinity", "requiredDuringSchedulingIgnoredDuringExecution")
  ok.(aa.is_a?(Array) && aa.any? { |t| t["topologyKey"] == "kubernetes.io/hostname" },
      "rabbitmq-ha: podAntiAffinity required po kubernetes.io/hostname")
  ok.(rmq && rmq.dig("spec", "resources", "limits", "memory") == "512Mi", "rabbitmq-ha: limit pamięci 512Mi")
  plugins = rmq ? (rmq.dig("spec", "rabbitmq", "additionalPlugins") || []) : []
  %w[rabbitmq_prometheus rabbitmq_shovel rabbitmq_shovel_management rabbitmq_stream].each do |pl|
    ok.(plugins.include?(pl), "rabbitmq-ha: wtyczka #{pl}")
  end
  cfg = rmq ? rmq.dig("spec", "rabbitmq", "additionalConfig").to_s : ""
  ok.(cfg =~ /^\s*default_queue_type\s*=\s*quorum\s*$/, "rabbitmq-ha: default_queue_type = quorum")
  ok.(rmq && rmq.dig("spec", "image").to_s =~ %r{\Arabbitmq:\d+\.\d+\.\d+-management\z}, "rabbitmq-ha: obraz przypięty (rabbitmq:X.Y.Z-management)")
  env_ver = File.read(File.expand_path("../../.env.example", __dir__), encoding: "UTF-8")[/^RABBITMQ_VERSION=(\S+)/, 1]
  ok.(env_ver && rmq && rmq.dig("spec", "image") == "rabbitmq:#{env_ver}-management",
      "rabbitmq-ha: spec.image = rabbitmq:<RABBITMQ_VERSION z .env.example>-management (#{env_ver})")
  ovr = rmq ? rmq.dig("spec", "override", "statefulSet", "spec", "template", "spec") : nil
  main = ovr && (ovr["containers"] || []).find { |c| c["name"] == "rabbitmq" }
  sc = main && main["securityContext"]
  ok.(sc && sc["allowPrivilegeEscalation"] == false && sc.dig("capabilities", "drop") == ["ALL"] &&
      sc.dig("seccompProfile", "type") == "RuntimeDefault", "rabbitmq-ha: securityContext (drop ALL, bez eskalacji, seccomp RuntimeDefault)")
  init = ovr && (ovr["initContainers"] || []).find { |c| c["name"] == "setup-container" }
  isc = init && init["securityContext"]
  ok.(isc && isc["allowPrivilegeEscalation"] == false && isc.dig("capabilities", "drop") == ["ALL"], "rabbitmq-ha: securityContext initContainera setup-container")

  netpols = docs.select { |d| d["kind"] == "NetworkPolicy" }
  ha = netpols.find { |d| d.dig("metadata", "name") == "rabbitmq-ha-ingress" }
  ok.(!ha.nil?, "NetworkPolicy rabbitmq-ha-ingress")
  rules = ha ? (ha.dig("spec", "ingress") || []) : []
  ports_of = ->(r) { (r["ports"] || []).map { |x| x["port"] }.sort }
  apps_of = lambda do |r|
    (r["from"] || []).flat_map do |f|
      ex = f.dig("podSelector", "matchExpressions") || []
      ex.flat_map { |e| e["values"] || [] } + [f.dig("podSelector", "matchLabels", "app")].compact
    end.sort
  end
  clients = rules.find { |r| ports_of.(r) == [5552, 5672] && apps_of.(r) == %w[outbox-publisher perf-test search-consumer] }
  ok.(!clients.nil?, "rabbitmq-ha-ingress: outbox-publisher, search-consumer, perf-test na 5672 i 5552")
  peers = rules.find do |r|
    (r["from"] || []).any? { |f| f.dig("podSelector", "matchLabels", "app.kubernetes.io/name") == "rabbitmq-ha" }
  end
  ok.(peers && ports_of.(peers) == [4369, 5552, 5672, 6000, 25672] &&
      peers["ports"].any? { |x| x["port"] == 6000 && x["endPort"] == 6500 },
      "rabbitmq-ha-ingress: pody rabbitmq-ha między sobą na 4369, 25672, 5672, 5552 i 6000-6500 (replikacja streamu)")
  ops = rules.find do |r|
    (r["from"] || []).any? { |f| f.dig("namespaceSelector", "matchLabels", "kubernetes.io/metadata.name") == "rabbitmq-system" }
  end
  ok.(ops && ports_of.(ops) == [15672], "rabbitmq-ha-ingress: namespace rabbitmq-system na 15672")
  ok.(rules.size == 3, "rabbitmq-ha-ingress: dokładnie trzy reguły, nic więcej z marketplace (jest: #{rules.size})")
  eg = netpols.find { |d| d.dig("metadata", "name") == "rabbitmq-ha-egress-api" }
  ok.(eg && eg.dig("spec", "podSelector", "matchLabels") == { "app.kubernetes.io/name" => "rabbitmq-ha" } &&
      eg.dig("spec", "policyTypes") == ["Egress"], "rabbitmq-ha-egress-api: wybiera wyłącznie pody rabbitmq-ha, tylko Egress")
  eg_rules = eg ? (eg.dig("spec", "egress") || []) : []
  lib = File.read(File.expand_path("lib.sh", __dir__), encoding: "UTF-8")
  g5_subnet = lib[/^G5_SUBNET="([^"]+)"/, 1]
  ok.(!g5_subnet.nil?, "lib.sh: G5_SUBNET zdefiniowane (#{g5_subnet})")
  ok.(eg_rules.size == 1 && (eg_rules[0]["to"] || []).map { |t| t["ipBlock"] } == [{ "cidr" => g5_subnet }] &&
      (eg_rules[0]["ports"] || []).map { |x| [x["port"], x["protocol"]] } == [[6443, "TCP"]],
      "rabbitmq-ha-egress-api: jedna reguła, tylko G5_SUBNET z lib.sh (sieć k3d) na 6443/TCP")
  from_ha = netpols.find { |d| d.dig("metadata", "name") == "rabbitmq-from-ha" }
  ok.(from_ha && from_ha.dig("spec", "podSelector", "matchLabels", "app") == "rabbitmq" &&
      (from_ha.dig("spec", "ingress") || []).any? { |r| ports_of.(r) == [5672] &&
        (r["from"] || []).any? { |f| f.dig("podSelector", "matchLabels", "app.kubernetes.io/name") == "rabbitmq-ha" } },
      "NetworkPolicy rabbitmq-from-ha: rabbitmq-ha -> stary rabbitmq:5672 (Shovel)")

  pols = docs.select { |d| d["kind"] == "NetworkPolicy" }.map { |d| d.dig("metadata", "name") }
  %w[default-deny allow-dns allow-egress-in-namespace].each do |n|
    ok.(pols.include?(n), "NetworkPolicy #{n} z bazy")
  end
else
  ok.(false, "nieznany overlay #{overlay}")
end

if fails.positive?
  puts "g5-check: #{fails} błędów"
  exit 1
end
puts "g5-check: OK"
