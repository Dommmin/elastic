# ETAP 15 — Detekcja nadużyć sprzedawców: plan wdrożenia

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** pięć reguł detekcji nadużyć (percolator ×2, Kibana ES|QL, EQL, agregacje) na strumieniu
`events-security` zasilanym ze sklepu i z symulatora, z raportem TP / FP / FN na zbiorze z ziarnem.

**Architecture:** catalog (Laravel) emituje `review.created` z kontekstem klienta (hasz IP, prefiks,
user-agent, wiek konta) i istniejące `offer.price_changed` przez outbox → RabbitMQ
`search.security.ingest` → `SecurityEventHandler` (Symfony) zapisuje do `events-security-default`
i percoluje zapisane dokumenty względem `security-rules`. Reguły okienkowe liczy `security:detect`
(EQL, agregacje, backfill ES|QL), cyklicznie jako job `seller360-scheduler`. Wszystkie alerty w
`alerts-security`; `security:report` porównuje je z `planted-security.json`.

**Tech Stack:** Elasticsearch 9.x (basic), Kibana Alerting `.es-query` ES|QL, Laravel 13 + Pest,
Symfony + PHPUnit, RabbitMQ (quorum), Docker Compose.

**Spec:** `docs/superpowers/specs/2026-11-25-etap-15-detekcja-naduzyc-design.md`

## Global Constraints

- Repo publiczne: `SECURITY_IP_PEPPER` tylko w `.env` / `apps/*/.env*`; w `.env.example` pusty `SECURITY_IP_PEPPER=`.
- Surowe IP nigdy w Postgresie, outboxie, RabbitMQ ani ES. Hasz = pierwsze 16 hex z HMAC-SHA256(IP, pepper); prefiks = `/24` IPv4, `/48` IPv6, zapis tekstowy (`203.0.113.0/24`, `2001:db8:1::/48`).
- Pusty pepper → pola IP null + log warning; recenzja zapisuje się.
- Reguły ceny widzą tylko `offer.price_changed`. `change_pct = round((new − old) / old × 100, 2)`, ze znakiem.
- `price-hug-threshold`: `25 ≤ change_pct ≤ 30` i `product.popularity ≥ p95` (p95 z `product-popularity`, liczony przy ładowaniu reguł, zapisany w regule i w `params.p95`).
- `fresh-account-5star`: `review.rating = 5` i `user.account_age_hours < 24`.
- `review-ip-burst`: ≥ 5 różnych `user.id` z jednym `client.ip_hash` w kubełku 1 h.
- `price-bait-and-switch`: `sequence by offer.id with maxspan=48h` [obniżka `change_pct ≤ −15`] → [`user.clicked_result`] `with runs=20` → [podwyżka `change_pct > 0`]; post-filtr: każda zmiana w sekwencji `|change_pct| ≤ 30` i cena końcowa ≥ 1,3 × cena sprzed obniżki (`old_price_cents` obniżki).
- `complaints-spike`: per sprzedawca dzienny `date_histogram` + `moving_fn` z 7 poprzednich dni (dzień nie liczy sam siebie); alert, gdy dzień ≥ średnia + 3σ i ≥ 5 reklamacji.
- Klucz alertu: `rule.id` + `entity.type` + `entity.id` + `window.start`; `_id` zapisów z Symfony = `sha1("{rule.id}|{entity.type}|{entity.id}|{window.start}")`.
- Nazwy: data stream `events-security-default` (template `events-security`, ILM `events-security` delete 30d), indeksy `security-rules`, `alerts-security`, kolejka `search.security.ingest` + `search.security.ingest.dlq`, transport Messenger `security`.
- Bez nowych kontenerów: transport `security` konsumuje `analytics-consumer` (`messenger:consume analytics security`), `security:detect` to job `seller360-scheduler` co 300 s.
- Nie psuć: `make seller360-verify` 5/5, reguły `zero-results-spike` / `ctr-drop`, pełne suity catalog (`cd apps/catalog && php artisan test --compact`, na hoście) i search (`docker compose exec search-consumer php vendor/bin/phpunit`).
- Testy catalog na hoście (SQLite w pamięci), nigdy w kontenerze (RUNBOOK #018, #026).
- Commit trailer: `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. Recenzja wysłana dwa razy przez tego samego użytkownika na ten sam produkt → druga odrzucona błędem walidacji, bez drugiego wiersza i bez drugiego zdarzenia outboxu (Task 3).
2. IPv6 i adresy mapowane IPv4 (`::ffff:203.0.113.7`) → prefiks liczony jak dla IPv4 `/24` (Task 2).
3. `offer.price_changed` z `old_price_cents = 0` → `change_pct = null`, zdarzenie zapisane, żadna reguła ceny nie strzela, brak dzielenia przez zero (Task 4).
4. Zdarzenie dla produktu spoza `product-popularity` → `product.popularity` brak, zapis przechodzi, `price-hug-threshold` nie strzela (Task 4/5).
5. Ponowny `security:detect --since=15d` → liczba dokumentów w `alerts-security` się nie zmienia (Task 6–8).

---

### Task 1: Infrastruktura ES i RabbitMQ

**Files:**
- Create: `infra/elasticsearch/detection/template-events-security.json`, `ilm-events-security.json`, `pipeline-events-security.json`, `index-security-rules.json`, `index-alerts-security.json`, `infra/elasticsearch/setup-detection.sh`
- Modify: `infra/rabbitmq/definitions.template.json`, `Makefile` (target `detection-setup`)

**Interfaces:**
- Produces: data stream `events-security-default` z `index.default_pipeline: events-security`; indeksy `security-rules`, `alerts-security`; kolejka `search.security.ingest`; funkcje `setup-detection.sh` sekcjami (`[1]` ES, `[2]` reguły percolatora, `[3]` Kibana) — sekcje 2 i 3 dopisują Task 5 i Task 6.

- [ ] **Step 1:** Template `events-security` (`index_patterns: ["events-security-*"]`, `data_stream: {}`, priorytet 200, 1 shard / 1 replika jak `template-events-user.json`, `dynamic: strict`). Pola dokładnie ze specu §5.2: `@timestamp` date; `event.{id,type}` keyword, `event.ingested` date; `offer.id` keyword, `offer.old_price_cents`/`new_price_cents` long, `offer.change_pct` scaled_float (factor 100); `product.id` keyword, `product.popularity` double; `seller.id`, `user.id`, `review.id` keyword; `user.account_age_hours` integer; `review.rating` byte; `client.ip_hash` keyword, `client.ip_prefix` keyword; `user_agent.original` keyword (`ignore_above: 256`); `trace.id` keyword; `labels.synthetic` boolean.
- [ ] **Step 2:** ILM `events-security`: hot (rollover 1d / 1 GB), delete `min_age: 30d`. Pipeline `events-security`: `set event.ingested`, `enrich` (`policy_name: product-popularity-policy`, `field: product.id`, `target_field: _pop`, `ignore_missing: true`, `max_matches: 1`), `set product.popularity` z `_pop.popularity` gdy istnieje, `remove _pop`; `on_failure` usuwa `_pop` i przepuszcza dokument (nigdy nie odrzuca zdarzenia).
- [ ] **Step 3:** `security-rules`: mapowanie = te same `properties` co template (percolator musi znać pola) + `query` typu `percolator` + `rule.{id,kind,severity}` keyword, `rule.description` text, `params` `flattened`. `alerts-security`: pola ze specu §7 (`evidence` `flattened`, `dynamic: strict`).
- [ ] **Step 4:** `setup-detection.sh` na wzór `setup-analytics.sh` (te same helpery `log`/`die`/`esa`/`kba`/`rule` — skopiowane lub wydzielone do wspólnego pliku `infra/elasticsearch/lib-setup.sh`, decyzja implementującego, bez zmiany zachowania `setup-analytics.sh`). Idempotentny: template/ILM/pipeline `PUT`, indeksy tworzone gdy brak, data stream gdy brak. Wymaga istniejącej polityki enrich `product-popularity-policy` → jasny błąd „najpierw make analytics-setup”.
- [ ] **Step 5:** RabbitMQ: kolejka `search.security.ingest` (quorum, `x-dead-letter-exchange: marketplace.dlx`, `x-delivery-limit: 5`), DLQ `search.security.ingest.dlq` (quorum), bindingi z `marketplace.events` dla `offer.price_changed` i `review.created` oraz z `marketplace.dlx` do DLQ z tymi samymi kluczami. Wygenerować `definitions.json` (`python3 tools/render-rabbitmq-definitions.py`) i załadować do brokera tak, jak robi to istniejący target (np. restart rabbitmq / import definicji — jak w Makefile :55–69).
- [ ] **Step 6: Weryfikacja.** `make detection-setup` dwa razy z rzędu → oba przebiegi exit 0. `curl` (przez `esa`) `GET _data_stream/events-security-default` → istnieje; `POST events-security-default/_doc?refresh=true` z dokumentem bez `product.id` → 201; z nieznanym polem → 400 (strict). `rabbitmqadmin list bindings` (nie `get`!) pokazuje oba bindingi.
- [ ] **Step 7: Commit** `feat(detekcja): infrastruktura events-security, security-rules, alerts-security`.

### Task 2: Odcisk klienta w catalog

**Files:**
- Create: `apps/catalog/app/Security/ClientFingerprint.php`, `apps/catalog/tests/Unit/Security/ClientFingerprintTest.php`
- Modify: `apps/catalog/config/services.php` (`'security' => ['ip_pepper' => env('SECURITY_IP_PEPPER')]`), `.env.example`, `compose.yaml` (`catalog-app` env `SECURITY_IP_PEPPER: ${SECURITY_IP_PEPPER:-}`)

**Interfaces:**
- Produces: `final class ClientFingerprint { public function __construct(?string $pepper) ; public function hash(string $ip): ?string ; public static function prefix(string $ip): ?string ; public static function userAgent(?string $ua): ?string }` — `hash` null przy pustym pepperze lub niepoprawnym IP; `prefix` null przy niepoprawnym IP; `userAgent` obcina do 256 znaków. Rejestracja w kontenerze z `config('services.security.ip_pepper')`.

- [ ] **Step 1: Testy (Pest):** `hash('203.0.113.7')` ma 16 znaków `[0-9a-f]` i jest stały dla tego samego peppera; inny pepper → inny hasz; pusty pepper (`''` i `null`) → `null`; `hash('nie-ip')` → `null`; `prefix('203.0.113.7') === '203.0.113.0/24'`; `prefix('2001:db8:1:2::5') === '2001:db8:1::/48'`; `prefix('::ffff:203.0.113.7') === '203.0.113.0/24'`; `prefix('x') === null`; `userAgent(str_repeat('a', 300))` ma 256 znaków; `userAgent(null) === null`.
- [ ] **Step 2:** Uruchom `cd apps/catalog && php artisan test --compact tests/Unit/Security/ClientFingerprintTest.php` → FAIL (brak klasy).
- [ ] **Step 3:** Implementacja (`hash_hmac('sha256', $ip, $pepper)`, `filter_var(FILTER_VALIDATE_IP)`, `inet_pton`/`inet_ntop` do maskowania; adres mapowany IPv4 sprowadzić do IPv4 przed haszem i prefiksem). Pusty pepper loguje `warning` raz na proces.
- [ ] **Step 4:** Testy → PASS.
- [ ] **Step 5: Commit** `feat(detekcja): odcisk klienta (HMAC IP, prefiks sieci)`.

### Task 3: Recenzja ze sklepu

**Files:**
- Create: `apps/catalog/app/Http/Controllers/Shop/ReviewController.php`, `apps/catalog/app/Http/Requests/StoreReviewRequest.php`, `apps/catalog/tests/Feature/Shop/StoreReviewTest.php`
- Modify: `apps/catalog/routes/web.php` (w grupie `auth`), `apps/catalog/app/Http/Controllers/Shop/ProductPageController.php` (prop `canReview`), `apps/catalog/resources/js/pages/shop/Product.vue`

**Interfaces:**
- Consumes: `ClientFingerprint` (Task 2), `Review::createWithOutbox($attributes, 'review.created', $payloadResolver)` (`app/Concerns/EmitsOutboxEvents.php:40`).
- Produces: route `POST products/{product}/reviews` → name `products.reviews.store`; payload `review.created` = dotychczasowe atrybuty + `client_ip_hash`, `client_ip_prefix`, `client_user_agent`, `user_account_age_hours` (int, pełne godziny od `users.created_at`). `SeedReviewsCommand` bez zmian (pola nieobecne → mapper traktuje jak null).

- [ ] **Step 1: Testy (Pest, `actingAs`):** (a) zalogowany, `rating=5`, `body='ok'` → redirect na `products.show`, 1 wiersz w `reviews`, 1 wiersz outboxu `review.created`, którego payload ma `client_ip_hash` (16 hex przy ustawionym `config(['services.security.ip_pepper' => 'test'])`), `client_ip_prefix === '127.0.0.0/24'`, `user_account_age_hours` = różnica od `created_at` użytkownika (ustaw `created_at` 30 h wstecz → 30), i **nie** zawiera surowego `127.0.0.1` nigdzie w JSON payloadu; (b) gość → redirect na login, 0 wierszy; (c) `rating` 0 i 6 → błąd walidacji; (d) druga recenzja tego samego użytkownika na ten sam produkt → błąd walidacji `rating`/`review`, nadal 1 wiersz i 1 zdarzenie (Review Focus 1); (e) pusty pepper → zapis przechodzi, `client_ip_hash === null`; (f) throttling: 6. żądanie w minucie → 429 (limit `throttle:5,1`).
- [ ] **Step 2:** Uruchom testy → FAIL.
- [ ] **Step 3:** Implementacja: FormRequest (`rating` int 1–5 wymagany, `body` nullable string max 2000, reguła unikalności `(product_id, user_id)` sprawdzana w aplikacji — bez migracji, bo dane z seedera mogą mieć duplikaty), kontroler wywołuje `createWithOutbox` z `payloadResolver` dokładającym pola klienta. Formularz w `Product.vue` (ocena 1–5, treść, przycisk; widoczny przy `canReview`), komunikat sukcesu flashem. Wayfinder generować z `--with-form`.
- [ ] **Step 4:** Testy → PASS; `npm run build` i `vue-tsc` (jak w projekcie) bez błędów.
- [ ] **Step 5: Commit** `feat(detekcja): recenzja ze sklepu z odciskiem klienta`.

### Task 4: Konsument `events-security`

**Files:**
- Create: `apps/search/src/Security/SecurityDocumentMapper.php`, `apps/search/src/MessageHandler/SecurityEventHandler.php`, `apps/search/tests/Unit/Security/SecurityDocumentMapperTest.php`, `apps/search/tests/Integration/Security/SecurityIngestTest.php`
- Modify: `apps/search/config/packages/messenger.yaml` (transport `security`, kolejka `search.security.ingest`, ta sama strategia retry), `compose.yaml` (`analytics-consumer`: `messenger:consume analytics security`)

**Interfaces:**
- Consumes: `IntegrationEvent`, `AnalyticsBulkWriter::write(array $items): BulkOutcome` (items `list<array{index,id,doc}>`), `BulkOutcome`.
- Produces: `SecurityDocumentMapper::map(IntegrationEvent $event): array` → `[]` albo jeden item `{index: 'events-security-default', id: <event id>, doc}`; stała `SecurityDocumentMapper::STREAM`. `SecurityEventHandler` po udanym zapisie paczki woła `SecurityPercolator::percolate(array $refs): int` (Task 5) — w tym tasku interfejs `App\Security\EventPercolator` z jedną metodą `percolate(array $refs): int` i implementacja no-op `NullEventPercolator` jako domyślny alias (Task 5 podmienia alias).

- [ ] **Step 1: Testy jednostkowe mappera:** `offer.price_changed` (old 10000, new 12750) → `offer.change_pct === 27.5`, `offer.id`/`product.id`/`seller.id` jako stringi, `event.type`, `@timestamp` = `occurred_at` koperty; old 0 → `change_pct` nieobecny (Review Focus 3); `review.created` z polami klienta → `client.ip_hash`, `client.ip_prefix`, `user_agent.original`, `user.account_age_hours`, `review.rating`; `review.created` bez pól klienta (seeder) → brak kluczy `client`/`user_agent`; `labels.synthetic` = `true`, gdy koperta ma `data.synthetic === true`, inaczej klucz nieobecny; inny typ (`offer.published`) → `[]`.
- [ ] **Step 2:** Uruchom → FAIL.
- [ ] **Step 3:** Mapper + handler (batch jak `AnalyticsEventHandler`: 500 / idle, 409 = ack, błąd mapowania → Unrecoverable, 429/5xx → Recoverable; po zapisie `percolate` na referencjach utworzonych dokumentów — błąd percolacji logowany i **nie** psuje ack zapisu, bo zdarzenie już jest w ES).
- [ ] **Step 4: Test integracyjny** (`RealElasticsearch`, grupa `integration`): handler z prawdziwym klientem zapisuje `offer.price_changed` dla produktu spoza `product-popularity` → dokument w `events-security-default`, brak `product.popularity` (Review Focus 4); ten sam event drugi raz → `duplicates = 1`.
- [ ] **Step 5:** Testy → PASS (`docker compose exec search-consumer php vendor/bin/phpunit tests/Unit/Security tests/Integration/Security`). Restart `analytics-consumer`, `rabbitmqadmin list queues` pokazuje konsumenta na `search.security.ingest`.
- [ ] **Step 6: Commit** `feat(detekcja): konsument events-security`.

### Task 5: Reguły percolatora

**Files:**
- Create: `infra/elasticsearch/detection/rules/price-hug-threshold.json`, `fresh-account-5star.json`; `apps/search/src/Security/RuleLoader.php`, `apps/search/src/Command/SecurityRulesLoadCommand.php`, `apps/search/src/Security/SecurityPercolator.php`, `apps/search/src/Security/AlertWriter.php`, `apps/search/src/Security/Alert.php`; testy `apps/search/tests/Unit/Security/AlertTest.php`, `apps/search/tests/Integration/Security/PercolatorRulesTest.php`
- Modify: `infra/elasticsearch/setup-detection.sh` (sekcja `[2]`: `security:rules:load` w kontenerze `search-consumer`), `apps/search/config/services.yaml` (alias `EventPercolator` → `SecurityPercolator`)

**Interfaces:**
- Produces: `final readonly class Alert { __construct(string $ruleId, string $ruleKind, string $severity, string $entityType, string $entityId, \DateTimeImmutable $windowStart, \DateTimeImmutable $windowEnd, string $message, array $evidence, bool $synthetic) ; public function id(): string ; public function toDocument(): array }` — `id()` = sha1 z Global Constraints. `AlertWriter::write(list<Alert> $alerts): int` (bulk `index` z `_id`, zwraca liczbę zapisanych). `security:rules:load` (`--dry-run`): czyta pliki reguł, dla reguł z `"params": {"p95_from": "product-popularity.popularity"}` liczy `percentiles` 95 i podstawia w zapytaniu znacznik `"{{p95}}"`, zapisuje `params.p95`; wypisuje użyte progi.
- Pliki reguł: `{ "rule": {id, kind: "percolator", severity, description}, "params": {...}, "query": {...}, "alert": {"entity_type": ..., "entity_field": ..., "window": "event"} }` — okno alertu = `@timestamp` zdarzenia (start = end).

- [ ] **Step 1: Test jednostkowy `Alert`:** ten sam zestaw pól → ten sam `id()`; inny `windowStart` → inny `id()`; `toDocument()` ma `rule.kind`, `entity.id`, `window.start` w ATOM, `labels.synthetic`.
- [ ] **Step 2: Test integracyjny** (indeks reguł załadowany z plików z p95 wymuszonym opcją testową lub progiem ustawionym w dokumencie testowym): zdarzenia `change_pct` 24.99 / 25 / 30 / 30.01 przy `product.popularity` ≥ p95 → alert tylko dla 25 i 30; 27.5 przy popularności < p95 → brak; brak `product.popularity` → brak; recenzja 5★ z `account_age_hours` 23 → alert `fresh-account-5star` z encją `user`, 24 → brak, 4★ z 1 h → brak. Ponowna percolacja tych samych dokumentów → liczba dokumentów w `alerts-security` bez zmian.
- [ ] **Step 3:** Uruchom → FAIL.
- [ ] **Step 4:** Implementacja: percolacja po zapisie paczki przez `_msearch` z zapytaniami `percolate` po `index` + `id` (dokument po pipeline, więc z `product.popularity`), `labels.synthetic` alertu = z dokumentu zdarzenia.
- [ ] **Step 5:** Testy → PASS; `make detection-setup` ładuje reguły i wypisuje p95.
- [ ] **Step 6: Commit** `feat(detekcja): reguły percolatora i zapis alertów`.

### Task 6: `security:detect` i reguła `review-ip-burst`

**Files:**
- Create: `infra/kibana/rules/security-review-ip-burst.json`, `infra/kibana/rules/connector-security-index.json`; `apps/search/src/Security/Detector.php` (interfejs), `apps/search/src/Security/Detect/ReviewIpBurstDetector.php`, `apps/search/src/Command/SecurityDetectCommand.php`, `apps/search/src/Security/SecurityDetectJob.php`; test `apps/search/tests/Integration/Security/ReviewIpBurstDetectorTest.php`, `apps/search/tests/Unit/Security/SecurityDetectJobTest.php`
- Modify: `infra/elasticsearch/setup-detection.sh` (sekcja `[3]`: konektor index → `alerts-security`, reguła, data view `alerts-security` i `events-security-*`), `compose.yaml` (`seller360-scheduler` dostaje mount `infra/kibana/rules` read-only, jeśli go nie ma)

**Interfaces:**
- Produces: `interface Detector { public function id(): string ; /** @return list<Alert> */ public function detect(\DateTimeImmutable $from, \DateTimeImmutable $to): array }` (tagowany `app.security_detector`). `security:detect [--rule=ID] [--since=24h]` (format `<liczba>(h|d)`), wypisuje per regułę liczbę alertów. `SecurityDetectJob implements Seller360Job`: `name() = 'security-detect'`, `intervalSeconds() = 300`, `run()` = wszystkie detektory za ostatnie 2 h.
- Reguła Kibany: `.es-query`, `searchType: esqlQuery`, `schedule 5m`, `timeWindowSize 1`, `timeWindowUnit h`, akcja konektorem index z dokumentem w kształcie `alerts-security` (`rule.id: review-ip-burst`, `rule.kind: esql`, `entity.type: ip_hash`). ES|QL:
  `FROM events-security-* | WHERE event.type == "review.created" AND client.ip_hash IS NOT NULL | STATS users = COUNT_DISTINCT(user.id), reviews = COUNT(*) BY client.ip_hash, hour = BUCKET(@timestamp, 1 hour) | WHERE users >= 5`
- `ReviewIpBurstDetector` czyta `params.esqlQuery.esql` z pliku reguły (ścieżka z parametru kontenera), dokleja zakres czasu (`WHERE @timestamp >= … AND @timestamp < …` po `FROM`) i woła `_query`; jeden alert na wiersz, okno = `[hour, hour+1h)`.

- [ ] **Step 1: Testy:** integracyjny — 5 różnych użytkowników z jednego hasza w tej samej godzinie → 1 alert; 4 → 0; 5 recenzji jednego użytkownika → 0; dwa przebiegi `detect` → 1 dokument w `alerts-security`. Jednostkowy — job ma nazwę i interwał jak wyżej i woła każdy detektor dokładnie raz.
- [ ] **Step 2:** Uruchom → FAIL.
- [ ] **Step 3:** Implementacja.
- [ ] **Step 4:** Testy → PASS; `make detection-setup` → reguła widoczna w Kibanie (`GET /api/alerting/rules/_find?search=review-ip-burst`).
- [ ] **Step 5: Commit** `feat(detekcja): security:detect i reguła review-ip-burst (Kibana + backfill)`.

### Task 7: EQL `price-bait-and-switch`

**Files:**
- Create: `infra/elasticsearch/detection/eql/price-bait-and-switch.eql`, `apps/search/src/Security/Detect/PriceBaitAndSwitchDetector.php`, `apps/search/tests/Unit/Security/BaitAndSwitchFilterTest.php`, `apps/search/tests/Integration/Security/PriceBaitAndSwitchDetectorTest.php`

**Interfaces:**
- Consumes: `Detector`, `Alert`, `AlertWriter` (przez `security:detect`).
- Produces: zapytanie EQL na `events-security-*,events-user-*` z `timestamp_field: @timestamp`, `event_category_field: event.type`; `PriceBaitAndSwitchDetector::accept(array $sequenceEvents): bool` (publiczne, testowalne) z post-filtrem z Global Constraints. Alert: encja `offer`, okno = od obniżki do ostatniej podwyżki, `evidence` = ceny i liczba kliknięć.

- [ ] **Step 1: Testy jednostkowe `accept`:** 10000 → 8000 (−20) → +20 → +16 (9600 → 11136, 1,11×) → false; 10000 → 8000 → +29 (10320) → +27 (13106, 1,31×) → true; 10000 → 8000 → +29 → +26 (13003, 1,30×) → true (granica włącznie); dowolny krok −31 lub +31 → false; 10000 → 8000 → +28 → +26 (12902, 1,29×) → false (bliskie pudło).
- [ ] **Step 2: Test integracyjny:** sekwencja obniżka → 20 kliknięć tej oferty → dwie podwyżki do 1,31× w ciągu 30 h → 1 alert; te same zdarzenia rozciągnięte na 60 h → 0; 19 kliknięć → 0.
- [ ] **Step 3:** Uruchom → FAIL. Implementacja: EQL `sequence by offer.id with maxspan=48h [offer.price_changed where offer.change_pct <= -15] [user.clicked_result where true] with runs=20 [offer.price_changed where offer.change_pct > 0] [offer.price_changed where offer.change_pct > 0]` — dokładny tekst w pliku `.eql`, `size` / paginacja tak, by backfill 15 dni nie gubił sekwencji (decyzja implementującego, opisana w PR).
- [ ] **Step 4:** Testy → PASS.
- [ ] **Step 5: Commit** `feat(detekcja): EQL price-bait-and-switch`.

### Task 8: `complaints-spike`

**Files:**
- Create: `apps/search/src/Security/Detect/ComplaintsSpikeDetector.php`, `apps/search/tests/Unit/Security/ComplaintsSpikeRuleTest.php`, `apps/search/tests/Integration/Security/ComplaintsSpikeDetectorTest.php`

**Interfaces:**
- Produces: `ComplaintsSpikeDetector::isSpike(int $count, ?float $mean, ?float $std): bool` (publiczne): true gdy `$count >= 5` i `$mean !== null` i `$count >= $mean + 3 * $std`. Zapytanie: `complaints`, `terms` po `seller_id` ograniczone do sprzedawców z reklamacją w oknie (`size` wystarczający dla populacji, kompozytowo albo z `partition`), w każdym `date_histogram` 1d (`extended_bounds` 8 dni wstecz od dnia oceny, `min_doc_count: 0`), `moving_fn` `MovingFunctions.unweightedAvg(values)` i `MovingFunctions.stdDev(values, MovingFunctions.unweightedAvg(values))`, `window: 7`, `shift: 0` (dzień nie liczy sam siebie). `evidence`: dzienne liczby, średnia, σ, top 3 kategorie z `significant_terms` po `category` dla sprzedawcy, oraz flaga, czy sprzedawca jest w `significant_terms` po `seller_id` (ostatnia doba vs tło 30 dni).

- [ ] **Step 1: Testy jednostkowe `isSpike`:** (12, 2.0, 1.0) → true; (4, 0.0, 0.0) → false (min 5); (5, 1.0, 1.0) → true (5 ≥ 4); (6, 2.0, 1.5) → false (6 < 6.5); (8, null, null) → false.
- [ ] **Step 2: Test integracyjny:** sprzedawca z 1–2 reklamacjami dziennie przez 7 dni i 12 ósmego dnia → 1 alert z encją `seller` i oknem tego dnia; sprzedawca z 3 dziennie i 6 ósmego → 0; nowy sprzedawca z 6 tylko jednego dnia → 0 (brak historii: `mean` null). Dwa przebiegi → bez duplikatów.
- [ ] **Step 3:** Uruchom → FAIL; implementacja; → PASS.
- [ ] **Step 4: Commit** `feat(detekcja): complaints-spike (moving_fn + significant_terms)`.

### Task 9: Symulator, purge, raport

**Files:**
- Create: `apps/catalog/app/Console/Commands/SecuritySimulateCommand.php`, `apps/catalog/app/Security/Simulation/SecurityScenario.php`, `apps/catalog/tests/Feature/Security/SecuritySimulateCommandTest.php`; `apps/search/src/Command/SecurityPurgeSyntheticCommand.php`, `apps/search/src/Command/SecurityReportCommand.php`, `apps/search/src/Security/DetectionScore.php`, `apps/search/tests/Unit/Security/DetectionScoreTest.php`
- Modify: `apps/catalog/app/Seller360/Simulation/ComplaintsCsvWriter.php` (`write(..., string $prefix = 'SIM')`), `Makefile` (`detection-simulate`, `detection-detect`, `detection-report`, `detection-purge`)

**Interfaces:**
- Consumes: `SimulatedEventPublisher::publish(array $envelope)` / `finish(): int`, `EventEnvelope::make(...)`, `ComplaintsCsvWriter::write`, `PlantedSellers` (do wykluczenia sprzedawców z `planted.json` ETAPU 13), próg p95 z dokumentu `security-rules/price-hug-threshold` (`params.p95`, pobrany przez klienta ES catalog).
- Produces: `security:simulate {--seed=1} {--days=14} {--plant=5} {--complaints=storage/app/security/complaints.csv}`; plik `storage/app/private/security/planted-security.json`: `{"seed", "generated_at", "p95", "cases": [{"rule", "type": "planted"|"near_miss", "entity_type", "entity_id", "window_start", "window_end"}]}`. Wszystkie koperty z `data.synthetic = true`, IP z pul TEST-NET (192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24, 2001:db8::/32) zamienione na hasz/prefiks tym samym `ClientFingerprint` co sklep. `DetectionScore::score(list<array> $cases, list<array> $alerts): array` → per reguła `tp`, `fp`, `fp_near_miss`, `fn`, `precision`, `recall` (alert dopasowany, gdy ta sama reguła i encja, a okno alertu przecina okno przypadku; alert bez przypadku = FP; bliskie pudło trafione = FP i `fp_near_miss`). `security:report {--truth=PATH} {--json}`. `security:purge-synthetic` kasuje `labels.synthetic: true` z `events-security-*` i `alerts-security` oraz reklamacje z `ticket_id` `SEC-*` (prefix query), potem odświeża sekcję reklamacji seller-360 dla dotkniętych sprzedawców istniejącym mechanizmem.
- Scenariusze na regułę (`--plant` = P, bliskie pudła = P): `price-hug-threshold` podwyżka 25–30 na ofercie ≥ p95 / pudło: 24 na ofercie ≥ p95 i 27 na ofercie < p95; `fresh-account-5star` konto 2–20 h / pudło: konto 30 h; `review-ip-burst` 5–8 kont z jednego IP w godzinę / pudło: 4 konta; `price-bait-and-switch` przynęta do 1,30–1,40× w ≤ 40 h z ≥ 25 kliknięciami / pudło: 1,28× albo 60 h; `complaints-spike` skok ≥ 4σ / pudło: skok ~2σ. Tło przez `--days`: zwykłe zmiany cen ±1–15%, recenzje rozproszone, dzienny szum reklamacji 0–3 na sprzedawcę. Konta „świeże” = użytkownicy syntetyczni tworzeni z `created_at` cofniętym tak, by wiek w chwili zdarzenia się zgadzał (prefiks e-mail `sec-sim-`), sprzątani przez `--purge` symulatora.

- [ ] **Step 1: Testy:** catalog (anonimowa podklasa publishera jak `Seller360SimulateCommandTest`) — to samo ziarno → identyczny plik prawdy i identyczne koperty; P=2 → 2 `planted` i 2 `near_miss` na każdą z 5 reguł; żaden JSON koperty nie zawiera adresu z pul TEST-NET w postaci surowej; wszystkie `occurred_at` w ostatnich `--days` dniach; żaden NIP reklamacji nie należy do sprzedawców z `planted.json` ETAPU 13 (fikstura); CSV ma `ticket_id` z prefiksem `SEC-`, a domyślne wywołanie `ComplaintsCsvWriter::write` nadal daje `SIM-`. search — `DetectionScore`: przypadek planted + alert → tp 1; planted bez alertu → fn 1; near_miss z alertem → fp 1 i fp_near_miss 1; alert bez przypadku → fp 1; okna rozłączne → brak dopasowania; precision przy 0 alertach = null (nie dzielić przez zero).
- [ ] **Step 2:** Uruchom → FAIL; implementacja; → PASS.
- [ ] **Step 3:** Makefile: `detection-simulate` = `security:simulate $(args)` w `catalog-app` + kopiowanie CSV i `seller360:complaints:import` (jak `seller360-complaints-import`); `detection-detect` = `security:detect --since=$(or $(since),15d)`; `detection-report` = kopiowanie pliku prawdy do `search-consumer` + `security:report --truth=…`; `detection-purge`.
- [ ] **Step 4: Commit** `feat(detekcja): symulator, raport skuteczności, purge`.

### Task 10: Przebieg na żywym stosie i pomiar

**Files:**
- Modify: `docs/POMIARY.md` (§19), lokalnie (git-ignored) `apps/catalog/storage/app/private/security/report-15.json`

- [ ] **Step 1:** `make detection-setup` → `make detection-simulate args="--seed=1 --plant=5"` → poczekać, aż `search.security.ingest` opustoszeje (`rabbitmqadmin list queues`) → `make detection-detect` (Kibana rule obsługuje tylko bieżące okno; backfill robi `security:detect`) → `make detection-report` → zapisać `--json`.
- [ ] **Step 2:** Drugi `make detection-detect` → `GET alerts-security/_count` bez zmian (Review Focus 5).
- [ ] **Step 3:** `make seller360-verify` → 5/5; reguły `zero-results-spike` i `ctr-drop` mają `lastRun` bez błędu (`/api/alerting/rules/_find`).
- [ ] **Step 4:** Jedna recenzja przez UI (`https://catalog.localhost:8443/products/{id}`, zalogowany użytkownik testowy z seedera) → dokument w `events-security-default` z `client.ip_hash`/`client.ip_prefix`, bez surowego IP (`GET events-security-*/_search` z `query_string` na adres) — wynik w POMIARY.
- [ ] **Step 5:** Jeżeli wynik którejś reguły jest nieczytelny (np. recall < 1 dla planted), zdiagnozować i opisać przyczynę; zmiana progu tylko z uzasadnieniem zapisanym w przewodniku.
- [ ] **Step 6:** POMIARY §19: tabela per reguła (TP, FP, FP z bliskich pudeł, FN, precision, recall), p95, czasy `security:detect` na 15 dniach, liczba zdarzeń. **Commit** `docs(detekcja): pomiar skuteczności reguł (POMIARY §19)`.

### Task 11: Przewodnik i dokumentacja

**Files:**
- Create: `docs/blog/etap-15-detekcja-naduzyc.md`
- Modify: `docs/RUNBOOK.md` (od #158 + wiersze indeksu), `docs/06-PLAN-WDROZENIA.md` (stan ETAPU 15; ETAP 14: „niewypchnięta” → „scalony PR #24”), `README.md` (linia o `make detection-*`), `.gitignore` jeśli `storage/app/private/security/` nie jest już objęte

- [ ] **Step 1:** Przewodnik krok po kroku jak 14a/14b: po co reguły detekcji; przepływ (diagram ze specu); każda reguła — dlaczego to narzędzie (pojedyncze zdarzenie → percolator, seria → ES|QL, sekwencja → EQL, skok → agregacje), komenda, wynik z POMIARY, czego nie złapie (bliskie pudło); dodanie reguły percolatora bez deployu (pokaz); prywatność IP (HMAC, prefiks, retencja 30 d, ograniczenie Docker Desktop); pytania kontrolne. Wszystkie komendy i liczby sprawdzone na stosie z Task 10.
- [ ] **Step 2:** RUNBOOK: co najmniej konsument stoi / DLQ rośnie, `security:rules:load` bez `product-popularity-policy`, reguła Kibany nie widzi starych danych (backfill), purge symulacji.
- [ ] **Step 3: Commit** `docs(detekcja): przewodnik ETAPU 15, RUNBOOK, plan`.
