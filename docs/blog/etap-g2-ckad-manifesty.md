# securityContext, NetworkPolicy, Ingress, HPA i canary na k3s, krok po kroku

*ETAP G2 projektu Marketplace Search Platform. Po ETAPIE D2 sklep działa na k3s,
ale manifesty obejmowały mniej więcej połowę zakresu CKAD. Wszystkie pody
działały jako root z zapisywalnym systemem plików i tokenem API, sieć podów
była płaska (każdy z każdym), do sklepu wchodziło się `port-forwardem`, a liczba
replik była na sztywno. Ten etap dokłada brakujące tematy na tej samej aplikacji.
Każdy z nich ma realny powód istnienia i test, który go pilnuje.*

> **Stan:** gałąź `g2-ckad` (na `g1-monitoring`, PR po G1).
> **Bez klastra, zielone:** `make k3s-manifests-check` (315 asercji, w tym
> ewaluator NetworkPolicy), `make k3s-ingress-check` (26), `make k3s-monitoring-check`,
> testy catalog 835/835 (nowy `TrustedProxiesTest`), wszystko w CI.
> **Sprawdzone na obrazach przed wdrożeniem:** catalog, search, postgres, redis
> i GlitchTip uruchomione lokalnie z `--user <uid> --read-only --cap-drop ALL`
> (sekcja 3). `kubectl apply --dry-run=server` na klastrze: wszystkie obiekty
> przyjęte. Dwa błędy niezmienności (selektor catalog-app, Joby) obsługuje deploy.sh.
> **NIE wdrożone:** wdrożenie na produkcję wymaga Twojego uruchomienia (sekcja 10).
> Wyniki `make k3s-verify faza=g2` dopiszę tutaj po wdrożeniu.
>
> Plan: [`13-PLAN-ETAP-G-K8S.md`](../13-PLAN-ETAP-G-K8S.md). Błędy i decyzje:
> RUNBOOK [#083–#087](../RUNBOOK.md#083).

---

## Spis treści

1. [Co się zmienia i dlaczego w tej kolejności](#1-co-się-zmienia-i-dlaczego-w-tej-kolejności)
2. [Mapa plików](#2-mapa-plików)
3. [Krok 1 — securityContext: najpierw obraz, potem manifest](#krok-1--securitycontext-najpierw-obraz-potem-manifest)
4. [Krok 2 — ServiceAccount bez tokena i jedno konto z Role](#krok-2--serviceaccount-bez-tokena-i-jedno-konto-z-role)
5. [Krok 3 — startupProbe zamiast initialDelaySeconds](#krok-3--startupprobe-zamiast-initialdelayseconds)
6. [Krok 4 — NetworkPolicy i test, który liczy polityki bez klastra](#krok-4--networkpolicy-i-test-który-liczy-polityki-bez-klastra)
7. [Krok 5 — Ingress: Traefik, cert-manager i własne CA](#krok-5--ingress-traefik-cert-manager-i-własne-ca)
8. [Krok 6 — HPA](#krok-6--hpa)
9. [Krok 7 — canary za tym samym Service](#krok-7--canary-za-tym-samym-service)
10. [Wdrożenie i weryfikacja](#wdrożenie-i-weryfikacja)
11. [Ile to kosztuje](#ile-to-kosztuje)
12. [Pułapki](#pułapki)
13. [Co z tego na CKAD](#co-z-tego-na-ckad)

---

## 1. Co się zmienia i dlaczego w tej kolejności

| Temat | Przed G2 | Po G2 | Co by się stało bez tego |
|---|---|---|---|
| securityContext | root, zapisywalny `/`, pełne capabilities | uid 33/70/999/5000, read-only, `drop: [ALL]`, seccomp | włamanie przez PHP = root w kontenerze + zapis do kodu aplikacji |
| ServiceAccount | `default` z tokenem w każdym podzie | konto per aplikacja, `automountServiceAccountToken: false` | token w `/var/run/secrets/...` to legitymacja do API klastra |
| startupProbe | `initialDelaySeconds: 30/90` | `startupProbe` z budżetem | albo czekanie bez sensu, albo zabicie poda w trakcie migracji |
| NetworkPolicy | brak (każdy z każdym) | default-deny + trasy per serwer | z przejętego poda prosto do Postgresa, Redisa i internetu |
| Ingress | `kubectl port-forward` | Traefik + TLS z własnego CA | brak TLS, brak jednego wejścia do trzech UI |
| HPA | `replicas: 1` | 1..3 po CPU | szczyt ruchu = jeden przeciążony pod |
| canary | brak | `make k3s-canary tag=…` | każda wersja od razu na 100% ruchu |

Kolejność: najpierw rzeczy, które nie zmieniają ruchu, czyli securityContext,
konta i sondy. Potem NetworkPolicy, która go zmienia, ale tylko wewnątrz
klastra. Na końcu Ingress, HPA i canary, bo te dodają nowe ścieżki.
NetworkPolicy musi już wtedy znać namespace `ingress` i `monitoring` z G1.

## 2. Mapa plików

```
deploy/k8s/
  namespace.yaml          etykiety Pod Security Admission (warn/audit: restricted)
  serviceaccounts.yaml    konta per aplikacja, bez tokena
  rbac.yaml               ServiceAccount + Role + RoleBinding `deployer`
  networkpolicies.yaml    default-deny, DNS, egress w namespace, 9 polityk serwerów
  hpa.yaml                HorizontalPodAutoscaler catalog-app
  catalog.yaml …          securityContext, init kontener, startupProbe, track: stable
deploy/ingress/
  cert-manager-values.yaml, traefik-values.yaml    charty Helma (wersje w install-ingress.sh)
  issuers.yaml            CA: selfsigned -> Certificate isCA -> ClusterIssuer
  ingresses.yaml          certyfikaty hostów + 3 Ingressy
tools/k3s/
  ckad-check.rb           asercje G2 na wyrenderowanych manifestach (CI)
  ingress-check.sh        render chartu Traefika + asercje (CI)
  canary-render.rb, canary.sh      canary z żywego Deploymentu
  install-ingress.sh, ingress-tunnel.sh
  verify.sh               fazy hardening, netpol, ingress, hpa, canary (g2 = wszystkie)
apps/catalog/bootstrap/app.php     trustProxies z env TRUSTED_PROXIES
```

---

## Krok 1 — securityContext: najpierw obraz, potem manifest

Profil „restricted” z Pod Security Standards to pięć rzeczy:

```yaml
spec:
  securityContext:                 # poziom poda
    runAsNonRoot: true
    runAsUser: 33                  # liczbowo — patrz niżej
    seccompProfile: { type: RuntimeDefault }
  containers:
    - securityContext:             # poziom kontenera
        allowPrivilegeEscalation: false
        readOnlyRootFilesystem: true
        capabilities: { drop: [ALL] }
```

`runAsUser` jest liczbą, bo `runAsNonRoot` sprawdza uid, a nie nazwę. Obraz
z `USER www-data` przy `runAsNonRoot: true` bez `runAsUser` kubelet odrzuci,
bo nie zweryfikuje, że to nie root.

**Najpierw obraz.** Zamiast wdrażać i patrzeć, co padnie na produkcji, każdy
obraz uruchomiłem lokalnie z tym samym profilem:

```bash
docker run --rm --user 33:33 --read-only --cap-drop ALL \
  --security-opt no-new-privileges --tmpfs /tmp:mode=1777 \
  -v st:/app/storage -v bc:/app/bootstrap/cache \
  ghcr.io/dommmin/elastic-catalog:<SHA>
```

Co wyszło (każdy punkt to zmiana w manifeście):

1. **`exec: frankenphp: Operation not permitted`** (RUNBOOK #086). Binarka ma
   file capability `cap_net_bind_service=ep`, a przy pustym zbiorze ograniczającym
   exec kończy się EPERM. Dlatego catalog-app i search-metrics dostają
   `add: [NET_BIND_SERVICE]`. Procesy `php artisan` i `bin/console` nie potrzebują nic.
2. **`mkdir /data/caddy: permission denied`.** Caddy trzyma lokalne CA
   (`local_certs`) w `XDG_DATA_HOME=/data`, a autosave w `/config`. Obie ścieżki
   dostały emptyDir. Druga linia w logu, `failed to install root certificate`, to
   próba wpisania CA do magazynu systemu. Usunąłem ją przez `skip_install_trust`
   w Caddyfile, bo nikt w kontenerze z tego CA nie korzysta.
3. **Laravel pisze w `storage/` i `bootstrap/cache/`.** Pusty emptyDir zakryłby
   to, co obraz ma tam gotowe: `route:cache`, `view:cache`, `packages.php`.
   Dlatego init kontener kopiuje zawartość z obrazu do wolumenów:

   ```yaml
   initContainers:
     - name: writable-dirs
       command: ["sh", "-c", "cp -R /app/storage/. /work/storage/ && cp -R /app/bootstrap/cache/. /work/bootstrap-cache/"]
   ```
   `cp -R`, nie `cp -a`. Przy `-a` cp próbuje zachować czas modyfikacji katalogu
   `.`, który należy do innego użytkownika, i pada z „Operation not permitted”.
4. **Search (Symfony)** działa tylko do odczytu bez zmian, bo cache jest
   rozgrzany w buildzie. Oba warianty, z zabezpieczeniami i bez, kończą się
   identycznie na braku brokera AMQP, czyli dalej niż jakikolwiek błąd systemu plików.
5. **Postgres jako uid 70.** Oficjalny obraz startuje jako root tylko po to, żeby
   zrobić chown i przełączyć się przez gosu. Katalog danych na PVC już należy do 70
   (sprawdzone `ls -ln` w podzie), więc start od razu jako 70 pomija chown.
   Zapisywalne muszą być tylko `/var/run/postgresql` (gniazdo) i `/tmp`. `fsGroup`
   na local-path nic nie robi, bo to hostPath.
6. **Redis** jako 999:1000, bo `/data` na PVC już tak należy. **GlitchTip** już
   działa jako uid 5000. Read-only sprawdziłem z prawdziwą bazą: migracje Django
   i `/_health/` dają 200.

**Wyjątki są jawne** (`EXCEPTIONS` w `tools/k3s/ckad-check.rb`):

- **rabbitmq**: entrypoint pisze `definitions.json` do `/etc/rabbitmq` i robi
  chown, a potem su-exec. Potrzebuje roota, więc zostaje tylko seccomp i zakaz
  eskalacji. G5 zastępuje go operatorem.
- **pg-backup**: root, bo hostPath należy do roota. Root bez capabilities pisze
  tylko tam, gdzie jest właścicielem. Resztę profilu ma.

**Pod Security Admission** na namespace: `warn` i `audit` dla restricted, **bez**
`enforce`. Przy `enforce` rabbitmq, pg-backup i init kontenery ECK nie wstałyby
przy następnym rolloucie. Lista ostrzeżeń to lista długu, a faza `hardening`
pilnuje, żeby nie rosła:

```bash
kubectl label --dry-run=server --overwrite ns marketplace pod-security.kubernetes.io/enforce=restricted
# Warning: rabbitmq-0: allowPrivilegeEscalation != false ...  <- tylko znane wyjątki
```

## Krok 2 — ServiceAccount bez tokena i jedno konto z Role

Żaden pod w marketplace nie rozmawia z API Kubernetesa, a każdy miał token
w `/var/run/secrets/kubernetes.io/serviceaccount/token`. Teraz wygląda to tak:

```yaml
apiVersion: v1
kind: ServiceAccount
metadata: { name: catalog }
automountServiceAccountToken: false
```

`automountServiceAccountToken: false` jest i na koncie, i w podzie. Na koncie
działa dla każdego przyszłego poda. Pole w podzie wygrywa, więc nikt nie włączy
tokena przez przypadek, kopiując szablon.

Osobne konta per aplikacja, choć żadne nie ma uprawnień. RBAC przypina się do
konta, a w logu audytu widać `system:serviceaccount:marketplace:catalog`, a nie
anonimowe `default`.

**Role z prawdziwym powodem** dostało konto `deployer` (`deploy/k8s/rbac.yaml`):
dokładnie to, czego potrzebuje `tools/k3s/deploy.sh`, i tylko w namespace
marketplace. Bez wildcardów, bez `pods/exec`. Dziś deploy używa admina klastra,
a docelowo CI dostanie krótki token: `kubectl -n marketplace create token deployer --duration=1h`.
Sprawdzenie bez tokena (impersonacja):

```bash
kubectl auth can-i patch deployments -n marketplace --as=system:serviceaccount:marketplace:deployer   # yes
kubectl auth can-i create pods/exec -n marketplace --as=system:serviceaccount:marketplace:deployer    # no
kubectl auth can-i list secrets -n kube-system --as=system:serviceaccount:marketplace:deployer        # no
```

Uczciwie: Role ma `secrets`, bo deploy je wdraża (`es-app-roles` z
secretGeneratora). Kto może wdrażać do namespace, ten widzi jego sekrety.
Granicą jest namespace.

## Krok 3 — startupProbe zamiast initialDelaySeconds

`initialDelaySeconds` to zgadywanie: albo za długo (zdrowy pod czeka 30 s,
zanim dostanie ruch), albo za krótko (GlitchTip przy pierwszym starcie migruje
bazę minutami, a liveness zabija go w połowie). startupProbe blokuje liveness
i readiness, dopóki raz nie przejdzie:

```yaml
startupProbe:
  httpGet: { path: /up, port: 80 }
  periodSeconds: 2
  failureThreshold: 30      # budżet 60 s, ale gotowy po pierwszym udanym /up (~3 s)
livenessProbe:
  httpGet: { path: /up, port: 80 }
  periodSeconds: 15         # bez initialDelaySeconds
```

catalog-app ma budżet 60 s, GlitchTip 10 min (migracje), RabbitMQ 5 min. Przy
HPA to ma znaczenie: nowy pod przyjmuje ruch po ~3 s, a nie po sztywnych 30.

## Krok 4 — NetworkPolicy i test, który liczy polityki bez klastra

Kontroler polityk jest wbudowany w k3s (kube-router), nic nie trzeba instalować.
Model w `deploy/k8s/networkpolicies.yaml`:

1. **default-deny**: pusty `podSelector`, `policyTypes: [Ingress, Egress]`, zero reguł;
2. **allow-dns**: egress do CoreDNS (UDP i TCP 53). Bez tego `postgres` się nie
   rozwiązuje i wszystko wygląda na awarię bazy;
3. **allow-egress-in-namespace**: wychodzić wolno tylko do podów sklepu;
4. **polityka per serwer**: kto może do niego mówić i na który port.

Dlaczego egress jest szeroki, a ingress precyzyjny: opis każdej trasy dwa razy
(egress klienta i ingress serwera) to dwa miejsca do pilnowania przy każdej
zmianie. Ingress serwera decyduje, czy połączenie dojdzie. Egress zamyka to,
co przy włamaniu groźne: internet i inne namespace.

**Zasada list klientów: kto ma hasło do usługi, ten ma do niej trasę.** Tabela
powstała z env w manifestach:

| Serwer:port | Klienci |
|---|---|
| postgres:5432 | catalog-app, outbox, worker, scheduler, migrate, search-consumer, search-metrics, glitchtip (+ 2 Joby), pg-backup |
| redis:6379 | catalog-app, outbox, worker, scheduler |
| rabbitmq:5672 | catalog-app, outbox, search-consumer |
| ES:9200 | catalog-app, outbox, migrate, search-consumer, Kibana, ES, **operator ECK** (elastic-system) |
| catalog-app:80 | namespace `ingress`, search-consumer (projekcja), catalog-worker (webhook) |
| porty metryk | namespace `monitoring` |

Rozpisując tę tabelę, znalazłem błąd z ETAPU F (RUNBOOK #085). Worker wysyła
webhook płatności, ale na k3s nie miał ustawionego URL-a, więc wysyłał na swój
localhost. Na produkcji nie było jeszcze zamówień, więc nikt tego nie zauważył.
Rozpisanie przepływów okazało się przy okazji audytem konfiguracji.

Trzy rzeczy, które łatwo przeoczyć:

- **ECK musi dosięgać ES** (`namespaceSelector: elastic-system`). Bez tego ES
  „działa”, ale operator widzi go jako niedostępny i wstrzymuje każdą zmianę spec;
- `namespaceSelector` + `podSelector` **w jednym elemencie listy** = AND
  (CoreDNS w kube-system). W dwóch osobnych elementach = OR (każdy pod
  w kube-system ORAZ każdy pod kube-dns gdziekolwiek);
- `port-forward` i sondy kubeleta polityk nie podlegają: kubelet łączy się
  z wnętrza przestrzeni sieciowej poda, a kube-router przepuszcza ruch z adresu węzła.

**Test bez klastra.** `tools/k3s/ckad-check.rb` ma mały ewaluator semantyki
NetworkPolicy. Ruch przechodzi, gdy pozwala Egress nadawcy i Ingress odbiorcy.
Pod niewybrany przez żadną politykę danego typu nie ma ograniczeń, a polityki się
sumują. Na tym ewaluatorze sprawdzane są:

- ponad 60 tras, które **mają** działać (tabela `ALLOWED`);
- 14 tras, których ma **nie** być (`DENIED`, każda z powodem), np. search-consumer do
  Redisa, obcy pod w namespace do Postgresa, catalog-app do internetu, generator
  obciążenia z innego namespace na skróty do catalog-app;
- każdy port każdego Service ma choć jednego dozwolonego klienta. Ten test
  od razu złapał UI RabbitMQ (15672), który celowo jest dostępny tylko przez
  port-forward i trafił na jawną listę.

Czy test łapie błąd? Usunąłem search-consumer z listy klientów Postgresa
i test od razu dał `FAIL|NetworkPolicy pozwala: search-consumer -> postgres:5432`.

Na klastrze faza `netpol` robi to samo na żywych podach. Z wnętrza poda
`php -r 'fsockopen(...)'` daje OPEN albo BLOCKED (timeout 3 s), a obcy pod
z busyboxem sprawdza, że DNS działa i baza jest zamknięta.

## Krok 5 — Ingress: Traefik, cert-manager i własne CA

**Traefik zamiast ingress-nginx z planu** (RUNBOOK #087), bo ingress-nginx jest
wycofany. Routing przez **standardowy** `networking.k8s.io/v1 Ingress`, nie przez
CRD Traefika, więc manifesty nie zależą od kontrolera.

**Nic na publicznym adresie.** Service Traefika jest typu ClusterIP. Adres
10.43.x.x istnieje tylko w klastrze, ale węzeł go zna, bo kube-proxy przekierowuje
też ruch z hosta. Tunel SSH: `localhost:8443` na Macu → `<ClusterIP>:443` na VPS.
Port 8443, bo 443 na Macu zajmuje Docker innego projektu.

```bash
make k3s-ingress          # cert-manager, Traefik, CA, certyfikaty, Ingressy
make k3s-ingress-tunnel   # tunel + zapis CA do ~/.kube/marketplace-ca.crt
# jednorazowo (sudo, sam):
echo "127.0.0.1 shop.marketplace.test grafana.marketplace.test kibana.marketplace.test" | sudo tee -a /etc/hosts
sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain ~/.kube/marketplace-ca.crt
```

**CA w trzech krokach** (`deploy/ingress/issuers.yaml`):

1. `ClusterIssuer selfsigned`;
2. `Certificate marketplace-ca` z `isCA: true`, podpisany przez (1), klucz
   w `cert-manager/marketplace-ca`;
3. `ClusterIssuer marketplace-ca` typu `ca:`, który wystawia certyfikaty hostów.

Przeglądarce ufasz raz, certyfikatowi CA, a nie każdemu hostowi po każdym
odnowieniu. Secret z certyfikatem musi być w tym samym namespace co Ingress,
dlatego są dwa Certificate (marketplace i monitoring) z jednego ClusterIssuera.

**Laravel za proxy.** Traefik kończy TLS i przekazuje HTTP z `X-Forwarded-Proto: https`.
Bez zaufania do proxy Laravel generuje linki `http://` na stronie otwartej przez
https, co daje mieszaną treść i pętle przekierowań. `bootstrap/app.php` czyta
`TRUSTED_PROXIES`:

- na k3s to sieć podów 10.42.0.0/16. Jest to bezpieczne, bo do catalog-app:80
  dochodzi tylko namespace `ingress` i dwa pody sklepu (NetworkPolicy), a Traefik
  nadpisuje `X-Forwarded-For` klienta;
- w Compose zmienna jest pusta, więc nikt nie jest zaufany i klient nie podszyje
  się pod cudzy IP (limit żądań w tunelu błędów liczy po IP).

Test: `apps/catalog/tests/Feature/TrustedProxiesTest.php` (trzy przypadki, napisane
przed zmianą). Zmiana w bootstrapie, więc wchodzi z nowym obrazem catalog.

## Krok 6 — HPA

```yaml
apiVersion: autoscaling/v2
kind: HorizontalPodAutoscaler
spec:
  scaleTargetRef: { apiVersion: apps/v1, kind: Deployment, name: catalog-app }
  minReplicas: 1
  maxReplicas: 3
  metrics:
    - type: Resource
      resource: { name: cpu, target: { type: Utilization, averageUtilization: 70 } }
  behavior:
    scaleDown:
      stabilizationWindowSeconds: 300
      policies: [{ type: Pods, value: 1, periodSeconds: 60 }]
```

- 70% liczy się od **requestu** (100m), czyli 70m na pod. Bez `requests.cpu`
  HPA pokazuje `<unknown>/70%` i nic nie robi;
- **z Deploymentu zniknęło `replicas`** (RUNBOOK #083). Inaczej każdy
  `make k3s-deploy` cofałby HPA do 1. Jedno pole, jeden właściciel;
- skalowanie w dół najwyżej o 1 pod na minutę i dopiero po 5 min niższych
  rekomendacji. Krótki dołek między falami ruchu nie zabija podów;
- tylko catalog-app. Worker kolejki musi mieć 1 replikę (kolejność FIFO,
  komentarz w catalog.yaml), a konsumentów skaluje się po długości kolejki
  (KEDA), nie po CPU.

Test (faza `hpa`): w osobnym namespace `loadtest` pod z
`curl --parallel --parallel-max 24` uderza w `/search?q=verify[1-200000]`
**przez Ingress**, czyli przez Service Traefika z nagłówkiem hosta. Bezpośrednio do
catalog-app nie dojdzie, bo NetworkPolicy go nie wpuści (to też jest w DENIED).
Faza czeka, aż HPA zechce ≥2 replik i będą gotowe. W Grafanie widać to jako
`kube_horizontalpodautoscaler_status_desired_replicas{horizontalpodautoscaler="catalog-app"}`.

## Krok 7 — canary za tym samym Service

Service catalog-app wybiera pody po `app: catalog-app`. Deploymenty mają
**rozłączne** selektory: stabilny `{app, track: stable}`, canary `{app, track: canary}`.
Service trafia do obu, a każdy Deployment widzi tylko swoje pody.

```bash
make k3s-canary tag=<SHA>     # 1 pod nowej wersji; udział ~1/(N+1) połączeń
make k3s-canary-status        # pody po track, PromQL do porównania
make k3s-canary-abort         # usuń canary
make k3s-deploy tag=<SHA>     # promocja: zwykłe wdrożenie (deploy.sh usuwa canary)
```

- **Canary z żywego Deploymentu** (`tools/k3s/canary-render.rb`), a nie plik
  w repo. Kopia 150 linii env i sond rozjechałaby się z oryginałem przy pierwszej
  zmianie. Z żywego obiektu, bo nazwa ConfigMapy ma hash treści, a deploy.sh
  renderuje z podmienionym `SENTRY_RELEASE`. Render z repo wskazywałby nieistniejącą ConfigMapę;
- `SENTRY_RELEASE` = tag canary, więc wyjątki z canary są w GlitchTipie osobno;
- ServiceMonitor ma `podTargetLabels: [track]`, więc porównanie to jedna linijka:
  `sum by (track) (rate(caddy_http_request_duration_seconds_count{job="caddy",server!="metrics",code=~"5.."}[5m]))`;
- **zmiana selektora istniejącego Deploymentu jest zabroniona** (RUNBOOK #084).
  deploy.sh jednorazowo robi `delete --cascade=orphan`: pody zostają i obsługują
  ruch, nowy Deployment ich nie przejmuje, a po rolloucie stary ReplicaSet znika.

**Ograniczenia, które trzeba znać:** podział ruchu zależy od liczby podów (bez
wag w Ingressie i bez service mesha). Canary nie obsługuje migracji (dzieli bazę,
Job migrate działa przy promocji) ani zmian kontraktu projekcji (search-consumer
zostaje przy starej wersji).

---

## Wdrożenie i weryfikacja

Kolejność, z Maca (tunel startuje sam):

```bash
# 0. jednorazowo, sekret webhooka płatności (RUNBOOK #085) — na serwerze, wartość nie wychodzi z hosta:
ssh elastic-vps 'grep -q "^FAKE_GATEWAY_WEBHOOK_SECRET=." /opt/marketplace/.env || echo "FAKE_GATEWAY_WEBHOOK_SECRET=$(openssl rand -hex 32)" >> /opt/marketplace/.env'
scp tools/k3s/secrets.sh elastic-vps:/tmp/ && ssh elastic-vps 'bash /tmp/secrets.sh'
```

```bash
make k3s-ingress        # 1. cert-manager + Traefik + CA + Ingressy
```

```bash
make k3s-monitoring     # 2. podTargetLabels [track] w ServiceMonitorze, root_url Grafany
```

```bash
make k3s-deploy         # 3. securityContext, NetworkPolicy, HPA, migracja selektora (restart wszystkich podów)
```

```bash
make k3s-verify faza=g2 # 4. hardening, netpol, ingress, hpa, canary
```

```bash
make k3s-verify faza=exposure   # 5. 80/443 dalej zamknięte z zewnątrz
```

Uwagi do kroku 3:

- `postgres-0`, `redis-0` i `rabbitmq-0` się zrestartują (StatefulSet z jedną
  repliką, ~30 s przerwy w zapisie). Zdarzenia czekają w RabbitMQ.
- Sprawdzenie „Laravel generuje linki https” w fazie `ingress` przejdzie dopiero
  na obrazie catalog zbudowanym po merge (`TRUSTED_PROXIES` jest w kodzie).

*(Wyniki z klastra dopiszę po wdrożeniu.)*

## Ile to kosztuje

| Składnik | requests pamięci |
|---|---|
| Traefik | 64Mi |
| cert-manager (3 pody) | 120Mi |
| init kontenery catalog (4 × 16Mi, tylko przy starcie) | — |
| HPA: do 2 dodatkowych podów catalog-app | 0–256Mi |
| **Razem** | ~185Mi stale, do ~440Mi w szczycie |

Węzeł po G1 miał 48% zajętej pamięci (11,5 Gi z 24).

## Pułapki

1. `exec: … Operation not permitted` po `drop: [ALL]`, gdy binarka ma file
   capabilities (#086).
2. `replicas` w manifeście walczy z HPA (#083).
3. Selektor Deploymentu jest niezmienny, więc `--cascade=orphan` (#084).
4. `cp -a` do emptyDir: „preserving times … Operation not permitted”. Wystarczy `cp -R`.
5. Skrypty w CI: `kubectl kustomize` renderuje Ingressy i Certificate bez CRD,
   ale `apply` już nie. Dlatego `deploy/ingress` ma osobny skrypt, który najpierw
   instaluje cert-manager.
6. Traefik chart: klucz to `accessLog`, nie `logs.access`, a `to: websecure`
   renderuje się jako `:443`. Oba złapał `helm template` w `ingress-check.sh`.

## Co z tego na CKAD

- **securityContext**: różnica między poziomem poda a kontenera. `runAsNonRoot`
  bez `runAsUser` przy obrazie z nazwanym użytkownikiem. `capabilities.add` po `drop: [ALL]`.
- **Init kontener** przygotowujący dane dla głównego (emptyDir współdzielony).
- **ServiceAccount**: `automountServiceAccountToken`, `kubectl create token`,
  `kubectl auth can-i --as=system:serviceaccount:<ns>:<sa>`.
- **Sondy**: startup vs liveness vs readiness, i czemu startup zastępuje `initialDelaySeconds`.
- **NetworkPolicy**: default-deny, DNS, AND/OR w `from`, `namespaceSelector`
  po `kubernetes.io/metadata.name`.
- **Ingress**: `ingressClassName`, `tls.secretName` w tym samym namespace, `pathType`.
- **HPA**: `autoscaling/v2`, `behavior`, zależność od `requests`.
- **Deployment**: niezmienny selektor, `--cascade=orphan`, canary przez etykiety.

Szybkie ćwiczenia na tym klastrze będą w G3 (`docs/ckad/`).
