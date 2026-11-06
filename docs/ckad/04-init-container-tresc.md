# 04. Init container przygotowuje treść strony

**Domena:** Application Design and Build · **Limit:** 7 min · **Namespace:** `ckad-lab`

## Zadanie

W namespace `ckad-lab` utwórz Deployment `site` (1 replika) z Service `site`
(port 80):

1. Kontener `nginx` z obrazem `nginx:1.29` serwuje katalog
   `/usr/share/nginx/html`. Nie buduj własnego obrazu.
2. Init container (obraz `busybox:1.37`) zapisuje przed startem nginx plik
   `index.html` z tekstem `Strona z initContainera`.
3. Treść przekazujesz przez wolumen `www` typu `emptyDir`, zamontowany w obu
   kontenerach.

Sprawdź sam: `kubectl run tmp --rm -i --restart=Never --image=busybox:1.37 -- wget -qO- http://site`.

## Po co to na egzaminie i w życiu

Init container wykonuje się do końca przed kontenerami aplikacji: pobiera
konfigurację, czeka na zależność albo, jak tutaj, przygotowuje pliki.
Typowa pułapka to zapomnienie o wolumenie współdzielonym: bez `emptyDir`
init zapisuje do własnego, znikającego systemu plików. W sklepie init
containery czekają na zależności przed startem aplikacji, zobacz
`deploy/k8s/catalog.yaml`.

## Rozwiązanie

<details>
<summary>Pokaż rozwiązanie</summary>

```bash
kubectl apply -n ckad-lab -f - <<'EOF'
apiVersion: apps/v1
kind: Deployment
metadata:
  name: site
spec:
  replicas: 1
  selector:
    matchLabels: { app: site }
  template:
    metadata:
      labels: { app: site }
    spec:
      volumes:
        - name: www
          emptyDir: {}
      initContainers:
        - name: prepare
          image: busybox:1.37
          command: ["sh", "-c", "echo 'Strona z initContainera' > /www/index.html"]
          volumeMounts:
            - { name: www, mountPath: /www }
      containers:
        - name: nginx
          image: nginx:1.29
          ports:
            - containerPort: 80
          volumeMounts:
            - { name: www, mountPath: /usr/share/nginx/html }
EOF
kubectl expose deployment site -n ckad-lab --port=80
kubectl rollout status deployment/site -n ckad-lab --timeout=90s
kubectl run tmp-04 -n ckad-lab --rm -i --restart=Never --image=busybox:1.37 -- wget -qO- -T 5 http://site
```

Pułapka: init i nginx montują ten sam wolumen pod **różnymi** ścieżkami
(`/www` i `/usr/share/nginx/html`); ważne jest, że to ten sam `name`.

</details>
