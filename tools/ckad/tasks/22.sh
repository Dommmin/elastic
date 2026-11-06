# shellcheck shell=bash
# 22. RBAC pod-reader — sprawdzarka (docs/ckad/22-rbac-pod-reader.md)

check() {
  local as="--as=system:serviceaccount:${NS}:viewer"
  expect "SA viewer istnieje" "viewer" "$(jp sa/viewer '{.metadata.name}')"
  expect "Role pod-reader istnieje" "pod-reader" "$(jp role/pod-reader '{.metadata.name}')"
  expect "RoleBinding wskazuje Role pod-reader" "pod-reader" "$(jp rolebinding/pod-reader-binding '{.roleRef.name}')"
  expect "RoleBinding ma podmiot viewer" "viewer" "$(jp rolebinding/pod-reader-binding '{.subjects[0].name}')"
  expect "viewer: list pods" "yes" "$(ko -n "${NS}" auth can-i list pods "${as}" 2>/dev/null)"
  expect "viewer: get pods" "yes" "$(ko -n "${NS}" auth can-i get pods "${as}" 2>/dev/null)"
  expect "viewer: watch pods" "yes" "$(ko -n "${NS}" auth can-i watch pods "${as}" 2>/dev/null)"
  expect "viewer: get pods/log" "yes" "$(ko -n "${NS}" auth can-i get pods/log "${as}" 2>/dev/null)"
  expect "viewer: delete pods" "no" "$(ko -n "${NS}" auth can-i delete pods "${as}" 2>/dev/null)"
  expect "viewer: list secrets" "no" "$(ko -n "${NS}" auth can-i list secrets "${as}" 2>/dev/null)"
}
