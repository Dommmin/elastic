# Śledzenie błędów w GlitchTipie (self-hosted), krok po kroku

*ETAP 11b projektu Marketplace Search Platform. ETAP 11 dał nam logi w ES
i metryki z alertami w Prometheusie. Brakowało trzeciego pytania, tego, które
zadaje się po awarii: **jaki błąd w kodzie, od kiedy, jak często i w której
wersji?** Ten etap dokłada GlitchTip: wyjątki z Laravela (żądania HTTP,
kolejka), z Symfony (konsument Messengera) i z przeglądarki (Vue) lądują
jako zgrupowane issues, z tagiem `trace_id`, który prowadzi do tych samych
zdarzeń w Kibanie. Przy okazji: protokół Sentry, tunel błędów z limitami,
source mapy z Debug IDs, bootstrap usługi jako kod i pomiar, ile kosztuje
awaria narzędzia do awarii.*

> **Stan:** gałąź `glitchtip` (commity z prefiksem `ETAP 11b`),
> **niewypchnięta i niezmergowana**. Zweryfikowane na żywym stacku 2026-10-26:
> `make errors-smoke` zielony (HTTP, kolejka, search i przeglądarka → po jednym
> issue we właściwym projekcie, tagi `trace_id` i `process`, ramki frontu
> zmapowane do `ErrorsSmoke.vue`, mail o nowym issue, tunel, odporność na
> awarię GlitchTipa, brak e-maila i tokenu z adresu w zdarzeniach). Testy: catalog 832 (817 zielonych, 15 pominiętych),
> search 34 (7 pominiętych na hoście bez `ext-amqp`), JS `node --test` 7, `make obs-check`.
> **Nie sprawdzone:** obecność `trace.id` w logach ES w ramach smoke (profil
> `obs` nie działał; zgodność `trace_id` z `X-Trace-Id` jest sprawdzana, a że
> ten sam id jest w logach, ustalił ETAP 11). **Nie zrobione:** wdrożenie
> GlitchTipa na VPS/k3s (SDK jest tam wyłączone pustym DSN).
>
> Decyzja: [ADR-0009](../adr/0009-wyjatki-w-glitchtip.md). Spec:
> [`superpowers/specs/2026-10-26-glitchtip-design.md`](../superpowers/specs/2026-10-26-glitchtip-design.md),
> plan: [`superpowers/plans/2026-10-26-glitchtip.md`](../superpowers/plans/2026-10-26-glitchtip.md).
> Błędy: RUNBOOK [#068–#073](../RUNBOOK.md#068).

---

## Spis treści

1. [Po co trzeci sygnał](#1-po-co-trzeci-sygnał)
2. [Mapa: co powstało i gdzie](#2-mapa-co-powstało-i-gdzie)
3. [Krok 1 — start GlitchTipa](#krok-1--start-glitchtipa)
4. [Krok 2 — pierwszy błąd z catalog](#krok-2--pierwszy-błąd-z-catalog)
5. [Krok 3 — kolejka i search](#krok-3--kolejka-i-search)
6. [Krok 4 — przeglądarka i source mapy](#krok-4--przeglądarka-i-source-mapy)
7. [Krok 5 — od issue do logów po `trace_id`](#krok-5--od-issue-do-logów-po-trace_id)
8. [Krok 6 — co NIE trafia do GlitchTipa i dlaczego](#krok-6--co-nie-trafia-do-glitchtipa-i-dlaczego)
9. [Krok 7 — awaria GlitchTipa](#krok-7--awaria-glitchtipa)
10. [Krok 8 — `make errors-smoke`](#krok-8--make-errors-smoke)
11. [Pułapki, które wybuchły po drodze](#pułapki-które-wybuchły-po-drodze)
12. [Czego jeszcze nie ma](#czego-jeszcze-nie-ma)

---

## 1. Po co trzeci sygnał

Trzy narzędzia odpowiadają na trzy różne pytania:

| Pytanie | Gdzie |
|---|---|
| Co się działo w tym przypadku użycia? | Kibana, po `trace.id` |
| Ile, jak szybko, czy przekroczono próg? | Prometheus, Grafana, alerty |
| **Jaki błąd w kodzie, od kiedy, jak często, w której wersji?** | **GlitchTip** |

Log mówi „stało się”. Nie zgrupuje tysiąca powtórzeń w jeden wpis, nie ma
stanu „naprawione / wróciło” i nie napisze maila tylko wtedy, gdy pojawił się
*nowy rodzaj* błędu. Do tego służą issues.

Dlaczego GlitchTip, a nie Sentry: GlitchTip mówi tym samym protokołem, więc
używamy oficjalnych SDK Sentry, a po stronie serwera to jeden kontener
(200 MiB w spoczynku) zamiast kilkunastu GB. Alternatywy i ich koszty:
[ADR-0009](../adr/0009-wyjatki-w-glitchtip.md).

## 2. Mapa: co powstało i gdzie

| Warstwa | Co | Gdzie |
|---|---|---|
| Usługa | GlitchTip all-in-one, baza `glitchtip` w istniejącym Postgresie | `compose.yaml`, profil `errors` |
| Bootstrap | organizacja, 3 projekty, stałe klucze DSN, alerty, token API | `infra/glitchtip/` |
| catalog | `sentry-laravel`, `EnrichEvent`, `QueueRetryFilter` | `apps/catalog/config/sentry.php`, `app/Support/ErrorTracking/` |
| search | `sentry-symfony`, `EnrichEvent` | `apps/search/config/packages/sentry.yaml`, `src/ErrorTracking/` |
| Przeglądarka | `@sentry/vue`, konfiguracja z meta, breadcrumbs z `X-Trace-Id` | `apps/catalog/resources/js/lib/errorTracking.ts` |
| Tunel | `POST /_errors` → projekt `catalog-frontend`, z limitami | `app/Http/Controllers/ErrorTunnelController.php`, sufit ciała w `infra/caddy/Caddyfile` |
| Alert | `GlitchTipDown` (z testami `promtool`) | `infra/obs/prometheus/rules/` |
| Test | `make errors-smoke`, `make errors-tunnel-check` | `tools/` |

Trzy projekty w GlitchTipie, o stałych ID: `catalog` (1), `catalog-frontend`
(2), `search` (3). Front ma własny projekt, bo ma inną platformę, inne source
mapy i inny poziom szumu.

## Krok 1 — start GlitchTipa

Postgres musi działać (`make up` albo `make up-apps`). Potem:

```bash
make up-errors
```

Robi cztery rzeczy, każda idempotentna: zakłada rolę i bazę `glitchtip`
(skrypt z `docker-entrypoint-initdb.d` działa tylko na świeżym wolumenie,
a `pg-data` już istnieje), startuje kontener, czeka na `healthy`, uruchamia
bootstrap. Bootstrap kończy się asercjami (ID projektów 1/2/3, aktywne klucze
zgodne z `.env`, reguły alertów, token) i drukuje:

```
OK: 3 projekty, klucze, alerty, token
```

UI: <http://localhost:18000>, login `admin@marketplace.localhost`, hasło
`glitchtip_dev_2026` (z `.env`).

**Dlaczego klucze DSN są stałe i leżą w `.env`.** Bootstrap ustawia
`public_key` klucza projektu na wartość z `.env`. Dzięki temu DSN-y w
`.env.example` nie zależą od tego, co wyklikano w UI, a `make errors-reset`
(kasuje bazę i odtwarza) daje identyczny stan. To klucze tylko do dev; na VPS
generujesz własne.

**Dlaczego bez Valkey.** `VALKEY_URL=""` każe GlitchTipowi trzymać kolejkę,
cache i sesje w Postgresie, a `GLITCHTIP_EMBED_WORKER=true` uruchamia worker w
procesie WWW. To jeden kontener zamiast trzech. Redis aplikacji trzyma metryki
search (ADR-0005), więc nie mieszamy z nim cudzej kolejki.

Sprawdź zużycie: `docker stats --no-stream marketplace-glitchtip-1`
(zmierzone 183–229 MiB z limitu 512).

## Krok 2 — pierwszy błąd z catalog

Potrzebne: `make up-apps` (albo przynajmniej `catalog-app`). Trasy debug
istnieją tylko w `APP_ENV=local`:

```bash
curl -sk -D - -o /dev/null https://catalog.localhost:${CATALOG_HTTPS_PORT:-8443}/_debug/errors/http?m=pierwszy
```

Odpowiedź to `500` z nagłówkiem `X-Trace-Id`. W GlitchTipie (projekt
`catalog`) pojawia się issue `errors-smoke:http:pierwszy`. Otwórz je i
zobacz tagi:

- `trace_id` równy nagłówkowi `X-Trace-Id` z odpowiedzi,
- `process = http`,
- użytkownik: tylko `id` (bez e-maila i IP).

Jak to działa: `Integration::handles($exceptions)` w `bootstrap/app.php`
podpina SDK pod to, co Laravel *raportuje*. Funkcja `before_send` w
`EnrichEvent` dokłada tagi i wyczyszczonego użytkownika. Konfiguracja SDK
(`config/sentry.php`) jest świadomie ubogą: `send_default_pii=false`,
`max_request_body_size='never'`, `traces_sample_rate=0`, bez logów i metryk.

Pusty DSN wyłącza SDK całkowicie: `SENTRY_LARAVEL_DSN=` w kontenerze i nic
się nie dzieje.

## Krok 3 — kolejka i search

Kolejka (worker `catalog-worker`):

```bash
docker compose exec catalog-app php artisan errors:smoke kolejka --queue
```

Zadanie `ErrorsSmokeJob` rzuca wyjątek (`tries=1`). Issue pojawia się z
`process = queue`, a `trace_id` pochodzi z `Context` Laravela, który
przechodzi przez kolejkę w ładunku zadania.

Search (konsument Messengera):

```bash
docker compose exec search-consumer php bin/console app:errors:smoke search
```

Komenda ustawia `TraceContext` tak jak robi to `TraceContextSubscriber` przy
odbiorze wiadomości i rzuca wyjątek. Issue ma `process = consumer` i
`trace_id`.

**Dlaczego zadanie z ponowieniem nie robi issue przy każdej próbie.** Laravel
zgłasza do handlera wyjątek *każdej* próby. Zadania sagi mają `tries` 5–10 z
`backoff`, więc przejściowa porażka (katalog chwilę leży) zalałaby GlitchTip.
`QueueRetryFilter` wie z zdarzeń kolejki, że po tej próbie zadanie wróci:
worker publikuje `JobExceptionOccurred`, a jeśli to ostatnia próba, to
`JobFailed`, i dopiero potem raportuje. Stan wiążemy z **obiektem wyjątku**
(`WeakMap`), nie z flagą procesu, bo flaga wyciekałaby na wyjątki spoza
zadania w tym samym procesie. W Symfony to samo załatwia
`capture_soft_fails: false`.

## Krok 4 — przeglądarka i source mapy

Przeglądarka **nie** łączy się z GlitchTipem. SDK wysyła na to samo origin:

```
POST https://catalog.localhost:8443/_errors
```

a `ErrorTunnelController` w catalog przekazuje kopertę do projektu `catalog-frontend`. Korzyści: GlitchTip
nie musi być publiczny (na VPS jest tylko za tunelem SSH), nie ma CORS ani
mixed content, a adblockery nie widzą domeny `sentry`.

**To nie jest otwarty przekaźnik.** Cel (ID projektu i jego klucz) jest
w configu (`config/error_tracking.php`) i nie zależy od DSN w kopercie ani od klucza
w query stringu czy nagłówku. Sprawdza to `make errors-tunnel-check` na żywym
`catalog-app`: koperta z kluczem projektu `search` ląduje w `catalog-frontend`, nie w
`search`. Tunel przyjmuje tylko POST (405 dla GET), ciało do 1 MB (413), odrzuca
`Content-Encoding` (415), nie przekazuje ciasteczek ani autoryzacji, przy pustym kluczu
odpowiada 204, a przy awarii GlitchTipa szybkim 502. Ma też **limity liczby żądań:
20/min na IP i 60/min globalnie** (429 z `Retry-After`; globalny zmniejszony z 300 po pomiarze rozmiaru bazy, RUNBOOK #077); logika jest w testach Pest
(`ErrorTunnelTest`). Dlaczego w Laravelu, a nie w Caddy: Caddy nie ma limitera w
rdzeniu, a własny obraz z modułem rate-limit to nowa zależność w łańcuchu budowania.
W Caddy zostaje tylko sufit rozmiaru ciała (2 MB), działający zanim PHP zacznie je
buforować.

Konfiguracja przeglądarki (DSN, środowisko, release) przychodzi **w runtime**
z tagów `<meta>` w `app.blade.php`, nie z buildu. Ten sam bundle działa w
każdym środowisku, a brak DSN oznacza brak meta, więc SDK się nie włącza.

Źródła w stack trace: `vite.config.ts` ma `sourcemap: 'hidden'`, czyli mapy
powstają, ale bundle nie ma komentarza `sourceMappingURL`. `make
errors-sourcemaps` buduje front w jednorazowym kontenerze, wstrzykuje Debug IDs
(`sentry-cli sourcemaps inject`) i wgrywa mapy z hosta do GlitchTipa. Potem
wejdź na:

```
https://catalog.localhost:8443/_debug/errors/frontend?m=front
```

Strona rzuca błąd w `onMounted`. W issue w projekcie `catalog-frontend`
ramka wskazuje `resources/js/pages/debug/ErrorsSmoke.vue`, a nie
`assets/ErrorsSmoke-<hash>.js`.

Mapy **nie trafiają do obrazu prod**: etap `assets` w `catalog.Dockerfile`
wycina je do `/sourcemaps` zanim bundle trafi do etapu `prod` (skasowanie w
`prod` zostawiłoby plik w warstwie obrazu). Zweryfikowane: obraz `prod` ma 0
plików `.map` i 98 plików `.js`.

> Uwaga na `public/hot`. Gdy działa serwer Vite (`catalog-vite`), Blade ładuje
> assety z niego, bez Debug IDs i bez wgranych map. Do pracy z source mapami
> uruchamiaj `make errors-sourcemaps` bez serwera Vite.

## Krok 5 — od issue do logów po `trace_id`

W issue skopiuj wartość tagu `trace_id`. W Kibanie (Discover, data view
`logs-app-*`, wymaga profilu `obs`):

```
trace.id: "b8cfb43b8eeaf33155b00f9366fc84c0"
```

Dostajesz wszystkie logi obu aplikacji dla tego przypadku użycia: żądanie,
wpis do outboxu, publikację do RabbitMQ i przetworzenie w search. GlitchTip
mówi *co się wywaliło*, Kibana *co się działo wokół*.

Błąd z przeglądarki nie ma własnego `trace_id` (przeglądarka go nie
generuje). Zamiast tego błędy HTTP z routera Inertia (`httpException`) i błędy
sieci (`networkError`) trafiają do **breadcrumbs** razem z `X-Trace-Id`
odpowiedzi. Późniejszy błąd w przeglądarce pokazuje więc id śladu żądań, które
go poprzedziły. Samej odpowiedzi 5xx front nie raportuje drugi raz: jest już
issue w projekcie `catalog`.

## Krok 6 — co NIE trafia do GlitchTipa i dlaczego

Filtrem jest **jedna** lista: handler wyjątków Laravela. SDK dostaje to, co
Laravel raportuje, więc:

- 404, błędy walidacji, brak autoryzacji, CSRF: wbudowane `dontReport`,
- błąd reguły domeny w żądaniu HTTP (`DomainException`, np. „Koszyk jest
  pusty”): oczekiwany błąd użytkownika, pomijany przez istniejące
  `dontReportWhen` poza konsolą,
- próba zadania, po której zadanie wróci do kolejki: `QueueRetryFilter`,
- wiadomość Messengera, która zostanie ponowiona: `capture_soft_fails: false`.

Nie ma drugiej listy wyjątków do utrzymywania: dopisanie wyjątku do
`dontReport` wycisza go i w logu, i w GlitchTipie.

Czego GlitchTip też nie dostaje, z założenia: logów (są w ES), śladów i
metryk (Prometheus), nagrań sesji. Bez handlera Monologa → Sentry, żeby ten
sam błąd nie przyszedł dwa razy; logi są tylko breadcrumbs.

## Krok 7 — awaria GlitchTipa

Narzędzie do awarii nie może wywołać awarii. SDK wysyła synchronicznie, więc
zmierzyłem, ile kosztuje żądanie z błędem, gdy GlitchTip nie odpowiada
(`catalog-app`, ciepła ścieżka, [POMIARY §13](../POMIARY.md)):

| Stan GlitchTipa | Żądanie z błędem |
|---|---|
| działa | 0,22–0,40 s |
| **zawieszony** (`docker pause`) | 1,26–1,34 s |
| zatrzymany | 0,24–0,29 s |

Zatrzymany jest tani, bo nazwa przestaje się rozwiązywać i wysyłka pada
natychmiast. Najgorszy jest **zawieszony**: połączenie przechodzi, odpowiedzi
brak, a koszt to dokładnie `http_timeout`. Dlatego timeouty są krótkie:
`http_connect_timeout` 0,3 s i `http_timeout` 1 s (pierwotnie planowałem 1 s i
2 s; smoke pokazał, że to za dużo). Połączenie w sieci dockerowej trwa
milisekundy, więc 0,3 s ma duży zapas.

Uwaga na pomiar: **pierwszy błąd po bezczynności trwa 2,5–3 s niezależnie od
GlitchTipa** (stwierdziłem to, zatrzymując niepowiązany mailpit przy
działającym GlitchTipie: 3,2 s). Dlatego smoke mierzy narzut względem ciepłej
bazy, a nie bezwzględny czas.

## Krok 8 — `make errors-smoke`

```bash
make up-apps && make up-errors && make errors-sourcemaps
make errors-smoke
```

Skrypt wywołuje błąd z żądania HTTP, z kolejki, z search i z przeglądarki
(Playwright na zainstalowanym Chrome), po czym pyta API GlitchTipa, czy każdy
jest issue we właściwym projekcie z tagami. Sprawdza też zmapowane ramki
frontu, mail o nowym issue w mailpicie, tunel (`errors-tunnel-check`) i
odporność na zawieszony oraz zatrzymany GlitchTip. Przykładowy przebieg:

```
  ✓ HTTP → issue 14 w catalog (process=http, trace_id=b8cfb43b…)
  ✓ kolejka → issue 15 w catalog (process=queue, trace_id=8840d6c6…)
  ✓ search → issue 16 w search (process=consumer, trace_id=f885d4cc…)
  ✓ przeglądarka → issue 17 w catalog-frontend
  ✓ ramki frontu zmapowane do ErrorsSmoke.vue
  ✓ GlitchTip zawieszony: 500, 1.26s wobec 0.30s bazowo (narzut poniżej 1,5 s)
OK: wyjątki ze wszystkich źródeł docierają do GlitchTipa
```

## Pułapki, które wybuchły po drodze

Szczegóły i komendy diagnostyczne w RUNBOOK
[#068–#073](../RUNBOOK.md#068). Skrót tego, co kosztowało czas:

1. **Recipe contrib jest pomijany.** `composer require sentry/sentry-symfony`
   pod Flex nie zarejestrował bundla ani konfiguracji (`IGNORING … recipes-contrib`).
   Bundle i `sentry.yaml` trzeba dodać ręcznie.
2. **SDK odrzuca nieznane klucze configu.** Ustawienia aplikacyjne (tag
   `process`, konfiguracja frontu) nie mogą leżeć w `config/sentry.php`; są
   w `config/error_tracking.php`.
3. **Pest nie pozwala na dwie klasy bazowe.** Podklasa `TestCase` dla testów
   Sentry kończyła się `TestCaseAlreadyInUse`; fałszywy transport podpina się
   opt-in w `Tests\TestCase::createApplication()`.
4. **Zdarzenia routera Inertia v3 mają nowe nazwy:** `httpException` i
   `networkError`, nie `invalid` i `exception`.
5. **Zapytanie `a:b` w API GlitchTipa to filtr klucz:wartość.** Smoke szukał
   `errors-smoke:http:<znacznik>` i nie znajdował istniejących issues; szuka
   teraz po samym znaczniku i wybiera po tytule.
6. **`sentry-cli` w kontenerze nie wgrywa map.** GlitchTip w odpowiedzi
   na `chunk-upload` podaje adres z `GLITCHTIP_DOMAIN` (`localhost:18000`),
   niewidoczny z kontenera. Build idzie w kontenerze, upload z hosta.
7. **Pojedynczy `es01` nie wstaje** z wolumenem klastra 3-węzłowego: głosowanie
   wymaga 2 z 3 węzłów (RUNBOOK [#021](../RUNBOOK.md#021)). Uruchom `es02`
   i `es03` jak `make up-apps`.
8. **Stary `public/hot`** wskazywał martwy serwer Vite, więc strona ładowała
   assety z `localhost:15173` zamiast z buildu.
9. **Końcowy przegląd znalazł wyciek, którego testy nie widziały.** Obraz FrankenPHP
   nie ma `php.ini`, więc `zend.exception_ignore_args` jest Off i ślad wyjątku niesie
   wartości argumentów: wyjątek w `CreateNewUser::create(array $input)` wysłałby do
   GlitchTipa jawne hasło (i do maila o nowym issue). Do tego tokeny resetu hasła szły
   w URL, `Referer` i breadcrumbs, a e-maile w komunikatach SQL (`DETAIL: Key
   (email)=(…)`). Każdy przypadek ma teraz test (Pest, PHPUnit, `node --test`), a smoke
   sprawdza, że e-mail i token z adresu nie ma w zdarzeniu z backendu ani z
   przeglądarki. Wniosek: test „nie wysyłamy body i ciasteczek” nie dowodzi „nie
   wysyłamy PII”; trzeba przejść wszystkie pola zdarzenia (ramki, URL, nagłówki,
   breadcrumbs, komunikaty).
10. **Healthcheck GlitchTipa padał po `git checkout`.** Kontener ma zamontowany
    pojedynczy plik `healthcheck.py`; git podmienia plik (nowy i-węzeł), a kontener
    trzyma stary, więc `python /healthcheck.py` kończył się kodem 2, choć GlitchTip
    działał. Pomaga odtworzenie kontenera (`up -d --force-recreate glitchtip`).

11. **`:?` w nakładce prod blokuje deploy.** Compose interpoluje CAŁY plik, także usługi z
    wyłączonych profili, więc `${GLITCHTIP_DB_PASSWORD:?…}` przerwałoby `docker compose config`
    (czyli każdy deploy) na serwerze ze starym `.env`, zanim ktokolwiek włączy GlitchTipa. W
    prod wartości są puste, a odmowę dają `guard.py` i `setup_db.py`.
12. **`SENTRY_RELEASE=dev` na serwerze.** `compose.yaml` ustawia `dev`, co przesłoniłoby SHA
    wbudowany w obraz. Nakładka prod ustawia `IMAGE_TAG`, więc release w GlitchTipie wskazuje
    wdrożony commit.
13. **`COPY --chmod=0644` psuje katalog.** BuildKit stosuje chmod także do utworzonego katalogu
    docelowego, który traci bit wykonania (`Permission denied` przy `python /opt/…`); poprawne
    jest `0755`.
14. **Compose daje pierwszeństwo zmiennym z powłoki.** Test uruchomiony z wyeksportowanym
    lokalnym `.env` sprawdzał wartości deweloperskie zamiast wygenerowanych; `compose-prod-check`
    czyści teraz z otoczenia zmienne, które definiuje wygenerowany plik.

## Czego jeszcze nie ma

- GlitchTip na VPS jest w `compose.prod.yaml`, ale **niewdrożony**: najpierw merge i obraz
  z CI, potem procedura z RUNBOOK [#074](../RUNBOOK.md#074) i pomiar RAM na serwerze. Na k3s
  go nie ma (SDK są tam wyłączone pustymi DSN-ami, `deploy/k8s/config.env`).
- Upload source map w CI: `docker build --target assets` daje mapy w
  `/sourcemaps`, ale CI nie ma jeszcze dokąd ich wysłać.
- `trace_id` z przeglądarki do żądań HTTP (dziś tylko w drugą stronę, przez
  breadcrumbs).
- Limit *łącznej wielkości* danych w bazie `glitchtip` i sprawdzenie dekompresji po stronie GlitchTipa (ADR-0009, „Przed wystawieniem na VPS”).
- Proces zabity z zewnątrz (OOM, SIGKILL) nie zdąży wysłać zdarzenia; pokrywa go
  `SearchConsumerDown`. Crash komendy `messenger:consume` jako wyjątek TRAFIA do GlitchTipa
  (`ConsumerCommandCrashTest`; lista wyjątków SDK dotyczy tylko śladów wydajności).
