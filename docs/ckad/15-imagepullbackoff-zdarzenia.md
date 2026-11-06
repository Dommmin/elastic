# 15. ImagePullBackOff i zdarzenia

**Domena:** Application Observability and Maintenance · **Limit:** 6 min · **Namespace:** `ckad-lab`

## Zadanie

W namespace `ckad-lab` Deployment `pull-web` nie może wystartować (pod w stanie
`ErrImagePull` / `ImagePullBackOff`).

1. Ustal z **zdarzeń** (`kubectl events` albo `kubectl describe`), dlaczego
   pobranie obrazu się nie udaje, i zapisz w lokalnym pliku `/tmp/ckad-15.txt`
   komunikat (lub sam powód) z tych zdarzeń.
2. Napraw obraz na `nginx:1.29`. Deployment ma być w pełni dostępny.

Sprawdź sam: `kubectl get deploy pull-web` pokazuje `1/1`.

## Po co to na egzaminie i w życiu

Literówka w tagu to najczęstsza przyczyna ImagePullBackOff, a pierwszym
miejscem do zajrzenia są zdarzenia: `Failed` z komunikatem `manifest unknown`
albo `not found` oznacza zły tag, a `unauthorized` brak `imagePullSecrets`.
Pułapka: zdarzenia wygasają po godzinie, więc zapisz powód, zanim naprawisz.
W projekcie tagi są przypięte (np. obraz w `deploy/k8s/catalog.yaml`), żeby
rollout nie kończył się właśnie takim błędem.

## Rozwiązanie

<details>
<summary>Pokaż rozwiązanie</summary>

```bash
until kubectl get events -n ckad-lab --field-selector reason=Failed -o jsonpath='{.items[*].message}' | grep -q .; do sleep 2; done
kubectl get events -n ckad-lab --field-selector reason=Failed -o jsonpath='{.items[0].message}' > /tmp/ckad-15.txt
cat /tmp/ckad-15.txt
kubectl set image -n ckad-lab deployment/pull-web web=nginx:1.29
kubectl rollout status -n ckad-lab deployment/pull-web --timeout=120s
```

Plik zawiera komunikat w stylu `Failed to pull image ... not found`. Dopiero
po zapisaniu powodu poprawiamy obraz.

</details>
