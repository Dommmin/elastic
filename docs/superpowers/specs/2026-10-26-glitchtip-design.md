# ETAP 11b — Śledzenie błędów w GlitchTip (self-hosted)

- **Data:** 2026-10-26
- **Gałąź:** `glitchtip`
- **Zakres środowisk:** lokalnie (profil Compose `errors`). Na VPS/k3s tylko
  przygotowanie: SDK wyłączone przy pustym DSN. Wdrożenie samego GlitchTipa na
  k3s to osobny etap po pomiarze RAM.
- **Nowy ADR:** `docs/adr/0009-wyjatki-w-glitchtip.md`

## 1. Cel i granica

ETAP 11 (ADR-0005) podzielił sygnały: **logi** w ES (z `trace_id`), **metryki
i alerty** w Prometheusie. Brakuje trzeciego filaru: **wyjątków zgrupowanych
w issues**. Chodzi o jeden wpis na klasę błędu z licznikiem wystąpień, pełnym
stack trace'em (dla Vue po source mapach), wersją (release), środowiskiem,
pierwszym i ostatnim wystąpieniem, statusem resolved/ignored oraz mailem przy
nowym issue.

| Pytanie | Gdzie |
|---|---|
| Co się działo w tym przypadku użycia? (pełna historia) | ES / Kibana, po `trace.id` |
| Ile / jak szybko / czy przekroczyliśmy próg? | Prometheus / Grafana, alerty Alertmanager |
| **Jaki błąd w kodzie, od kiedy, jak często, w której wersji, kogo dotyka?** | **GlitchTip** |

**Zasady granicy (do ADR-0009):**
1. Do GlitchTipa idą tylko **wyjątki, które są błędem w kodzie albo awarią**.
   Nie trafiają tam błędy użytkownika: 404, walidacja, brak autoryzacji, CSRF,
   złamana reguła domeny w HTTP. Filtr jest jeden: istniejący handler
   wyjątków Laravela (`dontReport*`). Nie budujemy drugiej listy wyjątków.
2. **Bez logów i bez performance w GlitchTipie.** `GLITCHTIP_ENABLE_LOGS=false`,
   `traces_sample_rate=0`, bez Session Replay. Logi są w ES, latencja
   w Caddy/Prometheusie. Nie dublujemy sygnałów.
3. **Bez handlera Monologa → Sentry.** Ten sam błąd nie może trafić dwa razy
   (raz jako wyjątek, raz jako linia `error` w logu). Logi trafiają do zdarzenia
   tylko jako breadcrumbs.
4. **Każde zdarzenie ma tag `trace_id`.** Most do Kibany: issue → `trace.id`
   → pełny przebieg w logach obu aplikacji.
5. **Bez PII.** `send_default_pii=false` wszędzie. Ze zdarzeń wysyłamy tylko
   `user.id`, bez e-maila, IP i treści formularzy.
6. **Pusty DSN = SDK wyłączone.** Kod i obrazy są te same w każdym środowisku.
   Brak GlitchTipa niczego nie psuje.
7. **Błąd wysyłki do GlitchTipa nigdy nie przerywa żądania ani wiadomości.**
   SDK wysyła z timeoutem i połyka błędy transportu (ta sama reguła co zapis
   metryki w ADR-0005).

## 2. Infrastruktura (profil `errors`)

### 2.1 Kontener `glitchtip` — tryb all-in-one

- Obraz `glitchtip/glitchtip:${GLITCHTIP_VERSION}`, linia 6.2.x. Dokładny tag
  sprawdzamy w rejestrze w kroku 0 planu i wpisujemy do `.env.example`
  i `07-WERSJE.md`.
- `GLITCHTIP_EMBED_WORKER=true`: worker zadań działa w procesie WWW, więc jest
  jeden kontener zamiast trzech (web, worker, Valkey).
- `VALKEY_URL=""`: kolejka zadań, cache i sesje lądują w Postgresie. Redis
  aplikacji trzyma metryki `search` (ADR-0005), więc nie mieszamy z nim cudzej
  kolejki. Przy tym ruchu Postgres w zupełności wystarczy.
- `DATABASE_URL` → osobna baza i użytkownik `glitchtip` w istniejącym
  kontenerze `postgres`. To ten sam kompromis „jeden kontener, osobne bazy”
  co dla `catalog` i `searchsvc`.
- `GLITCHTIP_DOMAIN=http://localhost:${GLITCHTIP_PORT}`, port
  `127.0.0.1:${GLITCHTIP_PORT:-18000}:8000`. Przesunięty, bo maszyna jest
  współdzielona.
- `EMAIL_URL=smtp://mailpit:1025`, `DEFAULT_FROM_EMAIL=glitchtip@marketplace.localhost`.
  Maile z alertów idą do mailpita, jak z Alertmanagera.
- `ENABLE_USER_REGISTRATION=false`, `ENABLE_ORGANIZATION_CREATION=false`.
- `GLITCHTIP_ENABLE_LOGS=false`, `GLITCHTIP_ENABLE_UPTIME=false`. Uptime
  dublowałby alerty Prometheusa (zasada 2).
- `ENABLE_OBSERVABILITY_API=true`. GlitchTip wystawia metryki Prometheusa
  i dopisujemy go do `scrape_configs` w profilu `obs`, czyli monitorujemy
  monitoring (ADR-0005).
- Retencja lokalna: `GLITCHTIP_RETENTION_DAYS=14`.
- Wolumen `glitchtip-uploads` na source mapy. `mem_limit: 512m`,
  zgodnie z rekomendacją producenta dla all-in-one.
- Healthcheck HTTP na stronie logowania lub endpoincie zdrowia (który z nich,
  ustalamy w kroku 0). `restart: unless-stopped`, sieć `marketplace`.

### 2.2 Kontener jednorazowy `glitchtip-setup` — wszystko jako kod

Skrypty `infra/postgres/init/*` działają tylko na świeżym wolumenie, a wolumen
`pg-data` już istnieje. Dlatego jest osobny, **idempotentny** krok w dwóch
fazach:

1. **Baza:** `psql` z obrazu Postgresa robi `CREATE ROLE/DATABASE … IF NOT EXISTS`
   (przez `DO $$ … $$`), z tymi samymi grantami do schematu `public` co
   `01-databases.sh`. Do `01-databases.sh` dopisujemy też `glitchtip`, żeby
   świeży wolumen miał ją od razu.
2. **Organizacja i projekty:** `./manage.py shell < infra/glitchtip/bootstrap.py`
   w obrazie GlitchTipa, idempotentnie:
   - superuser z `.env` (`GLITCHTIP_ADMIN_EMAIL` / `GLITCHTIP_ADMIN_PASSWORD`),
   - organizacja `marketplace`, zespół `platform`,
   - trzy projekty:

     | Projekt | Platforma | Źródła |
     |---|---|---|
     | `catalog` | php-laravel | catalog-app (HTTP/Octane), catalog-worker, outbox-publisher, catalog-scheduler |
     | `catalog-frontend` | javascript-vue | przeglądarka (Inertia/Vue) |
     | `search` | php-symfony | search-consumer, search-metrics |

     Frontend ma osobny projekt: inna platforma, source mapy, inny poziom szumu,
     a jego DSN jest publiczny.
   - **Klucze DSN deterministyczne z `.env`** (`GLITCHTIP_KEY_CATALOG`,
     `…_FRONTEND`, `…_SEARCH`, wartości UUID tylko dla dev). Skrypt ustawia
     `public_key` klucza projektu na tę wartość. Dzięki temu DSN-y w
     `.env.example` są stałe, a `make nuke && make up-errors` odtwarza
     identyczny stan bez ręcznego kopiowania kluczy z UI.
   - reguła alertu „nowy issue → e-mail do zespołu” w każdym projekcie,
   - token API użytkownika (scope `project:releases`) dla `sentry-cli`,
     również deterministyczny z `.env` (`GLITCHTIP_API_TOKEN`).

   **Ryzyko:** skrypt używa wewnętrznych modeli Django GlitchTipa. Nazwy
   modeli i pól sprawdzamy w kroku 0 na przypiętym tagu. Skrypt kończy się
   asercjami (projekty, klucze i reguły istnieją), więc po podbiciu wersji
   psuje się głośno, a nie po cichu. Wpis w RUNBOOK opisuje, co zrobić.

### 2.3 Ścieżka przeglądarki: tunel przez Caddy w `catalog`

Przeglądarka **nie** łączy się z GlitchTipem bezpośrednio. SDK ma opcję
`tunnel: '/_errors'`. Caddy w `catalog-app` przyjmuje `POST /_errors`
i przekazuje kopertę do `glitchtip:8000` pod **stały** adres projektu
frontendu (`/api/<id>/envelope/?sentry_key=<klucz frontendu>`).

Dlaczego tak:
- GlitchTip nie musi być publiczny. Na VPS ma działać wyłącznie przez tunel
  SSH (decyzja z ETAPU D), a błędy z przeglądarek nadal dochodzą.
- To samo origin, więc nie ma CORS, mixed content (`https://catalog.localhost`
  → `http://localhost:18000`) ani blokad adblocków na domeny `sentry`.
- **Brak open relay:** Caddy nie czyta DSN z koperty. Cel jest wpisany na
  sztywno (zmienne środowiskowe), więc tunelem da się pisać tylko do projektu
  `catalog-frontend`.
- `request_body max_size 1MB` ucina nadużycia rozmiarem. Limity liczby zdarzeń
  zostają po stronie GlitchTipa (throttling organizacji).
- Gdy GlitchTip nie działa, Caddy zwraca 502, a SDK to ignoruje (zasada 7).

Czy GlitchTip przyjmuje klucz z `?sentry_key=` na endpoincie envelope,
sprawdzamy w kroku 0. Planem B jest nagłówek `X-Sentry-Auth` ustawiany przez
Caddy.

### 2.4 Makefile, doctor, Prometheus

- `make up-errors`: najpierw `glitchtip-setup` faza 1, potem `up -d --no-deps
  mailpit glitchtip`, czekanie na health, faza 2 i wypisanie URL-a UI. Ten sam
  wzór `--no-deps` co `up-obs` (nie ruszamy współdzielonych kontenerów).
- `make errors-smoke`: test end-to-end (sekcja 6).
- `make errors-sourcemaps`: build frontu z `release` i upload source map
  (sekcja 5.3).
- `tools/doctor.sh`: profil `errors` sprawdza wolny `GLITCHTIP_PORT` i dolicza
  512 MB do budżetu pamięci.
- `infra/obs/prometheus/prometheus.yml`: job `glitchtip`. Alert
  `GlitchTipDown` dochodzi z testem `promtool`, jak pozostałe reguły.

## 3. Backend — `catalog` (Laravel 13 + Octane)

- Pakiet `sentry/sentry-laravel`, plik `config/sentry.php`.
- Spięcie z handlerem: `Integration::handles($exceptions)` w `bootstrap/app.php`.
  GlitchTip widzi dokładnie to, co Laravel **raportuje**, więc obecne
  `dontReportWhen` dla `DomainException` w HTTP i wbudowane `dontReport`
  (404, walidacja, auth, CSRF) działają bez zmian.
- Zmienne (kotwica YAML `x-error-tracking-catalog` dla wszystkich czterech
  kontenerów catalog): `SENTRY_LARAVEL_DSN=http://<klucz>@glitchtip:8000/<id>`,
  `SENTRY_ENVIRONMENT` (`local` / `production`), `SENTRY_RELEASE=${APP_VERSION:-dev}`,
  `SENTRY_TRACES_SAMPLE_RATE=0`, `SENTRY_SEND_DEFAULT_PII=false`.
- **Octane:** pakiet sam resetuje scope między żądaniami. Test sprawdza, że
  tag `trace_id` jednego żądania nie przecieka do następnego.
- **`before_send` → `App\Support\ErrorTracking\EnrichEvent`** (statyczna metoda,
  bo musi przeżyć `config:cache`):
  - tag `trace_id` z `TraceId::current()`. Context Laravela przechodzi do
    jobów kolejki, więc tag dostają HTTP, joby i komendy,
  - `user.id` z `auth()->id()`, gdy jest zalogowany użytkownik. Bez e-maila
    i bez IP,
  - tag `process` (`http`, `queue`, `outbox-publisher`, `scheduler`) ze
    zmiennej `ERROR_TRACKING_PROCESS` ustawianej per kontener w compose.
- **Pętle długożyjące:** w `PublishOutboxCommand` i jobach przeglądamy każde
  `catch`, które loguje błąd i jedzie dalej. Tam, gdzie błąd jest awarią
  (a nie oczekiwanym retry), dokładamy `report($e)`, żeby trafił do GlitchTipa
  zgodnie z zasadą 1. Lista miejsc i decyzja dla każdego trafia do planu.
- **Kolejka:** pakiet łapie wyjątki jobów. Joby z retry raportują się dopiero
  przy ostatecznej porażce (`failed`), żeby każda próba nie robiła szumu.
  Szczegół zależy od `tries` w jobach, plan go doprecyzowuje.
- Monitorów cron Sentry (`sentryMonitor()`) nie używamy. Zdrowie schedulera
  i outboxu pilnuje już Prometheus.

## 4. Backend — `search` (Symfony 8.1, Messenger)

- Pakiet `sentry/sentry-symfony`, `config/packages/sentry.yaml`. DSN z
  `SENTRY_DSN`, pusty oznacza wyłączone.
- `register_error_listener: true` dla wyjątków HTTP i konsoli w `search-metrics`
  i komendach.
- **Messenger:** `messenger.enabled: true`, `capture_soft_fails: false`.
  Raportujemy tylko wiadomość, która wyczerpała `max_retries: 3` i trafia do
  `failed`. Retry z opóźnieniem to normalna praca. Scope jest czyszczony między
  wiadomościami, co sprawdza test.
- **`before_send` → serwis `App\ErrorTracking\EnrichEvent`**: tag `trace_id`
  z `TraceContext` (ustawiany przez `TraceContextSubscriber` z `TraceIdStamp`)
  i tag `process` (`consumer` / `metrics`).
- **Flush:** konsument żyje godzinę (`--time-limit=3600`), więc zdarzenie musi
  wyjść od razu po porażce wiadomości, a nie przy końcu procesu. Bundle robi
  flush na zdarzeniach workera. Weryfikujemy to w teście integracyjnym
  i w smoke.
- Bez handlera Monologa (zasada 3), breadcrumbs z logów zostają.
- `search` używa Twig/asset-mapper tylko szczątkowo, więc frontendu Symfony nie
  instrumentujemy.

## 5. Frontend — `catalog` (Vue 3 + Inertia)

### 5.1 Konfiguracja z serwera, nie z buildu

DSN, `environment` i `release` przychodzą **w runtime** z
`resources/views/app.blade.php` jako tagi `<meta name="error-tracking-*">`,
renderowane z `config('sentry.frontend.*')` tylko przy niepustym DSN. Ten sam
obraz działa w każdym środowisku (12-factor), bez `VITE_*` wpiekanych w build.
Tagi meta zamiast inline `<script>` są przyjazne dla CSP.

### 5.2 Inicjalizacja

- `@sentry/vue`, inicjalizacja w `resources/js/lib/errorTracking.ts`.
  Wywołanie z callbacku `setup` w `createInertiaApp` (`Sentry.init({ app, … })`),
  więc Vue `errorHandler`, globalne `onerror` i `unhandledrejection` są
  podpięte. Bez meta DSN funkcja nic nie robi.
- `tunnel: '/_errors'`, `sendDefaultPii: false`, `tracesSampleRate: 0`,
  bez Replay.
- **Szum:** `allowUrls` = własny origin (odpada kod rozszerzeń przeglądarki),
  `ignoreErrors` dla znanych niegroźnych komunikatów (`ResizeObserver loop…`,
  anulowana wizyta Inertia). Lista jest krótka i opisana w komentarzu.
- **Most do backendu:** listener `router.on('finish' | 'invalid' | 'exception')`
  Inertii dodaje breadcrumb z nagłówkiem `X-Trace-Id` ostatnich odpowiedzi.
  Błąd w przeglądarce pokazuje więc `trace_id` żądań, które go poprzedziły,
  i można przejść do Kibany. Odpowiedź 500 z backendu jest już issue
  w projekcie `catalog`, więc frontend nie raportuje jej drugi raz.

### 5.3 Source mapy i release

- `vite.config.ts`: `build.sourcemap: 'hidden'`. Mapy powstają, ale bundle nie
  zawiera komentarza `sourceMappingURL`.
- **Mapy nigdy nie są serwowane publicznie.** Etap `prod` w
  `infra/php/catalog.Dockerfile` usuwa `public/build/**/*.map` z finalnego
  obrazu.
- `release` = `APP_VERSION` (SHA commita z `images.yml`), wspólny dla backendu
  i frontendu `catalog`. Lokalnie `dev`, a w `make errors-sourcemaps` SHA HEAD.
- `make errors-sourcemaps` w kontenerze `catalog-vite`: `npm run build` →
  `sentry-cli sourcemaps inject` (Debug IDs) → `sentry-cli sourcemaps upload
  --release <sha>` do lokalnego GlitchTipa z tokenem z bootstrapu. Wsparcie
  Debug IDs w GlitchTipie 6.x sprawdzamy w kroku 0. Planem B jest upload
  powiązany z release i `url-prefix`. Upload w CI (`images.yml`) wchodzi
  dopiero razem z GlitchTipem na k3s, bo dziś CI nie ma go gdzie wysłać.

## 6. Testy i weryfikacja

**Testy jednostkowe i integracyjne (bez żywego GlitchTipa, transport
podstawiony):**
- `catalog` (Pest):
  - wyjątek w żądaniu HTTP → zdarzenie z tagiem `trace_id` równym `X-Trace-Id`
    i `user.id`, bez e-maila,
  - `DomainException` w HTTP, 404 i walidacja → brak zdarzenia,
  - wyjątek w jobie → zdarzenie z `trace_id` z Context,
  - dwa żądania pod rząd → tag pierwszego nie przecieka do drugiego,
  - pusty DSN → żadnego wywołania transportu,
  - `app.blade.php` bez DSN nie renderuje meta, a z DSN renderuje DSN,
    `environment` i `release`.
- `search` (PHPUnit):
  - wiadomość, która padła 1× i potem przeszła → brak zdarzenia,
  - wiadomość po wyczerpaniu retry → dokładnie jedno zdarzenie z `trace_id`
    ze stampa,
  - kolejna wiadomość bez stampa → brak starego `trace_id`.
- Frontend: `npm run lint`, `vue-tsc` i build przechodzą. Logikę `beforeSend`
  i filtrów trzymamy w czystych funkcjach. Testu jednostkowego JS nie dodajemy,
  bo projekt nie ma runnera, a dokładanie Vitesta tylko dla tego to YAGNI.
  Ścieżkę przeglądarki pokrywa smoke.

**Smoke end-to-end `make errors-smoke`** (`tools/errors-smoke.sh`, na żywym
stosie):
1. Backend HTTP: lokalna trasa `GET /_debug/errors/http` (rejestrowana
   **tylko** przy `APP_ENV=local`) rzuca wyjątek z unikalnym znacznikiem.
2. Kolejka: `php artisan errors:smoke --queue` wrzuca job, który rzuca wyjątek.
3. `search`: `bin/console app:errors:smoke` rzuca wyjątek z ustawionym
   `TraceContext`.
4. Przeglądarka: Playwright (preinstalowany Chromium) otwiera lokalną stronę
   `/_debug/errors/frontend` (tylko `local`), która rzuca w `onMounted`.
   Zdarzenie przechodzi przez tunel Caddy.
5. Skrypt odpytuje API GlitchTipa tokenem z bootstrapu i sprawdza, że każdy
   znacznik jest issue we właściwym projekcie z tagiem `trace_id`. Dla kroku 1
   sprawdza też, że ten `trace_id` jest w ES (`logs-app-*`, `trace.id`), jeśli
   profil `obs` działa. Na końcu: mail „nowy issue” w mailpicie.
6. Tunel nie jest open relay: kopertę z DSN innego projektu GlitchTip zapisuje
   w `catalog-frontend` (cel na sztywno), a nie w projekcie z DSN.

## 7. Dokumentacja (definicja „etap skończony”)

- `docs/adr/0009-wyjatki-w-glitchtip.md`: granica z sekcji 1 i rozważane
  opcje (self-hosted Sentry: kilkanaście GB RAM; Sentry SaaS: dane poza
  infrastrukturą; web+worker+Valkey: +3 kontenery bez zysku przy tej skali;
  wyjątki tylko w ES: brak grupowania i stanu resolved/regression).
- `docs/blog/etap-11b-glitchtip.md`: przewodnik krok po kroku (start,
  logowanie, pierwszy błąd z każdego źródła, przejście issue → Kibana po
  `trace_id`, source mapy, co **nie** trafia do GlitchTipa i dlaczego).
- Aktualizacje:
  - `docs/RUNBOOK.md`: nowe wpisy od #068 (GlitchTip nie startuje lub brak
    bazy, bootstrap padł po podbiciu wersji, brak zdarzeń z frontu (tunel),
    stack trace bez source map, `GlitchTipDown`),
  - `docs/01-INFRASTRUKTURA.md`: profil `errors`,
  - `docs/06-PLAN-WDROZENIA.md`: ETAP 11b,
  - `docs/07-WERSJE.md`: GlitchTip i wersje SDK,
  - `docs/POMIARY.md`: RAM kontenera w spoczynku i pod smoke,
  - `README.md`: tabela stanu,
  - nagłówek `compose.yaml`: lista profili,
  - `.env.example`,
  - `deploy/k8s/config.env`: puste DSN z komentarzem.

## 8. Poza zakresem

- GlitchTip na VPS/k3s i upload source map w CI (osobny etap po pomiarze RAM).
- Performance/tracing, Session Replay, logi OTLP w GlitchTipie (zasada 2).
- Monitory cron i uptime GlitchTipa (to robi Prometheus).
- Integracja powiadomień poza e-mailem (Slack itd.).

## 9. Krok 0 planu — fakty do potwierdzenia na przypiętym tagu

1. Dokładny tag obrazu 6.2.x i jego healthcheck lub endpoint zdrowia.
2. Modele Django dla organizacji, projektu, klucza, reguły alertu i tokenu
   API (bootstrap).
3. Autoryzacja `?sentry_key=` na `/api/<id>/envelope/` (tunel). Plan B:
   nagłówek.
4. Debug IDs / artifact bundles w `sentry-cli sourcemaps upload` na GlitchTipie
   6.x. Plan B: release + `url-prefix`.
5. Zachowanie `sentry-symfony` (flush i scope) w workerze Messengera
   w aktualnej wersji.

## 10. Zmiany względem projektu (po implementacji, 2026-10-26)

Spec powyżej to projekt z brainstormingu. Implementacja zmieniła to, co pomiar
i kod pokazały; ADR-0009 i przewodnik opisują stan faktyczny.

| Sekcja specu | Było | Jest | Dlaczego |
|---|---|---|---|
| Zasada 7, 3 | `http_connect_timeout` 1 s, `http_timeout` 2 s | **0,3 s i 1 s** | pomiar na żywym stacku: przy zawieszonym GlitchTipie narzut żądania z błędem to `http_timeout`; 2 s psułoby budżet 3 s |
| 3 | `config('sentry.frontend.*')`, tag `process` w `config/sentry.php` | `config/error_tracking.php` | SDK odrzuca nieznane klucze najwyższego poziomu |
| 3 | `QueueRetryFilter::willRetry()` bez argumentu, stan procesu | `willRetry(Throwable)`, `WeakMap` po obiekcie wyjątku | flaga procesu wyciekałaby na wyjątki spoza zadania |
| 3, 6 | podklasa `TestCase` dla testów Sentry | opt-in w `Tests\TestCase::createApplication()` | Pest nie pozwala na dwie klasy bazowe |
| 4 | recipe Flex | ręczna rejestracja `SentryBundle` i `sentry.yaml` | Flex pomija recipe contrib |
| 4, 6 | test flushu przed zatrzymaniem workera | pominięty w testach, sprawdza go smoke | fałszywy transport wysyła synchronicznie |
| 5.2 | `setup` w `createInertiaApp`, zdarzenia `invalid`/`exception`, `shouldDrop` | `withApp`, zdarzenia `httpException`/`networkError`, bez `shouldDrop` | API Inertia v3; YAGNI |
| 5.3 | upload `sentry-cli` w kontenerze | build i inject w kontenerze, **upload z hosta** | `chunk-upload` zwraca adres z `GLITCHTIP_DOMAIN`, niewidoczny z kontenera |
| 5.3 | `find -delete` map w etapie `prod` | wycięcie map do `/sourcemaps` w etapie `assets` | skasowanie w `prod` zostawia plik w warstwie obrazu |
| 6 | próg odporności 3 s dla zatrzymanego GlitchTipa | narzut < 1,5 s względem ciepłej bazy dla zawieszonego | pierwszy błąd po bezczynności trwa 2,5–3 s niezależnie od GlitchTipa |
| 6 | Playwright preinstalowany | `playwright-core` w katalogu tymczasowym na systemowym Chrome | to był opis innego środowiska |
| 2.2 | token API i klucze „deterministyczne” | tak, ale jako literały w `.env`/`.env.example` | interpolacja w `.env` czytana przez Make i Compose różnie |
| 9 pkt 1–5 | fakty do potwierdzenia | potwierdzone na 6.2.6: tag obrazu, `/_health/`, `?sentry_key=`, Debug IDs, modele | plan A wystarczył, żaden plan B nie był potrzebny |
| 1 zasada 5, 4 | `send_default_pii=false` wystarcza | + `data_collection` (bez zmiennych ramek, query stringa, nagłówków poza allow-listą), `EventScrubber`, `scrubUrl.ts`, `zend.exception_ignore_args=On` w obrazach prod | końcowy przegląd: ślad wyjątku niósł jawne hasło z formularza, a tokeny resetu hasła i e-maile szły w URL, `Referer` i komunikatach SQL (każdy przypadek odtworzony testem) |
| 2.3 | „limity liczby zdarzeń zostają po stronie GlitchTipa (throttling organizacji)” | założenie niezweryfikowane; tunel odrzuca `Content-Encoding` (415) i zdejmuje `X-Sentry-Auth`; limit liczby żądań do zrobienia przed VPS | throttling GlitchTipa może wynikać tylko z billingu; patrz ADR-0009, „Przed wystawieniem na VPS” |
| 2.3 (po przeglądzie) | tunel w Caddy z celem w zmiennych środowiskowych | `ErrorTunnelController` w catalog z limitami 20/min na IP i 300/min globalnie; w Caddy tylko sufit ciała 2 MB | Caddy nie ma limitera w rdzeniu, a własny obraz z modułem rate-limit to nowa zależność; limit w aplikacji |
| 2.2 (po przeglądzie) | klucze „stałe z `.env`” | + `guard.py`: poza localhostem odmowa dla wartości deweloperskich; `gen-env.sh` generuje klucze UUID hex, token 64 znaki i DSN-y z `__REF:…__` | wartości z `.env.example` są publiczne |
| 2.1 (VPS) | skrypty GlitchTipa montowane z repo | obraz `elastic-glitchtip` z CI (`infra/glitchtip/Dockerfile`) ze skryptami w środku; dev używa tego samego; `setup_db.py` (psycopg) zamiast `setup-db.sh` | na serwerze nie ma repo (reguła „zero bind-mountów”) |
| 2.2 (VPS) | strażnik tylko poza localhostem | + `GLITCHTIP_STRICT_SECRETS=true` w nakładce prod; odmowa także dla pustych sekretów | przez tunel SSH GlitchTip ma domenę `localhost`; `:?` w nakładce blokowałoby deploy na starym `.env` |
| 2.4 (VPS) | — | `SENTRY_RELEASE=IMAGE_TAG` w nakładce; `add-glitchtip-env.sh` dopisuje sekcję do istniejącego `.env`; mailpit jako odbiorca maili, tylko przez tunel | `compose.yaml` ustawiał `dev`; `gen-env.sh` nie nadpisuje `.env` |
