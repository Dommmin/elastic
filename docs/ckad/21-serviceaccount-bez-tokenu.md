# 21. ServiceAccount bez tokenu API

**Domena:** Application Environment, Configuration and Security · **Limit:** 5 min · **Namespace:** `ckad-lab`

## Zadanie

W namespace `ckad-lab`:

1. Utwórz ServiceAccount `reporter` z `automountServiceAccountToken: false`.
2. Utwórz pod `reporter-pod` (obraz `busybox:1.37`, polecenie `sleep 3600`),
   który używa tego ServiceAccounta. Token API **nie** może być w nim
   zamontowany.

Sprawdź sam: `kubectl exec reporter-pod -- ls /var/run/secrets/kubernetes.io/serviceaccount`
ma zakończyć się błędem „No such file or directory”.

## Po co to na egzaminie i w życiu

Domyślnie każdy pod dostaje token konta `default` i może rozmawiać z API
Kubernetesa; większość aplikacji tego nie potrzebuje, a wykradziony token to
gotowa ścieżka eskalacji. Pułapka: ustawienie na **poziomie ServiceAccounta**
działa dla wszystkich jego podów, ale `automountServiceAccountToken` w spec
poda ma pierwszeństwo (pod może je włączyć z powrotem). W sklepie każdy
workload ma własne konto z wyłączonym tokenem
(`deploy/k8s/serviceaccounts.yaml`, `automountServiceAccountToken: false`).

## Rozwiązanie

<details>
<summary>Pokaż rozwiązanie</summary>

```bash
kubectl create serviceaccount reporter -n ckad-lab
kubectl patch serviceaccount reporter -n ckad-lab \
  -p '{"automountServiceAccountToken": false}'
kubectl run reporter-pod -n ckad-lab --image=busybox:1.37 \
  --overrides='{"spec":{"serviceAccountName":"reporter"}}' \
  -- sleep 3600
kubectl wait -n ckad-lab --for=condition=Ready pod/reporter-pod --timeout=90s
kubectl exec -n ckad-lab reporter-pod -- ls /var/run/secrets/kubernetes.io/serviceaccount || echo "brak tokenu - dobrze"
```

Pułapka: `--serviceaccount` w `kubectl run` już nie istnieje; używa się
`--overrides` albo YAML-a z `serviceAccountName`.

</details>
