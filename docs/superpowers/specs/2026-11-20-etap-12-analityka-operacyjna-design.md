# ETAP 12 — analityka operacyjna: projekt

*Data: 2026-11-20. Moduł 13a (`docs/04-ES-JAKO-PLATFORMA.md`, tor 3). Mapa drogowa: `docs/06-PLAN-WDROZENIA.md`, ETAP 12.*

## 1. Po co

Projekt jest do nauki Elasticsearcha. ETAP 12 uczy drugiego archetypu ES: **analityki operacyjnej na strumieniu
zdarzeń** (data streams, ILM, Transforms, ES|QL, Kibana Alerting, enrich). Domyka też pętlę: dane o zachowaniu
użytkowników wracają do rankingu, a efekt jest **zmierzony**, nie zadeklarowany.

**DoD z mapy drogowej:** dashboard z lejkiem i CTR; popularność z kliknięć realnie zmienia ranking; `_rank_eval`
przed i po **udowadnia** poprawę. Do tego obowiązkowo przewodnik w `docs/blog/` (definicja „etap skończony”).

## 2. Cele, nie-cele, anty-cele

**Cele**
- Zdarzenia `user.*` z prawdziwego UI catalogu i z symulatora lądują w data streamie `events-user-default`.
- Lejek wyszukiwanie → kliknięcie → koszyk → checkout, CTR, CTR wg pozycji, raport „zero results”: Kibana + ES|QL.
- Transforms: dzienne statystyki zapytań (`search-stats-daily`) i popularność produktów (`product-popularity`).
- Popularność wraca do indeksu produktów jako `rank_feature` i wpływa na `_score`.
- Dowód w `_rank_eval` z eksperymentem kontrolnym (sekcja 8).
- Dwie reguły anomalii w Kibana Alerting, wyzwalane wstrzykniętą anomalią z symulatora.

**Nie-cele**
- ML anomaly detection (płatne; mapa drogowa przenosi trial do ETAPU 14).
- Śledzenie po stronie przeglądarki (beacon JS), RODO/zgody, łączenie sesji między urządzeniami.
- Wdrożenie na k3s (brak serwera) — manifesty tylko, jeśli dochodzą nowe procesy; weryfikacja lokalnie.

**Anty-cele (porażka, nawet jeśli „działa”)**
- Dowód poprawy, który wynika z definicji (symulator klika według tych samych ocen, którymi mierzymy).
- Request użytkownika czeka na Elasticsearch albo pada, gdy ES lub RabbitMQ leży.
- Reprojekcja produktu (strumień ETAPU 9 albo narzędzia ETAPU 10) kasuje popularność.
- Regresja 13 zapytań z `search:eval` (baseline `POMIARY.md` §5a) bez świadomej decyzji.

## 3. Podejścia (wybrane z użytkownikiem)

**Model kliknięć** — rozważone: (1) klient z ukrytą atrakcyjnością i biasem pozycji, (2) ręczne klikanie w UI +
dobicie wolumenu, (3) symulator klika według ocen. **Wybrane (1)**: (2) daje za cienkie dane, (3) czyni dowód
błędnym kołem. (1) pozwala też pokazać, jak bias pozycji zniekształca CTR i „utrwala” obecną kolejność.

**Droga zdarzeń** — rozważone: (A) RabbitMQ → search-service → bulk do data streamu, (B) logi JSON → Filebeat →
ingest pipeline, (C) catalog pisze do ES sam. **Wybrane (A)**: search-service już jest właścicielem zapisów do ES,
kolejka buforuje awarie, a backfill historii z podanym `@timestamp` to zwykły bulk. (B) uzależnia dane biznesowe od
profilu `obs` i polityki logów; (C) miesza odpowiedzialności i i tak wymaga bufora. Topologia jest już gotowa:
kolejka `search.analytics.ingest` (quorum, DLX, `x-delivery-limit 5`) związana z `marketplace.events` kluczem
`user.#`, transport `analytics` w `apps/search/config/packages/messenger.yaml`.

## 4. Zdarzenia

Koperta bez zmian (`OutboxMessageFactory::envelope`): `id`, `type`, `version`, `source`, `occurred_at`,
`aggregate`, `sequence`, `data`. `aggregate = {type: "session", id: <session_id>}`, `sequence` = 0 (analityka nie
potrzebuje kolejności per agregat).

| Routing key | Kiedy (catalog) | `data` |
|---|---|---|
| `user.searched` | `SearchController::index` (web i API), tylko pierwsza strona wyników | `search_id`, `query` (znormalizowane: trim, lowercase), `query_raw`, `filters`, `total_hits`, `results` (ID produktów top 20 w kolejności), `sort` |
| `user.clicked_result` | `ProductPageController::show` z parametrami `sid` i `pos` w URL | `search_id`, `query`, `product_id`, `position` (1-based) |
| `user.added_to_cart` | `CartController::store` | `product_id`, `offer_id`, `search_id` i `query`, jeśli produkt otwarto z wyników |
| `user.checked_out` | `CartController::checkout` (request kupującego) | `order_ids` (jeden checkout = kilka zamówień, po jednym na sprzedawcę), `product_ids`, `amount_cents` |

**Moje decyzje:**
- **Kliknięcie rejestruje serwer przy wejściu na stronę produktu**, a nie beacon JS. Linki w `Search.vue` dostają
  `?sid=<search_id>&pos=<n>`. Prościej, testowalne w Pest, działa bez JS.
- **Kontekst wyszukiwania w Redisie:** przy `user.searched` catalog zapisuje `search_id → {query, results}` na 30 min.
  Kliknięcie i koszyk dociągają z niego `query`, więc każde zdarzenie niesie frazę, a Transform może liczyć CTR per
  fraza bez joinów (pivot nie umie łączyć dokumentów po `search_id`). Brak wpisu (wygasł) = zdarzenie bez `query`.
- **Odświeżenie strony produktu nie dubluje kliknięcia:** `user.clicked_result` niesie w `data` deterministyczny
  `dedup_key` = `click:<search_id>:<product_id>`, a handler używa go jako `_id` zamiast `id` koperty (kolumna
  `outbox.event_id` to ULID, więc nie da się w niej trzymać klucza wyliczonego). Drugi zapis dostaje 409
  (sekcja 5) — deduplikacja w jednym miejscu, Transformy jej nie potrzebują.
- **Koniec lejka to `user.checked_out` z warstwy web, nie zdarzenie kontekstu Ordering.** Ordering celowo nie
  wypuszcza zdarzeń zamówienia (`OrderEventLog`), a potwierdzenie płatności przychodzi webhookiem bramki
  (`PaymentWebhookController`) bez cookie sesji. Checkout to fakt analityczny z requestu kupującego — granica kontekstu zostaje nienaruszona. Płatność
  poza lejkiem (świadomie).
- **Koszyk wymaga logowania**, więc anonimowa sesja wypada z lejka na kroku koszyka albo kontynuuje po zalogowaniu —
  `mp_sid` przeżywa logowanie, więc lejek się nie rozcina. Lejek w raporcie pokazuje ten krok jawnie.
- **Sesja = anonimowy identyfikator w cookie** (`mp_sid`, losowy UUID, 30 min bezczynności przedłużane przy każdym
  żądaniu), niezależny od logowania. `user_id` dokładany, gdy użytkownik jest zalogowany. Sesja Laravela się nie
  nadaje: zmienia ID przy logowaniu (ochrona przed session fixation), więc rozcięłaby lejek na „przed” i „po”.
- **Publikacja z UI przez istniejący outbox** (wiersz w `outbox`, wysyła `outbox-publisher`). Jedna droga publikacji,
  `trace_id` za darmo, request nie dotyka RabbitMQ. Wiersz zapisywany poza transakcją domenową (analityka może
  zginąć przy awarii Postgresa w tej samej chwili — akceptowalne).
- **Symulator publikuje prosto do `marketplace.events`** z tą samą kopertą (publisher confirms, paczki). Przy
  setkach tysięcy zdarzeń historii outbox byłby wąskim gardłem i zaśmiecił tabelę. Od RabbitMQ w górę droga jest
  identyczna jak z UI.

## 5. search-service: ingest do data streamu

- Handler `AnalyticsEventHandler` na transporcie `analytics`. Zbiera wiadomości w paczki (Messenger
  `BatchHandlerInterface`, np. 500 sztuk) i wysyła jednym `_bulk`. Paczka spływa, gdy jest pełna albo gdy worker
  nie ma więcej wiadomości (tak działa `BatchHandlerTrait` — nie ma flushu co N sekund).
- `op_type: create`, `_id` = `id` zdarzenia → **duplikat z at-least-once dostaje 409 i jest pomijany** — deduplikacja
  bez tabeli `processed_events`. Ograniczenie świadome: działa w obrębie jednego backing index. Duplikat po
  rolloverze przejdzie — realnie tylko przy ręcznym `messenger:failed:retry` po dniach; RUNBOOK to opisze.
- Błędy pozycji `_bulk` inne niż 409: wyjątek → retry Messengera (`max_retries: 3`, przez `marketplace.delays`)
  → po wyczerpaniu transport `failed` (Doctrine), jak w `product_sync`. Kolejka `search.analytics.ingest.dlq`
  i `x-delivery-limit` w praktyce nie biorą udziału (`AckAfterHandOffTransport` potwierdza po przekazaniu).
  Odrzucenie przez mapowanie (`mapper_parsing_exception`) = `UnrecoverableMessageHandlingException` → od razu
  `failed`, bez retry.
- Dokument: `@timestamp` = `occurred_at`, `event.type`, `session.id`, `user.id`, `search.id`, `search.query`, …
  — płasko spłaszczona `data` w polach ECS-podobnych. Szablon i mapowanie jako kod w `infra/elasticsearch/`.
- **Pipeline ingest `events-ingested`** (domyślny w szablonie) ustawia `event.ingested` = czas przyjęcia przez ES.
  Transform ciągły synchronizuje się po `event.ingested`, a kubełkuje po `@timestamp`. Sync po `@timestamp`
  gubiłby po cichu zdarzenia spóźnione (backfill historii, retry, przestój kolejki) — checkpoint czyta tylko
  dokumenty nowsze od poprzedniego.
- **Szablon indeksu** `events-user` (data stream, `index.mode` zwykły), `synthetic _source` nie — Transforms
  i podgląd w Discover potrzebują zwykłego `_source` w tej skali. **ILM** `events-user`: hot z rolloverem
  1 dzień / 1 GB, delete po 90 dniach.
- **Uprawnienia:** setup (szablony, ILM, pipeline'y, polityka enrich, transformy, reguły Kibany) robi skrypt jako
  `elastic`, jak pozostałe `setup-*.sh`. Rola `search_service` dostaje w `setup-security.sh`: `create_doc`
  na `events-*`, odczyt `events-*` i `product-popularity`, `manage_transform`, `manage_enrich`, bo
  `search:popularity:refresh` (sekcja 7) chodzi jako ona.

## 6. Transforms

| Transform | Tryb | Grupowanie | Metryki |
|---|---|---|---|
| `search-stats-daily` | ciągły, `sync` po `event.ingested`, opóźnienie 60 s | `date_histogram(@timestamp)` 1d × `search.query` | liczba wyszukań, zero results, unikalne sesje, kliknięcia, CTR, średnia pozycja kliknięcia (kliknięcia niosą `query`, sekcja 4) |
| `product-popularity` | wsadowy, odtwarzany codziennie komendą | `product_id` | wyświetlenia (z `results`), kliknięcia, kliknięcia skorygowane o pozycję, `popularity` |

**Dlaczego `product-popularity` jest wsadowy:** liczony z okna ostatnich 30 dni. Transform ciągły tylko dopisuje
i aktualizuje kubełki, nie „zapomina” zdarzeń wychodzących z okna — przy oknie przesuwnym prościej przeliczyć
całość (kilka sekund przy tej skali). To jest lekcja „kiedy ciągły, kiedy wsadowy”.

**Kliknięcia z korektą biasu pozycji (sedno 12b):** surowe kliknięcia nagradzają to, co już jest wysoko.
Korekta to ważenie odwrotnością skłonności (IPS): każde kliknięcie na pozycji `k` liczy się jako `1 / p(k)`, gdzie
`p(k)` to szacowane prawdopodobieństwo obejrzenia pozycji. `p(k)` szacujemy z danych (CTR wg pozycji dla wszystkich
zapytań, znormalizowany do pozycji 1), nie bierzemy z parametrów symulatora — inaczej korekta „znałaby odpowiedź”.

**Formuła `popularity` to wygładzony współczynnik, nie suma** — suma rosłaby z liczbą wyświetleń, więc wygrywałyby
produkty z częstych fraz (Zipf), a w kontroli popularność byłaby proporcjonalna do ekspozycji:

- surowa: `(kliknięcia + α·c̄) / (wyświetlenia + α)`
- IPS: `(Σ po kliknięciach 1/p(k) + α·c̄) / (wyświetlenia + α)`

gdzie `c̄` to średni CTR globalnie (dla surowej: kliknięcia / wyświetlenia; dla IPS: `Σ 1/p(k)` / wyświetlenia — prior musi być w tej samej skali co współczynnik, inaczej w kontroli IPS dalej rankowałaby po ekspozycji), `α` (rzędu 20) to siła wygładzania. Wartość zawsze > 0 (wymóg `rank_feature`).
W kontroli (atrakcyjność płaska) IPS-owy współczynnik wychodzi ≈ stały dla wszystkich produktów → brak wpływu na
kolejność. Raport porównuje obie wersje w `_rank_eval` (sekcja 8).

Wyświetlenia z tablicy `results` wymagają rozwinięcia jej na pary (produkt, pozycja). Transform tego nie umie
(pivot po polu wielowartościowym liczy każdą wartość, ale bez pozycji) — dlatego handler zapisuje **dodatkowe
dokumenty `impression`** (jeden na produkt z top 20, z pozycją) do osobnego data streamu `events-impression-default`.
Koszt: ~20× więcej dokumentów niż wyszukań; przy skali symulacji (rzędu 50 tys. wyszukań) to ~1 mln małych
dokumentów — akceptowalne lokalnie i dobry materiał do pomiaru miejsca (`POMIARY.md`).

## 7. Popularność w rankingu

- Mapowanie **`products-v6`** (v5 już istnieje — przebudowa z ETAPU 10) = v5 + `popularity: rank_feature`
  (+ `popularity_raw` i `popularity_ips` jako `float` do wglądu i porównań). Migracja narzędziami ETAPU 10
  (`search:index:migrate`, bramka) — to też ćwiczenie tych narzędzi.
- **Jak popularność przeżywa reprojekcję:** pipeline ingest `products-enrich-popularity` z procesorem `enrich`
  (polityka `product-popularity-policy` na indeksie `product-popularity`, pole dopasowania `product_id`)
  ustawiony jako `index.default_pipeline` w `products-v6`. Każdy zapis produktu — strumień, backfill, reprojekcja —
  przechodzi przez pipeline i dostaje aktualną popularność.
- **Szczegóły pipeline'u, bo mapowanie jest `dynamic: strict` i nie ma pola `product_id`:** enrich czyta `_id`
  dokumentu (`field: "_id"`), wynik ląduje w tymczasowym `_pop`, procesory `set` przepisują wybraną wartość do
  `popularity` / `popularity_*`, `remove` kasuje `_pop`. Brak wpisu (`ignore_missing`) = pole nieustawione
  (rank_feature traktuje brak jako zero wkładu).
- **Pipeline nigdy nie odrzuca zapisu produktu** (anty-cel: strumień ETAPU 9): `on_failure` na poziomie pipeline'u
  kasuje `_pop` i pola popularności i przepuszcza dokument. Pokrywa brak polityki / niewykonaną politykę / brak
  indeksu enrich. Setup od razu tworzy pusty `product-popularity` i wykonuje politykę, żeby stan „brak enrich”
  istniał tylko na wypadek awarii. Test integracyjny: zapis produktu przy usuniętej polityce przechodzi.
- **Odświeżenie po przeliczeniu:** komenda `search:popularity:refresh` = odtwórz transform → `_execute` polityki
  enrich → `_update_by_query` z `pipeline=products-enrich-popularity` (zadanie w tle, `conflicts=proceed`, throttling).
  Wersje: UBQ zapisuje V+1 wewnętrznie, a wersja zewnętrzna to `projected_at_us` (~1,7e15) — ten sam argument co
  w `SellerRenamer` (ETAP 10), kolejność strumienia zostaje nienaruszona.
- **Zapytanie — jedna ścieżka:** dziś `search()` i `priceHistogram()` składają własny `bool`, a `buildSearchQuery()`
  używa tylko `SearchEvalCommand`. Klauzula w samym `buildSearchQuery()` zmieniłaby `_rank_eval`, ale nie to, co
  widzi użytkownik i symulator. Dlatego najpierw refaktor: wspólna metoda budująca część punktującą (tekst +
  popularność), używana przez `search()`, `buildSearchQuery()` i eval; test pilnuje, że oba ciała zapytania mają tę
  samą część punktującą. Klauzula: `bool.should: rank_feature { field: popularity, saturation: {}, boost: B }`.
- Tylko przy zapytaniu tekstowym (dla `match_all` i sortowań po cenie nie ma sensu) — dlatego zapytania dowodu
  (sekcja 8) są **frazami tekstowymi**, które dają duże grupy remisu (np. „smartfon”, „słuchawki” — przez synonimy
  i nazwę kategorii), nie przeglądaniem kategorii filtrem.
- `B` i włącznik w konfiguracji (`SEARCH_POPULARITY_BOOST`, 0 = wyłączone), żeby `_rank_eval` dało się puścić przed
  i po bez zmiany kodu. Wariant popularności wybiera `search:popularity:refresh --variant=raw|ips`: pipeline
  przepisuje do `popularity` wskazaną wartość, a obie trafiają do `popularity_raw` / `popularity_ips`.

## 8. Symulator i dowód

**Symulator** — komenda w catalogu `analytics:simulate` (ma bazę produktów i `ProductSearchService`, więc ranking
jest prawdziwy, bez HTTP):

- Zapytania losowane z listy fraz z wagami (rozkład Zipfa) zbudowanej z kategorii, marek i nazw seeda, plus
  ~5% fraz bez wyników (literówki, produkty spoza katalogu).
- **Ukryta atrakcyjność** `a(produkt) ∈ (0,1)`: deterministyczna (ziarno + hash ID, rozkład Beta), nieznana
  wyszukiwarce. Kliknięcie w pozycję `k`: `P = p_obs(k) · a`, `p_obs(k) = 1/k^η` (domyślnie η = 1). Szum: część
  sesji klika losowo.
- Po kliknięciu: koszyk z P zależnym od `a`, zakup z mniejszym P. Lejek ma więc sensowne proporcje.
- Opcje: `--days`, `--sessions-per-day`, `--seed`, `--eta`, `--flat-attractiveness` (kontrola), `--inject=zero-results|ctr-drop`
  (anomalia w ostatniej godzinie), `--live` (bieżący czas zamiast historii).

**Dowód, krok po kroku:**
1. Nowy zestaw ocen `tests/relevance/popularity-queries.yaml`: **frazy tekstowe** z dużymi grupami remisu,
   oceny **wewnątrz grupy** z prawdy symulatora (`a` zaokrąglone do skali 0–3). Uczciwie zapisujemy w przewodniku,
   co to mierzy: *czy pętla odzyskuje ukrytą prawdę z zaszumionych, przesuniętych przez pozycję kliknięć*. Istniejące
   reguły (`RelevanceJudgments`) oceniają całe grupy remisu tak samo, więc z definicji nie zobaczą tej poprawy.
2. **Stabilna linia bazowa mimo remisów.** Bez popularności kolejność w remisie to kolejność dokumentów w Lucene,
   zmienna przy każdej przebudowie (`search:eval` już to wykrywa — RUNBOOK #096), a migracja do v6 to przebudowa.
   Dlatego eval dostaje `--tie-shuffles=N` (domyślnie 0 = jeden przebieg jak dotąd, ze strażnikiem remisów; dowód
   używa N = 20): N przebiegów `_rank_eval`, w przebiegu `i` żądanie dostaje drugi klucz sortowania
   `sort: [{_score: desc}, {_script: seedowany hash SplitMix64(_seq_no, created_at, ziarno = i), order: asc}]`.
   `query` zostaje bez zmian, więc `_score` jest liczony bit w bit tak samo, a zmienia się wyłącznie kolejność
   dokumentów o identycznym wyniku. `_seq_no` jest wspólny dla primary i repliki, więc kolejność nie zależy od
   kopii shardu. Raport: średnia i min–max nDCG@10. Tak samo mierzymy „przed” i „po” — produkty bez popularności
   dalej remisują i dalej są tasowane.

   *Zmienione w trakcie implementacji (Task 10).* Pierwotnie: `function_score` z `random_score` (ziarno = numer
   przebiegu) o wadze tak małej (1e-6), że rozstrzyga tylko remisy, i domyślne N = 20. Na żywym klastrze to nie
   działa, bo `_score` to float32: przy wyniku ~27 odstęp między sąsiednimi floatami to ~1,9e-6, więc dodatek < 1e-6
   zaokrągla się do zera dla ~95% dokumentów, a kolejność w remisie dalej wyznacza Lucene. Większa waga przestawiałaby
   dokumenty o różnych wynikach (przy `rank_feature` wyniki są prawie ciągłe). Sortowanie osiąga cel z konstrukcji.
   Domyślne 0 zachowuje dotychczasowe zachowanie `make eval`. Szczegóły: RUNBOOK #133, przewodnik 12b krok 5.
3. **Pętla otwarta (dowód główny):** 30 dni symulacji przy wyłączonym boost (ranking stały) → refresh → `_rank_eval`
   bez popularności / z surową / z IPS. Czysty pomiar: kliknięcia zbierane na tym samym rankingu.
4. **Eksperyment kontrolny** (`--flat-attractiveness`), też w pętli otwartej: kliknięcia zależą tylko od pozycji.
   Oczekiwanie: IPS ≈ brak zmiany nDCG; surowa może utrwalać obecną kolejność. Jeśli w kontroli IPS też daje
   „poprawę”, dowód jest wadliwy — to jest test falsyfikujący.
5. **Pętla zamknięta (lekcja o sprzężeniu zwrotnym):** `analytics:simulate --closed-loop` po każdym symulowanym dniu
   robi refresh, a boost jest włączony — popularność wpływa na kolejny dzień kliknięć. Porównanie trajektorii nDCG
   surowa vs IPS pokazuje „bogaci się bogacą”. Refresh przy tej skali trwa sekundy, więc 30 kroków jest wykonalne.
   Okno popularności liczone względem symulowanego „dziś”, nie zegara ściennego (parametr `--as-of` w refresh).
6. Strażnik: `search:eval` na 13 starych zapytaniach przed i po; regresja = decyzja o `B` zapisana w `POMIARY.md`.

## 9. Anomalie

Kibana Alerting (licencja basic), reguły jako kod (skrypt `make analytics-setup` przez API Kibany):
- **Skok zero results:** udział `user.searched` z `total_hits = 0` w ostatnich 15 min > 3× mediana z 7 dni
  (reguła ES|QL albo „Elasticsearch query” z progiem z `search-stats-daily`).
- **Spadek CTR:** CTR ostatniej godziny < 50% CTR tej samej godziny średnio z 7 dni.
- Akcja: connector „Index” do `alerts-analytics` + „Server log”. Dowód: `analytics:simulate --live --inject=...`
  i wpis w `alerts-analytics`.

## 10. Kibana i ES|QL

- Data views `events-user-*`, `search-stats-daily`; dashboard „Wyszukiwarka — analityka” (lejek, CTR w czasie,
  CTR wg pozycji, top zero results, top frazy) eksportowany jako NDJSON do repo i importowany przez setup.
- Ściąga ES|QL w przewodniku: lejek per sesja, zero results, CTR wg pozycji, porównanie z odpowiednikiem SQL.

## 11. Podział na części

- **12a:** zdarzenia w catalogu, sesja, outbox; handler i data streamy w search-service; szablony/ILM; symulator
  (bez dowodu); Transform `search-stats-daily`; dashboard; ES|QL. Przewodnik 12a.
- **12b:** `products-v6` z `rank_feature` i pipeline enrich; `product-popularity` (surowa + IPS); komenda refresh;
  klauzula w zapytaniu; dowód i kontrola; reguły anomalii. Przewodnik 12b + `POMIARY.md` §16.

Każda część kończy się commitami i przewodnikiem; 12a i 12b mogą iść jednym PR-em albo dwoma (decyzja przy planie).

## 12. Co zostaje budującemu

- Dokładne nazwy pól ECS, rozmiary paczek bulk, η i parametry rozkładów, wartość `B` (wyznaczona pomiarem).
- Lista fraz symulatora i liczba zapytań w `popularity-queries.yaml` (rozsądnie: 8–12).
- Czy reguła zero results to ES|QL czy „Elasticsearch query” — wybór po sprawdzeniu, co wspiera zainstalowana
  wersja Kibany.
- Testy: Pest dla zdarzeń catalogu i symulatora (deterministyczny przy ziarnie), PHPUnit dla handlera (paczka,
  409 jako sukces, mapper error do DLQ), test integracyjny pipeline'u enrich na prawdziwym ES jak w ETAPIE 10.

## 13. Ryzyka

- **Kliknięcia z UI liczone podwójnie** (odświeżenie) — deterministyczne `id` kliknięcia + 409 w handlerze (sekcja 4).
- **Enrich to migawka:** po zmianie `product-popularity` bez `_execute` pipeline daje stare wartości — komenda
  refresh robi to zawsze w tej kolejności; RUNBOOK.
- **Pamięć lokalnie:** maszyna współdzielona; ~1 mln dokumentów impression to dziesiątki MB — mierzymy i ewentualnie
  ograniczamy `--days`.
