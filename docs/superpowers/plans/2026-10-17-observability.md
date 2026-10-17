# ETAP 11: tor observability — plan implementacji

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Logi obu aplikacji w ES (ECS, data streams, ILM) skorelowane po `trace_id`, Stack Monitoring klastra przez Metricbeat, metryki i alerty w Prometheusie z powiadomieniem w mailpicie i dashboardami w Grafanie — wszystko w profilu Compose `obs`.

**Architecture:** Aplikacje piszą JSON Monologa na stderr; Filebeat zbiera tylko linie JSON kontenerów projektu `marketplace`, a ingest pipeline `logs-app` mapuje je na ECS. `trace_id` żyje w `Context` Laravela, w kolumnie `outbox.trace_id`, w nagłówku AMQP i w stampie Messengera. Prometheus scrapuje RabbitMQ, Caddy, gauge catalog (spatie, liczone przy scrape) i nowy kontener `search-metrics`; reguły z testami `promtool` wysyłają przez Alertmanager do mailpita.

**Tech Stack:** Laravel 13 + `spatie/laravel-prometheus` ^1.7, Symfony 8.1 Messenger + Monolog, Elasticsearch/Filebeat/Metricbeat 9.5.1, Prometheus, Alertmanager, Grafana, Docker Compose.

**Spec:** [`docs/superpowers/specs/2026-10-17-observability-design.md`](../specs/2026-10-17-observability-design.md)

## Global Constraints

- Każdy nowy kontener ma `mem_limit`: filebeat 256m, metricbeat 256m, prometheus 512m, alertmanager 64m, grafana 256m, search-metrics 128m, mailpit 256m.
- Nowe serwisy w profilu `obs`; `mailpit` w `[tools, obs]`.
- Porty na hoście tylko `127.0.0.1`: `GRAFANA_PORT=13000`, `PROMETHEUS_PORT=19090`, `ALERTMANAGER_PORT=19093`. Endpointy `/metrics` i `/prometheus` **nie** są publikowane na hosta.
- Wersje przypięte w `.env.example` i `docs/07-WERSJE.md`; beats = `${ES_VERSION}` (9.5.1, obrazy sprawdzone).
- `trace_id`: 32 znaki `[0-9a-f]`; nagłówek odpowiedzi `X-Trace-Id`; nagłówek AMQP `trace_id`; przychodzący W3C `traceparent`.
- Data streams `logs-app-catalog`, `logs-app-search`, demo `logs-ilmdemo-*`; szablony priorytet 200.
- Etykiety metryk bez identyfikatorów (ADR-0005, zasada 3).
- Testy aplikacji **tylko na hoście**: catalog `php artisan test --compact` (SQLite in-memory), search `vendor/bin/phpunit`. Nigdy w kontenerze.
- Maszyna współdzielona: nie zatrzymywać ani nie odtwarzać cudzych kontenerów; `docker compose` z worktree zawsze z `--env-file /Users/domin/projects/elastic/.env` i **`--no-deps`** przy `up` pojedynczych serwisów (inaczej Compose odtworzy es01/catalog-app z worktree).
- Kontenery aplikacji montują główny checkout — weryfikacja kodu aplikacji na żywo dopiero w Task 12.
- Commity `ETAP 11 [k/13]: …`, stopka `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Bez pusha.

## Review Focus

- **Baza niedostępna przy scrape** — `/prometheus` ma odpowiedzieć 200 z pozostałymi metrykami, a nie 500 (Task 5: test z gauge rzucającym wyjątek).
- **Zły lub obcy `traceparent`** (wielkie litery, same zera, inna wersja, śmieci) — generujemy nowy ślad, nie przepuszczamy wartości do logów (Task 1: test przypadków).
- **Wiadomość bez nagłówka `trace_id`** (stare wiadomości w kolejce, retry sprzed wdrożenia, `make mq-publish`) — konsument działa jak dziś, log bez `trace_id` (Task 3: test dekodowania bez nagłówka).
- **Linia logu, która nie jest JSON-em, albo JSON bez pól Monologa/Caddy** — dokument trafia do ES z `event.original` i tagiem, nie przepada i nie blokuje bulka (Task 7: próbka w `_simulate`).
- **Pusty klucz sweepu po świeżym starcie** — brak próbki, a nie wiek liczony od epoki 1970 (Task 5: test).

---

### Task 1: `trace_id` w catalog (HTTP i konsola)

**Files:**
- Create: `apps/catalog/app/Support/Tracing/TraceId.php`
- Create: `apps/catalog/app/Http/Middleware/AssignTraceId.php`
- Modify: `apps/catalog/bootstrap/app.php` (w `withMiddleware`: `$middleware->prepend(AssignTraceId::class)`)
- Test: `apps/catalog/tests/Unit/Support/TraceIdTest.php`, `apps/catalog/tests/Feature/TraceIdMiddlewareTest.php`

**Interfaces:**
- Produces: `final class App\Support\Tracing\TraceId` z `public const CONTEXT_KEY = 'trace_id'`, `static current(): string` (z `Context`, a jeśli brak — `generate()` zapisane w `Context::add`), `static generate(): string`, `static fromTraceparent(?string $header): ?string`, `static toTraceparent(string $traceId): string` (`00-{trace}-{16 hex losowe}-01`).
- Produces: middleware `AssignTraceId` — ustawia `Context::add(TraceId::CONTEXT_KEY, …)` i nagłówek odpowiedzi `X-Trace-Id`.

- [ ] **Step 0: Przygotuj worktree do testów na hoście** — `cp /Users/domin/projects/elastic/apps/catalog/.env apps/catalog/.env` (jeśli testy go potrzebują), `cd apps/catalog && composer install && npm ci` (wayfinder: patrz pamięć „`--with-form`”); `php artisan test --compact` → wszystko zielone jak na main (punkt odniesienia: 764 testy, 15 pominiętych).
- [ ] **Step 1: Testy (czerwone)**
  - `TraceIdTest`: `fromTraceparent('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01')` → `'4bf92f3577b34da6a3ce929d0e0e4736'`; `null` dla: `null`, `''`, `'garbage'`, wersji `'ff-…'`, trace z wielkimi literami, trace `'000…0'` (32 zera), za krótkiego; `generate()` pasuje do `/^[0-9a-f]{32}$/` i dwa wywołania się różnią; `current()` dwa razy w tym samym Context zwraca to samo; `toTraceparent($t)` pasuje do `/^00-$t-[0-9a-f]{16}-01$/` i `fromTraceparent(toTraceparent($t)) === $t`.
  - `TraceIdMiddlewareTest`: `get('/up')` ma nagłówek `X-Trace-Id` zgodny z `/^[0-9a-f]{32}$/`; z nagłówkiem `traceparent` poprawnym → `X-Trace-Id` równy trace z nagłówka; z niepoprawnym → nowy, inny niż śmieci; trasa testowa zarejestrowana w teście zwraca `Context::get('trace_id')` i jest równa nagłówkowi.
- [ ] **Step 2:** `php artisan test --compact --filter=TraceId` → FAIL (brak klas).
- [ ] **Step 3: Implementacja** — W3C: wersja `00`, trace 32 hex lowercase, nie same zera. Middleware globalny (`prepend`), więc obejmuje też `/up`, `/api/internal/*` i webhooki.
- [ ] **Step 4:** `php artisan test --compact --filter=TraceId` → PASS; `vendor/bin/pint --dirty`; `vendor/bin/phpstan` jeśli używany w repo (sprawdź `composer.json` scripts).
- [ ] **Step 5: Commit** `ETAP 11 [1/13]: trace_id w catalog (Context, traceparent, X-Trace-Id)`

### Task 2: `outbox.trace_id` i nagłówek AMQP w publisherze

**Files:**
- Create: `apps/catalog/database/migrations/2026_10_17_100000_add_trace_id_to_outbox_table.php` (`char('trace_id', 32)->nullable()`)
- Create: `apps/catalog/app/Support/Outbox/OutboxMessageFactory.php`
- Modify: `apps/catalog/app/Models/Outbox.php` (`trace_id` w `$fillable`)
- Modify: `apps/catalog/app/Concerns/EmitsOutboxEvents.php:116-130` (`'trace_id' => TraceId::current()`)
- Modify: `apps/catalog/src/SellerPortal/Infrastructure/Persistence/OfferIntegrationEvents.php:58-66` (to samo)
- Modify: `apps/catalog/app/Console/Commands/PublishOutboxCommand.php` (wiadomość z fabryki; log per zdarzenie)
- Test: `apps/catalog/tests/Feature/OutboxTraceIdTest.php`, `apps/catalog/tests/Unit/Support/OutboxMessageFactoryTest.php`

**Interfaces:**
- Consumes: `TraceId::current()`, `TraceId::CONTEXT_KEY` (Task 1).
- Produces: `final class OutboxMessageFactory` z `static make(Outbox $row): AMQPMessage` — ciało = dotychczasowe `toEventEnvelope()` (przeniesione 1:1, kształt koperty bez zmian), właściwości `content_type`, `delivery_mode` jak dziś, `application_headers` = `AMQPTable(['trace_id' => $row->trace_id])` tylko gdy `trace_id !== null`.

**Uwaga (architektura):** `OfferIntegrationEvents` leży w `src/SellerPortal/Infrastructure` — sprawdź `tests/Arch/SellerPortalLayersTest.php`, czy Infrastructure może używać `App\Support\*`. Jeśli nie, przenieś `TraceId` do `src/SharedKernel/Tracing/TraceId.php` (zależy tylko od fasady `Context`, to infrastruktura współdzielona) i zaktualizuj Task 1 — zdecyduj na podstawie testu architektury, nie zgaduj.

- [ ] **Step 1: Testy (czerwone)**
  - `Context::add('trace_id', 'aaaa…' (32))` → zmiana encji z `EmitsOutboxEvents` (np. utworzenie produktu fabryką używaną w `OutboxEventsTest`) → wiersz `outbox` ma ten `trace_id`.
  - Ten sam przypadek dla ścieżki `OfferIntegrationEvents::record` (przez istniejący przypadek użycia portalu sprzedawcy, np. zmiana ceny; wzoruj się na testach w `tests/Feature/SellerPortal`).
  - Bez Context → `trace_id` w wierszu pasuje do `/^[0-9a-f]{32}$/`.
  - `OutboxMessageFactoryTest`: wiersz z `trace_id` → `$msg->get('application_headers')->getNativeData()['trace_id']` równe; wiersz z `trace_id = null` → brak `application_headers`; `json_decode($msg->getBody())` ma dokładnie klucze `id,type,version,source,occurred_at,aggregate,sequence,data`.
- [ ] **Step 2:** `php artisan test --compact --filter='OutboxTraceId|OutboxMessageFactory'` → FAIL.
- [ ] **Step 3: Implementacja.** W `PublishOutboxCommand::publishBatch` dla każdej wiadomości: `Context::add('trace_id', $row->trace_id)` (gdy nie null), `Log::debug('Opublikowano zdarzenie do RabbitMQ', ['event_id' => …, 'type' => …, 'aggregate_id' => …])`, potem `Context::forget('trace_id')`. Log po potwierdzeniu nie jest potrzebny — debug przy wysłaniu wystarcza do śladu, a niepotwierdzone pokaże ponowna próba z tym samym `trace_id`.
- [ ] **Step 4:** cały `php artisan test --compact` → PASS (bez regresji w `OutboxEventsTest`, `SeedMarketplaceCommandTest`); `pint --dirty`.
- [ ] **Step 5: Commit** `ETAP 11 [2/13]: trace_id w outboxie i w nagłówku AMQP`

### Task 3: `trace_id` w search (stamp, logi, żądanie projekcji)

**Files:**
- Create: `apps/search/src/Messenger/TraceIdStamp.php` (`final class TraceIdStamp implements StampInterface { public function __construct(public readonly string $traceId) {} }`)
- Create: `apps/search/src/Tracing/TraceContext.php` (`set(?string): void`, `get(): ?string`, `clear(): void`; serwis współdzielony)
- Create: `apps/search/src/Tracing/TraceContextSubscriber.php` (`WorkerMessageReceivedEvent` → `set` z `TraceIdStamp`; `WorkerMessageHandledEvent`, `WorkerMessageFailedEvent` → `clear`)
- Create: `apps/search/src/Tracing/TraceIdLogProcessor.php` (`#[AsMonologProcessor]`, `__invoke(LogRecord): LogRecord` — `extra['trace_id']` tylko gdy `get() !== null`)
- Modify: `apps/search/src/Messenger/ExternalJsonEnvelopeSerializer.php` (`TRACE_ID_HEADER = 'trace_id'`; `decode` dodaje stamp, gdy nagłówek ma 32 hex; `encode` zapisuje nagłówek z ostatniego stampa)
- Modify: `apps/search/src/Service/CatalogProjectionClient.php` (konstruktor: `TraceContext $trace`, opcjonalny `?HttpClientInterface $client = null`; nagłówek `traceparent` gdy jest ślad)
- Modify: `apps/search/config/services.yaml` (wiring, jeśli autowiring nie wystarczy)
- Modify: `apps/search/config/packages/monolog.yaml` (handler `stderr_json`: `type: stream`, `path: php://stderr`, `level: info`, `formatter: monolog.formatter.json`, `channels: ["!event", "!doctrine"]` w `when@dev` i `when@prod`; w `when@prod` zastępuje `nested` z `fingers_crossed` tylko dla tego handlera — reszta bez zmian)
- Test: `apps/search/tests/Unit/Messenger/ExternalJsonEnvelopeSerializerTraceTest.php`, `apps/search/tests/Unit/Tracing/TraceIdLogProcessorTest.php`, `apps/search/tests/Unit/Tracing/TraceContextSubscriberTest.php`, `apps/search/tests/Unit/Service/CatalogProjectionClientTraceTest.php`

**Interfaces:**
- Produces: `TraceContext` — używany tylko w tym zadaniu (subscriber, procesor, klient).
- Format nagłówka do catalog: `traceparent: 00-{trace_id}-{16 hex}-01` (catalog parsuje go `TraceId::fromTraceparent`, Task 1).

- [ ] **Step 0:** `cd apps/search && composer install`; `cp` `.env.local`/`.env` z głównego checkoutu, jeśli nie są w repo; `vendor/bin/phpunit tests/Unit` → zielone (punkt odniesienia; testy `Integration` wymagają żywych usług — uruchamiaj je tylko jeśli tak robi README).
- [ ] **Step 1: Testy (czerwone)**
  - serializer: `decode(['body' => <poprawna koperta>, 'headers' => ['trace_id' => $t]])` → `$envelope->last(TraceIdStamp::class)->traceId === $t`; bez nagłówka → brak stampa, wiadomość zdekodowana; nagłówek `'nie-hex'` → brak stampa; `encode(decode(...))['headers']['trace_id'] === $t`; `RETRY_COUNT_HEADER` dalej działa razem z nowym nagłówkiem.
  - procesor: z `set('abc…')` rekord ma `extra.trace_id`; po `clear()` nie ma klucza.
  - subscriber: `WorkerMessageReceivedEvent` z kopertą ze stampem → `get()` zwraca ślad; po `WorkerMessageHandledEvent` → `null`; po `WorkerMessageFailedEvent` → `null`.
  - klient: `MockHttpClient` z callbackiem zapisującym nagłówki; z ustawionym śladem żądanie ma `traceparent` pasujący do `/^00-$t-[0-9a-f]{16}-01$/`; bez śladu brak nagłówka.
- [ ] **Step 2:** `vendor/bin/phpunit tests/Unit` → FAIL.
- [ ] **Step 3:** Implementacja według plików powyżej.
- [ ] **Step 4:** `vendor/bin/phpunit tests/Unit` → PASS; `php bin/console lint:container` i `php bin/console debug:config monolog` bez błędów; lint/format zgodnie z `composer.json` search (php-cs-fixer, jeśli jest).
- [ ] **Step 5: Commit** `ETAP 11 [3/13]: trace_id w search (stamp, procesor logów, traceparent do catalog)`

### Task 4: `search-metrics` — gauge failure transportu

**Files:**
- Create: `apps/search/src/Controller/MetricsController.php` (`#[Route('/metrics', methods: ['GET'])]`, `__invoke(): Response`)
- Modify: `apps/search/config/services.yaml` (wstrzyknięcie `messenger.transport.failed` jako `MessageCountAwareInterface $failedTransport`, jeśli `#[Autowire(service: 'messenger.transport.failed')]` w konstruktorze nie wystarczy)
- Test: `apps/search/tests/Unit/Controller/MetricsControllerTest.php`

**Interfaces:**
- Produces: `GET /metrics` → `200`, `Content-Type: text/plain; version=0.0.4`, ciało:
  ```
  # HELP search_messenger_failed_messages Wiadomości w failure transport (messenger:failed:show).
  # TYPE search_messenger_failed_messages gauge
  search_messenger_failed_messages <liczba>
  ```
  Błąd odczytu transportu → `200` z `search_metrics_scrape_error 1` zamiast gauge (Prometheus dostaje sygnał, a nie 500) i log `warning`.

- [ ] **Step 1: Testy (czerwone)** — dubler `MessageCountAwareInterface` zwracający 3 → ciało zawiera linię `search_messenger_failed_messages 3` i nagłówek typu; dubler rzucający wyjątek → `200`, ciało zawiera `search_metrics_scrape_error 1`, nie zawiera `search_messenger_failed_messages `.
- [ ] **Step 2:** `vendor/bin/phpunit tests/Unit/Controller` → FAIL.
- [ ] **Step 3:** Implementacja.
- [ ] **Step 4:** PASS; `php bin/console debug:router | grep metrics` pokazuje trasę.
- [ ] **Step 5: Commit** `ETAP 11 [4/13]: search-metrics, gauge failure transportu`

### Task 5: Gauge catalog (`spatie/laravel-prometheus`)

**Files:**
- Modify: `apps/catalog/composer.json` (`composer require spatie/laravel-prometheus:^1.7`), `php artisan prometheus:install` (publikuje `config/prometheus.php` i `app/Providers/PrometheusServiceProvider.php`; zarejestruj provider w `bootstrap/providers.php`, jeśli instalator tego nie zrobi)
- Create: `apps/catalog/app/Metrics/CatalogGauges.php`
- Modify: `apps/catalog/app/Providers/PrometheusServiceProvider.php` (rejestracja gauge)
- Modify: `apps/catalog/config/prometheus.php` (`'urls' => ['default' => 'prometheus']`, `'default_namespace' => 'catalog'`, middleware pakietu bez filtra IP — ochroną jest Caddy, Task 6)
- Create (jeśli `EXPLAIN` w Task 12 pokaże seq scan): `apps/catalog/database/migrations/2026_10_17_100001_add_outbox_pending_index.php` (częściowy indeks Postgresa `outbox (occurred_at) WHERE published_at IS NULL`, `DB::statement` tylko dla `pgsql`)
- Test: `apps/catalog/tests/Feature/Metrics/CatalogGaugesTest.php`, `apps/catalog/tests/Feature/Metrics/PrometheusEndpointTest.php`

**Interfaces:**
- Produces: `final class App\Metrics\CatalogGauges` z `public const STUCK_PLACED_AFTER_SECONDS = 60;` i metodami (wszystkie `: ?float`, `null` = brak próbki):
  `outboxPending()`, `outboxOldestPendingAgeSeconds()`, `offersPendingModeration()`, `offersPendingModerationOldestAgeSeconds()`, `sellersPendingApproval()`, `sellersPendingApprovalOldestAgeSeconds()`, `ordersStuckPlaced()`, `ordersSettledWithActiveReservation()`, `orderingSweepLastRunAgeSeconds()`, `failedJobs()`.
  Wieki: `0.0`, gdy brak oczekujących; `orderingSweepLastRunAgeSeconds()` → `null`, gdy brak klucza `ordering:sweep:last_run_at`.
- Nazwy metryk (spec D8): `catalog_outbox_pending`, `catalog_outbox_oldest_pending_age_seconds`, `catalog_offers_pending_moderation`, `catalog_offers_pending_moderation_oldest_age_seconds`, `catalog_sellers_pending_approval`, `catalog_sellers_pending_approval_oldest_age_seconds`, `catalog_orders_stuck_placed`, `catalog_orders_settled_with_active_reservation`, `catalog_ordering_sweep_last_run_age_seconds`, `catalog_failed_jobs`.
- Produces: `GET /prometheus` (trasa pakietu).

Rejestracja: dla każdej metody `Prometheus::addGauge(<nazwa bez prefiksu>)->helpText(<po polsku>)->value(fn () => $this->sample(fn () => $gauges->x()))`, gdzie prywatne `sample(callable): array` łapie `Throwable`, loguje `warning` z nazwą metryki i zwraca brak próbki. **Sprawdź w `vendor/spatie/laravel-prometheus/src`**, jak pakiet renderuje callback zwracający pustą tablicę albo `null`, i dobierz reprezentację „brak próbki” tak, żeby linia metryki nie pojawiła się w wyjściu — test w Step 1 to rozstrzyga.

Zapytania: progi czasu w PHP (`now()->subSeconds(...)`), wieki w PHP z `min(...)` zwróconego przez SQL (`Carbon::parse`). Złączenie rezerwacji: `offer_reservations.reservation_key = 'order-' || orders.id`, `offer_reservations.status = 'active'`, `orders.status IN ('paid','shipped')`. Użyj `OrderStatus::Paid->value` / `OrderStatus::Shipped->value` / `OrderStatus::Placed->value` zamiast literałów, jeśli test architektury pozwala `app/` sięgać do `Ordering\Domain` (sprawdź `tests/Arch/OrderingLayersTest.php`); jeśli nie, literały z komentarzem odsyłającym do enuma.

- [ ] **Step 1: Testy (czerwone)** — `CatalogGaugesTest` (Pest, `RefreshDatabase`, `Carbon::setTestNow`):
  - pusto: wszystkie liczniki `0.0`, wieki `0.0`, sweep `null`;
  - outbox: 2 nieopublikowane (`occurred_at` −10 s i −90 s) + 1 opublikowany −500 s → pending `2.0`, oldest `90.0`;
  - oferta z `pending_requested_at` −2 h → count `1.0`, age `7200.0`;
  - sprzedawca `pending` z `status_changed_at = null`, `created_at` −3 h → age `10800.0`;
  - zamówienia `placed`: `placed_at` −59 s i −61 s → `ordersStuckPlaced() === 1.0`; `awaiting_payment` −600 s się nie liczy;
  - rezerwacje: zamówienie `paid` z rezerwacją `active` (`reservation_key = 'order-'.$id`) → `1.0`; to samo z rezerwacją `confirmed` → `0.0`; zamówienie `awaiting_payment` z `active` → `0.0`; zamówienie `shipped` z `active` → liczy się;
  - sweep: `Cache::put('ordering:sweep:last_run_at', now()->subSeconds(200)->toIso8601String())` → `200.0`;
  - `failed_jobs`: 2 wiersze → `2.0`.
  Dane wstawiaj istniejącymi fabrykami Orderingu/SellerPortal, jeśli są (zob. `tests/Feature/Ordering`), inaczej `DB::table()->insert` z kompletem kolumn NOT NULL.
  `PrometheusEndpointTest`: `get('/prometheus')` → 200, zawiera `catalog_outbox_pending 0` i `# TYPE catalog_failed_jobs gauge`, **nie** zawiera `catalog_ordering_sweep_last_run_age_seconds ` jako linii z wartością; z `CatalogGauges` podmienionym w kontenerze na dublera, którego `failedJobs()` rzuca → 200, pozostałe metryki są, linii `catalog_failed_jobs ` z wartością brak.
- [ ] **Step 2:** `php artisan test --compact --filter=Metrics` → FAIL.
- [ ] **Step 3:** Instalacja pakietu i implementacja.
- [ ] **Step 4:** cały `php artisan test --compact` → PASS; `pint --dirty`; `php artisan route:list --path=prometheus` pokazuje trasę.
- [ ] **Step 5: Commit** `ETAP 11 [5/13]: gauge catalog liczone przy scrape (outbox, moderacja, Ordering)`

### Task 6: Caddy — metryki i ukrycie `/prometheus`

**Files:**
- Modify: `infra/caddy/Caddyfile`
  - w bloku globalnym: `metrics { per_host }`;
  - nowy blok `http://:2020 { metrics }` z komentarzem (dlaczego nie admin API: `:2019` pozwala zmieniać konfigurację; jawny `http://` z tego samego powodu co blok `http://catalog-app`);
  - w bloku `{$SERVER_NAME:catalog.localhost}` przed `php_server`: `@metrics path /prometheus /prometheus/*` + `respond @metrics 404`;
  - w bloku `http://catalog-app`: `route /prometheus { root * public/  php_server }`.

- [ ] **Step 1: Walidacja składni** — `docker run --rm -v "$PWD/infra/caddy/Caddyfile:/etc/frankenphp/Caddyfile:ro" dunglas/frankenphp:1.12.7-php8.5 frankenphp validate --config /etc/frankenphp/Caddyfile --adapter caddyfile` → `Valid configuration`.
- [ ] **Step 2: Commit** `ETAP 11 [6/13]: Caddy — endpoint metryk :2020, /prometheus tylko w sieci wewnętrznej`

Działanie na żywo (404 z hosta, 200 z `catalog-app`, metryki `caddy_http_*` i `frankenphp_*`) sprawdza Task 12.

### Task 7: ES — szablony, pipeline ECS, ILM, użytkownicy (`obs-setup`)

**Files:**
- Create: `infra/obs/elasticsearch/component-mappings.json` (`logs-app@mappings`), `component-settings.json` (`logs-app@settings`: 1 shard, 1 replika, `index.default_pipeline: logs-app`, `index.lifecycle.name` **nie** tu — w szablonie), `template-logs-app.json` (`index_patterns: ["logs-app-*"]`, `data_stream: {}`, `priority: 200`, `composed_of`, `template.settings.index.lifecycle.name: logs-app`), `template-logs-ilmdemo.json` (`logs-ilmdemo-*`, priorytet 200, `lifecycle.name: logs-ilmdemo`), `ilm-logs-app.json`, `ilm-logs-ilmdemo.json`, `pipeline-logs-app.json`, `role-filebeat-writer.json`
- Create: `infra/obs/elasticsearch/setup-obs.sh` (idempotentny; `curl -fsS -u elastic:$ELASTIC_PASSWORD -X PUT` po kolei: komponenty → ILM → pipeline → szablony → rola `filebeat_writer` → użytkownik `filebeat_writer` z `FILEBEAT_WRITER_PASSWORD` → `POST _security/user/remote_monitoring_user/_password` z `REMOTE_MONITORING_PASSWORD` → data view w Kibanie `POST $KIBANA_URL/api/data_views/data_view` z `override: true`, id `logs-app`; na końcu `_simulate` próbek i `grep` asercji — skrypt kończy się kodem ≠ 0, gdy którakolwiek nie przejdzie)
- Create: `infra/obs/elasticsearch/samples/*.json` (dokumenty dla `_simulate`)
- Modify: `compose.yaml` (serwis `obs-setup`, profil `obs`, obraz `marketplace/elasticsearch:${ES_VERSION}`, `depends_on: es01 healthy, kibana healthy`, `restart: "no"`, montuje `./infra/obs/elasticsearch:/obs:ro`)
- Modify: `.env.example` (`FILEBEAT_WRITER_PASSWORD`, `REMOTE_MONITORING_PASSWORD`)

**Interfaces:**
- Produces: pipeline `logs-app` (spec D4), szablony i ILM (spec D3), użytkownik `filebeat_writer` (rola: indeksy `logs-app-*`, `logs-ilmdemo-*` z `create_doc`, `auto_configure`, `view_index_metadata`; klaster `monitor`), hasło `remote_monitoring_user`.
- Pole wejściowe, które zapewnia Filebeat (Task 8): `message` (surowa linia), `fields.app`, `container.labels.com_docker_compose_service`.

Próbki `_simulate` i oczekiwany wynik (asercje w skrypcie przez `jq`):
1. Monolog catalog: `{"message":"Opublikowano zdarzenie do RabbitMQ","context":{"event_id":"01J…"},"level":100,"level_name":"DEBUG","channel":"local","datetime":"2026-10-17T20:00:00.123456+00:00","extra":{"trace_id":"4bf9…"}}` → `trace.id`, `log.level == "debug"`, `@timestamp` z `datetime`, `app.context.event_id`.
2. Monolog search z `context.exception` (`class`, `message`, `trace`) → `error.type`, `error.message`, `error.stack_trace`.
3. Access log Caddy (`logger: "http.log.access.log0"`, `ts`, `request.method`, `request.uri`, `status`, `duration`, `resp_headers.X-Trace-Id: ["4bf9…"]`) → `http.response.status_code`, `url.original`, `event.duration` w ns, `trace.id`, `event.dataset == "catalog.access"`.
4. Linia nie-JSON `"PHP Warning: …"` → dokument istnieje, `tags` zawiera `json_parse_failure`, `event.original` zachowany.
5. JSON bez pól Monologa/Caddy `{"foo":1}` → dokument istnieje, `app.context` albo `event.original` zachowany, bez błędu pipeline'u.

- [ ] **Step 1:** Napisz próbki i asercje (część skryptu) — uruchom `docker compose --env-file /Users/domin/projects/elastic/.env --profile obs run --rm --no-deps obs-setup` → FAIL (brak pipeline'u).
- [ ] **Step 2:** Napisz JSON-y i resztę skryptu.
- [ ] **Step 3:** To samo polecenie → kod 0; `curl -u elastic:… localhost:19200/_index_template/logs-app` pokazuje priorytet 200; drugie uruchomienie też kod 0 (idempotencja).
- [ ] **Step 4:** Ręczny test strumienia: `POST logs-app-catalog/_doc` z próbką 1 (`op_type=create`) → `GET _data_stream/logs-app-catalog` istnieje, `ilm_policy: logs-app`; usuń strumień testowy.
- [ ] **Step 5: Commit** `ETAP 11 [7/13]: ES — pipeline ECS, data streams logs-app-*, ILM, obs-setup`

### Task 8: Filebeat i Metricbeat

**Files:**
- Create: `infra/obs/filebeat/filebeat.yml` — autodiscover `docker` z `templates`: warunek `equals.docker.container.labels.com_docker_compose_project: marketplace` **i** `or` po `com_docker_compose_service` w `{catalog-app, catalog-worker, catalog-scheduler, outbox-publisher, search-consumer, search-metrics}`; wejście `container` (`paths: /var/lib/docker/containers/${data.docker.container.id}/*.log`), `include_lines: ['^\{']`, `fields.app` = `catalog` dla serwisów `catalog-*`/`outbox-publisher`, `search` dla `search-*` (dwa szablony); `output.elasticsearch` z `hosts: [es01:9200]`, `username: filebeat_writer`, `index: "logs-app-%{[fields.app]}"`, `pipeline: logs-app` nie jest potrzebny (default_pipeline w szablonie); `setup.template.enabled: false`, `setup.ilm.enabled: false`; `logging.level: info`; `http.enabled: false`.
- Create: `infra/obs/metricbeat/metricbeat.yml` — moduł `elasticsearch`, `xpack.enabled: true`, `scope: cluster`, `period: 10s`, `hosts: [http://es01:9200]`, `username: remote_monitoring_user`; `output.elasticsearch` do es01 tym samym użytkownikiem; `setup.*` wyłączone.
- Modify: `compose.yaml` — serwisy `filebeat` (`user: root`, `command: ["-e", "--strict.perms=false"]`, montaże `./infra/obs/filebeat/filebeat.yml:/usr/share/filebeat/filebeat.yml:ro`, `/var/lib/docker/containers:/var/lib/docker/containers:ro`, `/var/run/docker.sock:/var/run/docker.sock:ro`, wolumen `filebeat-data:/usr/share/filebeat/data` — rejestr pozycji przeżywa restart), `metricbeat` (analogicznie, bez socketu); oba `depends_on: obs-setup: service_completed_successfully`, `mem_limit: 256m`, profil `obs`.

- [ ] **Step 1:** `docker compose … --profile obs config -q` → bez błędów.
- [ ] **Step 2:** `docker compose … --profile obs up -d --no-deps filebeat metricbeat`; `docker compose … logs filebeat | grep -i error` → pusto po 60 s.
- [ ] **Step 3:** `curl -u elastic:… 'localhost:19200/logs-app-*/_search?size=1&sort=@timestamp:desc'` → dokument z `service.name`, `log.level` (z kontenerów głównego checkoutu — format JSON pojawi się po Task 12, do tego czasu mogą przychodzić tylko access logi Caddy). `GET _cat/indices/.monitoring-es-*?v` → indeks rośnie.
- [ ] **Step 4:** Upewnij się, że kontenery `igrit-*` i `starter-*` **nie** trafiają do ES: `terms` agregacja po `container.name` w `logs-app-*` zawiera tylko `marketplace-*`.
- [ ] **Step 5: Commit** `ETAP 11 [8/13]: Filebeat (tylko JSON z kontenerów marketplace) i Metricbeat dla Stack Monitoring`

### Task 9: Prometheus, reguły z testami, Alertmanager → mailpit

**Files:**
- Create: `infra/obs/prometheus/prometheus.yml` (`scrape_interval: 15s`, `evaluation_interval: 15s`, `rule_files: [/etc/prometheus/rules/*.yml]`, `alerting.alertmanagers: [alertmanager:9093]`, joby z spec D7: `rabbitmq` (`rabbitmq:15692`, `/metrics`), `rabbitmq-queues` (`metrics_path: /metrics/detailed`, `params: {family: [queue_coarse_metrics, queue_consumer_count]}`), `caddy` (`catalog-app:2020`), `catalog` (`catalog-app:80`, `metrics_path: /prometheus`), `search` (`search-metrics:80`), `prometheus`, `alertmanager`)
- Create: `infra/obs/prometheus/rules/marketplace.yml` — 14 alertów dokładnie z tabeli spec D10 (nazwy, wyrażenia, `for`, `severity`); `annotations.runbook` = `https://github.com/Dommmin/elastic/blob/main/docs/RUNBOOK.md#NNN` (wpisy: OutboxStale → #015 lub właściwy wpis outboxu — znajdź `grep -n "outbox" docs/RUNBOOK.md`; SearchConsumerDown/SearchFailedMessages → #050; OrdersStuckPlaced → #045; SettledOrderHoldsReservation → #046; OrderingSweep* → #044; CatalogFailedJobs → #046; nowe wpisy z Task 13 dla pozostałych). `CatalogHttpErrorRate`: `sum(rate(caddy_http_request_duration_seconds_count{host="catalog.localhost",code=~"5.."}[5m])) / sum(rate(caddy_http_request_duration_seconds_count{host="catalog.localhost"}[5m])) > 0.05 and sum(rate(caddy_http_request_duration_seconds_count{host="catalog.localhost"}[5m])) > 0.1` — **zweryfikuj nazwy metryk i etykiet** (`host`, `code`) w żywym `/metrics` Caddy w Task 12 i popraw regułę oraz test, jeśli się różnią.
- Create: `infra/obs/prometheus/rules/marketplace.test.yml` — dla każdego alertu: seria powyżej progu przez czas ≥ `for` → `exp_alerts` z nazwą i `severity`; seria tuż pod progiem (np. 60 dla `> 60`, 0 dla `> 0`) → `exp_alerts: []`. Dla `OrderingSweepNeverRan`: tylko `up{job="catalog"}=1` przez 11 m → odpala; z próbką sweepu → nie.
- Create: `infra/obs/alertmanager/alertmanager.yml` (spec D11: `smtp_smarthost: mailpit:1025`, `smtp_from: alertmanager@marketplace.local`, `smtp_require_tls: false`, odbiorca `email` → `alerts@marketplace.local`, `send_resolved: true`, `group_by: [alertname]`, `group_wait: 10s`, `group_interval: 1m`, `repeat_interval: 4h`)
- Modify: `compose.yaml` — `prometheus` (`--config.file`, `--storage.tsdb.retention.time=15d`, `--web.enable-lifecycle`, wolumen `prometheus-data`, port `127.0.0.1:${PROMETHEUS_PORT}:9090`, 512m), `alertmanager` (port `${ALERTMANAGER_PORT}`, 64m, `depends_on: mailpit`), `mailpit` → `profiles: [tools, obs]` i wersja `${MAILPIT_VERSION}`
- Modify: `Makefile` — `obs-check` (trzy polecenia `docker run --rm` z obrazami `prom/prometheus:${PROMETHEUS_VERSION}` `--entrypoint promtool` i `prom/alertmanager:${ALERTMANAGER_VERSION}` `--entrypoint amtool`: `check config`, `test rules`, `check-config`), `up-obs`, rozszerzenie `urls`
- Modify: `.env.example`, `docs/07-WERSJE.md` (`PROMETHEUS_VERSION`, `ALERTMANAGER_VERSION`, `GRAFANA_VERSION`, `MAILPIT_VERSION` — najnowsze stabilne, sprawdzone `docker manifest inspect`; porty z Global Constraints)

- [ ] **Step 1:** Napisz `marketplace.test.yml`; `make obs-check` → FAIL (brak reguł).
- [ ] **Step 2:** Napisz konfiguracje i reguły.
- [ ] **Step 3:** `make obs-check` → `SUCCESS` dla `test rules`, `check config`, `check-config`.
- [ ] **Step 4:** `docker compose … --profile obs up -d --no-deps mailpit alertmanager prometheus`; `curl -s localhost:19090/api/v1/targets | jq '.data.activeTargets[] | {job: .labels.job, health}'` → `rabbitmq`, `rabbitmq-queues`, `prometheus`, `alertmanager` = `up` (catalog/caddy/search wstaną w Task 12); `curl -s 'localhost:19090/api/v1/query?query=rabbitmq_detailed_queue_consumers' | jq` zwraca serię dla `search.product.sync`.
- [ ] **Step 5: Commit** `ETAP 11 [9/13]: Prometheus, reguły alertów z testami promtool, Alertmanager do mailpita`

### Task 10: `search-metrics` w Compose

**Files:**
- Modify: `compose.yaml` — serwis `search-metrics`: profil `obs`, build jak `search-consumer` (target `dev`), `command: ["frankenphp", "php-server", "--root", "/app/public", "--listen", ":80"]`, te same zmienne `DATABASE_URL` i `MESSENGER_TRANSPORT_DSN` co konsument, wolumen `./apps/search:/app`, `healthcheck: curl -fsS http://localhost/metrics`, `depends_on: postgres healthy`, `mem_limit: 128m`, **bez** `ports`.

- [ ] **Step 1:** `docker compose … --profile obs config -q` → OK.
- [ ] **Step 2: Commit** `ETAP 11 [10/13]: kontener search-metrics w profilu obs`

(Uruchomienie z kodem z worktree w Task 12 — w głównym checkoucie nie ma jeszcze kontrolera.)

### Task 11: Grafana

**Files:**
- Create: `infra/obs/grafana/provisioning/datasources/datasources.yml` (Prometheus `uid: prometheus`, domyślny, `http://prometheus:9090`; Alertmanager `uid: alertmanager`, `implementation: prometheus`, `http://alertmanager:9093`)
- Create: `infra/obs/grafana/provisioning/dashboards/dashboards.yml` (provider plikowy, folder „Marketplace”, `path: /var/lib/grafana/dashboards`)
- Create: `infra/obs/grafana/dashboards/marketplace-overview.json`, `infra/obs/grafana/dashboards/ordering.json` — panele według spec D12; zapytania PromQL z tymi samymi nazwami metryk co Task 5/4/9; link dashboardu „Logi w Kibanie” → `http://localhost:${KIBANA_PORT}/app/discover#/?_g=(time:(from:'${__from:date:iso}',to:'${__to:date:iso}'))&_a=(dataSource:(dataViewId:logs-app,type:dataView))` (port wstawiony na sztywno z `.env.example` jest akceptowalny; zanotuj w przewodniku)
- Modify: `compose.yaml` — `grafana` (`GF_SECURITY_ADMIN_PASSWORD: ${GRAFANA_ADMIN_PASSWORD}`, `GF_AUTH_ANONYMOUS_ENABLED: "false"`, `GF_USERS_ALLOW_SIGN_UP: "false"`, montaże provisioningu i dashboardów `:ro`, wolumen `grafana-data`, port `127.0.0.1:${GRAFANA_PORT}:3000`, 256m, `depends_on: prometheus`)
- Modify: `.env.example` (`GRAFANA_ADMIN_PASSWORD`)

- [ ] **Step 1:** `docker compose … --profile obs up -d --no-deps grafana`; `curl -su admin:$GRAFANA_ADMIN_PASSWORD localhost:13000/api/search?query= | jq '.[].title'` → oba dashboardy; `…/api/datasources` → dwa źródła; `docker compose … logs grafana | grep -i "error"` bez błędów provisioningu.
- [ ] **Step 2: Commit** `ETAP 11 [11/13]: Grafana — źródła i dwa dashboardy z repo`

### Task 12: Logi aplikacji w kontenerach i weryfikacja na żywym stacku (DoD)

**Files:**
- Modify: `compose.yaml` — w `catalog-app`, `outbox-publisher`, `catalog-worker`, `catalog-scheduler`: `LOG_CHANNEL: stack`, `LOG_STACK: single,stderr`, `LOG_STDERR_FORMATTER: Monolog\Formatter\JsonFormatter`, `LOG_LEVEL: debug` (stderr channel ma `PsrLogMessageProcessor`; sprawdź, że `Context` trafia do `extra` w JSON — jeśli nie, dodaj procesor w `config/logging.php`, test w Task 1 stylu)
- Modify: `docs/POMIARY.md` (sekcja „Observability 2026-10-17”: `docker stats --no-stream` dla kontenerów `obs` przed/po, czasy wyzwolenia i zamknięcia alertu, rozmiar `logs-app-*` po godzinie)

Kroki weryfikacji — kod z worktree w kontenerach aplikacji, ze sprzątaniem na końcu:

- [ ] **Step 1: Przełącz kontenery aplikacji na worktree** — `cp` `.env` aplikacji z głównego checkoutu do worktree (gitignored); `docker compose --env-file /Users/domin/projects/elastic/.env build catalog-app` (nowy Caddyfile w obrazie); `docker compose --env-file … --profile apps up -d --no-deps catalog-app outbox-publisher catalog-worker catalog-scheduler search-consumer`; `docker compose … exec catalog-app php artisan migrate --force` (addytywna kolumna `outbox.trace_id`, zgodna z kodem main); `docker compose … --profile obs up -d --no-deps search-metrics`.
- [ ] **Step 2: Endpointy** — `curl -sk https://catalog.localhost:8443/prometheus -o /dev/null -w '%{http_code}'` → `404`; `docker compose … exec prometheus wget -qO- http://catalog-app/prometheus | grep catalog_outbox_pending` → linia; Prometheus targets: **wszystkie** joby `up`. Sprawdź nazwy metryk Caddy (`caddy_http_request_duration_seconds_count` z `host`, `code`) i popraw regułę/test z Task 9, jeśli trzeba (`make obs-check` znowu zielone). `EXPLAIN` zapytania `outbox` z Task 5 w `make psql db=catalog` — przy seq scan dodaj migrację indeksu z Task 5.
- [ ] **Step 3: Alert celowo (DoD)** — `date; docker stop marketplace-search-consumer-1`; odczekaj aż `SearchConsumerDown` będzie `firing` (`curl -s localhost:19090/api/v1/alerts | jq`); mail w mailpicie (`curl -s localhost:18025/api/v1/messages | jq '.messages[].Subject'`); `date; docker start marketplace-search-consumer-1`; mail „RESOLVED”. Zapisz czasy.
- [ ] **Step 4: Drugi alert (Ordering)** — `docker stop marketplace-catalog-worker-1`, złóż zamówienie w przeglądarce (`agent-browser --ignore-https-errors`, konto z `make admin`/seed) → `OrdersStuckPlaced` po ~3 min, mail; `docker start` → resolved.
- [ ] **Step 5: Ślad (DoD)** — zmiana ceny oferty w portalu sprzedawcy przez `agent-browser`, odczyt `X-Trace-Id` (lub `curl -sk -D-` na żądanie zmiany ceny z sesją), potem `GET logs-app-*/_search` z `term: {trace.id: …}` i `sort: @timestamp` → dokumenty z `service.name` = `catalog-app` (access + aplikacja), `outbox-publisher`, `search-consumer` (odbiór i „Indexed”) oraz `catalog-app` z `url.original` `/api/internal/products/…/projection`. Zrzut wyniku do przewodnika.
- [ ] **Step 6: ILM demo** — 20× `POST logs-ilmdemo-test/_doc`, potem co minutę `GET logs-ilmdemo-test/_ilm/explain` aż backing index przejdzie hot → warm → (po kolejnych minutach) zostanie usunięty; zanotuj czasy.
- [ ] **Step 7: Stack Monitoring** — Kibana → Stack Monitoring pokazuje klaster `marketplace-local` i 3 węzły (zrzut ekranu `agent-browser`).
- [ ] **Step 8: RAM** — `docker stats --no-stream` profilu `obs` → `POMIARY.md`.
- [ ] **Step 9: Powrót do głównego checkoutu** — z `/Users/domin/projects/elastic`: `docker compose --profile apps up -d --no-deps catalog-app outbox-publisher catalog-worker catalog-scheduler search-consumer` (kontenery znów montują main); `search-metrics` i reszta `obs` zostają uruchomione z worktree tylko do końca weryfikacji — zatrzymaj `search-metrics` (`docker compose … stop search-metrics`), bo main nie ma kontrolera. Kolumna `outbox.trace_id` zostaje (nullable, main jej nie używa) — zanotuj w RUNBOOK.
- [ ] **Step 10: Commit** `ETAP 11 [12/13]: logi JSON w kontenerach catalog, pomiary observability`

### Task 13: RUNBOOK, plan, przewodnik, PR

**Files:**
- Modify: `docs/RUNBOOK.md` — nowe wpisy (#051…) z prawdziwymi problemami z Task 7–12 (objaw, diagnoza, przyczyna, naprawa) oraz po jednym wpisie dla alertów bez istniejącego wpisu (`DeadLetterNotEmpty`, `SearchSyncBacklog`, `CatalogHttpErrorRate`, `TargetDown`, `OfferModerationOverdue`/`SellerApprovalOverdue`); w #050 „Czego się nauczyłem” → odnośnik do `SearchFailedMessages`. Zaktualizuj `annotations.runbook` w regułach do nowych numerów, `make obs-check`.
- Modify: `docs/06-PLAN-WDROZENIA.md` (ETAP 11: co zrobione, co przeniesione do kolejnego PR — lista z „Poza zakresem” specu), `docs/01-INFRASTRUKTURA.md` (profil `obs`: lista kontenerów i portów), `README.md` (tabela statusu, link do przewodnika, `make up-obs`), komentarz nagłówka `compose.yaml` (profil `obs`).
- Create: `docs/blog/etap-11-observability.md` — w stylu `docs/blog/etap-f-zamowienia-saga.md`: co budujemy i po co (ADR-0005), krok po kroku każdy element z kodem i „dlaczego tak”, jak sprawdzić (polecenia z Task 12 z prawdziwymi wynikami), każdy błąd z diagnozą, lista ręcznej weryfikacji (Grafana, Stack Monitoring, Discover po `trace.id`), zadania domowe (np. dopisać alert, przejść na `promphp` w search).
- [ ] **Step 1:** Napisz dokumenty; `make obs-check`; `php artisan test --compact` i `vendor/bin/phpunit tests/Unit` (search) ostatni raz → zielone.
- [ ] **Step 2: Commit** `ETAP 11: RUNBOOK, plan, przewodnik observability`
- [ ] **Step 3: PR** — push gałęzi `observability` robi użytkownik (push z sesji jest zablokowany); po pushu `gh pr create --base main --head observability` z opisem: zakres, decyzje, wyniki weryfikacji (czasy alertu, ślad, RAM), co poza zakresem; stopka `🤖 Generated with [Claude Code](https://claude.com/claude-code)`.
