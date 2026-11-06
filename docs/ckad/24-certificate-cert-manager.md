# 24. Certificate z cert-managera (CRD)

**Domena:** Application Environment, Configuration and Security · **Limit:** 6 min · **Namespace:** `ckad-lab`

## Zadanie

W namespace `ckad-lab` utwórz zasób `Certificate` o nazwie `lab-cert` dla nazwy
DNS `lab.marketplace.test`, wystawiany przez `ClusterIssuer` `marketplace-ca`.
Klucz i certyfikat mają trafić do Secretu `lab-cert-tls`. Poczekaj, aż
Certificate będzie `Ready`.

Sprawdź sam: `kubectl get certificate lab-cert -n ckad-lab` pokazuje `READY True`,
a Secret `lab-cert-tls` ma typ `kubernetes.io/tls`.

## Po co to na egzaminie i w życiu

CKAD wymaga umiejętności pracy z własnymi zasobami (CRD): `kubectl get crd`,
`kubectl explain certificate.spec`, tworzenie obiektu z zainstalowanego CRD.
Pułapka: `kubectl` nie ma generatora dla Certificate, więc piszesz YAML
od zera — dlatego `kubectl explain --recursive` jest cenne. W sklepie
cert-manager wystawia certyfikaty dokładnie tak: Certificate `marketplace-tls`
w `deploy/ingress/ingresses.yaml` z ClusterIssuera `marketplace-ca`
(`deploy/ingress/issuers.yaml`).

## Rozwiązanie

<details>
<summary>Pokaż rozwiązanie</summary>

```bash
kubectl apply -n ckad-lab -f - <<'EOF2'
apiVersion: cert-manager.io/v1
kind: Certificate
metadata:
  name: lab-cert
spec:
  secretName: lab-cert-tls
  dnsNames:
    - lab.marketplace.test
  issuerRef:
    name: marketplace-ca
    kind: ClusterIssuer
EOF2
kubectl wait -n ckad-lab --for=condition=Ready certificate/lab-cert --timeout=90s
kubectl get secret lab-cert-tls -n ckad-lab
```

Pułapka: bez `issuerRef.kind: ClusterIssuer` cert-manager szuka zwykłego
`Issuer` w namespace i certyfikat wisi w stanie „issuer not found”.

</details>
