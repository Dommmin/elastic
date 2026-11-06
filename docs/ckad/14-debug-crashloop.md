# 14. Debug: CrashLoopBackOff

**Domena:** Application Observability and Maintenance · **Limit:** 7 min · **Namespace:** `ckad-lab`

## Zadanie

W namespace `ckad-lab` działa Deployment `broken-api`, którego pod wpada
w `CrashLoopBackOff`. W namespace jest też ConfigMap `broken-api-config`.

1. Znajdź przyczynę awarii (`kubectl describe`, `kubectl logs --previous`).
2. Napraw Deployment bez zmiany polecenia kontenera: aplikacja ma dostać
   brakującą zmienną **z ConfigMapy**, nie wpisaną na sztywno.
3. Po naprawie pod jest `Ready` i ma 0 restartów.

Sprawdź sam: `kubectl get pods -l app=broken-api`.

## Po co to na egzaminie i w życiu

Debugowanie to osobna domena egzaminu, a CrashLoopBackOff to jej klasyk.
Kolejność: `get pods` (status), `describe` (zdarzenia, kod wyjścia), `logs
--previous` (log poprzedniego, już martwego kontenera). Pułapka: zwykłe `logs`
pokazuje często pusty, świeżo startujący kontener. Wartość wstrzykuje się ze
źródła prawdy (ConfigMap), tak jak `catalog-app` bierze konfigurację z
`deploy/k8s/config.env` przez `envFrom` (`deploy/k8s/catalog.yaml`).

## Rozwiązanie

<details>
<summary>Pokaż rozwiązanie</summary>

```bash
kubectl logs -n ckad-lab deploy/broken-api --previous --tail=5 || true
kubectl get configmap -n ckad-lab broken-api-config -o yaml
kubectl set env -n ckad-lab deployment/broken-api --from=configmap/broken-api-config
kubectl rollout status -n ckad-lab deployment/broken-api --timeout=90s
kubectl get pods -n ckad-lab -l app=broken-api
```

`kubectl set env --from` dodaje wszystkie klucze ConfigMapy jako zmienne
(`envFrom`-owy skrót) i wywołuje nowy rollout, więc pody są świeże i mają
0 restartów.

</details>
