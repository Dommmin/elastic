# 19. Secret jako env i jako plik

**Domena:** Application Environment, Configuration and Security · **Limit:** 7 min · **Namespace:** `ckad-lab`

## Zadanie

W namespace `ckad-lab`:

1. Utwórz Secret `db-credentials` (typ generic) z literałów `username=app`
   i `password=S3cr3t-Pass`.
2. Utwórz pod `secret-demo` (obraz `busybox:1.37`, polecenie `sleep 3600`):
   - zmienna `DB_PASSWORD` pochodzi z klucza `password` Secretu,
   - cały Secret jest zamontowany jako wolumen w `/etc/db`, a pliki mają tryb
     `0400` (`defaultMode`).

Sprawdź sam: `kubectl exec secret-demo -- sh -c 'echo $DB_PASSWORD; stat -L -c "%a" /etc/db/password'`.

## Po co to na egzaminie i w życiu

Secret to ten sam mechanizm co ConfigMap, z dwiema różnicami: dane są w base64
(to **nie** szyfrowanie) i da się ustawić tryb plików. Pułapka egzaminowa:
`defaultMode` w YAML jest liczbą, a `0400` w JSON-ie to `256` — wpisz w YAML-u
`0400` (ósemkowo) albo `256`. Wolumen jest bezpieczniejszy niż env (env widać
w `kubectl describe`, w zrzutach awaryjnych i dziedziczą go procesy potomne).
W sklepie hasła i klucze są w Secrecie `marketplace-secrets`, czytane przez
`secretKeyRef` (`deploy/k8s/search-consumer.yaml`).

## Rozwiązanie

<details>
<summary>Pokaż rozwiązanie</summary>

```bash
kubectl create secret generic db-credentials -n ckad-lab \
  --from-literal=username=app --from-literal=password=S3cr3t-Pass
```

```bash
kubectl apply -n ckad-lab -f - <<'EOF2'
apiVersion: v1
kind: Pod
metadata:
  name: secret-demo
spec:
  containers:
    - name: app
      image: busybox:1.37
      command: ["sleep", "3600"]
      env:
        - name: DB_PASSWORD
          valueFrom:
            secretKeyRef: { name: db-credentials, key: password }
      volumeMounts:
        - { name: creds, mountPath: /etc/db, readOnly: true }
  volumes:
    - name: creds
      secret:
        secretName: db-credentials
        defaultMode: 0400
EOF2
kubectl wait -n ckad-lab --for=condition=Ready pod/secret-demo --timeout=90s
kubectl exec -n ckad-lab secret-demo -- sh -c 'echo $DB_PASSWORD; stat -L -c "%a" /etc/db/password'
```

Pułapka: `stat` bez `-L` pokazuje dowiązanie symboliczne (pliki w wolumenie
Secretu to symlinki do `..data/`), nie tryb samego pliku.

</details>
