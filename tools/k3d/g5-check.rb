# frozen_string_literal: true
# tools/k3d/g5-check.rb — asercje renderu overlayów ETAPU G5 (bez klastra).
#   ruby g5-check.rb <render.yaml> <overlay> [<render g5-local.yaml>]
# Trzeci argument (tylko dla g5-local-ha): render g5-local do porównania stanu
# końcowego ze stanem wyjściowym (różnica = prune.txt + przełączenia kroków 2 i 4).
# Styl jak tools/k3s/ckad-check.rb: linie ✓/✗, kod wyjścia != 0 przy porażce.
require "yaml"
require "json"

path, overlay, local_path = ARGV
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
if !%w[g5-local g5-local-ha].include?(overlay)
  ok.(false, "nieznany overlay #{overlay}")
else
  # g5-local = stan wyjściowy migracji (stary rabbitmq + Shovele), g5-local-ha = stan końcowy.
  final = overlay == "g5-local-ha"
  want = %w[Deployment/catalog-app Deployment/outbox-publisher Deployment/search-consumer Elasticsearch/marketplace
            Job/migrate RabbitmqCluster/rabbitmq-ha StatefulSet/postgres]
  want = (want + %w[StatefulSet/rabbitmq]) unless final
  want = want.sort
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
  # Budżet (ruling R7): w trakcie migracji działają oba RabbitMQ (<= 6 Gi), po niej <= 5 Gi.
  budget = final ? 5120 : 6144
  ok.(total <= budget, "suma limitów pamięci kontenerów #{total} Mi <= #{budget} Mi (#{budget / 1024} Gi; k3d i operatory poza renderem)")

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
  ok.(peers && ports_of.(peers) == [4369, 5552, 5672, 6000, 25672, 35672] &&
      peers["ports"].any? { |x| x["port"] == 6000 && x["endPort"] == 6500 } &&
      peers["ports"].any? { |x| x["port"] == 35672 && x["endPort"] == 35682 },
      "rabbitmq-ha-ingress: pody rabbitmq-ha między sobą na 4369, 25672, 5672, 5552 , 6000-6500 (replikacja streamu) i 35672-35682 (CLI)")
  ops = rules.find do |r|
    (r["from"] || []).any? { |f| f.dig("namespaceSelector", "matchLabels", "kubernetes.io/metadata.name") == "rabbitmq-system" }
  end
  ok.(ops && ports_of.(ops) == [15672], "rabbitmq-ha-ingress: namespace rabbitmq-system na 15672")
  # catalog-app: /health sprawdza RabbitMQ po TCP (jak w bazowej rabbitmq-ingress) — tylko 5672.
  cat_rule = rules.find { |r| apps_of.(r) == %w[catalog-app] }
  ok.(cat_rule && ports_of.(cat_rule) == [5672], "rabbitmq-ha-ingress: catalog-app tylko na 5672 (/health)")
  ok.(rules.size == 4, "rabbitmq-ha-ingress: dokładnie cztery reguły, nic więcej z marketplace (jest: #{rules.size})")
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

  pols = docs.select { |d| d["kind"] == "NetworkPolicy" }.map { |d| d.dig("metadata", "name") }
  %w[default-deny allow-dns allow-egress-in-namespace].each do |n|
    ok.(pols.include?(n), "NetworkPolicy #{n} z bazy")
  end

  # --- adresy RabbitMQ w aplikacjach (migracja, spec G5 sekcja 5) ---
  # Efektywna wartość zmiennej w kontenerze: jawne env wygrywa z envFrom (ConfigMapa z renderu).
  cms = docs.select { |d| d["kind"] == "ConfigMap" }
  deploy = ->(n) { docs.find { |d| d["kind"] == "Deployment" && d.dig("metadata", "name") == n } }
  container = lambda do |dep, cname|
    dep && (dep.dig("spec", "template", "spec", "containers") || []).find { |c| c["name"] == cname }
  end
  effective = lambda do |c, var|
    e = (c["env"] || []).find { |x| x["name"] == var }
    next(e["value"] || e["valueFrom"]) if e
    (c["envFrom"] || []).map { |f| f.dig("configMapRef", "name") }.compact.reverse_each do |cm_name|
      cm = cms.find { |m| m.dig("metadata", "name") == cm_name }
      v = cm && (cm["data"] || {})[var]
      break v if v
    end
  end
  host_want = final ? "rabbitmq-ha" : "rabbitmq"
  [%w[catalog-app catalog], %w[outbox-publisher publisher]].each do |dep, cname|
    c = container.(deploy.(dep), cname)
    got = c && effective.(c, "RABBITMQ_HOST")
    ok.(got == host_want, "#{dep}/#{cname}: efektywny RABBITMQ_HOST = #{host_want} (jest: #{got.inspect})")
  end
  # Hasło: użytkownik marketplace na rabbitmq-ha ma WŁASNE hasło (rabbitmq-ha-marketplace-user).
  pw_want = final ? { "name" => "rabbitmq-ha-marketplace-user", "key" => "password" } : { "name" => "marketplace-secrets", "key" => "RABBITMQ_PASSWORD" }
  [%w[catalog-app catalog], %w[outbox-publisher publisher]].each do |dep, cname|
    c = container.(deploy.(dep), cname)
    pw = c && (c["env"] || []).find { |x| x["name"] == "RABBITMQ_PASSWORD" }
    ok.(pw && pw["valueFrom"] == { "secretKeyRef" => pw_want }, "#{dep}/#{cname}: RABBITMQ_PASSWORD z #{pw_want['name']}/#{pw_want['key']}")
  end
  dsn_key = final ? "MESSENGER_TRANSPORT_DSN_HA" : "MESSENGER_TRANSPORT_DSN"
  c = container.(deploy.("search-consumer"), "consumer")
  dsn = c && (c["env"] || []).find { |x| x["name"] == "MESSENGER_TRANSPORT_DSN" }
  ok.(dsn && dsn["valueFrom"] == { "secretKeyRef" => { "name" => "marketplace-secrets", "key" => dsn_key } },
      "search-consumer: MESSENGER_TRANSPORT_DSN z marketplace-secrets/#{dsn_key}")

  shovels = docs.select { |d| d["kind"] == "Shovel" }
  queues = JSON.parse(File.read(File.expand_path("../../infra/rabbitmq/definitions.template.json", __dir__)))["queues"]
               .map { |q| q["name"] }.sort
  if final
    # --- stan końcowy: bez starego RabbitMQ i bez Shoveli ---
    ok.(shovels.empty?, "bez Shoveli (migracja to tylko g5-local; jest: #{shovels.size})")
    old = docs.select do |d|
      d.dig("metadata", "labels", "app") == "rabbitmq" ||
        d.dig("spec", "podSelector", "matchLabels", "app") == "rabbitmq" ||
        d.dig("spec", "selector", "app") == "rabbitmq" ||
        (%w[StatefulSet Service ServiceAccount].include?(d["kind"]) && d.dig("metadata", "name") == "rabbitmq")
    end.map { |d| "#{d['kind']}/#{d.dig('metadata', 'name')}" }
    ok.(old.empty?, "żadnego obiektu starego RabbitMQ (app: rabbitmq; jest: #{old.join(', ')})")
    inits = ->(d) { d.dig("spec", "template", "spec", "initContainers") || [] }
    old_dsn = workloads.flat_map { |d| containers.(d) + inits.(d) }.flat_map { |x| x["env"] || [] }
                       .select { |e| e.dig("valueFrom", "secretKeyRef", "key") == "MESSENGER_TRANSPORT_DSN" }
    ok.(old_dsn.empty?, "żaden kontener (też init, też Job migrate) nie bierze starego MESSENGER_TRANSPORT_DSN")
    # Żaden pod RabbitMQ jako root: szukamy runAsUser: 0 w całym obiekcie (łącznie z override CR-a).
    deep_uid0 = lambda do |o|
      case o
      when Hash then o.any? { |k, v| (k == "runAsUser" && v.to_i.zero? && !v.nil?) || deep_uid0.(v) }
      when Array then o.any? { |v| deep_uid0.(v) }
      else false
      end
    end
    rmq_objs = workloads.select { |d| d.dig("metadata", "name").to_s.include?("rabbitmq") || d.to_yaml.include?("rabbitmq:") }
    root = rmq_objs.select { |d| deep_uid0.(d) }.map { |d| "#{d['kind']}/#{d.dig('metadata', 'name')}" }
    ok.(!rmq_objs.empty? && root.empty?, "żaden pod RabbitMQ z runAsUser: 0 (#{root.join(', ')})")
    no_nonroot = rmq_objs.select { |d| %w[StatefulSet Deployment].include?(d["kind"]) }
                         .reject { |d| d.dig("spec", "template", "spec", "securityContext", "runAsNonRoot") == true }
    ok.(no_nonroot.empty?, "RabbitMQ tylko z operatora (uid 999), bez własnych podów bez runAsNonRoot")
    # prune.txt: deploy.sh kasuje te obiekty; żaden z nich nie może wracać z renderu.
    prune_path = File.expand_path("../../deploy/overlays/#{overlay}/prune.txt", __dir__)
    prune = File.exist?(prune_path) ? File.readlines(prune_path, chomp: true).map(&:strip).reject { |l| l.empty? || l.start_with?("#") } : []
    ok.(prune.include?("statefulset/rabbitmq") && prune.include?("service/rabbitmq"),
        "prune.txt: kasuje statefulset/rabbitmq i service/rabbitmq")
    queues.each do |q|
      n = "shovel.rabbitmq.com/migrate-#{q.tr('.', '-')}"
      ok.(prune.include?(n), "prune.txt: kasuje #{n}")
    end
    rendered = docs.map { |d| "#{d['kind'].downcase}/#{d.dig('metadata', 'name')}" }
    back = prune.select { |p| rendered.include?(p.sub(/\Ashovel\.rabbitmq\.com/, "shovel")) }
    ok.(back.empty?, "prune.txt: nic z listy nie jest w renderze (jest: #{back.join(', ')})")

    # Stan końcowy = stan wyjściowy (g5-local) - prune.txt + przełączenia z kroków 2 i 4.
    # Każda inna różnica znaczyłaby, że `deploy.sh g5-local-ha` po migracji zmienia coś
    # więcej niż usunięcie starego RabbitMQ (rollout postgresa, inny limit itd.).
    if local_path
      local_docs = YAML.load_stream(File.read(local_path)).compact
      key = ->(d) { d["kind"] == "Shovel" ? "shovel.rabbitmq.com/#{d.dig('metadata', 'name')}" : "#{d['kind'].downcase}/#{d.dig('metadata', 'name')}" }
      gone = (local_docs.map(&key) - docs.map(&key)).sort
      ok.(gone == prune.sort, "g5-local -> g5-local-ha: znika dokładnie prune.txt (różnica: #{(gone - prune) | (prune - gone)})")
      added = (docs.map(&key) - local_docs.map(&key)).sort
      ok.(added.empty?, "g5-local -> g5-local-ha: żadnych nowych obiektów (jest: #{added.join(', ')})")
      # Zmiany na żywo: migrate.sh robi `kk patch --type=json` plikami <deployment>-env.yaml
      # z overlayu. Tu stosujemy je (test/add/replace, JSON Pointer) do kopii obiektu z g5-local;
      # wszystko inne ma być identyczne.
      ha_dir = File.expand_path("../../deploy/overlays/#{overlay}", __dir__)
      json_patch = lambda do |doc, ops|
        ops.each do |op|
          *parents, last = op["path"].split("/").drop(1)
          tgt = parents.reduce(doc) { |o, k| o.is_a?(Array) ? o[k.to_i] : o[k] }
          case op["op"]
          when "test" then raise "test #{op['path']}" unless tgt[tgt.is_a?(Array) ? last.to_i : last] == op["value"]
          when "add" then last == "-" ? tgt << op["value"] : (tgt.is_a?(Array) ? tgt.insert(last.to_i, op["value"]) : tgt[last] = op["value"])
          when "replace" then tgt[tgt.is_a?(Array) ? last.to_i : last] = op["value"]
          else raise "nieobsługiwana operacja #{op['op']}"
          end
        end
        doc
      end
      live = lambda do |d|
        d = Marshal.load(Marshal.dump(d))
        f = File.join(ha_dir, "#{d.dig('metadata', 'name')}-env.yaml")
        d["kind"] == "Deployment" && File.exist?(f) ? json_patch.(d, YAML.safe_load(File.read(f))) : d
      end
      %w[catalog-app outbox-publisher search-consumer].each do |n|
        ok.(File.exist?(File.join(ha_dir, "#{n}-env.yaml")), "#{overlay}/#{n}-env.yaml istnieje (migrate.sh stosuje go na żywo)")
      end
      changed = docs.reject { |d| d["kind"] == "Job" && d.dig("metadata", "name") == "migrate" }.reject do |d|
        l = local_docs.find { |x| key.(x) == key.(d) }
        l && live.(l) == d
      end.map(&key)
      ok.(changed.empty?, "g5-local + kroki 2 i 4 == g5-local-ha dla każdego obiektu poza Jobem migrate (różne: #{changed.join(', ')})")
    end
  else
    # --- stan wyjściowy: Shovel dla każdej kolejki starego węzła (definitions.template.json) ---
    from_ha = netpols.find { |d| d.dig("metadata", "name") == "rabbitmq-from-ha" }
    ok.(from_ha && from_ha.dig("spec", "podSelector", "matchLabels", "app") == "rabbitmq" &&
        (from_ha.dig("spec", "ingress") || []).any? { |r| ports_of.(r) == [5672] &&
          (r["from"] || []).any? { |f| f.dig("podSelector", "matchLabels", "app.kubernetes.io/name") == "rabbitmq-ha" } },
        "NetworkPolicy rabbitmq-from-ha: rabbitmq-ha -> stary rabbitmq:5672 (Shovel)")
    ok.(shovels.map { |d| d.dig("spec", "srcQueue") }.sort == queues,
        "Shovel dla każdej kolejki starego węzła (#{queues.join(', ')}), nic więcej")
    queues.each do |q|
      sh = shovels.find { |d| d.dig("spec", "srcQueue") == q }
      sp = sh ? sh["spec"] : {}
      ok.(sh && sh.dig("metadata", "name") == "migrate-#{q.tr('.', '-')}" && sp["name"] == sh.dig("metadata", "name"),
          "Shovel #{q}: nazwa migrate-#{q.tr('.', '-')}")
      ok.(sp["ackMode"] == "on-confirm" && sp["srcDeleteAfter"] == "queue-length",
          "Shovel #{q}: ackMode on-confirm, srcDeleteAfter queue-length")
      ok.(sp["uriSecret"] == { "name" => "rabbitmq-shovel-uris" } && sp.dig("rabbitmqClusterReference", "name") == "rabbitmq-ha" &&
          sp["vhost"] == "/", "Shovel #{q}: uriSecret rabbitmq-shovel-uris, klaster rabbitmq-ha, vhost /")
      ok.(sh && sh.dig("metadata", "labels", "app.kubernetes.io/component") == "migration",
          "Shovel #{q}: etykieta component=migration (deploy.sh pomija, stosuje migrate.sh)")
      # Cel = TA SAMA kolejka na rabbitmq-ha (destQueue), nie marketplace.events: wiadomość po
      # retry wraca z kolejki delay_* z routing key = nazwa kolejki, której bindingi topic
      # nie znają — przez exchange przepadała jako unroutable (zmierzone na k3d: 2 z 1500).
      ok.(sp["destQueue"] == q && sp["destExchange"].nil? && sp["destExchangeKey"].nil?,
          "Shovel #{q}: cel kolejka #{q} na rabbitmq-ha (bez exchange'a, routing key bez znaczenia)")
    end
  end
end

if fails.positive?
  puts "g5-check: #{fails} błędów"
  exit 1
end
puts "g5-check: OK"
