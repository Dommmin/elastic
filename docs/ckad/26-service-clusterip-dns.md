# 26. Service ClusterIP i DNS po FQDN

**Domena:** Services and Networking · **Limit:** 6 min · **Namespace:** `ckad-lab`

## Zadanie

W namespace `ckad-lab`:

1. Utwórz Deployment `catalog-mock` (obraz `nginx:1.29`, 2 repliki). Kontener ma
   port `80` **nazwany** `http`.
2. Wystaw go Service'em `catalog-mock` (typ ClusterIP) na porcie `80`.
   `targetPort` ma wskazywać port **po nazwie** (`http`), nie po numerze.
3. Z poda w namespace `ckad-lab-b` zapytanie po pełnej nazwie
   `catalog-mock.ckad-lab.svc.cluster.local` ma zwracać stronę nginx.

Sprawdź sam: `kubectl get endpointslice -n ckad-lab` pokazuje adresy podów,
a `kubectl run -n ckad-lab-b tmp --rm -it --image=busybox:1.37 --restart=Never -- wget -qO- <fqdn>` zwraca HTML.

## Po co to na egzaminie i w życiu

Service + selektor + `targetPort` to najczęstsza para zadań sieciowych. Port po
nazwie pozwala zmienić numer portu w Deploymencie bez dotykania Service'u.
Typowa pułapka: pomylony selektor (Service nie ma endpointów, a `kubectl get svc`
wygląda zdrowo) oraz krótka nazwa `catalog-mock`, która działa tylko w tym
samym namespace — z innego trzeba `nazwa.namespace` albo pełnego FQDN.
W sklepie tak jest wystawiony katalog: `deploy/k8s/catalog.yaml` (Service
`catalog-app`).

## Rozwiązanie

<details>
<summary>Pokaż rozwiązanie</summary>

Port nazwany nie ma flagi w `kubectl create deployment`, więc Deployment idzie
w YAML-u, a Service już imperatywnie:

```bash
kubectl apply -n ckad-lab -f - <<'EOF'
apiVersion: apps/v1
kind: Deployment
metadata:
  name: catalog-mock
spec:
  replicas: 2
  selector:
    matchLabels: { app: catalog-mock }
  template:
    metadata:
      labels: { app: catalog-mock }
    spec:
      containers:
        - name: nginx
          image: nginx:1.29
          ports:
            - { name: http, containerPort: 80 }
EOF
kubectl rollout status -n ckad-lab deploy/catalog-mock --timeout=90s
kubectl expose -n ckad-lab deploy/catalog-mock --port=80 --target-port=http
kubectl run -n ckad-lab-b tmp-dns --rm -i --restart=Never --image=busybox:1.37 \
  -- wget -qO- -T 5 http://catalog-mock.ckad-lab.svc.cluster.local | head -5
```

Pułapka: `kubectl expose` z `--target-port=http` zapisuje nazwę, nie liczbę —
sprawdź `kubectl get svc catalog-mock -o yaml`. Bez endpointów (`kubectl get
endpointslice`) Service istnieje, ale nic nie odpowiada.

</details>
