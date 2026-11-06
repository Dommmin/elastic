# 09. Blue/green przełączeniem selektora

**Domena:** Application Deployment · **Limit:** 6 min · **Namespace:** `ckad-lab`

## Zadanie

W namespace `ckad-lab` działa już Deployment `app-blue` (2 repliki, pody z
etykietami `app=app`, `version=blue`, strona z tekstem `blue`) oraz Service
`app` (port 80 → 8080), który wybiera pody po `app=app,version=blue`.

1. Utwórz Deployment `app-green`: 2 repliki, `busybox:1.37`, `httpd` na porcie
   8080 ze stroną `green`, pody z etykietami `app=app`, `version=green`.
2. Poczekaj, aż `app-green` będzie gotowy, **dopiero potem** przełącz Service
   `app` na wersję green zmianą selektora (`version=green`).
3. Nie usuwaj `app-blue` (to natychmiastowy rollback).

Sprawdź sam: `kubectl get endpoints app` zawiera wyłącznie adresy podów
`app-green-*`.

## Po co to na egzaminie i w życiu

Blue/green to dwa pełne środowiska i jeden przełącznik: selektor Service.
Przełączenie jest atomowe (cały ruch naraz) i odwracalne tą samą komendą, za
cenę podwójnych zasobów. Pułapka: przełączenie przed gotowością zielonej
wersji daje chwilę bez endpointów, a zapomniana etykieta `version` w szablonie
poda sprawia, że Service nie znajdzie nic. Ten sam mechanizm (selektor Service po
etykiecie `app`) wykorzystuje canary w `tools/k3s/canary.sh`; w
`deploy/k8s/catalog.yaml` selektory trzymają Service `catalog-app` przy
Deploymentach.

## Rozwiązanie

<details>
<summary>Pokaż rozwiązanie</summary>

```bash
kubectl apply -n ckad-lab -f - <<'EOF2'
apiVersion: apps/v1
kind: Deployment
metadata:
  name: app-green
  labels: { app: app, version: green }
spec:
  replicas: 2
  selector:
    matchLabels: { app: app, version: green }
  template:
    metadata:
      labels: { app: app, version: green }
    spec:
      containers:
        - name: web
          image: busybox:1.37
          command: ["sh", "-c", "mkdir -p /www; echo green > /www/index.html; exec httpd -f -p 8080 -h /www"]
          ports:
            - containerPort: 8080
EOF2
kubectl rollout status deployment/app-green -n ckad-lab --timeout=120s
kubectl patch service app -n ckad-lab -p '{"spec":{"selector":{"app":"app","version":"green"}}}'
kubectl get endpoints app -n ckad-lab
```

Przełączenie `kubectl patch` (albo `kubectl set selector svc app app=app,version=green`)
działa od razu. Powrót do blue to ten sam patch z `version: blue`.

</details>
