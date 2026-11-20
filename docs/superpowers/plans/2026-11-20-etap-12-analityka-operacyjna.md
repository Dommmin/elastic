# ETAP 12 — analityka operacyjna: plan implementacji

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Zdarzenia `user.*` z catalogu i symulatora trafiają przez RabbitMQ do data streamów ES. Lejek, CTR i zero results widać w Kibanie i ES|QL. Popularność z kliknięć (surowa i z korektą IPS) wraca do rankingu jako `rank_feature`, a efekt jest udowodniony w `_rank_eval` z eksperymentem kontrolnym.

**Architecture:** Catalog zapisuje zdarzenia analityczne do istniejącego outboxu; symulator publikuje je prosto do `marketplace.events`. Nowy proces `analytics-consumer` (search-service, transport `analytics`) bulkiem pisze je do `events-user-default` i `events-impression-default`. Transformy materializują `search-stats-daily` i `product-popularity`. Pipeline enrich ustawiony jako domyślny w `products-v6` dokleja popularność przy każdym zapisie produktu.

**Tech Stack:** Laravel 13 / Pest (catalog), Symfony 8.1 Messenger / PHPUnit (search), Elasticsearch + Kibana 9.5.1 (data streams, ILM, Transforms, enrich, ES|QL, Alerting), RabbitMQ, Redis, bash.

**Spec:** `docs/superpowers/specs/2026-11-20-etap-12-analityka-operacyjna-design.md` (czytaj w całości przed zadaniem).

## Global Constraints

- Repo jest publiczne: żadnych sekretów w commitach. Pliki z danymi dostępowymi (`.env`, `apps/*/.env*`, `*.key`) nie są commitowane.
- D-03: catalog nigdy nie pisze do ES. Pisze tylko search-service (rola `search_service`) i skrypty setup jako `elastic`.
- Koperta zdarzenia bez zmian: `OutboxMessageFactory::envelope` (`id`, `type`, `version`, `source`, `occurred_at`, `aggregate`, `sequence`, `data`). Dla analityki `aggregate = {type: "session", id: <mp_sid>}`, `sequence = 0`.
- Routing keys: `user.searched`, `user.clicked_result`, `user.added_to_cart`, `user.checked_out`. Kolejka `search.analytics.ingest`, transport `analytics` — topologia już istnieje, nie zmieniać.
- Data streamy: `events-user-default`, `events-impression-default`. Transformy: `search-stats-daily` (ciągły, sync `event.ingested`, delay 60s), `product-popularity` (wsadowy). Pipeline'y: `events-ingested`, `products-enrich-popularity`. Polityka enrich: `product-popularity-policy`. Mapowanie: `products-v6`.
- Cookie sesji `mp_sid`: UUID, 30 min bezczynności, przedłużane przy każdym żądaniu, przeżywa logowanie.
- Kontekst wyszukiwania w Redisie: klucz `analytics:search:<search_id>`, TTL 30 min, wartość `{query, results}`.
- Dedup kliknięcia: `data.dedup_key = "click:<search_id>:<product_id>"`; handler: `_id = data.dedup_key ?? id`.
- Popularność: surowa `(clicks + α·c̄)/(impressions + α)`, IPS `(Σ_clicks 1/p(k) + α·c̄)/(impressions + α)`, α = 20, `p(k)` szacowane z danych (CTR wg pozycji / CTR pozycji 1). Wartość zawsze > 0.
- Pipeline `products-enrich-popularity` nigdy nie odrzuca zapisu produktu (`on_failure` kasuje pola popularności).
- Boost: `SEARCH_POPULARITY_BOOST` (0 = wyłączone), tylko przy zapytaniu tekstowym.
- Kontener `catalog-app` montuje główny checkout (`apps/catalog` z `main`), nie worktree — weryfikację z worktree robić wg pamięci projektu (`kontener-montuje-glowny-checkout`). Pracujemy na gałęzi `etap-12-analityka` w głównym checkoucie.
- Maszyna współdzielona: nie zatrzymywać kontenerów `igrit-*`, `starter-*`, `blog_*`. ES z hosta na 19200.
- Nowe zmienne env dopisywać **na końcu** list env w `deploy/k8s/*.yaml` (overlay G5 łata po indeksie).
- Testy: catalog `docker compose exec catalog-app php artisan test --compact <plik>`; search `docker compose exec search-consumer php bin/phpunit <plik>`. Pint (`vendor/bin/pint --dirty --format agent`) po każdej zmianie PHP w catalogu.
- Komentarze i komunikaty po polsku, jak w reszcie repo.

## Review Focus

1. **Zapis produktu przy zepsutym enrich** (polityka usunięta, indeks enrich pusty, wartość ≤ 0) — produkt MUSI się zaindeksować bez popularności. Test w Task 8.
2. **ES lub RabbitMQ leży podczas wyszukiwania** — request catalogu kończy się normalnie (outbox to wiersz w Postgresie, Redis opcjonalny: błąd Redisa = zdarzenie bez kontekstu, nie 500). Test w Task 3.
3. **Kliknięcie bez kontekstu** (wygasły `sid`, ręcznie zmieniony `pos`, `pos` nieliczbowy) — strona produktu działa, zdarzenie bez `query` albo pominięte, nigdy 500. Test w Task 4.
4. **Zdarzenie spóźnione** (`@timestamp` sprzed checkpointu) — trafia do `search-stats-daily`. Weryfikacja w Task 6.
5. **Paginacja wyników** (strona 2+ infinite scroll) — nie emituje drugiego `user.searched`, a kliknięcie z dalszej strony ma poprawną pozycję globalną. Test w Task 3/4.

---

## CZĘŚĆ 12a — zdarzenia, ingest, statystyki

### Task 1: Infrastruktura ES pod zdarzenia

**Files:**
- Create: `infra/elasticsearch/analytics/` — `ilm-events-user.json`, `template-events-user.json`, `template-events-impression.json`, `pipeline-events-ingested.json`
- Create: `infra/elasticsearch/setup-analytics.sh` (idempotentny, jako `elastic`, wzór: `setup-snapshots.sh`)
- Modify: `infra/elasticsearch/setup-security.sh` (rola `search_service`: cluster `+manage_transform`, `+manage_enrich`; indeksy `+product-popularity*`)
- Modify: `Makefile` (target `analytics-setup`)

**Interfaces:**
- Produces: data streamy `events-user-default`, `events-impression-default` z mapowaniem; pipeline `events-ingested` ustawiający `event.ingested` (`{{_ingest.timestamp}}`).

- [ ] **Step 1:** Mapowanie `events-user` (`dynamic: strict`): `@timestamp` date, `event.id` / `event.type` keyword, `event.ingested` date, `session.id`, `user.id`, `search.id`, `search.query` keyword (+ `search.query_raw` text), `search.total_hits` integer, `search.results` keyword (tablica ID), `search.sort` keyword, `search.filters` flattened, `product.id`, `offer.id` keyword, `click.position` integer, `order.ids` keyword, `order.amount_cents` long, `trace.id` keyword. `events-impression`: `@timestamp`, `event.ingested`, `search.id`, `search.query`, `session.id`, `product.id`, `impression.position`.
- [ ] **Step 2:** ILM `events-user`: hot rollover `max_age 1d` / `max_primary_shard_size 1gb`, delete po `90d`; oba szablony używają tej polityki i `index.default_pipeline: events-ingested`, 1 shard, 1 replika (0 w trybie single-node — jak istniejące szablony).
- [ ] **Step 3:** `setup-analytics.sh`: PUT ILM, pipeline, szablony; `PUT _data_stream/<nazwa>` gdy brak. Uruchomienie dwa razy z rzędu kończy się sukcesem.
- [ ] **Step 4:** Uprawnienia w `setup-security.sh`; uruchom `make analytics-setup` i `bash infra/elasticsearch/setup-security.sh`.
- [ ] **Step 5: Weryfikacja**
  Run: `curl -sk -u elastic:$ELASTIC_PASSWORD https://localhost:19200/_data_stream/events-*?pretty | grep -E '"name"|ilm_policy'` → oba streamy, polityka `events-user`.
  Run: dokument testowy przez `_doc?op_type=create` jako `searchsvc` → ma `event.ingested`; drugi zapis z tym samym `_id` → 409. Skasuj dokument testowy.
- [ ] **Step 6: Commit** `feat(analytics): data streamy events-user/impression, ILM, pipeline event.ingested`

### Task 2: search-service — `analytics-consumer`

**Files:**
- Create: `apps/search/src/Analytics/AnalyticsDocumentMapper.php`, `apps/search/src/MessageHandler/AnalyticsEventHandler.php`, `apps/search/src/Analytics/AnalyticsBulkWriter.php`
- Test: `apps/search/tests/Unit/Analytics/AnalyticsDocumentMapperTest.php`, `apps/search/tests/Unit/Analytics/AnalyticsBulkWriterTest.php`
- Modify: `compose.yaml` (usługa `analytics-consumer` = obraz search, `messenger:consume analytics`, te same env co `search-consumer`), `compose.prod.yaml`, `deploy/k8s/analytics-consumer.yaml` + `kustomization.yaml`, `tools/k3s/manifests-check.sh` jeśli wymaga listy

**Interfaces:**
- Consumes: `App\Message\IntegrationEvent` (istniejący; serializer `ExternalJsonEnvelopeSerializer`).
- Produces: `AnalyticsDocumentMapper::map(IntegrationEvent $e): list<array{index: string, id: string, doc: array}>` — dla `user.searched` zwraca dokument zdarzenia + po jednym `impression` na produkt z `results` (pozycja 1-based, `_id` = `<search_id>:<pos>`); `AnalyticsBulkWriter::write(list $items): BulkOutcome{created:int, duplicates:int, rejected:list<string>, retryable:list<string>}`.

- [ ] **Step 1: Testy mappera:** `user.searched` z 3 wynikami → 4 dokumenty, impression `position` 1..3; `user.clicked_result` z `dedup_key` → `id` = dedup_key; bez → `id` = id koperty; `@timestamp` = `occurred_at`; nieznany typ → `[]`.
- [ ] **Step 2: Testy writera** (mock klienta ES, wzór `ElasticsearchIndexerTest`): 409 liczone jako `duplicates`, nie błąd; `mapper_parsing_exception` → `rejected`; 429/5xx → `retryable`; ciało `_bulk` używa `create` z `_index` = data stream.
- [ ] **Step 3:** Uruchom — FAIL (brak klas).
- [ ] **Step 4:** Implementacja. `AnalyticsEventHandler` — `#[AsMessageHandler(fromTransport: 'analytics')]`, `BatchHandlerInterface` + `BatchHandlerTrait`, `getBatchSize()` = 500. Po zapisie: `retryable` niepuste → ack z wyjątkiem (`$ack->nack(new RecoverableMessageHandlingException)`) dla wiadomości, których dotyczą; `rejected` → `UnrecoverableMessageHandlingException` dla tych wiadomości; reszta `ack`. Log podsumowania partii (created/duplicates/rejected).
- [ ] **Step 5:** Testy PASS. Uruchom usługę: `docker compose up -d analytics-consumer`; `make mq-status` pokazuje konsumenta na `search.analytics.ingest`.
- [ ] **Step 6:** Weryfikacja end-to-end: `make mq-publish rk=user.searched` z kopertą testową (skrypt lub `rabbitmqadmin publish` z pełną kopertą) → dokument w `events-user-default` i impressions w `events-impression-default`.
- [ ] **Step 7:** `tools/k3s/manifests-check.sh` exit 0, `tools/k3d/g5-check.sh` OK.
- [ ] **Step 8: Commit** `feat(analytics): analytics-consumer — bulk create do data streamów, dedup przez 409`

### Task 3: catalog — sesja, recorder, `user.searched`

**Files:**
- Create: `apps/catalog/app/Http/Middleware/AnalyticsSession.php`, `apps/catalog/app/Analytics/AnalyticsRecorder.php`, `apps/catalog/app/Analytics/SearchContextStore.php`
- Modify: `apps/catalog/bootstrap/app.php` (middleware w grupie web i api), `apps/catalog/app/Http/Controllers/SearchController.php`, `apps/catalog/resources/js/pages/Search.vue`, `apps/catalog/resources/js/components/search/ProductCard.vue`
- Test: `apps/catalog/tests/Feature/Analytics/SearchedEventTest.php`, `apps/catalog/tests/Feature/Analytics/AnalyticsSessionTest.php`

**Interfaces:**
- Produces:
  - `AnalyticsSession::currentId(Request $r): string` (statyczny helper czytający atrybut requestu `analytics_session_id`).
  - `AnalyticsRecorder::record(string $type, string $sessionId, array $data, ?CarbonImmutable $occurredAt = null): void` — `Outbox::create` z `aggregate_type = 'session'`, `sequence = 0`, `event_id` ULID, `trace_id`.
  - `SearchContextStore::put(string $searchId, string $query, array $results): void`, `get(string $searchId): ?array{query: string, results: list<int>}` — błędy Redisa łapane i logowane (zwraca `null`).
  - Prop Inertia `searchId` (string|null) na stronie `Search`; `ProductCard` dostaje `position` i buduje link `products.show` z `?sid=&pos=`.

- [ ] **Step 1: Testy sesji:** pierwsze żądanie ustawia cookie `mp_sid` (UUID); kolejne z cookie zachowuje wartość; logowanie nie zmienia `mp_sid`.
- [ ] **Step 2: Testy `user.searched`:** `GET /search?q=buty` → jeden wiersz outbox `user.searched` z `query = "buty"` (znormalizowane: trim + lowercase), `total_hits`, `results` ≤ 20 w kolejności, `search_id` = prop `searchId`; kontekst w Redisie; żądanie z kursorem (strona 2) → brak nowego wiersza; `GET /api/search?q=x` też emituje; awaria Redisa (mock rzucający) → 200 i wiersz outbox nadal jest; zapytanie bez wyników → `total_hits = 0`, `results = []`.
- [ ] **Step 3:** FAIL → implementacja → PASS. Pozycja w `ProductCard` = globalny indeks wyniku (licząc poprzednie strony infinite scroll), 1-based.
- [ ] **Step 4:** Pint; `npm run build` w kontenerze vite nie jest wymagany do testów, ale sprawdź `vue-tsc` (`docker compose exec catalog-vite npx vue-tsc --noEmit`; Wayfinder z `--with-form` wg pamięci projektu).
- [ ] **Step 5: Commit** `feat(analytics): sesja mp_sid, AnalyticsRecorder przez outbox, zdarzenie user.searched`

### Task 4: catalog — kliknięcie, koszyk, checkout

**Files:**
- Modify: `apps/catalog/app/Http/Controllers/Shop/ProductPageController.php`, `apps/catalog/app/Http/Controllers/Shop/CartController.php`, `apps/catalog/resources/js/pages/shop/Product.vue` (przenosi `sid` do formularza koszyka)
- Modify: `apps/catalog/app/Http/Requests/*/AddToCartRequest.php` (opcjonalne `sid`, string, max 64)
- Test: `apps/catalog/tests/Feature/Analytics/FunnelEventsTest.php`

**Interfaces:**
- Consumes: `AnalyticsRecorder::record`, `SearchContextStore::get`, `AnalyticsSession::currentId` (Task 3).

- [ ] **Step 1: Testy:** `GET /products/{id}?sid=S&pos=3` z kontekstem S → `user.clicked_result` z `query`, `position = 3`, `dedup_key = "click:S:{id}"`; bez kontekstu → zdarzenie bez `query`; `pos=abc` lub brak `sid` → brak zdarzenia, 200; `POST cart/items` z `sid` → `user.added_to_cart` z `search_id` i `query`; bez `sid` → zdarzenie bez nich; `POST cart/checkout` → jeden `user.checked_out` z listą `order_ids` (kilku sprzedawców = kilka ID), `product_ids`, `amount_cents`.
- [ ] **Step 2:** FAIL → implementacja → PASS. Checkout: zdarzenie zapisane po udanym `PlaceOrdersFromCartHandler`, nie w jego transakcji (granica kontekstu Ordering).
- [ ] **Step 3:** Pint, `vue-tsc`, pełny pakiet testów catalogu (`php artisan test --compact`) zielony.
- [ ] **Step 4: Weryfikacja na żywo:** klik w UI przez `/search` → produkt → koszyk (zalogowany) → checkout; w Discover / `_search` na `events-user-*` cztery typy zdarzeń z tym samym `session.id`; odświeżenie strony produktu nie dodaje drugiego kliknięcia.
- [ ] **Step 5: Commit** `feat(analytics): zdarzenia clicked_result, added_to_cart, checked_out`

### Task 5: catalog — symulator `analytics:simulate`

**Files:**
- Create: `apps/catalog/app/Analytics/Simulation/ClickModel.php`, `QueryPool.php`, `Attractiveness.php`, `SimulatedEventPublisher.php`
- Create: `apps/catalog/app/Console/Commands/AnalyticsSimulateCommand.php`
- Modify: `Makefile` (target `simulate`)
- Test: `apps/catalog/tests/Unit/Analytics/ClickModelTest.php`, `apps/catalog/tests/Unit/Analytics/AttractivenessTest.php`, `apps/catalog/tests/Feature/Analytics/SimulateCommandTest.php`

**Interfaces:**
- Produces:
  - `Attractiveness::for(int $productId, int $seed): float` ∈ (0,1), deterministyczna (hash ID + ziarno → Beta(2,5) przez odwrotną dystrybuantę lub sumę uniformów; `--flat-attractiveness` → stała 0.3).
  - `ClickModel::clicks(list<int> $results, int $seed, float $eta, float $noise): list<int>` — pozycje kliknięte, `P(k) = (1/k^eta) · a`.
  - `analytics:simulate {--days=30} {--sessions-per-day=300} {--seed=1} {--eta=1} {--noise=0.05} {--flat-attractiveness} {--inject=} {--live} {--day=} {--as-of=}` — `--day=N` symuluje tylko dzień N (dla pętli zamkniętej w Task 11).
  - `SimulatedEventPublisher` — php-amqplib, exchange `marketplace.events`, publisher confirms, paczki po 500, ta sama koperta co `OutboxMessageFactory` (wydziel `envelope()` do publicznej metody lub współdzielonej klasy — bez zmiany kształtu).

- [ ] **Step 1: Testy:** ta sama para (ziarno, ID) → ta sama atrakcyjność; średnia `Attractiveness` dla 10k ID ≈ 0.286 ± 0.02; `ClickModel` przy `eta=1` i stałej atrakcyjności → CTR pozycji 1 ≈ 2× CTR pozycji 2 (10k prób, tolerancja 10%); przy ziarnie wynik deterministyczny; komenda z `--days=1 --sessions-per-day=5` i podmienionym publisherem (fake w kontenerze) → zdarzenia wszystkich 4 typów, `occurred_at` w obrębie wskazanego dnia, kliknięcia mają `dedup_key`; `--inject=zero-results` → w ostatniej godzinie ≥ 50% wyszukań ma `total_hits = 0`.
- [ ] **Step 2:** `QueryPool` — frazy z kategorii, marek i słów nazw seeda, wagi Zipf (s = 1.1), ~5% fraz spoza katalogu; ranking z `ProductSearchService::search()` (prawdziwy). Wyniki wyszukiwań cache'owane per fraza w obrębie przebiegu (stały ranking w pętli otwartej, szybciej).
- [ ] **Step 3:** FAIL → implementacja → PASS. Pint.
- [ ] **Step 4: Weryfikacja:** `make simulate days=30` → liczby zdarzeń w `events-user-*` per typ (`_count` z `term` na `event.type`) zgodne z podsumowaniem komendy (± duplikaty); czas przebiegu zapisany do `POMIARY.md`.
- [ ] **Step 5: Commit** `feat(analytics): symulator klienta z ukrytą atrakcyjnością i biasem pozycji`

### Task 6: `search-stats-daily`, Kibana, ES|QL, przewodnik 12a

**Files:**
- Create: `infra/elasticsearch/analytics/transform-search-stats-daily.json`, `infra/kibana/analytics-dashboard.ndjson`
- Modify: `infra/elasticsearch/setup-analytics.sh` (transform + start; import NDJSON przez Kibana `_import`), `Makefile`
- Create: `docs/blog/etap-12a-zdarzenia-i-lejek.md`; Modify: `docs/RUNBOOK.md` (nowe wpisy od #128), `docs/POMIARY.md` (§16)

- [ ] **Step 1:** Transform pivot: `group_by` `day: date_histogram(@timestamp, 1d)`, `query: terms(search.query)`; agregacje: `searches` (filter `event.type: user.searched`), `zero_results` (filter + `total_hits: 0`), `sessions` (cardinality `session.id`), `clicks` (filter `user.clicked_result`), `avg_click_position`, `ctr` (`bucket_script` clicks/searches); `sync.time.field: event.ingested`, `delay: 60s`, `frequency: 1m`; `dest: search-stats-daily`.
- [ ] **Step 2: Weryfikacja late events:** po starcie transformu dopisz zdarzenie z `@timestamp` sprzed 3 dni → po ≤ 2 min pojawia się w kubełku tamtego dnia.
- [ ] **Step 3:** Dashboard „Wyszukiwarka — analityka” (lejek: liczba sesji z ≥1 zdarzeniem każdego typu; CTR w czasie; CTR wg `click.position`; top `search.query` z `zero_results`; top frazy), zapisz NDJSON, import przez setup.
- [ ] **Step 4:** Zapytania ES|QL w przewodniku (każde uruchomione na żywych danych, wyniki wklejone): lejek per sesja (`STATS ... BY session.id` → zliczenia etapów), zero results, CTR wg pozycji (`events-impression` vs kliknięcia), porównanie z SQL.
- [ ] **Step 5:** Przewodnik 12a krok po kroku (wzór: `etap-10b-…`): problem, decyzje, każdy komponent z komendą weryfikacji, liczby z żywego stosu, ściąga. RUNBOOK dla każdego realnego błędu napotkanego w 12a.
- [ ] **Step 6: Commit** `feat(analytics): transform search-stats-daily, dashboard, ES|QL` oraz `docs: przewodnik 12a`

---

## CZĘŚĆ 12b — pętla do rankingu, dowód, anomalie

### Task 7: Wspólna część punktująca zapytania + `rank_feature`

**Files:**
- Modify: `apps/catalog/app/Services/ProductSearchService.php`, `apps/catalog/config/services.php` (`search.popularity_boost` ← `SEARCH_POPULARITY_BOOST`, domyślnie 0), `compose.yaml`, `.env.example`
- Test: `apps/catalog/tests/Unit/Search/ScoringQueryTest.php` (lub istniejący plik testów `ProductSearchService`)

**Interfaces:**
- Produces: `ProductSearchService::buildScoringQuery(string $q): array` — `bool{must: [buildTextQuery($q)], should: [rank_feature]}` gdy `$q !== ''` i boost > 0; w przeciwnym razie samo `buildTextQuery($q)`. Używana przez `search()`, `priceHistogram()`, `buildSearchQuery()`.

- [ ] **Step 1: Testy:** boost 0 → ciało `search()` identyczne jak przed zmianą (snapshot obecnego ciała); boost 2 i `q='buty'` → `should` zawiera `rank_feature{field: popularity, saturation: {}, boost: 2}`; `q=''` → brak `rank_feature`; ciało z `buildSearchQuery()` i z `search()` mają tę samą część punktującą (porównanie po wyjęciu).
- [ ] **Step 2:** FAIL → refaktor → PASS. Pełny pakiet catalogu zielony; `make eval` daje te same liczby co przed zmianą (boost 0).
- [ ] **Step 3: Commit** `refactor(search): jedna część punktująca dla search/eval + rank_feature za flagą`

### Task 8: `products-v6` i pipeline enrich

**Files:**
- Create: `infra/elasticsearch/mappings/products-v6.json` (= v5 + `popularity: rank_feature`, `popularity_raw: float`, `popularity_ips: float`, `settings.index.default_pipeline: products-enrich-popularity`)
- Create: `infra/elasticsearch/analytics/enrich-policy-product-popularity.json`, `pipeline-products-enrich-popularity.json`, `index-product-popularity.json`
- Modify: `infra/elasticsearch/setup-analytics.sh`
- Test: `apps/search/tests/Integration/PopularityPipelineTest.php` (prawdziwy ES, wzór testów integracyjnych ETAPU 10)

**Interfaces:**
- Produces: indeks `product-popularity` z polami `product_id` (keyword), `popularity_raw`, `popularity_ips` (float), `popularity` (float — wariant wybrany przy refresh), `impressions`, `clicks`; pipeline dokleja `popularity`, `popularity_raw`, `popularity_ips` z dopasowania `_id`.

- [ ] **Step 1: Testy integracyjne:** z wpisem w enrich → dokument ma `popularity`; bez wpisu → brak pól, zapis OK; polityka usunięta / indeks enrich nieistniejący → zapis OK bez pól; wartość 0 w źródle → pole pominięte (`rank_feature` wymaga > 0), zapis OK.
- [ ] **Step 2:** Pipeline: `enrich{policy_name, field: "_id", target_field: "_pop", ignore_missing: true}` → `set` z warunkiem `ctx._pop?.popularity != null && ctx._pop.popularity > 0` → `remove _pop`; `on_failure` na poziomie pipeline'u: `remove` pól popularności i `_pop` z `ignore_missing`.
- [ ] **Step 3:** FAIL → implementacja → PASS.
- [ ] **Step 4:** Migracja na żywym stosie narzędziami ETAPU 10: `make reindex` (v5 → v6) z bramką; po switchu `make eval` = wynik sprzed migracji (± niedeterminizm remisów, RUNBOOK #096); `make churn rate=10 duration=10` + `search:verify-convergence` → `mismatches=0`.
- [ ] **Step 5: Commit** `feat(search): products-v6 z rank_feature popularity i pipeline enrich, który nie odrzuca zapisów`

### Task 9: `product-popularity` i `search:popularity:refresh`

**Files:**
- Create: `apps/search/src/Analytics/PopularityRefresher.php`, `apps/search/src/Command/PopularityRefreshCommand.php`, `apps/search/src/Analytics/PositionPropensity.php`
- Test: `apps/search/tests/Unit/Analytics/PositionPropensityTest.php`, `apps/search/tests/Unit/Analytics/PopularityFormulaTest.php`, `apps/search/tests/Integration/PopularityRefreshTest.php`
- Modify: `Makefile` (`popularity-refresh variant=ips as_of=...`)

**Interfaces:**
- Produces: `search:popularity:refresh {--variant=ips|raw} {--as-of=} {--window-days=30} {--alpha=20} {--rps=-1}`; kroki: (1) policz `p(k)` z kliknięć/wyświetleń w oknie, (2) przelicz `product-popularity` (transform wsadowy `product-popularity` usunięty i odtworzony z oknem `[as_of − window, as_of)` albo agregacja + bulk — wybór implementującego, transform preferowany dla lekcji), (3) `_execute` polityki enrich (czekaj na zakończenie), (4) `_update_by_query` na `products-write` z `pipeline=products-enrich-popularity`, `conflicts=proceed`, w tle, czekanie przez `TaskWaiter`.
- `PositionPropensity::fromCounts(array<int, array{impressions:int, clicks:int}>): array<int, float>` — `p(k) = ctr(k)/ctr(1)`, monotonicznie nierosnące (izotoniczna korekta), `p ≥ 0.01`.

- [ ] **Step 1: Testy:** propensity z danych `ctr(1)=0.2, ctr(2)=0.1` → `p(2)=0.5`; szum (ctr(5) > ctr(4)) wygładzony do monotonicznego; formuła: 0 kliknięć, 0 wyświetleń → `c̄` (> 0); integracja: po refresh produkt z kliknięciami ma `popularity` w `products-search`, `--variant=raw` zmienia wartość `popularity` na `popularity_raw`.
- [ ] **Step 2:** FAIL → implementacja → PASS.
- [ ] **Step 3: Commit** `feat(search): product-popularity (surowa i IPS) i komenda search:popularity:refresh`

### Task 10: Eval z tasowaniem remisów i zestaw `popularity-queries`

**Files:**
- Modify: `apps/catalog/app/Console/Commands/SearchEvalCommand.php` (`--tie-shuffles=0`, `--queries=` ścieżka pliku, domyślnie obecny), `apps/catalog/app/Services/Search/RelevanceJudgments.php` (selektor `ids: {id: grade}` dla jawnych ocen)
- Create: `apps/catalog/app/Console/Commands/AnalyticsJudgmentsCommand.php` (`analytics:judgments {--seed=1} {--queries=10}` → zapisuje `tests/relevance/popularity-queries.yaml`)
- Create: `tests/relevance/popularity-queries.yaml` (wygenerowany, commitowany)
- Test: `apps/catalog/tests/Feature/Search/SearchEvalTieShufflesTest.php`, `apps/catalog/tests/Feature/Analytics/JudgmentsCommandTest.php`

**Interfaces:**
- Consumes: `Attractiveness::for` (Task 5), `buildSearchQuery` (Task 7).
- Produces: wyjście evala z `--tie-shuffles=N`: per zapytanie i łącznie `mean`, `min`, `max` nDCG@k; przebieg `i` dokłada do zapytania `function_score{random_score{seed: i, field: "_seq_no"}, weight: 1e-6, boost_mode: sum}`.

- [ ] **Step 1: Testy:** `--tie-shuffles=5` → 5 przebiegów, wypisane mean/min/max; przy braku remisów min = max; `analytics:judgments` wybiera frazy tekstowe, których top 50 ma ≥ 20 produktów z tym samym `_score` (największe grupy remisu), oceny = `floor(a · 4)` obcięte do 0–3, plik deterministyczny przy ziarnie.
- [ ] **Step 2:** FAIL → implementacja → PASS. Pint.
- [ ] **Step 3: Commit** `feat(eval): --tie-shuffles, jawne oceny ids i zestaw popularity-queries z prawdy symulatora`

### Task 11: Pętla zamknięta — orkiestracja

**Files:**
- Create: `tools/analytics/closed-loop.sh` (dla dnia 1..N: `analytics:simulate --day=d --as-of=...` w catalog-app → `search:popularity:refresh --as-of=...` w search-consumer → `search:eval --queries=… --tie-shuffles=10`; zapis CSV `dzień,wariant,ndcg_mean,min,max`)
- Modify: `Makefile` (`closed-loop variant=raw|ips days=30`)

- [ ] **Step 1:** Skrypt czyści `events-*` (tylko streamy analityczne, z potwierdzeniem w terminalu jak `reindex-cleanup`) przed przebiegiem, ustawia `SEARCH_POPULARITY_BOOST` na czas przebiegu przez `docker compose exec -e`.
- [ ] **Step 2:** Test dymny: `make closed-loop days=2` kończy się, CSV ma 2 wiersze.
- [ ] **Step 3: Commit** `feat(analytics): pętla zamknięta symulacja → refresh → eval`

### Task 12: Eksperymenty i wybór `B`

**Files:** Modify: `docs/POMIARY.md` (§16), `compose.yaml` / `.env.example` (ustalone `B`)

- [ ] **Step 1:** Pętla otwarta: wyczyść streamy → `make simulate days=30` (boost 0) → `make popularity-refresh variant=raw` → eval `popularity-queries` z `--tie-shuffles=20` dla boost 0 / raw / ips (refresh `variant=ips`), dla `B ∈ {0.5, 1, 2, 4}`.
- [ ] **Step 2:** Kontrola: to samo z `--flat-attractiveness`. Kryterium falsyfikacji zapisane: IPS w kontroli |Δ nDCG| < rozrzut min–max baseline'u.
- [ ] **Step 3:** Pętla zamknięta `variant=raw` i `variant=ips`, 30 dni; wykres trajektorii (tabela lub PNG w `docs/blog/img/`).
- [ ] **Step 4:** Strażnik: `make eval` (13 starych zapytań) dla wybranego `B`. Wybór `B` z uzasadnieniem w POMIARY.
- [ ] **Step 5: Commit** `docs(pomiary): ETAP 12 — dowód popularności, kontrola, pętla zamknięta`

### Task 13: Reguły anomalii

**Files:**
- Create: `infra/kibana/rules/zero-results-spike.json`, `ctr-drop.json`, connector „Index” do `alerts-analytics` + „Server log”
- Modify: `infra/elasticsearch/setup-analytics.sh` (tworzy connectory i reguły przez Kibana API, idempotentnie po nazwie)

- [ ] **Step 1:** Sprawdź w zainstalowanej Kibanie, czy reguła ES|QL jest dostępna w basic; jeśli tak — ES|QL, jeśli nie — „Elasticsearch query” z DSL. Zapisz wybór w przewodniku.
- [ ] **Step 2:** Reguły wg specu §9; harmonogram co 1 min, okna jak w specu.
- [ ] **Step 3: Weryfikacja:** `analytics:simulate --live --inject=zero-results` → w ≤ 5 min dokument w `alerts-analytics`; to samo `--inject=ctr-drop`; bez wstrzyknięcia przez 10 min — brak alertów.
- [ ] **Step 4: Commit** `feat(analytics): reguły anomalii zero results i spadku CTR w Kibana Alerting`

### Task 14: Przewodnik 12b i domknięcie

**Files:** Create: `docs/blog/etap-12b-popularnosc-w-rankingu.md`; Modify: `docs/RUNBOOK.md`, `docs/06-PLAN-WDROZENIA.md` (ETAP 12 oznaczony jako zrobiony z linkami), `docs/01-INFRASTRUKTURA.md` (nowe usługi), pamięć projektu (`etap-12-analityka-stan.md` + MEMORY.md)

- [ ] **Step 1:** Przewodnik krok po kroku: enrich i dlaczego pipeline nie może odrzucać, IPS i dlaczego suma to zła formuła, tasowanie remisów, wyniki z POMIARÓW z interpretacją (także jeśli dowód wyszedł słabszy niż oczekiwano — wtedy uczciwie dlaczego), reguły anomalii, ograniczenia.
- [ ] **Step 2:** Pełne pakiety testów: catalog zielony; search — tylko 8 znanych błędów `ErrorTracking`.
- [ ] **Step 3: Commit** `docs: przewodnik 12b, RUNBOOK, plan wdrożenia`
