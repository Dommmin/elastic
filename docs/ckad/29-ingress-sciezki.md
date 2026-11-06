# 29. Ingress z routingiem po ścieżkach

**Domena:** Services and Networking · **Limit:** 7 min · **Namespace:** `ckad-lab`

## Zadanie

W namespace `ckad-lab` działają Deploymenty i Service'y `api-svc` (busybox httpd) oraz
`web-svc` (nginx), oba na porcie `80`. Utwórz Ingress `shop-ingress`:

1. `ingressClassName: traefik`.
2. Host `ckad.marketplace.test`.
3. Ścieżka `/api` z `pathType: Prefix` kieruje do Service'u `api-svc`, port `80`.
4. Ścieżka `/` (też `Prefix`) kieruje do `web-svc`, port `80`.

Sprawdź sam: `kubectl describe ingress shop-ingress -n ckad-lab` pokazuje oba
backendy z adresami podów (nie `<error: endpoints not found>`).

## Po co to na egzaminie i w życiu

Ingress to reguły routingu L7, ale sam nic nie robi — potrzebuje kontrolera
(tu Traefik) i dopasowania `ingressClassName`. Pułapki: `pathType: Exact`
zamiast `Prefix` (wtedy `/api/v1` nie pasuje), brak klasy (żaden kontroler
nie przejmie zasobu) oraz Service bez endpointów. Najdłuższy pasujący prefiks
wygrywa, więc `/api` ma pierwszeństwo przed `/`. Prawdziwe Ingressy sklepu:
`deploy/ingress/ingresses.yaml` (`shop`, `kibana`, `grafana`). Na egzaminie
`kubectl create ingress` z `--rule` oszczędza pisania YAML-a.

## Rozwiązanie

<details>
<summary>Pokaż rozwiązanie</summary>

Sufiks `*` w `--rule` oznacza `pathType: Prefix` (bez niego byłoby `Exact`):

```bash
kubectl create ingress shop-ingress -n ckad-lab --class=traefik \
  --rule="ckad.marketplace.test/api*=api-svc:80" \
  --rule="ckad.marketplace.test/*=web-svc:80"
kubectl get ingress shop-ingress -n ckad-lab -o yaml | grep -E "pathType|path:|name:|number:"
kubectl get endpointslice -n ckad-lab -l kubernetes.io/service-name=api-svc
kubectl get endpointslice -n ckad-lab -l kubernetes.io/service-name=web-svc
```

Pułapka: bez `*` dostajesz `Exact` — `/api` działa, `/api/v1` już nie.

</details>
