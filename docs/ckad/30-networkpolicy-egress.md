# 30. NetworkPolicy: ruch wychodzący tylko do DNS i jednej usługi

**Domena:** Services and Networking · **Limit:** 9 min · **Namespace:** `ckad-lab`

## Zadanie

W namespace `ckad-lab` działa pod `restricted-client` (etykieta
`app=restricted-client`, busybox) oraz dwa Service'y: `allowed-svc` i
`other-svc`, oba na porcie `80`. Utwórz NetworkPolicy `restricted-client-egress`:

1. Dotyczy tylko podu `restricted-client`.
2. Pod może wychodzić **tylko** do DNS (CoreDNS w `kube-system`, port `53`
   UDP i TCP) oraz do podów Service'u `allowed-svc` na porcie `80`.
3. Połączenie z `other-svc` i z internetem (np. `1.1.1.1`) jest blokowane.
4. Rozwiązywanie nazw (`nslookup` pełnej nazwy `allowed-svc.ckad-lab.svc.cluster.local`) nadal działa.

Sprawdź sam: `kubectl exec -n ckad-lab restricted-client -- wget -qO- -T 3 allowed-svc`.

## Po co to na egzaminie i w życiu

Egress jest trudniejszy niż ingress: zablokowanie wyjścia zabija też DNS,
więc bez reguły na port 53 nic się nie rozwiąże i wygląda to na awarię sieci.
Polityka działa na adresy podów (po przejściu przez Service), dlatego w
`to` wskazujesz etykiety podów, nie nazwę Service'u. Pułapka: osobne elementy
listy `to` to OR, a `namespaceSelector` z `podSelector` w jednym elemencie to
AND. Wzorzec „default-deny + allow-dns" jest w `deploy/k8s/networkpolicies.yaml`
(`allow-dns`) — tam z tego samego powodu.

## Rozwiązanie

<details>
<summary>Pokaż rozwiązanie</summary>

```bash
kubectl get pods -n ckad-lab -l app=allowed -o name   # jakie etykiety mają pody allowed-svc
kubectl apply -n ckad-lab -f - <<'EOF'
apiVersion: networking.k8s.io/v1
kind: NetworkPolicy
metadata:
  name: restricted-client-egress
spec:
  podSelector:
    matchLabels: { app: restricted-client }
  policyTypes: [Egress]
  egress:
    - to:
        - namespaceSelector:
            matchLabels: { kubernetes.io/metadata.name: kube-system }
      ports:
        - { protocol: UDP, port: 53 }
        - { protocol: TCP, port: 53 }
    - to:
        - podSelector:
            matchLabels: { app: allowed }
      ports:
        - { protocol: TCP, port: 80 }
EOF
sleep 5
kubectl exec -n ckad-lab restricted-client -- nslookup allowed-svc.ckad-lab.svc.cluster.local | tail -3
kubectl exec -n ckad-lab restricted-client -- wget -qO- -T 5 allowed-svc | head -3
kubectl exec -n ckad-lab restricted-client -- wget -qO- -T 3 other-svc || echo "zablokowane (oczekiwane)"
```

Pułapka: bez reguły DNS `allowed-svc` się nie rozwiąże; bez `policyTypes:
[Egress]` polityka z samym `egress` i tak działa, ale jawny zapis jest czytelniejszy.

</details>
