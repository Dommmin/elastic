# 20. SecurityContext poda i kontenera

**Domena:** Application Environment, Configuration and Security · **Limit:** 7 min · **Namespace:** `ckad-lab`

## Zadanie

W namespace `ckad-lab` utwórz pod `hardened` (obraz `busybox:1.37`, polecenie
`sleep 3600`) z następującym SecurityContextem:

- na poziomie poda: `runAsUser: 1000`, `runAsGroup: 3000`, `fsGroup: 2000`,
  `runAsNonRoot: true`, `seccompProfile` typu `RuntimeDefault`,
- na poziomie kontenera: `readOnlyRootFilesystem: true`,
  `allowPrivilegeEscalation: false`, `capabilities` z `drop: ["ALL"]`.

Sprawdź sam: `kubectl exec hardened -- id` ma pokazać `uid=1000 gid=3000`.

## Po co to na egzaminie i w życiu

To obowiązkowy punkt domeny bezpieczeństwa. Pułapka: część pól istnieje tylko
na poziomie poda (`fsGroup`, `runAsUser` jako domyślne), a część tylko na
poziomie kontenera (`allowPrivilegeEscalation`, `readOnlyRootFilesystem`,
`capabilities`) — wpisane na złym poziomie dają błąd walidacji albo są ciche.
Pamiętaj też, że `readOnlyRootFilesystem` blokuje zapis nawet do `/tmp`, więc
aplikacje potrzebują wtedy `emptyDir`. Dokładnie ten profil „restricted”
stosuje sklep od ETAPU G2: pod `consumer` w `deploy/k8s/search-consumer.yaml`
ma `runAsNonRoot`, `fsGroup`, `seccompProfile`, `drop: [ALL]` i system plików
tylko do odczytu, a admission policy w `deploy/k8s/admission-policy.yaml`
pilnuje, żeby deployer nie utworzył poda uprzywilejowanego.

## Rozwiązanie

<details>
<summary>Pokaż rozwiązanie</summary>

Flagi `kubectl run` nie obejmują securityContextu — YAML:

```bash
kubectl apply -n ckad-lab -f - <<'EOF2'
apiVersion: v1
kind: Pod
metadata:
  name: hardened
spec:
  securityContext:
    runAsUser: 1000
    runAsGroup: 3000
    fsGroup: 2000
    runAsNonRoot: true
    seccompProfile: { type: RuntimeDefault }
  containers:
    - name: app
      image: busybox:1.37
      command: ["sleep", "3600"]
      securityContext:
        readOnlyRootFilesystem: true
        allowPrivilegeEscalation: false
        capabilities: { drop: ["ALL"] }
EOF2
kubectl wait -n ckad-lab --for=condition=Ready pod/hardened --timeout=90s
kubectl exec -n ckad-lab hardened -- id
```

Pułapka: `kubectl explain pod.spec.securityContext` i
`kubectl explain pod.spec.containers.securityContext` pokazują, które pole na
którym poziomie jest dozwolone — na egzaminie szybsze niż szukanie w docs.

</details>
