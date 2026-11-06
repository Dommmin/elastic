# 10. Helm: instalacja i upgrade lokalnego chartu

**Domena:** Application Deployment · **Limit:** 8 min · **Namespace:** `ckad-lab`

## Zadanie

W repozytorium jest lokalny chart `tools/ckad/fixtures/10/webapp` (Deployment +
Service; wartości `replicaCount`, `image.tag`, `service.port`).

1. Zainstaluj go w namespace `ckad-lab` jako wydanie `webapp`, nadpisując
   wartości: `replicaCount=2`, `image.tag=1.28`, `service.port=8080`.
   Poczekaj na gotowość wdrożenia.
2. Wykonaj `helm upgrade` wydania `webapp`, zmieniając tylko `replicaCount` na
   `3`. Pozostałe nadpisane wartości mają zostać (obraz `nginx:1.28`, port
   Service `8080`).

Sprawdź sam: `helm list -n ckad-lab` (status `deployed`, rewizja 2),
`helm get values webapp -n ckad-lab`.

## Po co to na egzaminie i w życiu

Na CKAD Helm to instalacja, upgrade, rollback i odczyt wartości (`helm list`,
`helm get values`, `helm history`). Pułapka numer jeden: `helm upgrade` bez
`--reuse-values` (albo bez ponownego podania `--set`) wraca do wartości
domyślnych chartu i po cichu cofa nadpisania z instalacji. Druga: zapomniany
`-n`, przez co wydanie ląduje w `default`. W projekcie Helm służy do
zależności klastra (ingress, monitoring: `tools/k3s/install-ingress.sh`,
`tools/k3s/install-monitoring.sh`), a aplikację wdraża Kustomize
(`deploy/k8s/kustomization.yaml`).

## Rozwiązanie

<details>
<summary>Pokaż rozwiązanie</summary>

```bash
helm install webapp tools/ckad/fixtures/10/webapp -n ckad-lab \
  --set replicaCount=2 --set image.tag=1.28 --set service.port=8080 \
  --wait --timeout 120s
helm upgrade webapp tools/ckad/fixtures/10/webapp -n ckad-lab \
  --reuse-values --set replicaCount=3 --wait --timeout 120s
helm list -n ckad-lab
helm get values webapp -n ckad-lab
kubectl rollout status deployment/webapp -n ckad-lab --timeout=120s
```

`--reuse-values` zachowuje to, co nadpisano przy instalacji. Alternatywa:
ponownie podać wszystkie trzy `--set` (albo `--reset-then-reuse-values`).

</details>
