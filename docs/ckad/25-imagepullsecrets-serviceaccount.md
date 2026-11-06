# 25. imagePullSecrets przez ServiceAccount

**Domena:** Application Environment, Configuration and Security · **Limit:** 6 min · **Namespace:** `ckad-lab`

## Zadanie

W namespace `ckad-lab`:

1. Utwórz Secret typu docker-registry `regcred` dla serwera
   `registry.example.test` (użytkownik `demo`, hasło `demo-pass`,
   e-mail `demo@example.test` — dane fikcyjne).
2. Utwórz ServiceAccount `puller` i dopnij do niego `regcred` jako
   `imagePullSecrets`.
3. Utwórz pod `puller-pod` (obraz `busybox:1.37`, polecenie `sleep 3600`)
   z ServiceAccountem `puller` — **bez** wpisywania `imagePullSecrets` w poda.

Sprawdź sam: `kubectl get pod puller-pod -o jsonpath='{.spec.imagePullSecrets}'`
pokazuje `regcred`.

## Po co to na egzaminie i w życiu

Prywatny rejestr to codzienność: bez sekretu pod kończy w `ImagePullBackOff`.
Dopięcie sekretu do ServiceAccounta oszczędza wpisywania go w każdym
Deploymencie — admission kopiuje `imagePullSecrets` z konta do poda przy
tworzeniu. Pułapka: dotyczy to tylko podów utworzonych **po** zmianie konta;
istniejące trzeba odtworzyć. Sklep używa obrazów z GHCR
(`ghcr.io/dommmin/...` w `deploy/k8s/search-consumer.yaml`); gdyby paczki były
prywatne, ten sam wzorzec (docker-registry + ServiceAccount z
`deploy/k8s/serviceaccounts.yaml`) rozwiązałby pobieranie bez zmian w Deploymentach.

## Rozwiązanie

<details>
<summary>Pokaż rozwiązanie</summary>

```bash
kubectl create secret docker-registry regcred -n ckad-lab \
  --docker-server=registry.example.test --docker-username=demo \
  --docker-password=demo-pass --docker-email=demo@example.test
kubectl create serviceaccount puller -n ckad-lab
kubectl patch serviceaccount puller -n ckad-lab \
  -p '{"imagePullSecrets":[{"name":"regcred"}]}'
kubectl run puller-pod -n ckad-lab --image=busybox:1.37 \
  --overrides='{"spec":{"serviceAccountName":"puller"}}' -- sleep 3600
kubectl wait -n ckad-lab --for=condition=Ready pod/puller-pod --timeout=90s
kubectl get pod puller-pod -n ckad-lab -o jsonpath='{.spec.imagePullSecrets}{"\n"}'
```

Pułapka: pod startuje, bo `busybox` jest publiczny i serwer z sekretu nie
pasuje do Docker Hub — liczy się to, że `imagePullSecrets` jest w specyfikacji.

</details>
