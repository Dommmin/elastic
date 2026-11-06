# 07. Rolling update i rollback

**Domena:** Application Deployment · **Limit:** 7 min · **Namespace:** `ckad-lab`

## Zadanie

W namespace `ckad-lab`:

1. Utwórz Deployment `web-roll` z obrazem `nginx:1.28` i 3 replikami. Strategia
   `RollingUpdate` z `maxSurge: 1` i `maxUnavailable: 0`.
2. Zaktualizuj obraz kontenera do `nginx:1.29`. Nowa rewizja ma mieć adnotację
   `kubernetes.io/change-cause` z opisem zmiany (dowolny niepusty tekst).
   Poczekaj na zakończenie rolloutu.
3. Wycofaj Deployment do **pierwszej** rewizji (obraz `nginx:1.28`) i poczekaj,
   aż wszystkie 3 repliki będą dostępne.

Sprawdź sam: `kubectl rollout history deploy/web-roll` pokazuje rewizje,
a `kubectl get deploy web-roll` — 3/3 gotowe.

## Po co to na egzaminie i w życiu

Aktualizacja, historia i `rollout undo` to żelazny punkt domeny Application
Deployment. Typowa pułapka: `rollout undo` nie cofa rewizji, tylko tworzy nową
z szablonem starej (po cofnięciu do rewizji 1 masz rewizje 2 i 3, a „jedynka”
znika z listy). Druga: `change-cause` ustawiasz **przed** zmianą obrazu, bo
trafia do ReplicaSetu tworzonego przez tę zmianę. W sklepie `maxSurge 1`
bez `readinessProbe` jest powodem, dla którego `catalog-worker` i
`catalog-scheduler` używają `Recreate` (komentarz w `deploy/k8s/catalog.yaml`),
a `tools/k3s/deploy.sh` czeka na każdy rollout przez `rollout status`.

## Rozwiązanie

<details>
<summary>Pokaż rozwiązanie</summary>

```bash
kubectl create deployment web-roll --image=nginx:1.28 --replicas=3 -n ckad-lab
kubectl patch deployment web-roll -n ckad-lab -p \
  '{"spec":{"strategy":{"type":"RollingUpdate","rollingUpdate":{"maxSurge":1,"maxUnavailable":0}}}}'
kubectl rollout status deployment/web-roll -n ckad-lab --timeout=120s

kubectl annotate deployment web-roll -n ckad-lab kubernetes.io/change-cause="nginx 1.28 -> 1.29" --overwrite
kubectl set image deployment/web-roll -n ckad-lab nginx=nginx:1.29
kubectl rollout status deployment/web-roll -n ckad-lab --timeout=120s

kubectl rollout history deployment/web-roll -n ckad-lab
kubectl rollout undo deployment/web-roll -n ckad-lab --to-revision=1
kubectl rollout status deployment/web-roll -n ckad-lab --timeout=120s
kubectl rollout history deployment/web-roll -n ckad-lab
```

Nazwa kontenera w `kubectl create deployment` to nazwa obrazu bez tagu (`nginx`),
stąd `nginx=nginx:1.29`. Po `undo` Deployment wskazuje `nginx:1.28`, ale numer
rewizji to już 3.

</details>
