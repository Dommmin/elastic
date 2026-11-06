# 22. RBAC: Role i RoleBinding dla ServiceAccounta

**Domena:** Application Environment, Configuration and Security · **Limit:** 7 min · **Namespace:** `ckad-lab`

## Zadanie

W namespace `ckad-lab`:

1. Utwórz ServiceAccount `viewer`.
2. Utwórz Role `pod-reader`, która pozwala na `get`, `list`, `watch` na
   `pods` oraz `get` na `pods/log`.
3. Utwórz RoleBinding `pod-reader-binding`, który nadaje tę rolę
   ServiceAccountowi `viewer`.

Sprawdź sam: `kubectl auth can-i list pods --as=system:serviceaccount:ckad-lab:viewer`
(w labie uczeń nie ma `impersonate`, więc to polecenie wykonuje się kontem
operatora — tu sprawdzarka). Ma być: list pods `yes`, delete pods `no`,
get pods/log `yes`.

## Po co to na egzaminie i w życiu

Zadanie „daj kontu dokładnie te uprawnienia” wraca na każdym egzaminie.
Pułapka: log to **podzasób** (`pods/log`) i trzeba go wymienić osobno —
`pods` go nie obejmuje. Druga: `kubectl create rolebinding --serviceaccount`
wymaga formatu `namespace:nazwa`. Najmniejsze uprawnienia stosuje też sklep:
konto `deployer` z `deploy/k8s/rbac.yaml` ma tylko to, czego potrzebuje
wdrożenie, i tylko w namespace `marketplace`.

## Rozwiązanie

<details>
<summary>Pokaż rozwiązanie</summary>

```bash
kubectl create serviceaccount viewer -n ckad-lab
kubectl create role pod-reader -n ckad-lab \
  --verb=get,list,watch --resource=pods
kubectl patch role pod-reader -n ckad-lab --type=json -p '[
  {"op":"add","path":"/rules/-","value":{"apiGroups":[""],"resources":["pods/log"],"verbs":["get"]}}]'
kubectl create rolebinding pod-reader-binding -n ckad-lab \
  --role=pod-reader --serviceaccount=ckad-lab:viewer
kubectl get role pod-reader -n ckad-lab -o jsonpath='{.rules}{"\n"}'
```

Pułapka: `kubectl create role` z dwoma `--resource` i wspólnymi `--verb` dałoby
`get/list/watch` także na logach; stąd osobna reguła z `patch`.

</details>
