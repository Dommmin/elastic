# 06 — Plan wdrożenia: pełna mapa drogowa

Dokument nadrzędny. Scala infrastrukturę, aplikacje i naukę w **jedną uporządkowaną
listę etapów**. Do niego wracasz, gdy nie wiesz, co dalej.

---

## CZĘŚĆ A — Rejestr decyzji (ADR-lite)

Decyzje podjęte świadomie, z uzasadnieniem. Jeśli którąś zmienimy, dopisujemy dlaczego.

| # | Decyzja | Uzasadnienie | Alternatywa i dlaczego odpadła |
|---|---|---|---|
| D-01 | Domena: marketplace z ofertami sprzedawców | wymusza naturalnie wszystkie mechanizmy ES | blog/CMS — za mało danych i wymagań |
| D-02 | Dwie aplikacje: Laravel (write) + Symfony (read model) | realny wzorzec CQRS; nauka kontraktu między serwisami | monolit — nie nauczy eventingu ani spójności rozproszonej |
| D-03 | ES nigdy nie jest źródłem prawdy | brak ACID, brak FK | „ES jako baza" — najczęstszy błąd produkcyjny |
| D-04 | Komunikacja: RabbitMQ, topic exchange | asynchroniczność, odporność, nauka AMQP | HTTP sync — sprzęga serwisy, brak odporności |
| D-05 | Transactional Outbox od dnia 1 | jedyna gwarancja braku zgubionych zdarzeń | dual write — antywzorzec (patrz `05`) |
| D-06 | External versioning zamiast wymuszania kolejności | odporne na dowolną kolejność, bez koordynacji | consistent hashing — ograniczy skalowanie (zrobimy jako ćwiczenie porównawcze) |
| D-07a | FrankenPHP (Caddy) zamiast nginx + php-fpm | jeden proces, HTTP/2-3, HTTPS lokalnie, statyki i proxy do Vite bez PHP | php-fpm — działa, ale nie uczy nic nowego i wymaga drugiego kontenera |
| D-07b | Octane worker mode — **jako przełącznik `OCTANE_ENABLED`**, zmierzony w etapie 4 | profil Inertii (dużo małych żądań JSON) daje największy zysk z eliminacji bootstrapu | tryb klasyczny — prostszy w debugowaniu; dlatego zostaje jako opcja, nie jest usuwany |
| D-08 | Frontend: **Inertia.js + Vue 3** | wybór użytkownika — przy okazji nauka Vue; partial reloads pasują do facetów | Livewire — tańszy, ale nie uczy frontendu (patrz sekcja B) |
| D-09 | Logika wyszukiwania w **klasie serwisowej**, nie w kontrolerze/komponencie | pozwala wystawić i UI, i JSON API z tego samego kodu | logika w komponencie — zablokowałaby zmianę frontu |
| D-10 | `xpack.security.enabled=true` od początku | produkcja tak wygląda; API keys i RBAC to osobna kompetencja | wyłączone — wygodne, ale uczy złych nawyków |
| D-11 | `"dynamic": "strict"` w mapowaniach | ochrona przed mapping explosion | dynamic true — wygodne, potem klaster pada |
| D-12 | Klaster 3-nodowy jako tryb domyślny (32 GB RAM) | połowa problemów ES nie istnieje w single-node | single-node — uczy uproszczenia |
| D-13 | Wolumen: 5 mln produktów / 15 mln ofert | przy 10 tys. dokumentów wszystko jest szybkie i nie widać decyzji | mały zbiór — brak materiału do nauki tuningu |
| D-14 | Licencja: basic, trial tylko na moduł ML | uczciwe wobec realiów kosztowych | trial wszędzie — nauczyłbyś się rzeczy, których firma nie kupi |
| D-15 | Cztery tory: search, observability, analityka, agregat 360 | ES to nie tylko wyszukiwarka (patrz `04`) | sam search — poznałbyś 25 % narzędzia |

---

## CZĘŚĆ B — Decyzja o froncie: Livewire vs Inertia (rozstrzygnięcie)

**Nie, Inertia i Livewire to nie to samo.** To dwa różne modele, choć rozwiązują podobny problem.

| | Blade + Livewire | Inertia + Vue/React |
|---|---|---|
| Gdzie żyje stan komponentu | **na serwerze** (PHP) | **w przeglądarce** (JS) |
| Co leci po sieci przy interakcji | żądanie → serwer zwraca HTML/diff → morphing DOM | żądanie → serwer zwraca **propsy JSON** → komponent się przerysowuje |
| Język komponentów | PHP + Blade | Vue/React (prawdziwe komponenty) |
| Build frontu (Node, Vite) | minimalny (tylko CSS/Alpine) | **wymagany**, pełny pipeline |
| Wymagana wiedza | PHP | PHP **+ Vue lub React** |
| Interaktywność bez serwera | ograniczona (Alpine.js) | pełna |

### Weryfikacja Twoich trzech obaw

**„Tracimy API"** — nie, i to niezależnie od wyboru. Ani Livewire, ani Inertia **nie budują**
publicznego REST API (Inertia to protokół propsów, nie REST). Ale API i tak powstanie,
bo wymaga go architektura:
- `/internal/products/{id}/projection` — konsumowane przez `search-service`,
- `/api/search` — cienki kontroler JSON,
- oba korzystają z **tej samej klasy `ProductSearchService`**, co komponent Livewire (D-09).

Czyli UI i API są dwoma adapterami do jednej logiki. Nic nie tracisz — i możesz kiedykolwiek
dostawić Next.js jako trzeci adapter, bez dotykania backendu.

**„Tracimy Octane z FrankenPHP i Caddy"** — nie. To nieporozumienie: **wybór frontendu
nie ma żadnego związku z runtime'em PHP.** FrankenPHP + Octane działa tak samo z Blade,
Livewire i Inertią. Zostaje w planie bez zmian, w każdym wariancie.

**„To nowy obraz zamiast php-fpm"** — tak, ale to również dzieje się niezależnie od frontu.
FrankenPHP **zastępuje parę nginx + php-fpm** jednym procesem, bo jest modułem Caddy'ego.
Ten obraz budujemy tak czy inaczej (faza I-6). Inertia dołożyłaby do niego jedynie
**stage buildu Node/Vite**, nie zmieniłaby runtime'u.

### ✅ Decyzja: Inertia.js + Vue 3

Wybrane świadomie, z dodatkowym celem edukacyjnym (Vue). Uczciwie o kosztach i zyskach:

**Co dochodzi (koszt: ~2–3 wieczory):**
- **stage Node/Vite w Dockerfile** aplikacji `catalog` (multi-stage: `node:24` LTS → build assets
  → kopiowanie `public/build` do obrazu runtime)
- **Vite dev server w trybie dev** — HMR; Caddy proxuje `/@vite` i `/resources` na `vite:5173`
- znajomość Vue 3 (Composition API, `<script setup>`) — do ogarnięcia w tydzień
- opcjonalnie **SSR** (`inertia:start-ssr` jako osobny kontener) — dla SEO wyszukiwarki.
  Zostawiamy jako etap 7b, bo to realny wymóg e-commerce i dobre ćwiczenie

**Co zyskujemy poza samym Vue — i to nie jest oczywiste:**

1. **Partial reloads (`only: [...]`)** — to funkcja Inertii, która wręcz pasuje do facetów.
   Klikasz filtr marki → prosisz serwer tylko o `results` i `facets`, bez `categories`
   i `user`. Serwer wie, których propsów nie liczyć → **nie wykonuje niepotrzebnych
   agregacji w ES**. Realna oszczędność, nie kosmetyka.
2. **Automatyczne anulowanie poprzedniej wizyty** — Inertia domyślnie przerywa poprzedni
   request przy nowym. Rozwiązuje wyścig żądań przy debounce (patrz pułapka niżej).
3. **`deferred` props** — ciężkie agregacje (np. histogram cen po 15 mln ofert) ładujesz
   po wyrenderowaniu wyników. Użytkownik widzi listę natychmiast, facety dojeżdżają.
   Uczy dzielenia zapytań do ES na krytyczne i drugorzędne.
4. **Stan w URL za darmo** — historia przeglądarki, przycisk „wstecz", udostępnianie linku
   z filtrami. W Livewire wymaga to dłubania.
5. **Prawdziwa separacja warstw** — kontroler zwraca dane, nie HTML. Bliżej temu do
   architektury API-first, którą chcieliśmy zachować (D-09).

**Czego pilnujemy, żeby nie stracić:** D-09 zostaje bez zmian — logika w
`ProductSearchService`, kontroler Inertii i kontroler JSON to dwa cienkie adaptery.
Dzięki temu `/api/search` istnieje równolegle i możesz go użyć z czegokolwiek.

### Czy przy Inertii Octane i FrankenPHP mają jeszcze sens?

Pytanie zadane wprost w trakcie planowania, warte zapisania — bo intuicja podpowiada
„skoro nie ma API, to po co worker mode", a jest dokładnie odwrotnie.

**Przesłanka „nie mamy API" jest nieprawdziwa.** Przy Inertii każde żądanie po pierwszym
załadowaniu strony to **XHR zwracający JSON z propsami**. Inertia to protokół JSON-owy,
nie mechanizm renderowania HTML na serwerze. Do tego dochodzą `/api/suggest`,
`/api/events/click` i `/internal/products/{id}/projection`. Ta aplikacja generuje
**więcej** małych żądań niż klasyczny Blade, nie mniej.

#### Dlaczego to wzmacnia sens worker mode, a nie osłabia

Bootstrap frameworka to **koszt stały**, płacony przy każdym żądaniu w modelu php-fpm.
Kluczowa zależność:

> Im **lżejsze i częstsze** żądanie, tym **większy** udział bootstrapu w całości —
> a więc tym większy zysk z worker mode.

Ilustracyjnie (liczby zmierzymy sami w etapie 4, tu chodzi o proporcje):

| Typ żądania | php-fpm: bootstrap + praca | worker mode | Zysk |
|---|---|---|---|
| Kliknięcie facetu (partial reload, zapytanie do ES) | ~25 ms + ~10 ms | ~10 ms | **duży** — bootstrap był 70 % czasu |
| Podpowiedź w autocomplete | ~25 ms + ~5 ms | ~5 ms | **bardzo duży** |
| Ciężki raport `seller-360` | ~25 ms + ~400 ms | ~400 ms | znikomy (6 %) |

Profil obciążenia Inertii to pierwszy i drugi wiersz. **To jest dokładnie ten workload,
w którym worker mode daje najwięcej.**

#### Drugi powód: trwałe połączenia

W php-fpm każde żądanie nawiązuje połączenia od zera. W worker mode żyją między żądaniami:

- **klient ES** — handshake TLS + keep-alive przy każdym kliknięciu facetu to realny koszt,
- **Postgres**, **Redis**,
- **AMQP** — połączenie do RabbitMQ jest szczególnie drogie w nawiązaniu.

#### Trzeci powód: uczciwe profilowanie

Efekt uboczny, ale cenny w projekcie o wydajności: gdy zdejmiesz stały narzut bootstrapu,
w profilerze widać **Twój kod i Twoje zapytania do ES**, a nie ładowanie frameworka.
Tuning ES na aplikacji, w której 70 % czasu to bootstrap PHP, jest zgadywanką.

#### Rozdzielmy dwie decyzje, bo to nie jest jeden wybór

| | Uzasadnienie | Zależy od Inertii? |
|---|---|---|
| **FrankenPHP zamiast nginx + php-fpm** | jeden proces zamiast dwóch, HTTP/2 i HTTP/3, automatyczne HTTPS dla `catalog.localhost`, serwowanie statyków i assetów Vite bez dotykania PHP, proxy do HMR | **nie** — uzasadnione niezależnie |
| **Octane worker mode na FrankenPHP** | zysk wydajnościowy opisany wyżej | **tak — i Inertia go wzmacnia** |

FrankenPHP **da się uruchomić w trybie klasycznym** (żądanie = nowy proces PHP), bez Octane.
Wtedy dostajesz Caddy'ego, HTTP/3 i HTTPS, ale bez worker mode. To jest w pełni sensowna
konfiguracja i **od niej zaczynamy** w etapie 4.

#### Uczciwie o kosztach worker mode

Nie jest darmowy i przećwiczymy to jako osobne ćwiczenie „zepsuj i napraw":

- **wyciek stanu między żądaniami** — statyczne właściwości, singletony trzymające request
  albo zalogowanego użytkownika. Objaw: użytkownik A widzi dane użytkownika B.
  To najgroźniejszy błąd w tym modelu i **musisz go zobaczyć na własne oczy**;
- **wycieki pamięci** — worker żyje godzinami; potrzebny `--max-requests`;
- **zerwane połączenia** — ES/Postgres zamyka idle connection, worker o tym nie wie;
- **trudniejszy debug** — zmiana w kodzie wymaga restartu workera.

#### Rozstrzygnięcie

Oba zostają, ale jako **przełącznik**, nie dogmat: `OCTANE_ENABLED=true|false` w `.env`.
Etap 4 wymaga zmierzenia obu konfiguracji k6-em i zapisania liczb w `POMIARY.md`.
Dopiero wtedy podejmujesz decyzję **na podstawie własnych danych** — a przy okazji
masz gotową odpowiedź na rozmowie kwalifikacyjnej, popartą pomiarem, a nie cytatem z bloga.

### Co konkretnie budujemy w Vue

| Komponent | Funkcja | Mechanizm Inertii | Czego uczy po stronie ES |
|---|---|---|---|
| `SearchBar.vue` | debounce 300 ms + podpowiedzi | `router.get` z `only:['suggestions']` | suggester, wyścigi żądań |
| `SearchResults.vue` | wyniki + infinite scroll | `router.reload` z `preserveState` | `search_after`, `track_total_hits` |
| `FacetPanel.vue` | filtry z licznikami | partial reload `only:['results','facets']` | agregacje, `post_filter`, semantyka liczników |
| `PriceHistogram.vue` | rozkład cen | **`deferred` prop** | ciężka agregacja poza ścieżką krytyczną |
| `SortSelector.vue` | trafność / cena / data | `preserveScroll` | `_score` vs sortowanie po polu |
| `ProductCard.vue` | najtańsza oferta | — | `collapse` + `inner_hits` |
| `AlertForm.vue` | zapisany alert | `useForm` + walidacja | percolator |
| `SellerDashboard.vue` | statystyki sprzedawcy | polling / `deferred` | agregacje, `seller-360` |

**Pułapka do przećwiczenia — wyścig żądań przy debounce.** Użytkownik pisze „lap", „lapt",
„lapto". Trzy requesty w locie, odpowiedzi wracają w losowej kolejności, UI pokazuje wyniki
dla „lapt". Inertia anuluje poprzednią wizytę automatycznie, **ale przy ręcznym `fetch`
do `/api/search` (autocomplete) już nie** — tam zrobimy to jawnie (`AbortController`
albo odrzucanie odpowiedzi ze starym numerem sekwencyjnym).

Zwróć uwagę, że to **dokładnie ta sama klasa problemu** co kolejność zdarzeń w RabbitMQ
z dokumentu `05`: odpowiedzi/wiadomości wracają nie w tej kolejności, w której wyszły,
i obroną jest numer sekwencyjny plus odrzucanie przestarzałych. Ta sama idea na dwóch
zupełnie różnych warstwach systemu — warto to zauważyć.

---

## CZĘŚĆ C — Mapa drogowa: 13 etapów

Każdy etap ma: **cel → co budujemy → moduły nauki → definition of done**.
Nie przechodzimy dalej bez spełnionego DoD.

### ETAP 0 — Fundament repozytorium (0,5 dnia)
- `git init`, struktura katalogów, `.editorconfig`, `.gitignore`
- `compose.yaml` ze szkieletem profili (`default`, `cluster`, `obs`, `ml`, `tools`)
- `Makefile` z pomocą, `.env.example`
- `docs/RUNBOOK.md` — pusty szkielet, który będziesz wypełniał przez cały kurs

**DoD:** `make` wypisuje listę komend; repo ma pierwszy commit.

### ETAP 1 — Warstwa danych (0,5 dnia)
- Postgres (2 bazy: `catalog`, `searchsvc`), Redis, healthchecki, limity zasobów

**DoD:** `make up` → oba kontenery `healthy`; łączysz się do obu baz.

### ETAP 2 — Elasticsearch + Kibana (1–2 dni) → **moduły 1, 2**
- własny obraz ES (`analysis-stempel`, `analysis-icu`), security ON, `es-setup` z hasłami
- Kibana z Dev Tools
- profil `cluster` (es01–es03) z jawnymi rolami node'ów

**DoD:** klaster **green** na 3 node'ach; umiesz wyjaśnić różnicę green/yellow/red;
`GET _analyze` zwraca zrdzeniowane polskie tokeny; w RUNBOOK-u są pierwsze 3 wpisy.

### ETAP 3 — RabbitMQ (0,5–1 dzień) → **moduł 15 (część 1)**
- broker, `definitions.json` (exchange'e, kolejki, bindingi, DLX), panel, quorum queues

**DoD:** ręcznie publikujesz wiadomość i widzisz ją w kolejce; rozumiesz różnicę
durable / persistent / confirms.

### ETAP 4 — Laravel na FrankenPHP + Inertia/Vue (2–3 dni)
- multi-stage Dockerfile: `composer` → `node:24` (build Vite 8) → runtime FrankenPHP 1.12.7
- Laravel 13, migracje modelu z `02-APLIKACJE.md`
- Inertia + Vue 3 (`@inertiajs/vue3`), Vite dev server proxowany przez Caddy (HMR)
- tryb klasyczny → potem **Octane worker mode**, porównanie k6

**DoD:** `https://catalog.localhost` renderuje stronę Vue przez Inertię; HMR działa w dev;
build produkcyjny przechodzi w obrazie; masz **liczby** req/s przed i po worker mode;
znasz 2 pułapki worker mode.

### ETAP 5 — Symfony + Messenger (1 dzień)
- Symfony 8, Messenger z transportem AMQP, konsument „echo", retry + DLQ

**DoD:** event z Laravela ląduje w logu Symfony; celowo zepsuta wiadomość trafia do DLQ
po zdefiniowanej liczbie prób.

### ETAP 6 — Pierwszy indeks i pierwsza wyszukiwarka (2–3 dni) → **moduły 3, 4, 5**
- mapowanie `products-v1` (wersja minimalna), analizatory PL
- outbox w Laravelu + publikator
- konsument indeksujący (`_bulk`, wersjonowanie zewnętrzne, idempotencja)
- `make seed` — najpierw 100 tys. dokumentów

**DoD:** dodajesz ofertę w Laravelu, po < 2 s jest w ES; duplikat eventu nic nie psuje;
event ze starszym `sequence` daje 409 i **nie** cofa danych.

### ETAP 7 — Wyszukiwarka dla ludzi (4–5 dni) → **moduły 6, 7, 8, 9**
- `ProductSearchService` (D-09), Query DSL, facety, autocomplete, collapse
- UI w Vue przez Inertię (komponenty z części B), partial reloads, `deferred` props
- stan filtrów w URL + obsługa przycisku „wstecz"
- jawna obrona przed wyścigiem żądań w autocomplete
- `_rank_eval` + zestaw ~50 zapytań kontrolnych, `make eval`

**DoD:** klikalna wyszukiwarka z poprawnymi licznikami facetów; kliknięcie facetu
**nie** wywołuje niepotrzebnych agregacji w ES (dowód w slowlogu); link z filtrami
działa po wklejeniu; potrafisz wyjaśnić przez `_explain`, dlaczego wynik nr 1 jest
pierwszy; nDCG@10 zmierzone i zapisane jako punkt odniesienia.

### ETAP 7b — SSR (opcjonalny, 1 dzień)
- `inertia:start-ssr` jako osobny kontener, Caddy kieruje boty do SSR

**DoD:** `curl` na stronę wyników zwraca wyrenderowany HTML z produktami.

### ETAP 8 — Skala i tuning (2–3 dni) → **moduł 10**
- `make seed n=5000000` (5 mln produktów, ~15 mln ofert)
- porównanie 1 / 3 / 12 shardów, cache, slowlog, `?profile=true`, `hot_threads`
- świadome wywołanie i naprawa: 429, circuit breaker, głęboka paginacja

**DoD:** własna tabela decyzyjna „ile shardów i dlaczego" oparta na **Twoich pomiarach**;
5 nowych wpisów w RUNBOOK-u.

### ETAP 9 — Spójność na poważnie (2–3 dni) → **`05-SPOJNOSC-DANYCH.md`, moduł 15 (część 2)**
- pełna obsługa per-item błędów bulka, klasyfikacja błędów, `dlq:replay`
- reconciliation 3-poziomowy + metryka lag + alerty
- **testy chaosu** z sekcji 10 dokumentu `05` — wszystkie 10

**DoD:** każdy z 10 testów chaosu kończy się zbieżnością danych; ręczny `UPDATE`
w psql zostaje wykryty i naprawiony automatycznie.

### ETAP 10 — Operacje na indeksach (2 dni) → **moduł 11**
- aliasy, reindeks v1→v2 z zerowym downtime, `update_by_query` z throttlingiem
- snapshoty (`fs`, potem MinIO), SLM, restore

**DoD:** `make reindex` przechodzi na 5 mln dokumentów bez przerwy w działaniu
wyszukiwarki; kasujesz indeks i przywracasz ze snapshotu.

### ETAP 11 — Tor observability (3–4 dni) → **moduł 10a**
- logi ECS z obu apek, `trace_id` propagowany przez RabbitMQ
- Filebeat/Metricbeat, ingest pipelines, data streams `logs-app-*`, ILM
- Stack Monitoring, dashboard latencji i błędów

**DoD:** jedno żądanie użytkownika prześledzone przez Laravel → RabbitMQ → Symfony → ES
po jednym `trace_id`; ILM przenosi indeksy między fazami na Twoich oczach.

### ETAP 12 — Tor analityki operacyjnej (3–4 dni) → **moduł 13a**
- data stream `events-user-*`, lejek konwersji, raport „zero results"
- Transforms → `search-stats-*`, pętla zwrotna do `rank_feature`
- ES|QL, wykrywanie anomalii regułami

**DoD:** dashboard z lejkiem i CTR; popularność z kliknięć realnie zmienia ranking;
mierzysz `_rank_eval` przed i po i **udowadniasz** poprawę.

### ETAP 13 — Tor agregatu „Seller 360" (4–5 dni) → **moduł 11a**
- indeks `seller-360` scalany z 5 źródeł, partial updates (`doc_as_upsert`)
- `enrich` processor, fan-out, wersjonowanie per sekcja, metryka świeżości per sekcja
- FLS/DLS: konsultant widzi saldo, sprzedawca nie → **moduł 12**

**DoD:** działa zapytanie z sekcji 5 dokumentu `04` (spadek konwersji + zaległość +
fraza w reklamacjach); opóźnienie jednego źródła nie psuje pozostałych sekcji dokumentu.

### ETAP 14 (opcjonalny) — Semantyka i ML (2–3 dni) → **moduł 13**
- `dense_vector`, kNN, hybryda z RRF, porównanie z BM25 na tym samym `_rank_eval`
- profil `ml` na trialu: ELSER / anomaly detection

**DoD:** liczbowe porównanie BM25 vs kNN vs hybryda; wiesz, kiedy wektory szkodzą.

### ETAP 15 (opcjonalny) — Security analytics lite → **tor 5 z `04`**
- reguły detekcji nadużyć, `significant_terms`, Kibana Alerting

---

## CZĘŚĆ D — Szacunek czasu

| Blok | Etapy | Czas (wieczory po ~2 h) |
|---|---|---|
| Infrastruktura + fundament | 0–5 | 12–18 |
| Rdzeń: wyszukiwarka + skala | 6–8 | 18–25 |
| Produkcyjna jakość | 9–10 | 12–16 |
| Tory rozszerzające | 11–13 | 25–35 |
| Opcjonalne | 14–15 | 10–15 |
| **Razem** | | **75–110 wieczorów ≈ 4–6 miesięcy** |

Etapy 0–10 (≈ 45–60 wieczorów) dają już poziom **solidnego mida** znającego ES od strony
produkcyjnej. Etapy 11–13 są tym, co odróżnia „umiem wyszukiwarkę" od „rozumiem ES jako
platformę danych".

---

## CZĘŚĆ E — Czego świadomie NIE robimy (granice zakresu)

Żeby projekt się nie rozlał:

- ❌ **Kubernetes / deploy produkcyjny** — wszystko lokalnie w Dockerze. Inny temat.
- ❌ **CI/CD** — poza jednym testem kontraktowym; nie jest celem.
- ❌ **Płatności, realny checkout** — koszyk kończy się na walidacji stanu; nie budujemy sklepu.
- ❌ **Kafka** — RabbitMQ wystarczy do nauki eventingu; różnice omówimy teoretycznie.
- ❌ **Debezium / CDC** — omówimy jako wariant, nie wdrażamy (dodałoby Kafka Connect).
- ❌ **Elastic Security / pełny SIEM** — tylko lekka wersja w etapie 15.
- ❌ **Własne trenowanie modeli ML** — używamy gotowych, nie uczymy.
- ❌ **Wielojęzyczność wyszukiwarki** — tylko polski + angielski, żeby pokazać mechanizm.

Jeśli któryś punkt zacznie Cię ciągnąć — dopisujemy jako etap 16+, nie wciskamy w środek.

---

## CZĘŚĆ F — Artefakty, które powstaną poza kodem

Kod jest ulotny; te dokumenty zostaną z Tobą:

1. **`docs/RUNBOOK.md`** — Twój osobisty przewodnik diagnostyczny, budowany po każdym
   ćwiczeniu „zepsuj i napraw". **To jest najcenniejszy artefakt całego projektu.**
2. **`docs/POMIARY.md`** — tabela wszystkich pomiarów: bulk vs single, shardy, cache,
   worker mode, BM25 vs hybryda. Twoje własne liczby, nie cudze blogi.
3. **`docs/adr/`** — decyzje architektoniczne podejmowane w trakcie, z uzasadnieniem.
4. **`tests/relevance/queries.yaml`** — zestaw zapytań kontrolnych do `_rank_eval`.
5. **Odpowiedzi na pytania kontrolne** z dokumentów `03`, `04`, `05` — napisane własnymi
   słowami. Jeśli nie umiesz odpowiedzieć, wracasz do modułu.

---

## CZĘŚĆ G — Zasady pracy w trakcie

1. **Nie idź dalej bez DoD.** Etapy są zależne; niedokończony fundament mści się później.
2. **Każde ćwiczenie „zepsuj i napraw" jest obowiązkowe.** Diagnozowania nie da się
   nauczyć z opisu — tylko z widzenia błędu na własne oczy.
3. **Mierz, nie wierz.** Zdanie o wydajności bez liczby nie istnieje.
4. **Po każdym etapie: 3 wpisy do RUNBOOK-a i aktualizacja POMIARÓW.**
5. **Pytania bez odpowiedzi zapisuj.** Lista niewiedzy jest planem nauki.
6. **Dokumentacja Elastica > blogi.** Blogi są zwykle nieaktualne o 2–3 wersje major.

---

## CZĘŚĆ H — Stan planu

| Dokument | Status |
|---|---|
| `00-PRZEGLAD.md` | ✅ gotowy |
| `01-INFRASTRUKTURA.md` | ✅ gotowy (zaktualizowany pod 32 GB) |
| `02-APLIKACJE.md` | ✅ gotowy |
| `03-SCIEZKA-NAUKI.md` | ✅ gotowy (15 modułów + 3 nowe z toru 2–4) |
| `04-ES-JAKO-PLATFORMA.md` | ✅ gotowy |
| `05-SPOJNOSC-DANYCH.md` | ✅ gotowy |
| `06-PLAN-WDROZENIA.md` | ✅ ten dokument |
| **Kod** | ⏸️ **nie zaczęty — świadomie, zgodnie z ustaleniem** |

**Otwarte pytania:** brak. D-08 rozstrzygnięte na **Inertia + Vue 3**.
Plan jest kompletny i gotowy do realizacji od ETAPU 0.
