# 08. Canary na dwóch Deploymentach

**Domena:** Application Deployment · **Limit:** 8 min · **Namespace:** `ckad-lab`

## Zadanie

W namespace `ckad-lab` wdróż aplikację w dwóch wersjach za jednym Service:

1. Deployment `shop-v1`: 3 repliki, obraz `busybox:1.37`, serwuje przez `httpd`
   na porcie 8080 stronę z tekstem `shop v1`.
2. Deployment `shop-v2`: 1 replika, ten sam obraz, strona z tekstem `shop v2`.
3. Pody obu Deploymentów mają wspólną etykietę `app=shop` (oraz `track=stable`
   w v1 i `track=canary` w v2). Selektory samych Deploymentów muszą się różnić,
   żeby jeden nie przejmował podów drugiego.
4. Service `shop` (port 80 → 8080) wybiera pody po wspólnej etykiecie `app=shop`,
   więc ma 4 endpointy: 3 z v1 i 1 z v2.

Wskazówka: strona to `echo 'shop v1' > /www/index.html` i
`httpd -f -p 8080 -h /www`.

Sprawdź sam: `kubectl get endpoints shop` ma 4 adresy, a wielokrotne
`wget -qO- http://shop` z poda pokazuje obie wersje.

## Po co to na egzaminie i w życiu

Canary bez service mesha to dwa Deploymenty i Service wybierający po etykiecie
wspólnej dla obu. Udział ruchu wynika z liczby podów (tu 1 z 4 = ok. 25%), nie
z procentu. Pułapka: wspólna etykieta musi być w `template.metadata.labels`
(nie tylko w Deployment), a **selektor** Deploymentu nie może być wspólny —
inaczej oba walczą o te same pody. Dokładnie tak działa
`tools/k3s/canary.sh` w tym projekcie: `catalog-app-canary` ma `track: canary`
i tę samą etykietę `app: catalog-app` co stabilny Deployment z
`deploy/k8s/catalog.yaml`.

## Rozwiązanie

<details>
<summary>Pokaż rozwiązanie</summary>

```bash
kubectl apply -n ckad-lab -f - <<'EOF2'
apiVersion: apps/v1
kind: Deployment
metadata:
  name: shop-v1
  labels: { app: shop, track: stable }
spec:
  replicas: 3
  selector:
    matchLabels: { app: shop, track: stable }
  template:
    metadata:
      labels: { app: shop, track: stable }
    spec:
      containers:
        - name: web
          image: busybox:1.37
          command: ["sh", "-c", "mkdir -p /www; echo 'shop v1' > /www/index.html; exec httpd -f -p 8080 -h /www"]
          ports:
            - containerPort: 8080
---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: shop-v2
  labels: { app: shop, track: canary }
spec:
  replicas: 1
  selector:
    matchLabels: { app: shop, track: canary }
  template:
    metadata:
      labels: { app: shop, track: canary }
    spec:
      containers:
        - name: web
          image: busybox:1.37
          command: ["sh", "-c", "mkdir -p /www; echo 'shop v2' > /www/index.html; exec httpd -f -p 8080 -h /www"]
          ports:
            - containerPort: 8080
EOF2
kubectl create service clusterip shop -n ckad-lab --tcp=80:8080
kubectl rollout status deployment/shop-v1 -n ckad-lab --timeout=120s
kubectl rollout status deployment/shop-v2 -n ckad-lab --timeout=120s
kubectl get endpoints shop -n ckad-lab
```

`kubectl create service clusterip shop` ustawia selektor `app=shop` (nazwę
serwisu), więc wystarczy jedna komenda. `kubectl expose deploy shop-v1` by tu nie
zadziałało: przejęłoby selektor v1 (`track=stable`) i canary nie dostałby ruchu.

</details>
