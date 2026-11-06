# 23. ResourceQuota i LimitRange zespołu

**Domena:** Application Environment, Configuration and Security · **Limit:** 8 min · **Namespace:** `ckad-lab-b`

## Zadanie

W namespace `ckad-lab-b` (tylko tu masz uprawnienia do kwot):

1. Utwórz ResourceQuota `team-b`: `requests.cpu` 500m, `limits.memory` 512Mi,
   `pods` 3.
2. Utwórz LimitRange `team-b-defaults` dla kontenerów: domyślny request
   `cpu: 100m`, `memory: 64Mi`, domyślny limit `cpu: 200m`, `memory: 128Mi`.
3. Utwórz pod `quota-ok` (obraz `nginx:1.29`), który mieści się w kwocie
   (np. limit pamięci 128Mi). Pod z limitem pamięci 1Gi ma zostać **odrzucony**.
   Nie ruszaj istniejących obiektów `lab-guard`.

Sprawdź sam: `kubectl describe resourcequota team-b -n ckad-lab-b`.

## Po co to na egzaminie i w życiu

Kwota ogranicza sumę zasobów w namespace, LimitRange ogranicza i uzupełnia
wartości pojedynczego kontenera. Pułapka: gdy kwota dotyczy `requests`
lub `limits`, **każdy** pod musi je mieć — bez LimitRange z wartościami
domyślnymi pod bez zasobów jest odrzucany. Błąd odrzucenia (`exceeded quota`)
przychodzi od razu przy tworzeniu, nie jako Pending. Ten sam mechanizm
chroni ten lab (`lab-guard`) i dzieli klaster między zespoły w życiu;
sklep ustawia limity zasobów wprost w manifestach, np. w `deploy/k8s/catalog.yaml`.

## Rozwiązanie

<details>
<summary>Pokaż rozwiązanie</summary>

```bash
kubectl create resourcequota team-b -n ckad-lab-b \
  --hard=requests.cpu=500m,limits.memory=512Mi,pods=3
kubectl apply -n ckad-lab-b -f - <<'EOF2'
apiVersion: v1
kind: LimitRange
metadata:
  name: team-b-defaults
spec:
  limits:
    - type: Container
      defaultRequest: { cpu: 100m, memory: 64Mi }
      default: { cpu: 200m, memory: 128Mi }
EOF2
kubectl apply -n ckad-lab-b -f - <<'EOF2'
apiVersion: v1
kind: Pod
metadata:
  name: quota-ok
spec:
  containers:
    - name: web
      image: nginx:1.29
      resources:
        requests: { cpu: 100m, memory: 64Mi }
        limits: { cpu: 200m, memory: 128Mi }
EOF2
kubectl wait -n ckad-lab-b --for=condition=Ready pod/quota-ok --timeout=90s
# pod przekraczający kwotę (limit 1Gi > 512Mi) — odrzucony przy tworzeniu
kubectl apply -n ckad-lab-b -f - <<'EOF2' || true
apiVersion: v1
kind: Pod
metadata:
  name: quota-big
spec:
  containers:
    - name: web
      image: nginx:1.29
      resources:
        requests: { cpu: 100m, memory: 64Mi }
        limits: { cpu: 200m, memory: 1Gi }
EOF2
kubectl describe resourcequota team-b -n ckad-lab-b
```

Pułapka: `kubectl run` nie ma już flag `--requests/--limits`, więc zasoby
pisze się w YAML-u (albo polega na LimitRange z wartościami domyślnymi).

</details>
