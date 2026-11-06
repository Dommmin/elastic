# 17. Przestarzałe API

**Domena:** Application Observability and Maintenance · **Limit:** 6 min · **Namespace:** `ckad-lab`

## Zadanie

W namespace `ckad-lab` działa Deployment `legacy-web`. Manifest
`tools/ckad/fixtures/17/legacy.yaml` (HorizontalPodAutoscaler i
PodDisruptionBudget dla tego Deploymentu) używa usuniętych wersji API
i `kubectl apply` go odrzuca.

1. Ustal aktualne wersje API (`kubectl api-resources`, `kubectl explain`).
2. Popraw manifest, zachowując semantykę (ten sam cel, repliki 2 do 5, CPU 60%,
   `minAvailable: 1`), i zastosuj go w `ckad-lab`. Oryginału w repozytorium nie
   nadpisuj, pracuj na kopii.

Sprawdź sam: `kubectl get hpa,pdb -n ckad-lab`.

## Po co to na egzaminie i w życiu

Po aktualizacji klastra stare manifesty przestają się stosować, a egzamin
sprawdza, czy umiesz znaleźć właściwy `apiVersion` bez googlowania:
`kubectl api-resources | grep -i autoscaling`, `kubectl explain hpa`.
Pułapka: sama zmiana wersji bywa niewystarczająca, bo w `v2beta1`
pola metryk wyglądały inaczej niż w `v2`. W projekcie HPA używa stabilnego
`autoscaling/v2` (`deploy/k8s/hpa.yaml`).

## Rozwiązanie

<details>
<summary>Pokaż rozwiązanie</summary>

```bash
kubectl api-resources | grep -Ei 'horizontalpodautoscalers|poddisruptionbudgets'
sed -e 's#autoscaling/v2beta2#autoscaling/v2#' -e 's#policy/v1beta1#policy/v1#' \
  tools/ckad/fixtures/17/legacy.yaml > /tmp/legacy-fixed.yaml
kubectl apply -n ckad-lab -f /tmp/legacy-fixed.yaml
kubectl get hpa,pdb -n ckad-lab
```

W tym manifeście pola `v2beta2` i `v2` są identyczne, więc wystarcza zmiana
`apiVersion`.

</details>
