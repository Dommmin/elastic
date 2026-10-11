# Ten sam stack w Kubernetesie: k3s, operator ECK i przełączenie bez utraty danych

*ETAP D2 projektu Marketplace Search Platform. Ten sam system co w D1 (te same
obrazy z GHCR, te same dane) przeniesiony z Docker Compose do Kubernetesa na
tym samym serwerze, obok działającej wersji z Compose, z przełączeniem i drogą
powrotu. Celem nie jest „Kubernetes, bo modny”, tylko odpowiedź na pytanie,
co on daje, a co zabiera, na systemie, który znasz.*

> **Stan:** zrobione na serwerze. `make k3s-verify` → 82/82, restart serwera →
> wszystko samo w 178 s, migracja z Compose bez utraty danych (eval identyczny),
> powrót do Compose przećwiczony. Plan: [`docs/09-PLAN-ETAP-D2-K3S.md`](../09-PLAN-ETAP-D2-K3S.md).

---

## Spis treści

1. [k3s to Kubernetes](#1-k3s-to-kubernetes)
2. [Mapa: Compose → Kubernetes](#2-mapa-compose--kubernetes)
3. [Krok 1 — k3s obok Dockera](#krok-1--k3s-obok-dockera)
4. [Krok 2 — Operator ECK](#krok-2--operator-eck)
5. [Krok 3 — Sekrety i konfiguracja](#krok-3--sekrety-i-konfiguracja)
6. [Krok 4 — Elasticsearch i Kibana przez operator](#krok-4--elasticsearch-i-kibana-przez-operator)
7. [Krok 5 — Bazy jako StatefulSety](#krok-5--bazy-jako-statefulsety)
8. [Krok 6 — Aplikacje, Job migracji, wdrożenie](#krok-6--aplikacje-job-migracji-wdrożenie)
9. [Krok 7 — Migracja danych: blue → green](#krok-7--migracja-danych-blue--green)
10. [Krok 8 — Backupy: CronJob zamiast timera](#krok-8--backupy-cronjob-zamiast-timera)
11. [Krok 9 — Przełączenie, restart, droga powrotu](#krok-9--przełączenie-restart-droga-powrotu)
12. [Bilans: co Kubernetes dał, a co zabrał](#bilans-co-kubernetes-dał-a-co-zabrał)
13. [Ściąga](#ściąga)
14. [Twoja kolej](#twoja-kolej)

---

## 1. k3s to Kubernetes

k3s nie jest „lżejszą alternatywą Kubernetesa”. To **certyfikowana dystrybucja
Kubernetesa** (CNCF): to samo API, ten sam `kubectl`, te same manifesty, Helm
i operatory. Różnica dotyczy tylko sposobu zbudowania klastra:

| | k3s | kubeadm („vanilla”) |
|---|---|---|
| Instalacja | jedna binarka, jedna komenda | składasz sam: containerd, sieć podów, etcd, storage, ingress |
| RAM samego klastra | ~0,5–1 GB (u nas: proces k3s ~650 MB) | ~1,5–2 GB |
| Czego uczy | **uruchamiania aplikacji** w Kubernetesie | budowy i utrzymania samego klastra (CKA) |

Wybraliśmy k3s, bo na 24 GB mamy 3 węzły ES i chcemy ćwiczyć workloady.
Wszystko, co jest w tym przewodniku, działa 1:1 na EKS, GKE czy AKS.

Druga decyzja: **blue-green**. k3s stanął *obok* działającego Compose (D1),
dane zostały skopiowane, a przełączenie nastąpiło dopiero po weryfikacji.
Compose został zatrzymany, ale nie usunięty, więc droga powrotu to jedna komenda.

---

## 2. Mapa: Compose → Kubernetes

To jest najważniejsza tabela tego etapu. Każda linijka `compose.yaml` ma
odpowiednik, a różnice mówią, co robi za nas Kubernetes.

| W Compose (D1) | W Kubernetesie (D2) | Co się zmienia |
|---|---|---|
| `services: catalog-app:` | `Deployment` + `Service` | Deployment pilnuje liczby kopii i robi rolling update; Service daje stały adres DNS |
| `services: postgres:` + wolumen `pg-data` | `StatefulSet` + `volumeClaimTemplates` + headless `Service` | stała nazwa poda (`postgres-0`), PVC przypięty do *tego* poda |
| `es01`, `es02`, `es03` + `es-init` + `es-setup` | jeden zasób `Elasticsearch` (ECK) z `count: 3` | operator robi certyfikaty, hasła, kolejność startu, rolling restart |
| `environment:` | `ConfigMap` (`config.env`) + `Secret` | konfiguracja i sekrety osobno; zmiana ConfigMapy = automatyczny restart |
| `.env` z hasłami | `Secret marketplace-secrets` | base64, **nie** szyfrowanie (o tym niżej) |
| `healthcheck:` | `readinessProbe` + `livenessProbe` | dwa różne pytania: „czy kierować ruch” i „czy zrestartować” |
| `depends_on: condition: service_healthy` | brak — aplikacja ma wytrzymać brak zależności, a Kubernetes ją restartuje | zmiana filozofii: samoleczenie zamiast kolejności startu |
| `restart: unless-stopped` | domyślne zachowanie kontrolerów | Deployment i StatefulSet zawsze przywracają zadaną liczbę podów |
| krok „migracje” w `deploy.sh` | `Job migrate` | jednorazowe zadanie z historią i logami |
| timer systemd `pg-backup` | `CronJob pg-backup` | harmonogram i strefa czasowa w manifeście |
| `ports: 127.0.0.1:8080:80` + tunel SSH | `Service` (ClusterIP) + `kubectl port-forward` przez tunel do API | nic nie słucha na hoście |
| `image: …:${IMAGE_TAG}` | `images:` w `kustomization.yaml` | tag w jednym miejscu |
| `docker compose up -d` | `kubectl apply -k deploy/k8s` | deklaratywnie: „ma być tak”, a kontrolery dochodzą do tego stanu |

---

## Krok 1 — k3s obok Dockera

```bash
scp tools/k3s/install-k3s.sh elastic-vps:/tmp/
ssh elastic-vps 'sudo -n sh -c "nohup bash /tmp/install-k3s.sh > /var/log/install-k3s.log 2>&1 < /dev/null &"'
make k3s-verify faza=cluster     # → 9/9
make k3s-verify faza=exposure    # → 14/14
```

`/etc/rancher/k3s/config.yaml`:

```yaml
disable: [traefik, servicelb]     # nic nie otwiera portów 80/443 na węźle
write-kubeconfig-mode: "0640"
write-kubeconfig-group: deploy    # kubectl na serwerze czyta /etc/rancher/k3s/k3s.yaml
```

Wersja przypięta (`v1.36.5+k3s1`, kanał stable), a nie „latest”: aktualizacja
klastra to świadoma operacja, a nie skutek uboczny reinstalacji.

**ufw a sieć podów.** `ufw default deny incoming` zablokowałby ruch *z podów
do hosta*. Pod łączy się z API Kubernetesa przez Service `10.43.0.1`, który
jest przekierowywany (DNAT) na adres węzła. Stąd dwie reguły:
`ufw allow from 10.42.0.0/16` (pody) i `10.43.0.0/16` (usługi). Z internetu
nic się nie otwiera — skan `exposure` z Maca obejmuje teraz też 6443 (API),
10250 (kubelet) i zakres NodePortów 30000–32767.

### Błąd w planie, złapany przed wdrożeniem (RUNBOOK #036)

Plan zakładał „API tylko na 127.0.0.1” — zasadę z D1 („słuchaj tylko lokalnie”).
Przy rozpisaniu drogi pakietu wyszło, że to odcięłoby pody od API: pakiet
z poda trafia na *adres węzła*, a „127.0.0.1” w podzie to **loopback poda**,
nie hosta. API słucha więc na interfejsie węzła, a z internetu zamyka je ufw.
Lekcja: „localhost” znaczy coś innego po każdej stronie granicy network namespace'u.

### kubectl z Maca

```bash
brew install kubernetes-cli       # 1.37; stary z /usr/local/bin (1.33) jest za stary
make k3s-tunnel                   # tunel SSH: localhost:26443 -> serwer 127.0.0.1:6443
export KUBECONFIG=~/.kube/elastic-vps.yaml
kubectl get nodes                 # albo: k9s
```

Kubernetes gwarantuje zgodność `kubectl` z serwerem ±1 wersja minor — 1.33
przy serwerze 1.36 potrafi się sypać w nieoczywisty sposób.

### Dwa fałszywie zielone testy

Pierwsza wersja `verify.sh` zaliczała „Traefik wyłączony” **zanim klaster
istniał**: `kubectl` zwracał pusto, `grep -c traefik` → 0 → ✓. Potem odwrotnie:
`grep -c` przy zerze trafień kończy się kodem 1, więc `|| echo 'brak API'`
dopisywało drugą linię. Zasada dla testów: **brak danych to nie jest zero** —
test musi odróżnić „sprawdziłem i nie ma” od „nie dało się sprawdzić”.

---

## Krok 2 — Operator ECK

**Operator** to program działający *w* klastrze, który zna dziedzinę (tu:
Elasticsearch) i pilnuje zasobów własnego typu. Piszesz „chcę ES 9.5.1 z 3
węzłami”, a operator robi resztę: StatefulSet, Service'y, certyfikaty, hasło
`elastic`, kolejność startu, rolling restart przy zmianie.

```bash
make k3s-verify faza=eck   # → 5/5
```

Manifesty ECK 3.5.0 leżą w repo (`deploy/k8s/vendor/eck-3.5.0/`), a nie są
pobierane z internetu przy instalacji. Repo jest źródłem prawdy. Definicje CRD
(~800 KB) nakładamy przez `kubectl apply --server-side`: zwykłe `apply` zapisuje
całą treść w adnotacji `last-applied-configuration`, która ma limit 256 KB.

---

## Krok 3 — Sekrety i konfiguracja

```bash
scp tools/k3s/secrets.sh elastic-vps:/tmp/ && ssh elastic-vps 'bash /tmp/secrets.sh'
make k3s-verify faza=config   # → 8/8
```

- **Te same hasła co w D1.** `secrets.sh` czyta `/opt/marketplace/.env` na
  serwerze. Dzięki temu dane z Compose można przenieść bez zmiany haseł w bazach.
  Test porównuje skróty SHA-256 *po stronie serwera* — wartość nie opuszcza serwera.
- **Secret to base64, a nie szyfrowanie.** Każdy z prawem `get secrets`
  w namespace widzi hasła. Na klastrze jednoosobowym to OK. W zespole dochodzi
  RBAC, szyfrowanie etcd w spoczynku i np. Sealed Secrets albo SOPS.
- **ConfigMap z hashem w nazwie** (`marketplace-config-chc67gcc6b`).
  Kustomize dokleja skrót treści, więc zmiana `config.env` daje nową nazwę,
  a Deploymenty, które jej używają, same robią rolling restart. W Compose
  trzeba było pamiętać o odtworzeniu kontenera.
- **Role ES jako plik w repo** (`es-roles.yml`). Przeniesione 1:1
  z `setup-security.sh`, który w D1 tworzył je przez API. ECK przyjmuje role
  tylko z Secretu, a użytkowników aplikacji z Secretów typu `basic-auth`
  (file realm).

---

## Krok 4 — Elasticsearch i Kibana przez operator

`deploy/k8s/elasticsearch.yaml` — cała konfiguracja klastra, który w D1 zajmował
5 usług compose i 2 skrypty:

```yaml
kind: Elasticsearch
spec:
  version: 9.5.1
  image: ghcr.io/dommmin/elastic-elasticsearch:<tag>   # obraz z D1: pluginy PL
  http: { tls: { selfSignedCertificate: { disabled: true } } }   # parytet z D1
  auth:
    roles:     [{ secretName: es-app-roles }]
    fileRealm: [{ secretName: es-user-catalog }, { secretName: es-user-searchsvc }]
  nodeSets:
    - name: default
      count: 3
      config: { path.repo: ["/snapshots"] }
      # podTemplate: heap, limity pamięci, wspólny PVC na snapshoty
      # volumeClaimTemplates: 10 Gi na node (local-path)
```

```bash
make k3s-verify faza=es   # → 13/13: green, 3 nody, pluginy stempel+icu na każdym,
                          #   "butów" -> "but", catalog dostaje 403 przy zapisie
```

**Pułapka Kustomize: obraz w CRD.** Kustomize podmienia tagi w polach,
które zna (`containers[].image` w Podach, Deploymentach…). `spec.image`
w zasobie `Elasticsearch` jest dla niego nieznany, więc **po cichu** zostawiłby
`placeholder`. Naprawa: `kustomizeconfig.yaml` z `images: [{path: spec/image,
kind: Elasticsearch}]`. Test sprawdza tag na podach, a nie w manifeście.

**Burza startowa (RUNBOOK #037).** Po `apply` serwer przestał odpowiadać na
SSH na ~25 minut. Zero zabitych procesów, pamięci wystarczyło. Na 4 vCPU
startowały naraz 3 nowe JVM-y ES i Kibana, obok 3 działających JVM-ów D1.
Sprawdzenie `journalctl -k | grep -i oom` od razu odróżnia „zabrakło pamięci”
od „zabrakło CPU i I/O”. Na małej maszynie startuj ES i Kibanę osobno.

**Liczby, nie nazwy.** Katalogi PVC Elasticsearcha na hoście „należą” do
grupy `deploy`. To nie uprawnienie, tylko zbieżność: ECK ustawia `fsGroup: 1000`,
a na hoście GID 1000 ma akurat `deploy`. Dla jądra właściciel to liczba;
nazwa to tylko etykieta z `/etc/group` *tego* systemu.

---

## Krok 5 — Bazy jako StatefulSety

Postgres, RabbitMQ i Redis to zwykłe `StatefulSet`y z obrazami z D1. Bez
operatorów, żeby zobaczyć „gołe” prymitywy.

```bash
make k3s-verify faza=data   # → 14/14
```

- **Headless Service** (`clusterIP: None`): DNS `postgres` wskazuje wprost
  na pod, bez wirtualnego IP. Typowe dla baz: połączenia są długie,
  load balancer jest zbędny.
- **`volumeClaimTemplates`**: PVC `data-postgres-0` przypięty do *tego* poda.
  Test: zapis → `kubectl delete pod postgres-0` → StatefulSet tworzy pod od nowa
  z tym samym PVC → zapis jest.
- **Stała nazwa węzła RabbitMQ.** W compose trzeba było ustawić `hostname:
  rabbitmq`, bo inaczej po restarcie węzeł zmieniał nazwę i gubił trwałe
  kolejki. StatefulSet daje `rabbitmq-0` za darmo. Dokładnie po to ten typ
  zasobu powstał.
- **Readiness ≠ liveness.** W compose był jeden `healthcheck`. Tu są dwa
  pytania: „czy kierować do mnie ruch” (readiness, często) i „czy mnie
  zrestartować” (liveness, ostrożnie, z dłuższym startem). RabbitMQ ma sondę
  TCP zamiast `rabbitmq-diagnostics`, bo CLI w trakcie bootu kładło kontener
  (RUNBOOK #033).

### Mini-awaria, którą wywołał test

Usunięcie `postgres-0` miało skutek uboczny: **headless Service bez gotowego
poda nie ma rekordu DNS**. Przez kilka sekund nazwa `postgres` w ogóle nie
istniała, więc outbox-publisher padł na `could not translate host name
"postgres"`. Kubelet restartował go z rosnącym odstępem (back-off). Po 74 s
wszystko wróciło **bez żadnej interwencji**. Test mierzy to teraz jawnie:
„aplikacje same wróciły po restarcie bazy (74 s)”.

To jest zmiana filozofii względem Compose. Tam `depends_on: service_healthy`
pilnowało kolejności startu. Tu kolejności nie ma: aplikacja padnie, zostanie
zrestartowana i w końcu trafi na gotową bazę. Działa, ale kosztem restartów
i opóźnień. Aplikacja, która sama ponawia połączenie, wróciłaby szybciej.

---

## Krok 6 — Aplikacje, Job migracji, wdrożenie

```bash
make k3s-deploy                  # ostatni udany build CI
make k3s-deploy tag=<SHA>        # konkretna wersja / rollback
make k3s-verify faza=apps        # → 10/10
```

Zasada z D1 „schemat przed kodem”, tutaj wyrażona etykietami
`app.kubernetes.io/component`:

```
1. infrastruktura (bez etykiety)   kubectl apply -l 'component notin (app,migrate)'
   → czekaj: StatefulSety gotowe, ES: observedGeneration == generation, Ready, green
2. Job migrate                     usuń stary → utwórz nowy → czekaj na Complete
   initContainers PO KOLEI: Laravel migrate → Doctrine migrate → konfiguracja backupu ES
   kontener: indeks + alias, tylko gdy aliasu nie ma
3. aplikacje (component=app)       → czekaj na rollout 3 Deploymentów
```

**Job jest niezmienny.** `kubectl apply` z nowym obrazem w istniejącym Jobie
kończy się błędem, więc deploy go usuwa i tworzy od nowa.

**Service musi nazywać się `catalog-app`.** Caddyfile w obrazie ma blok
`http://catalog-app` dla wewnętrznego API projekcji (search → catalog). Inna
nazwa Service'u oznaczałaby, że Host się nie zgadza, a odpowiedź to 404.

**Kotwice YAML nie przechodzą przez `---`.** `env: &catalog-env` w jednym
dokumencie i `env: *catalog-env` w drugim kończy się błędem `unknown anchor`.
Każdy dokument YAML jest osobny.

### Rolling restart przez ECK i stan RED (RUNBOOK #038)

Pierwszy deploy z nowym tagiem (nowy obraz ES), obserwowany co 10 s:

```
  1s: yellow ApplyingChanges  zaktualizowane=1/3
113s: red    ApplyingChanges  zaktualizowane=2/3     ← RED
306s: green  Ready            zaktualizowane=3/3
```

ECK restartuje węzły po jednym, ale `products-v1` ma **0 replik**. Węzeł
z jedynym shardem leży, więc indeks jest niedostępny. **Rolling restart chroni
tylko dane z replikami.** W D1 (restart całego klastra naraz) było tak samo,
tylko mniej widać. Ćwiczenie w sekcji „Twoja kolej”.

Drugi błąd z tego samego przebiegu: deploy czekał na „green” i dostał go
*z poprzedniego stanu*, bo ECK jeszcze nie zaczął zmian. Aplikacje wdrażały się
w trakcie restartu ES. Teraz deploy czeka, aż operator potwierdzi przetworzenie
nowej specyfikacji: `.status.observedGeneration == .metadata.generation`,
a do tego `phase: Ready` i `health: green`.

---

## Krok 7 — Migracja danych: blue → green

```bash
scp tools/k3s/migrate-from-compose.sh elastic-vps:/tmp/ && ssh elastic-vps 'bash /tmp/migrate-from-compose.sh'
make k3s-verify faza=stack   # → 5/5
```

1. W k8s zatrzymani są ci, którzy **piszą** (publisher, consumer). Zdarzenie
   w trakcie restore'u utworzyłoby indeks `products-search` z automatycznym
   mapowaniem.
2. **Postgres:** świeży `pg_dump -Fc` z Compose → `pg_restore --clean
   --single-transaction` do `postgres-0`. Bez `--no-owner`: użytkownicy
   `catalog` i `searchsvc` istnieją w k8s pod tymi samymi nazwami, więc
   własność obiektów się zgadza.
3. **ES:** snapshot `products-v1` w Compose → kopia plików repozytorium do
   PVC `es-snapshots` → w k8s repozytorium **tylko do odczytu** (dwa klastry
   piszące do jednego repo fs to przepis na uszkodzone metadane) → restore
   z aliasem.
4. Wznowienie piszących.

Wynik: liczby wierszy = Compose, 1500 dokumentów, **eval 0.841 = Compose**
(ten sam indeks, te same segmenty, ta sama kolejność remisów), zmiana ceny
w k8s widoczna w ES po 3 s.

---

## Krok 8 — Backupy: CronJob zamiast timera

```bash
make k3s-verify faza=backup   # → 5/5
```

| | D1 (Compose) | D2 (Kubernetes) |
|---|---|---|
| Konfiguracja snapshotów ES | krok w `deploy.sh` (curl PUT) | init container w `Job migrate` (curl PUT), przy każdym wdrożeniu |
| Dump Postgresa | timer systemd + `pg-backup.sh` | `CronJob pg-backup`: `30 3 * * *`, `timeZone: Europe/Warsaw` |
| Dwa backupy naraz | `Type=oneshot` | `concurrencyPolicy: Forbid` |
| Historia i logi | `journalctl -u marketplace-pg-backup` | `kubectl get jobs`, `kubectl logs job/…` |
| Ręczne uruchomienie | `systemctl start …service` | `kubectl create job reczny --from=cronjob/pg-backup` |
| Gdzie trafiają dumpy | `/var/backups/marketplace` | `hostPath: /var/backups/marketplace-k8s` |

`hostPath` jest świadomym skrótem dla klastra jednowęzłowego: `make vps-backup-pull`
ściąga dumpy tak samo jak w D1. Przy wielu węzłach to byłby PVC na NFS albo S3.

---

## Krok 9 — Przełączenie, restart, droga powrotu

### Przełączenie

```bash
ssh elastic-vps 'cd /opt/marketplace && docker compose stop'           # D1 zatrzymany, wolumeny ZOSTAJĄ
ssh elastic-vps 'sudo systemctl disable --now marketplace-pg-backup.timer'
# heap ES 1g -> 1500m (limit 2Gi -> 3Gi) w elasticsearch.yaml, potem:
make k3s-deploy                                                        # rolling restart ES: 6,5 min
```

### Restart serwera: status, któremu nie wolno ufać (RUNBOOK #039)

Pierwszy test restartu zgłosił „wszystko gotowe po 115 s”. Chwilę później
`search:eval` padł na `No alive nodes`, a Service ES **nie miał endpointów**:
pody ES miały `0/1 Ready`, bo jeszcze startowały. Zasób `Elasticsearch`
pokazywał jednak `green 3`. Operator po restarcie nie zdążył odświeżyć statusu
i podawał **ostatni znany stan sprzed restartu**.

Test sprawdza teraz gotowość samych podów i Deploymentów. Prawdziwe czasy
po restarcie, odczytane z `lastTransitionTime` warunku `Ready`:

```
redis, postgres      33–37 s
aplikacje            56–75 s   (publisher 10 restartów, consumer 6 — czekały na bazę w back-off)
ES 3/3 gotowe        141 s
Kibana = całość      178 s     (D1: 187 s)
eval po restarcie    0.841 = przed
```

`docker compose stop` przeżywa restart serwera: `restart: unless-stopped`
nie wznawia kontenerów zatrzymanych ręcznie. D1 poprawnie nie wstał.

### Droga powrotu do D1

```bash
ssh elastic-vps 'sudo /usr/local/bin/k3s-killall.sh'                # zatrzymuje WSZYSTKIE kontenery k3s
ssh elastic-vps 'cd /opt/marketplace && docker compose start'
make vps-verify faza=stack                                          # D1 działa
# … i z powrotem:
ssh elastic-vps 'cd /opt/marketplace && docker compose stop && sudo systemctl start k3s'
```

`systemctl stop k3s` nie wystarczy: zatrzymuje control plane, ale kontenery
podów działają dalej i zajmują pamięć. Dopiero `k3s-killall.sh` zwalnia wszystko.

Uwaga, której ćwiczenie nie pokazuje: dane w D1 to **kopia z chwili migracji**.
Wszystko, co zapisano w k8s po przełączeniu, w D1 nie istnieje. Prawdziwy
powrót po dniach pracy wymagałby migracji w drugą stronę (ten sam skrypt,
odwrócone kierunki).

Pomiar: powrót do D1 (killall + start + czekanie na zdrowie) trwał łącznie
14 min, przy czym kontenery D1 były healthy już po ~2 min. Pozostałego czasu
nie rozdzieliłem między `k3s-killall.sh` i `compose up --wait` — to otwarte
pytanie do następnego pomiaru.

### Weryfikacja, która sama wisiała

Kilka razy `verify.sh` stawał na kwadrans. Przyczyny po kolei:
- `kubectl exec` przez tunel wisiał na **zestawianiu strumienia**, zanim
  komenda dotarła do poda. Ani `timeout 60` w podzie, ani `--request-timeout`
  tego nie łapią. Pomógł limit czasu po stronie Maca (macOS nie ma `timeout`,
  stąd własne `with_timeout` w bashu).
- `curl` do ES bez `-m` wisiał bez końca.
- `ssh -fN` (tunel) bez `ConnectTimeout` wisiał w trakcie bootu serwera,
  bo TCP już przyjmował połączenia, a sshd jeszcze nie odpowiadał.

- Mój własny `with_timeout` w pierwszej wersji **spowalniał wszystko**:
  strażnik `( sleep 120; kill ) &` dziedziczył stdout podstawienia `$(…)`,
  więc bash czekał na każde `$(k …)` pełne 120 s. Naprawa: strażnik pisze do `/dev/null`.
- `kubectl run -i --rm` w tle nie ma terminala do podłączenia i wisiał do
  limitu. Faza trwała ~10 min, po zmianie na create → wait → logs → delete trwa 9 s.

Zasada: **każde wywołanie sieciowe w automatyzacji ma mieć limit czasu**,
a sam mechanizm limitu trzeba przetestować na szybkim przypadku. Brak limitu
nie objawia się błędem, tylko ciszą (RUNBOOK #039).

---

## Bilans: co Kubernetes dał, a co zabrał

Zmierzone na tym samym serwerze, tych samych obrazach i tych samych danych:

| | D1: Compose | D2: k3s |
|---|---|---|
| RAM całego stacku | 9,9 GB (41%) | 9,5 GB (39%), w tym k3s + containerd ~1,1 GB |
| Restart serwera → całość gotowa | 187 s | 178 s |
| Nowa wersja (nowy obraz ES) | ~3,5 min, restart całego klastra ES naraz | ~2 min dla aplikacji + rolling restart ES węzeł po węźle (5–6,5 min) |
| Zmiana konfiguracji | odtworzenie kontenera ręcznie | ConfigMap z hashem → automatyczny rolling restart |
| Padnięta aplikacja | `restart: unless-stopped` | to samo + readiness odcina ruch, zanim pod będzie gotowy |
| Kolejność startu | `depends_on: service_healthy` | brak — restarty z back-offem aż zależności będą gotowe |
| Elasticsearch: certy, hasła, użytkownicy | 2 kontenery jednorazowe + skrypty | operator i deklaracja w YAML |
| Ilość YAML | 1 plik compose + nakładka | 11 manifestów + operator |
| Debugowanie | `docker compose logs`, `exec` | to samo + warstwa statusów, które potrafią kłamać (#039) |

**Kubernetes dał:** deklaratywność (opisujesz stan, a kontrolery do niego
dochodzą), operator ES (najtrudniejsza część D1 w jednym zasobie), rolling
restart bez ręcznej koreografii, samoleczenie, ConfigMap z automatycznym
wdrożeniem zmian, CronJob i Job jako obiekty z historią.

**Kubernetes zabrał:** prostotę. Doszła cała warstwa pośrednia: API przez
tunel, statusy operatora, etykiety sterujące kolejnością wdrożenia, Kustomize
z pułapkami (CRD, kotwice YAML), sieć podów kontra ufw. Na **jednym** serwerze
większość zalet Kubernetesa (rozpraszanie na węzły, odporność na awarię
maszyny, autoskalowanie) w ogóle nie występuje.

**Kiedy warto:** gdy węzłów jest więcej niż jeden, gdy zespół wdraża wiele
usług, gdy potrzebujesz operatorów (bazy, ES, kolejki) albo gdy firma i tak
ma klaster. **Kiedy nie:** jeden serwer, jedna osoba, kilka usług. Wtedy
Compose z D1 robi to samo przy ułamku złożoności.

---

## Ściąga

| Chcę… | Komenda |
|---|---|
| kubectl / k9s z Maca | `make k3s-tunnel`, potem `export KUBECONFIG=~/.kube/elastic-vps.yaml` |
| szybkie polecenie | `make k3s k="get pods -n marketplace"` |
| wdrożyć wersję / rollback | `make k3s-deploy [tag=<SHA>]` |
| UI aplikacji | `kubectl -n marketplace port-forward svc/catalog-app 38080:80` → http://localhost:38080/search |
| Kibana | `kubectl -n marketplace port-forward svc/marketplace-kb-http 35601:5601` → http://localhost:35601 |
| logi | `kubectl -n marketplace logs deploy/search-consumer -f` |
| stan ES | `kubectl -n marketplace get elasticsearch` (i zawsze: `get pods`) |
| ręczny backup PG | `kubectl -n marketplace create job pg-reczny --from=cronjob/pg-backup` |
| weryfikacja | `make k3s-verify` (bez restartu), `make k3s-verify faza=reboot` (restartuje serwer) |
| awaryjnie wrócić do D1 | `k3s-killall.sh` + `docker compose start` (Krok 9) |

---

## Twoja kolej

1. `make k3s-tunnel`, `export KUBECONFIG=~/.kube/elastic-vps.yaml`, uruchom `k9s`
   i znajdź: pody ES, PVC `data-postgres-0`, CronJob `pg-backup`, ostatni Job `migrate` i jego logi.
2. **Repliki a rolling restart.** Ustaw `number_of_replicas: 1` na `products-v1`:
   ```bash
   kubectl -n marketplace exec marketplace-es-default-0 -- \
     curl -s -u "elastic:$(make -s vps-secret k=ELASTIC_PASSWORD)" -X PUT localhost:9200/products-v1/_settings \
     -H 'Content-Type: application/json' -d '{"index":{"number_of_replicas":1}}'
   ```
   Potem zmień cokolwiek w `podTemplate` ES (np. dodaj zmienną środowiskową), `make k3s-deploy`
   i obserwuj `kubectl get elasticsearch -w`. Czy klaster przechodzi przez RED?
3. **Rollback na dwa sposoby:** `kubectl -n marketplace rollout undo deploy/catalog-app`
   kontra `make k3s-deploy tag=<starszy SHA>`. Sprawdź `X-App-Version` po każdym.
   Czym różnią się te dwa rollbacki? (Podpowiedź: co z ES, Jobem migracji i resztą Deploymentów?)

Pytania kontrolne:

- Dlaczego API k3s nie może słuchać tylko na `127.0.0.1`, skoro w D1 wszystko tak słuchało?
- Co się stanie, jeśli zmienisz `config.env` i zrobisz `kubectl apply -k`? Dlaczego?
- Headless Service a zwykły Service: co widzi aplikacja, gdy pod bazy nie jest gotowy?
- Dlaczego deploy czeka na `observedGeneration`, a nie tylko na `health: green`?
- Na jednym serwerze: co realnie zyskaliśmy względem D1, a co jest tylko nauką na przyszłość?
