# encoding: utf-8
# ============================================================================
#  canary-render.rb — Deployment catalog-app-canary z wyrenderowanych manifestów
#  (ETAP G2). Używane przez tools/k3s/canary.sh i testowane w ckad-check.rb.
#
#    kubectl -n marketplace get deploy catalog-app -o yaml | ruby tools/k3s/canary-render.rb <SHA>
#
#  Wejściem jest ŻYWY Deployment, nie render repo: deploy.sh renderuje z
#  podmienionym SENTRY_RELEASE, więc nazwa ConfigMapy (z hashem treści) w repo
#  różni się od tej na klastrze — canary z renderu repo wskazywałby nieistniejącą.
#
#  Dlaczego generowany, a nie plik w repo: canary to KOPIA catalog-app z innym
#  obrazem. Osobny plik YAML trzeba by trzymać w zgodzie z catalog.yaml ręcznie
#  (~150 linii env, sond, securityContext) — przy pierwszej zapomnianej zmiennej
#  canary pokazałby błąd, którego stabilna wersja nie ma, i porównanie byłoby
#  bez sensu. Kopia z tego samego renderu różni się tylko tym, czym ma.
# ============================================================================
require "yaml"

module CanaryRender
  module_function

  def containers(dep)
    spec = dep.dig("spec", "template", "spec")
    (spec["initContainers"] || []) + (spec["containers"] || [])
  end

  def canary_from(stable, tag)
    d = Marshal.load(Marshal.dump(stable)) # głęboka kopia
    d["metadata"] = {
      "name" => "catalog-app-canary",
      "namespace" => stable.dig("metadata", "namespace") || "marketplace",
      "labels" => (stable.dig("metadata", "labels") || {}).merge(
        # NIE `app`: deploy.sh nakłada aplikacje selektorem component=app z pliku
        # renderu, w którym canary nie istnieje — i usuwa canary jawnie.
        "app.kubernetes.io/component" => "canary",
      ),
    }
    d["spec"]["replicas"] = 1
    # Selektor i etykiety: ten sam `app` (Service catalog-app wybiera po nim),
    # inny `track` — selektory Deploymentów są rozłączne.
    d["spec"]["selector"] = { "matchLabels" => { "app" => "catalog-app", "track" => "canary" } }
    d["spec"]["template"]["metadata"]["labels"] = d["spec"]["template"]["metadata"]["labels"].merge("track" => "canary")
    containers(d).each { |c| c["image"] = c["image"].sub(/:[^:@\/]+\z/, ":#{tag}") }
    # Release w GlitchTipie = wersja canary (ConfigMap ma SENTRY_RELEASE stabilnej):
    # wyjątki z canary muszą dać się odróżnić od wyjątków stabilnej wersji.
    d["spec"]["template"]["spec"]["containers"].each do |c|
      env = (c["env"] ||= []).reject { |e| e["name"] == "SENTRY_RELEASE" }
      c["env"] = env + [{ "name" => "SENTRY_RELEASE", "value" => tag }]
    end
    d.delete("status")
    d
  end
end

if $PROGRAM_NAME == __FILE__
  tag = ARGV.fetch(0) { abort "użycie: kubectl kustomize deploy/k8s | ruby #{__FILE__} <SHA>" }
  abort "tag ma być pełnym SHA (40 znaków hex)" unless tag.match?(/\A[0-9a-f]{40}\z/)
  stable = YAML.load_stream(STDIN.read).compact.find { |x| x["kind"] == "Deployment" && x.dig("metadata", "name") == "catalog-app" }
  abort "brak Deploymentu catalog-app w renderze" unless stable
  puts CanaryRender.canary_from(stable, tag).to_yaml
end
