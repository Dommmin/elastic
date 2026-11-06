# 27. Service typu NodePort

**Domena:** Services and Networking · **Limit:** 4 min · **Namespace:** `ckad-lab`

## Zadanie

W namespace `ckad-lab` działa Deployment `web-np` (nginx, port kontenera `80`,
etykieta `app=web-np`). Wystaw go na zewnątrz węzła:

1. Utwórz Service `web-np` typu `NodePort` z portem `80` i `targetPort` `80`.
2. Service ma mieć endpointy (pody `web-np`).
3. Konkretnego `nodePort` **nie** przypinaj — niech przydzieli go klaster
   (zakres 30000–32767).

Sprawdź sam: `kubectl get svc web-np -n ckad-lab` pokazuje `80:3xxxx/TCP`.

## Po co to na egzaminie i w życiu

NodePort otwiera ten sam port na każdym węźle i jest najprostszym sposobem
dostępu bez Ingressu czy LoadBalancera. Pułapki: ręcznie wpisany `nodePort`
poza zakresem lub już zajęty (zadanie się nie utworzy), oraz pomylenie trzech
numerów — `port` (Service), `targetPort` (pod), `nodePort` (węzeł). W tym
projekcie ruch z internetu wchodzi inaczej, przez Traefika:
`deploy/ingress/traefik-values.yaml`; NodePort jest narzędziem do testów.
W labie obowiązuje limit 2 NodePortów na namespace (`lab-guard`).

## Rozwiązanie

<details>
<summary>Pokaż rozwiązanie</summary>

```bash
kubectl expose -n ckad-lab deploy/web-np --type=NodePort --port=80 --target-port=80
kubectl get svc -n ckad-lab web-np
kubectl get endpointslice -n ckad-lab -l kubernetes.io/service-name=web-np
```

Gdyby Service miał być w YAML-u: `--dry-run=client -o yaml`, a w nim
`type: NodePort`; pole `nodePort` zostaw puste.

</details>
