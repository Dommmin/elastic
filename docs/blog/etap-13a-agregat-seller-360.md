# Agregat „Seller 360”, krok po kroku: jeden dokument z pięciu źródeł, wersje sekcji, świeżość i dowód zapytania

*ETAP 13a projektu Marketplace Search Platform. Konsultant ma odpowiedzieć na pytanie „którzy sprzedawcy
z mazowieckiego tracą konwersję, zalegają z płatnościami i dostają reklamacje o uszkodzonych paczkach?”. Dane leżą
w pięciu systemach: profil i oferty w catalogu, sprzedaż w analityce z ETAPU 12, rozliczenia w nowym kontekście
Billing, reklamacje w pliku CSV z helpdesku, a status VAT i kurier w zewnętrznym rejestrze. Joina przez te systemy nie
ma. Ten etap buduje piąty archetyp ES: **zmaterializowany model odczytu** `seller-360`, jeden dokument na sprzedawcę,
składany z sześciu niezależnych sekcji. Część 13b (fan-out kuriera, przebudowa bez przestoju, FLS/DLS) jest
w [`etap-13b-skala-i-bezpieczenstwo.md`](etap-13b-skala-i-bezpieczenstwo.md).*

> **Stan:** gałąź `etap-13-seller-360` (niewypchnięta), lokalny stos Docker (3 węzły ES 9.5.1, RabbitMQ, Redis),
> sprawdzone 2026-11-22. **Na żywo:** 20 061 dokumentów (20 000 syntetycznych + 61 prawdziwych), zapytanie z DoD
> zwraca dokładnie 5 zasadzonych sprzedawców (precyzja 1,0, czułość 1,0, 0 bliskich pudeł, p50 1 ms / p99 6 ms),
> awaria rejestru zostawia `billing` i `profile` świeże (zapis po 3–4 s), a `registry` przechodzi w `stale`
> z rosnącym lagiem i wraca do `ok` po końcu awarii.
> Projekt: [specyfikacja](../superpowers/specs/2026-11-21-etap-13-seller-360-design.md).
> Pomiary: [`POMIARY.md` §17](../POMIARY.md). Błędy z drogi: RUNBOOK [#140–#145](../RUNBOOK.md#140).
> Nie wdrożone na k3s (brak serwera, manifesty w [13b](etap-13b-skala-i-bezpieczenstwo.md#krok-14--manifesty-k3s-i-sekrety)).

---

## Spis treści

1. [Problem: dlaczego agregat](#1-problem-dlaczego-agregat)
2. [Mapa źródeł i dokumentu](#2-mapa-źródeł-i-dokumentu)
3. [Mapa plików](#3-mapa-plików)
4. [Cały tor 13a od zera](#4-cały-tor-13a-od-zera)
5. [Krok 1 — NIP jako klucz tożsamości](#krok-1--nip-jako-klucz-tożsamości)
6. [Krok 2 — zdarzenia z catalogu: seller.\* i billing.\* przez outbox](#krok-2--zdarzenia-z-catalogu-seller-i-billing-przez-outbox)
7. [Krok 3 — strażnik wersji i `noop`](#krok-3--strażnik-wersji-i-noop)
8. [Krok 4 — `doc_as_upsert` a skrypt](#krok-4--doc_as_upsert-a-skrypt)
9. [Krok 5 — region: czemu enrich nie działa w `_update`](#krok-5--region-czemu-enrich-nie-działa-w-_update)
10. [Krok 6 — koalescencja ofert](#krok-6--koalescencja-ofert)
11. [Krok 7 — sprzedaż z ETAPU 12](#krok-7--sprzedaż-z-etapu-12)
12. [Krok 8 — reklamacje: import z enrich po NIP](#krok-8--reklamacje-import-z-enrich-po-nip)
13. [Krok 9 — rejestr: circuit breaker i `stale`](#krok-9--rejestr-circuit-breaker-i-stale)
14. [Krok 10 — lag: co właściwie mierzymy](#krok-10--lag-co-właściwie-mierzymy)
15. [Krok 11 — generator i prawda podstawowa](#krok-11--generator-i-prawda-podstawowa)
16. [Krok 12 — ekran konsultanta](#krok-12--ekran-konsultanta)
17. [Krok 13 — dowód zapytania z DoD](#krok-13--dowód-zapytania-z-dod)
18. [Krok 14 — opóźnione źródło](#krok-14--opóźnione-źródło)
19. [Incydent: przepełnione kolejki i replay przez shovel](#incydent-przepełnione-kolejki-i-replay-przez-shovel)
20. [Liczby z żywego stosu](#liczby-z-żywego-stosu)
21. [Ograniczenia](#ograniczenia)
22. [Co wybuchło po drodze](#co-wybuchło-po-drodze)
23. [Ściąga](#ściąga)

---

## 1. Problem: dlaczego agregat

Zapytanie z `04` §5 łączy cztery warunki z czterech systemów i sortuje po piątym. Bez agregatu są trzy drogi i każda
jest zła:

| Droga | Co się psuje |
|---|---|
| Join w aplikacji (odpytaj każdy system, przetnij wyniki) | 20 tys. sprzedawców × 5 systemów na każde kliknięcie filtra; sortowanie po sprzedaży wymaga pobrania wszystkiego; awaria jednego systemu kładzie ekran |
| Jedna baza dla wszystkich | Billing, analityka i rejestr to osobne konteksty z własnym tempem. Wspólna baza to rozproszony monolit |
| Kopia w ES robiona nocnym wsadem | Dzień opóźnienia, a pełny przelot 20 tys. dokumentów co noc i tak nie mówi, która część jest nieświeża |

Agregat odwraca kierunek: każde źródło **pcha** swoją część do jednego dokumentu, kiedy ona się zmienia, a zapytanie
idzie do jednego indeksu. Cena: trzeba poradzić sobie z tym, czego join nie ma:

- sekcje przychodzą w dowolnej kolejności, powtórzone i spóźnione (strażnik wersji, krok 3);
- źródło potrafi wysłać setki zdarzeń na sekundę o tym samym (koalescencja, krok 6);
- źródło potrafi paść (circuit breaker i `stale`, krok 9);
- każda sekcja ma inną świeżość i trzeba ją mierzyć osobno (lag, krok 10).

## 2. Mapa źródeł i dokumentu

| Sekcja | Źródło | Kanał | Klucz u źródła | Wersja |
|---|---|---|---|---|
| `profile` | catalog, SellerPortal | `seller.*` przez outbox → `search.seller360` | `seller_id` | `version` agregatu |
| `billing` | catalog, nowy kontekst Billing | `billing.*` przez outbox | `seller_id` | `version` konta |
| `offers` | catalog, oferty | `offer.*` jako sygnał „brudny”, przeliczenie z `GET /api/internal/sellers/{id}/offer-stats` | `seller_id` | `computed_at` w ms |
| `sales` | zdarzenia ETAPU 12 v2 → `events-seller-default` → Transform `seller-sales-daily` | zadanie co 300 s | `seller_id` | czas przeliczenia w ms |
| `complaints` | helpdesk, plik CSV | import do indeksu `complaints` przez enrich `seller-by-nip` | **NIP** | czas przeliczenia w ms |
| `registry` | mock rejestru firm i kurierów | HTTP z cache, ponowieniami i bezpiecznikiem | **NIP** | czas próby w ms |

Każda sekcja ma `_meta: {version, source_ts, updated_at, status: ok|stale|error, error}`. Dokument ma jeszcze pola
`seller_id` (`_id`), `nip`, `region`, `synthetic` i `doc_updated_at`. **Brak sekcji to stan poprawny:** dokument
powstaje z pierwszej sekcji, która przyjdzie (`scripted_upsert`), a reszta dochodzi. Mapowanie jest
`dynamic: strict`, `_meta` jest jawnie w każdej sekcji
(`infra/elasticsearch/seller360/template-seller-360.json`).

Prawdziwy przykład (sprzedawca zasadzony przez generator, skrócone):

```json
{
  "seller_id": "5008159", "nip": "1500815906", "region": "mazowieckie", "synthetic": true,
  "profile":  { "name": "Hurtownia Kasztan 8160", "city": "Mińsk Mazowiecki", "status": "active",
                "_meta": { "version": 2, "source_ts": "2026-11-22T01:47:27Z", "updated_at": "2026-11-22T01:51:10Z", "status": "ok" } },
  "billing":  { "overdue_cents": 73703, "balance_cents": 86591, "open_invoices": 2, "_meta": { "version": 1, "...": "..." } },
  "sales":    { "gmv_30d_cents": 213287, "cart_sessions_prev_30d": 44, "conversion_drop": 0.326531, "_meta": { "version": 1795313933633, "...": "..." } },
  "complaints": { "open": 2, "last_30d": 4, "recent_texts": ["Przesyłka uszkodzona, zawartość rozbita.", "..."], "_meta": { "...": "..." } },
  "registry": { "legal_name": "Kowalski Sp. j. 126", "vat_status": "active", "courier": { "id": "courier-3" }, "_meta": { "...": "..." } }
}
```

Sekcji `offers` tu nie ma: syntetyczni sprzedawcy nie mają ofert. Karta konsultanta pokaże „brak danych z oferty”.

## 3. Mapa plików

```
apps/catalog/
  src/SellerPortal/Domain/Seller/TaxId.php                    NIP: VO z sumą kontrolną
  src/SellerPortal/Infrastructure/Persistence/SellerIntegrationEvents.php   seller.* do outboxu (pełny profil + version)
  src/Billing/{Domain,Application,Infrastructure}/            kontekst Billing: BillingAccount, Invoice, billing.*
  app/Http/Controllers/Internal/SellerExportController.php    /api/internal/sellers, offer-stats, billing/accounts
  app/Seller360/Simulation/*                                  generator: sprzedawcy, billing, reklamacje, sprzedaż, prawda podstawowa
  app/Seller360/{Seller360Query,Seller360Reader,Seller360Card}.php   zapytanie z filtrów, odczyt ES, props karty
  app/Http/Controllers/Panel/Seller360Controller.php          /panel/sellers-360 (lista i karta)
  app/Console/Commands/Seller360QueryVerifyCommand.php        seller360:query:verify (dowód DoD)
  resources/js/pages/panel/sellers360/{Index,Show}.vue        ekran konsultanta
apps/search/
  src/MessageHandler/Seller360EventHandler.php                seller.* → profile, billing.* → billing, offer.* → zbiór brudnych
  src/Seller360/SectionWriter.php                             _update + skrypt seller360-apply-section (prywatny i publiczny)
  src/Seller360/RegionResolver.php                            miasto → województwo z CSV (zamiast enrich)
  src/Seller360/OffersSectionFlusher.php                      koalescencja ofert (RENAME + klucz roboczy)
  src/Seller360/SalesSectionRefresher.php                     sekcja sales z seller-sales-daily
  src/Seller360/Complaints/*                                  import CSV, retry unmatched, sekcja complaints
  src/Seller360/Registry/*                                    klient rejestru, bezpiecznik, synchronizacja z cache
  src/Seller360/LagReport.php                                 lag per sekcja → Redis seller360:lag → /metrics
  src/Command/Seller360*Command.php                           komendy seller360:*
infra/elasticsearch/seller360/                                szablony seller-360(-public), complaints, skrypt, enrich, pipeline
infra/elasticsearch/setup-seller360.sh                        make seller360-setup (idempotentny)
infra/reference/miasta-wojewodztwa.csv                        656 miast, 16 województw
infra/kibana/{seller360-dashboard.ndjson,rules/seller360-*.json}   dashboard świeżości i dwie reguły
tools/registry-mock/                                          mock rejestru (php -S), /_chaos
tools/seller360/{replay-dlq.sh,stale-source.sh}               replay DLQ przez shovel, skrypt opóźnionego źródła
```

Procesy w `compose.yaml` (profil `apps`): `seller360-consumer` (kolejka `search.seller360`), `seller360-scheduler`
(`offers:flush` co 30 s, `sales:refresh` co 300 s, `lag` co 30 s), `seller360-registry` (pętla synchronizacji
rejestru), `registry-mock`.

## 4. Cały tor 13a od zera

Stos (`make up`) działa, `make analytics-setup` z ETAPU 12 też (reguły lagu piszą do jego indeksu
`alerts-analytics`, RUNBOOK [#145](../RUNBOOK.md#145)). Potem:

```bash
make seller360-setup                     # szablony, skrypt, aliasy, enrich, pipeline, dashboard, reguły
docker compose up -d seller360-consumer seller360-scheduler seller360-registry registry-mock
make seller360-simulate args="--sellers=20000 --billing --sales --complaints=storage/app/seller360/complaints.csv --plant=5"
make seller360-complaints-import         # CSV z catalogu do search, enrich po NIP, sekcje complaints
# po ~5 min (Transform + sales:refresh):
make seller360-verify                    # precyzja/czułość/bliskie pudła, p50/p99
make seller360-stale-source              # awaria rejestru, ~2,5 min
open "https://catalog.localhost:${CATALOG_HTTPS_PORT:-8443}/panel/sellers-360"   # konto admina
```

Generator z backpressure jedzie około 14 minut (czeka, aż projektor zje kolejkę, krok 11). Bez niego kolejki się
przepełniły; ta historia jest w [incydencie](#incydent-przepełnione-kolejki-i-replay-przez-shovel).

## Krok 1 — NIP jako klucz tożsamości

Helpdesk i rejestr nie znają naszego `seller_id`. Znają NIP. Agregat musi więc umieć rozwiązać tożsamość po NIP,
a do tej pory sprzedawca NIP-u nie miał.

- `TaxId` w SellerPortal: 10 cyfr, myślniki i spacje dozwolone na wejściu, suma kontrolna z wagami 6, 5, 7, 2, 3, 4,
  5, 6, 7. Zły NIP to `InvalidValue`, a nie wiersz w bazie.
- Formularz zgłoszenia sprzedawcy ma pole NIP; `sellers.nip` jest `unique`, a naruszenie to błąd domeny
  `tax_id_already_taken` pod polem formularza.
- Istniejący sprzedawcy dostali NIP z migracji, z deterministycznego generatora poprawnych NIP-ów
  (`TaxIdGenerator::forSeed(id)`). Generator syntetycznych sprzedawców używa `forSeed(5 000 000 + i)`.

**Dlaczego unikalny i walidowany już w domenie:** w ES NIP jest `keyword` i kluczem polityki enrich. Dwa dokumenty
z tym samym NIP-em to dwie odpowiedzi na pytanie „czyja to reklamacja”, a enrich wybierze jedną bez ostrzeżenia
([Ograniczenia](#ograniczenia)).

## Krok 2 — zdarzenia z catalogu: seller.\* i billing.\* przez outbox

Do ETAPU 13 `SqlSellerRepository` celowo nie publikował zdarzeń sprzedawcy. Teraz publikuje **zdarzenia integracyjne**
(nie domenowe 1:1) w tej samej transakcji co zapis: `seller.registered`, `approved`, `rejected`, `blocked`,
`unblocked`, `renamed`, `relocated`. Ładunek to **pełny stan profilu** plus `version` agregatu:

```json
{"seller_id": 5008159, "name": "Hurtownia Kasztan 8160", "city": "Mińsk Mazowiecki", "nip": "1500815906",
 "status": "active", "status_reason": null, "synthetic": true, "version": 2, "occurred_at": "2026-11-22T01:47:27.123456+00:00"}
```

`occurred_at` w ładunku `seller.*` i `billing.*` to ISO 8601 z offsetem i mikrosekundami (`SqlTimestamp::iso()`).
Pierwsza wersja miała postać SQL `2026-11-22 01:47:27.123456` bez strefy: konsument z inną strefą domyślną odczytałby
inny moment, a `source_ts` sekcji i lag razem z nim. Projektor przyjmuje oba formaty (wiadomości sprzed zmiany
w kolejce czy DLQ dalej się parsują; obie postacie dają ten sam moment przy strefie UTC kontenerów).

Pełny stan zamiast „co się zmieniło” jest kluczowy: projektor nie musi niczego łączyć ani znać poprzedniego stanu.
Najnowsze zdarzenie po prostu zastępuje sekcję. Zgubione zdarzenie pośrodku nie zostawia dziury, bo następne niesie
wszystko.

Dwie zmiany w domenie, bez których `profile` nigdy by się nie zmienił:

- `Seller::rename()` i `Seller::relocate()` stały się metodami agregatu ze zdarzeniami i podbiciem `version`.
  `marketplace:seller-rename` (ETAP 10) przeszedł na ścieżkę domenową. Wcześniej robił zwykły UPDATE bez wersji,
  a zmiana bez nowej wersji przegrywałaby ze strażnikiem.
- `ProductSyncHandler` (związany z `seller.#`) ignoruje te siedem typów na poziomie `debug`, zamiast ostrzegać
  o każdym.

Billing to nowy kontekst `apps/catalog/src/Billing` (Domain, Application, Infrastructure, jak Ordering): agregat
`BillingAccount` z fakturami, zdarzenia `billing.invoice_issued`, `billing.payment_received`,
`billing.invoice_overdue` (to ostatnie emituje sweep `billing:mark-overdue` co 5 minut). Ładunek: `balance_cents`,
`overdue_cents`, `overdue_since`, `open_invoices`, `invoice_id`, `version`.

Kolejka `search.seller360` (quorum, limit dostarczeń 5) jest związana z `marketplace.events` kluczami `seller.#`,
`billing.#` i `offer.#`. Ma **własny** `x-dead-letter-routing-key: search.seller360`, a `search.seller360.dlq` jest
związana tylko z tym kluczem. Dlaczego: `marketplace.dlx` kieruje martwe wiadomości po ich pierwotnym kluczu,
a `seller.#` i `offer.#` są już związane z `search.product.sync.dlq`. Bez własnego klucza martwe wiadomości
projektora mieszałyby się z DLQ synchronizacji produktów (RUNBOOK [#140](../RUNBOOK.md#140)).

## Krok 3 — strażnik wersji i `noop`

Zdarzenia przychodzą at-least-once, w dowolnej kolejności między kolejkami i z replayu DLQ. Zapis sekcji musi więc
być **idempotentny i monotoniczny**: starsza wersja nigdy nie nadpisuje nowszej. Robi to skrypt zapisany w klastrze
`seller360-apply-section` (`infra/elasticsearch/seller360/script-apply-section.json`):

```painless
Map stored = ctx._source[params.section];
long incoming = ((Number) params.meta.version).longValue();
if (stored != null && stored._meta != null && stored._meta.version != null
    && ((Number) stored._meta.version).longValue() >= incoming) {
  ctx.op = 'noop';            // spóźnione albo powtórzone: nic nie zmieniamy
  return;
}
Map next = new HashMap(params.data);
next._meta = params.meta;
ctx._source[params.section] = next;          // CAŁA sekcja zastąpiona
ctx._source.doc_updated_at = params.meta.updated_at;
```

`SectionWriter` woła go przez `_update` z `scripted_upsert: true`, `upsert: {}` i `retry_on_conflict: 3`:

```bash
# podgląd ręczny: to samo, co robi projektor (konto searchsvc z kontenera)
docker compose exec -T search-consumer sh -c 'curl -s -u "$ELASTICSEARCH_USER:$ELASTICSEARCH_PASSWORD" \
  -H "Content-Type: application/json" -X POST "$ELASTICSEARCH_HOST/seller-360-write/_update/5008159" -d "{
  \"scripted_upsert\": true, \"upsert\": {},
  \"script\": {\"id\": \"seller360-apply-section\", \"params\": {\"section\": \"profile\",
    \"data\": {\"name\": \"stara nazwa\"}, \"meta\": {\"version\": 1, \"source_ts\": \"2026-11-01T00:00:00Z\",
    \"updated_at\": \"2026-11-01T00:00:00Z\", \"status\": \"ok\", \"error\": null}}}}"' | jq -r .result
# noop    ← dokument ma profile._meta.version 2, więc wersja 1 przegrywa
```

Wynik `noop` liczy metryka `seller360_section_writes_total{result="noop"}`. Konflikt wersji **dokumentu**
(`409 version_conflict`) to coś innego: dwie sekcje piszą ten sam dokument naraz. `retry_on_conflict: 3` każe ES
ponowić skrypt na świeżej wersji dokumentu, co jest bezpieczne, bo skrypt sam sprawdza wersję sekcji.

**Dlaczego replay jest bezpieczny.** Każde zdarzenie niesie pełny stan i wersję. Przepuszczenie przez projektor
całej DLQ jeszcze raz daje `noop` dla wszystkiego, co już jest nowsze, i domyka tylko to, czego brakowało. Na tym
oparty był replay 19 817 wiadomości w [incydencie](#incydent-przepełnione-kolejki-i-replay-przez-shovel).

Ten sam skrypt pisze kopię okrojoną do `seller-360-public` (tylko `profile`, `offers`, `sales` i ocena kuriera,
`PublicProjection`). Zapis publiczny idzie zawsze, także gdy prywatny był `noop`: ponowienie po awarii między dwoma
zapisami domyka kopię publiczną. Odczyt tej kopii przez sprzedawcę to temat [13b](etap-13b-skala-i-bezpieczenstwo.md#krok-10--widok-sprzedawcy-na-licencji-basic).

## Krok 4 — `doc_as_upsert` a skrypt

Spec zakładał, że sekcje przeliczane w całości (`offers`, `sales`, `complaints`, `registry`) pójdą przez prostsze
`doc_as_upsert`. Implementacja pisze **wszystkie** sekcje tym samym skryptem. Powody:

| | `{"doc": {...}, "doc_as_upsert": true}` | skrypt `seller360-apply-section` |
|---|---|---|
| Strażnik wersji | brak: `_update` z `doc` nie umie warunkowo zrobić `noop` | `ctx.op = 'noop'` przy starszej wersji |
| Zastąpienie sekcji | **scala mapy rekurencyjnie**: pole, które zniknęło u źródła, zostaje w ES | `ctx._source[sec] = next`, sekcja zastąpiona w całości |
| Pierwszy zapis | tworzy dokument z `doc` | `scripted_upsert` z pustym `upsert` uruchamia skrypt także na nowym dokumencie |
| Koszt | tańszy (bez Painless) | kompilacja raz (skrypt zapisany), wykonanie per zapis |

`doc_as_upsert` wystarcza, gdy sekcja jest migawką, którą pisze **jeden** proces w **rosnącym** czasie, a pola nigdy
nie znikają. „Ostatni zapis wygrywa” jest wtedy prawdą. Przestaje wystarczać, gdy zapisy mogą przyjść poza
kolejnością (dwa przebiegi przeliczenia, które się nałożyły; backfill z przebudowy w 13b ścigający się z zapisem na
żywo). Dla przeliczeń wersja to czas przeliczenia w ms (`computed_at`), więc stare przeliczenie nie nadpisze nowego.

Pułapka po drodze: `new HashMap(params.data)` na pustej tablicy PHP. `json_encode([])` daje `[]`, czyli listę,
i skrypt rzuca `ClassCastException`. `SectionWriter` wysyła `data`, `meta` i `doc` zawsze jako obiekty JSON.

## Krok 5 — region: czemu enrich nie działa w `_update`

Region (województwo) trzeba wyliczyć z miasta. Naturalny pomysł z ETAPU 12: enrich processor z polityką
„miasto → województwo”. Nie zadziała: **`_update` nie przechodzi przez ingest pipeline.** `default_pipeline` dotyczy
`index`, `create` i `_bulk index`, a częściowa aktualizacja dokumentu wykonuje się na shardzie, bez pipeline'u.

Dlatego region liczy projektor, `RegionResolver`, ze statycznego pliku `infra/reference/miasta-wojewodztwa.csv`
(656 miast, normalizacja bez polskich znaków i wielkości liter; nazwy niejednoznaczne po normalizacji pominięte).
Nieznane miasto daje `region: null` i licznik `seller360_region_unknown_total`. Ten sam plik czyta generator, więc
miasta syntetycznych sprzedawców zawsze się rozwiązują.

Enrich jest w tym etapie użyty tam, gdzie ma sens: przy **imporcie** reklamacji, bo to zwykłe `index` przez
pipeline (krok 8).

## Krok 6 — koalescencja ofert

`make churn` zmienia stany magazynowe setki razy na sekundę. Zamiana każdego `offer.updated` na zapis do
`seller-360` byłaby amplifikacją bez sensu: sekcja `offers` to cztery liczniki, a konsultantowi wystarcza ich stan
sprzed kilkudziesięciu sekund.

Droga:

1. projektor na `offer.*` robi tylko `SADD seller360:dirty:offers <seller_id>` (plus licznik zdarzeń);
2. co 30 s `seller360:offers:flush` zdejmuje zbiór i dla każdego sprzedawcy woła **raz**
   `GET /api/internal/sellers/{id}/offer-stats` (token z ETAPU 10b), niezależnie od liczby zdarzeń;
3. wynik idzie jednym `_bulk` przez ten sam skrypt, z wersją = `computed_at` w ms.

Zdjęcie zbioru musi być atomowe względem `SADD` z projektora. Użyte `RENAME seller360:dirty:offers →
seller360:dirty:offers:flushing`: nowe zdarzenia lecą od razu do świeżego zbioru. Dwie lekcje z przeglądu:

- **Wyścig przy scalaniu.** Pierwsza wersja scalała pozostałość po przerwanym przebiegu z nowym zbiorem
  (`SUNIONSTORE` + `DEL`). Między tymi krokami projektor mógł dopisać sprzedawcę, którego `DEL` kasował. Poprawka:
  pozostały klucz roboczy jest przetwarzany **pierwszy, bez scalania**, a `seller360:dirty:offers` czeka nietknięty
  do następnego cyklu.
- **Limit przebiegu.** Najwyżej 500 sprzedawców na cykl (mieści się w `stop_grace_period`), przetworzeni są zdejmowani
  `SREM`, reszta zostaje w kluczu roboczym. Sprzedawca, dla którego pobranie albo zapis padły, wraca do zbioru.

Pomiar (`make churn rate=50 duration=60`): **2713 zdarzeń ofert → 154 zapisy sekcji**, ~17,6 zdarzenia na zapis,
o 94% mniej zapisów. Zbiór brudnych opróżnił się do zera ([POMIARY §17.2](../POMIARY.md)).

## Krok 7 — sprzedaż z ETAPU 12

`user.added_to_cart` dostał `seller_id`, a `user.checked_out` mapę `seller_amounts` (`seller_id` → kwota). Koperta
idzie z `version: 2`; mapper ETAPU 12 przyjmuje obie wersje i liczniki ETAPU 12 się nie zmieniają.

Mapy nie da się agregować (szablon `dynamic: strict`, pivot nie grupuje po kluczach mapy, a grupowanie po polu
wielowartościowym przypisałoby całą kwotę każdemu sprzedawcy). Mapper rozbija więc zdarzenie na wiersze nowego
data streamu `events-seller-default`, jeden na parę sesja–sprzedawca: `{@timestamp, session_id, seller_id,
kind: cart|order, amount_cents}`. Transform `seller-sales-daily` grupuje po dniu i sprzedawcy (`cardinality` sesji
osobno dla `cart` i `order`, suma kwot) i synchronizuje się po `event.ingested`, bo generator wstawia zdarzenia
z datą wsteczną do 60 dni (lekcja z ETAPU 12).

`seller360:sales:refresh` (co 300 s) liczy z niego konwersję 30 dni bieżących i 30 poprzednich oraz spadek
`(prev − cur) / prev`, tylko przy `cart_sessions_prev_30d ≥ 20`, żeby szum małych liczb nie dawał fałszywych
spadków. Pierwsza wersja transformu z `filter` + zagnieżdżonym `cardinality` padła
(`failed to parse field [cart_sessions] of type [long] … '{sessions=2.0}'`); działa wersja ze `scripted`
`cardinality`.

## Krok 8 — reklamacje: import z enrich po NIP

CSV od helpdesku: `ticket_id, nip, created_at, category, status, body`. Klucz to NIP.

```bash
make seller360-complaints-import          # docker compose cp CSV do seller360-consumer + seller360:complaints:import
```

- Bulk `index` do `complaints` (`_id = ticket_id`, więc ponowny import jest idempotentny) przez pipeline
  `complaints-resolve-seller` (`default_pipeline` szablonu).
- Pipeline: enrich z polityką `seller-by-nip` (źródło `seller-360`, dopasowanie `nip`, pola `seller_id`, `region`).
  Wiersz bez dopasowania idzie `reroute` do `complaints-unmatched`.
- Polityka enrich to **migawka**: nowy sprzedawca nie zostanie dopasowany do następnego `_execute`. Import robi
  `_execute` na początku. `--retry-unmatched` ponawia `complaints-unmatched` (po `_execute`) przez `_reindex`
  z jawnym pipeline'em i kasuje przeniesione wiersze (RUNBOOK [#141](../RUNBOOK.md#141)).
- Po imporcie `ComplaintsSectionRefresher` przelicza sekcję `complaints` dotkniętych sprzedawców: `open`, `last_30d`,
  `top_categories`, `recent_texts` (treść 50 ostatnich, analizator polski z ETAPU 7).

Wynik na żywo po replayu: `indexed=8148, unmatched=0`, 4065 sekcji `complaints`. W pierwszym, uszkodzonym przebiegu
było `unmatched=1175`: NIP-y sprzedawców, których dokumenty zginęły w przepełnionej kolejce. To dokładnie przypadek,
dla którego jest `complaints-unmatched`: wiersz czeka, zamiast zniknąć.

## Krok 9 — rejestr: circuit breaker i `stale`

Rejestr to zewnętrzne HTTP, więc musi umieć paść, nie zabierając ze sobą niczego innego.

- Osobny proces `seller360-registry` (`seller360:registry:sync --loop`), **nie** handler w `seller360-consumer`.
  Awaria API nie zatrzymuje kolejki zdarzeń. To jest dosłownie kryterium DoD.
- Klient: timeout 2 s, 3 próby z backoffem 200/800 ms.
- Bezpiecznik w Redisie (wspólny dla pętli i komend jednorazowych): 5 błędów z rzędu → 30 s przerwy bez HTTP.
- Cache odpowiedzi `seller360:registry:<nip>` na 24 h.
- Porażka z cache: sekcja z danymi z cache, `status: stale`, `error` z opisem. Porażka bez cache: same `_meta`
  ze `status: error`.
- Pętla: sprzedawcy od najstarszej synchronizacji, najwyżej 20 żądań/s (2 na sprzedawcę). Przy otwartym
  bezpieczniku nie hamuje, tylko przepisuje sekcje jako `stale` z cache.
- Wyjątek przy jednym sprzedawcy (np. ES odrzucił zapis) nie zabija pętli: ostrzeżenie z `seller_id` w logu, próba
  zapisu pustej sekcji ze `status: error` (świeże `updated_at` przesuwa go na koniec kolejności) i następny sprzedawca.
  Bez tego jeden zepsuty dokument na początku kolejki zatrzymywałby całą synchronizację.
- Zapis `stale` z cache bierze ocenę kuriera z `courier-ratings` (lokalny ES działa mimo awarii rejestru). Wersja
  zapisu to „teraz”, więc kurier z cache sprzed fan-outu (13b) cofnąłby nowszą ocenę.

Mock (`tools/registry-mock`, `php -S` z 4 workerami) ma `/_chaos`:

```bash
docker compose exec search-consumer curl -s -XPOST registry-mock:8080/_chaos -d '{"outage":true}'   # 3 s, potem 503
docker compose exec search-consumer curl -s -XPOST registry-mock:8080/_chaos -d '{"outage":false}'
```

4 workery są potrzebne, bo w trakcie awarii każde żądanie wisi 3 s, a `POST /_chaos` musi się przebić.

**Koszt, o którym warto wiedzieć:** przy otwartym bezpieczniku pętla przepisuje wszystkich sprzedawców jako `stale`
bez hamulca. W 90 s awarii było to ~12,4 tys. zapisów (~140/s), a po końcu awarii powrót wszystkich do `ok` trwa
pełne przejście przy 10 sprzedawcach/s, czyli ~30 min dla 20 tys. Karta pokazuje wtedy uczciwie „nieaktualne”.

## Krok 10 — lag: co właściwie mierzymy

Spec mówił: lag sekcji = `now − source_ts`. Po wdrożeniu okazało się, że to mierzy coś innego niż chcemy (świadoma zmiana względem specyfikacji):

- `profile` sprzedawcy, który niczego nie zmienił od tygodnia, ma `source_ts` sprzed tygodnia. `now − source_ts`
  = tydzień, choć sekcja jest idealnie aktualna. Alert „p99 > 300 s” wisiałby zawsze.
- Faktury generatora mają `source_ts` z datą wsteczną (do 90 dni), więc `billing` „spóźniałby się” o miesiące.

Dlatego **lag = `_meta.updated_at − _meta.source_ts`**: ile minęło od zdarzenia u źródła do zapisu w read modelu.
To mierzy dokładnie to, na co projektor ma wpływ. `LagReport` liczy go polem runtime w samym żądaniu (bez zmiany
mapowania), percentyle 50 i 99 oraz max per sekcja, co 30 s, i odkłada do Redisa `seller360:lag` (TTL 120 s), skąd
czyta `/metrics` (`seller360_section_lag_seconds{section,quantile}`):

```bash
docker compose exec -T seller360-scheduler php bin/console seller360:lag   # tam jest REDIS_*; search-consumer go nie ma
```

Czego `updated_at − source_ts` **nie** widzi: martwego pisarza. Gdy nic nie pisze, nie przybywa próbek i lag stoi.
Na to jest drugi wskaźnik `age_max = now − max(updated_at)`.

**Rejestr w trakcie awarii.** Zapis `stale` niesie `source_ts` ostatniego **udanego** pobrania, więc jego lag rośnie
z każdą minutą awarii. Tego w kodzie z Task 8 nie było: zapis `stale` dostawał `source_ts = teraz` i lag rejestru stał
na zerze, mimo że komentarz w `LagReport` twierdził inaczej. Wyszło dopiero w skrypcie opóźnionego źródła (krok 14).
Poprawka: cache trzyma `{fetched_at, data}`, a zapis `stale` bierze `source_ts` z `fetched_at`. Stary format cache
(same dane) dalej działa i wygasa w 24 h.

Dwie reguły w Kibanie (ES|QL, jak w ETAPIE 12), `make seller360-setup`:

| Reguła | Co sprawdza | Czego nie wykryje |
|---|---|---|
| `Seller 360: lag sekcji ponad SLO` | p99 lagu `profile`, `billing`, `offers` > 300 s, liczony **tylko** z dokumentów, których dana sekcja zapisała się w ostatnich 10 min | sekcji, która w ogóle przestała się zapisywać (nie ma próbek z 10 min); rejestru (osobny proces, własny status `stale`) |
| `seller360-sales-stale` | wiek `sales` > 900 s (trzy pominięte odświeżenia co 300 s) | sytuacji, gdy `sales` nigdy nie został zapisany albo wszystkie dokumenty są starsze niż okno 30 dni |

Bezczynne sekcje sterowane zdarzeniami nie alarmują z założenia: brak zmian u źródła nie jest błędem. Zastój pisarza
alarmuje tylko dla `sales`, bo tylko ona jest okresowa. Akcje reguł zapisują do `alerts-analytics` z ETAPU 12; jej
mapowanie jest `strict`, więc setup dokłada pola `profile_p99`, `billing_p99`, `offers_p99`, `sales_age_seconds`.
Renderu akcji przy prawdziwym odpaleniu nie oglądałem.

## Krok 11 — generator i prawda podstawowa

```bash
make seller360-simulate args="--sellers=20000 --billing --sales --complaints=storage/app/seller360/complaints.csv --plant=5"
```

- **Sprzedawcy przez domenę.** `Seller::register` → zapis → `ApproveSellerHandler`, więc zdarzenia idą prawdziwą
  drogą outboxu. ID = 5 000 000 + i i NIP = `forSeed(5 000 000 + i)`: sekwencja Postgresa nigdy się nie cofa, a bez
  stałych ID `planted.json` po `--purge` nie byłby identyczny. Użytkownicy `seller-<n>@synthetic.invalid` z losowym
  hasłem, którego nikt nie zna. Kolumna `synthetic` w `users`, `sellers`, zdarzeniu i dokumencie; panel moderacji
  domyślnie ich ukrywa.
- **Billing** przez agregat: 3 faktury w 90 dniach, 15% zwykłych sprzedawców z zaległością.
- **Reklamacje** do CSV: szablony `damage` (jedyne ze słowem na „uszkodz…”) i `other` (żadne słowo nie zaczyna się
  od „usz”). Wyłączność sprawdzona `_analyze` na analizatorze pola: szablony `damage` dają token `uszkodzony`,
  szablony `other` żadnego tokenu na `uszkodzon`. Bez tego bliskie pudło „bez reklamacji o uszkodzeniu” mogłoby
  trafić przypadkiem.
- **Sprzedaż**: zdarzenia v2 z datą wsteczną (okno poprzednie 33–57 dni temu, bieżące 3–27 dni temu), prosto na
  exchange (120 tys. wierszy outboxu byłoby wąskim gardłem). Zasadzeni: spadek 0,3–0,5 przy `cart_prev ≥ 40`.
- **Prawda podstawowa** `--plant=5`: 5 sprzedawców spełnia wszystkie cztery warunki, a 4 bliskie pudła po trzy
  z czterech (każde z innym brakującym). Zapis do `storage/app/seller360/planted.json`.
- **Determinizm**: jedno `Mt19937($seed)`, ID zdarzeń z RNG, czasy względem północy UTC. Powtórka tego samego dnia
  daje te same `_id` (409, bez duplikatów).
- **Backpressure**: przed każdą paczką generator sprawdza głębokość `search.seller360` (i outbox w drodze)
  i czeka poniżej 50 tys. (`--max-wait=900`). Bez tego kolejki się przepełniły.
- **Purge**: `seller360:simulate --purge` czyści bazę catalogu, a `seller360:purge-synthetic` po stronie search
  najpierw zbiera id syntetycznych z `seller-360` (`synthetic:true`), potem kasuje ich wiersze w `seller-sales-daily`
  i zdarzenia w `events-seller-default` (`_delete_by_query` na nazwie strumienia), a dopiero na końcu dokumenty
  w obu indeksach seller-360. Kolejność ma znaczenie: same dokumenty wróciłyby po 5 minutach, bo `sales:refresh`
  robi `scripted_upsert` dla każdego `seller_id` z wierszy sprzedaży — ~20 tys. dokumentów z samą sekcją `sales`
  i bez pola `synthetic`, których kolejny purge już by nie znalazł. Transform ciągły nie przelicza kubełków po
  usunięciu zdarzeń, więc wiersze sprzedaży nie odrastają. Purge uruchamiać po opróżnieniu kolejki projektora.

```json
{"planted": [5008159, 5011866, 5012886, 5014882, 5017067],
 "near_misses": [{"id": 5000872, "missing": "complaint"}, {"id": 5009765, "missing": "overdue"},
                 {"id": 5012970, "missing": "region"}, {"id": 5015738, "missing": "drop"}],
 "region": "mazowieckie", "phrase": "uszkodzon*"}
```

Bliskie pudło „drop” ma spadek ujemny przy `cart_prev ≥ 40`: odpada przez wartość, a nie przez brak danych. Gdyby
odpadało przez brak sekcji, test nie sprawdzałby filtra `range`.

## Krok 12 — ekran konsultanta

`/panel/sellers-360` (grupa `panel`, `can:panel`, czyli tylko `is_admin`; inni dostają 403). Catalog czyta ES tym
samym singletonem `Client` co wyszukiwarka, kontem `catalog` z rolą `catalog_app` (`read` na `seller-360*`).
`Seller360Reader` owija klienta (w testach podmieniany na fałszywy w kontenerze).

`Seller360Query::fromFilters()` buduje ciało `_search`. Każdy filtr jest opcjonalny, a brak sekcji nie wywraca
zapytania:

```php
['bool' => [
  'must'   => [['multi_match' => ['query' => $q, 'fields' => ['profile.name', 'nip', 'profile.city', 'complaints.recent_texts'], 'lenient' => true]]],
  'filter' => [
    ['term'  => ['region' => 'mazowieckie']],
    ['range' => ['sales.conversion_drop' => ['gte' => 0.2]]],     // brak sekcji sales = brak pola = nie pasuje
    ['range' => ['billing.overdue_cents' => ['gt' => 0]]],
    ['simple_query_string' => ['query' => 'uszkodzon*', 'fields' => ['complaints.recent_texts'], 'analyze_wildcard' => true, 'default_operator' => 'and']],
  ]]]
// sort: sales.gmv_30d_cents desc, missing: _last, unmapped_type: long; potem seller_id asc (stabilne strony)
```

- Warunki zawężające są w `filter` (bez liczenia trafności, cache filtrów), w `must` tylko wyszukiwarka `q`.
- `analyze_wildcard: true`: gwiazdka przechodzi przez analizator pola, więc `uszkodzon*` trafia „uszkodzony”,
  „uszkodzona”, „uszkodzone”.
- `lenient: true` w `multi_match`: `nip` to `keyword`, tekst nie może dać błędu formatu.
- `missing: _last` stawia sprzedawców bez sekcji `sales` na końcu; `unmapped_type` chroni pusty indeks.

Karta (`Seller360Card`) ma sześć niezależnych bloków. Każdy pokazuje „zapisano 3 minuty temu · źródło 5 minut temu”
z `_meta.updated_at` i `source_ts`, a przy `stale` albo `error` czerwoną odznakę z `_meta.error` w podpowiedzi.
Brak sekcji to napis „brak danych z <źródło>” (`catalog`, `oferty`, `analityka`, `billing`, `reklamacje`, `rejestr`).
Przycisk „Zapytanie z DoD” ustawia filtry z `Seller360Query::dodPreset()` i od razu szuka. Gdy ES leży (transport
albo 5xx), lista i karta mówią to komunikatem, a nie 500. Odrzucone zapytanie (4xx) ma osobny komunikat „ES odrzucił
zapytanie”: ES działa, to zapytanie jest złe, więc „niedostępny” wprowadzałby w błąd. Numer strony jest przycięty do
400 (`from + size ≤ 10 000`, domyślne `index.max_result_window`); dalsza strona dawałaby 400 z ES.

Testy: `tests/Unit/Seller360/Seller360QueryTest.php` (preset: bool z czterema filtrami i sortem po
`sales.gmv_30d_cents`) i `tests/Feature/Panel/Seller360ControllerTest.php` (karta z samym `profile` renderuje się
z „brak danych”, 403 bez `is_admin`, 404 dla nieznanego, lista i karta bez ES, 4xx jako błąd zapytania, przycięta
strona).

## Krok 13 — dowód zapytania z DoD

```bash
make seller360-verify          # = docker compose exec catalog-app php artisan seller360:query:verify
```

```
Zapytanie z DoD: {"region":"mazowieckie","min_drop":0.2,"overdue":true,"complaint_phrase":"uszkodzon*","sort":"gmv"}
Wynik: 5 dokumentów (ES total 5): 5014882, 5011866, 5012886, 5017067, 5008159
Zasadzeni: 5, trafieni: 5
Precyzja: 1.000
Czułość: 1.000
Bliskie pudła w wyniku: 0
took z 100 powtórzeń: p50 1 ms, p99 6 ms (min 1, max 11)
DoD: OK
```

Kod wyjścia 1, jeśli precyzja albo czułość < 1,0 albo trafiło jakiekolwiek bliskie pudło. Porównanie idzie na
zbiorach, nie na floatach: w PHP `3/3` to `int(1)`, a `1 === 1.0` to fałsz (pierwsza wersja komendy zwracała przez
to kod 1 przy dokładnym wyniku; złapał to test).

Dlaczego bliskie pudła są ważniejsze niż sama precyzja: precyzja 1,0 na zbiorze, w którym nikt poza zasadzonymi nie
spełnia nawet jednego warunku, nic nie dowodzi. Każde bliskie pudło spełnia trzy warunki, więc jego brak w wyniku
dowodzi, że **czwarty** filtr naprawdę działa. Samo zapytanie o frazę zwraca 8 sprzedawców: 5 zasadzonych i bliskie
pudła „region”, „drop” i „overdue”.

`took` to czas ES w stanie ustalonym (cache filtrów ciepły po pierwszym zapytaniu, 20 tys. dokumentów, jeden shard).

## Krok 14 — opóźnione źródło

```bash
make seller360-stale-source    # tools/seller360/stale-source.sh, ~2,5 min
```

```
━━ 0. Wybór syntetycznego sprzedawcy z NIP-em (poza planted.json) i świeży rejestr
  ✓ sprzedawca 5000000 (NIP 1500000009), „Hurtownia Wisła 1”, billing v3, registry ok
━━ 1. Całkowita awaria rejestru
  ✓ registry-mock: outage=true (każde żądanie 3 s, potem 503; timeout klienta 2 s) [1 s]
━━ 2. Zdarzenie billingu i zmiana profilu
  ✓ faktura 12,34 zł wystawiona (billing.invoice_issued przez outbox)
  ✓ zmiana nazwy na „Hurtownia Wisła 1 (awaria 033614)” (seller.renamed przez outbox)
━━ 3. billing i profile aktualne mimo awarii, registry stale
  ✓ profile.name = „Hurtownia Wisła 1 (awaria 033614)”, billing v4 (open_invoices 4) [4 s]
  ✓ registry: stale, error „Rejestr niedostępny (/companies/1500000009): Operation timed out after 2000 milliseconds …”, dane z cache zostały, lag 11 s [12 s]
━━ 4. Lag: registry rośnie, profile i billing nie (czekam 90 s)
  ✓ sprzedawca 5000000: lag registry 11 s → 108 s, lag profile 2 s, billing 1 s [110 s]
  ✓ registry od początku awarii: {"stale":1,"max_lag":7.0} → {"stale":15757,"max_lag":894.0} (pętla seller360-registry)
  ✓ seller360:lag: profile p99 "1466 → 1466" s, billing p99 bez wzrostu [145 s]
━━ 5. Koniec awarii: registry wraca do ok
  ✓ registry: ok, lag 0 s, error null [146 s]
WSZYSTKIE KROKI ZIELONE (awaria wyłączona, nazwa przywrócona)
```

Jak to jest zbudowane i dlaczego tak:

- **Sprzedawca spoza `planted.json`**, żeby nie ruszać dowodu zapytania. Faktura testowa (12,34 zł, termin za 14
  dni) zostaje na jego koncie; nie zmienia `overdue_cents`. Nazwę przywraca `trap`.
- **`trap` zawsze wyłącza awarię**, także po błędzie albo Ctrl-C.
- **Wybrany sprzedawca jest synchronizowany jednorazowo** (`seller360:registry:sync --seller=ID`, ten sam kod i ten
  sam bezpiecznik w Redisie co pętla). Jego pozycja w przejściu pętli jest przypadkowa (pełne przejście 20 tys. to
  ~35 min przy zdrowym rejestrze), a skrypt ma być deterministyczny. Zachowanie pętli sprawdza osobna asercja:
  zapisy `registry` od początku awarii (z 1 do 15 757 `stale` w 90 s, max lag 7 → 894 s).
- **Czekanie.** `WAIT_SECONDS=90` między odczytami (trzy cykle zadania `lag`, które liczy raport co 30 s) i jeszcze
  35 s po ostatnim zapisie, żeby raport w Redisie go zobaczył.
- **„Pozostałe nie rosną”** to p99 z `seller360:lag` dla `profile` i `billing` przed i po. Percentyle w ES to
  TDigest: przy lagu billingu ~7,7 mln s (faktury z datą wsteczną) szum to kilkanaście sekund, więc tolerancja to
  5 s albo 0,01% wartości. Na poziomie dokumentu lag `profile` i `billing` wybranego sprzedawcy jest < 60 s.

Pierwsze uruchomienie tego skryptu znalazło błąd z kroku 10 (lag `stale` stał na zerze). Drugie: `p99 billing
7691012 → 7691030` przy sztywnym progu 5 s, czyli szum TDigestu, a nie wzrost. Trzecie: globalny `max` lagu rejestru
z Redisa nie urósł, bo trzymał go stary zapis z poprzedniej awarii; stąd asercja na zapisach **od początku tej
awarii**.

## Incydent: przepełnione kolejki i replay przez shovel

Pierwszy przebieg generatora (bez backpressure) opublikował ~140 tys. zdarzeń `seller.*`/`billing.*` i ~120 tys.
zdarzeń sprzedaży w 3 minuty. Projektor je ~170 wiadomości/s. Kolejki `search.seller360` i
`search.analytics.ingest` mają politykę `max-length 100000, overflow drop-head` (`seller360-cap`,
`analytics-ingest-cap`), więc **najstarsze** wiadomości poszły do DLQ: 19 817 i 18 889. W ES było 17 809 dokumentów
zamiast ~20 061, a bliskie pudło „complaint” zgubiło okno poprzedniej sprzedaży (spadek `null`).

Naprawa w dwóch częściach:

1. **Kod:** `QueueBackpressure` w generatorze (pasywne `queue_declare`, czekanie poniżej 50 tys., limit czasu
   `--max-wait=900` z czytelnym błędem). Przebieg 20 tys. trwa teraz ~14 min zamiast 3.
2. **Dane:** bez purge (destrukcyjne) — replay DLQ dynamicznym shovelem (`tools/seller360/replay-dlq.sh`):

```bash
tools/seller360/replay-dlq.sh search.seller360.dlq search.seller360
tools/seller360/replay-dlq.sh search.analytics.ingest.dlq search.analytics.ingest
```

Shovel kopiuje z DLQ do **domyślnego exchange'a z routing key = nazwa kolejki docelowej**, więc wiadomości trafiają
tylko tam, a nie do każdej kolejki związanej z `marketplace.events` (np. `search.product.sync` nie dostała duplikatów).
`src-delete-after: queue-length` zabiera tyle wiadomości, ile było na starcie, i shovel sam się kończy. Wynik: DLQ
19 817 → 0 w 8 s, 20 061 dokumentów, reklamacje `unmatched=0`, DoD dokładne 5/5. Bezpieczne, bo zdarzenia `seller.*`
i `billing.*` niosą pełny stan i wersję (krok 3), a zdarzenia analityki mają stałe `_id` (409).

Dwie rzeczy, które warto wiedzieć: wtyczka `rabbitmq_shovel` jest włączana tylko w działającym węźle (plik
`enabled_plugins` jest montowany tylko do odczytu) i znika po restarcie RabbitMQ; replay nie ma backpressure, więc
DLQ większa niż 100 tys. przepełniłaby kolejkę docelową znowu (RUNBOOK [#143](../RUNBOOK.md#143)).

## Liczby z żywego stosu

| Co | Wartość |
|---|---|
| Dokumenty `seller-360` | 20 061 (20 000 syntetycznych + 61 prawdziwych) |
| Generator, zapis do bazy | 170,1 s (500 sprzedawców na paczkę, ~4,3 s na paczkę), całość 175,3 s bez backpressure |
| Generator z backpressure | ~14 min (tempo projektora ~170 wiadomości/s) |
| Zdarzenia sprzedaży / reklamacje | 119 604 / 8148 |
| Koalescencja ofert | 2713 zdarzeń → 154 zapisy (~17,6 : 1) |
| Zapytanie z DoD | 5 / 5, precyzja 1,0, czułość 1,0, 0 bliskich pudeł; p50 1 ms, p99 6 ms (100 powtórzeń) |
| Opóźnione źródło | profile i billing w ES po 3–4 s od zdarzenia mimo awarii; registry `stale` po ~11 s; lag 11 → 108 s w 97 s |
| Zapisy `stale` w awarii | ~12,4–15,8 tys. w 90 s (~140/s, bezpiecznik otwarty) |

Więcej: [`POMIARY.md` §17](../POMIARY.md).

## Ograniczenia

- **`conversion_drop` jest `null`, gdy bieżące okno ma 0 sesji koszyka** (konwersja bieżąca niezdefiniowana).
  Sprzedawca, który całkiem przestał sprzedawać, nie trafi do filtra „spadek ≥ 20%”. To największy spadek, a filtr go
  nie widzi; do poprawy (np. `drop = 1` przy `prev ≥ 20` i `cur = 0`).
- **Bieżące okno 30 dni obejmuje dzisiejszy, niepełny dzień**, a sesja rozpięta na dwa dni liczy się dwa razy (suma
  dziennych `cardinality`).
- **Sprzedawca, który wypadł z okna 60 dni, zachowuje starą sekcję `sales`**. Odświeżanie przelicza tylko
  sprzedawców obecnych w `seller-sales-daily`.
- **Wiersze reklamacji nigdy nie wracają do `complaints-unmatched`.** Dopasowany wiersz, którego sprzedawca potem
  zniknął, zostaje w `complaints` z nieaktualnym `seller_id`.
- **Duplikat NIP w `seller-360` → dowolny sprzedawca.** Enrich bierze pierwsze dopasowanie. Unikalność w catalogu
  chroni przed tym w normalnym ruchu, ale nie przed dokumentami-sierotami w ES.
- **Syntetyczne koszyki i checkouty zaburzają lejek ETAPU 12** (zdarzenia bez wyszukania w `events-user-default`).
  Purge kasuje zdarzenia syntetyków tylko z `events-seller-default`; czyszczenie `events-user-default` jest poza
  zakresem.
- **Prawdziwi sprzedawcy mają rzadkie karty** (brak historycznych zdarzeń `seller.*`, więc bez `profile` i `nip`).
  Uzupełni ich przebudowa z backfillem w [13b](etap-13b-skala-i-bezpieczenstwo.md#krok-7--backfill-i-weryfikacja).
- **Awaria rejestru wzmacnia zapisy**: przy otwartym bezpieczniku pętla przepisuje wszystkich jako `stale` (~140/s),
  a powrót do `ok` to pełne przejście (~30 min dla 20 tys.).
- **Wpis cache rejestru w starym formacie** (sprzed poprawki `fetched_at`) daje przy awarii `stale` z
  `source_ts = teraz`. Znika po 24 h albo po pierwszej udanej synchronizacji.
- **Karta konsultanta sprawdzona testami i `vue-tsc`, nie wzrokowo** (wymaga zalogowania kontem admina); odczyt
  na żywym ES przez `Seller360Reader` sprawdzony w tinkerze.

## Co wybuchło po drodze

| RUNBOOK | Objaw |
|---|---|
| [#140](../RUNBOOK.md#140) | martwe wiadomości projektora mieszałyby się z DLQ synchronizacji produktów (własny klucz DLQ) |
| [#141](../RUNBOOK.md#141) | reklamacje w `complaints-unmatched` po imporcie (migawka enrich, `--retry-unmatched`) |
| [#142](../RUNBOOK.md#142) | sekcja `registry` w stanie `stale`, w logach „Bezpiecznik rejestru otwarty” |
| [#143](../RUNBOOK.md#143) | 19 817 + 18 889 wiadomości w DLQ po generatorze: limit 100 tys. z `drop-head`, replay shovelem |
| [#144](../RUNBOOK.md#144) | `AnalyticsEventHandler` uruchamiany na każdym transporcie (podwójna rejestracja handlera wsadowego) |
| [#145](../RUNBOOK.md#145) | `make seller360-setup` pada bez `make analytics-setup` |

## Ściąga

```bash
# setup i procesy
make analytics-setup && make seller360-setup
docker compose up -d seller360-consumer seller360-scheduler seller360-registry registry-mock

# dane
make seller360-simulate args="--sellers=20000 --billing --sales --complaints=storage/app/seller360/complaints.csv --plant=5"
make seller360-complaints-import
docker compose exec seller360-consumer php bin/console seller360:complaints:import --retry-unmatched
make seller360-simulate args="--purge" && docker compose exec seller360-consumer php bin/console seller360:purge-synthetic

# dowody
make seller360-verify
make seller360-stale-source                       # WAIT_SECONDS=90 domyślnie

# jeden dokument i licznik (konto searchsvc z kontenera, hasła nie wychodzą na ekran)
docker compose exec -T search-consumer sh -c 'curl -s -u "$ELASTICSEARCH_USER:$ELASTICSEARCH_PASSWORD" "$ELASTICSEARCH_HOST/seller-360/_doc/5008159"' | jq '._source | {profile: .profile.name, reg: .registry._meta}'
docker compose exec -T search-consumer sh -c 'curl -s -u "$ELASTICSEARCH_USER:$ELASTICSEARCH_PASSWORD" "$ELASTICSEARCH_HOST/seller-360/_count"'

# świeżość
docker compose exec -T seller360-scheduler php bin/console seller360:lag
docker compose exec -T seller360-scheduler php bin/console seller360:offers:flush
docker compose exec -T seller360-scheduler php bin/console seller360:sales:refresh

# rejestr: jeden sprzedawca, awaria
docker compose exec seller360-registry php bin/console seller360:registry:sync --seller=5000000
docker compose exec search-consumer curl -s -XPOST registry-mock:8080/_chaos -d '{"outage":true}'
docker compose exec search-consumer curl -s -XPOST registry-mock:8080/_chaos -d '{"outage":false}'

# kolejki (nigdy rabbitmqadmin get: konsumuje)
docker compose exec -T rabbitmq rabbitmqctl -t 60 list_queues name messages | grep seller360
tools/seller360/replay-dlq.sh search.seller360.dlq search.seller360

# ekran konsultanta (admin)
open "https://catalog.localhost:${CATALOG_HTTPS_PORT:-8443}/panel/sellers-360"
```
