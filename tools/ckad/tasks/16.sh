# shellcheck shell=bash
# 16. kubectl top — sprawdzarka (docs/ckad/16-kubectl-top.md)

setup() {
  rm -f /tmp/ckad-16.txt
  # Losowy „palacz” — nie da się zgadnąć po nazwie. Pozostałe pody śpią.
  local names=(cpu-a cpu-b cpu-c) burner n cmd
  burner="${names[$((RANDOM % 3))]}"
  for n in "${names[@]}"; do
    if [[ "${n}" == "${burner}" ]]; then cmd='while :; do :; done'; else cmd='sleep 3600'; fi
    ko -n "${NS}" apply -f - >/dev/null <<YAML
apiVersion: v1
kind: Pod
metadata:
  name: ${n}
  labels: { task: cpu }
spec:
  containers:
    - name: main
      image: busybox:1.37
      command: ["sh", "-c", "${cmd}"]
      resources:
        requests: { cpu: 10m, memory: 16Mi }
        limits: { cpu: 150m, memory: 64Mi }
YAML
  done
}

top_ready() {
  local out
  out="$(ko -n "${NS}" top pod -l task=cpu --no-headers 2>/dev/null)" || return 1
  [[ "$(echo "${out}" | wc -l)" -eq 3 ]] && echo "${out}" | awk '$2+0 >= 50 {f=1} END{exit !f}'
}

check() {
  wait_for 150 top_ready
  local actual given=""
  actual="$(ko -n "${NS}" top pod -l task=cpu --no-headers --sort-by=cpu 2>/dev/null | head -1 | awk '{print $1}')"
  [[ -f /tmp/ckad-16.txt ]] && given="$(tr -d '[:space:]' < /tmp/ckad-16.txt)"
  expect_match "metrics-server zwraca dane dla podów labu" '^cpu-[abc]$' "${actual}"
  expect "/tmp/ckad-16.txt wskazuje pod z największym CPU" "${actual}" "${given}"
}
