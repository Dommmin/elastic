#!/usr/bin/env ruby
# frozen_string_literal: true
# ============================================================================
#  tools/k3s/gen-prometheus-rule.rb — PrometheusRule z reguł ETAPU 11 (G1)
#
#    ruby tools/k3s/gen-prometheus-rule.rb > deploy/monitoring/prometheus-rule.yaml
#    ruby tools/k3s/gen-prometheus-rule.rb --check   # CI: plik aktualny?
#
#  Źródło prawdy to infra/obs/prometheus/rules/marketplace.yml (testowany
#  przez promtool: make obs-rules-test). Prometheus Operator nie czyta plików
#  reguł — chce obiektu PrometheusRule z tymi samymi `groups` w `spec`.
#  Kopia ręczna rozjechałaby się przy pierwszej zmianie alertu, więc plik
#  jest generowany, a --check w CI pilnuje, że nikt nie zapomniał go odświeżyć.
# ============================================================================
require 'yaml'

root = File.expand_path('../..', __dir__)
src = File.join(root, 'infra/obs/prometheus/rules/marketplace.yml')
out = File.join(root, 'deploy/monitoring/prometheus-rule.yaml')

rules = YAML.safe_load(File.read(src, encoding: 'UTF-8'))
doc = {
  'apiVersion' => 'monitoring.coreos.com/v1',
  'kind' => 'PrometheusRule',
  'metadata' => { 'name' => 'marketplace' },
  'spec' => { 'groups' => rules.fetch('groups') }
}
header = <<~TXT
  # WYGENEROWANE przez tools/k3s/gen-prometheus-rule.rb — NIE EDYTUJ RĘCZNIE.
  # Źródło: infra/obs/prometheus/rules/marketplace.yml (testy: make obs-rules-test).
TXT
rendered = header + YAML.dump(doc)

if ARGV.include?('--check')
  current = File.exist?(out) ? File.read(out, encoding: 'UTF-8') : ''
  if current == rendered
    puts 'prometheus-rule.yaml aktualny'
  else
    warn 'deploy/monitoring/prometheus-rule.yaml NIEAKTUALNY — uruchom: make k3s-monitoring-rules'
    exit 1
  end
else
  print rendered
end
