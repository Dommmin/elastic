# 28. NetworkPolicy: ruch przychodzący tylko od frontendu

**Domena:** Services and Networking · **Limit:** 8 min · **Namespace:** `ckad-lab-b`

## Zadanie

W namespace `ckad-lab-b` działa Deployment `api` (pody z etykietą `app=api`,
nginx na porcie `80`) i Service `api`. Klaster egzekwuje NetworkPolicy.

1. Utwórz politykę `api-allow-frontend`: pody `app=api` przyjmują ruch na porcie
   `80` (TCP) **tylko** od podów z etykietą `role=frontend` z tego samego
   namespace.
2. Oddzielną politykę `default-deny-ingress`: w całym namespace `ckad-lab-b` nic
   nie wchodzi do podów, dopóki inna polityka na to nie pozwoli.
3. Pod z etykietą `role=frontend` łączy się z `api:80`, pod bez tej etykiety
   — nie (timeout).

Sprawdź sam: `kubectl run -n ckad-lab-b t --rm -it --restart=Never --labels=role=frontend --image=busybox:1.37 -- wget -qO- -T 3 api`.

## Po co to na egzaminie i w życiu

NetworkPolicy to pewniak egzaminu. Zasady: polityki się sumują (suma
dozwolonych), pusty `podSelector: {}` oznacza wszystkie pody, a polityka bez
reguł `ingress` z `policyTypes: [Ingress]` blokuje wszystko. Pułapka:
`from` z dwoma elementami listy to OR, a `podSelector` + `namespaceSelector`
w jednym elemencie to AND. Dokładnie ten model — default-deny plus
polityka po stronie serwera — stoi w `deploy/k8s/networkpolicies.yaml`.

## Rozwiązanie

<details>
<summary>Pokaż rozwiązanie</summary>

NetworkPolicy nie ma generatora w `kubectl create`, więc YAML:

```bash
kubectl apply -n ckad-lab-b -f - <<'EOF'
apiVersion: networking.k8s.io/v1
kind: NetworkPolicy
metadata:
  name: default-deny-ingress
spec:
  podSelector: {}
  policyTypes: [Ingress]
---
apiVersion: networking.k8s.io/v1
kind: NetworkPolicy
metadata:
  name: api-allow-frontend
spec:
  podSelector:
    matchLabels: { app: api }
  policyTypes: [Ingress]
  ingress:
    - from:
        - podSelector:
            matchLabels: { role: frontend }
      ports:
        - { protocol: TCP, port: 80 }
EOF
kubectl get netpol -n ckad-lab-b
# bez etykiety: timeout (pod zostanie usunięty, błąd wget ignorujemy)
kubectl run -n ckad-lab-b t-deny --rm -i --restart=Never --image=busybox:1.37 \
  -- wget -qO- -T 3 api || echo "zablokowane (oczekiwane)"
kubectl run -n ckad-lab-b t-allow --rm -i --restart=Never --labels=role=frontend --image=busybox:1.37 \
  -- wget -qO- -T 5 api | head -3
```

Pułapka: `default-deny-ingress` sam w sobie zamyka też ruch do `api` — dopiero
`api-allow-frontend` go otwiera, i to tylko dla `role=frontend`.

</details>
