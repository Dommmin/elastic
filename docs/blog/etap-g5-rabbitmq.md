# RabbitMQ przez operator, krok po kroku: migracja pod ruchem i zabity lider

*ETAP G5 projektu Marketplace Search Platform. Po G1–G4 sklep działa na k3s z alertami, politykami
sieciowymi i laboratorium awarii. RabbitMQ jest nadal pojedynczym StatefulSetem z jednym węzłem:
padnie jego pod, a zdarzenia z outboxu stoją. Ten etap stawia klaster trzech węzłów z kolejkami
quorum przez operator, przenosi na niego żywy ruch sklepu bez utraty ani jednej wiadomości (dowód
liczbami na obu końcach), zabija lidera pod obciążeniem i pokazuje, czym stream różni się od kolejki.
Wszystko na lokalnym k3d, bo VPS wygasł (a ten etap i tak rusza dane i zabija węzły).*

> **Stan:** gałąź `g5-rabbitmq` (niewypchnięta).
> **Na k3d (2026-11-10):** `make g5-verify` PASS=22 FAIL=0; migracja pod ruchem dała
> `2935 0 2935 1500 0 0` w przebiegu od zera (nic nie zginęło); lider zabity 6 razy (3 × pod, 3 × węzeł), potwierdzone
> utracone: 0; pełny przebieg od zera w [sekcji „Przebieg od zera”](#przebieg-od-zera).
> Projekt: [specyfikacja](../superpowers/specs/2026-11-10-g5-rabbitmq-operator-design.md).
> Pomiary: [`POMIARY.md`](../POMIARY.md) (sekcja „G5 — RabbitMQ”). Plan: [`13-PLAN-ETAP-G-K8S.md`](../13-PLAN-ETAP-G-K8S.md).
> Błędy z drogi: RUNBOOK [#098–#109](../RUNBOOK.md#098).
> **Kolejność scalania:** wpisy #096 i #097, do których odwołują się skrypty (`seed.sh`, `perf-test.sh`) i specyfikacja, pochodzą z PR #17 i #18;
> scal je przed tą gałęzią (#097 istnieje tylko na gałęzi PR #18).

---

## Spis treści

1. [Problem: jeden węzeł to jeden punkt awarii, a migracja to jedyny krok bez cofnięcia](#1-problem)
2. [Mapa plików](#2-mapa-plików)
3. [Krok 1 — środowisko: k3d i przypięta podsieć](#krok-1--środowisko-k3d-i-przypięta-podsieć)
4. [Krok 2 — operatory zamiast własnego StatefulSetu](#krok-2--operatory-zamiast-własnego-statefulsetu)
5. [Krok 3 — topologia jako CR-y i test zgodności](#krok-3--topologia-jako-cr-y-i-test-zgodności)
6. [Krok 4 — NetworkPolicy: cztery rzeczy, które trzeba przepuścić](#krok-4--networkpolicy-cztery-rzeczy-które-trzeba-przepuścić)
7. [Krok 5 — migracja pod ruchem](#krok-5--migracja-pod-ruchem)
8. [Krok 6 — failover: zabity lider](#krok-6--failover-zabity-lider)
9. [Krok 7 — stream](#krok-7--stream)
10. [Krok 8 — pomiary](#krok-8--pomiary)
11. [Przebieg od zera](#przebieg-od-zera)
12. [Odstępstwa od planu](#odstępstwa-od-planu)
13. [Co wybuchło po drodze](#co-wybuchło-po-drodze)
14. [Czego G5 nie robi](#czego-g5-nie-robi)
15. [Ściąga](#ściąga)
16. [Twoja kolej](#twoja-kolej)

---

## 1. Problem

Dzisiejszy RabbitMQ sklepu to jeden pod. Kolejki są trwałe (przeżyją restart), ale w czasie restartu
`outbox-publisher` nie ma gdzie publikować, a `search-consumer` skąd czytać. Klaster trzech węzłów z kolejkami
**quorum** (Raft: wiadomość jest potwierdzona po zapisie na większości replik) naprawia to: ginie jeden
węzeł, a kolejki dalej działają po wyborze nowego lidera.

Tu jest haczyk, dla którego ten podetap jest ostatni: **to jedyny krok G, który rusza dane**. Zdarzenia, które
leżą dziś w kolejkach, muszą przejść na nowy klaster, a sklep przez ten czas dalej je produkuje.
Reszta etapów G mogła się mylić i dało się cofnąć; tu pomyłka to utrata wiadomości bez śladu w logach.
Dlatego cała konstrukcja jest podporządkowana jednemu pytaniu: **jak udowodnić liczbami, że nic nie zginęło**.

Dwie decyzje wstępne:

- **Operator zamiast własnego StatefulSetu.** Klaster RabbitMQ to nie trzy pody: to formowanie klastra
  (peer discovery), kolejność rolloutu (kolejka quorum nie przeżyje zabicia dwóch węzłów naraz), wtyczki,
  hasła i topologia (kolejki, bindingi, uprawnienia). Cluster Operator robi dwie pierwsze rzeczy, Messaging Topology
  Operator ostatnią, jako zasoby Kubernetesa. Własny StatefulSet to byłby własny operator, bez testów.
- **Lokalne k3d, nie VPS.** VPS wygasł, więc to jedyne środowisko. Etap zabija pody i węzły (`docker stop`) i przenosi produkcyjnie wyglądające dane;
  nawet na żywym serwerze byłoby to ryzyko bez korzyści: wszystko, czego uczy ten etap, widać na
  trzech węzłach k3d. Manifesty są te same; wdrożenie na k3s zostaje na później.

## 2. Mapa plików

```
deploy/k8s/rabbitmq-cluster/
  rabbitmq-ha.yaml        RabbitmqCluster: 3 repliki, 4.3.4, wtyczki (shovel, stream), anty-afinity
  topology.yaml           User, Permission, Exchange, Queue, Binding = definitions.template.json jako CR-y
  stream.yaml             search.analytics.stream (x-queue-type stream, x-max-age 7D) + binding user.#
  perftest.yaml           użytkownik g5-perftest z uprawnieniami tylko do g5.*
  networkpolicies.yaml    egress do API, ingress peerów i klientów (stream, CLI), catalog-app
  migration/shovels.yaml  6 Shoveli (kolejka -> ta sama kolejka), tylko w overlayu g5-local
deploy/k8s/vendor/        eck-3.5.0, rabbitmq-cluster-operator-2.23.2, messaging-topology-operator-1.20.5
deploy/overlays/
  g5-base/                część wspólna (limity, ES w jednym węźle, bez Kibany/HPA/Redisa/workerów)
  g5-local/               g5-base + stary rabbitmq + rabbitmq-ha + Shovele (stan „przed migracją”)
  g5-local-ha/            g5-base + rabbitmq-ha, aplikacje na nowym hoście (stan końcowy)
tools/k3d/
  up.sh down.sh images.sh lib.sh   klaster, sieć, obrazy; kk/kc i pomocnicy w lib.sh
  install-operators.sh             ECK, cert-manager, oba operatory RabbitMQ
  secrets.sh deploy.sh seed.sh     sekrety (idempotentne), wdrożenie overlayu, dane i ruch
  counts.sh migrate.sh             linia „sześciu liczb” i migracja w 6 krokach
  perf-test.sh stream-demo.sh      Job perf-test, demo streamu
  verify.sh measure.sh             make g5-verify, make g5-measure
  g5-check.sh g5-check.rb topology-parity.rb   asercje renderu (bez klastra, w CI)
Makefile                           g5-up g5-down g5-images g5-check g5-deploy g5-migrate g5-counts g5-verify g5-measure
```

Zasada, którą narzędzia egzekwują: **goły `kubectl` jest tylko w `tools/k3d/lib.sh`** (funkcje `kk`, `kc`, które zawsze
dopisują `--context k3d-elastic-g5`; `g5-check.sh` grepuje resztę skryptów). To lekcja z G4: goły `kubectl` na Macu trafia
w pierwszy z brzegu kontekst, czyli w cudzy klaster. Wartości kontekstu spoza `k3d-elastic-g5*` skrypty odrzucają.

## Krok 1 — środowisko: k3d i przypięta podsieć

```bash
make g5-up        # 1 server + 2 agenty, k3s v1.36.5, odmawia przy < 6000 MB wolnych w Docker VM
make g5-images    # catalog, search, postgres, ES, rabbitmq, perf-test: build lokalnie + import do węzłów
```

Trzy węzły są po to, żeby anty-afinity rozłożyła repliki RabbitMQ, a „awaria węzła” (`docker stop` agenta) była
prawdziwym failoverem, nie restartem poda.

**Maszyna jest współdzielona** (inne projekty trzymają swoje kontenery i pamięć), więc `up.sh` przed startem
sprawdza wolną pamięć Docker VM i odmawia poniżej progu, zamiast zacząć i paść w połowie migracji. Compose tego
projektu na czas G5 jest zatrzymany (`make down`). Cudzych kontenerów nikt nie rusza.

Jeden szczegół, który wyszedł dopiero przy przeglądzie: **podsieć Dockera klastra jest przypięta**
(`G5_SUBNET=172.22.0.0/16`). Dlaczego, widać w kroku 4: polityka sieciowa dla API używa `ipBlock`, a k3d
domyślnie losuje podsieć. `up.sh` tworzy sieć sam (`docker network create --subnet`), podaje ją klastrowi
(`--network`), a `down.sh` ją **usuwa jawnie**, bo k3d kasuje tylko sieci, które sam stworzył. Docker odmawia
nakładającej się podsieci, więc kolizja z cudzym projektem to czytelny błąd, nie cicha pomyłka. `deploy.sh` przed
`apply` porównuje `ipBlock` z renderu z żywą podsiecią i odmawia przy różnicy. Ta ścieżka (sieć tworzona i
usuwana) przy implementacji nigdy nie biegła na żywo: sprawdza ją dopiero [przebieg od zera](#przebieg-od-zera).

Obrazy węzłów nie mają dostępu do Docker Huba, więc wszystko jest importowane (`k3d image import`). Pułapka z tego:
komunikat `ctr: content digest ... not found` przy imporcie obrazów wieloarchitekturowych. Czasem obraz mimo to jest,
czasem nie, a k3d kończy mimo to kodem 0 ([RUNBOOK #102](../RUNBOOK.md#102)). Dlatego `images.sh` zapisuje wszystkie obrazy
jako jedną platformę (`docker save --platform linux/arm64`) i na końcu sprawdza **każdy obraz na każdym węźle** (`crictl images`).
Pierwsza wersja tego skryptu „działała”, bo testowałem ją na klastrze, który miał już obrazy z ręcznych importów; wydało się dopiero
na świeżym klastrze.

## Krok 2 — operatory zamiast własnego StatefulSetu

```bash
bash tools/k3d/install-operators.sh   # ECK -> cert-manager -> cluster-operator -> topology-operator
make g5-deploy o=g5-local             # overlay z obecnym RabbitMQ (rabbitmq) i nowym (rabbitmq-ha) obok siebie
```

`deploy.sh` czeka nie tylko na StatefulSety z renderu, ale też na `AllReplicasReady` klastra `rabbitmq-ha` (jego StatefulSet tworzy operator,
więc `rollout status` go nie widzi) i na `Ready=True` wszystkich CR-ów topologii. Gdy `Permission` pojawi się w tej samej sekundzie co
`User`, zaobserwowałem `vhost_or_user_not_found` przy jednej próbie i brak ponowienia przez kilka minut, mimo że użytkownik był już w brokerze.
Najpewniej (hipoteza, nie zbadałem kodu operatora) próba wyprzedziła użytkownika. Skrypt niegotowe CR-y „trąca” zmianą adnotacji, ale to
**obejście, którego konieczności nie udowodniłem**: przebieg od zera po poprawce przeszedł i nie wiem, czy trącenie było w nim potrzebne
([#108](../RUNBOOK.md#108)).

Kolejność ma powód: ECK, bo sklep ma Elasticsearch jako CRD; cert-manager, bo webhook Topology Operatora potrzebuje
certyfikatu; Cluster Operator, potem Topology Operator. Manifesty są **wendorowane** (`deploy/k8s/vendor/`, z adresem
źródła i sha256 w nagłówku), a nie pobierane przy instalacji: instalacja jest powtarzalna i działa bez sieci. Wersje:
[`07-WERSJE.md`](../07-WERSJE.md).

`RabbitmqCluster` (`rabbitmq-ha.yaml`) to jeden obiekt: 3 repliki, obraz `rabbitmq:4.3.4-management` (debianowy,
uid 999: obraz alpine z Compose ma uid 100 i nie pasuje do tego, co wymusza operator), wtyczki `prometheus`, `shovel`, `shovel_management`, `stream`, anty-afinity
(po jednym podzie na węzeł), limit 512 Mi. Operator domyślnie nadaje profil `restricted`-podobny: `runAsNonRoot`,
`drop: [ALL]`, `seccompProfile: RuntimeDefault`, `readOnlyRootFilesystem`. Po wdrożeniu:

```
3 pody na 3 różnych węzłach (server-0@agent-1, server-1@agent-0, server-2@server-0)
cluster_status: 3 Disk Nodes, 3 Running Nodes, RabbitMQ 4.3.4 / Erlang 27.3.4.16
```

Budżet pamięci to osobny temat, bo maszyna jest ciasna. Overlay mierzy **sumę limitów** kontenerów w renderze
(`g5-check.rb`: `g5-local` ≤ 6 Gi, bo w czasie migracji chodzą oba RabbitMQ; `g5-local-ha` ≤ 5 Gi), a realne zużycie
sprawdza `verify.sh` (≤ 5,7 GB wg specyfikacji; **tylko w stanie końcowym**, nie w szczycie migracji, gdy chodzą oba RabbitMQ): pody 2 025 MiB, trzy węzły k3d 3 429 MiB (przebieg od zera). Limity to nie zużycie:
dlatego asercja na limitach jest tania i działa bez klastra, a ta na zużyciu na klastrze.

## Krok 3 — topologia jako CR-y i test zgodności

W Compose topologię ładuje `definitions.template.json` (kolejki, exchange, bindingi, użytkownik). Tu każdy element to
osobny obiekt `Exchange`, `Queue`, `Binding`, `User`, `Permission`: 3 exchange, 6 kolejek quorum z DLX i
`x-delivery-limit: 5`, 14 bindingów (+ stream i jego binding). Dwie konwencje:

- `metadata.name` to nazwa z kropkami zamienionymi na myślniki (nazwa obiektu Kubernetesa), a prawdziwa nazwa idzie
  w `spec.name`;
- typ kolejki w `spec.type` (quorum/stream), **nigdy** w `arguments.x-queue-type` (nadpisałby po cichu).

Zasób `Ready=True` mówi tylko „operator zaaplikował”. Dlatego powstał **test zgodności**,
`tools/k3d/topology-parity.rb`, wołany z `g5-check.sh` dla każdego overlayu z `rabbitmq-ha`: porównuje CR-y z
`definitions.template.json` (nazwy, typy, durable, argumenty, bindingi). Jedyne dozwolone różnice to stream i jego binding.
Przetestowałem go na dryf: `x-delivery-limit` 5 na 6 albo `routingKey` `alert.#` na `alerts.#` w renderze daje czerwony
test, zanim cokolwiek trafi do klastra. To ta sama zasada co zawsze w tym repo: dwa źródła prawdy się rozjeżdżają,
więc jedno sprawdza drugie.

Uprawnienia użytkownika `marketplace` to regexy (configure/write/read) z komentarzem w `topology.yaml`, dlaczego
każdy jest taki, jaki jest: Messenger przy retry **deklaruje** kolejki `delay_*` (configure), wiąże je z
`marketplace.delays` i publikuje do niego, a DLX kolejek `delay_*` to domyślny exchange. Tego nie widać w kodzie
aplikacji, tylko w tym, że bez tych praw retry kończy się `ACCESS_REFUSED`. Sprawdzone na żywo: kolejki
`delay_messages_search.product.sync_*_retry` powstają, w logach brokerów i konsumenta 0 × `ACCESS_REFUSED`.

## Krok 4 — NetworkPolicy: cztery rzeczy, które trzeba przepuścić

Sklep ma `default-deny` (G2), więc klaster RabbitMQ potrzebuje własnych reguł. Cztery, i każda wyszła z błędu:

| Reguła | Po co | Skąd wiem |
|---|---|---|
| **Egress do API**, `ipBlock 172.22.0.0/16`, `6443/TCP` | peer discovery pyta API o endpointy | test połączenia z poda: bez polityki API zamknięte, z polityką otwarte, a kubelet (`10250`) nadal zamknięty ([#100](../RUNBOOK.md#100)) |
| **Peery → peery `6000-6500/TCP`** | replikacja streamu (porty osiris) | CR `Queue` stream miał Ready=True, a replika `connection_refused` ([#101](../RUNBOOK.md#101)) |
| **Peery → peery `35672-35682/TCP`** | `rabbitmqctl` to osobny węzeł Erlanga, polecenia klastrowe łączą się z peerów **zwrotnie** do niego | `list_queues` wisiało na „Some queue(s) are unresponsive” ([#101](../RUNBOOK.md#101)) |
| **`catalog-app` → `5672`** | jego `/health` sprawdza RabbitMQ | po migracji `/health` zgłosił `Connection refused` ([#101](../RUNBOOK.md#101)) |

Dwie rzeczy warto wynieść:

1. **Egress do API wygląda jak „API nie istnieje”**: natychmiastowe `Connection refused` (REJECT), nie timeout. Dlatego test
   połączenia (`bash /dev/tcp/…`) z poda jest tu lepszy niż czytanie logów.
2. **Nie udało mi się odtworzyć awarii przy starcie poda bez polityki.** Skasowany PVC i pod, żeby wymusić peer discovery:
   węzeł dołączył mimo braku polityki. Najpewniej okno startowe kube-routera, w którym świeży pod bywa przez pierwsze sekundy
   poza egzekwowaniem polityk (odwrotność wyścigu kube-routera, który w tym projekcie wywalał Joby tuż po starcie: tam polityka działała za późno, tu świeży pod bywa
   przez chwilę poza nią). To nie dowód, że polityka jest zbędna: uzasadnia ją deterministyczny test
   połączenia, nie test rozruchu. „Działa przy starcie” nie znaczy „nie potrzeba”.

Reguła 1 potrzebuje stałej podsieci: dlatego krok 1 ją przypina, a `g5-check.rb` czyta `G5_SUBNET` z `lib.sh` i porównuje z
`ipBlock` w polityce.

## Krok 5 — migracja pod ruchem

To jest sedno. Stan wyjściowy to overlay `g5-local`: stary `rabbitmq` (1 węzeł) i nowy `rabbitmq-ha` (3 węzły z pustą topologią)
chodzą obok siebie, a sklep produkuje zdarzenia.

```bash
make g5-migrate load=1500     # = bash tools/k3d/migrate.sh --load=1500
make g5-counts                # sześć liczb, na żądanie
make g5-deploy o=g5-local-ha  # stan końcowy jako kod: nic się nie zmienia
```

**Zawsze migruj przed wdrożeniem `g5-local-ha`.** Strażnik w `deploy.sh` (nie kasuje starego RabbitMQ przy niepustych kolejkach) działa po `apply`,
więc `g5-deploy o=g5-local-ha` na stanie sprzed migracji nałożyłby nowy overlay, zanim dane przejdą. Kolejność: `g5-local` → `g5-migrate` → `g5-local-ha`.

**Stary PVC zostaje.** `g5-local-ha` nie usuwa PVC `data-rabbitmq-0` (dane starego brokera zostają celowo, jako ostatnia kopia). Gdy już ich nie
potrzebujesz: `kubectl --context k3d-elastic-g5 -n marketplace delete pvc data-rabbitmq-0`.

### Dowód: jedna linia, sześć liczb

`counts.sh` wypisuje po każdym kroku jedną linię (kontrakt, parsowany po pozycji):

```
outbox_total outbox_unpublished processed_events es_count old_queues_total dlq_total
```

| Liczba | Co znaczy |
|---|---|
| `outbox_total` | wiersze tabeli `outbox` w bazie katalogu (zdarzeń wyprodukowanych) |
| `outbox_unpublished` | z nich `published_at IS NULL` (broker jeszcze nie potwierdził) |
| `processed_events` | ile zdarzeń **z outboxu** ma znacznik w `processed_events` (przecięcie po `event_id`, nie `COUNT(*)`: seed `--fresh` czyści outbox, ale nie bazę search) |
| `es_count` | `_count` aliasu `products-search` w ES po refresh |
| `old_queues_total` | wiadomości (ready + unacked) we **wszystkich** kolejkach starego węzła, też `delay_*` |
| `dlq_total` | kolejki `*.dlq` na obu klastrach |

Warunek końcowy: `outbox_unpublished = 0`, `processed_events = outbox_total`, `es_count` = liczba produktów w bazie
katalogu, `old_queues_total = 0`, `dlq_total = 0`. `processed_events` ma unikalny klucz `(event_id, handler)`, więc równość
oznacza także brak podwójnego przetwarzania (duplikat z okna przełączenia skończyłby jako „Duplikat, pomijam” i nie
podniósłby licznika). `counts.sh` jest **fail-closed**: gdy którejkolwiek liczby nie da się ustalić, kod ≠ 0 i nic na stdout.
Pierwsza wersja przy błędzie API zwracała 0 w miejsce „nie wiem”, czyli dokładnie to, czego dowód nie może robić.

### Sześć kroków

1. **Postaw `rabbitmq-ha` z topologią obok starego.** Już zrobione przez `g5-deploy o=g5-local`.
2. **Wydawca na nowy klaster.** `outbox-publisher` i `catalog-app` dostają host `rabbitmq-ha` **i hasło** użytkownika HA.
   Od tej chwili nowe zdarzenia trafiają tylko do nowego klastra. Dzieje się to przez `kubectl patch --type=json` tymi
   samymi plikami, które są w overlayu końcowym (z operacjami `test`).
3. **Shovel** dla każdej z 6 kolejek starego węzła (`ackMode: on-confirm`, `srcDeleteAfter: queue-length`) przenosi zaległości.
   Cel to **ta sama kolejka** na nowym klastrze (patrz niżej, to najważniejsza zmiana).
4. **Konsument na nowy klaster.** `search-consumer` dostaje `MESSENGER_TRANSPORT_DSN_HA`. Idempotencja przez `ProcessedEvent`
   chroni przed duplikatami z okna przełączenia.
5. **Sprawdzenie** sześciu liczb (zbieżność, z czasem na dojście).
6. **Stan końcowy jako kod.** `kc diff` overlayu `g5-local-ha` (bez Joba `migrate`) ma być **pusty**: przełączenie na żywo
   dało dokładnie to, co jest w repo. Porównanie obejmuje obiekty rdzenia (Deploymenty, Service, ConfigMapy, NetworkPolicy),
   a pomija CR-y operatorów: API dopisuje do nich wartości domyślne przy pierwszej aktualizacji, więc na świeżym klastrze
   różniłyby się od repo bez żadnej zmiany merytorycznej ([#109](../RUNBOOK.md#109)). Luka: CR-y `RabbitmqCluster`, topologii i Elasticsearch **nie są** w kroku 6
porównywane ze stanem na żywo; ich zgodność z repo sprawdza tylko render (`g5-check`, test zgodności topologii).

### Obciążenie

Plan zakładał seed w pętli. Się nie da: bez `--fresh` seed pada na unikalnym slugu kategorii, z `--fresh` robi TRUNCATE
outboxu i kasuje dowód liczenia. Dlatego: `seed --n=1500 --fresh` przed krokiem 2 (1500 zaległych zdarzeń w kolejce) plus
Job `marketplace-traffic` (`seed.sh --traffic`: ciągłe `product.updated` tą samą ścieżką co panel sprzedawcy, do ok. 20 zdarzeń/s: tyle wynika z kodu, `usleep(50000)` w pętli; to górna granica, nie pomiar),
który działa **do po kroku 4**. `migrate.sh` odmawia (die), jeśli ruch nie działał do końca kroku 4. Migracja bez ruchu
niczego nie dowodzi.

### Wyniki z k3d

Przebieg 1 był **czerwony**: `start 200 0 200 200 0 0` → `po kroku 4 1500 0 1075 1080 0 0` → stoi na `1500 0 1498 1498 0 0`.
Dwie wiadomości zniknęły bez błędu. Przyczyna i naprawa niżej ([#103](../RUNBOOK.md#103)). Przebieg 3 (na zakomitowanym kodzie,
po resecie do `g5-local`):

```
start               2990 0 2990 1500 0 0
obciążenie trwa     1640 7 667 1500 974 0
po kroku 2          1782 7 831 1500 884 0
przed Shovelami     1951 9 1094 1500 745 0   (Shovele: 745 -> 0)
po kroku 3          2143 15 1208 1500 0 0
po kroku 4          2343 12 1229 1500 0 0
ruch zatrzymany     2963 0 2164 1500 0 0
krok 5 (końcowe)    2963 0 2963 1500 0 0     krok 6: diff pusty
```

Czytaj to jak historię: `outbox_total` rośnie, bo ruch trwa; `processed_events` goni go; `old_queues_total` spada od 974
do 0 (Shovele przenoszą zaległość, 745 → 0); po zatrzymaniu ruchu obie liczby się zrównują (**2963 = 2963**), a `es_count`
stoi na 1500 (liczba **produktów**, bo `product.updated` nadpisuje dokument, a nie dodaje). `dlq_total = 0` cały czas.

`make g5-deploy o=g5-local-ha` po przebiegu: różnica stanu klastra to wyłącznie usunięte `sts/svc/sa rabbitmq`, jego
NetworkPolicy i 6 Shoveli (plus nowy pod Joba `migrate`, który `deploy.sh` zawsze odtwarza). UID-y podów i ReplicaSety bez zmian:
zero rolloutów. To jest „repo = prawda” sprawdzone, nie zadeklarowane.

### Dlaczego Shovel kolejka do kolejki (najważniejsza zmiana planu)

Plan: Shovel publikuje do `marketplace.events` z zachowaniem routing key. Przebieg 1: `processed_events` 1498 z 1500.
Na starym węźle `dead_lettered_expired_total{classic} = 2` (kopie po retry Messengera wróciły z kolejki `delay_*`), na nowym
`received 434, routed 432, unroutable_dropped_total 2`.

Mechanizm: kopia po retry wraca do kolejki przez **default exchange**, więc jej routing key to **nazwa kolejki**
(`search.product.sync`). Bindingi topic (`product.#` itd.) takiego klucza nie znają. Shovel publikował bez `mandatory`, więc
exchange nic nie routował i wiadomość znikała bez śladu. Kolejka do kolejki (`destQueue`) nie zależy od routing key ani
bindingów, nie dubluje przy nakładających się bindingach, a DLQ→DLQ zostawia martwe wiadomości martwymi.
Wykrywasz to dwiema metrykami: `unroutable_dropped_total` na celu i `dead_lettered_expired_total` na źródle.

**„Bez błędów w logach” nie było żadnym dowodem.** Dowodem było liczenie.

### Przełączenie przez JSON patche, nie `set env`

Plan: `set env RABBITMQ_HOST`, ewentualnie zmiana ConfigMapy. Trzy powody, dla których nie:

1. Zmiana wartości w ConfigMapie zmienia jej **nazwę** (sufiks z hashem), więc rollout dostają wszyscy czytelnicy, **także
   Postgres** (zmierzone mutacją `g5-check`: różnice w ConfigMapie, 3 Deploymentach i StatefulSecie postgres).
2. `set env` nie zmieni klucza w istniejącym `valueFrom` (DSN, hasło).
3. Strategic merge w kustomize wstawia nową zmienną na początek listy i przesuwa scaloną, więc kolejność env po zmianie na
   żywo różniłaby się od renderu: następny `apply` zrobiłby rollout.

Stąd: host i hasło jako jawne `env` (wygrywa z `envFrom`), JSON6902 z `test`, **te same pliki** w overlayu i w `migrate.sh`.
Do tego **hasło**: użytkownik `marketplace` na `rabbitmq-ha` ma własne ([#104](../RUNBOOK.md#104)); sam host dałby `CrashLoopBackOff`
z `invalid credentials`, więc patch zmienia też `RABBITMQ_PASSWORD`.

### Retry i DLQ

Test ścieżki retry na nowym klastrze: koperta z `aggregate.id = "nie-liczba"` daje 500 z `catalog`, Messenger deklaruje
kolejki `delay_*`, po 3 retry wiadomość ląduje w **failure transport** (Doctrine `failed`), nie w DLQ. Tak działa
`AckAfterHandOffTransport` (RUNBOOK #027: DLQ tylko dla „trucizn” i redelivered). Ścieżkę DLQ sprawdziłem osobno złym
JSON-em (`search.product.sync.dlq = 1`, potem `purge_queue`: DLQ była 0, jedyna wiadomość to testowa).
Efekt uboczny, poza zakresem etapu: zły JSON wywala workera (`LogicException` w `ExternalJsonEnvelopeSerializer::encode`)
i konsument restartuje się dwa razy, zanim wiadomość trafi do DLQ.

## Krok 6 — failover: zabity lider

```bash
make g5-verify        # sekcja 4 zabija pod lidera pod obciążeniem i liczy
make g5-measure m=failover
```

Producent z potwierdzeniami (perf-test, `-y 0`, **bez** konsumentów) wysyła 9 000 wiadomości po 300/s do kolejki quorum
`g5.failover`. W trakcie kasujemy pod lidera. Brak konsumentów jest świadomy: wtedy „w kolejce ≥ potwierdzone” to uczciwy dowód
zero-loss (gdyby potwierdzona wiadomość zniknęła, w kolejce byłoby mniej niż potwierdzonych). „Potwierdzone” to dolne
ograniczenie z metryk perf-test (odczyt co 0,25 s), więc ostatnie kilka wiadomości bywa niepoliczonych (8 994–8 997 zamiast 9 000).

Wyniki (szczegóły i caveaty w [`POMIARY.md`](../POMIARY.md)):

| Zdarzenie | Czas do nowego lidera (mediana, min–max) | Utracone potwierdzone |
|---|---|---|
| kasowanie poda lidera (3 ×) | 2 872 ms (2 797–2 981) | 0 |
| `docker stop` węzła z liderem (3 ×) | 1 091 ms (1 017–1 186) | 0 |

`make g5-verify` w swoim jednym przebiegu (od zera): lider `server-1`, w kolejce 2 993 wiadomości, nowy lider po 2 817 ms,
wysłane 9 000, potwierdzone ≥ 8 999, w kolejce 8 999, `nacked 0`.

Dwie pułapki testu ([#106](../RUNBOOK.md#106)): lider kolejki quorum zawsze startuje na podzie z węzła `server-0`
(płaszczyzna sterowania, nie wolno jej zatrzymać), więc test najpierw robi `rabbitmq-queues transfer_leadership` na pod z agenta;
a generator obciążenia może wylądować na zatrzymywanym węźle (stąd `PERF_AVOID_NODE`). Pierwsza taka próba dała „czas do nowego
lidera” 356 s: to był artefakt testu, nie wynik.

## Krok 7 — stream

```bash
bash tools/k3d/stream-demo.sh 1000
```

Stream to log, nie kolejka: wiadomość **nie znika po odczycie**, a czytelnik wybiera, od którego miejsca czyta
(`first`, `next`, znacznik czasu). Kolejka `search.analytics.stream` (typ stream, retencja `x-max-age: 7D`) jest podpięta pod
`marketplace.events` z kluczem `user.#`, obok dotychczasowej `search.analytics.ingest`. Sklep nie publikuje dziś zdarzeń
`user.*`, więc demo publikuje 1 000 sztuk `user.searched` samo i czyta je czterema czytelnikami:

| Czytelnik | Wynik |
|---|---|
| A (`first`) | 1 000 (cała historia; na klastrze po kilku przebiegach: baza z poprzednich + 1 000) |
| B (`first`, równolegle z A) | tyle samo co A, niezależnie od niego |
| C (znacznik czasu z zegara klastra) | dokładnie 1 000 (tylko nowe) |
| D (`next`) | 0 (nic nowego po starcie) |
| stream po wszystkich odczytach | nadal 1 000 (odczyt niczego nie kasuje) |

Kontrast z kolejką: gdyby czytały dwa czytelniki z kolejki, każda wiadomość trafiłaby do jednego z nich i zniknęła. Tu każdy
czytelnik dostaje całość, a stan czytelnika to tylko offset. Stream rośnie o 1 000 wiadomości na każdy `make g5-verify`
(retencja 7 dni, nie da się go opróżnić bez usuwania CR), więc `verify.sh` liczy bazę dynamicznie. Wpięcie prawdziwego konsumenta
analityki w aplikację search **nie** wchodzi w G5.

Dedykowany użytkownik testowy `g5-perftest` ma uprawnienia tylko do `^g5\..*$`: to część dowodu, że testy obciążeniowe nie mogą
dotknąć kolejek sklepu. `topology-parity.rb` pilnuje, że są dokładnie dwa `User` (`marketplace`, `g5-perftest`) i że drugi nie
dotyka zasobów sklepu.

## Krok 8 — pomiary

Wszystko przez `perf-test` jako Job; **tabele i konfiguracja są w [`POMIARY.md`](../POMIARY.md)** (nie duplikuję ich tutaj). Najważniejsze
wnioski i ich uczciwa miara:

- **Quorum vs classic:** w tym środowisku quorum było ok. 10–12 × wolniejsze w przepustowości (mediana 2,2 tys. vs 25,9 tys. msg/s).
  Przyczyny **nie zmierzyłem** (hipotezy: fsync/WAL na współdzielonym dysku VM, alarmy pamięci przy 512 Mi). Classic ma jedną kopię:
  kolejki sklepu są quorum właśnie dlatego, że zdarzenia nie mogą zniknąć.
- **Failover:** zob. wyżej; 0 utraconych we wszystkich 6 przebiegach.
- **Prefetch:** transport AMQP Symfony Messengera **nie ma** prefetch (konsumuje przez `basic.get`, `list_consumers` jest puste przy
  działającym `search-consumer`). Zmierzyłem więc prefetch na konsumencie perf-test (`-q` 1/10/50: ok. 580 / 2 490 / 7 880 msg/s) i
  baseline reindeksu `search-consumer` (42 s dla 1 500 zdarzeń). Dla `search-consumer` dźwignią byłaby prawdopodobnie równoległość konsumentów, nie opcja (nie zweryfikowane).

Maszyna jest współdzielona, load average w trakcie pomiarów wynosił 6–17 przy 10 CPU. Liczby mają rząd wielkości, nie dokładność.

## Przebieg od zera

Wszystko do tej pory testowałem na jednym klastrze, który żył od początku etapu. Ścieżki „nic jeszcze nie istnieje” (tworzenie i
usuwanie sieci Dockera, import obrazów na puste węzły, formowanie klastra RabbitMQ razem z topologią, pierwszy `apply` CR-ów)
nie biegły nigdy. Dlatego ostatni krok etapu to jedna komenda od zera na prawdziwej maszynie (2026-11-10):

```bash
make g5-down && make g5-up && make g5-images && bash tools/k3d/install-operators.sh \
  && make g5-deploy o=g5-local && make g5-migrate load=1500 && make g5-verify && bash tools/k3d/g5-check.sh
```

Wynik przebiegu, który przeszedł w całości (maszyna współdzielona, żadnych zatrzymanych cudzych kontenerów):

| Krok | Czas | Wynik |
|---|---|---|
| `g5-down` (sieć i klaster usunięte) | 2 s | rc=0 |
| `g5-up` (sieć `172.22.0.0/16` utworzona, 3 węzły Ready) | 12 s | rc=0 |
| `g5-images` (build z cache, import, kontrola każdego obrazu na każdym węźle) | 1 min 55 s | rc=0 |
| `install-operators.sh` (ECK, cert-manager, oba operatory) | 2 min 7 s | rc=0 |
| `g5-deploy o=g5-local` (czeka na RabbitMQ i topologię) | 1 min 31 s | rc=0 |
| `g5-migrate load=1500` | 2 min 22 s | rc=0, krok 6: diff pusty |
| `g5-verify` | 2 min 26 s | **PASS=22 FAIL=0** |
| `g5-check.sh` | 1 s | OK |

Razem ok. 10,5 minuty. Linie `counts.sh` z migracji (kolejno: start, obciążenie trwa, po kroku 2, przed Shovelami, po kroku 3, po kroku 4,
ruch zatrzymany, końcowe):

```
start                0 0 0 0 0 0     (przed seedem, na pustym klastrze; w przebiegu 3 wyżej `start` to stan po wcześniejszych seedach)
obciążenie trwa      1656 12 635 641 1039 0
po kroku 2           1790 6 804 811 910 0
przed Shovelami      1953 19 1065 1070 769 0     (Shovele: 769 -> 0)
po kroku 3           2136 15 1165 1165 0 0
po kroku 4           2309 2 1182 1170 0 0
ruch zatrzymany      2935 0 2203 1500 0 0
krok 5 (końcowe)     2935 0 2935 1500 0 0        krok 6: diff pusty
```

Znowu **2935 = 2935**, `es_count` = 1 500 produktów, stare kolejki 0, DLQ 0. `g5-verify`: lider `g5.failover` zabity przy 2 993 wiadomościach,
nowy lider po 2 817 ms (server-1 → server-2), wysłane 9 000, potwierdzone ≥ 8 999, w kolejce 8 999, `nacked 0`; stream 1 000 / 1 000 / 1 000 / 0;
pamięć: pody 2 025 MiB, węzły k3d 3 429 MiB (limit 5 700). Na końcu `make g5-deploy o=g5-local-ha` skasował stary RabbitMQ i Shovele
(`prune.txt`) i skończył bez rolloutu aplikacji; klaster został w stanie końcowym `g5-local-ha`.

**Ten przebieg nie przeszedł za pierwszym razem, i dobrze.** Ścieżka od zera wykryła trzy błędy, których nie widział żaden wcześniejszy test:

1. **Import obrazów** ([#102](../RUNBOOK.md#102)): `k3d image import` z kilkoma obrazami wypisał `ERRO ... content digest not found`, zakończył się kodem 0,
   a `sts/postgres` wisiał w `ImagePullBackOff`. Naprawa: jeden tarball jednej platformy i kontrola każdego obrazu na każdym węźle.
2. **Wdrożenie kończy się przed gotowością RabbitMQ** ([#108](../RUNBOOK.md#108)): `make g5-migrate` odmówił (brak `AllReplicasReady`), a dwa `Permission` utknęły
   na `vhost_or_user_not_found`. Naprawa: `deploy.sh` czeka na klaster i topologię i trąca niegotowe CR-y.
3. **Krok 6 migracji czerwony mimo poprawnych liczb** ([#109](../RUNBOOK.md#109)): `kc diff` pokazał wartości domyślne, które API dopisało do CR-ów. Naprawa:
   diff tylko dla obiektów rdzenia.

Czwarta przeszkoda była zewnętrzna i nie ma wpisu: `docker.elastic.co` przez kilka minut nie rozwiązywał się w DNS (build obrazu ES padł na
pobraniu metadanych bazy), więc przebieg powtórzyłem po powrocie sieci. Każdy z trzech powyższych błędów kosztował powtórzenie całego
przebiegu (ok. 10 minut), co jest dobrym argumentem za tym, żeby ten przebieg był pierwszą rzeczą, a nie ostatnią, przy zmianie skryptów `tools/k3d/`.


## Odstępstwa od planu

| Plan | Co zrobiłem | Dlaczego |
|---|---|---|
| Wdrożenie na VPS | **k3d na Macu** | VPS wygasł (spec); etap i tak zabija węzły i przenosi dane |
| kube-prometheus-stack do porównań | **bez niego**: metryki przez `port-forward` na 15692 | budżet pamięci (≤ 5,7 GB realnie) nie mieści monitoringu |
| Shovel do `marketplace.events` | **Shovel per kolejka (`destQueue`)** (R9) | kopie po retry gubiły się jako unroutable (2 z 1500) |
| `set env` + ConfigMapa | **JSON patche** tych samych plików co overlay + hasło HA (R10) | zmiana ConfigMapy restartuje Postgresa; repo ≡ stan na żywo |
| seed w pętli | **seed `--fresh` + Job `--traffic`** (R11) | unikalny slug / TRUNCATE outboxu |
| retry kończy w DLQ | **failure transport**, DLQ osobno (R12) | `AckAfterHandOffTransport` |
| prefetch na `search-consumer` | **prefetch na perf-test** + baseline reindeksu (R13) | Messenger AMQP nie ma prefetch |
| overlaye w `deploy/k8s/overlays/` | **`deploy/overlays/`** + wspólny `g5-base` (R4) | kustomize `cycle detected` ([#098](../RUNBOOK.md#098)) |
| bez `catalog-app` | **`catalog-app` zostaje** (R5) | `search-consumer` czyta produkt z powrotem po HTTP |
| budżet ≤ 4,5 Gi limitów | **6 / 5 Gi limitów + ≤ 5,7 GB realnie** (R7) | limity są nadmiarowe względem zużycia; spec mówi o realnej pamięci |
| numeracja RUNBOOK od #096 | **od #098** (R14) | #096 i #097 zajęte na otwartych gałęziach |
| kryterium 4: failover na `search.product.sync` | **na kolejce testowej quorum `g5.failover`** (R3) | pomiar pod obciążeniem nie może zalać kolejki produkcyjnej sklepu |
| `vm_memory_high_watermark` dopasowany do limitu | **nie ustawiony jawnie**: domyślna wartość operatora wyliczana z limitu | operator sam wylicza ją z limitu pamięci |
| trasy NetworkPolicy w tabeli ALLOWED `ckad-check.rb` | **asercje w `tools/k3d/g5-check.rb`** | `ckad-check.rb` dotyczy bazy produkcyjnej bez tych polityk |
| `verify` czyta metryki z 15692 | **bez odczytu 15692**: management UI / `port-forward` | brak polityki monitoring → 15692, nie ma Prometheusa |
| cele `make g5-measure-*` | **`make g5-measure m=queues\|failover\|prefetch`** | jeden cel z parametrem zamiast trzech

Specyfikacja ma przy zmienionych miejscach krótkie „Zmiana w trakcie realizacji” (sekcje 1, 3, 5, 7, 8, 9 i 10).

## Co wybuchło po drodze

Pełne wpisy w RUNBOOK; tu mapa:

| Błąd | Wpis |
|---|---|
| overlay wewnątrz bazy: `cycle detected` | [#098](../RUNBOOK.md#098) |
| webhook odrzuca `User`/`Shovel`: Secret bez etykiety | [#099](../RUNBOOK.md#099) |
| egress do API i przypięta podsieć | [#100](../RUNBOOK.md#100) |
| stream bez repliki, `list_queues` wisi, `/health` | [#101](../RUNBOOK.md#101) |
| `k3d image import`: `content digest not found` | [#102](../RUNBOOK.md#102) |
| **Shovel przez exchange gubi wiadomości po retry** | [#103](../RUNBOOK.md#103) |
| `invalid credentials`, zmiana ConfigMapy restartuje Postgresa | [#104](../RUNBOOK.md#104) |
| perf-test `ACCESS_REFUSED`, `rabbitmqadmin`, `psysh` | [#105](../RUNBOOK.md#105) |
| test failover: lider na `server-0`, IP po `docker start` | [#106](../RUNBOOK.md#106) |
| kustomize a CRD Elasticsearch | [#107](../RUNBOOK.md#107) |
| świeży klaster: wdrożenie kończy się przed gotowością RabbitMQ, `Permission` bez ponowienia | [#108](../RUNBOOK.md#108) |
| krok 6 migracji czerwony przez wartości domyślne w CR-ach | [#109](../RUNBOOK.md#109) |

Trzy z nich ([#102](../RUNBOOK.md#102), [#108](../RUNBOOK.md#108), [#109](../RUNBOOK.md#109)) wyszły dopiero w przebiegu od zera
na świeżym klastrze: wszystko, co przez dni testowałem na jednym długo żyjącym klastrze, miało już obrazy, gotowe CR-y i
punkt stały po kilku `apply`. Przebieg od zera jest więc nie formalnością, tylko jedynym testem ścieżki „nic jeszcze nie istnieje”.

Wspólny wzór: **większość wyglądała na zdrowe** (#102: kod wyjścia 0 mimo `ERRO`; #109: poprawne liczby; #108: `deploy.sh` kończył się sukcesem, a `Permission` miał `Ready=False` z czytelnym błędem, tyle że bez naprawy samoczynnej). Zielony
status obiektu, brak błędów i kod wyjścia nic nie znaczą; znaczy skutek, sprawdzony liczbą albo połączeniem.

## Czego G5 nie robi

- **Wdrożenia na VPS/k3s.** Manifesty `rabbitmq-cluster/` są wspólne, ale overlay produkcyjny i migracja produkcyjnych kolejek
  to osobny krok (z tym samym `counts.sh` jako dowodem).
- **Monitoringu klastra RabbitMQ w Prometheusie.** Metryki są na 15692, odczytywane przez `port-forward`. Brak polityki
  monitoring → 15692 jest zamierzony.
- **Konsumenta streamu w aplikacji.** Demo czyta stream perf-testem.
- **Wiadomości `user.*`/`alert.*` w `counts.sh`.** Równość `processed_events = outbox_total` zakłada, że każde zdarzenie outboxu idzie do
  `search.product.sync`. Dziś seed robi tylko takie; przy innych typach trzeba filtrować outbox po typie.
- **Porównania CR-ów ze stanem na żywo w kroku 6 migracji** (`RabbitmqCluster`, topologia, Elasticsearch): pokrywa je tylko render.
- **Ścieżki „Shovele od nowa” w kroku 4.** Logika jest, ale w żadnym przebiegu nie wykonała się (stare kolejki były puste od razu po
  rolloucie), więc nie ma na nią dowodu na żywo.

## Ściąga

```bash
make g5-up && make g5-images                      # klaster + obrazy
bash tools/k3d/install-operators.sh               # ECK, cert-manager, operatory RabbitMQ
make g5-deploy o=g5-local                         # stan „przed”
make g5-migrate load=1500                         # migracja pod ruchem, 6 kroków, linia liczb po każdym
make g5-deploy o=g5-local-ha                      # stan końcowy (powinien nic nie zmienić)
make g5-verify                                    # 22 asercje, 2–5 min (2 min 26 s w przebiegu od zera)
make g5-counts                                    # outbox_total outbox_unpublished processed_events es_count old dlq
make g5-measure m=queues|failover|prefetch        # pomiary (kilka–kilkanaście minut)
make g5-check                                     # asercje renderu bez klastra (CI)
make g5-down                                      # klaster i sieć znikają
```

Gdy coś poszło nie tak: [RUNBOOK #098–#109](../RUNBOOK.md#098).

## Twoja kolej

1. Zrób migrację sam, bez `migrate.sh`: kolejność kroków 2–4 z sekcji o migracji, ręcznie, z `make g5-counts` po każdym. Zatrzymaj
   się po kroku 3 i wyjaśnij, czemu `old_queues_total` spada, a `outbox_total` rośnie.
2. Zmień cel Shovela na `marketplace.events` i powtórz przebieg 1. Znajdź dwie zgubione wiadomości dwiema metrykami z [#103](../RUNBOOK.md#103).
3. Zabij `rabbitmq-ha-server-0` i `server-1` jednocześnie (nie po kolei). Co się dzieje z kolejką quorum i dlaczego operator robi rollout po jednym podzie?
4. Zmień `x-delivery-limit` w `topology.yaml` z 5 na 6 i uruchom `make g5-check`. Które asercje poczerwieniały i czemu to lepsze niż
   `kubectl diff`?
5. Napisz konsumenta streamu w aplikacji search, który wznawia od zapisanego offsetu, i sprawdź, że po restarcie nie czyta niczego dwa razy.
