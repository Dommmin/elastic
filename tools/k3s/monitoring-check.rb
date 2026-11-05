# frozen_string_literal: true
# encoding: utf-8
# ============================================================================
#  monitoring-check.rb — asercje monitoringu BEZ klastra (ETAP G1)
#  Wołany z tools/k3s/monitoring-check.sh z trzema plikami renderu:
#    ARGV[0] = kubectl kustomize deploy/k8s            (sklep)
#    ARGV[1] = kubectl kustomize deploy/monitoring     (ServiceMonitory, reguły)
#    ARGV[2] = helm template kube-prometheus-stack     (chart z naszymi values)
#
#  Najważniejsza asercja: każdy ServiceMonitor trafia w ISTNIEJĄCY Service
#  z ISTNIEJĄCYM portem o tej nazwie. Pomyłka w etykiecie albo nazwie portu
#  nie daje błędu nigdzie — Prometheus po prostu nie ma celu, a alert
#  TargetDown milczy, bo nie ma czego uznać za „down”.
# ============================================================================
require 'yaml'

load = ->(path) { YAML.load_stream(File.read(path, encoding: 'UTF-8')).compact }
app, mon, chart = ARGV.map { |p| load.(p) }
fails = 0
ok = lambda do |cond, msg|
  puts "  #{cond ? '✓' : '✗'} #{msg}"
  fails += 1 unless cond
end
of_kind = ->(docs, kind) { docs.select { |d| d['kind'] == kind } }

puts 'ServiceMonitory -> Service w deploy/k8s'
services = of_kind.(app, 'Service')
monitors = of_kind.(mon, 'ServiceMonitor')
ok.(monitors.size == 5, "5 ServiceMonitorów (jest #{monitors.size})")
jobs = []
monitors.each do |sm|
  name = sm.dig('metadata', 'name')
  ok.(sm.dig('spec', 'namespaceSelector', 'matchNames') == ['marketplace'], "#{name}: namespace marketplace")
  want = sm.dig('spec', 'selector', 'matchLabels') || {}
  hits = services.select { |s| want.all? { |k, v| s.dig('metadata', 'labels', k) == v } }
  ok.(hits.size == 1, "#{name}: selektor #{want} trafia dokładnie w 1 Service (#{hits.map { |s| s.dig('metadata', 'name') }})")
  ports = hits.flat_map { |s| (s.dig('spec', 'ports') || []).map { |p| p['name'] } }
  sm.dig('spec', 'endpoints').each do |ep|
    ok.(ports.include?(ep['port']), "#{name}: port '#{ep['port']}' istnieje w Service")
    job = (ep['relabelings'] || []).find { |r| r['targetLabel'] == 'job' }&.fetch('replacement')
    ok.(!job.nil?, "#{name} #{ep['path']}: relabeling job=#{job}")
    jobs << job
  end
end

puts 'Reguły marketplace'
rule = of_kind.(mon, 'PrometheusRule').first
alerts = rule ? rule.dig('spec', 'groups').flat_map { |g| g['rules'] }.map { |r| r['alert'] }.compact : []
ok.(alerts.size == 18, "PrometheusRule marketplace ma 18 alertów (jest #{alerts.size})")
exprs = rule ? rule.dig('spec', 'groups').flat_map { |g| g['rules'] }.map { |r| r['expr'].to_s }.join("\n") : ''
referenced = exprs.scan(/job="([^"]+)"/).flatten.uniq
referenced.each { |j| ok.(jobs.include?(j), "job=\"#{j}\" z reguł ma cel (ServiceMonitor)") }
# Cele, które w Compose scrape'owało prometheus.yml (poza samym Prometheusem
# i Alertmanagerem — te zbiera chart). Brak któregoś = alert z ETAPU 11 ślepy.
%w[caddy catalog rabbitmq rabbitmq-queues search glitchtip].each do |j|
  ok.(jobs.include?(j), "job=#{j} jak w infra/obs/prometheus/prometheus.yml")
end

puts 'Dashboardy z repo'
cms = of_kind.(mon, 'ConfigMap').select { |c| c.dig('metadata', 'labels', 'grafana_dashboard') == '1' }
ok.(cms.size == 2, "2 ConfigMapy z etykietą grafana_dashboard=1 (jest #{cms.size})")
cms.each do |c|
  json = c['data'].values.first.to_s
  ok.(json.include?('"uid": "prometheus"'), "#{c.dig('metadata', 'name')}: źródło danych po uid=prometheus")
end

puts 'Chart kube-prometheus-stack (values.yaml)'
prom = of_kind.(chart, 'Prometheus').first
ok.(!prom.nil?, 'obiekt Prometheus w renderze')
if prom
  spec = prom['spec']
  ok.(spec['scrapeInterval'] == '15s', "scrapeInterval 15s (jest #{spec['scrapeInterval']})")
  ok.(spec['evaluationInterval'] == '15s', "evaluationInterval 15s (jest #{spec['evaluationInterval']})")
  # {} = wszystkie obiekty; z etykietą release nasze ServiceMonitory byłyby pominięte.
  %w[serviceMonitorSelector ruleSelector].each do |k|
    ok.(spec[k] == {}, "#{k} = {} (bez wymogu etykiety release; jest #{spec[k].inspect})")
  end
  ok.(spec['retentionSize'] == '4GB', 'retentionSize 4GB')
  ok.(spec.dig('storage', 'volumeClaimTemplate', 'spec', 'storageClassName') == 'local-path', 'PVC Prometheusa na local-path')
  ok.(!spec.dig('resources', 'limits', 'memory').nil?, 'Prometheus ma limit pamięci')
end
sm_names = of_kind.(chart, 'ServiceMonitor').map { |d| d.dig('metadata', 'name') }
%w[kube-etcd kube-controller-manager kube-scheduler kube-proxy].each do |n|
  ok.(sm_names.none? { |s| s.include?(n) }, "brak ServiceMonitora #{n} (k3s: jeden proces)")
end
graf = of_kind.(chart, 'Deployment').find { |d| d.dig('metadata', 'name') == 'kps-grafana' }
env = graf ? graf.dig('spec', 'template', 'spec', 'containers').flat_map { |c| c['env'] || [] } : []
pass = env.find { |e| e['name'] == 'GF_SECURITY_ADMIN_PASSWORD' }
ok.(pass&.dig('valueFrom', 'secretKeyRef', 'name') == 'grafana-admin', 'Grafana: hasło admina z Secretu grafana-admin')
deps = of_kind.(chart, 'Deployment') + of_kind.(chart, 'DaemonSet')
deps.each do |d|
  lim = d.dig('spec', 'template', 'spec', 'containers').all? { |c| c.dig('resources', 'limits', 'memory') || c['name'] =~ /reloader/ }
  ok.(lim, "#{d['kind']} #{d.dig('metadata', 'name')}: limity pamięci")
end

puts 'deploy/k8s: zmiany ETAPU G1'
pg = of_kind.(app, 'StatefulSet').find { |s| s.dig('metadata', 'name') == 'postgres' }
tmpl = pg&.dig('spec', 'template')
ok.(tmpl&.dig('metadata', 'annotations', 'kubectl.kubernetes.io/default-container') == 'postgres',
    'postgres-0: default-container=postgres (exec/logs bez -c trafiają w bazę)')
ok.(tmpl&.dig('spec', 'containers')&.map { |c| c['name'] } == %w[postgres exporter], 'postgres-0: kontenery [postgres, exporter]')
sm = of_kind.(app, 'Deployment').find { |d| d.dig('metadata', 'name') == 'search-metrics' }
ok.(!sm.nil? && sm.dig('metadata', 'labels', 'app.kubernetes.io/component') == 'app', 'Deployment search-metrics (component=app)')

puts(fails.zero? ? 'OK' : "BŁĘDY: #{fails}")
exit(fails.zero? ? 0 : 1)
