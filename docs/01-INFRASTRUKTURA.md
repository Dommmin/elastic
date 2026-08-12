# 01 — Plan infrastruktury (Docker, lokalnie)

Ten dokument opisuje **co postawimy, dlaczego akurat tak, i w jakiej kolejności**.
Każda sekcja ma część „co to jest / po co" — bo infrastruktura, której nie rozumiesz,
to infrastruktura, której nie zdiagnozujesz.

---

## 1. Budżet pamięci — czytaj to najpierw

Docelowy sprzęt: **Apple Silicon (M1 Pro) / 32 GB RAM**. Docker Desktop na macOS działa
w maszynie wirtualnej z własnym limitem — domyślnie znacznie niższym niż RAM hosta.
Elasticsearch to JVM: jest głodny i nie lubi, gdy mu się pamięć zabiera w locie.

**Zrób najpierw:** Docker Desktop → Settings → Resources → Memory: **20–22 GB**, CPU: 8,
Swap: 2 GB, Disk: min. 100 GB. Zostawiamy ~10 GB hostowi na macOS, IDE i przeglądarkę.

### Profil `default` (jeden node ES) — codzienna praca

| Kontener | Heap / limit | Realne zużycie |
|---|---|---|
| `elasticsearch` (1 node) | `ES_JAVA_OPTS=-Xms4g -Xmx4g`, limit 8g | ~6 GB (heap + off-heap + page cache Lucene) |
| `kibana` | `--max-old-space-size=2048`, limit 3g | ~1,5 GB |
| `rabbitmq` | limit 1g | ~300 MB |
| `postgres` | limit 2g (`shared_buffers=512MB`) | ~600 MB |
| `redis` | limit 512m | ~50 MB |
| `catalog-app` + worker + scheduler + outbox | limit 512m każdy | ~800 MB |
| `catalog-vite` (tylko dev, HMR) | limit 1g | ~400 MB |
| `search-http` + 3× konsument | limit 512m każdy | ~700 MB |
| **Razem** | | **~10 GB** |

### Profil `cluster` (3 node'y ES) — moduły o shardach i HA

| Kontener | Heap | Realne zużycie |
|---|---|---|
| `es01`, `es02`, `es03` | `-Xms3g -Xmx3g` każdy | ~4,5 GB × 3 = **13,5 GB** |
| reszta stacku (bez zmian) | | ~4 GB |
| **Razem** | | **~17,5 GB** |

To mieści się w 20–22 GB przydzielonych Dockerowi — czyli **klaster i pełny stack mogą
chodzić jednocześnie**, bez gaszenia Kibany czy aplikacji. Przy 16 GB byłoby to niemożliwe.

### Co konkretnie zyskujemy dzięki 32 GB (i czego nie musimy odpuszczać)

- **4 GB heapu zamiast 2 GB** w profilu default → realne testy agregacji i wektorów.
  Circuit breaker będziesz wywoływał celowo, a nie przypadkiem przy zwykłej pracy.
- **Klaster 3-nodowy jako stan „normalny"**, a nie awaryjne ćwiczenie. Możemy trzymać
  `number_of_replicas: 1` i pracować na klastrze **green** — czyli w konfiguracji
  produkcyjnej, a nie w uproszczeniu.
- **Profil `obs` (Filebeat + Metricbeat + Stack Monitoring) może działać na stałe**
  (+~1 GB). To bardzo dużo daje w nauce: cały czas widzisz metryki klastra, na którym
  eksperymentujesz.
- **Realny wolumen danych** — nie 10 tys. produktów, tylko **5–10 mln dokumentów**.
  Dopiero przy takiej skali widać różnicę między dobrym a złym mapowaniem, między
  `from/size` a `search_after`, i po co jest `force_merge`. Na małym zbiorze wszystko
  jest szybkie i niczego się nie nauczysz.
- **Profil `ml` (trial, ELSER/embeddingi)** — node ML potrzebuje ~4 GB. Przy 16 GB
  trzeba by go było odpalać zamiast klastra; teraz zmieści się obok.

### Trzy żelazne zasady heapu ES (zapamiętaj na zawsze)

1. **Heap = max 50 % RAM dostępnego dla node'a.** Druga połowa jest potrzebna Lucene na
   cache plików (mmap) — to stamtąd bierze się szybkość wyszukiwania. Dlatego przy limicie
   kontenera 8 GB dajemy heap 4 GB, nie 7.
2. **Nigdy powyżej ~31 GB.** Powyżej tego JVM traci „compressed oops" i wskaźniki zajmują
   2× więcej — masz mniej użytecznej pamięci przy większym heapie. Klasyczne pytanie
   rekrutacyjne.
3. `-Xms` musi równać się `-Xmx` (brak resizowania heapu w locie) + `bootstrap.memory_lock: true`.

---

## 2. Struktura katalogów repozytorium

```
elastic/
├── docs/                       # ten plan + Twój runbook diagnostyczny
├── compose.yaml                # główny plik (profile: default, cluster, obs, tools)
├── compose.override.yaml       # lokalne nadpisania (git-ignored)
├── .env.example / .env
├── Makefile                    # skróty: make up, make reindex, make es-health...
├── infra/
│   ├── elasticsearch/
│   │   ├── Dockerfile          # obraz bazowy + plugin analysis-stempel (polski!)
│   │   ├── elasticsearch.yml
│   │   ├── config/             # role mappings, users
│   │   └── certs/              # generowane lokalnie, git-ignored
│   ├── kibana/kibana.yml
│   ├── rabbitmq/
│   │   ├── rabbitmq.conf
│   │   ├── enabled_plugins
│   │   └── definitions.json    # exchanges/queues/policies jako kod
│   ├── postgres/init/          # tworzenie 2 baz
│   └── caddy/Caddyfile         # jeśli wyjdziemy poza domyślny FrankenPHP
├── apps/
│   ├── catalog/                # Laravel 13
│   └── search/                 # Symfony 8
└── tools/
    ├── seed/                   # generator danych (produkty, oferty, zdarzenia)
    └── bench/                  # skrypty do testów wydajności (k6 / vegeta)
```

Repozytorium **monorepo** — dwie apki obok siebie. W realnej firmie byłyby to osobne repa,
ale do nauki monorepo jest znacznie wygodniejsze (jeden `docker compose up`).

> Katalog nie jest jeszcze repozytorium git — pierwszym krokiem implementacji będzie `git init`.

---

## 3. Kontenery — co, dlaczego i jak

### 3.1 `elasticsearch` — serce projektu

**Obraz:** własny `Dockerfile` na bazie `docker.elastic.co/elasticsearch/elasticsearch:9.5.1`
(wersje przypięte — patrz `07-WERSJE.md`; nigdy `:latest` w infrastrukturze).

Dlaczego własny obraz, a nie gotowy?
- musimy doinstalować **plugin `analysis-stempel`** (polski stemmer) i `analysis-icu`
  (normalizacja Unicode, sortowanie po polsku) — pluginy w ES instaluje się do obrazu,
  nie da się „w locie",
- uczysz się, że **wersja pluginu musi się co do znaku zgadzać z wersją ES** — to typowa
  pułapka przy upgrade'ach.

**Konfiguracja startowa (profil `default`, jeden node):**
```yaml
discovery.type: single-node          # brak elekcji mastera, brak quorum
xpack.security.enabled: true         # TAK, uczymy się z włączonym bezpieczeństwem
xpack.security.http.ssl.enabled: false  # ale bez TLS na HTTP w profilu dev — patrz niżej
cluster.name: marketplace-local
node.name: es01
path.repo: /snapshots                # do ćwiczeń ze snapshotami
```

**Decyzja o bezpieczeństwie — świadoma i wyjaśniona:**
Wielu tutoriali każe ustawić `xpack.security.enabled=false`. **Nie zrobimy tego**, bo:
- w produkcji nigdy tak nie jest, a Ty masz się nauczyć realiów,
- API keys, role i użytkownicy to osobny, ważny temat (moduł 12),
- ale w profilu `default` wyłączymy TLS na warstwie HTTP, żeby debugowanie curl-em nie było
  udręką. W module o bezpieczeństwie **włączymy pełne TLS** (transport + HTTP) z certyfikatami
  generowanymi przez `elasticsearch-certutil` i zobaczysz różnicę.

**Wymagania systemowe, o których wszyscy zapominają:**
- `vm.max_map_count=262144` — na Docker Desktop/macOS ustawiane jest w VM; jeśli ES nie wstaje,
  to pierwsze miejsce do sprawdzenia (błąd `max virtual memory areas ... too low`),
- `ulimits: memlock: -1` i `bootstrap.memory_lock: true` — żeby heap nie poszedł na swap
  (swap = śmierć wydajności ES),
- `ulimits: nofile: 65536`.

**Healthcheck:** `curl -s -u elastic:$PASS localhost:9200/_cluster/health | grep -q '"status":"\(green\|yellow\)"'`.
Wszystkie inne kontenery czekają na `condition: service_healthy` — dzięki temu Kibana nie
wstaje przed ES i nie zaśmieca logów.

**Wolumeny:** `es-data:/usr/share/elasticsearch/data`, `es-snapshots:/snapshots`.

### 3.2 `es02`, `es03` — profil `cluster` (przy 32 GB: nasz **domyślny** tryb pracy)

Uruchamiane przez `docker compose --profile cluster up`. Wtedy `es01` przełącza się z
`discovery.type: single-node` na `discovery.seed_hosts` + `cluster.initial_master_nodes`.

Przy 32 GB RAM rekomendacja się zmienia: **od fazy I-9 pracujemy na klastrze na co dzień**,
z `number_of_replicas: 1` i statusem **green**. Powód jest pedagogiczny — połowa realnych
problemów Elasticsearcha (rebalans, statystyki score per shard, nierówne obciążenie,
alokacja, rejections na jednym node'zie) **w ogóle nie istnieje w konfiguracji
single-node**. Ucząc się na jednym node'zie, uczysz się uproszczenia.

Profil `default` (single-node) zostaje jako szybki tryb do prostych eksperymentów
i do porównań „to samo zapytanie na 1 vs 3 node'ach".

Po co? Żebyś **na własne oczy** zobaczył:
- co znaczy shard **primary** i **replica**, i dlaczego 1 node = klaster **yellow**,
- co się dzieje, gdy `docker stop es02` — rebalans, `_cluster/allocation/explain`,
- czym jest quorum i split brain (`minimum_master_nodes` w starych wersjach vs.
  automatyczny mechanizm od 7.x),
- role node'ów: `master`, `data_hot`, `data_warm`, `ingest`, `ml`, `coordinating_only` —
  ustawimy je jawnie w każdym node'zie, żeby nie były magią.

### 3.3 `kibana`

Nie tylko „ładne wykresy" — to Twoje główne narzędzie diagnostyczne:
- **Dev Tools Console** — tu będziesz spędzał 80 % czasu nauki (autouzupełnianie zapytań!),
- **Index Management** — mapowania, aliasy, ILM, szablony klikalnie,
- **Discover / Data Views** — przeglądanie zdarzeń,
- **Dashboards** — analityka wyszukiwań,
- **Alerting**, **ES|QL**, **Search Profiler**, **Grok Debugger**.

Kibana potrzebuje `ELASTICSEARCH_SERVICEACCOUNTTOKEN` albo użytkownika `kibana_system`
— jego hasło ustawimy skryptem inicjalizacyjnym (`infra/elasticsearch/setup.sh`, kontener
jednorazowy `es-setup`, który po wykonaniu kończy pracę). To też lekcja: **jak inicjalizuje
się sekrety w klastrze**.

### 3.4 `rabbitmq`

Obraz `rabbitmq:4.3.4-management-alpine` (port 15672 = panel WWW).

Konfiguracja **jako kod** (`definitions.json` ładowany przez `load_definitions`) — nie
klikamy w panelu, tylko deklarujemy:

```
exchange  marketplace.events        typ: topic, durable
exchange  marketplace.events.dlx    typ: topic, durable       (dead letter)
exchange  marketplace.retry         typ: topic, durable       (opóźnione ponowienia)

queue     search.product.sync       ← binding: product.*, offer.*
queue     search.analytics.ingest   ← binding: user.search, user.click, user.cart.*
queue     notifications.alerts      ← binding: alert.matched
queue     *.dlq                     dla każdej z powyższych
```

Czego się przy tym nauczysz (moduł RabbitMQ w `03-SCIEZKA-NAUKI.md`):
- **exchange vs queue vs binding vs routing key** — i dlaczego `topic` jest domyślnym wyborem,
- **durable / persistent / publisher confirms** — trzy różne rzeczy, wszystkie trzy potrzebne,
  żeby nie zgubić wiadomości,
- **prefetch (QoS)** — dlaczego bez tego jeden konsument zassie 10 000 wiadomości i inne stoją,
- **DLX + TTL** jako mechanizm retry z backoffem (albo plugin `rabbitmq_delayed_message_exchange`
  — porównamy oba),
- **quorum queues** vs classic — co wybrać w 2026 r.,
- **poison message** — wiadomość, która zawsze wywala konsumenta; jak jej nie zapętlić.

Włączone pluginy: `rabbitmq_management`, `rabbitmq_prometheus`,
opcjonalnie `rabbitmq_delayed_message_exchange`.

### 3.5 `postgres`

Jeden kontener `postgres:18.4-alpine`, **dwie bazy** tworzone przez skrypt w
`/docker-entrypoint-initdb.d/`:
- `catalog` — dla Laravela (produkty, oferty, sprzedawcy, użytkownicy, **outbox**),
- `searchsvc` — dla Symfony (stan indeksacji, definicje alertów, historia reindeksów).

**Dlaczego dwie bazy, a nie jedna wspólna?** Bo „shared database" to antywzorzec w
architekturze serwisowej — dwa serwisy nie mogą czytać sobie nawzajem tabel, bo wtedy
kontraktem staje się schemat bazy i nie da się nic zmienić. Kontraktem mają być zdarzenia.
Jeden *kontener* z dwiema bazami to kompromis pod 16 GB RAM — i tak nazwiemy to wprost.

### 3.6 `redis`

`redis:8.8.1-alpine` — cache aplikacji Laravel, sesje, rate limiting, oraz **cache wyników
wyszukiwania** (nauczysz się, kiedy cache'ować przed ES, a kiedy zaufać request cache ES).

### 3.7 `catalog-app` — Laravel 13 na FrankenPHP

**Czym jest FrankenPHP i po co:** to serwer aplikacyjny PHP napisany jako **moduł Caddy'ego**.
Czyli jeden proces = HTTP server + PHP runtime. Zalety, które zobaczysz w praktyce:

- **worker mode** — aplikacja bootuje raz i obsługuje tysiące requestów bez ponownego
  ładowania frameworka (jak Swoole/RoadRunner, ale bez rozszerzeń). Laravel Octane ma
  oficjalny driver FrankenPHP.
- **HTTPS out of the box** — Caddy sam generuje i odnawia certyfikaty; lokalnie używa
  wewnętrznego CA. Dostajesz `https://catalog.localhost` bez zabawy z mkcert.
- **HTTP/2 i HTTP/3** za darmo,
- **Early Hints (103)**, kompresja, statyczne pliki serwowane przez Caddy'ego, nie przez PHP.

**Pułapki worker mode, które celowo przećwiczymy** (to jest wiedza produkcyjna):
- stan wyciekający między requestami (statyczne właściwości, singletony trzymające request),
- połączenia do bazy/ES żyjące długo — jak obsłużyć zerwane połączenie,
- kiedy worker mode **szkodzi** (długie zapytania blokujące workera).

Dockerfile będzie **multi-stage**:
```
stage 1: composer:2          → vendor/ (bez dev w prod)
stage 2: node:24.19.0-alpine → npm ci && npm run build → public/build (Vite 8 + Vue 3)
stage 3: frankenphp:1.12.7-php8.5 → runtime + artefakty z 1 i 2
```
W dev montujemy kod jako volume; w „prod-like" buildzie kopiujemy do obrazu i włączamy
`opcache.validate_timestamps=0`. Porównamy czasy odpowiedzi — świetna lekcja o OPcache.

Osobne kontenery z tego samego obrazu (bo tak robi się w produkcji):
- `catalog-app` — HTTP (FrankenPHP worker mode),
- `catalog-worker` — `php artisan queue:work` (kolejki wewnętrzne na Redisie),
- `catalog-scheduler` — `php artisan schedule:work`,
- `catalog-outbox` — publikator outboxu do RabbitMQ (opisany w `02-APLIKACJE.md`),
- `catalog-ssr` — `php artisan inertia:start-ssr` (profil `ssr`, etap 7b).

Dodatkowo **tylko w dev** (profil `dev`):
- `catalog-vite` — `npm run dev` na `node:24.19.0-alpine`, port 5173, HMR.
  Caddy proxuje `/@vite/*` i `/resources/*` do tego kontenera, więc w przeglądarce
  masz jedną domenę `https://catalog.localhost` bez mieszania portów.
  W buildzie produkcyjnym ten kontener nie istnieje — assets są w obrazie.

**Pułapka do przećwiczenia:** HMR przez HTTPS i proxy Caddy'ego wymaga poprawnego
`server.hmr.host` w `vite.config.js`. Klasyczny objaw: strona działa, ale zmiany w Vue
nie odświeżają się i konsola pokazuje błąd WebSocketa. Dobra lekcja o tym, jak działa
reverse proxy.

### 3.8 `search-service` — Symfony 8

- `search-http` — małe API administracyjne (health, status reindeksu, ręczne wyzwalanie),
  też na FrankenPHP (spójność + drugie podejście do tego samego serwera),
- `search-consumer-sync` — `messenger:consume sync_products` (skalowany do N replik!),
- `search-consumer-analytics` — `messenger:consume analytics`,
- `search-consumer-alerts` — `messenger:consume alerts`.

Rozdzielenie konsumentów na osobne kontenery to wzorzec produkcyjny: różne kolejki mają
różny SLA i różną skalę. Przećwiczysz `docker compose up --scale search-consumer-sync=4`
i zobaczysz w RabbitMQ, jak rozkłada się ruch (i co robi prefetch).

### 3.9 Profil `obs` — obserwowalność (przy 32 GB: włączony **na stałe**)

Przy 16 GB byłby to dodatek „jak starczy pamięci". Przy 32 GB kosztuje ~1 GB i włączamy
go od fazy I-10 na dobre — dzięki temu przez cały kurs masz przed oczami metryki klastra,
na którym eksperymentujesz. To zmienia naukę tuningu z teorii w obserwację.

- `filebeat` lub `elastic-agent` — zbiera logi kontenerów (JSON z Laravela i Symfony)
  i wysyła do ES przez **ingest pipeline**. Uczysz się: data streams, ILM, grok/dissect,
  `@timestamp`, ECS (Elastic Common Schema).
- `metricbeat` — metryki samego ES i RabbitMQ → gotowe dashboardy „Stack Monitoring".
  To jest dokładnie to, czym diagnozuje się produkcję.
- opcjonalnie `apm-server` + agent PHP → traces zapytań do ES z poziomu Laravela.

### 3.10 Profil `tools`

- `mailpit` — podgląd maili z alertów (SMTP na 1025, UI na 8025),
- `minio` — S3-kompatybilny storage do **snapshotów ES** (repozytorium typu `s3`).
  Alternatywa: repozytorium `fs` na wolumenie — zrobimy najpierw `fs`, potem `s3`, żeby
  zobaczyć różnicę i nauczyć się `_snapshot` API oraz SLM (Snapshot Lifecycle Management).
- `k6` — testy obciążeniowe wyszukiwarki (potrzebne w module o wydajności i cache'ach).

---

## 4. Sieć, porty, nazwy

Jedna sieć bridge `marketplace`. Komunikacja po nazwach serwisów (`http://elasticsearch:9200`).

Wystawione na hosta (tylko to, co potrzebne człowiekowi):

| Usługa | URL |
|---|---|
| Catalog (Laravel) | `https://catalog.localhost` |
| Search admin (Symfony) | `https://search.localhost` |
| Kibana | `http://localhost:5601` |
| Elasticsearch | `http://localhost:9200` |
| RabbitMQ panel | `http://localhost:15672` |
| Mailpit | `http://localhost:8025` |
| MinIO | `http://localhost:9001` |

`*.localhost` rozwiązuje się na 127.0.0.1 automatycznie w większości przeglądarek i systemów
— nie musimy grzebać w `/etc/hosts`. Certyfikaty wystawi wewnętrzne CA Caddy'ego (raz
zaakceptujesz je w systemie).

---

## 5. Makefile — interfejs do wszystkiego

Nie chcemy pamiętać długich komend. Plan skrótów:

```make
make up              # profil default (single-node) — szybkie eksperymenty
make up-cluster      # es01+es02+es03 + obs — TRYB DOMYŚLNY przy 32 GB
make up-ml           # + node ML na trial (moduł 13)
make down / make nuke
make logs s=elasticsearch
make sh s=catalog-app

# Elasticsearch
make es-health       # _cluster/health?pretty + _cat/indices?v
make es-explain      # _cluster/allocation/explain  (czemu shard nie przypisany)
make es-hot          # _nodes/hot_threads
make es-slowlog      # włącz slowlog na indeksie
make es-reset        # skasuj indeksy i szablony (tylko dev!)

# aplikacja
make seed n=5000000  # generuj dane testowe (domyślnie 5 mln produktów)
make reindex         # pełny reindeks z zerowym downtime
make consume         # podgląd konsumentów
make bench q=laptop  # test obciążeniowy zapytania
```

---

## 6. Kolejność budowy infrastruktury (fazy)

Każda faza kończy się **działającym, sprawdzalnym stanem**. Nie idziemy dalej, dopóki
poprzednia nie działa i nie rozumiesz, dlaczego działa.

| Faza | Co robimy | Kryterium ukończenia |
|---|---|---|
| **I-0** | `git init`, struktura katalogów, `.env.example`, Makefile-szkielet | `make` pokazuje pomoc |
| **I-1** | Postgres + Redis + healthchecki | `make up` → oba healthy |
| **I-2** | Elasticsearch (single-node, security ON) + kontener `es-setup` z hasłami | `make es-health` → `yellow`, rozumiesz **dlaczego yellow, a nie green** |
| **I-3** | Kibana + Dev Tools | wykonujesz `GET _cluster/health` w konsoli Kibany |
| **I-4** | Własny obraz ES z `analysis-stempel` + `analysis-icu` | `GET _analyze` na polskim tekście zwraca zrdzeniowane tokeny |
| **I-5** | RabbitMQ + `definitions.json` + panel | widzisz swoje exchange'e i kolejki, przepychasz testową wiadomość ręcznie |
| **I-6** | Laravel 13 + FrankenPHP (bez worker mode) | `https://catalog.localhost` zwraca stronę |
| **I-7** | Octane/worker mode + porównanie wydajności (k6) | masz liczby: req/s przed i po |
| **I-8** | Symfony 8 + Messenger + jeden konsument echo | wiadomość z Laravela ląduje w logu Symfony |
| **I-9** | Profil `cluster` (es02, es03) — **od tej fazy tryb domyślny** | klaster `green` z replikami, umiesz odczytać `_cat/shards` |
| **I-10** | Profil `obs` (Filebeat/Metricbeat) — zostaje włączony na stałe | logi obu apek w Kibana Discover + Stack Monitoring pokazuje 3 node'y |
| **I-11** | Snapshoty: repo `fs`, potem MinIO + SLM | robisz snapshot, kasujesz indeks, przywracasz |
| **I-12** | `make seed n=5000000` — realny wolumen danych | 5 mln produktów + 15 mln ofert w ES, znasz czas indeksacji i rozmiar indeksu |

---

## 7. Konwencje, których się trzymamy od początku

1. **Wersje przypięte** — `elasticsearch:9.5.1`, nie `:9` (pełna lista: `07-WERSJE.md`,
   wszystkie jako zmienne w `.env`). Upgrade to świadoma decyzja i
   osobne ćwiczenie (rolling upgrade).
2. **Konfiguracja przez env, sekrety przez `.env`** (git-ignored) — `.env.example` w repo.
3. **Wszystko idempotentne** — `make up` uruchomiony 5 razy daje ten sam stan.
4. **Healthcheck w każdym serwisie** — brak healthchecka = brak kontroli nad startem.
5. **Logi w JSON** z obu aplikacji — bo mają trafić do ES i być parsowalne (ECS).
6. **Żadnych ręcznych zmian w Kibanie, które są potrzebne do działania** — mapowania,
   szablony, ILM i aliasy tworzy kod (Symfony), nie klikanie. Kibana służy do oglądania.
7. **Ograniczenia zasobów (`deploy.resources.limits`) w każdym kontenerze** — żeby jeden
   proces nie zabił Ci laptopa i żebyś zobaczył, jak zachowuje się ES pod presją pamięci.

---

## 8. Ryzyka i jak je obchodzimy

| Ryzyko | Mitygacja |
|---|---|
| Pamięć: 32 GB starcza, ale nie jest nieskończona | profile compose; heap 3 GB/node w klastrze, 4 GB w single-node; limity `deploy.resources.limits` na **każdym** kontenerze; profil `ml` tylko na czas modułu 13 |
| Kuszenie „skoro mam RAM, dam heap 16 GB" | trzymamy się zasady 50 % limitu kontenera — celowo pracujemy przy rozsądnym heapie, żeby uczyć się tuningu, a nie zagłuszać problemy sprzętem |
| ARM64 (M1) — brak niektórych obrazów | ES, Kibana, RabbitMQ, Postgres, FrankenPHP mają arm64; Beats też. W razie czego `platform: linux/amd64` + emulacja (wolno — zaznaczymy gdzie) |
| Laravel 13 / Symfony 8 — świeże wersje, zmienne API | ✅ zweryfikowane 2026-08-12 w rejestrach: Laravel 13.25.0, Symfony 8.1.4, PHP 8.5.9 spełnia wymagania obu. Szczegóły: `07-WERSJE.md` |
| Funkcje ES pod licencją komercyjną (ML, ELSER, Watcher) | domyślnie **basic** (darmowa, ma ILM, data streams, kNN, ES\|QL, security). Do modułu ML/semantycznego włączymy 30-dniowy **trial** i jasno oznaczymy, co jest płatne w produkcji |
| Utrata danych przy eksperymentach | wszystko odtwarzalne: `make seed` + `make reindex`; snapshoty jako ćwiczenie |

---

## 9. Pytanie do Ciebie przed startem implementacji

Nic w tym planie nie jest zablokowane, ale jedna decyzja wpływa na kształt kodu:
**czy chcesz frontend** (Blade + Alpine/Livewire w Laravelu, żeby wyszukiwarkę dało się
klikać i „czuć"), czy **wystarczy API + Kibana**? Domyślnie zakładam minimalny, ale realny
frontend wyszukiwarki — bez niego trudno docenić facety, autouzupełnianie i relevance tuning.
