# 12. Wstrzymany rollout: kilka zmian, jedna rewizja

**Domena:** Application Deployment · **Limit:** 6 min · **Namespace:** `ckad-lab`

## Zadanie

W namespace `ckad-lab` działa Deployment `batch-api` (2 repliki, `nginx:1.28`).
Wprowadź na nim trzy zmiany tak, żeby powstała **dokładnie jedna** nowa
rewizja (jeden nowy ReplicaSet), a nie trzy:

1. obraz kontenera: `nginx:1.29`,
2. zasoby kontenera: requests `cpu=50m`, `memory=64Mi`, limits `cpu=100m`,
   `memory=128Mi`,
3. zmienna środowiskowa `LOG_LEVEL=debug`.

Na koniec wznów rollout i poczekaj na jego zakończenie.

Sprawdź sam: `kubectl rollout history deploy/batch-api` pokazuje rewizje 1 i 2.

## Po co to na egzaminie i w życiu

Każda zmiana szablonu poda uruchamia osobny rollout. `kubectl rollout pause`
pozwala zebrać kilka poleceń `set` w jedną rewizję i jeden rolling update, co
oszczędza restarty i porządkuje historię. Pułapka: w czasie pauzy `rollout
status` i `undo` nie działają (Deployment nie reaguje na zmiany), a zapomniany
`resume` zostawia zmiany bez wdrożenia. W sklepie podobnej dyscypliny wymaga
`deploy/k8s/catalog.yaml`, gdzie jedna zmiana szablonu to jeden rollout, który
`tools/k3s/deploy.sh` obserwuje przez `rollout status`.

## Rozwiązanie

<details>
<summary>Pokaż rozwiązanie</summary>

```bash
kubectl rollout pause deployment/batch-api -n ckad-lab
kubectl set image deployment/batch-api -n ckad-lab nginx=nginx:1.29
kubectl set resources deployment/batch-api -n ckad-lab \
  --requests=cpu=50m,memory=64Mi --limits=cpu=100m,memory=128Mi
kubectl set env deployment/batch-api -n ckad-lab LOG_LEVEL=debug
kubectl rollout resume deployment/batch-api -n ckad-lab
kubectl rollout status deployment/batch-api -n ckad-lab --timeout=120s
kubectl rollout history deployment/batch-api -n ckad-lab
```

W ostatniej historii są tylko rewizje 1 i 2.

</details>
