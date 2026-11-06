# 01. Sidecar, który czyta logi aplikacji

**Domena:** Application Design and Build · **Limit:** 7 min · **Namespace:** `ckad-lab`

## Zadanie

W namespace `ckad-lab` utwórz pod `log-shipper`:

1. Kontener `app` (obraz `busybox:1.37`) co 2 sekundy dopisuje bieżący czas
   (`date`) do pliku `/var/log/app/app.log`.
2. Kontener `shipper` (obraz `busybox:1.37`) wypisuje na standardowe wyjście
   kolejne linie tego pliku (`tail -F`). Ma to być **natywny sidecar**: startuje
   przed `app` i działa przez cały czas życia poda.
3. Oba kontenery współdzielą wolumen `logs` typu `emptyDir`, zamontowany
   w `/var/log/app`.

Sprawdź sam: `kubectl logs log-shipper -c shipper` pokazuje godziny.

## Po co to na egzaminie i w życiu

Wzorzec sidecar (adapter logów, proxy, agent) to jedno z pytań, które padają
prawie zawsze. Od Kubernetesa 1.29 sidecar to `initContainer` z
`restartPolicy: Always`: startuje przed kontenerami aplikacji, a pod nie czeka
na jego zakończenie. W sklepie tak samo działa eksporter metryk obok Postgresa
(`deploy/k8s/postgres.yaml`), tyle że jako zwykły drugi kontener.

## Rozwiązanie

<details>
<summary>Pokaż rozwiązanie</summary>

Szkielet z `kubectl run`, resztę dopisujesz w YAML-u (wolumen i drugi kontener
nie mają flag w `kubectl run`):

```bash
kubectl run log-shipper --image=busybox:1.37 --dry-run=client -o yaml \
  -- sh -c 'while true; do date >> /var/log/app/app.log; sleep 2; done' > /tmp/01.yaml
```

Gotowy manifest:

```bash
kubectl apply -n ckad-lab -f - <<'EOF'
apiVersion: v1
kind: Pod
metadata:
  name: log-shipper
spec:
  volumes:
    - name: logs
      emptyDir: {}
  initContainers:
    - name: shipper
      image: busybox:1.37
      restartPolicy: Always
      command: ["sh", "-c", "touch /var/log/app/app.log; tail -F /var/log/app/app.log"]
      volumeMounts:
        - { name: logs, mountPath: /var/log/app }
  containers:
    - name: app
      image: busybox:1.37
      command: ["sh", "-c", "while true; do date >> /var/log/app/app.log; sleep 2; done"]
      volumeMounts:
        - { name: logs, mountPath: /var/log/app }
EOF
kubectl wait -n ckad-lab --for=condition=Ready pod/log-shipper --timeout=90s
kubectl logs -n ckad-lab log-shipper -c shipper --tail=3
```

Pułapka: zwykły `initContainer` z `tail -F` nigdy się nie kończy, więc `app`
nigdy by nie wystartował. Różnicę robi właśnie `restartPolicy: Always`.

</details>
