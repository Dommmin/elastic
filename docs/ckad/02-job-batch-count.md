# 02. Job z czterema uzupełnieniami

**Domena:** Application Design and Build · **Limit:** 5 min · **Namespace:** `ckad-lab`

## Zadanie

W namespace `ckad-lab` utwórz Job `batch-count` (obraz `busybox:1.37`):

1. Job ma zakończyć się **4 udanymi podami** (`completions`), z czego naraz
   mogą działać **2** (`parallelism`).
2. Każdy pod czeka 3 sekundy, po czym wypisuje linię `batch-count-done`
   i kończy się kodem 0.
3. Niepowodzenia: `backoffLimit` równe 2, a cały Job ma mieć limit czasu
   `activeDeadlineSeconds` (np. 120 s).
4. Pod ma się nie restartować w miejscu (`restartPolicy: Never`).

Sprawdź sam: `kubectl get job batch-count` pokazuje `COMPLETIONS 4/4`.

## Po co to na egzaminie i w życiu

Job to jednorazowa praca wsadowa; na egzaminie prawie zawsze trzeba ustawić
`completions`, `parallelism` i politykę niepowodzeń. Pułapka: Job wymaga
`restartPolicy` `Never` albo `OnFailure` (domyślne `Always` jest odrzucane),
a `kubectl create job` nie ma flag na `completions`/`parallelism`, więc
szkielet z `--dry-run=client -o yaml` i tak edytujesz. W sklepie migracje
bazy to Job: `deploy/k8s/jobs/migrate.yaml`.

## Rozwiązanie

<details>
<summary>Pokaż rozwiązanie</summary>

```bash
kubectl apply -n ckad-lab -f - <<'EOF'
apiVersion: batch/v1
kind: Job
metadata:
  name: batch-count
spec:
  completions: 4
  parallelism: 2
  backoffLimit: 2
  activeDeadlineSeconds: 120
  template:
    spec:
      restartPolicy: Never
      containers:
        - name: count
          image: busybox:1.37
          command: ["sh", "-c", "sleep 3; echo batch-count-done"]
EOF
kubectl wait -n ckad-lab --for=condition=Complete job/batch-count --timeout=120s
kubectl get job batch-count -n ckad-lab
```

Pułapka: `activeDeadlineSeconds` dotyczy całego Joba, nie pojedynczego poda;
po jego przekroczeniu Job dostaje `DeadlineExceeded`, a działające pody są
ubijane.

</details>
