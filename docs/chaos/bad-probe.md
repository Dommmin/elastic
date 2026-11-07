# Rollout, który nigdy się nie kończy

**Oczekiwany alert:** `KubePodNotReady` (po 15 min) · **Limit:** 15 min

## Objaw

Ktoś „tylko poprawił konfigurację” Deploymentu `search-metrics` (eksporter metryk
wyszukiwarki dla Prometheusa). Od tej chwili `kubectl rollout status` nie kończy się,
w `kubectl get pods` widać dwa pody `search-metrics`: stary `1/1` i nowy, który od
minut wisi `0/1` w stanie `Running`, bez restartów. Metryki w Grafanie nadal płyną,
sklep działa. Alert przyjdzie dopiero po kwadransie, więc na produkcji taką awarię
łatwo przeoczyć aż do następnego wdrożenia, które utknie za nią w kolejce.

## Zacznij od

- Alertmanager/Grafana: `KubePodNotReady` (pojawi się po 15 min; nie czekaj na niego).
- Pierwsze polecenia:

```bash
kubectl -n marketplace rollout status deploy/search-metrics --timeout=20s
kubectl -n marketplace get pods -l app=search-metrics
kubectl -n marketplace get rs -l app=search-metrics
```

## Pytania prowadzące

1. Dlaczego stary pod nadal działa, skoro Deployment ma 1 replikę? Co mówi o tym
   `strategy.rollingUpdate` i jak zaokrągla się 25% z jednej repliki?
2. Pod jest `Running` i ma 0 restartów, a mimo to `0/1`. Która sonda decyduje
   o gotowości, a która o restarcie? Co pokazuje `kubectl describe pod` w `Events`?
3. Czym różni się szablon poda nowego ReplicaSetu od starego?
   (`kubectl rollout history deploy/search-metrics --revision=N`)
4. Co odpowiada aplikacja na ścieżce, którą sprawdza sonda? Sprawdź z wnętrza poda
   (`kubectl exec ... -- curl -s -o /dev/null -w '%{http_code}' localhost:80/...`).
5. Dlaczego `TargetDown` nie płonie, choć nowy pod nie jest gotowy?

## Po co to w życiu

Sonda gotowości `search-metrics` żyje w
[`deploy/k8s/search-consumer.yaml`](../../deploy/k8s/search-consumer.yaml)
(`readinessProbe.httpGet.path: /metrics`). Readiness decyduje, czy pod trafia do
Endpoints usługi (a więc i do celów Prometheusa przez ServiceMonitor), liveness —
czy kubelet go restartuje. Zła readiness nie zabija poda, tylko zatrzymuje rollout:
przy `maxUnavailable: 0` Kubernetes nie zdejmie starego poda, póki nowy nie jest
gotowy. To bezpiecznik, który ratuje produkcję przed złym obrazem albo złą sondą,
ale tylko wtedy, gdy ktoś zauważy, że rollout stoi: CI powinien kończyć wdrożenie
`kubectl rollout status --timeout=...` z błędem, a nie „apply i do domu”.

## Rozwiązanie

<details>
<summary>Pokaż rozwiązanie</summary>

**Przyczyna:** readiness kontenera `metrics` wskazuje `/healthz-old`, na które
aplikacja (Symfony za FrankenPHP) odpowiada `404`. Nowy pod nigdy nie jest gotowy,
a przy 1 replice i `maxUnavailable` zaokrąglonym do 0 stary pod zostaje i serwuje.
`TargetDown` nie płonie, bo niegotowy pod w ogóle nie trafia do Endpoints, więc
Prometheus nie ma dla niego serii `up` (nie ma `up == 0`).

**Naprawa** (jedna z dwóch):

```bash
# przywróć poprzednią rewizję (szablon sprzed zmiany)
kubectl -n marketplace rollout undo deploy/search-metrics
# albo popraw ścieżkę sondy na tę, którą aplikacja zna
kubectl -n marketplace patch deploy/search-metrics --type json \
  -p '[{"op":"replace","path":"/spec/template/spec/containers/0/readinessProbe/httpGet/path","value":"/metrics"}]'
kubectl -n marketplace rollout status deploy/search-metrics
make chaos-check s=bad-probe
```

**Jak temu zapobiec:** sonda wskazuje endpoint, który aplikacja ma w kodzie
i w teście (tu `MetricsController`); każde wdrożenie kończy się
`kubectl rollout status --timeout` z kodem błędu (robi to `tools/k3s/deploy.sh`);
alert `KubeDeploymentRolloutStuck` (po `progressDeadlineSeconds` + 15 min) łapie
rollout, który utknął, nawet gdy nikt nie patrzy.

</details>
