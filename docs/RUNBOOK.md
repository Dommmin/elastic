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
