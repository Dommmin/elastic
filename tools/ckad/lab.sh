#!/usr/bin/env bash
#  tools/ckad/lab.sh up|down|reset — laboratorium CKAD na k3s (ETAP G3).
#
#    make ckad-lab-up              # namespace'y ckad-lab i ckad-lab-b + kubeconfig ucznia
#    make ckad-lab-down            # usuwa oba namespace'y (i wszystko w nich)
#    LAB=ckad-d1 make ckad-lab-up  # osobny lab (równoległe testy, drugi uczeń)
#
#  Dlaczego osobny kubeconfig ucznia, a nie ćwiczenia na kubeconfigu operatora:
#  operator ma cluster-admin. Jedna literówka w `kubectl delete` w złym kontekście
#  kasuje sklep. Uczeń dostaje ServiceAccount z rolą `admin` tylko w dwóch
#  namespace'ach labu — to dokładnie tyle, ile wymaga program CKAD (wszystko jest
#  namespaced), a reszta klastra jest dla niego tylko do odczytu albo niewidoczna.
#
#  Strażnicy zasobów (ResourceQuota + LimitRange): ten sam węzeł obsługuje
#  produkcję. Zapomniane limity albo `replicas: 50` nie mogą zjeść jej pamięci.
source "$(dirname "$0")/lib.sh"

cmd="${1:-}"

manifests() {
  cat <<YAML
apiVersion: v1
kind: Namespace
metadata:
  name: ${NS}
  labels:
    ${INFRA_LABEL}: "true"
    pod-security.kubernetes.io/enforce: baseline
    pod-security.kubernetes.io/warn: restricted
---
apiVersion: v1
kind: Namespace
metadata:
  name: ${NS_B}
  labels:
    ${INFRA_LABEL}: "true"
    pod-security.kubernetes.io/enforce: baseline
    pod-security.kubernetes.io/warn: restricted
---
apiVersion: v1
kind: ServiceAccount
metadata:
  name: student
  namespace: ${NS}
  labels: { ${INFRA_LABEL}: "true" }
---
# Token o nieograniczonym czasie, żeby kubeconfig ucznia nie wygasał po godzinie
# (kubectl create token). Znika razem z namespace'em przy ckad-lab-down.
apiVersion: v1
kind: Secret
metadata:
  name: student-token
  namespace: ${NS}
  labels: { ${INFRA_LABEL}: "true" }
  annotations:
    kubernetes.io/service-account.name: student
type: kubernetes.io/service-account-token
YAML
  local ns
  for ns in "${NS}" "${NS_B}"; do
    cat <<YAML
---
apiVersion: rbac.authorization.k8s.io/v1
kind: RoleBinding
metadata:
  name: student-admin
  namespace: ${ns}
  labels: { ${INFRA_LABEL}: "true" }
roleRef: { apiGroup: rbac.authorization.k8s.io, kind: ClusterRole, name: admin }
subjects:
  - { kind: ServiceAccount, name: student, namespace: ${NS} }
---
apiVersion: v1
kind: ResourceQuota
metadata:
  name: lab-guard
  namespace: ${ns}
  labels: { ${INFRA_LABEL}: "true" }
spec:
  hard:
    requests.cpu: "1"
    requests.memory: 1536Mi
    limits.cpu: "2"
    limits.memory: 2Gi
    pods: "20"
    persistentvolumeclaims: "4"
    requests.storage: 2Gi
    services.loadbalancers: "0"
    services.nodeports: "2"
---
apiVersion: v1
kind: LimitRange
metadata:
  name: lab-guard
  namespace: ${ns}
  labels: { ${INFRA_LABEL}: "true" }
spec:
  limits:
    - type: Container
      defaultRequest: { cpu: 50m, memory: 64Mi }
      default: { cpu: 200m, memory: 256Mi }
      max: { cpu: "1", memory: 1Gi }
YAML
  done
  # Zadania o kwotach: rola `admin` widzi ResourceQuota/LimitRange tylko do odczytu.
  # Dodatkowe uprawnienie wyłącznie w $NS_B — strażnik w $NS zostaje nietykalny.
  cat <<YAML
---
apiVersion: rbac.authorization.k8s.io/v1
kind: Role
metadata:
  name: quota-editor
  namespace: ${NS_B}
  labels: { ${INFRA_LABEL}: "true" }
rules:
  - apiGroups: [""]
    resources: [resourcequotas, limitranges]
    verbs: [get, list, watch, create, update, patch, delete]
---
apiVersion: rbac.authorization.k8s.io/v1
kind: RoleBinding
metadata:
  name: student-quota-editor
  namespace: ${NS_B}
  labels: { ${INFRA_LABEL}: "true" }
roleRef: { apiGroup: rbac.authorization.k8s.io, kind: Role, name: quota-editor }
subjects:
  - { kind: ServiceAccount, name: student, namespace: ${NS} }
YAML
}

write_kubeconfig() {
  local token="" server ca
  for _ in $(seq 1 15); do
    token="$(ko -n "${NS}" get secret student-token -o jsonpath='{.data.token}' 2>/dev/null | base64 -d 2>/dev/null)"
    [[ -n "${token}" ]] && break
    sleep 1
  done
  [[ -n "${token}" ]] || { echo "Token ucznia się nie pojawił." >&2; exit 1; }
  server="$(ko config view --minify --raw -o jsonpath='{.clusters[0].cluster.server}')"
  ca="$(ko config view --minify --raw -o jsonpath='{.clusters[0].cluster.certificate-authority-data}')"
  umask 077
  cat > "${STUDENT_KUBECONFIG}" <<YAML
apiVersion: v1
kind: Config
clusters:
  - name: k3s
    cluster: { server: ${server}, certificate-authority-data: ${ca} }
users:
  - name: student
    user: { token: ${token} }
contexts:
  - name: ${LAB}
    context: { cluster: k3s, user: student, namespace: ${NS} }
current-context: ${LAB}
YAML
}

case "${cmd}" in
  up)
    manifests | ko apply -f - >/dev/null
    write_kubeconfig
    echo "Lab ${LAB} gotowy: namespace'y ${NS}, ${NS_B}."
    echo "  export KUBECONFIG=${STUDENT_KUBECONFIG}"
    echo "  kubectl auth can-i delete pods -n marketplace   # -> no"
    ;;
  down)
    ko delete namespace "${NS}" "${NS_B}" --ignore-not-found --wait=true --timeout=180s
    rm -f "${STUDENT_KUBECONFIG}"
    echo "Lab ${LAB} usunięty."
    ;;
  reset)
    require_lab
    lab_reset
    echo "Lab ${LAB} wyczyszczony."
    ;;
  *)
    echo "Użycie: $0 up|down|reset   (LAB=${LAB})" >&2
    exit 2
    ;;
esac
