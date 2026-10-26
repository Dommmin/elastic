# ETAP 11b — GlitchTip Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wyjątki z `catalog` (HTTP/Octane, kolejka, komendy), `search` (Messenger) i przeglądarki (Vue/Inertia) trafiają jako issues do self-hosted GlitchTipa z tagiem `trace_id`, bez szumu i bez PII.

**Architecture:** Jeden kontener GlitchTip all-in-one (profil `errors`, baza `glitchtip` w istniejącym Postgresie, bez Valkey). Organizacja, projekty, stałe klucze DSN i reguły alertów zakłada idempotentny bootstrap. Backend używa oficjalnych SDK Sentry, filtrowanych istniejącym handlerem wyjątków. Przeglądarka wysyła przez tunel `/_errors` w Caddy z celem wpisanym na sztywno.

**Tech Stack:** GlitchTip 6.2.6, `sentry/sentry-laravel` 4.28.0, `sentry/sentry-symfony` 5.13.0, `@sentry/vue` 10.76.2, `@sentry/cli` 3.8.0, Caddy (FrankenPHP), Pest, PHPUnit, Playwright.

**Spec:** `docs/superpowers/specs/2026-10-26-glitchtip-design.md`

## Global Constraints

- Wersje przypięte dokładnie (zasada `07-WERSJE.md`): `GLITCHTIP_VERSION=6.2.6`, `sentry/sentry-laravel:4.28.0`, `sentry/sentry-symfony:5.13.0`, `@sentry/vue@10.76.2`, `@sentry/cli@3.8.0`.
- Pusty DSN = SDK wyłączone: żadnego połączenia, żadnego błędu (spec, zasada 6).
- `send_default_pii=false`, `max_request_body_size='never'`, `traces_sample_rate=0`, bez Replay, bez handlera Monologa → Sentry (zasady 2, 3, 5).
- Transport z timeoutami `http_connect_timeout=1`, `http_timeout=2` (PHP). Błąd wysyłki nigdy nie przerywa żądania ani wiadomości (zasada 7).
- Każde zdarzenie ma tag `trace_id` i tag `process` (zasada 4).
- Porty tylko `127.0.0.1`: `GLITCHTIP_PORT=18000`. Każdy kontener ma `mem_limit`. Komendy Make nie odtwarzają współdzielonych kontenerów (`--no-deps`).
- Nie zatrzymujemy cudzych kontenerów na maszynie (`igrit-*`, `starter-*`).
- Język komentarzy, commitów i dokumentacji: polski, jak w repo. Commit kończy się linią `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Fakty z kroku 0 (zweryfikowane na obrazie 6.2.6):
  - health pod `GET /_health/`, w obrazie brak curl/wget (healthcheck przez `python`),
  - envelope `POST /api/<id>/envelope/` przyjmuje `?sentry_key=`,
  - artifact bundles (Debug IDs) są obsługiwane,
  - modele: `projects.Project`/`ProjectKey(public_key: UUID)`, `alerts.ProjectAlert(timespan_minutes, quantity)` + `AlertRecipient(recipient_type="email")`, `api_tokens.APIToken(token: str[64], scopes: BitField)`, `teams.Team(slug, organization, members, projects)`, `organizations_ext.Organization` + `add_user(user, role=OrganizationUserRole.OWNER)`.

## Review Focus

1. **GlitchTip nie działa albo nie odpowiada.** Żądanie z wyjątkiem kończy się zwykłym 500 w < 3 s, a konsument nie wisi. Pokrycie: Task 3 (test konfiguracji timeoutów), Task 7 (smoke: zatrzymany GlitchTip, pomiar czasu).
2. **Wrażliwe dane w żądaniu** (hasło w POST, ciasteczko sesji, e-mail). Zdarzenie nie ma body, ciasteczek, IP ani e-maila. Pokrycie: Task 3 (test POST z polem `password`).
3. **Job z retry.** Pierwsze, przejściowe niepowodzenie nie tworzy issue, ostatnie tworzy dokładnie jedno. Pokrycie: Task 3 (`QueueRetryFilter`), Task 5 (Messenger).
4. **Długożyjący proces** (Octane, worker, konsument). `trace_id` i `user.id` poprzedniej jednostki pracy nie przeciekają do następnej. Pokrycie: Task 3 i Task 5.
5. **Tunel `/_errors` jako furtka.** Koperta z DSN innego projektu ląduje w `catalog-frontend`, za duże body dostaje 413, metoda inna niż POST dostaje 405. Pokrycie: Task 6 (test Caddy curlami w smoke Task 7).

---

### Task 1: GlitchTip w profilu `errors` i bootstrap jako kod

**Files:**
- Create: `infra/glitchtip/setup-db.sh`, `infra/glitchtip/bootstrap.py`, `infra/glitchtip/healthcheck.py`
- Modify: `compose.yaml` (nagłówek z listą profili; serwisy `glitchtip`, `glitchtip-db-setup` (obraz postgres, `setup-db.sh`), `glitchtip-setup` (obraz GlitchTipa, `bootstrap.py`); wolumen `glitchtip-uploads`; `postgres.environment` + `GLITCHTIP_DB*`; `mailpit.profiles` += `errors`)
- Modify: `infra/postgres/init/01-databases.sh` (trzecie `create_db`)
- Modify: `.env.example`, `Makefile` (`up-errors`), `tools/doctor.sh` (profil `errors`)

**Interfaces:**
- Produces (`.env.example`, czytane przez Taski 3–7):
  - `GLITCHTIP_VERSION=6.2.6`, `GLITCHTIP_PORT=18000`,
  - `GLITCHTIP_DB=glitchtip`, `GLITCHTIP_DB_USER=glitchtip`, `GLITCHTIP_DB_PASSWORD=glitchtip_dev_2026`,
  - `GLITCHTIP_SECRET_KEY`, `GLITCHTIP_ADMIN_EMAIL=admin@marketplace.localhost`, `GLITCHTIP_ADMIN_PASSWORD=glitchtip_dev_2026`,
  - `GLITCHTIP_API_TOKEN` (64 znaki hex, tylko dev),
  - `GLITCHTIP_KEY_CATALOG`, `GLITCHTIP_KEY_FRONTEND`, `GLITCHTIP_KEY_SEARCH` (UUID w zapisie hex bez myślników, tylko dev).
- Produces: projekty o **stałych ID** w bootstrapie: `catalog`=1, `catalog-frontend`=2, `search`=3 (DSN `http://<key>@glitchtip:8000/<id>`). Bootstrap tworzy je w tej kolejności na pustej bazie i asercją sprawdza ID.
- Produces: `make up-errors`, serwis `glitchtip` w sieci `marketplace` pod `glitchtip:8000`.

- [ ] **Step 1: `setup-db.sh` — idempotentna baza.**
  Ten sam SQL co `create_db` w `01-databases.sh`, ale w bloku `DO $$ … IF NOT EXISTS (SELECT FROM pg_roles …)`. Bazę tworzy `SELECT 'CREATE DATABASE …' WHERE NOT EXISTS (…) \gexec`, bo `CREATE DATABASE` nie może być w `DO`. Do tego granty na schemat `public`. Uruchamiany obrazem `postgres:${POSTGRES_VERSION}-alpine` z `PGHOST=postgres`. Dopisz też `create_db "${GLITCHTIP_DB}" …` do `01-databases.sh`, żeby świeży wolumen miał bazę od razu.
- [ ] **Step 2: serwis `glitchtip`** (profil `errors`):
  - obraz `glitchtip/glitchtip:${GLITCHTIP_VERSION}`,
  - env z spec 2.1: `GLITCHTIP_EMBED_WORKER=true`, `VALKEY_URL=""`, `DATABASE_URL`, `SECRET_KEY`, `GLITCHTIP_DOMAIN=http://localhost:${GLITCHTIP_PORT}`, `EMAIL_URL=smtp://mailpit:1025`, `DEFAULT_FROM_EMAIL=glitchtip@marketplace.localhost`, `ENABLE_USER_REGISTRATION=false`, `ENABLE_ORGANIZATION_CREATION=false`, `GLITCHTIP_ENABLE_LOGS=false`, `GLITCHTIP_ENABLE_UPTIME=false`, `ENABLE_OBSERVABILITY_API=true`, `GLITCHTIP_RETENTION_DAYS=14`,
  - port `127.0.0.1:${GLITCHTIP_PORT}:8000`, wolumen `glitchtip-uploads:/code/uploads`, `mem_limit: 512m`,
  - healthcheck `python /healthcheck.py` (urllib `GET http://localhost:8000/_health/`, exit 0 przy 200), `start_period: 60s`,
  - `depends_on: postgres healthy`, `restart: unless-stopped`.
- [ ] **Step 3: `bootstrap.py`** (uruchamiany `./manage.py shell < /bootstrap.py` w serwisie `glitchtip-setup` z obrazem GlitchTipa, z tymi samymi env co `glitchtip`). Idempotentnie (`get_or_create` / `update_or_create`):
  - superuser,
  - organizacja `marketplace` + `add_user(owner, OWNER)`,
  - zespół `platform` z adminem,
  - trzy projekty ze `scrub_ip_addresses=True`, przypięte do zespołu,
  - dla każdego projektu jeden `ProjectKey` z `public_key=UUID(env)` (pozostałe klucze projektu `is_active=False`),
  - `ProjectAlert(name="Nowy issue", timespan_minutes=1, quantity=1)` + `AlertRecipient(recipient_type="email")`,
  - `APIToken(token=env, user=admin, label="sentry-cli")` ze scopes `project:read, project:write, project:releases, event:read, org:read`.
  Na końcu asercje (ID projektów 1/2/3, aktywne klucze równe env, alert istnieje, token istnieje). Przy niezgodności skrypt drukuje, co nie pasuje, i kończy `sys.exit(1)`. Uwaga: projekty o ID ≠ 1/2/3 na istniejącej bazie (ktoś klikał w UI) to błąd z instrukcją „make errors-reset” z RUNBOOK.
- [ ] **Step 4: Makefile `up-errors`:**
  1. `run --rm --no-deps glitchtip-db-setup` (faza 1),
  2. `up -d --no-deps mailpit glitchtip`,
  3. czekanie na `healthy` (pętla `docker inspect`, limit 120 s),
  4. `run --rm --no-deps glitchtip-setup` (bootstrap),
  5. echo URL-a UI i loginu.
  Dodaj `errors-reset`: drop i create bazy `glitchtip` po potwierdzeniu (`read`), potem `up-errors`. `doctor.sh errors`: port `GLITCHTIP_PORT` wolny lub zajęty przez nasz kontener, budżet +512 MB.
- [ ] **Step 5: Weryfikacja.**
  Run: `make up && make up-errors`
  Expected: bootstrap kończy się `OK: 3 projekty, klucze, alerty, token`.
  Run: `make up-errors` (drugi raz)
  Expected: znów `OK`, bez duplikatów (`docker compose exec postgres psql -U postgres -d glitchtip -c "select count(*) from projects_projectkey where is_active"` → 3).
  Run: `curl -s -o /dev/null -w '%{http_code}' http://localhost:18000/_health/`
  Expected: `200`.
  Run: `curl -s -H "Authorization: Bearer $GLITCHTIP_API_TOKEN" http://localhost:18000/api/0/organizations/marketplace/projects/ | python3 -m json.tool | grep slug`
  Expected: `catalog`, `catalog-frontend`, `search`.
- [ ] **Step 6: Commit** `ETAP 11b [1/8]: GlitchTip all-in-one w profilu errors, bootstrap jako kod`

### Task 2: GlitchTip w Prometheusie i alert `GlitchTipDown`

**Files:**
- Modify: `infra/obs/prometheus/prometheus.yml` (job `glitchtip`, target `glitchtip:8000`, `metrics_path: /metrics`)
- Modify: plik reguł w `infra/obs/prometheus/` (ten sam, co `SearchMetricsScrapeError`), `marketplace.test.yml`

**Interfaces:**
- Consumes: serwis `glitchtip:8000` z Task 1, `ENABLE_OBSERVABILITY_API=true`.

- [ ] **Step 1: Test promtool:** w `marketplace.test.yml` scenariusz `up{job="glitchtip"} == 0` przez 5m → alert `GlitchTipDown` (severity `warning`, `runbook_url` → `#070`). Drugi scenariusz: brak serii `up{job="glitchtip"}` (profil `errors` wyłączony) → **brak** alertu, bo GlitchTip jest opcjonalny.
- [ ] **Step 2: Run** `make obs-check` (lub cel promtool z Makefile). Expected: FAIL (brak reguły).
- [ ] **Step 3: Reguła** `GlitchTipDown: up{job="glitchtip"} == 0`, `for: 5m`, plus job scrape.
- [ ] **Step 4: Run** `make obs-check`. Expected: PASS. Przy działających `obs` i `errors`: `curl -s localhost:19090/api/v1/targets | grep -o '"job":"glitchtip"[^}]*"health":"up"'` daje trafienie.
- [ ] **Step 5: Commit** `ETAP 11b [2/8]: scrape GlitchTipa i alert GlitchTipDown`

### Task 3: `catalog` backend — sentry-laravel

**Files:**
- Modify: `apps/catalog/composer.json` (`sentry/sentry-laravel: 4.28.0`)
- Create: `apps/catalog/config/sentry.php` (publikacja + nasze wartości)
- Create: `apps/catalog/app/Support/ErrorTracking/EnrichEvent.php`, `apps/catalog/app/Support/ErrorTracking/QueueRetryFilter.php`
- Modify: `apps/catalog/bootstrap/app.php` (`Integration::handles($exceptions)`; `dontReportWhen` z `QueueRetryFilter`)
- Modify: `apps/catalog/app/Providers/AppServiceProvider.php` (rejestracja listenerów `QueueRetryFilter`)
- Modify: `compose.yaml` (kotwica `x-error-tracking-catalog` w `catalog-app`, `catalog-worker`, `outbox-publisher`, `catalog-scheduler`; `ERROR_TRACKING_PROCESS` = `http` / `queue` / `outbox-publisher` / `scheduler`)
- Create: `apps/catalog/tests/Support/FakeSentryTransport.php`, `apps/catalog/tests/Feature/ErrorTracking/ErrorTrackingTest.php`

**Interfaces:**
- Consumes: `App\Support\Tracing\TraceId::current(): string`.
- Produces:
  - `EnrichEvent::handle(\Sentry\Event $event, ?\Sentry\EventHint $hint): ?\Sentry\Event` (statyczna, wpięta w `config('sentry.before_send')` jako `[EnrichEvent::class, 'handle']`),
  - `config('sentry.frontend.dsn')`, `config('sentry.frontend.environment')`, `config('sentry.frontend.release')` (env `SENTRY_FRONTEND_DSN`; czyta Task 6),
  - `QueueRetryFilter::willRetry(): bool`.
- Konfiguracja `config/sentry.php`:
  - `dsn` = `SENTRY_LARAVEL_DSN`, `environment` = `SENTRY_ENVIRONMENT` (domyślnie `APP_ENV`), `release` = `SENTRY_RELEASE` (domyślnie `APP_VERSION`, potem `dev`),
  - `traces_sample_rate` 0, `send_default_pii` false, `max_request_body_size` `'never'`, `http_connect_timeout` 1, `http_timeout` 2,
  - `breadcrumbs.sql_bindings` false, `breadcrumbs.logs` true.
  - Env w compose: `SENTRY_LARAVEL_DSN=${GLITCHTIP_DSN_CATALOG:-}` (w `.env.example`: `GLITCHTIP_DSN_CATALOG=http://${GLITCHTIP_KEY_CATALOG}@glitchtip:8000/1`; lokalnie puste, dopóki ktoś nie odpali profilu), `SENTRY_FRONTEND_DSN=${GLITCHTIP_DSN_FRONTEND:-}` z kluczem frontendu i ID 2 (host bez znaczenia, przeglądarka używa tunelu).

`QueueRetryFilter` (algorytm, którego sygnatury nie wyznaczają): Laravel raportuje wyjątek **każdej** próby joba, a `JobFailed` leci przed `report()` tylko przy ostatniej. Listener `JobProcessing` ustawia `inJob=true, finalFailure=false`. `JobFailed` ustawia `finalFailure=true`. `JobProcessed` / `JobReleasedAfterException` / `JobExceptionOccurred` stanu nie zmieniają. `willRetry()` zwraca `inJob && !finalFailure`. Stan trzyma singleton zarejestrowany jako `scoped`, czyli Octane i każdy job zaczynają od czystego stanu. W `bootstrap/app.php`: `dontReportWhen(fn () => app(QueueRetryFilter::class)->willRetry())`. To samo wycisza wtedy linię w logu, co jest zgodne z zachowaniem istniejących `dontReportWhen`: przejściowa próba to nie awaria. Job przechodzi do `failed_jobs` z pełnym wyjątkiem w logu, kiedy próby się skończą.

- [ ] **Step 1: Testy** w `ErrorTrackingTest.php`. Helper `fakeSentry(): FakeSentryTransport` ustawia `sentry.dsn` na `http://00000000000000000000000000000001@glitchtip/1`, buduje klienta przez `app(\Sentry\ClientBuilder::class)` (binding z ServiceProvidera pakietu; jeśli w 4.28 inaczej, wziąć to, czego używa `Sentry\Laravel\ServiceProvider`), podstawia transport i binduje do `SentrySdk::getCurrentHub()`. Testy:
  - `it reports an unexpected exception from an HTTP request with trace_id equal to X-Trace-Id`: testowa trasa rzuca `RuntimeException('boom')`; jedno zdarzenie, `tags['trace_id'] === $response->headers->get('X-Trace-Id')`, `tags['process'] === 'http'`.
  - `it attaches user id but no email or ip`: zalogowany użytkownik; `user->getId() === (string) $user->id`, `getEmail()` null, `getIpAddress()` null.
  - `it sends no request body`: POST `password=secret123` → `json_encode($event->getRequest())` nie zawiera `secret123`.
  - `it does not report domain exceptions, 404 or validation in HTTP`: trzy żądania → 0 zdarzeń.
  - `it reports an exception thrown by a queued job with trace_id from Context`: `Context::add('trace_id', str_repeat('a', 32))`, dispatch joba testowego (`tries=1`), worker `--once` (`Queue::fake` nie, `sync` też nie: `Artisan::call('queue:work', ['--once'=>true, '--queue'=>...])` na połączeniu `database` z testów). Jedno zdarzenie z tagiem `aaaa…`.
  - `it does not report a job attempt that will be retried`: job z `tries=2`, który rzuca → po pierwszym `--once` 0 zdarzeń, po drugim 1.
  - `it does not leak trace_id between requests`: dwa żądania z różnymi `traceparent`, każde rzuca → dwa zdarzenia z różnymi `trace_id`, drugie bez `user` pierwszego.
  - `it has no client when DSN is empty`: `config(['sentry.dsn'=>null])` + przeładowanie providera → `SentrySdk::getCurrentHub()->getClient()` null, a żądanie z wyjątkiem zwraca 500.
  - `it pins transport timeouts`: `config('sentry.http_timeout') === 2`, `config('sentry.http_connect_timeout') === 1`.
- [ ] **Step 2: Run** `docker compose exec catalog-app php artisan test --filter=ErrorTracking` (wg [[kontener-montuje-glowny-checkout]] w głównym checkoutcie). Expected: FAIL (brak pakietu i klas).
- [ ] **Step 3: Implementacja:** `composer require sentry/sentry-laravel:4.28.0`, `config/sentry.php`, `EnrichEvent`, `QueueRetryFilter`, wpięcie w `bootstrap/app.php`. `EnrichEvent` dodaje `trace_id` z `TraceId::current()`, `process` z `env('ERROR_TRACKING_PROCESS', 'http')` (przez config `sentry.process`, bo `config:cache`) i `user.id` z `auth()->id()` (`$event->setUser(UserDataBag::createFromUserIdentifier(...))`). Kotwica compose.
  Pętla `PublishOutboxCommand`: jedyne `catch` (AMQPTimeoutException) i nack to oczekiwany retry, więc **bez** `report()`. Wyjątek, który wywala komendę, raportuje handler konsoli. Zapisz to jednym zdaniem w komentarzu przy `catch`.
- [ ] **Step 4: Run** testy z Step 2 + cała suita: `php artisan test`, `vendor/bin/pint --test`, `vendor/bin/phpstan`. Expected: PASS.
- [ ] **Step 5: Commit** `ETAP 11b [3/8]: catalog — sentry-laravel, trace_id i user.id, raport tylko ostatniej próby joba`

### Task 4: `search` backend — sentry-symfony

**Files:**
- Modify: `apps/search/composer.json` (`sentry/sentry-symfony: 5.13.0`, recipe Flex)
- Create: `apps/search/config/packages/sentry.yaml`, `apps/search/src/ErrorTracking/EnrichEvent.php`
- Modify: `apps/search/.env` (`SENTRY_DSN=`, `ERROR_TRACKING_PROCESS=consumer`)
- Modify: `compose.yaml` (`search-consumer`: `SENTRY_DSN=${GLITCHTIP_DSN_SEARCH:-}`, `ERROR_TRACKING_PROCESS=consumer`, `SENTRY_RELEASE=${APP_VERSION:-dev}`; `search-metrics`: to samo z `metrics`)
- Create: `apps/search/tests/Integration/ErrorTracking/MessengerErrorTrackingTest.php`, `apps/search/tests/Support/FakeSentryTransport.php`

**Interfaces:**
- Consumes: `App\Tracing\TraceContext` (`get(): ?string`), `App\Messenger\TraceIdStamp`.
- Produces: serwis `App\ErrorTracking\EnrichEvent` z `__invoke(\Sentry\Event $event, ?\Sentry\EventHint $hint): ?\Sentry\Event`, wpięty jako `sentry.options.before_send`.
- `sentry.yaml`:
  - `dsn: '%env(SENTRY_DSN)%'`, `register_error_listener: true`, `register_error_handler: true`,
  - `messenger: { enabled: true, capture_soft_fails: false }`,
  - `tracing: { enabled: false }`,
  - `options`: `environment: '%kernel.environment%'`, `release: '%env(default:sentry_default_release:SENTRY_RELEASE)%'`, `send_default_pii: false`, `max_request_body_size: never`, `http_connect_timeout: 1`, `http_timeout: 2`, `before_send: App\ErrorTracking\EnrichEvent`.
  - `when@test`: DSN testowy `http://00000000000000000000000000000001@glitchtip/3` z podstawionym transportem (`Sentry\Transport\TransportInterface` → `FakeSentryTransport`, przez `transport_factory` lub alias usługi, zależnie od tego, co wspiera 5.13).
  - Bez handlera Monologa (bundle go nie dodaje sam; sprawdź, czy recipe go nie dopisał do `monolog.yaml`).

- [ ] **Step 1: Testy** (Messenger in-memory transport + Worker z dispatcherem z kontenera, jak w istniejących `tests/Integration/Messenger`):
  - `testRetriedThenSucceededMessageIsNotReported`: handler rzuca raz, potem przechodzi → 0 zdarzeń.
  - `testMessageFailingAfterAllRetriesIsReportedOnceWithTraceIdFromStamp`: koperta z `TraceIdStamp(str_repeat('b',32))`, handler zawsze rzuca, `max_retries` z konfiguracji transportu testowego = 1 → dokładnie 1 zdarzenie, `tags['trace_id']==='bbbb…'`, `tags['process']==='consumer'`.
  - `testNextMessageWithoutStampHasNoStaleTraceId`: po powyższej wiadomość bez stampa, która pada → zdarzenie bez tagu `trace_id`.
  - `testEventIsFlushedBeforeWorkerStops`: po obsłudze wiadomości (przed `stop()`) transport ma już zdarzenie.
- [ ] **Step 2: Run** `docker compose exec search-consumer php bin/phpunit tests/Integration/ErrorTracking`. Expected: FAIL.
- [ ] **Step 3: Implementacja** wg Interfaces. `EnrichEvent` ustawia `trace_id` tylko przy niepustym `TraceContext::get()`, a `process` z `%env(ERROR_TRACKING_PROCESS)%`.
- [ ] **Step 4: Run** cała suita `php bin/phpunit` + `php bin/console lint:container`. Expected: PASS.
- [ ] **Step 5: Commit** `ETAP 11b [4/8]: search — sentry-symfony, raport tylko po wyczerpaniu retry, trace_id ze stampa`

### Task 5: Frontend `catalog` — konfiguracja w runtime i SDK

**Files:**
- Modify: `apps/catalog/resources/views/app.blade.php` (meta `error-tracking-dsn`, `error-tracking-environment`, `error-tracking-release`, tylko przy niepustym `config('sentry.frontend.dsn')`)
- Create: `apps/catalog/resources/js/lib/errorTracking.ts`
- Modify: `apps/catalog/resources/js/app.ts` (callback `setup` w `createInertiaApp` wywołuje `initErrorTracking(app)` przed `mount`)
- Modify: `apps/catalog/package.json` (`@sentry/vue: 10.76.2`)
- Create: `apps/catalog/tests/Feature/ErrorTracking/FrontendConfigTest.php`

**Interfaces:**
- Consumes: `config('sentry.frontend.*')` z Task 3.
- Produces:
  - `initErrorTracking(app: App): void` (no-op bez meta DSN),
  - czyste funkcje w tym samym pliku: `readConfig(doc: Document): ErrorTrackingConfig | null`, `shouldDrop(event: ErrorEvent): boolean`,
  - stała `TUNNEL_PATH = '/_errors'` (czyta Task 6).
- Opcje `Sentry.init`: `app`, `dsn`, `environment`, `release`, `tunnel: TUNNEL_PATH`, `sendDefaultPii: false`, `tracesSampleRate: 0`, `integrations` bez tracing/replay, `allowUrls: [window.location.origin]`, `ignoreErrors: [/ResizeObserver loop/, /Request aborted/]` (to drugie to anulowana wizyta Inertii), `beforeSend` → `shouldDrop`.
- Breadcrumbs: `router.on('finish' | 'invalid' | 'exception')` (Inertia v3) dodaje `Sentry.addBreadcrumb({ category: 'inertia', data: { url, status, trace_id } })`, gdzie `trace_id` to nagłówek `X-Trace-Id` odpowiedzi, jeśli dostępny. Odpowiedź 500 nie wywołuje `captureException` (backend już ją ma).

- [ ] **Step 1: Test Pest** `FrontendConfigTest`:
  - `it renders no error-tracking meta when frontend DSN is empty`: `get('/')` nie zawiera `error-tracking-dsn`.
  - `it renders dsn, environment and release meta when configured`: `config(['sentry.frontend.dsn'=>'http://k@glitchtip/2', 'sentry.frontend.environment'=>'local', 'sentry.frontend.release'=>'abc123'])` → wszystkie trzy wartości w `content="…"`.
- [ ] **Step 2: Run** `php artisan test --filter=FrontendConfig`. Expected: FAIL.
- [ ] **Step 3: Implementacja** wg Interfaces.
- [ ] **Step 4: Run** test z Step 2, `npm run lint:check`, `npm run format:check`, `npm run types:check`, `npm run build` (w `catalog-vite`, Wayfinder z `--with-form` wg [[wayfinder-with-form]]). Expected: wszystko PASS.
- [ ] **Step 5: Commit** `ETAP 11b [5/8]: frontend — @sentry/vue z konfiguracją z serwera, breadcrumbs z X-Trace-Id`

### Task 6: Tunel `/_errors` w Caddy, source mapy i release

**Files:**
- Modify: `infra/caddy/Caddyfile` (blok `handle /_errors` w hoście `{$SERVER_NAME}`)
- Modify: `compose.yaml` (`catalog-app`: `GLITCHTIP_FRONTEND_PROJECT_ID=2`, `GLITCHTIP_FRONTEND_KEY=${GLITCHTIP_KEY_FRONTEND:-}`, `GLITCHTIP_UPSTREAM=glitchtip:8000`)
- Modify: `apps/catalog/vite.config.ts` (`build.sourcemap: 'hidden'`)
- Modify: `infra/php/catalog.Dockerfile` (etap `prod`: `find public/build -name '*.map' -delete` po kopii buildu)
- Modify: `apps/catalog/package.json` (devDependency `@sentry/cli: 3.8.0`)
- Modify: `Makefile` (`errors-sourcemaps`)

**Interfaces:**
- Consumes: `TUNNEL_PATH` z Task 5, projekt 2 i klucz frontendu z Task 1.
- Produces: `POST https://catalog.localhost:8443/_errors` → `glitchtip:8000/api/2/envelope/?sentry_key=<GLITCHTIP_FRONTEND_KEY>`.

Caddy:
- `handle /_errors` z matcherem `method POST`. Inne metody dostają `respond 405`.
- `request_body { max_size 1MB }`.
- `rewrite * /api/{$GLITCHTIP_FRONTEND_PROJECT_ID}/envelope/?sentry_key={$GLITCHTIP_FRONTEND_KEY}`, potem `reverse_proxy {$GLITCHTIP_UPSTREAM}` z `header_up -Cookie` i `header_up Host glitchtip`, plus `lb_try_duration 0`.
- Przy pustym kluczu: `respond 204`, czyli tunel bez GlitchTipa po cichu nic nie robi.
- Upstream niedostępny daje 502 (SDK ignoruje).
- Komentarz wyjaśnia, dlaczego cel jest na sztywno (brak open relay).

- [ ] **Step 1: Weryfikacja ręczna jako „test”** (stos `apps` + `errors`):
  - `curl -sk -o /dev/null -w '%{http_code}' -X GET https://catalog.localhost:${CATALOG_HTTPS_PORT}/_errors` → `405`,
  - koperta 2 MB → `413`,
  - minimalna koperta z nagłówkiem DSN projektu `search` → `200`, a issue pojawia się w `catalog-frontend` (sprawdzenie przez API). Zautomatyzowane w Task 7.
- [ ] **Step 2: Implementacja** Caddy + env. `vite.config.ts`: `build: { sourcemap: 'hidden' }`. Dockerfile: usuwanie map w `prod`.
- [ ] **Step 3: `make errors-sourcemaps`** w `catalog-vite`:
  - `RELEASE=$(git rev-parse --short HEAD)`,
  - `npm run build`,
  - `npx sentry-cli sourcemaps inject public/build`,
  - `npx sentry-cli --url http://glitchtip:8000 --auth-token $GLITCHTIP_API_TOKEN sourcemaps upload --org marketplace --project catalog-frontend --release $RELEASE public/build`.
  Wypisuje, że do zobaczenia mapowanych ramek trzeba ustawić `SENTRY_RELEASE=$RELEASE` i przeładować `catalog-app`. Kontener `catalog-vite` potrzebuje sieci `marketplace` (już ją ma).
- [ ] **Step 4: Run** Step 1 + `make errors-sourcemaps`. Expected:
  - kody 405 / 413 / 200,
  - `sentry-cli` kończy się `Uploaded files to Sentry` i bundle ma Debug IDs,
  - `docker build --target prod -f infra/php/catalog.Dockerfile .` → `docker run --rm <img> sh -c 'find public/build -name "*.map" | wc -l'` daje `0`.
- [ ] **Step 5: Commit** `ETAP 11b [6/8]: tunel /_errors w Caddy, ukryte source mapy, upload przez sentry-cli`

### Task 7: Smoke end-to-end `make errors-smoke`

**Files:**
- Create: `apps/catalog/routes/debug.php` (ładowany w `bootstrap/app.php` tylko gdy `app()->environment('local')`): `GET /_debug/errors/http` rzuca `RuntimeException("errors-smoke:http:{marker}")`, `GET /_debug/errors/frontend` renderuje stronę Inertia `debug/ErrorsSmoke` (rzuca w `onMounted` z markerem z query).
- Create: `apps/catalog/resources/js/pages/debug/ErrorsSmoke.vue`
- Create: `apps/catalog/app/Console/Commands/ErrorsSmokeCommand.php` (`errors:smoke {marker} --queue`: dispatch joba `ErrorsSmokeJob` z `tries=1`, który rzuca `errors-smoke:queue:{marker}`)
- Create: `apps/search/src/Command/ErrorsSmokeCommand.php` (`app:errors:smoke <marker>`: ustawia `TraceContext` na losowy trace_id i rzuca `errors-smoke:search:{marker}`)
- Create: `tools/errors-smoke.sh`, `tools/errors-smoke-browser.mjs` (Playwright)
- Modify: `Makefile` (`errors-smoke`)
- Test: `apps/catalog/tests/Feature/ErrorTracking/DebugRoutesTest.php`

**Interfaces:**
- Consumes: wszystko powyżej, `GLITCHTIP_API_TOKEN`, API GlitchTipa `GET /api/0/projects/marketplace/<slug>/issues/?query=<marker>` i `GET /api/0/issues/<id>/events/latest/` (tagi).

- [ ] **Step 1: Test Pest** `DebugRoutesTest`: `it does not register debug routes outside local` (`app()->detectEnvironment(fn () => 'production')` + przeładowanie tras → `get('/_debug/errors/http')` = 404); w `local` → 500.
- [ ] **Step 2: Run** → FAIL. **Step 3:** implementacja tras i komend. **Run** → PASS.
- [ ] **Step 4: `tools/errors-smoke.sh`** (bash, `set -euo pipefail`, marker `smoke-$(date +%s)`). Każdy krok drukuje `OK`/`FAIL`, a skrypt kończy się kodem ≠ 0 przy pierwszym FAIL. Kroki:
  1. `curl` trasy HTTP; zapamiętaj `X-Trace-Id`.
  2. `docker compose exec catalog-app php artisan errors:smoke $M --queue` (job obsłuży `catalog-worker`).
  3. `docker compose exec search-consumer php bin/console app:errors:smoke $M`.
  4. `node tools/errors-smoke-browser.mjs "https://catalog.localhost:$CATALOG_HTTPS_PORT/_debug/errors/frontend?m=$M"` (Playwright `ignoreHTTPSErrors`, czeka na odpowiedź 200 z `/_errors`).
  5. Polling API (do 60 s) — dla każdego markera issue we właściwym projekcie (`catalog` ×2, `search`, `catalog-frontend`) z tagiem `trace_id`. Dla HTTP tag równy `X-Trace-Id`. Jeśli profil `obs` działa, `trace.id` z kroku 1 jest w `logs-app-*` (curl do ES na `$ES_PORT`).
  6. Mail „nowy issue” w mailpicie (`/api/v1/search?query=errors-smoke`).
  7. Tunel: GET → 405, 2 MB → 413, koperta z DSN `search` → issue w `catalog-frontend`, nie w `search`.
  8. Odporność: `docker compose stop glitchtip`, trasa HTTP odpowiada 500 w < 3 s (`curl -w %{time_total}`), `docker compose start glitchtip`.
- [ ] **Step 5: Run** `make up-apps && make up-errors && make errors-smoke`. Expected: wszystkie kroki `OK`. Zrzut ekranu issue z frontu z mapowanymi ramkami (po `make errors-sourcemaps`) do `docs/blog/img/`.
- [ ] **Step 6: Commit** `ETAP 11b [7/8]: make errors-smoke — wszystkie źródła, tunel i odporność end-to-end`

### Task 8: Dokumentacja i pomiary

**Files:**
- Create: `docs/adr/0009-wyjatki-w-glitchtip.md`, `docs/blog/etap-11b-glitchtip.md`
- Modify:
  - `docs/RUNBOOK.md`:
    - #068 GlitchTip nie startuje / brak bazy `glitchtip`,
    - #069 bootstrap padł (po podbiciu wersji albo ID projektów ≠ 1/2/3),
    - #070 `GlitchTipDown`,
    - #071 brak zdarzeń z przeglądarki (tunel, pusty klucz, 204),
    - #072 stack trace frontu bez source map (release ≠ `SENTRY_RELEASE`),
    - #073 błąd nie trafił do GlitchTipa (filtr `dontReport`, retry, pusty DSN),
  - `docs/01-INFRASTRUKTURA.md`, `docs/06-PLAN-WDROZENIA.md` (ETAP 11b), `docs/07-WERSJE.md`, `docs/POMIARY.md`, `README.md`,
  - `deploy/k8s/config.env`: `SENTRY_LARAVEL_DSN=`, `SENTRY_FRONTEND_DSN=`, `SENTRY_DSN=` z komentarzem „puste do etapu GlitchTip na k3s”.

- [ ] **Step 1: ADR-0009** wg spec sekcji 1 i 7 (status, kontekst, decyzja, opcje, konsekwencje), w formacie ADR-0005.
- [ ] **Step 2: Pomiar:** `docker stats --no-stream marketplace-glitchtip-1` w spoczynku i zaraz po `make errors-smoke`, wpis do `POMIARY.md`.
- [ ] **Step 3: Przewodnik** `etap-11b-glitchtip.md`, w stylu `etap-11-observability.md`:
  - po co trzeci filar,
  - `make up-errors` i logowanie,
  - pierwszy błąd z każdego źródła,
  - issue → `trace_id` → Kibana,
  - source mapy i release,
  - czego GlitchTip **nie** dostaje i dlaczego,
  - pułapki z wdrożenia.
- [ ] **Step 4: Weryfikacja:** linki względne w nowych dokumentach istnieją (`grep -o '](\.\./[^)]*)'` + `test -e`), kotwice RUNBOOK #068–#073 są osiągalne z alertu i z ADR.
- [ ] **Step 5: Commit** `ETAP 11b [8/8]: ADR-0009, przewodnik GlitchTip, RUNBOOK #068–#073, pomiary`
