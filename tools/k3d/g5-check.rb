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

WORKLOAD_KINDS = %w[Deployment StatefulSet DaemonSet Job CronJob Elasticsearch Kibana].freeze
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
            Job/migrate StatefulSet/postgres StatefulSet/rabbitmq].sort
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

  total = workloads.flat_map { |d| containers.(d) }.sum { |c| to_mi.(c.dig("resources", "limits", "memory")) }
  ok.(total <= 4608, "suma limitów pamięci kontenerów #{total} Mi <= 4608 Mi (4,5 Gi; k3d i operatory poza renderem)")

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
