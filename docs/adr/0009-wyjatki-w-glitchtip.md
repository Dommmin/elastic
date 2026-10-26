# ADR-0009: Wyjątki w self-hosted GlitchTipie

- **Status:** przyjęta (wdrożenie w ETAPIE 11b)
- **Data:** 2026-10-26
- **Kontekst decyzji:** uzupełnienie [ADR-0005](0005-metryki-w-prometheusie-logi-w-es.md) o trzeci sygnał; spec [`2026-10-26-glitchtip-design.md`](../superpowers/specs/2026-10-26-glitchtip-design.md), przewodnik [`blog/etap-11b-glitchtip.md`](../blog/etap-11b-glitchtip.md)

## Kontekst

ADR-0005 rozdzielił sygnały: logi w ES (z `trace_id`), metryki i alerty w
Prometheusie. Brakuje pytania, które zadaje się najczęściej po awarii:
**jaki błąd w kodzie, od kiedy, jak często, w której wersji?** Log mówi, że
coś się stało, ale nie grupuje powtórzeń, nie liczy wystąpień, nie ma stanu
„naprawione / wróciło” i nie wysyła maila przy *nowym* rodzaju błędu.
Do tego potrzebne jest narzędzie od wyjątków (issues), nie od zdarzeń.

Fakty:
- Aplikacje mają różne środowiska uruchomieniowe: Laravel na FrankenPHP
  (HTTP, kolejka bazodanowa, scheduler, publisher outboxu), Symfony Messenger
  (długo żyjący konsument) i przeglądarka (Vue/Inertia).
- Dane mają zostać w naszej infrastrukturze, a maszyna jest współdzielona
  i mała (VPS też): każdy kontener ma `mem_limit`.
- `catalog` ma już jedną listę „co nie jest błędem” (`dontReport*` w
  `bootstrap/app.php`). Druga lista wyjątków dla narzędzia błędów
  rozjeżdżałaby się z pierwszą.
- Powstaje `trace_id` przechodzący przez HTTP → outbox → RabbitMQ → search
  (ETAP 11). Wyjątek bez niego byłby wyspą.

## Decyzja

**Podział odpowiedzialności (uzupełnia tabelę z ADR-0005):**

| Pytanie | Gdzie |
|---|---|
| Co się działo w tym przypadku użycia? (pełna historia) | ES / Kibana, po `trace.id` |
| Ile / jak szybko / czy przekroczono próg? | Prometheus / Grafana, alerty Alertmanagera |
| **Jaki błąd w kodzie, od kiedy, jak często, w której wersji?** | **GlitchTip (issues)** |

**GlitchTip 6.2.6 self-hosted, tryb all-in-one** (`GLITCHTIP_EMBED_WORKER=true`,
bez Valkey: `VALKEY_URL=""`, kolejka, cache i sesje w Postgresie), jeden
kontener `mem_limit: 512m`, osobna baza `glitchtip` w istniejącym Postgresie,
profil Compose `errors`. SDK Sentry są protokołem, więc używamy oficjalnych:
`sentry/sentry-laravel`, `sentry/sentry-symfony`, `@sentry/vue`.

**Zasady:**
1. **Do GlitchTipa idą wyjątki, które są błędem w kodzie albo awarią.**
   Nie: 404, walidacja, brak autoryzacji, błąd reguły domeny w HTTP. Filtrem
   jest istniejący handler wyjątków Laravela (`Integration::handles($exceptions)`
   podpina SDK pod to, co Laravel raportuje). Nie utrzymujemy drugiej listy.
2. **Zadanie z ponowieniem raportujemy dopiero przy ostatniej próbie.** Laravel
   zgłasza do handlera wyjątek każdej próby; `QueueRetryFilter` (stan po
   obiekcie wyjątku, `WeakMap`) pomija próby, po których zadanie wraca do
   kolejki. W Symfony to samo robi `capture_soft_fails: false`.
3. **Bez logów, śladów, metryk i nagrań sesji w GlitchTipie.** Logi są w ES,
   latencja w Caddy/Prometheusie. Nie dublujemy sygnałów.
4. **Bez handlera Monologa → Sentry.** Ten sam błąd nie przychodzi dwa razy
   (raz jako wyjątek, raz jako linia `error`). Logi trafiają do zdarzenia tylko
   jako breadcrumbs.
5. **Każde zdarzenie z backendu ma tagi `trace_id` i `process`** (`http`,
   `queue`, `outbox-publisher`, `scheduler`, `consumer`, `metrics`). Most do
   Kibany: issue → `trace.id` → pełny przebieg w logach obu aplikacji.
6. **Bez PII.** `send_default_pii=false`, z użytkownika tylko `user.id`, a SDK
   dostaje natywny filtr `data_collection` (catalog): bez ciasteczek, treści
   żądania i query stringa, nagłówki z allow-listy (odpada `Referer`),
   **bez zmiennych ramek stosu**. To ostatnie jest konieczne, bo obraz FrankenPHP
   nie ma `php.ini`, więc `zend.exception_ignore_args` jest Off i ślad wyjątku
   niesie wartości argumentów (np. tablicę z hasłem w `CreateNewUser::create`);
   wykrył to końcowy przegląd, test odtwarzał jawne hasło w zdarzeniu. Obrazy
   `prod` mają dodatkowo `zend.exception_ignore_args=On`. Czego filtr nie obejmuje,
   czyści `EventScrubber`: tokeny w ścieżce (`/reset-password/{token}`,
   `/email/verify/…`) i dane z komunikatów SQL (`DETAIL: Key (email)=(…)`, zapytanie
   z powiązaniami). W search (bundle nie zna `data_collection`) zmienne ramek czyści
   `EnrichEvent`, w przeglądarce `scrubUrl.ts` (URL, `Referer`, breadcrumbs).
7. **Pusty DSN = SDK wyłączone.** Ten sam obraz działa w każdym środowisku;
   brak GlitchTipa niczego nie psuje.
8. **Awaria GlitchTipa nie przerywa ani nie wydłuża obsługi.** SDK wysyła
   synchronicznie, więc timeouty są twardym kosztem: `http_connect_timeout`
   0,3 s i `http_timeout` 1 s. Pomiar na żywym stacku: przy *zawieszonym*
   GlitchTipie żądanie z błędem kosztuje o `http_timeout` więcej niż bazowo
   (1,26 s wobec 0,30 s), przy *zatrzymanym* nazwa się nie rozwiązuje i koszt
   jest pomijalny ([POMIARY §13](../POMIARY.md)). Pierwotnie planowane 1 s/2 s
   przekraczałoby budżet 3 s.
9. **Przeglądarka nie łączy się z GlitchTipem bezpośrednio.** SDK (opcja
   `tunnel`) wysyła na `POST /_errors`, a Caddy w `catalog-app` przekazuje do
   projektu `catalog-frontend`. Cel i klucz są wpisane na sztywno w zmiennych
   środowiskowych, nie zależą od DSN w kopercie, więc tunel nie jest otwartym
   przekaźnikiem (klucz z query stringa i `X-Sentry-Auth` od klienta nie zmieniają
   celu). Ciało do 1 MB i bez `Content-Encoding` (415), bez ciasteczek i
   autoryzacji, pusty klucz wyłącza tunel (204). GlitchTip nie musi być publiczny.
10. **Konfiguracja jako kod.** Organizację, projekty, **stałe** klucze DSN,
    reguły alertu „nowy issue → e-mail” i token API zakłada idempotentny
    bootstrap (`infra/glitchtip/bootstrap.py`). Klucze są w `.env`, więc
    `make errors-reset` odtwarza identyczny stan. Rozjazd po podbiciu wersji
    kończy się głośno (asercje), nie po cichu.
11. **Source mapy:** `build.sourcemap: 'hidden'`, wgrywane do GlitchTipa przez
    `sentry-cli` z Debug IDs (`make errors-sourcemaps`). Mapy **nie trafiają do
    obrazu prod** (etap `assets` w `catalog.Dockerfile` wycina je do
    `/sourcemaps`).

## Rozważane opcje

| Opcja | Za | Przeciw |
|---|---|---|
| **A. GlitchTip all-in-one na istniejącym Postgresie (wybrana)** | jeden kontener ~200 MiB; protokół Sentry, więc standardowe SDK; dane u nas | mniej funkcji niż Sentry; kolejka w Postgresie (przy tej skali bez znaczenia) |
| B. Oficjalny układ: web + worker + Valkey | worker skaluje się osobno | +3 kontenery i ok. +300 MiB bez zysku przy tym ruchu |
| C. Self-hosted Sentry | pełne funkcje | kilkanaście GB RAM i kilkadziesiąt kontenerów; nieadekwatne do VPS |
| D. Sentry SaaS | zero utrzymania | dane poza infrastrukturą i niczego nie uczy (ten sam argument co opcja D w ADR-0005) |
| E. Wyjątki tylko w ES (logi `error.*`) | zero nowych usług | brak grupowania, liczników, stanu „naprawione / wróciło” i maila przy nowym rodzaju błędu |

## Konsekwencje

**Pozytywne**
- Jeden wpis na klasę błędu z licznikiem, release, środowiskiem i ostatnim
  wystąpieniem; mail przy nowym issue (do mailpita lokalnie).
- Przejście od issue do logów po `trace_id` bez grzebania.
- Front ma stack trace po source mapach (ramki wskazują `ErrorsSmoke.vue`, nie
  `assets/ErrorsSmoke-<hash>.js`).
- `make errors-smoke` sprawdza całą drogę ze wszystkich źródeł i odporność na
  awarię GlitchTipa.

**Negatywne**
- Czwarte UI (GlitchTip obok Kibany, Grafany, RabbitMQ). Korelacja po
  `trace_id` jest ręczna (skopiuj tag, wklej w Kibanie).
- +200 MiB RAM w spoczynku (limit 512m), plus baza `glitchtip` w Postgresie.
- Zdarzenia z okresu awarii GlitchTipa są gubione (SDK nie buforuje na dysku).
- Crash samego konsumenta Messengera (poza obsługą wiadomości) nie trafia do
  GlitchTipa: `messenger:consume` jest na domyślnej liście ignorowanych komend
  SDK. Pokrywa go alert `SearchConsumerDown` i restart kontenera.
- Skrypt bootstrapu używa wewnętrznych modeli Django GlitchTipa (zweryfikowane
  na 6.2.6): przy podbiciu wersji trzeba go sprawdzić (RUNBOOK #069).

**Przed wystawieniem na VPS (nierozstrzygnięte):** tunel `/_errors` nie ma limitu
liczby żądań. Zakładałem, że ogranicza je GlitchTip (throttling organizacji), ale
nie zweryfikowałem tego dla instalacji bez billingu (może wynikać wyłącznie z
kwot). Zdarzenia z publicznego tunelu wypełniałyby bazę `glitchtip`, która jest w
tym samym Postgresie co katalog. Przed wdrożeniem: limit żądań w Caddy (moduł
rate-limit) albo osobny limit rozmiaru bazy; do sprawdzenia też, czy GlitchTip
ogranicza rozmiar *po dekompresji* (tunel odrzuca kompresję, ale sam GlitchTip
przyjmuje ją na swoim porcie).

**Do zrobienia później:** GlitchTip na k3s po pomiarze RAM na VPS, upload source
map w CI (`docker build --target assets` daje mapy), przekazywanie `trace_id`
z przeglądarki do żądań HTTP.
