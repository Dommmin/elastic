# RUNBOOK — przewodnik diagnostyczny

> **To jest Twój najcenniejszy artefakt w tym projekcie.** Kod się zdezaktualizuje,
> ta wiedza nie. Dopisuj tu wpis po KAŻDYM ćwiczeniu „zepsuj i napraw" i po każdym
> błędzie, który Cię zatrzymał — nawet jeśli rozwiązanie wydaje się oczywiste.
> Za pół roku nie będzie.
>
> Format wpisu: **Objaw → Diagnoza → Przyczyna → Naprawa → Czego się nauczyłem.**

---

## Spis problemów

| # | Objaw | Warstwa | Etap |
|---|---|---|---|
| [001](#001) | `exit code 137` przy starcie Elasticsearcha | Docker | 2 |
| [002](#002) | Postgres 18 w pętli restartów, „unused mount/volume" | Docker | 1 |
| [003](#003) | `AccessDeniedException` na certyfikatach / snapshotach | Docker | 2 |
| [004](#004) | RabbitMQ: `Not_Authorized`, brak jakichkolwiek użytkowników | RabbitMQ | 3 |
| [005](#005) | `rabbitmqadmin`: `unrecognized subcommand` | RabbitMQ | 3 |
| [006](#006) | Licznik wiadomości w kolejce „nie nadąża" | RabbitMQ | 3 |
| [007](#007) | Polski stemmer kaleczy nazwy własne („Łodzi" → „łodzić") | Elasticsearch | 3 |
| [008](#008) | Klaster `yellow` na jednym node'zie | Elasticsearch | 2 |
| [009](#009) | Eloquent: `NOT NULL` przy kolumnie ustawionej przez trait | Laravel | 6 |
| [010](#010) | `parent::metoda()` nie widzi metody z traita | PHP | 6 |
| [011](#011) | `*/` wewnątrz treści komentarza `/** ... */` ubija plik | PHP | 6 |
| [012](#012) | `json_encode(4.0)` renderuje `4`, nie `4.0` | PHP | 6 |
| [013](#013) | Doctrine: „Invalid platform version" na `DATABASE_URL` | Symfony | 6 |
| [014](#014) | Mapowanie ES: „Invalid stemmer class specified: Polish" | Elasticsearch | 6 |
| [015](#015) | Messenger: retry wywala się na `encode()` transportu | Symfony | 6 |
| [016](#016) | Caddy globalnie przekierowuje HTTP→HTTPS mimo jawnego portu | Caddy | 6 |
| [017](#017) | External versioning: kolizja `sequence` między różnymi agregatami — **naprawione w Panel [2/8]** | Architektura | 6 |
| [018](#018) | `php artisan test` w kontenerze czyści prawdziwą bazę deweloperską | Testy | 6 |
| [019](#019) | `search_after`: sort po `_id` odrzucony — fielddata wyłączone | Elasticsearch | 7 |
| [020](#020) | `_rank_eval`: `ratings` po aliasie dają same "unrated_docs" | Elasticsearch | 7 |
| [021](#021) | Restart klastra 3-node wisi w nieskończoność (`master_not_discovered`) | Docker | 2 |
| [022](#022) | `port is already allocated` — stack wstaje w połowie | Docker | 7 |
| [023](#023) | Przewinięcie wyników po minucie: `search_context_missing_exception` (500) | Elasticsearch | 7 |
| [024](#024) | ESLint/Vite na hoście: `Cannot find native binding` | Docker / Node | 7 |
| [025](#025) | Node'y ES znikają bez logu zamknięcia, restartują się w kółko | Docker | 7 |
| [026](#026) | 18 testów Fortify pada tylko w kontenerze (419) — i testy WCIĄŻ czyściły Postgresa | Laravel / PHPUnit | 7 |
| [027](#027) | Retry Messengera: `no exchange 'delays'` -> DLQ po 1 próbie (a pod spodem retry bez limitu) | RabbitMQ / Symfony | 7 |
| [040](#040) | Zablokowany sprzedawca, a jego oferty wciąż w `/search` (worker polityk) | Kolejka / Portal sprzedawcy | Portal |
| [041](#041) | Szósta zmiana ceny w dobie przechodzi (dev DB z migracjami sprzed poprawki precyzji) | Postgres / Portal sprzedawcy | Portal |
| [042](#042) | „Ktoś w międzyczasie zmienił tę ofertę — odśwież stronę” (konflikt wersji) | Portal sprzedawcy | Portal |
| [043](#043) | Przed wdrożeniem portalu na k3s: pre-flight danych i okno 500 po migracji | k3s / Postgres / Portal sprzedawcy | Portal |
| [044](#044) | Zamówienia nie wygasają, towar wisi zarezerwowany (`catalog-scheduler`) | Scheduler / Zamówienia | Zamówienia |
| [045](#045) | Zamówienie wisi w „Sprawdzamy dostępność” (`placed`) | Kolejka / Zamówienia | Zamówienia |
| [046](#046) | Krok sagi w `failed_jobs`: diagnoza i ręczne ponowienie | Kolejka / Zamówienia | Zamówienia |
| [047](#047) | Bramka płatności: przyciski deweloperskie i webhook, który nie dochodzi | Caddy / Kolejka / Zamówienia | Zamówienia |
| [048](#048) | `catalog-scheduler` biegnie, a sweep ginie z kodem 137 i nic nie wygasa | Docker / Scheduler / Zamówienia | Zamówienia |
| [049](#049) | `orders:race-test` w `catalog-app` milknie po ~2 s (kod 137) | Docker / Zamówienia | Zamówienia |
| [050](#050) | search-consumer po restarcie węzłów ES: „No alive nodes” przy klastrze `green`, indeks stoi | Elasticsearch / Symfony (search) | Zamówienia (istniało wcześniej) |
| [051](#051) | Filebeat działa, a do `logs-app-*` nic nie dociera (sterownik logów `local`) | Docker / Filebeat | 11 |
| [052](#052) | Alert `SearchConsumerDown`: nikt nie odpytuje `search.product.sync` (Messenger `basic.get`, `consumers` zawsze 0) | Prometheus / RabbitMQ / Symfony | 11 |
| [053](#053) | Metryki osiągalne z zewnątrz przez sfałszowany `Host` i `/index.php/prometheus` | Caddy / bezpieczeństwo | 11 |
| [054](#054) | `CatalogHttpErrorRate` nie widzi ruchu przeglądarki (etykieta `host` z portem) | Caddy / Prometheus | 11 |
| [055](#055) | Grafana 13 przy `mem_limit: 256m` nie wstaje | Docker / Grafana | 11 |
| [056](#056) | Metricbeat: ~66 MB/h w `.monitoring-es-*` i obcy indeks `metricbeat-9.5.1` | Metricbeat / Elasticsearch | 11 |
| [057](#057) | Metricbeat bez `user: root`: `meta.json: permission denied` | Docker / Metricbeat | 11 |
| [058](#058) | Węzły ES restartują się co kilka minut przy włączonym profilu `obs` (nierozwiązane) | Docker / Elasticsearch | 11 |
| [059](#059) | Ślad `trace.id` w Kibanie niepełny | Caddy / Laravel | 11 |
| [060](#060) | Logi Caddy w ES jako surowy JSON, bez `log.level` | Ingest pipeline | 11 |
| [061](#061) | Build `catalog-app` z worktree podmienia Caddyfile w głównym checkoucie | Docker | 11 |
| [062](#062) | Alert `TargetDown` | Prometheus | 11 |
| [063](#063) | Alert `DeadLetterNotEmpty` | RabbitMQ | 11 |
| [064](#064) | Alert `SearchSyncBacklog` | RabbitMQ / search | 11 |
| [065](#065) | Alert `CatalogHttpErrorRate` | Caddy / catalog | 11 |
| [066](#066) | Alerty `OfferModerationOverdue`, `SellerApprovalOverdue` | Portal sprzedawcy | 11 |
| [067](#067) | Alerty `SearchMetricsScrapeError`, `CatalogMetricsScrapeErrors` | Prometheus / metryki | 11 |

---

<a id="001"></a>
## 001 — Elasticsearch ginie z `exit code 137`

**Objaw**
Kontener startuje, w logach widać normalny bootstrap JVM, a potem nagle:
```
Elasticsearch died while starting up, exit code: 137
```
W logach **nie ma żadnego błędu** — proces po prostu znika w połowie startu.

**Diagnoza**
```bash
docker info --format '{{.MemTotal}}'      # ile pamięci ma VM Dockera
docker compose ps                          # kontener w pętli restartów
make doctor                                # gotowa kontrola
```

**Przyczyna**
137 = 128 + 9 = proces dostał **SIGKILL**. W kontekście kontenerów prawie zawsze
oznacza **OOM killer**: kernel zabił proces, bo zabrakło pamięci. JVM próbuje
zaalokować heap (`-Xms` alokuje od razu!), nie mieści się i ginie.

W moim przypadku: Docker Desktop miał 7,75 GB, a profil `cluster` chciał
3 node'y × 3 GB heapu ≈ 13 GB.

**Naprawa**
1. Docker Desktop → Settings → Resources → Memory: podnieś
2. albo zmniejsz `ES_HEAP` w `.env` (tabela dopasowania jest w pliku)
3. albo użyj `make up` (1 node) zamiast `make up-cluster`

**Czego się nauczyłem**
- **137 = OOM.** Zapamiętaj to raz na zawsze; oszczędzi Ci godzinę.
- `-Xms` = `-Xmx` znaczy, że ES bierze **całą** pamięć heapu od razu przy starcie.
  Nie „urośnie w razie potrzeby" — albo jest miejsce, albo node nie wstaje.
- Realne zużycie ES ≈ **1,6 × heap** (off-heap, metaspace, bufory, stosy wątków).
- Dlatego `make up` uruchamia teraz `tools/doctor.sh` **przed** startem —
  lepiej dostać czytelny komunikat niż milczące 137.

---

<a id="002"></a>
## 002 — Postgres 18 restartuje się w pętli

**Objaw**
```
Error: in 18+, these Docker images are configured to store database data in a
       format which is compatible with "pg_ctlcluster" ...
       Counter to that, there appears to be PostgreSQL data in:
         /var/lib/postgresql/data (unused mount/volume)
```

**Przyczyna**
**Postgres 18 zmienił zalecany punkt montowania.** Wszystkie tutoriale (i mój
pierwszy compose) montują `/var/lib/postgresql/data`. Od wersji 18 obraz układa
dane w podkatalogu z numerem wersji, żeby dało się użyć `pg_upgrade --link`
bez przekraczania granicy montowania.

**Naprawa**
```yaml
volumes:
  - pg-data:/var/lib/postgresql      # NIE /var/lib/postgresql/data
```
Wolumen trzeba utworzyć od nowa (dane w starym układzie są nie do użycia w miejscu).

**Czego się nauczyłem**
Cena bycia na najnowszych wersjach: dokumentacja i Stack Overflow są o krok w tyle.
Zysk: takie rzeczy poznajesz na własnym laptopie, a nie w trakcie migracji produkcji.

---

<a id="003"></a>
## 003 — `AccessDeniedException` na wolumenie

**Objaw**
```
java.nio.file.AccessDeniedException: /certs/ca.p12
```
oraz, przy rejestracji repozytorium snapshotów:
```
[local-fs] path is not accessible on master node
caused_by: access_denied_exception: /snapshots/tests-XXXX
```

**Przyczyna**
Świeży **named volume** Dockera należy do `root:root`. Elasticsearch w kontenerze
działa jako uid **1000**. Nie ma prawa zapisu.

**Naprawa**
Kontener inicjalizacyjny `es-init` startuje jako root, przygotowuje katalogi
i oddaje je właściwemu użytkownikowi:
```bash
chown -R 1000:0 /snapshots && chmod 0775 /snapshots
chown 1000:0 /certs/*.p12  && chmod 0640 /certs/*.p12
```

**Czego się nauczyłem**
- Bind mount dziedziczy uprawnienia z hosta; **named volume startuje jako root**.
- Wzorzec „kontener init jako root, aplikacja jako user" to standard w Kubernetes
  (initContainers) — tutaj widać, skąd się wziął.
- Błąd przy snapshotach był **opóźniony** — pojawił się dopiero przy rejestracji
  repozytorium, nie przy starcie. Uprawnienia sprawdzaj wcześnie.

---

<a id="004"></a>
## 004 — RabbitMQ: `Not_Authorized`, `list_users` pusty

**Objaw**
Broker wstaje, healthcheck zielony, kolejki i exchange'e na miejscu.
Ale każda operacja zwraca `Not_Authorized`, a `rabbitmqctl list_users` nie
pokazuje **żadnego** użytkownika — mimo ustawionego `RABBITMQ_DEFAULT_USER`.

**Przyczyna**
`RABBITMQ_DEFAULT_USER` / `RABBITMQ_DEFAULT_PASS` działają **tylko wtedy, gdy node
startuje z pustą bazą użytkowników**. Ustawienie `load_definitions` liczy się jako
inicjalizacja — tworzenie domyślnego użytkownika zostaje **pominięte**.
Dwa mechanizmy inicjalizacji wykluczają się nawzajem.

**Naprawa**
Użytkownicy muszą być w `definitions.json`. Ale definicje trzymają **hash**, nie
hasło — więc generujemy plik ze wzorca (`tools/render-rabbitmq-definitions.py`):
```
sól = 4 losowe bajty
password_hash = base64( sól + sha256(sól + hasło) )
hashing_algorithm = rabbit_password_hashing_sha256
```
Dzięki temu jedynym źródłem prawdy zostaje `.env`.

⚠️ Definicje importują się **tylko przy inicjalizacji node'a**. Po zmianie musisz
usunąć wolumen: `docker volume rm marketplace_rabbitmq-data`.

**Czego się nauczyłem**
- „Healthcheck zielony" ≠ „usługa użyteczna". Dlatego powstał `make smoke`.
- Kiedy dwa mechanizmy konfiguracji robią to samo, sprawdź, czy się nie wykluczają.

---

<a id="005"></a>
## 005 — `rabbitmqadmin`: `unrecognized subcommand`

**Objaw**
```
error: unrecognized subcommand 'exchange=marketplace.events'
```

**Przyczyna**
RabbitMQ 4.x dostarcza **rabbitmqadmin v2** — przepisany od zera, z zupełnie inną
składnią. Stary format `klucz=wartość` nie działa.

**Naprawa**
```bash
# STARE (v1) — nie działa:
rabbitmqadmin publish exchange=X routing_key=Y payload=Z

# NOWE (v2):
rabbitmqadmin --username U --password P \
  publish message --exchange X --routing-key Y --payload Z
```
Uwaga na kolejność: flagi globalne (`--username`) **przed** podkomendą.
`-p` przed `publish` to hasło, a po `publish message` to `--properties`.

**Czego się nauczyłem**
Przy skoku MAJOR sprawdzaj też **narzędzia CLI**, nie tylko API i protokół.
Protokół AMQP się nie zmienił — zmieniło się narzędzie administracyjne.

---

<a id="006"></a>
## 006 — Licznik wiadomości w kolejce „nie nadąża"

**Objaw**
Publikacja zwraca `Message published and routed successfully`, ale
`rabbitmqctl list_queues messages` nadal pokazuje starą wartość.
Po `purge_queue` licznik też przez chwilę zostaje niezerowy.

**Diagnoza**
```bash
for i in 1 3 6 10; do sleep 1; rabbitmqctl list_queues name messages; done
```
Wartość ustala się po **~6 sekundach**.

**Przyczyna**
Statystyki kolejek są zbierane okresowo (eventually consistent), nie liczone
na żądanie. To świadomy kompromis wydajnościowy — liczenie w locie przy każdym
zapytaniu byłoby kosztowne.

**Naprawa**
W testach i skryptach **odpytuj w pętli z timeoutem**, nie zgaduj `sleep`
(`wait_for_depth` w `tools/smoke-test.sh`).

**Czego się nauczyłem**
- Nie diagnozuj systemu kolejkowego na podstawie jednego odczytu licznika.
- Ta sama zasada dotyczy monitoringu głębokości kolejek: alertuj na **trendzie**,
  nie na pojedynczej próbce.
- To dokładnie ta sama klasa zjawiska co `refresh_interval` w Elasticsearchu —
  system jest szybki, bo **nie** aktualizuje wszystkiego natychmiast.

---

<a id="007"></a>
## 007 — Polski stemmer kaleczy nazwy własne

**Objaw**
```
"Kupiłem najlepsze buty do biegania w Łodzi"
  -> kupić | najlepszy | but | biegać | łodzić
```
„Łodzi" zostało sprowadzone do nieistniejącego „łodzić".

**Przyczyna**
`analysis-stempel` jest **algorytmiczny/statystyczny**, nie słownikowy. Zgaduje
rdzeń na podstawie wzorców odmiany. Dla nazw własnych, marek i numerów
katalogowych to zgadywanie jest błędne.

Konsekwencja praktyczna: wyszukiwanie „Łódź" może nie trafić w dokument z „Łodzi"
w sposób, którego się spodziewasz — albo, gorzej, trafi w coś przypadkowego.

**Naprawa (do zrobienia w module 3)**
1. **multi-field**: `name` (analizowane) + `name.raw` (`keyword`) — marki i nazwy
   własne dopasowujemy dokładnie, nie przez stemmer
2. **`keyword_marker`** z listą wyjątków (marki, miasta) — chroni tokeny przed stemmerem
3. w `multi_match` dawać wyższy boost polu `raw` niż zrdzeniowanemu

**Czego się nauczyłem**
- Stemmer to **kompromis**: zyskujesz odmianę, tracisz precyzję nazw własnych.
- Zawsze sprawdzaj analizator na **realnych danych z domeny** (marki, modele, EAN),
  nie na zdaniu z podręcznika.
- `POST _analyze` to pierwsze narzędzie przy „nie znajduje, a przecież tam jest".

---

<a id="008"></a>
## 008 — Klaster `yellow` na jednym node'zie

**Objaw**
`GET _cluster/health` → `"status": "yellow"` mimo że nic nie jest zepsute.

**Diagnoza**
```bash
make es-shards          # które shardy są UNASSIGNED
make es-explain         # DLACZEGO nie da się ich przypisać
```

**Przyczyna**
Indeksy (systemowe Kibany też) proszą o repliki. Elasticsearch **nigdy nie umieści
repliki na tym samym node'zie co primary** — to by nie dawało żadnej odporności.
Przy jednym node'zie repliki są więc trwale nieprzypisane → yellow.

**Naprawa**
Żadna nie jest potrzebna — to **poprawne zachowanie**, nie awaria.
- w dev: `number_of_replicas: 0` usunie yellow
- właściwie: uruchom więcej node'ów (`make up-cluster`)

**Czego się nauczyłem**
- Kolor mówi **wyłącznie o przypisaniu shardów**, nie o „zdrowiu" w potocznym sensie.
- `yellow` = wszystkie primary działają, brakuje replik → **dane kompletne, brak HA**.
- `red` = brakuje primary → **część danych niedostępna**. To jest alarm.
- Na produkcji z 1 nodem yellow jest normą i alertowanie na nim to szum.

---

<a id="009"></a>
## 009 — Eloquent: `NOT NULL` przy kolumnie ustawionej przez trait

**Objaw**
```
SQLSTATE[23000]: Integrity constraint violation: 19 NOT NULL constraint
failed: outbox.sequence
```
mimo że kod jawnie ustawiał tę wartość przed zapisem.

**Diagnoza**
Wypisanie `$model->attributesToArray()` tuż po `static::create($attrs)`
pokazywało brak klucza `version` w ogóle — mimo że trait go dokładał do
tablicy atrybutów przekazywanej do `create()`.

**Przyczyna**
`$fillable` na modelu nie zawierał `'version'`. Mass-assignment guard
Eloquenta **po cichu odrzuca** klucze spoza `$fillable` — bez wyjątku,
bez ostrzeżenia. Trait poprawnie dokładał wartość, ale `create()` i tak ją
wyrzucał, zanim doszło do SQL-a.

**Naprawa**
Dodać `'version'` do `$fillable` na każdym modelu, który go używa.

**Czego się nauczyłem**
- Mass assignment w Laravelu **milczy** przy odrzuceniu pola — nie rzuca
  wyjątku jak np. walidacja. Jeśli coś "znika" między kodem a bazą,
  `$fillable`/`$guarded` to pierwsze podejrzane miejsce.
- Osobna, głębsza pułapka pod spodem: Eloquent **nie odświeża w pamięci**
  kolumn wypełnionych przez `DEFAULT` bazy danych po `INSERT`. Poleganie na
  `->default(1)` z migracji i pomijanie wartości w PHP wygląda na
  oszczędność kodu, ale zostawia model w niespójnym stanie do pierwszego
  `->fresh()`. Ustawiaj jawnie to, co ma być znane od razu w PHP.

---

<a id="010"></a>
## 010 — `parent::metoda()` nie widzi metody zdefiniowanej w traicie

**Objaw**
```
Call to undefined method App\Models\Offer::updateWithOutbox()
```
mimo że `Offer` używa traita `EmitsOutboxEvents`, który **ma** metodę
`updateWithOutbox()`, a `Offer` jawnie ją nadpisuje i woła
`parent::updateWithOutbox(...)`.

**Diagnoza**
Błąd pojawiał się dopiero **przy pierwszym wywołaniu w runtime** — `php -l`
i statyczna analiza nic nie wykrywają, bo to nie jest błąd składni.

**Przyczyna**
`parent::` w PHP odnosi się wyłącznie do **klasy bazowej w hierarchii
dziedziczenia** (tu: `Illuminate\Database\Eloquent\Model`), nigdy do
traita. Trait jest "wklejany" w ciało klasy — metoda z traita, którą klasa
nadpisuje, nie tworzy relacji parent/child. `Model` nie ma metody
`updateWithOutbox()`, więc `parent::` szuka jej tam i nie znajduje.

**Naprawa**
W traicie: publiczna metoda `updateWithOutbox()` to cienki wrapper wołający
`protected function performUpdateWithOutbox()` z właściwą logiką. Model,
który chce nadpisać zachowanie, nadpisuje `updateWithOutbox()` i woła
`$this->performUpdateWithOutbox(...)` bezpośrednio (nie `parent::`) — działa,
bo to metoda tej samej klasy (odziedziczona przez trait), nie klasy bazowej.

**Czego się nauczyłem**
Jeśli trait ma metodę, którą implementująca klasa będzie nadpisywać i chce
wywołać oryginalną logikę — nie projektuj tego z myślą o `parent::`. Albo
alias przez `insteadof`/`as` w konflikcie traitów, albo (prościej) rozbij
na publiczny punkt wejścia + chronioną metodę z logiką, wołaną przez `$this`.

---

<a id="011"></a>
## 011 — `*/` wewnątrz treści komentarza blokowego ubija plik

**Objaw**
```
Parse error: syntax error, unexpected identifier "review", expecting "function"
```
w linii, która była zwykłym tekstem wewnątrz `/** ... */`.

**Przyczyna**
Komentarz zawierał frazę „offer.*/review.*" (chciałem napisać „offer.* lub
review.*" skrótem). Parser PHP nie wie nic o Markdownie ani o intencji —
sekwencja znaków `*/` **zawsze** kończy blok `/** ... */`, niezależnie od
kontekstu. Wszystko po niej (reszta zdania, kolejne linie) stało się
"zwykłym kodem" i się nie parsowało.

**Naprawa**
Nie używać `*/` (ani samego `/*`) w treści komentarza blokowego. Pisać
pełnymi słowami ("zdarzenia oferty i opinii") zamiast skrótów z gwiazdką.

**Czego się nauczyłem**
`php -l` łapie to natychmiast i bezbłędnie — ale tylko jeśli się je
uruchomi. Lintuj **każdy** nowy plik PHP zanim przejdziesz dalej, nawet
"tylko komentarz". Ten błąd akurat jest tani (widać go od razu), ale uczy
ogólnej zasady: komentarz też jest częścią gramatyki języka, nie jest
"bezpiecznym" tekstem.

---

<a id="012"></a>
## 012 — `json_encode(4.0)` renderuje `4`, nie `4.0`

**Objaw**
Test API asercji `assertJsonPath('rating_avg', 4.0)` failował z
"Failed asserting that 4 is identical to 4.0", mimo że PHP-owa wartość
przed serializacją **była** floatem (`round(4.0, 2)` zwraca `float(4)`).

**Przyczyna**
PHP-owy `json_encode()` domyślnie renderuje float, którego wartość jest
liczbą całkowitą, **bez** części dziesiętnej: `json_encode(4.0)` daje
napis `"4"`, nie `"4.0"`. Po drugiej stronie kontraktu (Symfony,
`json_decode("4")`) taka wartość staje się `int`, nie `float` — cicha
utrata informacji o typie w danych przekazywanych między serwisami.

**Naprawa**
`response()->json($data, options: JSON_PRESERVE_ZERO_FRACTION)` — ta flaga
każe silnikowi JSON zachować `.0` dla floatów będących liczbami całkowitymi.

**Czego się nauczyłem**
Typ w PHP (`float`) i typ w JSON-ie na drucie to dwie różne rzeczy — jedno
nie gwarantuje drugiego bez jawnej flagi. W systemie z wieloma serwisami
(Laravel → Symfony), gdzie kontraktem jest JSON, ten rodzaj rozjazdu nie
wybuchnie od razu przy pierwszym teście integracyjnym — wybuchnie kiedyś,
w polu liczbowym, które akurat wyszło całkowite, i będzie wyglądał jak
błąd zupełnie gdzie indziej.

---

<a id="013"></a>
## 013 — Doctrine: „Invalid platform version" na `DATABASE_URL`

**Objaw**
```
Invalid platform version "" specified. The platform version has to be
specified in the format: "<major_version>.<minor_version>.<patch_version>".
```
przy pierwszym `doctrine:migrations:migrate` na żywym Postgresie — coś,
czego nie dało się złapać wcześniej, bo Symfony (w odróżnieniu od Laravela
z SQLite) nie ma taniego sposobu na testowanie migracji bez prawdziwej bazy.

**Przyczyna**
`DATABASE_URL` w `compose.yaml` nie miało parametru `serverVersion`.
Doctrine DBAL wymaga znać wersję Postgresa, żeby wybrać właściwy dialekt
SQL — bez `?serverVersion=...` w DSN dostaje pusty string zamiast numeru
i odmawia startu, zamiast (jak można by się spodziewać) samodzielnie
wykryć wersję przez połączenie.

**Naprawa**
```
DATABASE_URL: postgresql://user:pass@host:5432/db?serverVersion=18.4.0&charset=utf8
```
Druga pułapka po drodze: Doctrine wymaga **trzech** segmentów
(major.minor.patch), a nasz `POSTGRES_VERSION` w `.env` to tylko `18.4`
(taka jest konwencja tagów obrazu) — nie da się użyć tej zmiennej wprost,
potrzebna osobna, jawna wartość z trzecim segmentem.

**Czego się nauczyłem**
Laravel + SQLite in-memory dał złudne poczucie bezpieczeństwa — 51/51
testów przechodziło, a mimo to Symfony miało błąd, którego żaden test
by nie złapał, bo dotyczył configu specyficznego dla Postgresa. Kod można
zweryfikować bez Dockera; **konfigurację łączącą się z realną
infrastrukturą — nie zawsze.**

---

<a id="014"></a>
## 014 — Mapowanie ES: „Invalid stemmer class specified: Polish"

**Objaw**
```
400 Bad Request: {"error":{"...","reason":"Invalid stemmer class specified: Polish",
"caused_by":{"type":"class_not_found_exception",
"reason":"org.tartarus.snowball.ext.PolishStemmer"}},"status":400}
```
przy `search:index:create`.

**Przyczyna**
Filtr `pl_stem` w `products-v1.json` był zdefiniowany jako
`{"type": "stemmer", "language": "polish"}` — to generyczny filtr oparty
na bibliotece Snowball, która **nie ma polskiego** (`PolishStemmer` nie
istnieje w Snowball). Polski stemming daje dopiero plugin
`analysis-stempel`, który wystawia **własny, dedykowany typ filtra**:
`polish_stem`. Sam wcześniej to poprawnie udokumentowałem w
`docs/02-APLIKACJE.md`, a przy pisaniu właściwego pliku mapowania
pomyliłem formę.

**Naprawa**
```json
"pl_stem": { "type": "polish_stem" }
```
Bez `language` — to nie generyczny filtr, tylko gotowy, jeden filtr od
konkretnego pluginu.

**Czego się nauczyłem**
`GET _cat/plugins` pokaże, że `analysis-stempel` jest zainstalowany — ale
to nie znaczy, że użyłeś go poprawnie w mapowaniu. Dwie różne rodziny
filtrów stemujących (`stemmer`+`language` z rdzenia ES vs. dedykowany typ
z pluginu) łatwo pomylić, bo obie "brzmią" tak samo w JSON-ie. Warto
przetestować `_analyze` na WŁAŚCIWYM, nazwanym analizatorze z indeksu
(`POST products-search/_analyze {"analyzer":"pl_index",...}`), nie tylko
na wbudowanym `"polish"` — dopiero to łapie błędy w customowej definicji.

---

<a id="015"></a>
## 015 — Messenger: retry wywala się na `encode()` transportu

**Objaw**
Handler poprawnie rzuca `RecoverableMessageHandlingException` (błąd
przejściowy, retry ma sens), ale wiadomość i tak ląduje w DLQ po
**jednej** próbie, nie po skonfigurowanych trzech. W logu:
```
Symfony\Component\Messenger\Exception\LogicException: <treść wyjątku z encode()>
  at App\Messenger\ExternalJsonEnvelopeSerializer->encode()
  ... SendFailedMessageForRetryListener->onMessageFailed() ...
```

**Przyczyna**
Napisałem `encode()` w customowym serializerze transportu tak, żeby
zawsze rzucał wyjątkiem — założenie było "Symfony nigdy nie publikuje do
tych transportów, tylko konsumuje". Błędne założenie: **retry TEŻ jest
publikacją**. `retry_strategy` (max_retries, multiplier) działa przez
ponowne wysłanie wiadomości na TEN SAM transport — a to wymaga
`encode()`. Mój `encode()` rzucał, więc PRÓBA RETRY sama się wywalała,
i wiadomość szła do DLQ natychmiast, maskując przy tym oryginalny,
pierwotny błąd w logu konsoli (widoczny był tylko wyjątek z `encode()`,
nie ten, który naprawdę spowodował niepowodzenie — trzeba było zajrzeć do
`var/log/dev.log`, Monologa, żeby zobaczyć oba, po kolei, osobno).

**Naprawa**
Zaimplementować `encode()` jako lustrzane odbicie `decode()` — ten sam
kształt JSON, w drugą stronę. Retry wtedy działa jak zaprojektowano:
ponawia z backoffem (multiplier), a dopiero po wyczerpaniu prób ląduje
w `failure_transport`.

**Czego się nauczyłem**
- Jeśli implementujesz tylko połowę dwukierunkowego interfejsu (tu:
  `SerializerInterface::decode()`/`encode()`) z założeniem "ta druga
  połowa nigdy się nie wykona" — sprawdź DOKŁADNIE, czy framework
  faktycznie nigdy jej nie wywoła sam, wewnętrznie, z innego powodu niż
  ten, przed którym się zabezpieczasz. Retry, DLQ i inne mechanizmy
  odporności często cicho korzystają z tych samych ścieżek co "normalna"
  praca.
- Gdy stack trace w konsoli wygląda podejrzanie krótko/nie na temat —
  sprawdź plik logu (Monolog), nie tylko to, co wypisało się na stdout.
  Console error handler pokazuje często tylko OSTATNI wyjątek w łańcuchu,
  nie ten, który zaczął całą kaskadę.

> **KOREKTA (ETAP 7, wpis [027](#027)):** po tej naprawie retry nadal NIE
> działał "jak zaprojektowano" — nikt tego nie sprawdził na żywym brokerze.
> Następny w kolejce był brak exchange'a opóźnień (`no exchange 'delays'`,
> bo `auto_setup: false`), a pod nim retry bez limitu (`forceRetry`
> domyślnie `true` + serializer gubiący `RedeliveryStamp`). Do tego
> wyczerpane próby trafiają do `failure_transport` (Doctrine), a nie do DLQ
> w RabbitMQ.

---

<a id="016"></a>
## 016 — Caddy globalnie przekierowuje HTTP→HTTPS mimo jawnego portu

**Objaw**
Kod jawnie łączy się przez `http://catalog-app/...`, ale błąd TLS mówi
o **`https://catalog-app/...`**:
```
TLS connect error: error:0A000438:SSL routines::tlsv1 alert internal error
for "https://catalog-app/api/internal/products/1/projection".
```
mimo że dedykowany blok Caddy'ego dla ruchu wewnętrznego był zapisany
jako `catalog-app:80 { ... }` (jawny port, bez schematu `https`).

**Przyczyna**
Gdy w Caddyfile istnieje **choćkolwiek jeden** site z automatycznym HTTPS
(tu: główny blok `{$SERVER_NAME:catalog.localhost}`), Caddy instaluje
**globalny listener** przekierowujący port 80 → 443 dla wszystkich
requestów, zanim w ogóle dojdzie do routingu po nazwie hosta/site'a.
Samo podanie portu (`nazwa:80`) bez jawnego schematu **nie wystarcza**,
żeby dany blok wypisał się z tego globalnego przekierowania — to
zadziałało inaczej, niż się spodziewałem.

**Naprawa**
Jawny schemat w adresie site'a: `http://catalog-app { ... }` zamiast
`catalog-app:80 { ... }`. To jedyny pewny sposób powiedzenia Caddy'emu
"ten blok ma być czystym HTTP, nie wciągaj go do automatycznego HTTPS".

**Czego się nauczyłem**
W konfiguracji reverse proxy/serwera "wygląda na to, że powinno działać"
nie zastępuje sprawdzenia na żywo. Zachowania automatyczne (tu: auto-HTTPS)
bywają **globalne dla całego procesu**, nie per-blok, nawet jeśli
składnia sugeruje izolację. Warto zawsze przetestować DOKŁADNIE tę ścieżkę
sieciową, którą będzie szedł realny ruch (serwis-do-serwisu, nie tylko
przeglądarka-do-serwera) — te dwie ścieżki mogą trafiać w zupełnie różne
bloki konfiguracji.

---

<a id="017"></a>
## 017 — External versioning: kolizja `sequence` między różnymi agregatami

**Objaw**
Zdarzenie `offer.created` zostało odrzucone jako "nieaktualne" (409)
w logu:
```
Pominięto nieaktualne zdarzenie (nowsza wersja już zaindeksowana).
{"product_id":"2","event_type":"offer.created","sequence":1}
```
mimo że było to PIERWSZE zdarzenie dla tej oferty, nie duplikat ani
spóźniona dostawa. Dane w Elasticsearchu wyszły poprawne — ale przez
przypadek, nie dzięki poprawnie działającemu mechanizmowi.

**Diagnoza**
Dwa zdarzenia (`product.created` i `offer.created`) dla tego samego
produktu przyszły niemal jednocześnie. Pierwsze zaindeksowało dokument
z `version=1`. Drugie próbowało zapisać **też** z `version=1` (nie 2) —
ES odrzucił jako nie-nowszą wersję.

**Przyczyna**
`sequence` w kopercie zdarzenia to KOPIA lokalnego licznika `version`
z tabeli źródłowej (`products.version` albo `offers.version` w Laravelu)
— a to są **dwa niezależne liczniki**, oba zaczynające się od 1. Jeden
dokument Elasticsearcha bywa jednak budowany z KILKU różnych agregatów
źródłowych (produkt + jego oferty), które piszą do tego samego `_id`.
External versioning zakłada JEDEN monotoniczny licznik na dokument — a tu
dostaje dwa różne, przypadkowo nakładające się liczniki.

**Dlaczego tym razem nie zaszkodziło**
`CatalogProjectionClient` zawsze pobiera PEŁNY aktualny stan produktu
(łącznie z ofertami), niezależnie od tego, które zdarzenie wywołało
przeliczenie. Pierwszy zapis (z `product.created`) i tak zawierał świeże
dane oferty. Odrzucenie drugiego zdarzenia nie zgubiło więc żadnej
informacji — ale to przypadek tej konkretnej kolejności zdarzeń, nie
gwarancja.

**Status: naprawione w Panel [2/8]** (pierwotnie: "NIE naprawione,
świadomie odłożone"). Panel edycji pokazał, że to nie teoria: zmiana ceny
drugiej oferty z niskim `sequence` dostawała 409 i ginęła po cichu. Wersja
dokumentu = `projected_at_us` — chwila odczytu projekcji w µs z zegara
Postgresa catalogu (ok. 1,79·10¹⁵), mierzona przed odczytem danych. Jeden
zegar porządkuje zapisy ze wszystkich agregatów. Szczegóły:
`docs/superpowers/specs/2026-10-11-panel-edycji-design.md` (część 1)
i `docs/blog/panel-edycji.md`. Dokumenty sprzed zmiany (`_version` 1..3)
przelicza jednorazowo `make reproject` / `make k3s-reproject`.

**Zasada rollbacku (obowiązuje od Panel [2/8]).** NIE cofaj
search-consumera poniżej tej zmiany samym `make k3s-deploy tag=<stary SHA>`.
Stary consumer wersjonuje po `sequence` (1, 2, 3…), a w ES leży już
`_version` ~1.79e15 — dostanie 409 na KAŻDYM dokumencie i po cichu pominie
zapis (409 to dla niego "nieaktualne", czyli sukces). Indeks przestaje się
zmieniać, a w logach nie ma błędu. Rollback poniżej Panel [2/8] = reindeks
do NOWEGO indeksu (pusty, więc niskie wersje przejdą) + atomowe przełączenie
aliasu `products-search`, dopiero potem stary obraz.

**Kierunek właściwej naprawy (przyszły moduł)**
Jeden monotoniczny licznik NA DOKUMENT, utrzymywany przez search-service
(np. w tabeli stanu indeksacji), zamiast kopiowania 1:1 wersji ze źródła.
Alternatywa: porównywać po `occurred_at` (znacznik czasu zdarzenia)
zamiast surowego numeru wersji — mniej precyzyjne przy zdarzeniach z tej
samej milisekundy, ale odporne na kolizję liczników z różnych tabel.

**Czego się nauczyłem**
"Zadziałało" i "mechanizm jest poprawny" to dwa różne stwierdzenia —
trzeba je sprawdzać osobno. External versioning wymaga **jednego wspólnego
licznika** dla wszystkiego, co pisze do tego samego dokumentu; ponowne
użycie liczników z systemu źródłowego działa tylko wtedy, gdy jeden
dokument = jeden agregat źródłowy. W momencie, gdy denormalizujesz
wiele tabel w jeden dokument (a to jest dokładnie to, co robi ES —
patrz `04-ES-JAKO-PLATFORMA.md`), ten prosty schemat wersjonowania
przestaje wystarczać.

**Dopisek (ETAP 7, seeder)** — `SeedMarketplaceCommand` (`marketplace:seed`)
trafia na dokładnie ten problem przy generowaniu wielu ofert na produkt
w krótkim czasie: `offer.created` (sequence=1) koliduje z `product.created`
(sequence=1) tego samego produktu i zostaje odrzucony jako "stale", mimo że
to pierwsza oferta. Seeder obchodzi to STRUKTURALNIE, nie łatając
`ProductSyncHandler`: buduje w Postgresie pełny stan produktu (wszystkie
oferty) NAJPIERW, dopiero potem emituje jedno zdarzenie `product.created`.
Ponieważ `search-consumer` i tak robi pełny read-back projekcji, jeden
event wystarcza do zaindeksowania produktu ze wszystkimi ofertami —
sprawdzone na 1500 produktach / ~4500 ofertach: `products-search`
count = liczba produktów, bez strat.

---

<a id="018"></a>
## 018 — `php artisan test` w kontenerze czyści PRAWDZIWĄ bazę deweloperską

**Objaw**
`php artisan test` uruchomiony na hoście: 51/51 zielone. Ten sam
`php artisan test` uruchomiony **w kontenerze** (`docker compose exec
catalog-app php artisan test`): 18 nieudanych. Po sprawdzeniu — dane
utworzone chwilę wcześniej ręcznie (E2E test produktu/oferty) **zniknęły
z prawdziwego Postgresa**.

**Przyczyna**
`phpunit.xml` ustawiał `DB_CONNECTION=sqlite`/`DB_DATABASE=:memory:` przez
`<env>` **bez atrybutu `force="true"`**. Domyślne zachowanie PHPUnit:
taki wpis ustawia zmienną TYLKO jeśli nie jest już obecna w środowisku
procesu. Na hoście nic nie ustawiało `DB_CONNECTION` jako realną zmienną
systemową, więc wpis z `phpunit.xml` wygrywał. W kontenerze `compose.yaml`
wstrzykuje `DB_CONNECTION=pgsql`, `DB_HOST=postgres` jako PRAWDZIWE
zmienne środowiskowe kontenera — te istniały już przed startem PHPUnit,
więc nieforsowany `<env>` był po cichu ignorowany. Testy łączyły się
z prawdziwą bazą `catalog`, a `RefreshDatabase` (używane przez wszystkie
testy Feature) ją migrowało/czyściło między testami — kasując rzeczywiste
dane deweloperskie.

**Naprawa**
```xml
<env name="DB_CONNECTION" value="sqlite" force="true"/>
<env name="DB_DATABASE" value=":memory:" force="true"/>
<env name="DB_HOST" value="" force="true"/>
```
`force="true"` każe PHPUnit nadpisać zmienną BEZWARUNKOWO, niezależnie od
tego, co już jest w środowisku procesu.

**Czego się nauczyłem**
- To jest **realne ryzyko utraty danych**, nie tylko niewygoda — dokładnie
  ten rodzaj błędu, przed którym miał chronić SQLite-w-pamięci z ETAPU 6.
  Ochrona działała tylko na hoście; w kontenerze była iluzoryczna.
- Zasada ogólna: konfiguracja izolacji środowiska testowego, która "działa"
  w jednym kontekście uruchomienia, może **cicho przestać działać** w innym
  (host vs kontener, CI vs lokalnie) — jeśli mechanizm izolacji polega na
  "ustaw, jeśli jeszcze nie ustawione", a nie na jawnym wymuszeniu.
  Zawsze `force="true"` na zmiennych, których wyciek oznacza realną szkodę
  (baza danych!), nigdy nie zakładaj, że brak konfliktu dziś oznacza brak
  konfliktu jutro (albo w innym środowisku uruchomieniowym).
- **Przed pierwszym `php artisan test` w nowym środowisku uruchomieniowym
  (nowy kontener, CI, inny host) — zweryfikuj, że baza testowa faktycznie
  jest izolowana**, np. `php artisan tinker --execute 'echo config("database.default");'`
  powinno pokazać `sqlite`, nie `pgsql`.

> **KOREKTA (ETAP 7, wpis [026](#026)):** ta naprawa była NIEPEŁNA i baza
> w kontenerze **nadal nie była izolowana**. `<env force="true">` ustawia tylko
> `putenv()` i `$_ENV`, a Laravel czyta najpierw `$_SERVER`, gdzie wciąż
> siedziało `DB_CONNECTION=pgsql` z compose. Weryfikacja przez `tinker`
> (poniżej) też była mylna — tinker nie przechodzi przez `phpunit.xml`, więc
> nic nie mówi o środowisku testów. Prawdziwa naprawa: `<server>` w
> `phpunit.xml` + bezpiecznik w `tests/TestCase.php`.

**Efekt uboczny tego dochodzenia**
Przy okazji znaleziono (ale świadomie NIE naprawiono, bo poza zakresem
ETAPU 6) 18 nieudanych testów startera Fortify (`AuthenticationTest`,
`PasswordResetTest`, `SecurityTest` itd.) — przechodzą czysto na hoście,
failują tylko w kontenerze mimo poprawnie izolowanej bazy. Zweryfikowane:
to nie problem hashowania haseł ani samego `Auth::attempt()` (działa
poprawnie w izolacji przez tinker) — coś w cyklu żądanie-sesja klienta
testowego HTTP zachowuje się inaczej w kontenerze. Niezdiagnozowane do
końca — do zbadania osobno, nie blokuje ETAPU 6 (wszystkie testy własne:
outbox, health, projekcja — 12/12 zielone w obu środowiskach).

---

<a id="019"></a>
## 019 — `search_after`: sort po `_id` odrzucony — fielddata wyłączone

**Objaw**
Pierwsze wywołanie `ProductSearchService::search()` z sortem `[['_score' =>
'desc'], ['_id' => 'asc']]` (tie-breaker do stabilnej paginacji, moduł 9)
kończyło się błędem 400:
```
illegal_argument_exception: Fielddata access on the _id field is
disallowed, you can re-enable it by updating the dynamic cluster setting:
indices.id_field_data.enabled
```

**Diagnoza**
Zapytanie wyglądało poprawnie składniowo — problem ujawnił się dopiero przy
realnym wywołaniu na żywym klastrze, nie przy samym budowaniu DSL (stąd
osobne testy jednostkowe DSL i integracyjne na żywym ES — `tests/Unit/
Services/ProductSearchServiceQueryTest.php` tego by nie złapało).

**Przyczyna**
`_id` to pole metadanych, nie zwykłe pole dokumentu — sortowanie po nim
wymaga fielddata (budowania odwróconego indeksu w pamięci dla pola, które
z definicji ma tyle unikalnych wartości co dokumentów). ES **celowo**
blokuje to domyślnie — włączenie tego ustawienia to type of footgun, którego
dokumentacja ES explicite odradza.

**Naprawa**
Point In Time (PIT) + sort po `_shard_doc` zamiast `_id` — dokładnie to,
co moduł 9 (`docs/03-SCIEZKA-NAUKI.md`) opisuje jako poprawny mechanizm
głębokiej paginacji. `_shard_doc` to wewnętrzny numer dokumentu na shardzie,
zawsze unikalny i tani do sortowania, ale wymaga zamrożonego widoku
shardów (stąd PIT). Implementacja: `ProductSearchService::resolvePagination()`
otwiera PIT dla pierwszej strony (`openPointInTime`), cursor koduje
`{pit, sort}` razem (nie sam `sort` — PIT musi być spójny między stronami),
kolejne strony przekazują ten sam (lub odświeżony z odpowiedzi) `pit_id`.

**Czego się nauczyłem**
"Wygląda dobrze w DSL" i "działa na żywym klastrze" to dwa różne testy —
ten konkretny błąd nie miał ŻADNEGO sygnału na poziomie budowania zapytania
w PHP, tylko przy realnym wykonaniu. Pola metadanych (`_id`, `_index`) mają
inne zasady niż zwykłe pola mapowania i nie da się ich używać zamiennie
z polami dokumentu tylko dlatego, że składniowo pasują w to samo miejsce
(`sort`).

---

<a id="020"></a>
## 020 — `_rank_eval`: `ratings` po aliasie dają same "unrated_docs"

**Objaw**
`php artisan search:eval` (Faza 5, harness `_rank_eval` dla nDCG@10)
zwracał `nDCG@10 = 0.000` dla WSZYSTKICH 13 zapytań kontrolnych, mimo że
`ProductSearchService::search()` na te same zapytania zwracał poprawne,
oczekiwane wyniki na czołowych pozycjach (zweryfikowane ręcznie przez
`tinker` przed napisaniem samej komendy eval).

**Diagnoza**
`unrated_docs` w odpowiedzi `_rank_eval` pokazywał dokładnie te same ID
dokumentów, które ręcznie oceniłem jako trafne (`ratings`) — więc dopasowanie
NIE działało, mimo identycznych `_id`. Ręczny `curl` na `_rank_eval` z tym
samym zapytaniem i `"_index": "products-search"` w `ratings` odtworzył
problem 1:1.

**Przyczyna**
`_rank_eval` dopasowuje wpis z `ratings` do trafienia zapytania po PARZE
`_index`+`_id`, **dokładnie** (string match, nie przez alias). Zapytanie
szło przez alias `products-search`, ale każde trafienie w `hits` i tak
raportuje **fizyczny** indeks (`products-v1`) jako swój `_index` — bo alias
to tylko wskaźnik czasu zapytania, nie tożsamość dokumentu. `ratings`
oceniane po nazwie aliasu nigdy nie mogły się dopasować do trafień
raportujących fizyczny indeks.

**Naprawa**
`SearchEvalCommand::resolveAliasTarget()` odpytuje `GET _alias/products-search`
i podstawia PRAWDZIWĄ, aktualną nazwę fizycznego indeksu do `ratings._index`
— **tylko w tej komendzie**, świadomie NIE w `ProductSearchService` (D-11/D-09:
serwis produkcyjny ma zostać ślepy na fizyczne nazwy indeksów, `search:eval`
to narzędzie deweloperskie i może znać ten szczegół, podobnie jak
`search:index:create` po stronie Symfony).

**Czego się nauczyłem**
Alias jest przezroczysty dla ZAPYTANIA (czego szukasz), ale NIE dla
ODPOWIEDZI (co dokładnie zostało znalezione, z metadanymi) — każde API,
które porównuje odpowiedź z zewnętrznym zbiorem danych po `_index`+`_id`
(nie tylko `_rank_eval` — to samo dotyczyłoby np. `mget` po wynikach
zapisanych wcześniej), musi liczyć się z fizyczną nazwą, nie aliasem.

---

<a id="021"></a>
## 021 — Restart klastra 3-node wisi w nieskończoność (`master_not_discovered`)

**Objaw**
`make up-cluster` uruchomiony na klastrze, który WCZEŚNIEJ już działał
(kontenery zatrzymane, wolumeny `es0{1,2,3}-data` zachowane) wieszał się
bez końca. `docker compose ps` pokazywał `es01` jako `Up (unhealthy)`,
a `es02`/`es03` w stanie `Created` — nigdy nie wystartowane. `make es-health`:
```
{"error":{"root_cause":[{"type":"master_not_discovered_exception","reason":null}]},"status":503}
```
Logi `es01`: `master not discovered or elected yet, an election requires
at least 2 nodes with ids from [...], have only discovered non-quorum
[{es01}...]`.

**Diagnoza**
Przy PIERWSZYM (świeżym) `make up-cluster` — na pustych wolumenach — ten
sam stack startuje bez problemu. Problem pojawia się WYŁĄCZNIE przy
restarcie klastra, który ma już zapisane dane. To odróżnienie ("pierwszy
raz" vs "restart") było kluczowe do znalezienia przyczyny — nie jest to
usterka Dockera ani sieci, tylko stan zapisany na dysku node'a.

**Przyczyna**
Dwa mechanizmy nakładają się na siebie w `compose.yaml`:
1. `es01` ma `cluster.initial_master_nodes: es01` — świadoma decyzja
   (komentarz w kodzie: "ta sama konfiguracja działa w trybie 1-node
   i 3-node"), dzięki której `es01` może sam sformować klaster przy
   PIERWSZYM starcie, bez czekania na `es02`/`es03`.
2. `es02`/`es03` mają `depends_on: es01: condition: service_healthy` —
   celowe: "poczekaj, aż pierwszy node żyje, zanim dołączysz kolejne".

To działa idealnie za pierwszym razem: `es01` bootstrapuje się sam (szybko
staje się `healthy`), potem wstają `es02`/`es03`. ALE `cluster.
initial_master_nodes` działa TYLKO przy formowaniu zupełnie NOWEGO klastra
— gdy `es01-data` ma już zapisaną konfigurację głosującą (voting
configuration) z poprzedniego życia klastra jako 3-node, `es01` przy
starcie próbuje dołączyć do "ostatnio znanego" stanu klastra, co wymaga
KWORUM (2 z 3 node'ów), nie samego siebie. `es02`/`es03` czekają na
`es01: healthy`, `es01` nigdy nie będzie `healthy` bez `es02`/`es03` —
zakleszczenie strukturalne, nie tymczasowy problem wydajnościowy. Więcej
czasu oczekiwania NIC by nie zmieniło.

**Naprawa**
`depends_on.es01.condition` dla `es02` i `es03` zmienione z
`service_healthy` na `service_started` (`compose.yaml`). Discovery klastra
(`discovery.seed_hosts: es01,es02,es03`) samo w sobie odpytuje w pętli,
dopóki node'y się nie znajdą — nie potrzebuje pomocy od `depends_on`, żeby
wiedzieć, KIEDY zacząć próbować. Zweryfikowane: pełny `docker compose stop
es01 es02 es03` + `up -d es01 es02 es03` z ZACHOWANYMI danymi — wszystkie
trzy kontenery startują RÓWNOCZEŚNIE (bez oczekiwania), klaster osiąga
`green` w ~30 s.

**Czego się nauczyłem**
`depends_on: condition: service_healthy` wygląda na zawsze bezpieczniejszy
wybór niż `service_started` ("poczekaj, aż będzie NAPRAWDĘ gotowy, nie
tylko uruchomiony") — ale healthcheck, który sam zależy od stanu innych
serwisów w tej samej grupie startowej (klaster potrzebuje kworum, żeby być
"zdrowy"), zamienia "poczekaj, aż będzie gotowy" w "czekaj na coś, co nigdy
nie nadejdzie bez ciebie". To jest ten sam kształt problemu co zator
w wielowątkowości (dwa wątki czekające na siebie nawzajem) — tylko na
poziomie orkiestracji kontenerów. Kiedy usługa A i usługa B mogą się
wzajemnie potrzebować do wystartowania (klaster, nie prosty łańcuch
zależności), `depends_on` powinien pilnować TYLKO kolejności URUCHOMIENIA
procesu (`service_started`), a nie stanu, który sam zależy od pozostałych
uczestników tej samej grupy.

---

<a id="022"></a>
## 022 — `port is already allocated` — stack wstaje w połowie

**Objaw**
`make up-cluster` po kilku tygodniach przerwy:
```
Error response from daemon: failed to set up container networking: driver failed
programming external connectivity on endpoint marketplace-rabbitmq-1 (...):
Bind for 0.0.0.0:5672 failed: port is already allocated
```
Część kontenerów wystartowała, reszta została w stanie `Created`.

**Diagnoza**
```bash
docker ps --format '{{.Names}}\t{{.Ports}}' | grep -E '5672|9200|5432'
# igrit-rabbitmq-1        0.0.0.0:5672->5672/tcp ...
# igrit-elasticsearch-1   0.0.0.0:9200->9200/tcp
```
Inny projekt na tej samej maszynie (`igrit`, `starter-local`) trzymał 7 z naszych
portów: 5432, 6379, 9200, 5672, 15672, 8080, 5173.

**Przyczyna**
Porty publikowane na hoście są wspólne dla WSZYSTKICH stacków dockerowych na
maszynie. `docker compose up` nie sprawdza ich z góry — startuje kontenery po
kolei i wywraca się na pierwszym zajętym, zostawiając stack w połowie.

**Naprawa**
1. `tools/doctor.sh` sprawdza teraz porty PRZED startem i mówi, kto je trzyma
   (`make doctor`, `make up-cluster`, `make up-apps` — ten ostatni wcześniej
   w ogóle nie odpalał doctora).
2. W lokalnym `.env` porty przesunięte o +10000/+20000 (ES 19200, Postgres
   15432, Redis 16379, RabbitMQ 25672/35672, HTTP 18080, Vite 15173).
   `.env.example` zostaje ze standardowymi — to ustawienie TEJ maszyny.
3. Vite: `public/hot` zawierał `http://0.0.0.0:5173` na sztywno — po zmianie
   portu przeglądarka ładowałaby skrypty z CUDZEGO Vite na 5173. Teraz
   `vite.config.ts` czyta `VITE_PUBLIC_PORT` (z compose) i zapisuje adres
   hosta: `http://localhost:15173`.

**Czego się nauczyłem**
Port na hoście to tylko "drzwi z zewnątrz" — kontenery rozmawiają po sieci
dockerowej (`es01:9200`), więc zmiana `ES_PORT` nic w aplikacjach nie psuje.
Ale wszystko, co adres hosta ZAPISUJE gdzieś na trwałe (tu: plik `hot` Vite),
trzeba sprawdzić osobno — to tam port "przecieka" do przeglądarki.

---

<a id="023"></a>
## 023 — Przewinięcie wyników po minucie: `search_context_missing_exception` (500)

**Objaw**
Znalezione przy przeglądzie kodu, potwierdzone eksperymentem: kolejna strona
wyników ("załaduj więcej" / infinite scroll) po ponad minucie od poprzedniej
kończyła się błędem 500:
```
404 Not Found: {"error":{"root_cause":[{"type":"search_context_missing_exception",
"reason":"No search context found for id [...]"}]
```

**Diagnoza**
```php
$pit = $client->openPointInTime(['index' => 'products-search', 'keep_alive' => '1s'])->asArray()['id'];
$client->closePointInTime(['body' => ['id' => $pit]]);
$client->search(['body' => ['pit' => ['id' => $pit, ...], 'search_after' => [5], ...]]);
// ClientResponseException, code=404, search_context_missing_exception
```

**Przyczyna**
Point In Time (RUNBOOK #019) żyje `keep_alive` (u nas 1 minuta) od OSTATNIEGO
zapytania. Człowiek czytający wyniki dłużej niż minutę "zabija" swój PIT,
a kursor kolejnej strony nadal go wskazuje.

**Naprawa**
`ProductSearchService::searchWithPit()`: przy 404 `search_context_missing` na
stronie KOLEJNEJ (z kursorem) otwiera nowy PIT i kontynuuje z tymi samymi
wartościami `search_after`. Świadomy kompromis: `_shard_doc` nowego PIT-a
odpowiada staremu tylko, jeśli na shardzie nie było zapisów/merge'ów —
inaczej na granicy strony możliwy duplikat (zdejmuje go `matchOn('data.id')`
po stronie Inertii) albo pominięcie jednej pozycji. Test:
`SearchIntegrationTest` — "przewinięcie po wygaśnięciu PIT...".

Dlaczego nie po prostu `keep_alive: 30m`: otwarty PIT trzyma segmenty, które
merge chciałby już usunąć. Tysiąc porzuconych kart przeglądarki = tysiąc
trzymanych zestawów segmentów. Krótki PIT + tanie odtworzenie jest zdrowsze.

**Czego się nauczyłem**
Każdy zasób serwera z czasem życia (PIT, scroll, sesja, lock) trzeba
przemyśleć od strony "co jeśli użytkownik poszedł zrobić kawę". Testy
automatyczne tego nie złapią, bo wykonują się w milisekundach — trzeba
zasymulować wygaśnięcie jawnie (tu: ręczne `closePointInTime`).

---

<a id="024"></a>
## 024 — ESLint/Vite na hoście: `Cannot find native binding`

**Objaw**
`npx eslint resources/js` na hoście (macOS): błąd w KAŻDYM pliku, w linii 1:
```
Resolve error: Cannot find native binding. npm has a bug related to optional
dependencies (https://github.com/npm/cli/issues/4828). Please try `npm i` again
```

**Diagnoza**
Błąd dotyczył wszystkich plików naraz, także nietkniętych od tygodni — więc nie
kod, tylko środowisko. Kontener `catalog-vite` ma w CMD `npm install && npm run
dev` i montuje `./apps/catalog:/app` — razem z `node_modules`.

**Przyczyna**
Pakiety z natywnymi binarkami (resolver ESLinta, rolldown w Vite) instalują
TYLKO wariant dla platformy, na której biegnie `npm install`. Kontener (Linux)
nadpisywał `node_modules` hosta binarkami pod Linuksa → macOS-owy ESLint nie
mógł ich załadować. W drugą stronę tak samo: `npm i` na hoście psuło kontener
przy jego następnym starcie. Ping-pong bez końca. Przy okazji ten sam
mechanizm zmieniał `name` w `package-lock.json` (`app` vs `catalog` — npm bierze
nazwę z katalogu, gdy `package.json` jej nie ma).

**Naprawa**
- `compose.yaml`: nazwany wolumen `catalog-node-modules:/app/node_modules` dla
  `catalog-vite` — kontener ma WŁASNE `node_modules`, host swoje.
- `package.json`: jawne `"name": "catalog"` — koniec przepychanki w lockfile.

**Czego się nauczyłem**
Bind-mount katalogu z kodem to także bind-mount wszystkiego, co w nim leży —
w tym artefaktów zależnych od platformy (`node_modules`, `vendor` z
rozszerzeniami, skompilowane binarki). Wspólny katalog źródeł: tak; wspólne
zależności natywne: nigdy.

---

<a id="025"></a>
## 025 — Node'y ES znikają bez logu zamknięcia, restartują się w kółko

**Objaw**
Klaster raz `green`, raz `unreachable`; `docker compose ps` pokazuje node'y
z różnym czasem "Up" (es01 13 min, es02 8 min, es03 5 min). `docker exec` do
innych kontenerów wisi po kilkadziesiąt sekund, 5 testów Pest trwa 300 s
zamiast 3 s. W logach node'a — zwykłe ostrzeżenia discovery, a zaraz po nich
start nowej JVM. ŻADNEGO "stopping", "shutdown", wyjątku.

**Diagnoza**
```bash
docker inspect marketplace-es02 --format 'restarts={{.RestartCount}} oom={{.State.OOMKilled}}'
# restarts=2 oom=false          <- limit KONTENERA nie został przekroczony
docker info --format '{{.MemTotal}}'          # 15.6 GB (w ETAPIE 6 było 32 GB)
docker stats --no-stream ...                  # kontenery razem: 12.2 GB
```

**Przyczyna**
Docker VM dostał mniej pamięci niż w ETAPIE 6, a równolegle działały inne
projekty (sam `clamav` ~1.9 GB). 3 node'y x limit 4 GB + `-XX:+AlwaysPreTouch`
(JVM rezerwuje cały heap przy starcie) przekraczały WOLNĄ pamięć VM. Linuxowy
OOM killer w VM zabija proces Javy "z zewnątrz" — dlatego `OOMKilled=false`
(to flaga limitu cgroup kontenera, a nie VM) i brak jakiegokolwiek logu
zamknięcia: proces po prostu przestaje istnieć, a `restart: unless-stopped`
podnosi go od nowa.

**Naprawa**
- `.env` (lokalnie): `ES_HEAP=1g`, `ES_MEM_LIMIT=2g` — przy 1500 dokumentach
  z zapasem; PRZED ETAPEM 8 (5 mln dokumentów) wrócić do tabeli w `.env`.
- `tools/doctor.sh` liczy teraz pamięć zajętą przez INNE projekty i ostrzega,
  gdy po jej odjęciu nie starczy na ten stack. Przy dzisiejszych liczbach
  (2g heap: potrzeba ~13.1 GB, wolne ~9.7 GB) ostrzeżenie padłoby przed startem.

**Czego się nauczyłem**
`OOMKilled=false` NIE znaczy "to nie OOM". Są dwa różne OOM-y: limitu
kontenera (flaga = true, exit 137) i całej maszyny/VM (flaga = false, proces
znika). Proces, który ginie bez słowa w logach, prawie zawsze został zabity
z zewnątrz — szukaj po stronie zasobów, nie konfiguracji.

<a id="026"></a>
## 026 — 18 testów Fortify pada tylko w kontenerze (419) — i testy WCIĄŻ czyściły Postgresa

**Objaw**
`docker compose exec -T catalog-app php artisan test --compact`: 62 zielone,
18 czerwonych (`AuthenticationTest`, `PasswordResetTest`, `RegistrationTest`,
`TwoFactorChallengeTest`, `VerificationNotificationTest`, `ProfileUpdateTest`,
`SecurityTest`). Na hoście wszystko zielone. Asercje: `Session is missing
expected key [errors]`, `assertRedirect`, `assertAuthenticated` — a pod nimi
wspólny mianownik: **każdy POST dostaje 419** (CSRF token mismatch).

**Diagnoza**
419 w testach to podejrzane, bo `ValidateCsrfToken` sam się wyłącza, gdy
`app()->runningUnitTests()` — czyli gdy `APP_ENV=testing`. Hipoteza "zmienne
sesji/cache z compose" odpadła od razu: `docker compose exec catalog-app env`
nie ma ani `SESSION_*`, ani `CACHE_*`. Tymczasowy test-zrzut (nie tinker —
tinker nie ładuje `phpunit.xml`, więc nie widzi środowiska testów):
```
"app.env": "local",            <- a powinno być testing
"runningUnitTests": false,
"getenv APP_ENV": "testing",   <- phpunit.xml zadziałał...
"_ENV":           "testing",
"_SERVER":        "local",     <- ...ale nie tutaj
"db": "pgsql", "db_name": "catalog"   <- !!!
```
A potem: `select count(*) from products` w deweloperskim Postgresie -> **0**.
Wszystkie tabele puste, tylko `migrations` = 13 — klasyczny ślad
`RefreshDatabase` (`migrate:fresh`).

**Przyczyna**
Dwa mechanizmy, które razem dają cichy wyciek:
1. PHPUnit `<env name=... force="true">` (`PhpHandler::handleEnvironmentVariables`)
   robi `putenv()` i `$_ENV[...] = ...` — **nigdy nie dotyka `$_SERVER`**.
2. PHP CLI kopiuje zmienne środowiskowe procesu (z `environment:` w
   `compose.yaml`) do `$_SERVER`, a repozytorium Dotenv Laravela
   (`RepositoryBuilder::createWithDefaultAdapters()`) pyta **najpierw**
   `ServerConstAdapter`, dopiero potem `EnvConstAdapter`/`putenv`.

Więc w kontenerze `APP_ENV=local` i `DB_CONNECTION=pgsql` z compose wygrywały
z `phpunit.xml` mimo `force="true"`. Skutki: (a) środowisko `local` -> CSRF
aktywny -> 419 na każdym POST-cie -> 18 czerwonych testów; (b) testy jechały
na prawdziwej bazie `catalog`, a `RefreshDatabase` (podpięty w `Pest.php` do
całego `Feature/`) czyścił ją przy każdym uruchomieniu. Wpis [018](#018)
naprawił tylko połowę (`$_ENV`), a weryfikacja przez tinker dała fałszywe
poczucie bezpieczeństwa. Na hoście problemu nie ma, bo tam nic nie ustawia
tych zmiennych w środowisku procesu.

**Naprawa**
1. `apps/catalog/phpunit.xml` — zmienne krytyczne dla izolacji również jako
   `<server>` (PHPUnit nadpisuje `$_SERVER` bezwarunkowo, `force` niepotrzebne):
   ```xml
   <server name="APP_ENV" value="testing"/>
   <server name="DB_CONNECTION" value="sqlite"/>
   <server name="DB_DATABASE" value=":memory:"/>
   <server name="DB_HOST" value=""/>
   <server name="DB_URL" value=""/>
   ```
2. `apps/catalog/tests/TestCase.php` — bezpiecznik w `setUpTraits()` (czyli
   PRZED `RefreshDatabase`): jeśli env != `testing` albo baza != sqlite
   `:memory:`, test rzuca wyjątek zamiast migrować. Sprawdzone na starym
   `phpunit.xml`: testy odmawiają startu z komunikatem
   `env=local, db=pgsql/catalog`, Postgres nietknięty.

Wynik: kontener **80/80** (444 asercje), host 65 zielonych + 15 pominiętych
(funkcje Fortify wyłączone na hoście — bez zmian). Danych deweloperskich
nie da się odzyskać z tej bazy — trzeba je zasiać ponownie.

**Czego się nauczyłem**
- "Zmienna środowiskowa" w PHP to trzy różne miejsca: `getenv()`, `$_ENV`,
  `$_SERVER`. Narzędzie może ustawić jedno, a framework czytać drugie.
  Laravel czyta `$_SERVER` pierwszy — i tam trzeba wymusić wartość.
- **Weryfikuj w tym samym kontekście, w którym działa kod.** Tinker pokazywał
  "dobrą" konfigurację, bo w ogóle nie czyta `phpunit.xml`. Jedyny rzetelny
  dowód izolacji testów to asercja/zrzut wykonany *wewnątrz testu*.
- Jeśli błąd izolacji oznacza utratę danych, nie wystarczy konfiguracja —
  potrzebny jest bezpiecznik w kodzie, który głośno failuje, zanim zrobi
  szkodę (tu: `TestCase::setUpTraits()`).
- 18 "dziwnych" testów z [018](#018) było tym samym błędem co utrata danych,
  tylko widzianym z innej strony. Pozostawione "niezdiagnozowane do końca"
  czerwone testy mogą być jedynym widocznym objawem dużo groźniejszego problemu.

---

<a id="027"></a>
## 027 — Retry Messengera: `no exchange 'delays'`, a pod spodem jeszcze trzy błędy

**Objaw**
`make seed n=1500` (2026-10-06): pobranie projekcji produktu 457 kończy się
`Idle timeout reached for "http://catalog-app/api/internal/products/457/projection"`,
a próba retry wywala konsumenta:
```
WARNING [messenger] Error thrown while handling message App\Message\IntegrationEvent {}.
        Sending for retry #1 using 1067 ms delay. Error: "... Idle timeout reached ..."
AMQPQueueException: Server channel error: 404, message: NOT_FOUND - no exchange 'delays' in vhost '/'
  ... SendFailedMessageForRetryListener->onMessageFailed() ...
WARNING [messenger] ... Sending for retry #1 using 1088 ms delay.
        Error: "Redelivered message from AMQP detected that will be rejected and trigger the retry logic."
```
Po JEDNEJ próbie wiadomość leży w `search.product.sync.dlq` (`x-death`:
`reason: rejected`), zamiast przejść 3 ponowienia z backoffem.

**Diagnoza**
1. `rabbitmqctl list_exchanges` — exchange'a `delays` nie ma nigdzie.
   W `vendor/symfony/amqp-messenger/Transport/Connection.php`:
   `publishWithDelay()` publikuje na `delay.exchange_name` (domyślnie
   `delays`), a `setupDelay()` deklaruje ten exchange TYLKO gdy
   `auto_setup: true`. My mamy `false` (topologia jako kod, wpis do
   `messenger.yaml`) — i w `definitions.json` exchange'a nie było.
2. Test integracyjny na żywym brokerze
   (`apps/search/tests/Integration/Messenger/ProductSyncRetryTest.php`,
   jednorazowa kolejka quorum z DLX, prawdziwy `ProductSyncHandler`, klient
   katalogu celujący w zamknięty port) najpierw odtworzył dokładnie ten 404.
   Po dodaniu exchange'a test pokazał **kolejny** błąd: **18 wywołań handlera
   w 20 s**, co ~1 s — retry bez końca.
3. `vendor/symfony/messenger/EventListener/SendFailedMessageForRetryListener.php`,
   `shouldRetry()`: `RecoverableExceptionInterface` z `forceRetry() === true`
   -> `return true` bez pytania strategii o `max_retries`. W Symfony 8.1
   `RecoverableMessageHandlingException` ma `forceRetry = true` DOMYŚLNIE.
4. Po `forceRetry: false` nadal 20 wywołań — bo `retryCount` w logu to
   zawsze `#1`. Licznik ponowień to `RedeliveryStamp`, a nasz
   `ExternalJsonEnvelopeSerializer` nie zapisywał stampów: każda wiadomość
   z kolejki opóźnień wracała jako świeża.
5. Po naprawie licznika: 4 wywołania, backoff OK, ale w DLQ testu **3 kopie**.
   `Worker::handleMessage()` po każdej porażce woła `$receiver->reject()`,
   `AmqpReceiver::reject()` = `nack` bez requeue, a kolejka ma
   `x-dead-letter-exchange` -> każda nieudana próba to kopia w `*.dlq`.
6. Osobno, w Postgresie: `select * from processed_events where
   event_id='01M4942WSEH91185DWY7MJCV95'` -> znacznik z **17:28:43**, a
   handler padł o **17:28:46**. `tryMarkProcessed()` commituje się przed
   pobraniem projekcji — udany retry trafiłby na "Duplikat, pomijam",
   dostałby ack, a produkt nie zostałby przeindeksowany.

**Przyczyna**
Cztery błędy w jednym łańcuchu, każdy zasłonięty poprzednim:
1. **Topologia:** brak exchange'a opóźnień przy `auto_setup: false`.
   Mechanizm retry w AMQP to: publikacja na exchange opóźnień -> dynamiczna
   kolejka `delay_<exchange>_<kolejka>_<ms>_retry` z `x-message-ttl` ->
   po TTL jej DLX (`''`, default exchange) oddaje wiadomość do kolejki
   źródłowej. Samą kolejkę opóźnień Messenger deklaruje zawsze (nazwa jest
   dynamiczna) — exchange musi już istnieć.
2. **`forceRetry` domyślnie `true`** — retry bez limitu, `max_retries`
   ignorowane.
3. **Serializer gubił `RedeliveryStamp`** — strategia nigdy nie widziała
   przekroczonego limitu. Domyślny serializer Messengera robi to sam
   (nagłówki `X-Message-Stamp-*`), więc problem dotyczy tylko własnych
   serializerów.
4. **Semantyka DLQ vs. Messenger:** worker nackuje każdą nieudaną próbę,
   także tę, którą już przejął retry albo `failure_transport`.

Plus dedup przed efektem ubocznym (punkt 6 diagnozy) — bez transakcji
pierwszy retry po naprawie 1–3 byłby cichą utratą zdarzenia.

Dlaczego dane w ES i tak były spójne: produkt 457 zaindeksowało inne
zdarzenie tego samego agregatu (`_version: 1`, `count` 1500/1500).

**Naprawa**
1. `infra/rabbitmq/definitions.template.json`: exchange `marketplace.delays`
   (`direct`, durable). `messenger.yaml`, transporty AMQP:
   `options.delay.exchange_name: marketplace.delays` + `arguments:
   {x-queue-type: classic}` (kolejka opóźnień żyje sekundy, a
   `rabbitmq.conf` ma `default_queue_type = quorum` — Raft dla niej to narzut).
   Na działającym brokerze bez restartu, bo import jest addytywny:
   ```bash
   make up   # renderuje definitions.json z szablonu
   docker compose exec rabbitmq rabbitmqctl import_definitions /etc/rabbitmq/definitions.json
   docker compose exec rabbitmq rabbitmqctl list_exchanges name type | grep delays
   ```
2. `ProductSyncHandler`: `new RecoverableMessageHandlingException(...,
   forceRetry: false)`.
3. `ExternalJsonEnvelopeSerializer`: `encode()` zapisuje licznik w nagłówku
   `X-Message-Retry-Count`, a `decode()` odtwarza z niego `RedeliveryStamp`.
4. `App\Messenger\AckAfterHandOffTransport` (dekorator transportów AMQP,
   `services.yaml`): `reject()` po przejęciu wiadomości -> `ack`. Prawdziwy
   `nack` (-> DLQ) zostaje tylko dla wiadomości redelivered, czyli po crashu
   konsumenta, zanim cokolwiek ją przejęło. Błędy dekodowania `AmqpReceiver`
   nackuje sam, poza dekoratorem, więc też trafiają do DLQ.
   **Od teraz: `*.dlq` = trucizny, `failure_transport` (tabela
   `messenger_messages`, `queue_name='failed'`) = wyczerpane próby.**
5. `messenger.yaml`: middleware `doctrine_transaction` na busie — znacznik
   dedup i obsługa w jednej transakcji, wyjątek albo zerwane połączenie
   robią rollback.
6. `make mq-get`: `--ack-mode ack_requeue_true` — patrz niżej.

Dowody:
- `ProductSyncRetryTest` (2 testy, 18 asercji): 1 próba + 3 ponowienia,
  odstępy ≈1 s/2 s/4 s (±10% jitter), 1 wpis w `failure_transport`, pusta
  kolejka i DLQ. Scenariusz crash -> redelivery: dokładnie 1 kopia w DLQ,
  a wiadomość i tak przechodzi retry.
  ```bash
  docker compose exec search-consumer php vendor/bin/phpunit --group integration
  ```
- Ręczny repro na prawdziwym stacku (bus z `doctrine_transaction`, Postgres,
  kolejka `search.product.sync`):
  ```bash
  docker compose stop search-consumer          # żeby nie zabrał wiadomości
  docker compose exec rabbitmq rabbitmqadmin --username $RABBITMQ_USER --password $RABBITMQ_PASSWORD \
    publish message --exchange marketplace.events --routing-key product.updated \
    --payload '{"id":"01REPRO027","type":"product.updated","version":1,"source":"catalog","occurred_at":"2026-10-06T18:00:00+00:00","aggregate":{"type":"product","id":"457"},"sequence":1,"data":{}}'
  docker compose run --rm -e CATALOG_INTERNAL_BASE_URL=http://127.0.0.1:9 search-consumer \
    php bin/console messenger:consume product_sync --limit=4 -vv
  ```
  Wynik (2026-10-06):
  ```
  17:53:54 WARNING ... Sending for retry #1 using 999 ms delay.
  17:53:55 WARNING ... Sending for retry #2 using 1993 ms delay.
  17:53:57 WARNING ... Sending for retry #3 using 4014 ms delay.
  17:54:02 CRITICAL ... Removing from transport after 3 retries.
  17:54:02 INFO ... Rejected message ... will be sent to the failure transport DoctrineTransport
  ```
  `processed_events` dla tego `event_id`: **0** (rollback),
  `messenger_messages` (`failed`): **1**, `search.product.sync.dlq`: **0**.
  Sprzątanie: `messenger:failed:remove <id> --force`,
  `docker compose start search-consumer`.

**Wpadka przy diagnozie: `rabbitmqadmin get` KASUJE wiadomości**
Pozostałą w DLQ wiadomość (event `01M4942WSEH91185DWY7MJCV95`,
`product.created` 457) podejrzałem przez `rabbitmqadmin get messages` — i
tym samym ją usunąłem. W rabbitmqadmin v2 `--ack-mode` ma domyślnie
`ack_requeue_false`. `make mq-get` ("podejrzyj bez usuwania") robił to
samo. Skutek żaden (duplikat już zaindeksowanego dokumentu — i tak do
purge'a), ale na prawdziwej DLQ to utrata dowodów. Payload dla porządku:
```json
{"id":"01M4942WSEH91185DWY7MJCV95","type":"product.created","version":1,"source":"catalog","occurred_at":"2026-10-06T17:27:21+00:00","aggregate":{"type":"product","id":"457"},"sequence":1,"data":{"id":457,"ean":"0451540293504","name":"Romaguera Inc Kurtka softshell Ultra","version":1,"brand_id":15,"attributes":{"color":"purple","model":"Ultra"},"created_at":"2026-10-06T17:27:21.000000Z","updated_at":"2026-10-06T17:27:21.000000Z","category_id":7,"description":"Modi quidem architecto et exercitationem praesentium. Unde quasi id veritatis iure. Vel nihil dolor sit distinctio. Ipsa voluptatem nulla et tempore eveniet ipsa aliquam."}}
```

**Czego się nauczyłem**
- **`auto_setup: false` to umowa: wszystko, czego Messenger potrzebuje
  w brokerze, musi być w `definitions.json`** — także to, czego nie widać
  w `messenger.yaml` (exchange opóźnień). Nazwa ustawiona jawnie po obu
  stronach to jedno źródło prawdy zamiast ukrytego defaultu.
- Ścieżki awaryjne testuj **na prawdziwym brokerze**, nie na
  `InMemoryTransport`. Żaden z tych czterech błędów nie istnieje w pamięci:
  nie ma tam exchange'y, serializacji ani nacka.
- Gdy naprawa ujawnia następny błąd, nie kończ na pierwszym zielonym
  objawie. Test, który sprawdza **zachowanie końcowe** ("4 próby, potem
  failure"), a nie "brak wyjątku", złapał wszystkie warstwy po kolei.
- Retry ma sens tylko wtedy, gdy porażka niczego nie zostawia:
  znacznik idempotencji musi żyć w tej samej transakcji co efekt, inaczej
  ponowienie jest no-opem.
- DLQ na kolejce i `failure_transport` Messengera to dwa mechanizmy
  z różną semantyką. Ustal, co który znaczy, zanim alert na DLQ zacznie
  dzwonić przy każdym timeoucie.
- Przed "podglądem" kolejki narzędziem CLI sprawdź `--help` pod kątem
  trybu ack. "Get" w AMQP to konsumpcja.

---

<a id="028"></a>
## 028 — Obraz prod czyta `DB_CONNECTION=sqlite`, choć compose podaje `pgsql`

**Objaw**
Brak błędu w czasie buildu i startu. Test `tools/vps/prod-image-check.sh`:
`catalog: DB_CONNECTION z env po config:cache (oczekiwano: pgsql, otrzymano: sqlite)`.
Na serwerze aplikacja pisałaby do pliku SQLite w kontenerze, który znika przy każdym wdrożeniu.

**Diagnoza**
`php -r 'echo (require "bootstrap/cache/config.php")["database"]["default"];'`
w kontenerze zwraca `sqlite`, mimo `-e DB_CONNECTION=pgsql`.

**Przyczyna**
`php artisan config:cache` było w `RUN` Dockerfile'a. W buildzie nie ma zmiennych
z compose, więc `env()` zwracało wartości domyślne, a te zamrażały się w cache.
Gdy `bootstrap/cache/config.php` istnieje, Laravel w ogóle nie woła `env()`.

**Naprawa**
`config:cache` przeniesione do `infra/php/catalog-entrypoint.sh` (start kontenera).
`route:cache` i `view:cache` zostały w buildzie, bo nie zależą od środowiska.

**Czego się nauczyłem**
Cache budowany z env nie może powstać w buildzie obrazu. Pytanie kontrolne przy
każdym `RUN`: czy wynik zależy od czegoś, co poznam dopiero w runtime?

<a id="029"></a>
## 029 — `npm run build` pada w czystym klonie (`exit code: 1`), lokalnie działa

**Objaw**
```
infra/php/catalog.Dockerfile: RUN npm run build
ERROR: failed to solve: process "/bin/sh -c npm run build" did not complete successfully: exit code: 1
```

**Diagnoza**
Build z `git clone` do katalogu tymczasowego (`prod-image-check.sh`), a nie
z katalogu roboczego. `vite.config.ts` → plugin `wayfinder`.

**Przyczyna**
`@laravel/vite-plugin-wayfinder` woła `php artisan wayfinder:generate` także przy
buildzie produkcyjnym. Etap `assets` był na `node:alpine`, czyli bez PHP, vendor
i kodu. Lokalnie problem był niewidoczny, bo Vite zawsze chodził w kontenerze z PHP.
Dodatkowo `composer install --no-scripts` pomija `package:discover`, przez co
Laravel nie widzi komendy z paczki.

**Naprawa**
`assets` dziedziczy z etapu `vite` (base + Node) i dostaje `COPY --from=vendor /app`.
W `vendor` jest jawne `php artisan package:discover`.

**Czego się nauczyłem**
Build produkcyjny testuj zawsze z czystego klonu. Lokalny katalog roboczy ma pliki,
których CI nigdy nie zobaczy.

<a id="030"></a>
## 030 — Symfony `cache:warmup` w buildzie: `Environment variable not found`

**Objaw**
```
Environment variable not found: "DATABASE_URL".
Environment variable not found: "DEFAULT_URI".
```
Wcześniej to samo maskował lokalny `apps/search/.env`, który kopiował się do
obrazu (brak `.dockerignore`).

**Diagnoza**
Build z czystego klonu i `grep -rn "env(" apps/search/config`, żeby zobaczyć listę
wszystkich zmiennych.

**Przyczyna**
Kompilacja kontenera DI sprawdza, czy zmienne z `%env()%` istnieją (wartości
rozwiązuje w runtime). Do tego `Dotenv::bootEnv()` rzuca wyjątek bez pliku `.env`.

**Naprawa**
`RUN --mount=type=bind,source=infra/php/search.build.env,...` z atrapami tylko na
czas `cache:warmup`, plus `touch .env`. Nic z tego nie zostaje w obrazie.

**Czego się nauczyłem**
`ENV` w Dockerfile zostaje w obrazie na zawsze. Wartość potrzebna tylko na czas
buildu idzie przez `--mount` albo prefiks w tej samej linii `RUN`.

<a id="031"></a>
## 031 — Zmiana ceny nigdy nie dociera do ES (outbox bez przekaźnika)

**Objaw**
Wykryte w próbie generalnej ETAPU D. Po
`Offer::updateWithOutbox(['price_cents' => ...])` wiersz w `outbox` ma
`published_at = NULL` na zawsze, a w ES jest stara cena.

**Diagnoza**
`grep -rn "outbox:publish" compose.yaml Makefile apps/catalog`: komendę woła tylko
`SeedMarketplaceCommand` (na końcu seedu). Żadna usługa compose jej nie uruchamia.

**Przyczyna**
Dane w ETAPACH 6–7 szły zawsze przez `marketplace:seed`, który sam opróżnia
outbox. Normalna ścieżka „zmiana → zdarzenie" nie miała przekaźnika.

**Naprawa**
Usługa `outbox-publisher` (profil `apps`): obraz catalog,
`php artisan outbox:publish --loop`. `FOR UPDATE SKIP LOCKED` pozwala na
równoległą pracę z seedem. Zmierzone: zmiana ceny widoczna w ES po ~1 s.

**Czego się nauczyłem**
Test end-to-end ma iść ścieżką użytkownika (zmiana w aplikacji), a nie ścieżką
narzędzia (seed). Narzędzie potrafi po cichu wykonać pracę, której system nie robi.

<a id="032"></a>
## 032 — `nDCG@10` skacze 0.84–0.89 na tych samych danych

**Objaw**
`search:eval` po świeżym seedzie i indeksie: 0.851, 0.856, 0.842, 0.889.
Baseline w POMIARACH: 0.967.

**Diagnoza**
`_search?q=name:telefon`: wszystkie smartfony mają identyczny `_score` (2.583).
`queries.yaml` ocenia 10 z kilkudziesięciu remisujących dokumentów, a kolumna
„Nieocenione w top-k” jest > 0.

**Przyczyna**
Remis `_score` rozstrzyga wewnętrzny doc id Lucene, zależny od momentów refreshu
i merge'ów segmentów. 0.967 zmierzono na lokalnej bazie z 6019 produktami (wielokrotny
seed bez `--fresh`), czyli na stanie, którego nie da się odtworzyć.

**Naprawa**
ETAP D używa kryterium „eval OK" (≥ 0.80 + zapytania o konkretny produkt = 1.000),
a po restarcie i snapshocie sprawdza wynik identyczny jak przed. Deterministyczny
harness (ocena całych grup remisów) to osobne zadanie.

**Czego się nauczyłem**
Zanim liczba stanie się kryterium przyjęcia, zmierz ją 3 razy od zera. Metryka
z rozrzutem nie jest testem regresji.

<a id="033"></a>
## 033 — RabbitMQ: kontener ginie, gdy test pyta go o zdrowie w trakcie bootu

**Objaw**
`docker exec <rabbitmq> rabbitmq-diagnostics -q check_running` w pętli zaraz po
`docker run`: po kilkudziesięciu sekundach `Error response from daemon: container ... is not running`.
Ten sam obraz uruchomiony bez pętli działa poprawnie.

**Diagnoza**
`docker logs` + start bez odpytywania: użytkownik i kolejki tworzą się poprawnie,
`rabbitmqctl authenticate_user` → `Success`.

**Przyczyna**
CLI RabbitMQ (`rabbitmq-diagnostics`) uruchamiane w trakcie bootu node'a, przed
ustaleniem ciasteczka Erlanga i startem dystrybucji, kolidowało ze startem. Dokładny
mechanizm nie został ustalony: wystarczyło przestać pytać za wcześnie.

**Naprawa**
Test czeka na linię `Server startup complete` w `docker logs` i dopiero wtedy
używa CLI.

**Czego się nauczyłem**
Sonda zdrowia też jest obciążeniem i może zmienić to, co mierzy. W testach na
gotowość lepiej czekać na sygnał od usługi (log, port) niż pytać ją w pętli.

<a id="034"></a>
## 034 — Bootstrap serwera urywa się w połowie, port 22: `Connection refused`

**Objaw**
`ssh elastic-vps 'bash -s' < tools/vps/bootstrap.sh`: log kończy się na kroku 1/8,
a potem:
```
ssh: connect to host <IP> port 22: Connection refused
```
Po kilku minutach port wraca, ale root dostaje `Permission denied (publickey)`.

**Diagnoza**
Po odzyskaniu dostępu (jako `deploy`):
```
sudo zgrep -h " Ban \| Unban " /var/log/fail2ban.log*
NOTICE [sshd] Ban   <moje IP>     17:44:26
NOTICE [sshd] Unban <moje IP>     17:48:51
```
Stan serwera: `deploy` istnieje, `sshd` utwardzony (krok 3), `ufw` nieaktywny (krok 4 nie wykonany).

**Przyczyna**
Trzy rzeczy naraz:
1. Tuż przed bootstrapem uruchomiłem czerwoną fazę `verify.sh hardening`. Każde
   z ~13 sprawdzeń logowało się jako `deploy`, którego jeszcze nie było, więc
   każde było nieudanym logowaniem.
2. Krok 1 instaluje `fail2ban`. Pakiet startuje usługę od razu z domyślnym jailem
   Ubuntu i czyta dziennik z ostatnich 10 minut: >5 porażek z jednego IP = ban.
   Ban (REJECT) zrywa także nawiązane połączenie.
3. Skrypt na serwerze żył dalej, ale pierwsze `echo` do martwego terminala
   zakończyło go sygnałem SIGPIPE. Doszedł do kroku 3 (root wyłączony), dalej już nie.

**Naprawa**
- `verify.sh hardening`: po nieudanym logowaniu `deploy` przerywa zamiast próbować dalej.
- `bootstrap.sh`: jail `sshd` zapisany przed instalacją `fail2ban`. Skrypt
  uruchamiany w tle na serwerze (`scp` + `nohup … > /var/log/bootstrap.log`),
  więc zerwane połączenie go nie zabija.
- Ponowne uruchomienie (skrypt idempotentny) → `verify.sh hardening` 14/14.

**Czego się nauczyłem**
Test, który „tylko sprawdza", też ma skutki uboczne: dla fail2ban nieudane
logowanie to atak. Długie operacje na zdalnym serwerze uruchamiaj odłączone od
sesji SSH (nohup/tmux/systemd-run) i z logiem do pliku.

Przy okazji dwie drobniejsze pułapki tego samego dnia:
- `ssh host 'cat > plik && nohup … &' < skrypt` dało **pusty plik** (0 B): końcowe `&` wysyła w tło całą listę `a && b`, razem z `cat`, a proces w tle w nieinteraktywnej powłoce dostaje stdin z `/dev/null`. Pewniej jest przez `scp`.
- Pętla `until ssh host '! pgrep -f "bash /tmp/bootstrap.sh"'` nie kończyła się nigdy:
  `pgrep -f` znajdował **sam siebie**, bo szukana fraza była w jego własnej linii
  poleceń. Lepiej sprawdzać plik z logiem albo PID zapisany przy starcie.
- Ubuntu 24.04 ma domyślnie `vm.max_map_count = 1048576`. „Ustaw 262144" by go **obniżyło**.

<a id="035"></a>
## 035 — Zdalny skrypt przez `ssh host bash -s`: kończy się po cichu albo wisi

**Objaw**
Dwa różne objawy tego samego wzorca, `ssh host bash -s <<REMOTE … REMOTE`, w `tools/vps/deploy.sh`:
1. Wdrożenie kończy się bez błędu zaraz po `php artisan migrate`. Nie ma migracji
   Doctrine, nie ma `[deploy] OK`, nie startują es02, es03 ani `outbox-publisher`.
   Ten sam objaw ma `bash -s < tools/smoke-test.sh`: zero wyjścia, zero testów.
2. Po poprawce (1) wdrożenie wisi 10+ minut. Na serwerze nic nie działa, a na Macu:
   ```
   69322  bash tools/vps/deploy.sh …
   69338   └ bash tools/vps/deploy.sh …
   69340      └ bash -s           ← LOKALNIE, nie na serwerze
   ```

**Diagnoza**
(1) Ręczne `docker compose exec -T search-consumer … doctrine:migrations:migrate`
działa, więc winny jest skrypt, a nie migracja. (2) `ps -o pid,ppid` na Macu pokazał
lokalny `bash -s` jako dziecko podpowłoki deploy.sh.

**Przyczyna**
1. Skrypt trafia do zdalnego basha **przez stdin**. `docker compose exec -T` też
   czyta stdin, więc połknął resztę skryptu. Bash nie miał już czego czytać i zakończył
   się kodem 0.
2. Heredoc był **niecytowany** (`<<REMOTE`), więc lokalny bash rozwijał w nim
   `$(…)` i backticki, **także w komentarzach**. Komentarz z tekstem `` `bash -s` ``
   uruchomił `bash -s` na Macu, który czekał na wejście. Wcześniejszy komentarz
   z `` `up --wait` `` też się „wykonywał" (tyle że `up` nie istnieje, więc błąd zginął w logu).

**Naprawa**
- `</dev/null` przy każdym `docker compose exec`/`pull`/`up` w skrypcie zdalnym.
- Heredoc cytowany `<<'REMOTE'`, a zmienne jawnie: `ssh host "TAG='…' DIR='…' bash -s" <<'REMOTE'`.
- Skrypty z `docker compose exec` (smoke) kopiowane na serwer `scp` i uruchamiane z pliku.

**Czego się nauczyłem**
Heredoc do zdalnej powłoki zawsze cytuj (`<<'EOF'`), a dane przekazuj jawnie.
Jeśli skrypt idzie przez stdin, każda komenda w środku czytająca stdin (`docker exec -i/-T`,
`ssh`, `read`, `mysql`) musi dostać `</dev/null`.

<a id="036"></a>
## 036 — Plan: „API k3s tylko na 127.0.0.1" — złapane przed wdrożeniem

**Objaw**
Brak — błąd w planie (`docs/09-PLAN-ETAP-D2-K3S.md`, decyzja K-6), wyłapany
przy pisaniu `install-k3s.sh`. Gdyby wszedł: CoreDNS, operator ECK i każdy pod
rozmawiający z API wisiałyby na `dial tcp 10.43.0.1:443: connect: connection refused`.

**Diagnoza**
Rozpisanie drogi pakietu: pod → Service `kubernetes.default` (ClusterIP
`10.43.0.1:443`) → kube-proxy DNAT na **endpoint** = `advertise-address` węzła
`:6443` → apiserver. Endpoint wskazuje adres węzła, a nie loopback.

**Przyczyna**
`--bind-address 127.0.0.1` sprawiłoby, że apiserver słucha tylko na loopbacku
hosta. Ustawienie też `--advertise-address 127.0.0.1` nie pomaga: po DNAT na
`127.0.0.1` pakiet trafia na loopback **network namespace'u poda**, a nie hosta.

**Naprawa**
API słucha na interfejsie węzła. Z internetu 6443 zamyka ufw (apiserver to
proces hosta, więc łańcuch INPUT go obejmuje, inaczej niż porty publikowane
przez Docker). Dostęp z Maca: tunel SSH `LocalForward 26443 127.0.0.1:6443`.
`verify.sh exposure` skanuje 6443 z zewnątrz. ufw: `allow from 10.42.0.0/16`
i `10.43.0.0/16`, czyli ruch z podów do hosta, nie z internetu.

**Czego się nauczyłem**
„Słuchaj tylko na localhost" to dobra zasada dla usług, z których korzysta sam
host. Dla usług, z którymi łączą się kontenery albo pody, „localhost" oznacza
coś innego po każdej stronie granicy network namespace'u.

<a id="037"></a>
## 037 — Po `kubectl apply` ES + Kibany serwer „znika” na ~25 minut (bez OOM)

**Objaw**
Chwilę po utworzeniu `Elasticsearch` (3 nody) i `Kibany` przez ECK, obok
działającego stacku D1 (Compose, też 3 nody ES):
```
Unable to connect to the server: net/http: TLS handshake timeout
ssh elastic-vps …  →  Timeout, server not responding
```
Ping 50 ms, `nc -z <IP> 22` → otwarty, ale sesja SSH nie startuje.

**Diagnoza**
Po ~25 minutach SSH wrócił:
```
load average: 2.56   Mem: used 16812 / 23943, available 7130
journalctl -k | grep -i oom   → nic
kubectl get elasticsearch     → green 3/3, Kibana green, restartów 0
docker compose ps             → wszystko healthy
```

**Przyczyna**
To nie był brak pamięci (zero OOM), tylko burza startowa: 4 vCPU, a naraz
startowały 3 nowe JVM-y ES (każda kompiluje JIT, ładuje pluginy, robi recovery
shardów) i Kibana (Node.js budujący bundle), obok 3 działających JVM-ów D1.
Do tego zapis na lokalny dysk (nowe PVC). sshd i apiserver k3s nie dostawały CPU
i I/O na czas, więc nie zdążały z handshake'ami.

**Naprawa**
Nic nie trzeba było naprawiać, system się ustabilizował. Na przyszłość, przy
małej maszynie i blue-green:
- startuj ES i Kibanę osobno (najpierw `Elasticsearch`, Kibana po `green`);
- na czas startu zmniejsz `count` albo poczekaj z drugim stackiem;
- w pętlach czekających na klaster ustawiaj **limit czasu i sprawdzaj SSH
  bez tunelu**, bo zawieszone API wygląda tak samo jak zerwany tunel.

**Czego się nauczyłem**
„Mieści się w RAM” nie znaczy „wystartuje bezboleśnie”. Start JVM-a to szczyt
CPU i I/O, a orkiestrator (ECK, compose) chętnie startuje wszystko naraz.
Brak OOM w `journalctl -k` szybko odróżnia „zabrakło pamięci” od „zabrakło CPU/I/O”.

<a id="038"></a>
## 038 — Rolling restart ES przez ECK: klaster przechodzi przez RED, a deploy nie czeka

**Objaw**
`make k3s-deploy` z nowym tagiem (nowy obraz ES). Skrypt melduje „OK", a w tym
samym czasie:
```
  1s: yellow ApplyingChanges  zaktualizowane=1/3
113s: red    ApplyingChanges  zaktualizowane=2/3     ← RED
306s: green  Ready            zaktualizowane=3/3
statefulset.apps/marketplace-es-default  2/3         ← w podsumowaniu deployu
```

**Diagnoza**
1. Pętla obserwująca `.status.health`, `.status.phase` i `updatedReplicas` co 10 s.
2. `grep number_of_replicas infra/elasticsearch/mappings/products-v1.json` → `0`.

**Przyczyna**
1. **RED:** ECK restartuje węzły po jednym i czeka na zdrowie klastra, ale
   `products-v1` ma **0 replik**. Węzeł z jedynym shardem leży, więc ten indeks
   jest niedostępny, a klaster RED. Rolling restart chroni tylko dane z replikami.
   W D1 (restart całego klastra naraz) było tak samo, tylko krócej i mniej widocznie.
2. **Deploy nie czekał:** krok „czekaj na green" dostał green z *poprzedniego*
   stanu, bo ECK jeszcze nie zaczął zmian. Aplikacje wdrażały się w trakcie
   rolling restartu.

**Naprawa**
- `deploy.sh`: czeka, aż `observedGeneration == generation` (operator przetworzył
  nową spec), `phase == Ready` i `health == green`.
- Repliki: decyzja do podjęcia na poziomie projektu (mapowanie jest wspólne
  dla dev i prod). Na 3 nodach `number_of_replicas: 1` daje dostępność w trakcie
  restartu kosztem 2x miejsca i wolniejszego indeksowania. Przewodnik D2 ma to
  jako ćwiczenie.

**Czego się nauczyłem**
„Czekaj na stan X" działa tylko wtedy, gdy wiesz, że system już *zaczął*
dochodzić do nowego stanu. Przy operatorach: `observedGeneration`. A „rolling
restart bez przestojów" to obietnica dla danych z replikami, nie dla wszystkich.

<a id="039"></a>
## 039 — Po restarcie serwera ECK mówi „green 3”, a aplikacja: `No alive nodes`

**Objaw**
`verify.sh reboot` zaliczył „ES green 3/3 po 115 s”, ale chwilę później:
```
production.ERROR: No alive nodes. All the 1 nodes seem to be down.
kubectl get endpoints marketplace-es-http   →  ENDPOINTS: (puste)
kubectl get pods                            →  marketplace-es-default-*  0/1  Running  1 (41s ago)
kubectl get elasticsearch                   →  green  3  Ready
```

**Diagnoza**
Porównanie trzech źródeł prawdy: status zasobu `Elasticsearch` (operator),
`Ready` podów (kubelet) i endpointy Service'u (to, przez co faktycznie łączy
się aplikacja). Tylko pierwsze mówiło „gotowe”.

**Przyczyna**
Po restarcie węzła operator ECK jeszcze nie przeliczył stanu klastra i pokazywał
**ostatni znany status** (green sprzed restartu). Pody ES dopiero startowały,
nie były Ready, więc Service nie miał endpointów.

**Naprawa**
Test restartu wymaga: `health: green` **i** 3 pody ES `Ready` **i** 4 Deploymenty
`Available`. Prawdziwy czas z historii warunków (`lastTransitionTime` dla `Ready`):
ES 3/3 po 141 s, całość po 178 s.

**Czego się nauczyłem**
Status zasobu operatora to *raport* operatora, a nie obserwacja na żywo. Po
każdym zdarzeniu, które mogło go zdezaktualizować (restart węzła, przerwa
w działaniu operatora), sprawdzaj warstwę niżej: pody, endpointy, a na końcu
prawdziwe zapytanie.

### Ta sama noc: weryfikacja, która wisiała zamiast padać

Pięć różnych przyczyn i ten sam objaw (`verify.sh` stoi bez końca, bez błędu):
1. `kubectl exec` przez tunel wisiał na **zestawianiu strumienia**. `timeout 60`
   w podzie i `--request-timeout` tego nie obejmują. Rozwiązanie: limit po stronie Maca.
2. macOS nie ma `timeout`, więc powstało własne `with_timeout`. Pierwsza wersja
   strażnika `( sleep N; kill ) &` dziedziczyła stdout podstawienia `$(…)`, przez
   co bash czekał na `$(…)` pełne N sekund **przy każdym wywołaniu**.
   Naprawa: strażnik pisze do `/dev/null`.
3. `curl` do ES bez `-m` wisiał bez końca. Teraz `-m 30`.
4. `ssh -fN` (tunel) bez `ConnectTimeout` w trakcie bootu serwera: TCP już
   przyjmuje połączenia, a sshd jeszcze nie odpowiada.
5. `kubectl run -i --rm` uruchamiany w tle nie ma terminala do podłączenia,
   więc wisiał do limitu i był ponawiany. Faza trwała ~10 min zamiast 9 s.
   Teraz: create → wait → logs → delete.

Zasada: **każde wywołanie sieciowe w automatyzacji ma mieć limit czasu**, a każdy
mechanizm limitu czasu trzeba przetestować na szybkim przypadku. Strażnik z pkt 2
„działał”, tylko spowalniał wszystko do swojego limitu.

<a id="040"></a>
## 040 — Sprzedawca zablokowany, a jego oferty wciąż widać w `/search` (catalog-worker)

**Objaw**
Moderator zablokował sprzedawcę (status `blocked` w `sellers`), ale jego oferty
nadal są `published` i wyszukiwarka je zwraca. Po odblokowaniu odwrotnie: oferty
zostają `suspended`.

**Diagnoza**
```bash
docker compose ps catalog-worker        # k8s: kubectl -n marketplace get deploy catalog-worker
make worker-logs
docker compose exec postgres psql -U $CATALOG_DB_USER $CATALOG_DB \
  -c "select id, attempts, available_at from jobs order by id"
docker compose exec postgres psql -U $CATALOG_DB_USER $CATALOG_DB \
  -c "select id, failed_at, left(exception, 200) from failed_jobs order by id desc limit 5"
```
Job w `jobs` = worker nie nadąża albo nie działa. Job, który padł na regule
domeny (np. `ConcurrencyConflict` po 3 próbach, `SellerNotFound`), zostawia
też linię w `make worker-logs`: błędy domeny są wyciszone tylko w żądaniach
HTTP, nie w konsoli i workerze (`bootstrap/app.php`). Pusty `jobs` i pusty
`failed_jobs` przy niezmienionych ofertach = job nie powstał albo już się
wykonał; sprawdź, czy sprzedawca ma teraz właściwy status (job nic nie robi,
gdy status zdążył się zmienić).

**Przyczyna**
Polityka „zablokowany sprzedawca nie sprzedaje” jest *eventually consistent*:
handler blokady tylko wstawia job do tabeli `jobs` w tej samej transakcji co
zmianę statusu (albo blokada i job, albo nic), a oferty zawiesza dopiero proces
`catalog-worker` (`queue:work database`). Bez żywego workera blokada działa tylko
na sam status sprzedawcy.

**Naprawa**
1. Worker nie działa: `docker compose --profile apps up -d catalog-worker`
   (k8s: `kubectl -n marketplace rollout restart deploy/catalog-worker`).
2. Job w `failed_jobs` (po 5 próbach z backoffem 5/30/120 s): usuń przyczynę
   widoczną w kolumnie `exception`, potem
   `docker compose exec catalog-app php artisan queue:retry all`
   (albo konkretne ID). Polityka jest idempotentna, więc ponowienie nie
   dubluje zdarzeń outboxu.
   Objaw przekroczenia czasu (`MaxAttemptsExceeded` / timeout w `exception`):
   sprzedawca z bardzo wieloma ofertami; job ma `timeout=600` (worker
   `--timeout=600`, `retry_after`=660). `queue:retry all` wystarczy: job jest
   idempotentny i kontynuuje od ofert, które jeszcze nie zmieniły stanu.
3. Job zniknął, a oferty nie: przepchnij politykę ręcznie w tinkerze:
   `app(SellerPortal\Application\Policy\SuspendOffersOfBlockedSeller::class)->run(SellerPortal\Domain\Seller\SellerId::fromInt(ID))`
   (po odblokowaniu analogicznie `ReinstateOffersOfUnblockedSeller`).

4. Sprzedawca **aktywny**, a jego oferty `suspended` z `suspension_kind =
   seller_blocked` (albo odwrotnie: zablokowany z opublikowanymi) tuż po
   wdrożeniu: dwa workery wykonały „zablokuj” i „odblokuj” równolegle.
   Sprawdź, że `catalog-worker` ma `replicas: 1` i `strategy: Recreate`
   (`kubectl -n marketplace get deploy catalog-worker -o jsonpath='{.spec.replicas} {.spec.strategy.type}'`
   → `1 Recreate`), potem przepchnij politykę ręcznie jak w pkt 3.

**Jeden worker, nie więcej**
Polityki sprawdzają status sprzedawcy tylko na starcie joba i zakładają
kolejność FIFO na **jednym** workerze. Dlatego `catalog-worker` ma dokładnie
jedną replikę i `strategy: Recreate` (stary pod kończy pracę, zanim wstanie
nowy), a `terminationGracePeriodSeconds: 120` daje `queue:work` czas na
dokończenie bieżącego joba po SIGTERM. Nie skaluj workera bez wcześniejszej
zmiany polityk (np. ponowne sprawdzanie statusu sprzedawcy przy każdej
ofercie). Compose ma jeden kontener i zatrzymuje go przed startem nowego,
więc zachowuje się tak samo.

**Czego się nauczyłem**
Spójność ostateczna wymaga właściciela dla „ostatecznie”: bez działającego
konsumenta kolejka jest tylko tabelą. Worker ma więc healthcheck, wpis tutaj
i pozycję w sprawdzeniach wdrożenia. Szybkie „zablokuj → odblokuj” jest
bezpieczne, bo job sprawdza stan sprzedawcy w chwili wykonania — pod
warunkiem, że joby wykonuje jeden worker po kolei. Domyślny RollingUpdate
w k8s po cichu łamał to założenie przy każdym wdrożeniu (przegląd końcowy,
I3).

<a id="041"></a>
## 041 — Szósta zmiana ceny w dobie przechodzi (dev DB z migracjami sprzed poprawki precyzji)

**Objaw**
Na lokalnym stacku sprzedawca zmienia cenę kilka razy szybko po sobie
(albo skrypt w tinkerze robi to w pętli) i przekracza limit 5 zmian na dobę
bez błędu „Wykorzystano limit 5 zmian ceny na dobę”. Testy na hoście są
zielone (SQLite), a w tabeli `offer_price_changes` jest mniej wierszy, niż
było zmian.

**Diagnoza**
```bash
docker compose exec postgres psql -U $CATALOG_DB_USER $CATALOG_DB -c \
  "select table_name, column_name, datetime_precision from information_schema.columns
   where (table_name, column_name) in (('offer_price_changes','changed_at'), ('offers','pending_requested_at'))"
```
Obie kolumny muszą mieć `datetime_precision = 6`. Wartość `0` oznacza bazę,
która uruchomiła migracje portalu w wersji sprzed poprawki.

**Przyczyna**
`timestampTz('changed_at')` bez precyzji to w Postgresie `timestamptz(0)`,
czyli zaokrąglenie do pełnych sekund. `SqlOfferRepository::savePriceChanges`
dopisuje do historii tylko wpisy nowsze od najnowszego zapisanego, więc dwie
zmiany z tej samej (zaokrąglonej) sekundy sklejają się w jeden wiersz,
a limit dobowy liczy za mało. SQLite tego nie pokazuje, dlatego wyszło
dopiero przy weryfikacji migracji na jednorazowym Postgresie 18.4 (Task 6).
Poprawka zmieniła **już zacommitowane** migracje
(`2026_10_15_100001_…` i `2026_10_15_100002_…`, precyzja 6). Laravel nie
uruchamia ponownie migracji, które ma w tabeli `migrations`, więc baza
deweloperska, która przeszła starą wersję, zostaje ze starym typem kolumny.
(`sellers.status_changed_at` ma precyzję 0 i to jest w porządku: nic jej nie
porównuje.)

**Naprawa**
Wariant A, zostawia produkty i użytkowników (migracje portalu są odwracalne,
a w repo nie ma nowszych):
```bash
docker compose exec catalog-app php artisan migrate:rollback --step=3 --force
docker compose exec catalog-app php artisan migrate --force
make reproject
```
Uwaga: rollback cofa kolumny portalu. Sprzedawcy zarejestrowani przez portal
tracą powiązanie z kontem i wracają jako aktywni bez `user_id` (trzeba się
zgłosić od nowa). Oferty przechodzą przez `active`: opublikowane zostają
opublikowane, a szkice i zawieszone stają się wycofane. Znikają też
oczekujące zmiany cen i historia zmian cen. Na dev to akceptowalne.

Wariant B, od zera:
```bash
docker compose exec catalog-app php artisan migrate:fresh --force
make seed && make admin email=ty@example.com && make reproject
```
Na produkcji (k3s) problem nie wystąpi, bo migracje wejdą tam od razu
w poprawionej wersji.

**Czego się nauczyłem**
Migracja, która już gdzieś się wykonała, jest niezmienna. Poprawka typu
kolumny w już uruchomionej migracji naprawia tylko świeże bazy. Gdyby ta
gałąź była już na produkcji, poprawka musiałaby być nową migracją
(`ALTER COLUMN … TYPE timestamptz(6)`). Druga lekcja: typy dat i ich
precyzję sprawdza się na tym samym silniku co produkcja, bo SQLite w testach
przyjmie wszystko.

<a id="042"></a>
## 042 — „Ktoś w międzyczasie zmienił tę ofertę — odśwież stronę” (konflikt wersji)

**Objaw**
Sprzedawca albo moderator zapisuje formularz oferty i dostaje komunikat
„Ktoś w międzyczasie zmienił tę ofertę — odśwież stronę” (błąd `domain`,
HTTP 302 z powrotem na stronę). Zmiana się nie zapisała.

**Diagnoza**
```bash
# kto i kiedy zmieniał tę ofertę (ostatnie zdarzenia, sequence = wersja)
docker compose exec postgres psql -U $CATALOG_DB_USER $CATALOG_DB -c \
  "select sequence, event_type, occurred_at from outbox
   where aggregate_type = 'offer' and aggregate_id = '<ID>' order by id desc limit 10"
docker compose exec postgres psql -U $CATALOG_DB_USER $CATALOG_DB -c \
  "select id, version, status, stock, price_cents, pending_price_cents from offers where id = <ID>"
```
W UI to samo widać na osi czasu oferty (`/seller/offers/<ID>`).

**Przyczyna**
Oferta zmieniła się od chwili, w której człowiek ją zobaczył. Dwa mechanizmy
dają ten sam wyjątek `ConcurrencyConflict` i ten sam komunikat:

1. **Wersja z formularza** (najczęstszy przypadek). Każdy formularz oferty
   (sprzedawca: publikacja, cena, anulowanie zgłoszenia, stan, szczegóły,
   wycofanie; moderator: zawieszenie, przywrócenie, akceptacja i odrzucenie
   ceny) odsyła `version` z wyrenderowanej strony, a handler porównuje ją
   z wersją wczytaną teraz (`SellerPortal\Application\ExpectedVersion`).
   Różnica = ktoś zapisał ofertę w międzyczasie: druga karta tego samego
   sprzedawcy, moderator, job polityki zawieszający oferty zablokowanego
   sprzedawcy. Działa niezależnie od tego, ile czasu minęło między kartami.
2. **Blokada w repozytorium** (rzadko, wyścig w milisekundach).
   `SqlOfferRepository::save` robi `UPDATE … WHERE id = ? AND version = ?`;
   gdy dwa żądania wczytały tę samą wersję i zapisują naraz, drugi `UPDATE`
   trafia w 0 wierszy.

To jest **zamierzone**: zamiast po cichu nadpisać cudzą zmianę (lost update),
drugi zapis jest odrzucany w całości, razem z wierszami outboxu (jedna
transakcja).

Pokrewny komunikat w kolejce moderatora: „Sprzedawca zmienił zgłoszoną cenę —
odśwież kolejkę” (`PendingPriceChangeMismatch`). Moderator decyduje o kwocie,
którą widział (`expected_price_cents`), a sprzedawca w międzyczasie anulował
zgłoszenie i zgłosił inną. Tę regułę agregat sprawdza **przed** wersją, żeby
moderator dostał konkretny powód. Naprawa ta sama: odśwież kolejkę i zdecyduj
o aktualnej kwocie.

**Naprawa**
1. Pojedynczy przypadek: odśwież stronę i powtórz. To normalne zachowanie.
   Komunikat „Brak wersji oferty — odśwież stronę” (błąd pola `version`)
   oznacza stary widok sprzed wdrożenia albo własny skrypt/`curl` bez pola
   `version` — odśwież stronę lub dodaj pole.
2. Konflikt przy **każdej** próbie na tej samej ofercie: coś zmienia ją
   w kółko w tle. Sprawdź w outboxie, jakie zdarzenia przybywają, i czy
   `catalog-worker` nie mieli tego samego joba (`make worker-logs`,
   RUNBOOK [#040](#040)). Job polityki sam ponawia ofertę 3 razy przy
   konflikcie, a po wyczerpaniu prób ponawia się cały (idempotentnie).
3. Konflikt bez żadnego nowego zdarzenia w outboxie: ktoś zmienił
   `offers.version` z pominięciem repozytorium (ręczny SQL). Zapisy ofert idą
   wyłącznie przez handlery Seller Portalu ([ADR-0002](adr/0002-czysta-domena-bez-eloquenta.md)).

**Czego się nauczyłem**
Konflikt wersji to komunikat dla użytkownika, nie błąd serwera: ma dać 302
z czytelnym tekstem, nigdy 500 i nigdy cichego nadpisania (testy
`PriceGuardTest` › „druga karta z nieaktualną wersją…” i „równoległa edycja →
błąd concurrency”, `ModerationTest` › „sprzedawca anulował i zgłosił inną
cenę…”). Pierwsza wersja portalu miała tylko blokadę w repozytorium
i obiecywała ochronę „dwóch kart” — przegląd końcowy pokazał, że karty
otwarte od minut nigdy się nie nakładają, więc ta obietnica nie była
prawdziwa, dopóki formularze nie zaczęły odsyłać wersji. Jak to zepsuć
i odtworzyć dwiema „kartami” w tinkerze: [`10-DDD.md`](10-DDD.md), moduł 7.

<a id="043"></a>
## 043 — Przed wdrożeniem portalu na k3s (pre-flight danych i okno migracji)

**Objaw (którego chcemy uniknąć)**
Po `make k3s-deploy` z portalem sprzedawcy strona produktu w panelu
(`/panel/products/<ID>`) daje 500 z `UnexpectedValueException: Uszkodzony
wiersz oferty #…` albo `… sprzedawcy #…`, a job polityki blokady kończy się
w `failed_jobs` na jednej ofercie, zostawiając resztę ofert sprzedawcy
niezawieszonych (RUNBOOK [#040](#040)). Druga rzecz: przez chwilę między
Jobem migracji a końcem rolloutu stare pody `catalog-app` odpowiadają 500.

**Diagnoza — pre-flight PRZED `make k3s-deploy`**
Stary panel pozwalał na wartości, których value objecty portalu nie
przyjmą: `Stock` 0–100 000, `Money` > 0 groszy, `ShippingTime` 1–30 dni,
`SellerName` 3–120 znaków, `City` niepuste po trim i ≤ 120 znaków (stara
kolumna `sellers.city` jest nullable). Zapytanie czyta tylko kolumny, które
istnieją przed migracjami, więc działa na starym schemacie. Tunel musi
działać (`make k3s-tunnel`):
```bash
/opt/homebrew/bin/kubectl --kubeconfig ~/.kube/elastic-vps.yaml -n marketplace \
  exec -i postgres-0 -- psql -U postgres -d catalog <<'SQL'
-- oferty, których SqlOfferRepository::hydrate nie wczyta
select o.id, o.seller_id, o.product_id, o.stock, o.price_cents, o.currency, o.shipping_days
from offers o
left join sellers s on s.id = o.seller_id
left join products p on p.id = o.product_id
where o.stock > 100000
   or o.price_cents <= 0
   or o.currency <> 'PLN'
   or o.shipping_days not between 1 and 30
   or s.id is null            -- brak sprzedawcy (FK z cascade, więc tylko po ręcznym SQL)
   or p.id is null;           -- brak produktu (jw.)

-- sprzedawcy, których SqlSellerRepository::hydrate nie wczyta
select id, name, city
from sellers
where city is null
   or btrim(city) = ''
   or char_length(btrim(city)) > 120
   or char_length(btrim(name)) < 3
   or char_length(btrim(name)) > 120;
SQL
```
Oba wyniki muszą być puste. (Nazwy bazy i użytkownika z
`deploy/k8s/config.env`: `CATALOG_DB=catalog`, `POSTGRES_USER=postgres`.)

**Przyczyna**
Portal odtwarza agregaty z bazy przez value objecty (`hydrate`), a te
pilnują zakresów, których stary CRUD nie pilnował. Jeden zły wiersz psuje
całą stronę produktu (panel ładuje agregat każdej oferty) albo zatrzymuje
politykę na tej ofercie.

Okno 500: `tools/k3s/deploy.sh` uruchamia Job migracji (usuwa
`offers.active`) **przed** rolloutem aplikacji. Stare pody wciąż mają kod
z `where('active', true)` i do czasu podmiany dostają błędy SQL. To
oczekiwane, krótkie okno (sekundy do minuty). Search-consumer ponawia
pobranie projekcji (błąd odzyskiwalny), a `make k3s-reproject` po wdrożeniu
i tak wyrównuje indeks.

**Naprawa**
1. Pre-flight zwrócił wiersze: popraw je **przed** wdrożeniem, świadomie,
   po jednym (np. `update offers set stock = 100000 where id = …`,
   `update sellers set city = 'Nieznane' where id = …`). Zanotuj, co
   zmieniłeś. Potem uruchom zapytanie jeszcze raz.
2. Okno 500 skróć, nie zatrzymując się między krokami: `make k3s-deploy`
   przechodzi od migracji prosto do rolloutu, więc nie przerywaj go i nie
   wdrażaj w szczycie ruchu. Jeśli 500 są niedopuszczalne, przed wdrożeniem
   włącz tryb serwisowy w starym podzie
   (`… exec deploy/catalog-app -- php artisan down`): sterownik `file`
   trzyma flagę w systemie plików poda, więc nowy pod startuje już bez niej.
   Przy więcej niż jednej replice trzeba to zrobić w każdym podzie.
3. Po wdrożeniu: `catalog-worker` dostępny
   (`make k3s k="get deploy catalog-worker -n marketplace"`, strategia
   `Recreate`, RUNBOOK [#040](#040)), `make k3s-reproject`, `search:eval`.

**Czego się nauczyłem**
Nowe niezmienniki w kodzie to też migracja danych, tylko niewidoczna:
schemat przechodzi, a stare wiersze wybuchają przy pierwszym odczycie.
Pre-flight sprawdza dokładnie te zakresy, które pilnują value objecty.
Druga lekcja: „schemat przed kodem” (expand) zakłada, że stary kod przeżyje
nowy schemat. Usunięcie kolumny w tym samym wdrożeniu (contract) to
świadomie przyjęte okno błędów; czysta wersja to zostawić `active` na jedno
wydanie i usunąć ją w następnym.

<a id="044"></a>
## 044 — Zamówienia nie wygasają, towar wisi zarezerwowany (catalog-scheduler)

> Wpis napisany przed pierwszym uruchomieniem zamówień (Task 16), na
> żywym stacku sprawdzony 2026-10-17. Objaw wystąpił naprawdę już przy
> pierwszym uruchomieniu, ale z przyczyną, której tu nie było: scheduler
> żył, a każdy przebieg sweepu zabijał OOM (#048). Po podniesieniu limitu
> sweep działa: zamówienie bez płatności wygasło po 15 + 5 min i oddało
> rezerwację (oferta wróciła do indeksu), a zamówienie z terminem cofniętym
> w bazie o 6 min wygasło ~30 s później, przy najbliższym przebiegu.

**Objaw**
Zamówienie „Czeka na płatność” albo „Sprawdzamy dostępność” długo po
terminie (15 min + 5 min karencji). Oferta, której ostatnią sztukę ktoś
zarezerwował i nie zapłacił, nie wraca do `/search`. U sprzedawcy
„zarezerwowane” nie spada.

**Diagnoza**
```bash
docker compose ps catalog-scheduler                 # healthy? (healthcheck: proces schedule:work)
make scheduler-logs                                 # co minutę „Wygaszono zamówień: N”
curl -sk https://catalog.localhost/api/health | jq .ordering_sweep_last_run_at
make psql db=catalog
select id, status, payment_deadline from orders
 where status in ('placed', 'awaiting_payment') and payment_deadline < now() - interval '5 minutes';
```
`ordering_sweep_last_run_at` = `null` albo starsze niż 2 minuty → sweep nie
biegnie. Sweep biegnie, a zamówienia po terminie zostają → patrz log
(`ConcurrencyConflict` jest pomijany i spróbuje za minutę; każdy inny
wyjątek pomija tylko to jedno zamówienie: w logu „Sweep nie wygasił
zamówienia” z `order_id` i wyjątkiem, komenda kończy się kodem FAILURE,
a reszta zamówień wygasa normalnie). To samo zamówienie w logu co minutę =
zepsuty wiersz albo błąd w kodzie, którego ponowienie nie naprawi.

**Przyczyna**
Czas jest w tym procesie aktorem: nikt nie wyśle zdarzenia „minął termin”.
Wygasza `orders:expire-overdue` (`routes/console.php`, co minutę,
`withoutOverlapping`), a uruchamia go wyłącznie kontener `catalog-scheduler`
(`schedule:work`). Bez niego zamówienia nie wygasają, zwolnienie rezerwacji
nigdy nie jest kolejkowane i towar wisi. Drugi powód: `withoutOverlapping`
trzyma blokadę w cache; jeśli przebieg zginął w połowie (OOM, kill),
blokada wygasa dopiero po swoim czasie (domyślnie 24 h), a do tego czasu
każdy kolejny przebieg jest pomijany.

**Naprawa**
1. Scheduler nie działa: `docker compose --profile apps up -d catalog-scheduler`.
   Działa, a `ordering_sweep_last_run_at` stoi: sprawdź OOM (#048).
2. Jednorazowo, bez czekania: `docker compose exec catalog-app php artisan orders:expire-overdue`
   (idempotentne: wygaszone zamówienie przy drugim przebiegu to no-op).
3. Zablokowany `withoutOverlapping`: `docker compose exec catalog-app php artisan schedule:clear-cache`.
4. Wygaszone zamówienia mają w `jobs` kroki `ReleaseOrderReservationsJob`;
   wykona je `catalog-worker` (#045, #046).

**Czego się nauczyłem**
Proces, którego jeden krok wyzwala zegar, ma o jeden proces systemowy więcej
do pilnowania. Sweep jest odporniejszy niż opóźniony job (zgubiony job
zostawia zamówienie wiszące na zawsze, sweep naprawia nawet te, w których
saga utknęła), ale tylko wtedy, gdy ktoś widzi, że żyje: stąd czas ostatniego
przebiegu w `/health`.

<a id="045"></a>
## 045 — Zamówienie wisi w „Sprawdzamy dostępność” (`placed`)

> Wpis napisany przed pierwszym uruchomieniem (Task 16), na żywym stacku
> sprawdzony 2026-10-17 przez celowo zatrzymany worker (wynik niżej, w
> „Ile to normalnie trwa”).

**Objaw**
Kupujący złożył zamówienie, strona `/orders/{id}` odświeża się co sekundę
i od dłuższej chwili pokazuje „Sprawdzamy dostępność towaru — odświeżam co
sekundę.” Nie przechodzi ani do „Czeka na płatność”, ani do „Odrzucone”.
Po 120 s strona przestaje odpytywać i pokazuje „Sprawdzanie trwa dłużej niż
zwykle — odśwież stronę za chwilę”.

**Diagnoza**
```bash
docker compose ps catalog-worker
make worker-logs
make psql db=catalog
select id, status, placed_at from orders where id = <ID>;
select event_type, occurred_at from order_events where order_id = <ID> order by id;
select id, attempts, available_at, payload::json->>'displayName' as job from jobs order by id;
select id, failed_at, payload::json->>'displayName' as job, left(exception, 200) from failed_jobs order by id desc limit 5;
select reservation_key, offer_id, quantity, status from offer_reservations where reservation_key = 'order-<ID>';
```
- `ReserveOrderLinesJob` w `jobs`, `attempts = 0` → worker nie działa albo nie nadąża.
- `attempts > 0` i `available_at` w przyszłości → krok padł i czeka na
  ponowienie (backoff 5/30/120 s); powód w `make worker-logs`.
- Job w `failed_jobs` → #046.
- Brak joba i w `order_events` tylko `order.placed` → job nie powstał.
  Nie powinno się zdarzyć (job jest wstawiany w transakcji złożenia), chyba
  że ktoś zmienił `ORDERING_PROCESS_QUEUE_CONNECTION` na połączenie spoza
  bazy (Redis, `sync`).
- Część pozycji ma wiersz `active` w `offer_reservations`, a zamówienie jest
  dalej `placed` → awaria między T1 (rezerwacja w ofercie) a T2 (zapis
  w zamówieniu). Ponowiony job dokończy, bo rezerwacja tym samym kluczem jest
  no-opem (R2).

**Przyczyna**
`Placed` to stan procesu widoczny dla kupującego: zamówienie istnieje,
a rezerwację robi dopiero `catalog-worker` (`queue:work database`) po
`OrderPlaced`. Bez działającego workera saga nie rusza. Przy gorącej ofercie
kroki rezerwacji tej samej oferty rywalizują o jej wersję i ponawiają się,
więc przejście trwa dłużej, ale nie stoi.

**Naprawa**
1. Worker nie działa: `docker compose --profile apps up -d catalog-worker`.
2. Krok w `failed_jobs`: #046.
3. Ręcznie, w tinkerze (`docker compose exec catalog-app php artisan tinker`):
   `app(Ordering\Application\Process\ReserveOrderLines::class)->run(Ordering\Domain\OrderId::fromInt(<ID>))`.
   Krok jest idempotentny i sam sprawdza, czy zamówienie jest dalej `placed`.
4. Nic nie robić też jest naprawą: po terminie i karencji sweep wygasi
   zamówienie, a zwolnienie odda każdą pozycję, także zajętą bez zapisu w
   zamówieniu (R10). Kosztem jest kupujący, który 20 minut patrzy na
   „Sprawdzamy dostępność”.

**Ile to normalnie trwa (zmierzone 2026-10-17, lokalny stack)**
- Zwykłe złożenie z działającym workerem: ~1 s. Koszyk u dwóch sprzedawców,
  historia zamówienia: złożono 17:10:21, zarezerwowano 17:10:22 (UI pokazuje
  pełne sekundy, więc to „poniżej 2 s”, nie dokładna latencja).
- Worker zatrzymany (`docker compose stop catalog-worker`): po 10 s
  zamówienie dalej `placed`, w `jobs` jeden job. Po
  `docker compose start catalog-worker` → `awaiting_payment` po **28,1 s**,
  wliczając start kontenera. Saga domknęła się sama, bez ręcznego kroku.
- Pod obciążeniem (`orders:race-test`, 50 kupujących, 10 sztuk): czasu
  `placed` → `awaiting_payment` osobno **nie mierzyłem**. Race-test podaje
  tylko latencję samego złożenia, a i ta na współdzielonej maszynie była
  nieporównywalna między przebiegami (p50 od 0,6 s do 7 s, [POMIARY](POMIARY.md#zamowienia-2026-10-17)).

Próg praktyczny: kilka sekund w `placed` przy działającym workerze to już
objaw; pół minuty bywa normalne tylko tuż po starcie workera.

**Czego się nauczyłem**
Spójność ostateczna, którą widzi użytkownik, potrzebuje progu „to już nie
jest normalne”. Bez zmierzonego czasu przejścia każde „wisi” to zgadywanie.

<a id="046"></a>
## 046 — Krok sagi w `failed_jobs`: diagnoza i ręczne ponowienie

> Wpis napisany przed pierwszym uruchomieniem (Task 16). Na żywym stacku
> 2026-10-17 do `failed_jobs` nie trafiło nic: sześć przebiegów
> `orders:race-test` (oba style) skończyło się z `failed_jobs` = 0, a krok
> rezerwacji po zatrzymanym workerze dokończył się sam (#045). Tabela
> wyjątków niżej dalej wynika z kodu, nie z incydentu.

**Objaw**
W `failed_jobs` jest job z przestrzeni `Ordering\Infrastructure\Job\`
(`ReserveOrderLinesJob`, `ReleaseOrderReservationsJob`,
`ConfirmOrderReservationsJob`, `RefundPaymentJob`) albo
`App\FakePaymentGateway\Jobs\SendPaymentWebhookJob`. Zamówienie stoi:
opłacone, a stan oferty nie spadł; zwrot „w toku” od godzin; „Sprawdzamy
dostępność” bez końca.

**Diagnoza**
```bash
make psql db=catalog
select uuid, failed_at, payload::json->>'displayName' as job, left(exception, 300)
  from failed_jobs order by id desc limit 10;
```
Pierwsza linia `exception` mówi, co dalej:

| Wyjątek | Krok | Co znaczy |
|---|---|---|
| `ConcurrencyConflict` | dowolny | 3 próby w kroku × 5 prób joba przegrały z innymi zapisami tej samej oferty albo zamówienia. Gorąca oferta albo pętla. Ponowienie zwykle przechodzi |
| `PaymentGatewayUnavailable` | `RefundPaymentJob` | bramka nie przyjęła zwrotu 5 razy. Zwrot zostaje `requested` („w toku”) |
| `ReservationNotFound` | `ConfirmOrderReservationsJob` | rezerwacja zamówienia nie jest aktywna: została **zwolniona** przed potwierdzeniem. Pieniądze są, a stan oferty nie spadł. Ponowienie nic nie da |
| `ReservationConflict` | `ReserveOrderLinesJob` | rezerwacja klucza już rozliczonego (`confirmed`/`released`). Znany przypadek R19: zdublowany krok rezerwacji trwał przez płatność i potwierdzenie. Zamówienie zwykle jest w porządku, a job jest zbędny |
| `MaxAttemptsExceeded` | `SendPaymentWebhookJob` | 10 prób dostarczenia webhooka bez 2xx (#047) |

Dla rezerwacji sprawdź stan klucza:
```sql
select offer_id, quantity, status, reserved_at, settled_at from offer_reservations where reservation_key = 'order-<ID>';
select status, payment_id from orders where id = <ID>;
```

**Przyczyna**
Każdy krok sagi jest idempotentny i ponawiany (`tries = 5`, backoff
5/30/120 s), więc do `failed_jobs` trafia tylko to, czego ponowienia nie
naprawiły. Zamówienie świadomie **nie** śledzi, czy potwierdzenie
rezerwacji po płatności przeszło (spec, sekcja 5), a rekonsyliacji nie ma:
`failed_jobs` jest jedynym sygnałem trwałej awarii.

**Naprawa**
1. Przyczyna przejściowa (konflikt, bramka, sieć): usuń ją, potem
   `docker compose exec catalog-app php artisan queue:retry <uuid>`
   (albo `queue:retry all`). Kroki są idempotentne, ponowienie nie dubluje
   rezerwacji, zwrotów ani zdarzeń outboxu.
2. Ręcznie, w tinkerze, gdy joba już nie ma:
   ```php
   use Ordering\Domain\OrderId; use Ordering\Domain\RefundId;
   app(Ordering\Application\Process\ConfirmOrderReservations::class)->run(OrderId::fromInt(<ID>));
   app(Ordering\Application\Process\ReleaseOrderReservations::class)->run(OrderId::fromInt(<ID>));
   app(Ordering\Application\Process\RefundPayment::class)->run(OrderId::fromInt(<ID>), RefundId::fromString('refund-<ID>-1'));
   ```
3. `ReservationNotFound` przy potwierdzeniu: **nie** ponawiaj. Sztuki
   opłaconego zamówienia wróciły do sprzedaży. Ustal z `order_events`
   i `offer_reservations`, kto zwolnił (to błąd w sadze, nie zwykła ścieżka),
   a stan oferty popraw ręcznie przez sprzedawcę (zmiana stanu w portalu,
   z zachowaniem `StockBelowReserved`). Usuń wpis: `php artisan queue:forget <uuid>`.
4. `ReservationConflict` z R19: sprawdź, że zamówienie jest `paid`/`shipped`,
   a jego klucz `confirmed`. Jeśli tak, job jest zbędny: `queue:forget <uuid>`.

**Czego się nauczyłem**
Idempotentny krok można bez strachu ponawiać, ale nie każdy błąd jest
przejściowy. Tabela wyjątków wyżej to w praktyce lista rzeczy, które
naprawiłaby rekonsyliacja; dopóki jej nie ma, robi to człowiek z tym wpisem.

<a id="047"></a>
## 047 — Bramka płatności: przyciski deweloperskie i webhook, który nie dochodzi

> Wpis napisany przed pierwszym uruchomieniem (Task 16), na żywym stacku
> sprawdzony 2026-10-17 w przeglądarce: webhook z workera przez wewnętrzny
> Caddy (`/webhooks/*`) przestawił zamówienie na „Opłacone”; po powrocie
> z bramki strona pokazywała „Czekamy na potwierdzenie płatności” bez
> „Zapłać”; „Zapłać mimo wygaśnięcia” dało zwrot `late_payment`, a „Wyślij
> webhook ponownie” zakończył job bez skutku (dalej jeden zwrot i siedem
> zdarzeń na osi). Objaw (b) nie wystąpił, więc przyczyny niżej to dalej
> lista z kodu.

**Objaw**
(a) Na stronie bramki `/fake-gateway/payments/{id}` nie ma przycisków
„Wyślij webhook ponownie” i „Zapłać mimo wygaśnięcia” albo zwracają 404.
(b) Po „Zapłać” w bramce zamówienie zostaje „Czeka na płatność”, a strona
pokazuje „Czekamy na potwierdzenie płatności” i po 30 s wraca „Zapłać”.

**Diagnoza**
```bash
docker compose exec catalog-app php artisan about --only=environment   # APP_ENV
make worker-logs                                                       # SendPaymentWebhookJob
make psql db=catalog
select id, status, decided_at, expires_at from fake_gateway_payments order by created_at desc limit 5;
select id, attempts, payload::json->>'displayName' from jobs where payload like '%SendPaymentWebhookJob%';
make logs s=catalog-app        # „Nieprawidłowy podpis” = 401, „brak sekretu” = 500, „nieprawidłowym payloadem” / „nieznanego zamówienia” = 200 z ostrzeżeniem
```

**Przyczyna**
(a) Przyciski deweloperskie działają tylko w `APP_ENV` `local` i `testing`
(`FakePaymentGateway::developerToolsEnabled`); gdzie indziej akcje `resend`
i `pay_expired` dają 404. „Wyślij webhook ponownie” przed decyzją daje 409
(nie ma czego wysłać).
(b) Webhook jest jobem `SendPaymentWebhookJob` na połączeniu
`services.fake_gateway.queue_connection` (domyślnie `database`), który
wykonuje `catalog-worker` po ~2 s (`FAKE_GATEWAY_WEBHOOK_DELAY_SECONDS`)
i wysyła `POST` pod `FAKE_GATEWAY_WEBHOOK_URL`
(w compose `http://catalog-app/webhooks/payments`). Trzy typowe przyczyny:
worker nie działa; wewnętrzny blok Caddy `http://catalog-app` nie
przepuszcza `/webhooks/*` (obsługiwał tylko `/api/*`, trasa doszła
w etapie zamówień, `infra/caddy/Caddyfile`); sekret
`FAKE_GATEWAY_WEBHOOK_SECRET` różny w `catalog-app` i `catalog-worker`
(webhook dostaje 401 i job ponawia do 10 razy) albo pusty w `catalog-app`
(webhook dostaje 500, w logu „Webhook płatności odrzucony: brak sekretu”;
pusty sekret odrzucamy celowo, bo HMAC z pustym kluczem policzy każdy).

**Naprawa**
1. Przyciski: ustaw `APP_ENV=local` w lokalnym `.env`. Na serwerze ich nie
   włączaj, to celowe.
2. Worker: `docker compose --profile apps up -d catalog-worker`.
3. Caddy: Caddyfile jest **wbudowany w obraz** `catalog-app`
   (`COPY infra/caddy/Caddyfile` w `infra/php/catalog.Dockerfile`), więc
   sama zmiana pliku i restart nie wystarczą. Po pierwszym uruchomieniu
   zamówień (albo po zmianie Caddyfile):
   `docker compose --profile apps build catalog-app && docker compose --profile apps up -d catalog-app`.
   Sprawdzenie z sieci `marketplace`:
   `docker compose exec catalog-worker curl -s -o /dev/null -w '%{http_code}\n' -X POST http://catalog-app/webhooks/payments`
   → `401` (trasa jest, podpis zły) zamiast `404`; `500` znaczy, że
   `catalog-app` nie ma sekretu (punkt 4).
4. Sekret: ta sama wartość w obu serwisach (`compose.yaml` podaje domyślną
   `dev-only-fake-gateway-secret` obu), potem restart obu i „Wyślij webhook
   ponownie”. Zdublowany webhook jest bezpieczny (R4: ten sam `paymentId`
   to no-op).
5. Zwroty z `FAKE_GATEWAY_FAIL_FIRST_REFUND=true` celowo padają przy
   pierwszej próbie: zwrot jest „w toku”, po backoffie joba „zwrócono”.
   To pokaz ponowień, nie awaria.

**Czego się nauczyłem**
ACL webhooka odpowiada 200 na wszystko poza złym podpisem i brakiem sekretu, więc błędy
„po naszej stronie” widać tylko w logu. Test w pamięci nie złapie trasy w
Caddy ani różnicy sekretów między kontenerami; to wychodzi dopiero na stacku.

<a id="048"></a>
## 048 — `catalog-scheduler` biegnie, a sweep ginie z kodem 137 i nic nie wygasa

> Incydent z pierwszego uruchomienia zamówień na żywym stacku, 2026-10-17.

**Objaw**
`docker compose ps catalog-scheduler` pokazuje `healthy`, a zamówienia po
terminie zostają „Czeka na płatność” i towar wisi zarezerwowany (jak w
#044). `ordering_sweep_last_run_at` w `/api/health` się nie przesuwa.
Proces `orders:expire-overdue` uruchomiony przez scheduler kończy się
kodem **137**, bez żadnego wyjątku PHP.

**Diagnoza**
Wszystkie komendy z katalogu głównego checkoutu (tam leży `.env`,
z którego `docker compose` i `make` biorą nazwy i porty):
```bash
make scheduler-logs                                   # czy przebiegi kończą się DONE, czy błędem
docker inspect marketplace-catalog-scheduler-1 --format 'mem={{.HostConfig.Memory}}'   # limit w bajtach
docker stats --no-stream marketplace-catalog-scheduler-1   # MEM USAGE względem LIMIT w chwili przebiegu
```
Kod 137 = 128 + 9, proces dostał SIGKILL. Bez wyjątku w logu to prawie
zawsze OOM killer (ten sam mechanizm co w [#001](#001)). Healthcheck
patrzy tylko, czy PID 1 to `schedule:work`, a ten przeżywa: ginie dziecko.

**Przyczyna**
`schedule:work` (~63 MB) przy każdym przebiegu uruchamia **osobny, pełny
proces Laravela** dla `orders:expire-overdue` i obie rzeczy liczą się do
jednego limitu kontenera. Przy `mem_limit: 128m` dziecko się nie mieściło,
więc sweep nie przeszedł ani razu i nic nie wygasało. W testach tego nie
widać: tam komenda biegnie w procesie testu, bez limitu pamięci.

**Naprawa**
Jak wyżej, z katalogu głównego checkoutu.
1. Na już, bez restartu: `docker update --memory 256m marketplace-catalog-scheduler-1`.
   Sprawdzone: kolejne przebiegi co minutę kończyły się `DONE` (2 s, 11 s).
2. Na stałe: `compose.yaml`, `catalog-scheduler` → `mem_limit: 256m`
   (z komentarzem dlaczego), potem
   `docker compose --profile apps up -d catalog-scheduler`.
3. Jeśli przebieg zginął w połowie, blokada `withoutOverlapping` może
   pomijać kolejne przebiegi: `docker compose exec catalog-app php artisan schedule:clear-cache` (#044, pkt 3).

**Czego się nauczyłem**
Limit pamięci liczy się dla całego drzewa procesów kontenera, nie dla PID 1.
Proces, który „tylko planuje”, przy każdym tyknięciu staje się dwoma
procesami Laravela. Healthcheck „proces żyje” nie mówi, czy praca się
wykonuje; prawdziwy sygnał to czas ostatniego udanego przebiegu w `/health`.

<a id="049"></a>
## 049 — `orders:race-test` w `catalog-app` milknie po ~2 s (kod 137)

> Incydent z pomiarów zamówień na żywym stacku, 2026-10-17.

**Objaw**
`docker compose exec catalog-app php artisan orders:race-test --buyers=50 --stock=10 --workers=4`
po ~2 s milknie: bez komunikatu, bez raportu, kod wyjścia 137.

**Diagnoza**
Wszystkie komendy z katalogu głównego checkoutu (tam leży `.env`,
z którego `docker compose` i `make` biorą nazwy i porty):
```bash
echo $?                                              # 137 po exec
docker stats --no-stream marketplace-catalog-app-1   # limit 512 MiB
```

**Przyczyna**
Race-test startuje 50 procesów kupujących (`orders:race-test:buyer`, każdy
to pełny Laravel) i 4 workery naraz, w tym samym kontenerze co aplikacja.
`catalog-app` ma `mem_limit: 512m`; 50 procesów PHP się w nim nie mieści
i OOM killer zabija komendę. Podnosić limitu aplikacji dla jednego pomiaru
nie warto.

**Naprawa**
Jednorazowy kontener z tego samego obrazu, z większym limitem, w sieci
stacku i z tymi samymi zmiennymi co `catalog-app`. Z katalogu głównego
checkoutu:
```bash
env_file=$(mktemp)
docker inspect marketplace-catalog-app-1 --format '{{range .Config.Env}}{{println .}}{{end}}' > "$env_file"
docker run --rm --memory 3g --network marketplace_marketplace --env-file "$env_file" \
  -v "$PWD/apps/catalog:/app" -w /app marketplace-catalog-app \
  php artisan orders:race-test --buyers=50 --stock=10 --workers=4
rm "$env_file"
```
`-v` wskazuje checkout, którego kod mierzysz: dla gałęzi porównawczej
podaj `apps/catalog` z jej worktree zamiast `$PWD/apps/catalog`, bez
przełączania głównego checkoutu.

**Uwaga, maszyna współdzielona:** 50 procesów PHP w jednym przebiegu mocno
dociąża VM Dockera (15,6 GB, obok igrit i startera). W pomiarach
2026-10-17 węzły ES restartowały się w trakcie (es03: 20 restartów, [#025](#025)),
a po nich search-consumer przestał indeksować ([#050](#050)). Po
race-teście sprawdź klaster (`docker compose ps es01 es02 es03` albo
`make es-health`) i indeks. Czasy p50/p95 z takiego
przebiegu nie nadają się do porównań.

**Czego się nauczyłem**
Narzędzie obciążeniowe nie powinno biec w kontenerze, który mierzy. Cichy
koniec bez komunikatu to w Dockerze najpierw pytanie o kod wyjścia, a 137
to pamięć.

<a id="050"></a>
## 050 — search-consumer po restarcie węzłów ES: „No alive nodes” przy klastrze `green`, indeks stoi

> Zauważone przy pomiarach zamówień 2026-10-17, ale to **problem
> istniejący wcześniej, w search**, nie spowodowany przez Ordering:
> zamówienia tylko generują więcej zdarzeń ofert, więc szybciej go widać.

**Objaw**
Klaster ES wrócił do `green`, a zmiany ofert (rezerwacja, zwolnienie,
zmiana stanu) nie trafiają do `/search`. W logu search-consumera
„No alive nodes”, a wiadomości lądują w failure transport (w tym
incydencie 76).

**Diagnoza**
Wszystkie komendy z katalogu głównego checkoutu (tam leży `.env`,
z którego `docker compose` i `make` biorą nazwy i porty):
```bash
set -a; . ./.env; set +a
curl -sS -u "elastic:$ELASTIC_PASSWORD" "http://localhost:$ES_PORT/_cluster/health?pretty"   # "status": "green", 3 węzły (to samo: make es-health)
docker compose ps es01 es02 es03                                # czas „Up” — świeże restarty węzłów
docker compose logs --tail=100 search-consumer                  # „No alive nodes” mimo green
docker compose exec search-consumer php bin/console messenger:failed:show   # ile i jakie
```

**Przyczyna**
Najpewniej (hipoteza z objawu, nie sprawdzona w kodzie klienta): długo
żyjący proces konsumenta trzyma klienta ES, który po restartach węzłów
oznaczył wszystkie jako martwe i nie próbuje ich ponownie, choć klaster
jest już zdrowy. Za tym przemawia, że sam restart konsumenta naprawia. Messenger traktuje to jak zwykły błąd obsługi: po
ponowieniach wiadomość idzie do transportu `failed`, a indeks zostaje
nieaktualny bez żadnego alarmu.

**Naprawa**
Z katalogu głównego checkoutu:
1. `docker restart marketplace-search-consumer-1` (nowy proces, nowy klient ES).
2. Odzyskaj odrzucone zdarzenia:
   `docker compose exec search-consumer php bin/console messenger:failed:retry --force`,
   **albo** przelicz cały indeks: `make reproject` (1500 dokumentów w 76 s
   na tym stacku). `reproject` jest pewniejsze, gdy nie wiadomo, ile zdarzeń
   przepadło.
3. Sprawdź jedną ofertę, której stan się zmieniał, w `/api/search`.

**Czego się nauczyłem**
„Klaster zdrowy” i „konsument zdrowy” to dwie różne rzeczy: klient ES
w długim procesie ma własny, nieaktualny obraz klastra. Do zrobienia
w search (poza zakresem zamówień): restart konsumenta przy tym błędzie
zamiast odrzucania wiadomości albo alarm na rosnący failure transport.

**Dopisane w ETAPIE 11:** alarm już jest. Alert `SearchFailedMessages`
(`search_messenger_failed_messages > 0` przez 1 min, critical; metrykę
liczy kontener `search-metrics` przez `MessageCountAwareInterface`
failure transportu) prowadzi tutaj. Na żywo 2026-10-18 złapał ten
incydent sam: restart es01 09:00:21, `firing` 09:01:47, mail 09:02:57.
Zostaje do zrobienia restart konsumenta przy „No alive nodes”. Gdy
`search-metrics` nie umie odczytać transportu, wystawia
`search_metrics_scrape_error 1` zamiast tej metryki, a na to alertu jeszcze
nie ma (zadanie domowe w przewodniku ETAPU 11).

<a id="051"></a>
## 051 — Filebeat działa, a do `logs-app-*` nic nie dociera (sterownik logów `local`)

> ETAP 11, Task 8 i 12 (2026-10-17/18). Przewodnik:
> [`blog/etap-11-observability.md`](blog/etap-11-observability.md).

**Objaw**
`filebeat` jest `Up`, w `docker compose logs filebeat` nie ma błędów, data
streamy `logs-app-catalog` / `logs-app-search` nie powstają albo nie rosną.
`docker logs marketplace-catalog-app-1` pokazuje linie JSON, więc aplikacja
loguje.

**Diagnoza**
Wszystkie komendy z katalogu głównego checkoutu (tam leży `.env`):
```bash
docker inspect marketplace-catalog-app-1 --format '{{.HostConfig.LogConfig.Type}}'   # local = Filebeat nic nie przeczyta
docker compose logs --tail=100 filebeat | grep -iE 'error|harvester|filestream'
set -a; . ./.env; set +a
curl -sS -u "elastic:$ELASTIC_PASSWORD" "http://localhost:$ES_PORT/_cat/indices/.ds-logs-app-*?v&h=index,docs.count"
# czy w ES są dokumenty z prawdziwego serwisu (nie z sondy):
curl -sS -u "elastic:$ELASTIC_PASSWORD" "http://localhost:$ES_PORT/logs-app-*/_search?size=0" \
  -H 'Content-Type: application/json' \
  -d '{"aggs":{"s":{"terms":{"field":"service.name"}}}}'
```

**Przyczyna**
Dwie warstwy naraz:
1. Docker Desktop ma domyślny sterownik logów `local` (format binarny,
   plik `local-logs/container.log`). Filebeat czyta tylko `<id>-json.log`
   ze sterownika `json-file`, więc nie miał czego czytać, a błędu nie było:
   autodiscover po prostu nie znajdował plików.
2. Sterownik ustawia się przy **tworzeniu** kontenera. Dopisanie
   `logging:` w `compose.yaml` nic nie zmienia dla kontenera, który już
   działa, dopóki się go nie odtworzy.

Przy okazji tego samego zadania wyszły jeszcze trzy różnice między
dokumentacją a Filebeatem 9.5.1: wejście `type: container` jest wycofane
(zastępuje je `filestream` z parserem `container`), warunki autodiscover
działają na etykietach **zagnieżdżonych**
(`docker.container.labels.com.docker.compose.service`), a etykieta
`project` bywa liściem albo obiektem z kluczem `value` (stąd `or` z dwoma
wariantami w `infra/obs/filebeat/filebeat.yml`).

**Naprawa**
1. `compose.yaml`: kotwica `x-json-logging` (`json-file`, 10m × 3) i
   `logging: *json-logging` w sześciu serwisach, których logi czyta Filebeat.
2. Odtworzenie kontenerów (z katalogu głównego checkoutu):
   `docker compose --profile apps up -d --force-recreate --no-deps catalog-app outbox-publisher catalog-worker catalog-scheduler search-consumer`
   i `docker compose --profile obs up -d --force-recreate --no-deps search-metrics`.
3. Sprawdzenie: pierwsze polecenie z diagnozy daje `json-file`, a agregacja
   po `service.name` pokazuje te serwisy.

**Czego się nauczyłem**
Shipper logów, który „działa i milczy”, najczęściej nie widzi plików. Zanim
zaczniesz debugować pipeline, sprawdź najniższą warstwę: gdzie i w jakim
formacie leży log na dysku. Ustawienia z etapu tworzenia kontenera
(sterownik logów, sieć, wolumeny) wymagają odtworzenia, nie restartu.

<a id="052"></a>
## 052 — Alert `SearchConsumerDown`: nikt nie odpytuje kolejki `search.product.sync`

> Wpis dla alertu (link `runbook` w regule). Historia: pierwsza wersja
> alertu nigdy by się nie zamknęła; patrz „Przyczyna”.

**Objaw**
Mail `[FIRING:1] SearchConsumerDown (critical)` w mailpicie. Zmiany ofert
nie trafiają do `/search`, rośnie `messages_ready` w `search.product.sync`.

**Diagnoza**
Z katalogu głównego checkoutu:
```bash
docker compose ps search-consumer                      # Up? healthy? świeży restart?
docker compose logs --tail=100 search-consumer         # wyjątek, OOM, „No alive nodes” (#050)
make mq-status                                         # messages_ready rośnie; consumers = 0 to NORMALNE (niżej)
curl -sG "http://localhost:${PROMETHEUS_PORT:-19090}/api/v1/query" --data-urlencode \
  'query=sum by (queue) (rate({__name__=~"rabbitmq_detailed_queue_get_(empty|ack)_total",queue="search.product.sync"}[1m]))'
```
Działający konsument daje tu ok. 0,4–1 zapytania na sekundę. Wynik pusty
albo 0 przy żywym kontenerze: zajrzyj, czy kolejka w ogóle istnieje
(`make mq-status`).

**Przyczyna**
Alert patrzy na **tempo odpytywania** kolejki, a nie na liczbę konsumentów.
`symfony/amqp-messenger` pobiera wiadomości przez `basic.get` (pętla
odpytywania), nie przez `basic.consume`, więc RabbitMQ nigdy nie rejestruje
konsumenta: `rabbitmq_detailed_queue_consumers` i kolumna `consumers`
w `make mq-status` są zawsze 0. Pierwsza wersja alertu (`consumers == 0`)
odpalała więc na stałe i nie zamknęłaby się po `docker start`. Teraz:
suma `rate()` liczników `get_empty` (pusta kolejka) i `get_ack` (pobrana
wiadomość) równa 0 przez 1 min, po oknie 1 min, czyli ok. 2 min od awarii.

Alert odpala w trzech sytuacjach:
- konsument nie żyje albo wisi (najczęstsze);
- **kolejka `search.product.sync` zniknęła**, a RabbitMQ działa: brak serii
  traktujemy jak 0 (`or vector(0)`), bo zniknięcie kolejki to też awaria;
- **fałszywie**: jedna wiadomość obsługiwana dłużej niż ok. 2 min (konsument
  w trakcie pracy nie pyta kolejki). Przy obecnym kodzie (timeout klienta
  katalogu 3 s) to mało prawdopodobne, ale przy dużych bulkach możliwe.

Gdy RabbitMQ w ogóle nie jest scrapowany, ten alert milczy
(`and on() up{job="rabbitmq-queues"} == 1`), a odpala `TargetDown` (#062).

**Naprawa**
Z katalogu głównego checkoutu:
1. Kontener stoi: `docker compose --profile apps up -d --no-deps search-consumer`
   (albo `docker start marketplace-search-consumer-1`). Na żywo (2026-10-18)
   alert zamknął się 21 s po starcie, mail RESOLVED po 43 s.
2. Kontener żyje, ale nie odpytuje: `docker restart marketplace-search-consumer-1`;
   jeśli w logu jest „No alive nodes”, postępuj jak w [#050](#050).
3. Brak kolejki: topologia jest w `infra/rabbitmq/definitions.template.json`
   (entrypoint kontenera renderuje z niej `definitions.json`, a RabbitMQ
   wczytuje go przy starcie: `load_definitions`), więc
   `docker compose restart rabbitmq` odtwarza kolejkę; potem
   `make mq-status` musi ją pokazać.

**Czego się nauczyłem**
„Konsument podłączony” to cecha protokołu, nie aplikacji. Zanim zbudujesz
alert na metryce, sprawdź na żywym systemie, czy ta metryka w ogóle się
zmienia, gdy wyłączysz to, co ma wykrywać. Sygnał życia worka odpytującego
to tempo odpytywania, nie liczba połączeń.

<a id="053"></a>
## 053 — Metryki osiągalne z zewnątrz przez sfałszowany `Host` (port 80, `/index.php/prometheus`)

> Znalezione w przeglądzie bezpieczeństwa Taska 6 (ETAP 11), przed
> uruchomieniem na żywo.

**Objaw**
Pierwsza wersja wystawiała `/prometheus` (metryki Laravela) w bloku
`http://catalog-app` Caddy. Ten blok miał być „tylko dla sieci dockerowej”,
ale odpowiadał każdemu, kto podał nagłówek `Host: catalog-app` na
**opublikowanym** porcie 80. Po przeniesieniu metryk drugi przegląd znalazł
obejście publicznego 404: `https://catalog.localhost/index.php/prometheus`.

**Diagnoza**
Z katalogu głównego checkoutu (porty z `.env`); każde z tych poleceń ma
dawać **404**:
```bash
set -a; . ./.env; set +a
curl -sk -o /dev/null -w '%{http_code}\n' "https://catalog.localhost:$CATALOG_HTTPS_PORT/prometheus"
curl -sk -o /dev/null -w '%{http_code}\n' "https://catalog.localhost:$CATALOG_HTTPS_PORT/index.php/prometheus"
curl -s  -o /dev/null -w '%{http_code}\n' -H 'Host: catalog-app' "http://127.0.0.1:$CATALOG_HTTP_PORT/prometheus"
curl -s  -o /dev/null -w '%{http_code}\n' -H 'Host: catalog-app' "http://127.0.0.1:$CATALOG_HTTP_PORT/metrics"
# a z sieci dockerowej metryki są:
docker compose exec prometheus wget -qO- http://catalog-app:2020/prometheus | head -3
```

**Przyczyna**
1. Caddy wybiera blok po nagłówku `Host`, a nagłówek ustawia klient. Blok
   `http://catalog-app` słucha na porcie 80, który compose publikuje
   (`127.0.0.1:${CATALOG_HTTP_PORT}` w dev, port serwera w prod). „Nazwa
   wewnętrzna” nie jest granicą bezpieczeństwa.
2. `php_server` dzieli ścieżkę na `.php`: `/index.php/prometheus` to
   skrypt `index.php` z `PATH_INFO=/prometheus`, a Laravel routuje po
   `PATH_INFO`. Matcher `path /prometheus*` tego nie łapał.

**Naprawa**
`infra/caddy/Caddyfile`: osobny blok `http://:2020` (`/metrics` → handler
Caddy, `/prometheus` → `php_server`, reszta 404). Portu 2020 nie ma w żadnym
`ports:` w `compose*.yaml`, więc dostęp ma tylko sieć `marketplace`.
Publiczny blok odpowiada 404 na `/prometheus /prometheus/* /index.php/prometheus /index.php/prometheus/*`.
Prometheus scrapuje `catalog-app:2020/prometheus` i `catalog-app:2020/metrics`.
Zmiana Caddyfile wymaga przebudowy obrazu (`docker compose --profile apps build catalog-app`, patrz [#061](#061)).

**Uwaga, poza zakresem ETAPU 11:** ten sam mechanizm dotyczy `/api/internal/*`
i `/webhooks/*` w bloku `http://catalog-app` (osiągalne z zewnątrz przez
`Host: catalog-app`). Webhook ma podpis HMAC; wewnętrzne API do przejrzenia
osobno.

**Czego się nauczyłem**
Granicą dostępu jest port, który nie wychodzi z sieci, nie nazwa hosta.
Przy każdej trasie „tylko wewnętrznej” sprawdź dwa obejścia: inny `Host` na
tym samym porcie i inną ścieżkę, która trafia w ten sam handler
(`/index.php/...`, podwójny ukośnik, kodowanie znaków).

<a id="054"></a>
## 054 — `CatalogHttpErrorRate` nie widzi ruchu przeglądarki (etykieta `host` z portem)

> ETAP 11, Task 12, żywy stack 2026-10-18.

**Objaw**
Panel „HTTP catalog: req/s per kod” w Grafanie pokazywał kilka zapytań na
minutę mimo klikania po sklepie; reguła `CatalogHttpErrorRate` z
`host="catalog.localhost"` nie widziałaby 5xx z przeglądarki.

**Diagnoza**
Z katalogu głównego checkoutu:
```bash
docker compose exec prometheus wget -qO- http://catalog-app:2020/metrics \
  | grep '^caddy_http_request_duration_seconds_count' | grep -o 'host="[^"]*"' | sort | uniq -c
```
Wynik na żywo: `host="catalog.localhost:8443"` (przeglądarka i curl na
porcie hosta), `host="catalog.localhost"` (tylko healthcheck z kontenera),
`host="catalog-app"` (ruch wewnętrzny), `host="_other"` (scrape na :2020).

**Przyczyna**
`metrics { per_host }` bierze wartość z nagłówka `Host`, a przeglądarka
wysyła go z portem, gdy port nie jest domyślny (`:8443`). Plan zakładał
nazwę bez portu i nikt tego nie sprawdził przed żywym stackiem.

**Naprawa**
Reguła i panele: `host=~"catalog\\.localhost(:[0-9]+)?"`; test promtool
„host z portem” w `marketplace.test.yml`. Panele HTTP w Grafanie pomijają
`host="_other"` (scrape Prometheusa zawyżał ruch i p95). Na VPS host to
prawdziwa domena: regex trzeba tam sparametryzować (poza zakresem).

**Czego się nauczyłem**
Wartości etykiet poznaje się z `/metrics` żywego systemu, nie z
dokumentacji. Test jednostkowy reguły sprawdza logikę na danych, które sam
wymyśliłeś; tylko żywy endpoint mówi, jakie dane naprawdę przyjdą.

<a id="055"></a>
## 055 — Grafana 13 przy `mem_limit: 256m` nie wstaje, mieli CPU i dysk

> ETAP 11, Task 11 (2026-10-17).

**Objaw**
`docker compose ps grafana` pokazuje `Up`, ale `http://localhost:$GRAFANA_PORT`
nie odpowiada. `docker stats` pokazuje Grafanę przy limicie pamięci,
CPU ~600 % i ogromne `BLOCK I/O`.

**Diagnoza**
Z katalogu głównego checkoutu:
```bash
docker stats --no-stream marketplace-grafana-1
docker inspect marketplace-grafana-1 --format 'oom={{.State.OOMKilled}} restarts={{.RestartCount}}'
docker compose logs --tail=50 grafana        # migracje bazy przy starcie, bez komunikatu o gotowości
```

**Przyczyna**
Grafana 13 (Go, migracje SQLite przy pierwszym starcie, wtyczki) potrzebuje
ok. 300–350 MiB. Przy 256m jądro nie zabija procesu, tylko ciągle wyrzuca
z pamięci jego strony (kod i pliki bazy) i czyta je z dysku od nowa:
kontener żyje, ale nic nie robi (thrashing).

**Naprawa**
`compose.yaml`: `grafana` → `mem_limit: 512m` (z komentarzem), potem
`docker compose --profile obs up -d --no-deps grafana`. Zmierzone: ~350 MiB
po starcie, ~180 MiB po godzinie (POMIARY, sekcja 12).

**Czego się nauczyłem**
Za niski limit pamięci nie zawsze daje OOM (kod 137). Częściej daje proces,
który żyje i nie odpowiada, z wysokim I/O. `docker stats` z CPU i
`BLOCK I/O` mówi więcej niż sam status kontenera.

<a id="056"></a>
## 056 — Metricbeat: ~66 MB na godzinę w `.monitoring-es-*` i obcy indeks `metricbeat-9.5.1`

> ETAP 11, Task 12, żywy stack 2026-10-18.

**Objaw**
`.monitoring-es-8-mb-*` rośnie o ~68 tys. dokumentów (~66 MB) na godzinę.
W `_cat/indices` pojawia się `metricbeat-9.5.1` (575 dokumentów), którego
nikt nie zakładał.

**Diagnoza**
Z katalogu głównego checkoutu:
```bash
set -a; . ./.env; set +a
E="http://localhost:$ES_PORT"; A="elastic:$ELASTIC_PASSWORD"
curl -sS -u "$A" "$E/_cat/indices/.monitoring-es-*,metricbeat-*?v&h=index,docs.count,store.size"
# co zajmuje miejsce:
curl -sS -u "$A" "$E/.monitoring-es-*/_search?size=0" -H 'Content-Type: application/json' \
  -d '{"aggs":{"d":{"terms":{"field":"event.dataset"}}}}'
# co jest w obcym indeksie:
curl -sS -u "$A" "$E/metricbeat-*/_search?size=1&filter_path=hits.hits._source.error" 
```

**Przyczyna**
1. `period: 10s` z `scope: cluster` przy ~100 indeksach i 3 węzłach: 60 %
   to metricset `shard` (dokument na shard co 10 s). Wbudowana polityka
   `.monitoring-8-ilm-policy` trzyma dane 6 dni, czyli kilka GB.
2. Gdy ES nie odpowiada (restart węzła), metricset zwraca zdarzenie z samym
   `error.message`, bez `@metadata.index` trybu xpack. Takie zdarzenie
   trafia do domyślnego indeksu `metricbeat-<wersja>`, bez szablonu i ILM.

**Naprawa**
`infra/obs/metricbeat/metricbeat.yml`: `period: 30s` i procesor
`drop_event` dla zdarzeń z `error.message`; potem
`docker compose --profile obs up -d --force-recreate --no-deps metricbeat`.
Po zmianie: ~10 tys. dokumentów na godzinę. Obcy indeks **nie** został
usunięty automatycznie (usuwanie danych to Twoja decyzja); gdy go nie
potrzebujesz: `curl -sS -u "$A" -X DELETE "$E/metricbeat-9.5.1"`.

Skutek uboczny `drop_event`: błędy połączenia Metricbeata nie trafiają do
ES, widać je tylko w `docker compose logs metricbeat`. Gdy w Stack
Monitoring brakuje danych, zacznij od tego logu.

**Czego się nauczyłem**
Każdy shipper ma domyślną częstotliwość dobraną pod produkcję, nie pod
laptopa. Policz wolumen po pierwszej godzinie i porównaj z retencją. Ścieżka
błędu też produkuje dane i może je wysłać gdzie indziej niż ścieżka
szczęśliwa.

<a id="057"></a>
## 057 — Metricbeat bez `user: root`: `meta.json: permission denied`

> ETAP 11, utwardzenie po Tasku 12 (2026-10-18).

**Objaw**
Po usunięciu `user: root` z serwisu `metricbeat` kontener restartuje się
w kółko, w logu `... /usr/share/metricbeat/data/meta.json: permission denied`.

**Diagnoza**
Z katalogu głównego checkoutu:
```bash
docker compose logs --tail=20 metricbeat
docker run --rm -v marketplace_metricbeat-data:/d alpine ls -ln /d   # właściciel 0 (root), a beat działa jako 1000
```

**Przyczyna**
Wolumen `metricbeat-data` założył wcześniej Metricbeat działający jako root,
więc pliki należą do uid 0. Obraz beata działa domyślnie jako uid 1000 i nie
może ich nadpisać. Nowy, pusty wolumen tego problemu nie ma.

**Naprawa**
Jednorazowo (dotyczy też VPS przy aktualizacji), z katalogu głównego checkoutu:
```bash
set -a; . ./.env; set +a
docker run --rm --user root --entrypoint chown -v marketplace_metricbeat-data:/d \
  docker.elastic.co/beats/metricbeat:$ES_VERSION -R 1000:1000 /d
docker compose --profile obs up -d --no-deps metricbeat
```
Sprawdzone: 70 s logu bez błędów uprawnień, `.monitoring-es-*` rośnie,
proces działa jako uid 1000.

**Czego się nauczyłem**
Zmiana użytkownika kontenera to migracja danych w wolumenie. Wolumen
pamięta, kto go zapisał; obraz o tym nie wie.

<a id="058"></a>
## 058 — Węzły ES restartują się co kilka minut przy włączonym profilu `obs`

> ETAP 11, Task 12, żywy stack 2026-10-18. **Nie rozwiązane** — wymaga
> decyzji o zasobach (niżej).

**Objaw**
W czasie weryfikacji ETAPU 11 węzły restartowały się co 5–15 min
(`RestartCount` es01 31 → 37, es02 27 → 30, es03 22 → 26 w ~1 h). Skutki
w innych miejscach: search-consumer z „No alive nodes” ([#050](#050)) i
`SearchFailedMessages`, luki w scrape Prometheusa (~14 min dla **wszystkich**
celów, `scrape_duration` do 141 s), migający `DeadLetterNotEmpty`,
`TargetDown`, `OrderingSweepStale`, opóźnione o 12 min usunięcie w ILM demo,
`docker exec` wiszący ponad minutę.

**Diagnoza**
Z katalogu głównego checkoutu:
```bash
for n in es01 es02 es03; do
  docker inspect marketplace-$n --format "$n restarts={{.RestartCount}} oom={{.State.OOMKilled}}"
done
# cgroup v2 węzła: bieżące zużycie, ile razy uderzył w limit, ile razy OOM killer
docker exec marketplace-es01 sh -c 'cat /sys/fs/cgroup/memory.current; grep -E "^(max|oom_kill) " /sys/fs/cgroup/memory.events'
docker exec marketplace-es01 sh -c 'grep -E "^(anon|file) " /sys/fs/cgroup/memory.stat'
docker stats --no-stream --format '{{.Name}} {{.MemUsage}}' | sort -k2 -h   # kto zajmuje VM (także inne projekty)
docker info --format '{{.MemTotal}}'
```
Na żywo: `oom_kill 1` w `memory.events` es01 przed dwoma restartami
(09:37:45, 09:52:25), `anon` 1,5–1,7 GB przy limicie 2 GB, `file` ~0; VM
Dockera ~15,4 z 15,6 GiB, z czego ~6,7 GiB to inne projekty. Zatrzymanie
Metricbeata i Filebeata (09:43) **nie** zatrzymało restartów (es02/es03
09:49, es01 09:52), więc profil `obs` nie jest jedyną przyczyną.

**Przyczyna**
Dwa OOM-y naraz, ten sam mechanizm co w [#025](#025):
- **OOM limitu kontenera:** `ES_HEAP=1g` przy `ES_MEM_LIMIT=2g` zostawia
  ~1 GB na pamięć poza stertą (metaspace, bufory sieciowe Netty, wątki,
  Lucene). Pod obciążeniem `anon` dochodzi do limitu i cgroup zabija JVM
  (`oom_kill` w `memory.events`).
- **pełna VM:** gdy cała VM nie ma wolnej pamięci, jądro zabija proces bez
  flagi `OOMKilled` i bez logu zamknięcia, a dodatkowo wszystko zwalnia
  (stąd luki w scrape i wiszący `docker exec`).

Profil `obs` dokłada ~450–600 MiB (POMIARY, sekcja 12) i Metricbeat
odpytuje klaster, ale restarty zdarzały się już 2026-10-17, bez `obs`
(#049, #050).

**Naprawa**
Do decyzji (żadna nie jest zrobiona; każda ma koszt):
1. Więcej miejsca poza stertą: `ES_MEM_LIMIT=2560m` przy `ES_HEAP=1g`
   w `.env` (+1,5 GB dla 3 węzłów), potem odtworzenie węzłów
   `docker compose --profile cluster up -d es01 es02 es03` (po jednym,
   czekając na `green`, [#021](#021)).
2. Jeden węzeł lokalnie (`make up` zamiast `make up-cluster`): ~4 GB mniej,
   ale bez replik i bez nauki o shardach.
3. Mniej równoległych projektów na maszynie albo więcej pamięci dla VM
   Dockera.
4. Doraźnie, w trakcie incydentu: zatrzymaj Metricbeat
   (`docker compose stop metricbeat`), żeby nie dokładać zapytań do
   odradzającego się klastra; po `green` zrestartuj search-consumer ([#050](#050)).

**Czego się nauczyłem**
Observability na tej samej maszynie co system obserwowany cierpi razem
z nim: gdy VM jest pełna, gasną także metryki i alerty, które miały o tym
powiedzieć. Luka w scrape **wszystkich** celów naraz, łącznie z samym
Prometheusem, to sygnał problemu z hostem, nie z aplikacją.

<a id="059"></a>
## 059 — Ślad `trace.id` w Kibanie niepełny (brak logu kontrolera i żądania projekcji)

> ETAP 11, Task 12, żywy stack 2026-10-18.

**Objaw**
Filtr `trace.id: <X-Trace-Id>` po zmianie ceny pokazywał 7 dokumentów:
access log z `catalog-app`, publikację i kroki search-consumera. Brakowało
logu aplikacji z kontrolera i wpisu catalog-app dla żądania
`/api/internal/products/…/projection`, choć search wysyłał `traceparent`.

**Diagnoza**
Z katalogu głównego checkoutu (`<ID>` z nagłówka `X-Trace-Id` odpowiedzi):
```bash
set -a; . ./.env; set +a
curl -sS -u "elastic:$ELASTIC_PASSWORD" "http://localhost:$ES_PORT/logs-app-*/_search?size=50&sort=@timestamp:asc&filter_path=hits.hits._source.service.name,hits.hits._source.event.dataset,hits.hits._source.message,hits.hits._source.url.original" \
  -H 'Content-Type: application/json' -d '{"query":{"term":{"trace.id":"<ID>"}}}'
docker compose logs --tail=20 catalog-app | grep projection    # czy Caddy w ogóle loguje to żądanie
```

**Przyczyna**
1. Blok `http://catalog-app` w Caddyfile nie miał dyrektywy `log`, więc
   żądania wewnętrzne nie dawały access logu (blok publiczny miał).
2. `updatePrice` w kontrolerze sprzedawcy nic nie logował; spec zakładał
   „log kontrolera”, ale żaden test tego nie wymuszał.

**Naprawa**
`log { output stdout; format json }` w bloku `http://catalog-app` (obraz
przebudowany, [#061](#061)) i `Log::info('Zmiana ceny oferty', [...])`
z testem Pest (`Log::spy()`). Po zmianie: 9 dokumentów w 1,1 s, od logu
kontrolera do „Zaindeksowano produkt.”.

**Czego się nauczyłem**
Korelacja po `trace_id` jest tak dobra, jak najsłabsze ogniwo: każdy krok,
który nie loguje, jest dziurą w śladzie, której nie widać, dopóki nie
prześledzisz jednego żądania od początku do końca.

<a id="060"></a>
## 060 — Logi Caddy w ES jako surowy JSON, bez `log.level`

> ETAP 11, Task 12, żywy stack 2026-10-18.

**Objaw**
W Discover dokumenty `catalog.catalog-app` z `message` równym całej linii
JSON (np. „autosaved config”, „Caddy serving PHP app”), bez `log.level`,
z `event.original`.

**Diagnoza**
Z katalogu głównego checkoutu:
```bash
docker compose logs --no-log-prefix --tail=200 catalog-app | grep '^{' | grep -v '"logger"' | head -3
```
Linie bez pola `logger` to logi z głównego loggera Caddy.

**Przyczyna**
Gałąź Caddy w pipeline `logs-app` wymagała `logger instanceof String`.
Główny logger Caddy go nie wypisuje (ma tylko `level`, `ts`, `msg`), więc
linia wpadała do gałęzi „nieznany JSON” (cała treść do `app.context`
i `event.original`).

**Naprawa**
`infra/obs/elasticsearch/pipeline-logs-app.json`: procesor `set`
`_j.logger = caddy`, gdy jest `ts` i `msg`, a brak `level_name`
i `logger`. Próbka `samples/7-caddy-runtime-no-logger.json` i asercje
`_simulate`; pipeline wgrywa `docker compose --profile obs run --rm --no-deps obs-setup`.

**Czego się nauczyłem**
Próbki do `_simulate` pisane z dokumentacji pokazują tylko formaty, które
znasz. Raz po uruchomieniu zrób agregację po `tags` i `event.original`
w prawdziwym indeksie: to lista formatów, o których nie wiedziałeś.

<a id="061"></a>
## 061 — Build `catalog-app` z worktree podmienia Caddyfile w głównym checkoucie

> ETAP 11, Task 12 (2026-10-18). Dotyczy pracy w worktree.

**Objaw**
Po weryfikacji gałęzi w worktree kontenery uruchomione z głównego
checkoutu (`main`) zachowują się jak gałąź: Caddy ma blok `:2020`, którego
`main` nie zna.

**Diagnoza**
Z katalogu głównego checkoutu:
```bash
docker compose exec catalog-app grep -c 2020 /etc/frankenphp/Caddyfile   # >0 = Caddyfile z gałęzi
docker image ls marketplace-catalog-app
```

**Przyczyna**
Caddyfile jest kopiowany do obrazu przy buildzie, a obraz ma jedną nazwę
(`marketplace-catalog-app`) dla wszystkich checkoutów tego projektu
compose. Build z worktree nadpisuje obraz, z którego startuje też `main`.
Kod PHP się nie miesza (bind mount `./apps/catalog`), konfiguracja Caddy tak.

**Naprawa**
Po skończonej weryfikacji, z katalogu głównego checkoutu:
`docker compose --profile apps build catalog-app` i
`docker compose --profile apps up -d --no-deps catalog-app outbox-publisher catalog-worker catalog-scheduler search-consumer`.
Sprawdzenie: polecenie z diagnozy daje `0`.

**Czego się nauczyłem**
Worktree izoluje pliki, nie obrazy, wolumeny ani bazę. Wszystko, co
Docker nazywa po projekcie compose, jest wspólne dla wszystkich checkoutów.

<a id="062"></a>
## 062 — Alert `TargetDown`: Prometheus nie zbiera metryk z celu

**Objaw**
Mail `[FIRING:1] TargetDown (critical)` z etykietami `job` i `instance`.
Wszystkie inne alerty tego celu milczą (nie ma danych, więc nie ma progu
do przekroczenia).

**Diagnoza**
Z katalogu głównego checkoutu; zacznij od Prometheusa, który powie, *jak*
scrape się nie udał:
```bash
curl -s "http://localhost:${PROMETHEUS_PORT:-19090}/api/v1/targets?state=active" \
  | python3 -c 'import json,sys; [print(t["labels"]["job"], t["health"], t["lastError"]) for t in json.load(sys.stdin)["data"]["activeTargets"]]'
docker compose ps catalog-app search-metrics rabbitmq   # żyje? healthy?
docker compose exec prometheus wget -qO- http://catalog-app:2020/prometheus | head -3   # ten sam adres co scrape
```
Typowe `lastError`: `connection refused` / `no such host` (kontener nie
żyje albo nie ma go w sieci), `context deadline exceeded` (cel odpowiada
dłużej niż 10 s), `server returned HTTP status 404` (zła ścieżka albo
obraz bez bloku `:2020`, [#061](#061)).

**Przyczyna**
Najczęściej: kontener zatrzymany, kontener z kodem bez endpointu metryk
(np. `main` przed mergem ETAPU 11 nie ma `/prometheus` ani `:2020`;
dlatego targety `caddy`, `catalog`, `search` są DOWN, gdy profil `obs`
z gałęzi działa obok aplikacji z `main`), `search-metrics` nie uruchomiony
(jest tylko w profilu `obs`), albo przeciążony host ([#058](#058): gdy
DOWN są **wszystkie** cele naraz, łącznie z `prometheus`).

**Naprawa**
1. Uruchom brakujący kontener (`docker compose --profile apps up -d --no-deps catalog-app`,
   `docker compose --profile obs up -d --no-deps search-metrics`).
2. Endpoint 404: przebuduj obraz z właściwego checkoutu ([#061](#061)).
3. Wszystkie cele naraz: problem z hostem, nie z celem; patrz [#058](#058).

**Czego się nauczyłem**
`up == 0` to alert o alertach: bez niego martwy cel wygląda jak zdrowy
(brak danych to brak alarmu). `lastError` w `/api/v1/targets` mówi więcej
niż status kontenera.

<a id="063"></a>
## 063 — Alert `DeadLetterNotEmpty`: wiadomości w kolejce `.dlq`

**Objaw**
Mail `[FIRING:1] DeadLetterNotEmpty (warning)`, etykieta `queue` wskazuje
kolejkę `*.dlq` (np. `search.product.sync.dlq`). Alert ma `for: 5m`: odpala
dopiero po pięciu minutach niepustej DLQ.

**Diagnoza**
Z katalogu głównego checkoutu:
```bash
make mq-status                                     # która DLQ i ile
make mq-get q=search.product.sync.dlq              # podgląd BEZ usuwania (ack_requeue_true, #027)
docker compose logs --since=1h search-consumer | grep -iE 'error|reject|dlq'
```
Uwaga: zwykłe `rabbitmqadmin get` bez `--ack-mode ack_requeue_true`
**kasuje** wiadomości ([#027](#027)). Używaj `make mq-get`.

**Przyczyna**
Wiadomość, której konsument nie przyjął (zły JSON, nieznany typ, odrzucenie
po stronie brokera), ląduje w DLQ przez dead-letter exchange. To nie to samo
co failure transport Messengera (błąd obsługi po ponowieniach,
`SearchFailedMessages`, [#050](#050)): DLQ to odrzucenie na poziomie
RabbitMQ. Historia progu: przy `for: 0m` alert migał FIRING/RESOLVED przy
każdej luce w scrape ([#058](#058)), stąd `for: 5m`.

**Naprawa**
Każda wiadomość to ręczna decyzja:
1. Przeczytaj ją (`make mq-get`), znajdź przyczynę w logu konsumenta.
2. Napraw przyczynę (kod, kontrakt zdarzenia), potem ponów: przenieś
   wiadomości z DLQ do kolejki głównej (shovel w panelu RabbitMQ,
   „Move messages”), **albo** przelicz indeks `make reproject`, jeśli
   dotyczą ofert, a wiadomości same w sobie są niepotrzebne.
3. Wiadomość bezwartościowa (np. test z `make mq-publish`): wyczyść kolejkę
   w panelu RabbitMQ (Purge). To usuwa dane, zrób to świadomie.

**Czego się nauczyłem**
DLQ bez alertu to kosz, którego nikt nie wynosi. Ale alert na DLQ musi
przeczekać luki w danych: pojedyncza próbka po przerwie w scrape to nie
nowe zdarzenie.

<a id="064"></a>
## 064 — Alert `SearchSyncBacklog`: ponad 1000 wiadomości w `search.product.sync`

**Objaw**
Mail `[FIRING:1] SearchSyncBacklog (warning)`. W `/search` stare ceny
i stany; `messages_ready` w `search.product.sync` powyżej 1000 przez 5 min.

**Diagnoza**
Z katalogu głównego checkoutu:
```bash
make mq-status                                         # messages_ready, messages_unacknowledged
curl -sG "http://localhost:${PROMETHEUS_PORT:-19090}/api/v1/query" --data-urlencode \
  'query=sum(rate(rabbitmq_detailed_queue_get_ack_total{queue="search.product.sync"}[5m]))'   # ile/s konsument zdejmuje
docker compose logs --tail=100 search-consumer         # wolne odpowiedzi projekcji? błędy ES?
make es-health
```
Jeśli tempo zdejmowania jest 0, to nie zaległość, tylko martwy konsument:
[#052](#052). Licznik kolejki ustala się z opóźnieniem ~6 s ([#006](#006)),
więc patrz na trend, nie na jeden odczyt.

**Przyczyna**
Konsument żyje, ale nie nadąża: wolny ES (restarty węzłów, [#058](#058)),
wolne wewnętrzne API catalog (timeout 3 s na projekcję), albo nagły zalew
zdarzeń (seed, masowa zmiana ofert). Próg: seed 1500 zdarzeń schodzi
w niecałą minutę, więc 5 min powyżej 1000 to nie zwykły szczyt.

**Naprawa**
1. Przyczyna po stronie ES albo catalog: napraw ją najpierw, zaległość
   zejdzie sama.
2. Zalew zdarzeń przy zdrowym systemie: poczekaj i patrz na trend
   `messages_ready` w Grafanie („RabbitMQ: wiadomości gotowe”).
3. Indeks trzeba odświeżyć od razu: `make reproject` przelicza wszystko
   z projekcji, niezależnie od kolejki.

**Czego się nauczyłem**
Zaległość i martwy konsument dają ten sam objaw w `/search`, ale inną
naprawę. Dlatego to dwa alerty: jeden na tempo odpytywania, drugi na
głębokość kolejki.

<a id="065"></a>
## 065 — Alert `CatalogHttpErrorRate`: ponad 5% odpowiedzi 5xx

**Objaw**
Mail `[FIRING:1] CatalogHttpErrorRate (warning)`: przez 5 min ponad 5 %
odpowiedzi `catalog.localhost` to 5xx przy ruchu powyżej 0,1 req/s.

**Diagnoza**
Z katalogu głównego checkoutu. Najpierw które żądania, potem dlaczego:
```bash
set -a; . ./.env; set +a
curl -sS -u "elastic:$ELASTIC_PASSWORD" "http://localhost:$ES_PORT/logs-app-catalog/_search?size=0" \
  -H 'Content-Type: application/json' -d '{
  "query":{"bool":{"filter":[{"term":{"event.dataset":"catalog.access"}},
    {"range":{"http.response.status_code":{"gte":500}}},{"range":{"@timestamp":{"gte":"now-15m"}}}]}},
  "aggs":{"p":{"terms":{"field":"url.path"}}}}'
docker compose logs --since=15m catalog-app | grep -E '"level_name":"(ERROR|CRITICAL)"' | head
```
W Kibanie: Discover, `event.dataset: catalog.access and http.response.status_code >= 500`,
weź `trace.id` jednego żądania i filtruj po nim: zobaczysz log aplikacji
z wyjątkiem (`error.type`, `error.message`). Grafana ma przycisk „Logi
w Kibanie” z tym samym zakresem czasu.

**Przyczyna**
Najczęściej: baza albo Redis niedostępne, ES niedostępny dla wyszukiwarki
([#058](#058)), wyczerpana pamięć `catalog-app` (512m), błąd po wdrożeniu.
Próg ruchu jest po to, by pojedynczy błąd na pustym stacku nie dawał 100 %.
Etykieta `host` ma port ([#054](#054)), stąd regex w regule.

**Naprawa**
Zależy od wyjątku w logu; po naprawie alert zamknie się po ~5 min
(okno `rate[5m]` + `for: 5m` działa też w drugą stronę).

**Czego się nauczyłem**
Metryka mówi, że coś jest źle i jak bardzo; które żądanie i dlaczego mówią
logi. Przejście metryka → log po czasie i `trace.id` to cały sens podziału
z ADR-0005.

<a id="066"></a>
## 066 — Alerty `OfferModerationOverdue` i `SellerApprovalOverdue`: decyzja czeka ponad dobę

**Objaw**
Mail `[FIRING:1] OfferModerationOverdue (info)` albo `SellerApprovalOverdue (info)`.
To nie awaria techniczna, tylko SLA biznesowe: zmiana ceny oferty czeka na
moderatora albo sprzedawca czeka na akceptację dłużej niż 24 h.

**Diagnoza**
Z katalogu głównego checkoutu:
```bash
make psql db=catalog
-- w psql:
select id, pending_requested_at from offers where pending_requested_at is not null order by pending_requested_at limit 10;
select id, status, coalesce(status_changed_at, created_at) from sellers where status = 'pending' order by 3 limit 10;
```
Panel „Oferty: wiek najstarszej” / „Sprzedawcy: wiek najstarszego” na
dashboardzie „Marketplace: zamówienia” pokazuje, od kiedy rośnie.

**Przyczyna**
Nikt nie zajrzał do kolejki moderacji w panelu
(`/panel/moderation/price-changes`, `/panel/sellers`). Gauge liczy wiek
**najstarszej** oczekującej pozycji, więc alert trwa, dopóki ta jedna nie
zostanie rozstrzygnięta. Na lokalnym stacku z seedem odpala zwykle po
dniu bez klikania.

**Naprawa**
Rozstrzygnij najstarsze pozycje w panelu (akceptacja albo odrzucenie). Alert zamknie się przy następnej ewaluacji (`for: 0m`).
Lokalnie można go zignorować: `info` trafia do tej samej skrzynki tylko
dlatego, że jest jeden odbiorca (spec D11).

**Czego się nauczyłem**
Nie każdy alert jest o awarii. SLA biznesowe też da się mierzyć gauge'em
liczonym przy scrape, bez zmian w domenie, i trzymać z dala od pagera
przez `severity: info`.

<a id="067"></a>
## 067 — Alerty `SearchMetricsScrapeError` i `CatalogMetricsScrapeErrors`: metryki nie do odczytania

**Objaw**
Mail `[FIRING:1] SearchMetricsScrapeError (critical)` albo
`CatalogMetricsScrapeErrors (critical)`. Cel w Prometheusie jest `UP`
(`TargetDown` milczy), ale co najmniej jedna metryka nie jest liczona:
dla search znika seria `search_messenger_failed_messages`, dla catalog znika
próbka któregoś gauge (np. `catalog_outbox_pending`). Alerty zbudowane na
brakującej serii (`SearchFailedMessages`, `OutboxStale`, `CatalogFailedJobs`…)
w tym czasie nie odpalą, nawet jeśli problem, o którym mają mówić, trwa.

**Diagnoza**
Z kontenera Prometheusa pobierz surowe wyjście endpointu:
```bash
docker compose exec prometheus wget -qO- http://search-metrics/metrics
docker compose exec prometheus wget -qO- http://catalog-app:2020/prometheus | grep -E 'scrape_error|^catalog_'
```
W search szukaj linii `search_metrics_scrape_error 1`; w catalog wartości
`catalog_metrics_scrape_errors` (liczba gauge'ów, które padły) i brakujących
linii `catalog_*`. Przyczynę zapisuje aplikacja jako `warning` w logach:
```bash
docker compose logs --since 10m search-metrics | grep "failure transportu"
docker compose logs --since 10m catalog-app | grep "Gauge Prometheusa nie policzony"   # pole metric = nazwa gauge'a
```
W Kibanie: `log.level:warning` i `message:"Gauge Prometheusa nie policzony"`.

**Przyczyna**
Endpointy celowo odpowiadają `200` mimo błędu, żeby jeden zły odczyt nie
zabrał wszystkich metryk (`up == 0` ukryłby przyczynę). Cena: brak serii nie
wywołuje żadnego alertu, więc osobna metryka liczy awarie odczytu. Typowe
przyczyny: search, bo transport `failed` (Doctrine, baza searchsvc) jest
niedostępny (Postgres leży, zły `DATABASE_URL`, brak tabeli `messenger_messages`);
catalog, bo baza nie odpowiada,
brakuje tabeli (niezrobiona migracja) albo gauge rzuca wyjątek.

**Naprawa**
Usuń przyczynę z logu: przywróć Postgresa, uruchom brakującą
migrację (`php artisan migrate --force`). Alert zamknie się po następnym
udanym scrape (≤ 15 s), bo wartość wraca do 0. Gdy w odpowiedzi znów są
wszystkie serie, sprawdź, czy nie czeka nieodpalony alert, który w międzyczasie
milczał (`SearchFailedMessages`: `messenger:failed:show`).

**Czego się nauczyłem**
Tolerancja na błędy (200 zamiast 500) zamienia głośną awarię w cichą, więc musi
iść w parze z metryką błędów i alertem na nią. Alert, który zależy od
istnienia serii, nie powie nic, gdy seria znika.

## Notatka — czytanie `_explain` (ETAP 7)

Nie każdy wpis w tym dokumencie musi być błędem — DoD ETAP 7 wymaga umieć
wyjaśnić, DLACZEGO wynik #1 wygrywa z #2, przez `_explain`, a to dobre
miejsce, żeby to zostawić.

Zapytanie `q=Wyman-Howell Laptop Ultra 14"` (bez żadnych filtrów) zwraca na
pierwszym miejscu dokument `74` (`"Wyman-Howell Laptop Ultra 14\""`, score
**53.09**), na drugim `1157` (`"Wyman-Howell Laptop Neo 14\""`, score
**45.62**) — ta sama marka, ta sama kategoria, RÓŻNY model.

```
POST /products-search/_explain/74
{ "query": { "bool": { "must": [{"multi_match": {
    "query": "Wyman-Howell Laptop Ultra 14\"",
    "type": "best_fields", "tie_breaker": 0.3,
    "fields": ["name^3", "name.ac", "brand^2", "description"]
}}], "filter": [] } } }
```

Oba dokumenty dostają pełne trafienie na `Wyman-Howell` (brand^2) i `Laptop
14"` (name/name.ac) — te składniki `_score` są niemal identyczne. Różnica
53.09 vs 45.62 pochodzi WYŁĄCZNIE z tokenu `Ultra`: dokument `74` ma go
w `name`/`name.ac` (dodatkowe trafienie BM25 na rzadkim termie — tylko 59 na
1500 dokumentów zawiera „ultra” po analizie, wysokie `idf`), dokument `1157`
go nie ma (ma za to `Neo`, które nie występuje w zapytaniu, więc nic nie
wnosi do `_score`). To jest dokładnie zachowanie, którego oczekujemy od
`best_fields` + BM25: dokument z WIĘKSZYM pokryciem unikalnych termów
zapytania wygrywa, nawet gdy oba mają identyczne trafienie na marce
i kategorii.

**Jak to samemu odtworzyć:** `docker compose exec catalog-app php artisan
tinker`, zbuduj `SearchCriteria`, wywołaj `app(App\Services\
ProductSearchService::class)->buildSearchQuery($criteria)` żeby dostać
dokładny DSL, wklej do `_explain` przez `make es-analyze` albo bezpośrednio
`curl` (przykład wyżej).

---

## Szablon nowego wpisu

```markdown
<a id="0XX"></a>
## 0XX — <krótki objaw>

**Objaw**
<dokładny komunikat błędu — skopiuj, nie parafrazuj>

**Diagnoza**
<komendy, które faktycznie doprowadziły do przyczyny>

**Przyczyna**
<co się dzieje pod spodem, nie tylko "co naprawiło">

**Naprawa**
<konkretne kroki>

**Czego się nauczyłem**
<zasada ogólna, która przyda się przy innym problemie>
```
