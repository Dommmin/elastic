# Laboratorium CKAD (ETAP G3)

30 zadań na czas w pięciu domenach egzaminu Certified Kubernetes Application
Developer, wykonywanych na tym samym k3s co sklep, ale w odgrodzonym laboratorium.
Każde zadanie ma limit minut, sprawdzarkę i rozwiązanie imperatywne. Przewodnik
po tym, jak lab powstał i dlaczego wygląda tak, a nie inaczej:
[docs/blog/etap-g3-ckad-lab.md](../blog/etap-g3-ckad-lab.md).

## Start w 3 poleceniach

```bash
make k3s-tunnel                 # tunel do API k3s (127.0.0.1:26443), jak przy innych k3s-*
make ckad-lab-up                # namespace'y ckad-lab i ckad-lab-b + kubeconfig ucznia
export KUBECONFIG=~/.kube/ckad-lab.yaml
```

Potem dla każdego zadania:

```bash
make ckad-start t=07            # czyści lab, przygotowuje zadanie, pokazuje treść, włącza stoper
# ... rozwiązujesz w terminalu z KUBECONFIG ucznia ...
make ckad-check t=07            # ✓/✗ dla każdego wymagania, czas na tle limitu, zapis wyniku
make ckad-scores                # historia i lista zadań do powtórki
```

Symulacja egzaminu: `make ckad-exam` (domyślnie 16 losowych zadań na 120 minut;
`n=` i `min=` zmieniają), koniec: `bash tools/ckad/exam.sh check`. Próg zdania CKAD
to 66%.

Sprzątanie po sesji: `make ckad-lab-down` (usuwa oba namespace'y razem z kubeconfigiem
ucznia). Lab nic nie kosztuje, gdy go nie ma.

## Dlaczego to bezpieczne na klastrze ze sklepem

| Mechanizm | Co chroni |
|---|---|
| Kubeconfig ucznia (`~/.kube/ckad-lab.yaml`) = ServiceAccount z rolą `admin` tylko w `ckad-lab` i `ckad-lab-b` | `kubectl delete` w złym namespace kończy się `Forbidden`, nie awarią sklepu. Sprawdź: `kubectl auth can-i delete pods -n marketplace` → `no` |
| ResourceQuota `lab-guard` (1 CPU / 1,5 GiB requests, 2 CPU / 2 GiB limits, 20 podów) | `replicas: 50` albo pętla tworząca pody nie zje pamięci produkcji na tym samym węźle |
| LimitRange `lab-guard` (domyślnie 50m/64Mi request, 200m/256Mi limit) | zapomniane `resources` nie znaczą „bez limitu” |
| NetworkPolicy `default-deny` w `marketplace` (z G2) | pody z labu nie dosięgną bazy, kolejki ani ES sklepu |
| Pod Security `baseline` (ostrzeżenia `restricted`) | uprzywilejowany pod z labu nie dostanie się do węzła |

Ćwicz zawsze kubeconfigiem ucznia. Kubeconfig operatora (`~/.kube/elastic-vps.yaml`)
służy tylko skryptom do zakładania labu i sprawdzania.

## Nawyki, które oszczędzają minuty na egzaminie

```bash
alias k=kubectl
export do="--dry-run=client -o yaml"      # k create deploy web --image=nginx $do > web.yaml
export now="--force --grace-period=0"     # k delete pod x $now
k config set-context --current --namespace=ckad-lab   # na egzaminie: kontekst podany w zadaniu!
k explain deploy.spec.strategy.rollingUpdate --recursive | less
```

- Szkielet zawsze z `kubectl create/run ... $do`, nigdy YAML z pamięci.
- `kubectl explain <zasób>.<pole>` zamiast dokumentacji, gdy nie pamiętasz nazwy pola.
- Po każdej zmianie sprawdź stan (`k get`, `k describe`, `k rollout status`) zanim
  przejdziesz dalej: zadanie „zrobione”, ale z podem w `CrashLoopBackOff`, jest za 0 pkt.
- Vim: `set et sw=2 ts=2` w `~/.vimrc` (YAML i tabulatory się nie lubią).
- Zadanie, które Cię blokuje dłużej niż limit, zostaw i wróć do niego na końcu.

## Jak działa sprawdzarka

Dla każdego zadania `tools/ckad/tasks/NN.sh` ma `check()` (i czasem `setup()`, np.
celowo zepsuty Deployment do debugowania). Sprawdzarka weryfikuje **wymagania
z treści**, nie jeden konkretny sposób rozwiązania. Rozwiązanie deklaratywne
i imperatywne przechodzą tak samo.

`make ckad-selftest` udowadnia, że zadania nie kłamią: dla każdego zadania
nierozwiązany lab musi dać FAIL, a rozwiązanie z dokumentu wykonane kubeconfigiem
ucznia musi dać PASS. Uruchamiaj go po każdej zmianie zadań. Pełny przebieg
trwa kilkadziesiąt minut. Na jednym `LAB` zawsze jeden selftest naraz, bo reset
jednego przebiegu skasowałby zasoby drugiego. Do równoległych testów służą osobne
laby: `LAB=ckad-d1 make ckad-lab-up`, potem `LAB=ckad-d1 make ckad-selftest t=07`.

## Zadania

<!-- spis generowany: tools/ckad/index.sh -->

| # | Zadanie | Domena | Limit |
|---|---|---|---|
| 01 | [Sidecar, który czyta logi aplikacji](01-sidecar-logow.md) | Application Design and Build | 7 min |
| 02 | [Job z czterema uzupełnieniami](02-job-batch-count.md) | Application Design and Build | 5 min |
| 03 | [CronJob co 5 minut i ręczne uruchomienie](03-cronjob-report.md) | Application Design and Build | 6 min |
| 04 | [Init container przygotowuje treść strony](04-init-container-tresc.md) | Application Design and Build | 7 min |
| 05 | [PVC i Deployment, którego dane przeżywają restart](05-pvc-notes.md) | Application Design and Build | 8 min |
| 06 | [Budowanie obrazu kontenera](06-budowanie-obrazu.md) | Application Design and Build | 6 min |
| 07 | [Rolling update i rollback](07-rolling-update-i-rollback.md) | Application Deployment | 7 min |
| 08 | [Canary na dwóch Deploymentach](08-canary-dwa-deploymenty.md) | Application Deployment | 8 min |
| 09 | [Blue/green przełączeniem selektora](09-blue-green.md) | Application Deployment | 6 min |
| 10 | [Helm: instalacja i upgrade lokalnego chartu](10-helm-install-upgrade.md) | Application Deployment | 8 min |
| 11 | [Kustomize: overlay prod](11-kustomize-overlay-prod.md) | Application Deployment | 9 min |
| 12 | [Wstrzymany rollout: kilka zmian, jedna rewizja](12-wstrzymany-rollout.md) | Application Deployment | 6 min |
| 13 | [Sondy: startup, readiness i liveness](13-sondy-probe-web.md) | Application Observability and Maintenance | 7 min |
| 14 | [Debug: CrashLoopBackOff](14-debug-crashloop.md) | Application Observability and Maintenance | 7 min |
| 15 | [ImagePullBackOff i zdarzenia](15-imagepullbackoff-zdarzenia.md) | Application Observability and Maintenance | 6 min |
| 16 | [`kubectl top`: który pod zjada CPU](16-kubectl-top.md) | Application Observability and Maintenance | 6 min |
| 17 | [Przestarzałe API](17-przestarzale-api.md) | Application Observability and Maintenance | 6 min |
| 18 | [ConfigMap jako env i jako plik](18-configmap-env-wolumen.md) | Application Environment, Configuration and Security | 7 min |
| 19 | [Secret jako env i jako plik](19-secret-env-plik.md) | Application Environment, Configuration and Security | 7 min |
| 20 | [SecurityContext poda i kontenera](20-securitycontext.md) | Application Environment, Configuration and Security | 7 min |
| 21 | [ServiceAccount bez tokenu API](21-serviceaccount-bez-tokenu.md) | Application Environment, Configuration and Security | 5 min |
| 22 | [RBAC: Role i RoleBinding dla ServiceAccounta](22-rbac-pod-reader.md) | Application Environment, Configuration and Security | 7 min |
| 23 | [ResourceQuota i LimitRange zespołu](23-resourcequota-limitrange.md) | Application Environment, Configuration and Security | 8 min |
| 24 | [Certificate z cert-managera (CRD)](24-certificate-cert-manager.md) | Application Environment, Configuration and Security | 6 min |
| 25 | [imagePullSecrets przez ServiceAccount](25-imagepullsecrets-serviceaccount.md) | Application Environment, Configuration and Security | 6 min |
| 26 | [Service ClusterIP i DNS po FQDN](26-service-clusterip-dns.md) | Services and Networking | 6 min |
| 27 | [Service typu NodePort](27-service-nodeport.md) | Services and Networking | 4 min |
| 28 | [NetworkPolicy: ruch przychodzący tylko od frontendu](28-networkpolicy-ingress.md) | Services and Networking | 8 min |
| 29 | [Ingress z routingiem po ścieżkach](29-ingress-sciezki.md) | Services and Networking | 7 min |
| 30 | [NetworkPolicy: ruch wychodzący tylko do DNS i jednej usługi](30-networkpolicy-egress.md) | Services and Networking | 9 min |
