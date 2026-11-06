# 05. PVC i Deployment, którego dane przeżywają restart

**Domena:** Application Design and Build · **Limit:** 8 min · **Namespace:** `ckad-lab`

## Zadanie

W namespace `ckad-lab`:

1. Utwórz PersistentVolumeClaim `notes-data`: **100Mi**, `ReadWriteOnce`,
   StorageClass `local-path`.
2. Utwórz Deployment `notes` (1 replika, obraz `busybox:1.37`), który montuje
   claim w `/data` i przy każdym starcie dopisuje linię `start <data>` do
   pliku `/data/starts.log`, po czym zostaje przy życiu (`sleep 3600`).
3. Skasuj pod `notes` i upewnij się, że nowy pod widzi stary wpis: w pliku
   mają być teraz co najmniej 2 linie.

Sprawdź sam: `kubectl exec deploy/notes -- cat /data/starts.log`.

## Po co to na egzaminie i w życiu

Dane w kontenerze znikają z kontenerem; trwałość daje dopiero PVC. Na
egzaminie liczy się poprawny `accessModes`, `storageClassName` i
`claimName`. Pułapka: `local-path` ma tryb `WaitForFirstConsumer`, więc PVC
jest `Pending`, dopóki nie powstanie pod, który go używa, i to nie jest
błąd. W sklepie Postgres dostaje wolumen z `volumeClaimTemplates`
(`deploy/k8s/postgres.yaml`), czyli ten sam mechanizm automatycznie dla
StatefulSetu.

## Rozwiązanie

<details>
<summary>Pokaż rozwiązanie</summary>

```bash
kubectl apply -n ckad-lab -f - <<'EOF'
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: notes-data
spec:
  accessModes: ["ReadWriteOnce"]
  storageClassName: local-path
  resources:
    requests:
      storage: 100Mi
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: notes
spec:
  replicas: 1
  strategy:
    type: Recreate
  selector:
    matchLabels: { app: notes }
  template:
    metadata:
      labels: { app: notes }
    spec:
      volumes:
        - name: data
          persistentVolumeClaim:
            claimName: notes-data
      containers:
        - name: notes
          image: busybox:1.37
          command: ["sh", "-c", "echo \"start $(date)\" >> /data/starts.log; sleep 3600"]
          volumeMounts:
            - { name: data, mountPath: /data }
EOF
kubectl rollout status deployment/notes -n ckad-lab --timeout=90s
kubectl delete pod -n ckad-lab -l app=notes --wait=true
kubectl rollout status deployment/notes -n ckad-lab --timeout=90s
kubectl wait -n ckad-lab --for=condition=Ready pod -l app=notes --timeout=90s
kubectl exec -n ckad-lab deploy/notes -- cat /data/starts.log
kubectl get pvc notes-data -n ckad-lab
```

Pułapka: `strategy: Recreate` jest bezpieczniejsze przy RWO: nowy pod nie
walczy ze starym o ten sam wolumen podczas rolloutu.

</details>
