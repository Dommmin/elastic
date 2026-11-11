# frozen_string_literal: true
# tools/k3d/topology-parity.rb — zgodność topologii CR-ów z definitions.template.json (ETAP G5).
#   ruby topology-parity.rb <render.yaml> <definitions.template.json>
# Źródło prawdy to plik definitions (Compose); CR-y operatora mają się z nim zgadzać co do
# nazw, typów, argumentów i bindingów. Jedyna dozwolona różnica: stream analityki + jego binding.
# Dodatkowo asercje Permission użytkownika marketplace (patrz komentarz w topology.yaml).
require "yaml"
require "json"

render, defs_path = ARGV
abort "użycie: topology-parity.rb <render.yaml> <definitions.json>" unless render && defs_path
docs = YAML.load_stream(File.read(render)).compact
defs = JSON.parse(File.read(defs_path))
fails = 0
ok = lambda do |cond, msg|
  puts "  #{cond ? '✓' : '✗'} #{msg}"
  fails += 1 unless cond
end

STREAM = "search.analytics.stream"
STREAM_BINDING = ["marketplace.events", STREAM, "user.#"].freeze
mine = docs.select { |d| d.dig("spec", "rabbitmqClusterReference", "name") == "rabbitmq-ha" }
by_kind = ->(k) { mine.select { |d| d["kind"] == k } }
crs_in_ns = mine.all? { |d| d.dig("metadata", "namespace") == "marketplace" }
ok.(crs_in_ns, "wszystkie CR-y topologii w namespace marketplace")
ok.(mine.all? { |d| (d.dig("spec", "vhost") || "/") == "/" || d["kind"] == "User" }, "CR-y w vhoście /")

# --- Exchange ---------------------------------------------------------------
want_ex = defs["exchanges"].map { |e| [e["name"], e["type"], e["durable"], e["auto_delete"], e["arguments"]] }.sort
got_ex = by_kind.("Exchange").map { |d| s = d["spec"]; [s["name"], s["type"], s["durable"], s["autoDelete"] || false, s["arguments"] || {}] }.sort
ok.(got_ex == want_ex, "Exchange: nazwy, typy, durable/autoDelete, argumenty == definitions (#{got_ex.size}/#{want_ex.size})")

# --- Queue ------------------------------------------------------------------
want_q = defs["queues"].map { |q| [q["name"], q["durable"], q["auto_delete"], q["arguments"]] }
# Typ kolejki operator bierze z spec.type (nie z arguments) — doklejamy go jako x-queue-type.
got_q = by_kind.("Queue").map do |d|
  s = d["spec"]
  [s["name"], s["durable"], s["autoDelete"] || false, (s["arguments"] || {}).merge("x-queue-type" => s["type"])]
end
got_q_no_stream = got_q.reject { |q| q[0] == STREAM }
ok.(got_q_no_stream.sort_by(&:first) == want_q.sort_by(&:first),
    "Queue: nazwy, durable, argumenty (typ, DLX, delivery-limit) == definitions (poza streamem)")
ok.(got_q.size == want_q.size + 1, "Queue: dokładnie jedna dodatkowa kolejka (stream), jest #{got_q.size - want_q.size}")
stream = got_q.find { |q| q[0] == STREAM }
ok.(stream && stream[1] == true && stream[3] == { "x-queue-type" => "stream", "x-max-age" => "7D" },
    "stream #{STREAM}: durable, x-queue-type=stream, x-max-age=7D, nic więcej")
ok.(by_kind.("Queue").none? { |d| (d.dig("spec", "arguments") || {}).key?("x-queue-type") },
    "Queue: typ tylko w spec.type (x-queue-type w arguments nadpisałby go po cichu)")

# --- Binding ----------------------------------------------------------------
want_b = defs["bindings"].map { |b| [b["source"], b["destination"], b["routing_key"], b["destination_type"]] }
got_b = by_kind.("Binding").map { |d| s = d["spec"]; [s["source"], s["destination"], s["routingKey"], s["destinationType"]] }
ok.(got_b.size == got_b.uniq.size, "Binding: bez duplikatów")
ok.((got_b - [STREAM_BINDING + ["queue"]]).sort == want_b.sort && got_b.include?(STREAM_BINDING + ["queue"]),
    "Binding: zbiór (źródło, cel, klucz) == definitions + binding streamu #{STREAM_BINDING.inspect}")
names = by_kind.("Binding").map { |d| d.dig("metadata", "name") }
ok.(names.size == names.uniq.size, "Binding: unikalne metadata.name")

# --- User + Permission ------------------------------------------------------
user = by_kind.("User").find { |d| d.dig("metadata", "name") == "marketplace" }
ok.(user && user.dig("spec", "importCredentialsSecret", "name") == "rabbitmq-ha-marketplace-user",
    "User marketplace: importCredentialsSecret = rabbitmq-ha-marketplace-user")
ok.(user && (user.dig("spec", "tags") || []) == [], "User marketplace: bez tagów (nie administrator)")
# Jedyny dozwolony dodatek: użytkownik testowy perf-test (perftest.yaml) z uprawnieniami wyłącznie do g5.*.
perms = by_kind.("Permission")
ok.(perms.size == 2 && perms.map { |d| d.dig("spec", "userReference", "name") }.sort == %w[g5-perftest marketplace],
    "Permission: dokładnie dwa CR-y (marketplace + g5-perftest)")
pt_user = by_kind.("User").find { |d| d.dig("metadata", "name") == "g5-perftest" }
ok.(by_kind.("User").size == 2 && pt_user && pt_user.dig("spec", "importCredentialsSecret", "name") == "rabbitmq-ha-perftest-user" &&
    (pt_user.dig("spec", "tags") || []) == [], "User: dokładnie marketplace + g5-perftest (bez tagów)")
pt_perm = perms.find { |d| d.dig("spec", "userReference", "name") == "g5-perftest" }
pp = pt_perm && pt_perm.dig("spec", "permissions")
ok.(pp && pt_perm.dig("spec", "vhost") == "/" && %w[configure write read].all? { |k| pp[k].to_s.match?(/\A\^\(?g5/) } &&
    !Regexp.new(pp["configure"]).match?("search.product.sync") && !Regexp.new(pp["write"]).match?("marketplace.events") && !Regexp.new(pp["read"]).match?("search.product.sync"),
    "Permission g5-perftest: tylko g5.*, bez kolejek ani exchange'y sklepu")
perm = perms.find { |d| d.dig("spec", "userReference", "name") == "marketplace" }
p = perm && perm.dig("spec", "permissions")
ok.(perm && perm.dig("spec", "userReference", "name") == "marketplace" && perm.dig("spec", "vhost") == "/",
    "Permission: userReference=marketplace, vhost /")
if p
  m = ->(re, name) { Regexp.new(re).match?(name) }   # RabbitMQ: dopasowanie nieskotwiczone, my kotwiczymy w regexie
  queues = defs["queues"].map { |q| q["name"] } + [STREAM]
  ok.(%w[configure write read].all? { |k| p[k].is_a?(String) && p[k].start_with?("^") && p[k].end_with?("$") },
      "Permission: wszystkie trzy regexy kotwiczone ^...$ (bez wildcardu .*)")
  ok.(m.(p["configure"], "delay_search.product.sync_retry_1000") && m.(p["configure"], "delay_x"), "Permission: configure pasuje do delay_* (kolejki opóźnień Messengera)")
  ok.(queues.all? { |q| m.(p["configure"], q) }, "Permission: configure pasuje do wszystkich kolejek sklepu")
  ok.(!m.(p["configure"], "marketplace.events") && !m.(p["configure"], "amq.gen-x") && !m.(p["configure"], "other"),
      "Permission: configure NIE pasuje do exchange'y ani obcych nazw")
  ok.(m.(p["write"], "marketplace.events") && m.(p["write"], "marketplace.delays"), "Permission: write pasuje do marketplace.events (publikator) i marketplace.delays (retry)")
  ok.(m.(p["write"], "") && m.(p["write"], "delay_x"), "Permission: write pasuje do default exchange ('' — DLX kolejek delay_*) i delay_*")
  ok.(!m.(p["write"], "marketplace.dlx") && !m.(p["write"], "search.product.sync"), "Permission: write NIE pasuje do marketplace.dlx ani kolejek zadań")
  ok.(queues.reject { |q| q.end_with?(".dlq") }.all? { |q| m.(p["read"], q) } && m.(p["read"], "delay_x") && m.(p["read"], "marketplace.delays"),
      "Permission: read pasuje do kolejek konsumowanych, delay_* i marketplace.delays")
  ok.(!m.(p["read"], "other"), "Permission: read NIE pasuje do obcych nazw")
end

if fails.positive?
  puts "topology-parity: #{fails} błędów"
  exit 1
end
puts "topology-parity: OK"
