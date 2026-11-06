# 03. CronJob co 5 minut i ręczne uruchomienie

**Domena:** Application Design and Build · **Limit:** 6 min · **Namespace:** `ckad-lab`

## Zadanie

W namespace `ckad-lab`:

1. Utwórz CronJob `report` (obraz `busybox:1.37`, polecenie wypisujące
   `report-ok`), uruchamiany **co 5 minut**.
2. Ustaw: `concurrencyPolicy: Forbid`, `successfulJobsHistoryLimit: 2`,
   `failedJobsHistoryLimit: 1` i `startingDeadlineSeconds: 60`.
3. Nie czekaj na harmonogram: uruchom ręcznie Job `report-manual` na podstawie
   tego CronJoba i poczekaj, aż zakończy się sukcesem.

Sprawdź sam: `kubectl get cronjob,job`.

## Po co to na egzaminie i w życiu

CronJob to najczęstsze „zadanie z flagami”: harmonogram plus polityki.
`Forbid` pomija uruchomienie, gdy poprzednie jeszcze trwa (`Allow` je
nakłada, `Replace` zastępuje), a `startingDeadlineSeconds` mówi, jak późno
spóźnione uruchomienie jest jeszcze ważne. `kubectl create job --from=cronjob/…`
to standardowy sposób na test CronJoba bez czekania. W sklepie kopie zapasowe
Postgresa działają dokładnie tak: `deploy/k8s/backup.yaml`.

## Rozwiązanie

<details>
<summary>Pokaż rozwiązanie</summary>

```bash
kubectl create cronjob report -n ckad-lab --image=busybox:1.37 \
  --schedule='*/5 * * * *' -- sh -c 'echo report-ok'
kubectl patch cronjob report -n ckad-lab --type=merge -p \
  '{"spec":{"concurrencyPolicy":"Forbid","successfulJobsHistoryLimit":2,"failedJobsHistoryLimit":1,"startingDeadlineSeconds":60}}'
kubectl create job report-manual -n ckad-lab --from=cronjob/report
kubectl wait -n ckad-lab --for=condition=Complete job/report-manual --timeout=90s
kubectl get cronjob report -n ckad-lab
```

Pułapka: `kubectl create cronjob` nie ma flag na polityki, więc używasz
`kubectl patch` albo `kubectl edit`. Ręczny Job dostaje `ownerReference` do
CronJoba, więc zniknie razem z nim.

</details>
