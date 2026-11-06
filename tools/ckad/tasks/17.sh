# shellcheck shell=bash
# 17. Przestarzałe API — sprawdzarka (docs/ckad/17-przestarzale-api.md)

setup() {
  ko -n "${NS}" create deployment legacy-web --image=nginx:1.29 --replicas=2 >/dev/null
  ko -n "${NS}" set resources deployment/legacy-web --requests=cpu=20m,memory=32Mi --limits=cpu=100m,memory=64Mi >/dev/null
}

check() {
  local h="hpa.v2.autoscaling/legacy-web"
  expect "HPA legacy-web istnieje (autoscaling/v2)" "legacy-web" "$(jp "${h}" '{.metadata.name}')"
  expect "HPA celuje w Deployment legacy-web" "Deployment/legacy-web" \
    "$(jp "${h}" '{.spec.scaleTargetRef.kind}/{.spec.scaleTargetRef.name}')"
  expect "HPA: minReplicas 2" "2" "$(jp "${h}" '{.spec.minReplicas}')"
  expect "HPA: maxReplicas 5" "5" "$(jp "${h}" '{.spec.maxReplicas}')"
  expect "HPA: metryka CPU, Utilization 60" "cpu/Utilization/60" \
    "$(jp "${h}" '{.spec.metrics[0].resource.name}/{.spec.metrics[0].resource.target.type}/{.spec.metrics[0].resource.target.averageUtilization}')"
  local p="pdb.policy/legacy-web"
  expect "PDB legacy-web istnieje" "legacy-web" "$(jp "${p}" '{.metadata.name}')"
  expect "PDB: minAvailable 1" "1" "$(jp "${p}" '{.spec.minAvailable}')"
  expect "PDB: selektor app=legacy-web" "legacy-web" "$(jp "${p}" '{.spec.selector.matchLabels.app}')"
  wait_for 90 bash -c "[[ \$(kubectl --kubeconfig '${OPERATOR_KUBECONFIG}' -n '${NS}' get deploy/legacy-web -o jsonpath='{.status.readyReplicas}') == 2 ]]"
  expect "Deployment legacy-web ma 2 gotowe pody" "2" "$(jp deploy/legacy-web '{.status.readyReplicas}')"
}
