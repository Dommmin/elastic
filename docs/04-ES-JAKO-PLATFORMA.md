# 04 — Elasticsearch to nie jest wyszukiwarka

> „Często ES nie jest tylko wyszukiwarką, tylko całym agregatem danych. Sam nie wiem,
> po co to tak jest stosowane."

Dobre pytanie i trafna intuicja. Nazwa „Elasticsearch" jest myląca i przez nią większość
programistów widzi w nim „lepsze `LIKE`". Tymczasem w dużych firmach **wyszukiwarka
produktowa to często najmniejsze zastosowanie ES** — po objętości danych i po znaczeniu
biznesowym wygrywają logi, metryki i analityka operacyjna.

Ten dokument tłumaczy **czym ES naprawdę jest**, jakie ma archetypy zastosowań, dlaczego
akurat on, a nie inne narzędzie — i jak rozszerzymy projekt, żebyś poznał wszystkie te
twarze, a nie tylko wyszukiwarkę.

---

## 1. Czym ES jest naprawdę (definicja, która wszystko porządkuje)

> **Elasticsearch to rozproszony, near-real-time silnik zapytań i agregacji nad danymi
> pół-ustrukturyzowanymi, zbudowany na odwróconym indeksie i magazynie kolumnowym.**

Rozbierzmy to na czynniki, bo z każdego kawałka wynika inne zastosowanie:

| Właściwość | Mechanizm | Co z niej wynika |
|---|---|---|
| **Dowolne kombinacje filtrów bez planowania indeksów** | odwrócony indeks: *każde* pole jest indeksowane | w Postgresie każdy nowy wzorzec zapytania wymaga nowego indeksu; w ES filtrujesz po czymkolwiek, w dowolnej kombinacji, od razu |
| **Agregacje na dużej skali** | `doc_values` — kolumnowy magazyn na dysku | liczenie sum/średnich/percentyli po 500 mln wierszy w sekundy |
| **Near-real-time** | refresh co ~1 s | dane widoczne w dashboardzie sekundy po zdarzeniu, nie po nocnym ETL |
| **Skala pozioma** | shardy + repliki | dokładasz node'y, nie kupujesz większego serwera |
| **Elastyczny schemat** | dynamic mapping, `flattened`, runtime fields | wchłaniasz dane, których struktury nie znasz z góry (logi z 40 usług) |
| **Tania retencja** | ILM: hot → warm → cold → frozen (S3) → delete | trzymasz 2 lata danych, płacąc za szybki dysk tylko za ostatnie 7 dni |
| **Ranking, nie tylko filtrowanie** | BM25, wektory | „najbardziej pasujące", a nie tylko „pasujące" |
| **Brak joinów** | dokumenty zdenormalizowane | wymusza *materializowanie* danych z wielu źródeł w jeden dokument ← **to jest ten „agregat"** |

**Ostatni wiersz to sedno Twojego pytania.** Wrócę do niego w sekcji 3.

---

## 2. Pięć archetypów zastosowań ES w firmach

### Archetyp A — Search & Discovery (to, co mieliśmy w planie)

Wyszukiwarka produktowa, wyszukiwarka w intranecie, wyszukiwarka dokumentów, autocomplete.
Charakterystyka: liczy się **relewancja** (ranking), wolumen danych umiarkowany,
zapytań dużo, dane zmieniają się średnio często.

Kto: każdy e-commerce, portale ogłoszeniowe, dokumentacja, helpdesk.

### Archetyp B — Observability: logi, metryki, traces (największy wolumenowo)

To jest zastosowanie, przez które ES stał się sławny — stack **ELK / Elastic Stack**.
Wszystkie aplikacje w firmie wysyłają logi do jednego miejsca. Inżynier o 3 w nocy
wpisuje `service:payments AND level:error AND trace_id:abc123` i w sekundę ma odpowiedź
z 40 mikroserwisów naraz.

Dlaczego akurat ES, a nie pliki/baza:
- **wolumen**: setki tysięcy zdarzeń na sekundę, terabajty dziennie — baza relacyjna tego nie przyjmie,
- **różnorodność schematu**: każda usługa loguje inne pola; ES to wchłania,
- **pełny tekst w treści logu** + jednocześnie **filtry po polach** + **agregacje** — jedno narzędzie,
- **retencja tierowana**: ostatnie 7 dni na NVMe, 90 dni na wolniejszym dysku, rok w S3 (frozen tier),
- **near-real-time**: incydent widzisz teraz, nie za godzinę.

Charakterystyka danych: **append-only, time-series, ogromny wolumen, krótkie życie**.
Zupełnie inny profil niż wyszukiwarka produktów — i dlatego uczy zupełnie innych rzeczy
(data streams, ILM, rollover, ingest pipelines, tiering).

Kto: praktycznie każda firma z więcej niż kilkoma usługami.

### Archetyp C — Security analytics / SIEM

Ten sam mechanizm co obserwowalność, ale dane to zdarzenia bezpieczeństwa: logowania,
ruch sieciowy, zmiany uprawnień, wywołania API. Na to nakłada się **reguły detekcji**
(„5 nieudanych logowań, potem udane, z innego kraju") i **threat hunting** (interaktywne
przeszukiwanie miliardów zdarzeń wstecz).

Dlaczego ES: korelacja zdarzeń z różnych źródeł w jednym miejscu + zapytania ad-hoc po
danych, których nikt nie przewidział przy projektowaniu. Klasyczna baza wymagałaby
znajomości pytań z góry.

### Archetyp D — Analityka operacyjna (business intelligence „na żywo")

**To jest ta twarz ES, o którą pytasz.** Firma chce dashboard: sprzedaż w podziale na
kategorie, lejek konwersji, zachowanie użytkowników, wykrywanie anomalii w zamówieniach.

Naturalnym narzędziem wydaje się hurtownia danych (Snowflake, BigQuery). Ale hurtownia ma
dwie wady, które w wielu zastosowaniach są zabójcze:
- **świeżość** — dane trafiają tam batchem, są opóźnione o minuty–godziny,
- **interaktywność** — zapytanie kosztuje sekundy do minut, więc nie da się na tym zbudować
  UI, w którym użytkownik klika filtry i oczekuje natychmiastowej odpowiedzi.

ES odpowiada w kilkadziesiąt milisekund na danych sprzed sekundy. Dlatego dzieli się to tak:

| | Elasticsearch | Hurtownia danych |
|---|---|---|
| Horyzont | ostatnie dni/miesiące | lata |
| Świeżość | sekundy | minuty–godziny |
| Czas zapytania | ms | s–min |
| Typ pracy | **operacyjny**: „co się dzieje teraz" | **analityczny**: „jak było w Q3 2024" |
| Odbiorca | aplikacja, dyżurny inżynier, operacje | analityk, zarząd, raporty |

Nazywa się to **operational analytics** i to jest dokładnie ta nisza ES.

### Archetyp E — Materialized read model / „agregat danych" (najciekawszy architektonicznie)

I tu dochodzimy do tego, co wyczułeś. Sytuacja w dużej firmie:

> Dane o kliencie leżą w 8 systemach: CRM, billing, system zamówień, magazyn, obsługa
> zgłoszeń, marketing automation, system lojalnościowy, zewnętrzne API kurierskie.
> Konsultant na infolinii ma **jeden ekran** i musi w 200 ms zobaczyć pełny obraz klienta,
> z możliwością wyszukania go po czymkolwiek: nazwisku, mailu, numerze zamówienia,
> numerze przesyłki, fragmencie treści zgłoszenia.

Jak to zrobić?

- **Opcja 1: join w locie po API** — 8 równoległych requestów, latencja = najwolniejszy
  z nich, awaria jednego psuje ekran, nie da się wyszukiwać po polach z różnych systemów
  jednocześnie („znajdź klientów z Warszawy, którzy mieli reklamację i zamówienie > 5000 zł").
  **Nie działa.**
- **Opcja 2: hurtownia danych** — dane wczorajsze. Konsultant potrzebuje danych sprzed
  30 sekund. **Nie działa.**
- **Opcja 3: jedna wielka baza relacyjna z joinami** — wracamy do monolitu, którego firma
  właśnie się pozbyła; join po 8 tabelach po 200 mln wierszy nie da 200 ms.
  **Nie działa.**
- **Opcja 4: materializowany dokument w ES.** Każdy system publikuje zdarzenia; osobny
  serwis scala je w **jeden zdenormalizowany dokument** per klient i trzyma w ES.
  Zapytanie dotyka jednego indeksu, po dowolnej kombinacji pól, w kilkadziesiąt ms.
  **Działa.**

To jest wzorzec nazywany różnie: *materialized view*, *read model*, *CQRS projection*,
*data aggregate*, *unified data layer*, *customer 360*. Mechanizm zawsze ten sam:

```
System A ─┐
System B ─┼─► szyna zdarzeń (Kafka/RabbitMQ) ─► projektor ─► ES ─► API/UI (ms)
System C ─┘                                      (denormalizacja
System D ─┘                                       + wzbogacanie)
```

**Dlaczego akurat ES na końcu tej rury, a nie Redis czy Postgres?**
Bo dokument scalony z 8 systemów ma 200 pól i nikt nie wie z góry, po których ktoś zechce
filtrować, sortować i agregować. Redis potrafi tylko „daj po kluczu". Postgres wymagałby
indeksu na każdą kombinację. ES indeksuje **wszystko**, więc dowolne zapytanie ad-hoc
jest szybkie od pierwszego dnia. Do tego dostajesz gratis wyszukiwanie pełnotekstowe po
polach opisowych i agregacje na dashboard.

To jest odpowiedź na Twoje „po co to tak jest stosowane": **ES bywa nie wyszukiwarką,
tylko warstwą serwującą (serving layer) dla danych rozproszonych po całej firmie.**

---

## 3. Kiedy ES to **zły** wybór (równie ważne)

Znajomość granic narzędzia to różnica między seniorem a entuzjastą.

| Potrzeba | Właściwe narzędzie | Dlaczego nie ES |
|---|---|---|
| Transakcje, spójność, źródło prawdy | PostgreSQL | brak ACID między dokumentami, brak FK, brak rollbacku |
| Czysta analityka OLAP, dużo `GROUP BY`, joiny | ClickHouse / hurtownia | ES jest droższy w zasobach i słabszy w joinach; ClickHouse zwykle szybszy i tańszy na tym profilu |
| Metryki systemowe z alertami | Prometheus / VictoriaMetrics | model pull + PromQL + kompresja time-series są do tego przystosowane lepiej |
| Kolejka / log zdarzeń, replay | Kafka | ES nie jest szyną danych; nie odtworzysz z niego strumienia |
| Cache, sesje, liczniki | Redis | ES ma o rzędy wielkości większy narzut |
| Dokładne zliczanie unikalnych (billing!) | baza / ClickHouse | `cardinality` w ES jest **przybliżone** (HyperLogLog++) |
| Archiwum na 10 lat, rzadko czytane | S3 + Parquet/Iceberg | ES to drogi sposób trzymania zimnych danych |
| Wyszukiwanie po kluczu głównym | dowolna baza | ES użyty jako key-value to marnotrawstwo |

**Najczęstszy błąd w firmach:** ES użyty jako baza główna, bo „jest szybki". Kończy się
utratą danych i przepisywaniem systemu. Drugi najczęstszy: ES jako hurtownia danych na
5 lat historii, bo „już go mamy" — kończy się rachunkiem za infrastrukturę.

---

## 4. Gdzie ES siedzi w nowoczesnym stosie danych

```
 ŹRÓDŁA            TRANSPORT          PRZETWARZANIE        WARSTWA SERWUJĄCA      ODBIORCY
┌─────────┐      ┌──────────┐       ┌─────────────┐      ┌────────────────┐    ┌─────────┐
│ apki    │      │          │       │ Flink /     │      │ Elasticsearch  │    │ UI/API  │
│ bazy    │─────►│  Kafka   │──────►│ konsumenci  │─────►│ (ms, świeże,   │───►│ dyżurny │
│ logi    │      │ RabbitMQ │       │ (denormali- │      │  ad-hoc)       │    │ dashb.  │
│ 3rd     │      │          │       │  zacja)     │      └────────────────┘    └─────────┘
│ party   │      └──────────┘       └──────┬──────┘      ┌────────────────┐    ┌─────────┐
└─────────┘                                └────────────►│ Hurtownia/S3   │───►│ analityk│
                                                         │ (h, historia)  │    │ ML      │
                                                         └────────────────┘    └─────────┘
```

Zapamiętaj podział ról: **Kafka/RabbitMQ przenosi, Flink/konsumenci przetwarzają,
Elasticsearch serwuje szybkie zapytania na świeżych danych, hurtownia serwuje historię.**
W naszym projekcie RabbitMQ gra rolę Kafki, a `search-service` rolę projektora.

---

## 5. Jak rozszerzamy projekt, żeby pokryć wszystkie twarze ES

Dobra wiadomość: architektura z [02-APLIKACJE.md](02-APLIKACJE.md) **już produkuje właściwe dane**.
Nie trzeba jej przebudowywać — trzeba dołożyć trzy tory, które korzystają z tego samego
strumienia zdarzeń. Marketplace jest do tego idealną domeną, bo naturalnie generuje
i katalog, i logi, i zdarzenia biznesowe, i dane z wielu „systemów".

### TOR 1 — Search (archetyp A) — bez zmian

Indeks `products-search`. Moduły 3–9, 13 ścieżki nauki.

### TOR 2 — Observability (archetyp B) — **awansuje z opcji na rdzeń**

Logi i metryki **naszych własnych** aplikacji trafiają do ES: Laravel, Symfony, RabbitMQ,
Postgres, sam Elasticsearch. To nie jest ćwiczenie „na sucho" — będziesz miał realne
incydenty do zdiagnozowania, bo sam je wywołasz w ćwiczeniach „zepsuj i napraw".

Co konkretnie budujemy:
- logi w formacie **ECS** (Elastic Common Schema) z obu aplikacji, z `trace_id`
  propagowanym przez RabbitMQ — zobaczysz **jedno żądanie użytkownika przez oba serwisy i kolejkę**,
- data streams `logs-app-*` z ILM (hot 3 dni → warm 7 → delete 30),
- ingest pipeline z `grok`/`dissect`, obsługą błędów i wzbogacaniem (`enrich`),
- APM: czas zapytań do ES z poziomu PHP — nauczysz się odróżniać „ES jest wolny"
  od „moja apka wolno odpytuje ES",
- dashboard: p95 latencji wyszukiwania, error rate, lag konsumentów.

**Czego uczy, a czego nie uczy tor Search:** data streams, rollover, ILM, tiering,
ingest pipelines, ECS, korelacja rozproszona, praca z ogromnym wolumenem
append-only, oszczędzanie miejsca (`best_compression`, `synthetic _source`).

### TOR 3 — Analityka operacyjna (archetyp D) — **nowy**

Zdarzenia użytkownika (`user.searched`, `user.clicked_result`, `user.added_to_cart`)
→ data stream `events-user-*` → dashboardy i pętla zwrotna do rankingu.

Co budujemy:
- **lejek konwersji**: wyszukiwanie → wyświetlenie → kliknięcie → koszyk, liczony
  agregacjami (i porównanie: jak to policzyć w ES vs jak w SQL),
- **raport „zero results"** — frazy bez wyników; to realny artefakt biznesowy,
  na podstawie którego dopisujesz synonimy,
- **CTR i pozycja kliknięcia** — miara jakości wyszukiwarki,
- **Transforms** — materializacja dziennych statystyk do `search-stats-*`
  (nauka: kiedy liczyć w locie, a kiedy wcześniej),
- **pętla zwrotna**: popularność z kliknięć → pole `rank_feature` → lepszy ranking.
  Zamykasz obieg: dane analityczne wracają do produktu.
- **wykrywanie anomalii** regułami (skok pustych wyników, nagły spadek CTR) — i osobno
  ML na trialu, żeby zobaczyć różnicę między regułą a modelem.

### TOR 4 — Agregat danych / „Seller 360" (archetyp E) — **nowy, najważniejszy dla Twojego pytania**

Budujemy indeks `seller-360`, w którym **jeden dokument = jeden sprzedawca**, scalony
z pięciu niezależnych źródeł:

| Źródło | Dane | Jak trafia do ES |
|---|---|---|
| Postgres `catalog` (Laravel) | dane sprzedawcy, oferty | zdarzenia przez RabbitMQ |
| Strumień zdarzeń | sprzedaż, wyświetlenia, konwersja | agregacja w `search-service` |
| Zgłoszenia/reklamacje | treść tekstowa zgłoszeń | osobny „system" — mały serwis lub import CSV |
| Zewnętrzne API | weryfikacja NIP/KRS, rating kurierski | wywołanie HTTP + cache |
| System rozliczeń | saldo, faktury, zaległości | osobna baza + zdarzenia |

Po co to komu — realne zapytania, które staną się możliwe:

> „Pokaż sprzedawców z Mazowsza, którzy w ostatnich 30 dniach mieli spadek konwersji
> powyżej 20 %, mają zaległość płatniczą i w treści reklamacji pojawia się słowo
> «uszkodzon*» — posortuj po wartości sprzedaży."

Spróbuj napisać to jako SQL po pięciu systemach. Nie da się — i **to jest odpowiedź
na pytanie, po co firmy budują agregaty w ES**.

Czego się przy tym nauczysz (rzeczy nieobecnych w torze Search):
- **denormalizacja z wielu źródeł** i problem: co, gdy jedno źródło się spóźni
  (partial document, `_update` z `doc_as_upsert`, scalanie częściowe),
- **fan-out i amplifikacja zapisu** — jedna zmiana rating kuriera dotyka 10 tys. dokumentów,
- **enrich processor** — wzbogacanie w ingest node danymi referencyjnymi,
- **wersjonowanie pól per źródło** — skąd wiesz, że sekcja „billing" jest aktualna,
- **`_source` filtering i FLS** — konsultant widzi saldo, sprzedawca nie,
- **backfill z zerowym downtime** przy 5 źródłach, z których każde ma inny czas odpowiedzi,
- **pomiar świeżości** — metryka „lag" per sekcja dokumentu, wystawiona na dashboard.

### TOR 5 — Security analytics „lite" (archetyp C) — opcjonalny, na koniec

Na tych samych danych: reguły detekcji nadużyć sprzedawców (manipulacja ceną tuż po
zdobyciu widoczności, seria fałszywych recenzji z jednego IP, skok zwrotów). Realizowane
jako zapytania ES + Kibana Alerting + percolator.

Uczy: myślenia „detection rules", korelacji zdarzeń w oknie czasowym, agregacji
`significant_terms` (świetna do wykrywania anomalii) i `date_histogram` z `moving_fn`.

---

## 6. Zaktualizowana mapa nauki

Kolejność zostaje, ale dochodzą trzy nowe moduły i zmienia się akcent:

| Moduł | Tor | Zmiana |
|---|---|---|
| 1–9 | Search | bez zmian — fundament jest wspólny dla wszystkich torów |
| **10a (nowy)** | **Observability** | data streams, ILM, rollover, tiering, ECS, ingest pipelines, korelacja `trace_id` |
| 10 | wspólny | wydajność i tuning — teraz na **dwóch profilach danych**: search vs time-series (bardzo różne!) |
| 11 | wspólny | operacje na indeksach — bez zmian |
| **11a (nowy)** | **Agregat / 360** | denormalizacja wieloźródłowa, partial updates, enrich, fan-out, pomiar świeżości |
| 12 | wspólny | bezpieczeństwo — teraz z realnym uzasadnieniem FLS/DLS (dane billingowe w `seller-360`) |
| 13 | Search | wektory i semantyka |
| **13a (nowy)** | **Analityka** | transforms, lejki, pętla zwrotna do rankingu, wykrywanie anomalii, ES\|QL |
| 14 | wspólny | runbook — teraz obejmuje incydenty ze wszystkich torów |
| 15 | wspólny | RabbitMQ |

**Uwaga o koszcie:** trzy nowe tory to realnie +4–6 tygodni nauki. Ale bez nich znałbyś
ES tylko od strony, którą zna większość programistów PHP — a Ty pytasz właśnie o tę
drugą stronę. Tory są niezależne: możesz przejść 1–9 i wybrać, który tor dalej.

---

## 7. Pytania kontrolne do tego dokumentu

1. Wymień 5 archetypów zastosowań ES i podaj po jednym realnym przykładzie.
2. Firma ma dane w 6 systemach i chce jeden ekran z wyszukiwaniem po wszystkim.
   Wymień 4 możliwe architektury i uzasadnij wybór.
3. Kiedy wybierzesz ClickHouse zamiast ES? A kiedy Prometheus?
4. Dlaczego hurtownia danych nie zastąpi ES w analityce operacyjnej?
5. Jaka właściwość ES sprawia, że nadaje się na „agregat danych", a Redis nie?
6. Czym różni się profil obciążenia indeksu z logami od indeksu z produktami?
   Jakie ustawienia będą inne?
7. Twoja firma chce trzymać w ES 5 lat danych transakcyjnych „bo już mamy ES".
   Jak odpowiadasz?
