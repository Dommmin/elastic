# 13. Sondy: startup, readiness i liveness

**Domena:** Application Observability and Maintenance · **Limit:** 7 min · **Namespace:** `ckad-lab`

## Zadanie

W namespace `ckad-lab` utwórz Deployment `probe-web` (1 replika, obraz `nginx:1.29`,
kontener `web`, port 80) z trzema sondami:

1. `startupProbe`: HTTP GET `/` na porcie 80, `periodSeconds: 2`, `failureThreshold: 15`.
2. `readinessProbe`: HTTP GET `/` na porcie 80, `periodSeconds: 5`.
3. `livenessProbe`: TCP na porcie 80, `periodSeconds: 10`, `failureThreshold: 3`.

Pod ma być `Ready`.

Sprawdź sam: `kubectl describe pod -l app=probe-web` pokazuje trzy linie `Startup:`,
`Readiness:` i `Liveness:`.

## Po co to na egzaminie i w życiu

Sondy to pewniak egzaminacyjny. Readiness decyduje, czy pod dostaje ruch,
liveness, czy kubelet go zrestartuje, a startupProbe wstrzymuje obie pozostałe,
dopóki aplikacja raz nie wystartuje. Typowa pułapka: liveness bez startupProbe
na wolno startującej aplikacji zabija pod w pętli. W projekcie robi to
`catalog-app`: `startupProbe` z budżetem 60 s zamiast `initialDelaySeconds`
(`deploy/k8s/catalog.yaml`).

## Rozwiązanie

<details>
<summary>Pokaż rozwiązanie</summary>

Sond nie da się ustawić flagami, więc szkielet z `kubectl create deployment`
i uzupełnienie w YAML-u:

```bash
kubectl apply -n ckad-lab -f - <<'EOF'
apiVersion: apps/v1
kind: Deployment
metadata:
  name: probe-web
spec:
  replicas: 1
  selector:
    matchLabels: { app: probe-web }
  template:
    metadata:
      labels: { app: probe-web }
    spec:
      containers:
        - name: web
          image: nginx:1.29
          ports:
            - containerPort: 80
          startupProbe:
            httpGet: { path: /, port: 80 }
            periodSeconds: 2
            failureThreshold: 15
          readinessProbe:
            httpGet: { path: /, port: 80 }
            periodSeconds: 5
          livenessProbe:
            tcpSocket: { port: 80 }
            periodSeconds: 10
            failureThreshold: 3
EOF
kubectl rollout status -n ckad-lab deployment/probe-web --timeout=90s
```

Budżet startu to `failureThreshold × periodSeconds` (tu 30 s). Dopiero po
sukcesie startupProbe zaczynają działać pozostałe sondy.

</details>
