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
| [017](#017) | External versioning: kolizja `sequence` między różnymi agregatami | Architektura | 6 |
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

**Status: NIE naprawione, świadomie odłożone.** Udokumentowane w
`ElasticsearchIndexer.php` i tutaj, żeby nie zaskoczyło po cichu przy
pierwszej prawdziwej zmianie ceny na niskim numerze sekwencji.

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
