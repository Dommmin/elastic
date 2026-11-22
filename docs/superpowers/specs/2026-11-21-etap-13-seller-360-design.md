# ETAP 13: agregat „Seller 360”, projekt

*Data: 2026-11-21. Moduł 11a (`docs/04-ES-JAKO-PLATFORMA.md`, tor 4) i moduł 12 (bezpieczeństwo). Mapa drogowa:
`docs/06-PLAN-WDROZENIA.md`, ETAP 13.*

## 1. Po co

ETAP 13 uczy piątego archetypu ES, czyli **zmaterializowanego modelu odczytu**. Jeden dokument opisuje jednego
sprzedawcę i jest składany z pięciu niezależnych źródeł, które mają różne kanały, różne tempo i różną niezawodność.
Ma odpowiadać w kilkadziesiąt ms na zapytania, których nie da się zrobić joinem przez systemy.

**DoD z mapy drogowej:**
- działa zapytanie z `04` §5: województwo, spadek konwersji > 20%, zaległość płatnicza, „uszkodzon*” w reklamacjach,
  sortowanie po sprzedaży;
- opóźnienie jednego źródła nie psuje pozostałych sekcji dokumentu.

Do tego obowiązkowo przewodnik w `docs/blog/` (definicja „etap skończony”).

## 2. Cele, nie-cele, anty-cele

**Cele**
- Indeks `seller-360` (alias nad wersjonowanym indeksem), jeden dokument na sprzedawcę, sześć sekcji z pięciu
  źródeł.
- Każda sekcja aktualizowana częściowo i niezależnie, z własną wersją, czasem źródła i miarą świeżości (lag).
- Zapytanie z DoD zwraca **dokładnie** zaplanowanych sprzedawców (prawda podstawowa z generatora, sekcja 9).
- Fan-out: zmiana oceny kuriera aktualizuje tysiące dokumentów. Amplifikacja zapisu jest zmierzona.
- Przebudowa indeksu bez przestoju, w trakcie której zdarzenia na żywo nadal płyną i nie są gubione.
- Bezpieczeństwo w dwóch wariantach: to, co daje licencja basic (na głównym stosie), i prawdziwe FLS/DLS na
  jednorazowym węźle z trialem.
- Ekran konsultanta w panelu (pełny dokument) i sekcja „Twoje statystyki” w panelu sprzedawcy (widok okrojony).

**Nie-cele**
- Trial na głównym klastrze (decyzja D-14 zostaje: basic, trial tylko dla ML).
- Prawdziwe integracje z GUS/KRS czy kurierami. Zewnętrzne API to mock.
- Prawdziwy system ticketowy. Reklamacje przychodzą plikiem CSV.
- Fakturowanie zgodne z prawem (VAT, numeracja). Rozliczenia są uproszczone do sald i terminów.
- Wdrożenie na k3s (brak serwera). Manifesty dla nowych procesów owszem, weryfikacja tylko lokalnie.

**Anty-cele (porażka, nawet jeśli „działa”)**
- Awaria albo spowolnienie mocka API blokuje aktualizację innych sekcji albo przetwarzanie kolejki.
- Zdarzenie spóźnione albo powtórzone cofa sekcję do starszego stanu.
- Przebudowa indeksu gubi zdarzenia, które przyszły w jej trakcie.
- Panel sprzedawcy może pokazać saldo albo dane innego sprzedawcy (test negatywny jest obowiązkowy).
- Zmiany stanu magazynowego ofert (setki na sekundę przy `make churn`) zamieniają się 1:1 w zapisy do `seller-360`.

## 3. Podejścia (wybrane z użytkownikiem)

**Źródła.** Rozważone warianty:
- (A) każde brakujące źródło innym kanałem: rozliczenia jako kontekst z outboxem, reklamacje jako CSV, zewnętrzne API
  jako mock HTTP;
- (B) trzecia aplikacja z własną bazą;
- (C) wszystko z symulatora prosto na szynę.

**Wybrane A.** Każdy problem z `04` (spóźnione źródło, backfill, fan-out, świeżość) dostaje prawdziwy kanał, na którym
widać go w działaniu. B dokłada cały stos (Compose, k8s, testy) bez nowej wiedzy o ES. C nie ma prawdziwego backfillu
ani prawdziwej awarii.

**FLS/DLS.** Rozważone warianty:
- (1) główny stos na basic, prawdziwe FLS/DLS na jednorazowym węźle z trialem;
- (2) trial na głównym klastrze;
- (3) tylko basic.

**Wybrane 1.** D-14 zostaje nienaruszone, a porównanie „co daje basic, co kupuje Platinum” staje się częścią lekcji.
Wariant 2 wiązałby okno 30 dni z ETAPEM 14, a po wygaśnięciu triala role z FLS przestają działać. Wariant 3 zostawia
moduł 12 na papierze.

## 4. Źródła i sekcje dokumentu

| Sekcja | Źródło | Kanał | Klucz u źródła | Jak trafia do ES |
|---|---|---|---|---|
| `profile` | catalog: SellerPortal (nazwa, miasto, NIP, status) | zdarzenia `seller.*` przez outbox | `seller_id` | skrypt z wersją (§5.2) |
| `offers` | catalog: oferty (liczba aktywnych, zawieszonych, wyprzedanych) | zdarzenia `offer.*` jako sygnał „brudny”, przeliczenie z API wewnętrznego | `seller_id` | pełna sekcja, koalescencja (§6.1) |
| `sales` | zdarzenia ETAPU 12 (`user.added_to_cart`, `user.checked_out` v2) rozbite na `events-seller-default` | transform `seller-sales-daily`, potem zadanie okresowe | `seller_id` | pełna sekcja, `doc_as_upsert` |
| `billing` | catalog: nowy kontekst Billing | zdarzenia `billing.*` przez outbox | `seller_id` | skrypt z wersją |
| `complaints` | helpdesk: plik CSV | import wsadowy do indeksu `complaints`, potem agregacja | **NIP**, nie `seller_id` | pełna sekcja, `doc_as_upsert` |
| `registry` | mock „rejestr firm + platforma kurierska” | HTTP z cache, ponowienia, circuit breaker | NIP | pełna sekcja, `doc_as_upsert`; ocena kuriera przez fan-out (§6.2) |

Każda sekcja ma metadane `<sekcja>._meta`:
- `version` (long, monotoniczny u źródła albo czas źródła w ms dla sekcji przeliczanych);
- `source_ts` (kiedy stan powstał u źródła);
- `updated_at` (kiedy trafił do ES);
- `status`: `ok`, `stale` albo `error`, plus `error` (krótki opis), gdy ostatnia próba się nie udała.

Brak sekcji to stan poprawny: dokument powstaje z pierwszej sekcji, która przyjdzie, a reszta dochodzi.

**Pola na poziomie dokumentu:** `seller_id` (`_id`), `nip`, `region` (województwo), `synthetic` (bool),
`doc_updated_at`.

**Region.** Region liczy projektor ze statycznego pliku referencyjnego `infra/reference/miasta-wojewodztwa.csv`
(nieznane miasto daje `null` i licznik w metrykach). Enrich processor nie wchodzi w grę, bo `_update` nie przechodzi
przez ingest pipeline. To jest jedna z lekcji etapu i trafia do przewodnika.

### Rozstrzygnięcia w modelu źródeł (moja decyzja, zgodnie z [[ddd-decyzje-jak-staff-engineer]])

- **NIP w SellerPortal.** `TaxId` jako value object z walidacją sumy kontrolnej, wymagany przy zgłoszeniu sprzedawcy.
  Istniejący sprzedawcy dostają NIP migracją z deterministycznego generatora poprawnych NIP-ów. NIP jest kluczem,
  którym posługują się systemy zewnętrzne (helpdesk, rejestr), więc agregat musi umieć po nim rozwiązać tożsamość.
- **Zdarzenia sprzedawcy wychodzą z kontekstu.** Dziś `SqlSellerRepository` celowo ich nie publikuje. Teraz
  publikuje je przez outbox jako zdarzenia integracyjne (nie domenowe 1:1): `seller.registered`, `seller.approved`,
  `seller.rejected`, `seller.blocked`, `seller.unblocked`, `seller.renamed`, `seller.relocated`. Ładunek to pełny
  stan profilu plus `version` agregatu.
- **Zmiana nazwy i miasta w domenie.** Dziś nikt w produkcji nie emituje `seller.renamed` ani `seller.relocated`
  (występują tylko w teście ogólnego traitu `updateWithOutbox`). `SellerRenameCommand` z ETAPU 10 robi zwykły
  UPDATE bez outboxu i bez podbicia wersji. Decyzja:
  - `Seller::rename()` i `Seller::relocate()` stają się metodami agregatu ze zdarzeniami domenowymi i podbiciem
    `version`;
  - `SellerRenameCommand` przechodzi na ścieżkę domenową, a dotychczasowe `search:seller:rename` (UBQ na indeksie
    produktów) zostaje bez zmian;
  - przewodnik ETAPU 10 dostaje dopisek.

  Bez tego `profile` i `region` nigdy by się nie zmieniły, a zmiana bez nowej wersji przegrywałaby ze strażnikiem.
- **`ProductSyncHandler` przestaje ostrzegać o zdarzeniach cyklu życia sprzedawcy.** Jest związany z `seller.#`,
  więc dostaje też zdarzenia, których nie obsługuje. Dostaje jawną listę typów, które ignoruje na poziomie `debug`.
  Nieznany typ dalej daje ostrzeżenie.
- **Billing jako nowy kontekst** `apps/catalog/src/Billing` w układzie Domain, Application i Infrastructure, jak
  Ordering:
  - agregat `BillingAccount` (per sprzedawca) z fakturami `Invoice` (kwota, termin, status);
  - zdarzenia `billing.invoice_issued`, `billing.payment_received`, `billing.invoice_overdue`;
  - ładunek zawiera pełny stan sekcji: `balance_cents`, `overdue_cents`, `overdue_since`, `open_invoices`, `version`;
  - `invoice_overdue` emituje zadanie harmonogramu `billing:mark-overdue`;
  - faktury wystawia generator (§9), a nie prowizje od zamówień. Zamówienia z ETAPU F istnieją (checkout tworzy po
    jednym na sprzedawcę), ale jest ich lokalnie garstka, a etap dotyczy strony ES. Prowizje z prawdziwych zamówień
    to zadanie domowe w przewodniku.
- **Sprzedaż per sprzedawca.** `user.added_to_cart` dostaje `seller_id`, a `user.checked_out` dostaje
  `seller_amounts` (mapę `seller_id` → kwota). Koperta idzie w `version: 2`, mapper ETAPU 12 przyjmuje obie wersje.
  - Mapa nie nadaje się do agregacji: szablon jest `dynamic: strict`, transform nie grupuje po kluczach mapy,
    a grupowanie po polu wielowartościowym liczyłoby całą kwotę checkoutu każdemu sprzedawcy.
  - Dlatego mapper rozbija zdarzenie na wiersze w **nowym data streamie `events-seller-default`**, jeden wiersz na
    parę sesja–sprzedawca: `{@timestamp, session_id, seller_id, kind: cart|order, amount_cents}`.
  - Ten sam ILM i ten sam pipeline `events-ingested` co w ETAPIE 12. Rola `search_service` obejmuje już `events-*`.
  - `events-user-default` dostaje tylko nowe pole `seller_id` w mapowaniu i nic poza tym, więc liczniki ETAPU 12 się
    nie zmieniają.
  - Transform `seller-sales-daily` grupuje po `seller_id` i dniu (`cardinality` sesji osobno dla `cart` i `order`,
    suma kwot) i synchronizuje się po `event.ingested`, bo generator wstawia zdarzenia z datą wsteczną do 60 dni.

  **Konwersja sprzedawcy** to suma dziennych unikalnych sesji z zamówieniem u niego, podzielona przez sumę dziennych
  unikalnych sesji z dodaniem jego oferty do koszyka. Sesja rozpięta na dwa dni liczy się dwa razy, i to jest
  świadome uproszczenie opisane w przewodniku. Konwersja jest liczona za ostatnie 30 dni i za poprzednie 30 dni.
  Spadek to `(prev − cur) / prev`, liczony tylko przy `prev_sessions ≥ 20`, żeby szum małych liczb nie dawał
  fałszywych spadków.

## 5. Projektor w search-service

### 5.1 Procesy

Nowy proces `seller360-consumer` (Symfony Messenger, transport `seller360`). Kolejka quorum `search.seller360`
z limitem dostarczeń jak w ETAPIE 12, związana z `marketplace.events` kluczami `seller.#`, `billing.#`
i `offer.#`. Polityka limitu długości jak `analytics-ingest-cap`.

**DLQ:** `marketplace.dlx` kieruje martwe wiadomości po ich pierwotnym kluczu, a `seller.#` i `offer.#` są już
związane z `search.product.sync.dlq`. Dlatego kolejka ustawia własny `x-dead-letter-routing-key: search.seller360`,
a `search.seller360.dlq` jest związana tylko z tym kluczem. Martwe wiadomości nie mieszają się z DLQ synchronizacji
produktów. Wpis w RUNBOOK opisuje różnicę przy replayu.

Komendy:
- `seller360:sales:refresh` (co 5 minut): przelicza sekcję `sales` z transformu;
- `seller360:offers:flush` (co 30 sekund): przelicza sekcje brudnych sprzedawców;
- `seller360:registry:sync` (ciągły z limitem tempa, albo `--seller=` dla jednego sprzedawcy);
- `seller360:courier:poll` (co minutę): fan-out ocen kuriera;
- `seller360:complaints:import <plik.csv>`;
- `seller360:rebuild`;
- `seller360:lag` (wypisuje lag per sekcja, to samo, co trafia do metryk).

Harmonogram: Symfony Scheduler w procesie `seller360-scheduler`, albo cron w kontenerze, jeśli Scheduler nie jest już
w projekcie (budujący sprawdza i wybiera prostsze).

### 5.2 Zapis sekcji z wersją

Sekcje zasilane zdarzeniami (`profile`, `billing`) idą przez `_update` ze `scripted_upsert: true` i skryptem
zapisanym w klastrze (`seller360-apply-section`, parametry: nazwa sekcji, dane, metadane):
- jeśli `version` w dokumencie ≥ przychodzącej, skrypt ustawia `ctx.op = 'noop'`;
- w przeciwnym razie zastępuje całą sekcję i ustawia `doc_updated_at`.

Zdarzenia z ponowień i spóźnione dają `noop`, liczone w metryce `seller360_section_writes_total{result="stale"}`.

Sekcje przeliczane w całości (`offers`, `sales`, `complaints`, `registry`) idą przez `doc_as_upsert` z tym samym
strażnikiem. Wersja to `source_ts` w ms, więc stare przeliczenie nie nadpisze nowego. Przewodnik pokazuje oba
mechanizmy i to, kiedy zwykłe `doc_as_upsert` wystarcza (sekcja-migawka, ostatni zapis wygrywa) a kiedy nie (zdarzenia
mogą przyjść poza kolejnością).

Konflikty wersji dokumentu (`retry_on_conflict: 3`) są normalne, bo dwie sekcje mogą pisać naraz.

### 5.3 Rejestr: awaria nie blokuje reszty

- Wywołania mocka idą z timeoutem 2 s, z ponowieniem z backoffem i z circuit breakerem: po 5 błędach z rzędu
  przerwa 30 s.
- Odpowiedzi są cache'owane w Redisie z TTL 24 h. Przy awarii sekcja zostaje ze starymi danymi, a w `_meta` dostaje
  `status: stale` i `error`.
- Synchronizacja rejestru to osobny proces, nie handler w `seller360-consumer`, więc awaria API nie zatrzymuje kolejki
  zdarzeń. To jest dokładnie kryterium „opóźnienie jednego źródła nie psuje pozostałych sekcji”.

### 5.4 Mock zewnętrznego API

Kontener `registry-mock`: mały skrypt PHP (`tools/registry-mock/`, `php -S` na obrazie search, bez nowych zależności).

| Endpoint | Co zwraca |
|---|---|
| `GET /companies/{nip}` | nazwa rejestrowa, status VAT (`active`/`inactive`), data rejestracji; deterministycznie z NIP |
| `GET /couriers/assignments/{nip}` | `courier_id` (jeden z 8 kurierów) |
| `GET /couriers/ratings?since=` | lista `{courier_id, on_time_rate, rated_at}` zmienionych od `since` |
| `POST /_chaos` | ustawia opóźnienie, odsetek błędów 5xx, całkowitą awarię, zmianę oceny kuriera |

## 6. Amplifikacja zapisu

### 6.1 Oferty: koalescencja zamiast 1:1

Zdarzenie `offer.*` dopisuje tylko `seller_id` do zbioru Redis `seller360:dirty:offers`. Co 30 s `offers:flush` zdejmuje
zbiór i dla każdego sprzedawcy woła nowy endpoint wewnętrzny catalogu `GET /api/internal/sellers/{id}/offer-stats`
(z tokenem z ETAPU 10b), po czym zapisuje sekcję.

Metryki `seller360_offer_events_total` i `seller360_offer_section_writes_total` pokazują współczynnik koalescencji
przy `make churn`. Liczby trafiają do POMIARÓW.

### 6.2 Kurier: fan-out przez `_update_by_query`

Ocena kuriera zmienia się u źródła raz, ale jest zdenormalizowana na każdym jego sprzedawcy. `courier:poll` dla
każdej zmienionej oceny robi `_update_by_query` na `seller-360`:
- zapytanie: `registry.courier.id = X`;
- skrypt z strażnikiem `rated_at`;
- `slices=auto`, `conflicts=proceed`, `wait_for_completion=false`, a potem śledzenie zadania.

Kandydaci przegrywający konflikt z równoległym zapisem sekcji są ponawiani w kolejnym przebiegu, bo strażnik
`rated_at` zrobi to bezpiecznie.

Pomiar: czas i liczba zaktualizowanych dokumentów na 20 tys. sprzedawców, wpływ na p99 zapytań z ekranu konsultanta
w trakcie fan-outu.

Przewodnik porównuje dwa podejścia:
- denormalizację z fan-outem (szybki odczyt, drogi zapis);
- trzymanie ocen w osobnym indeksie i dociąganie ich w aplikacji (tani zapis, ale zapytanie „sprzedawcy z kurierem
  poniżej 90% terminowości” znów wymaga joina).

## 7. Reklamacje: import i rozwiązywanie tożsamości przez enrich

- CSV od helpdesku: `ticket_id, nip, created_at, category, status, body`. Klucz to NIP, a nie nasz `seller_id`.
- Import robi bulk `index` do indeksu `complaints` (`_id = ticket_id`, więc ponowny import jest idempotentny)
  przez pipeline `complaints-resolve-seller`. Pipeline używa enrich processora z polityką `seller-by-nip` (źródło:
  `seller-360`, dopasowanie `nip`, pola `seller_id`, `region`). Tu enrich ma sens, bo zapis idzie przez ingest.
- Wiersze bez dopasowania (NIP nieznany albo polityka jeszcze nie odświeżona) trafiają przez `on_failure`/`reroute`
  do `complaints-unmatched`. Komenda `--retry-unmatched` ponawia je po `_execute` polityki.
- Polityka enrich jest migawką, więc nowy sprzedawca nie zostanie dopasowany do następnego `_execute`. Import robi
  `_execute` na początku i to jest opisane jako świadomy koszt.
- Po imporcie sekcja `complaints` każdego dotkniętego sprzedawcy jest przeliczana z indeksu `complaints`:
  - `open`;
  - `last_30d`;
  - `top_categories`;
  - `recent_texts`: treść 50 ostatnich reklamacji, pole `text` z analizatorem polskim z ETAPU 7.

  Zapytanie z DoD szuka po `complaints.recent_texts` w jednym indeksie.
- Analizator: `uszkodzon*` musi trafić „uszkodzony”, „uszkodzona”, „uszkodzone”. Ma to sprawdzić test zapytania,
  a nie deklaracja.

## 8. Przebudowa bez przestoju

`seller360:rebuild` robi kolejno:
1. tworzy `seller-360-vN+1` z szablonu;
2. włącza **podwójny zapis**: projektor pisze do aliasu zapisu, który na czas przebudowy wskazuje oba indeksy
   (zapis przez dwa jawne cele, bo alias z dwoma indeksami nie przyjmuje zapisu bez `is_write_index`);
3. wypełnia nowy indeks ze źródeł w kolejności od najtańszej:
   - profil i NIP z nowego endpointu wewnętrznego `GET /api/internal/sellers?after=` (stronicowanie kursorem);
   - billing z `GET /api/internal/billing/accounts?after=`;
   - oferty, sprzedaż i reklamacje z przeliczeń;
   - rejestr z cache w Redisie, bez wołania API;
4. porównuje liczbę dokumentów i próbkę sekcji;
5. przełącza alias odczytu, wyłącza podwójny zapis i usuwa stary indeks po potwierdzeniu.

Backfill pisze tym samym strażnikiem wersji, więc zdarzenie na żywo nowsze niż migawka wygrywa niezależnie od
kolejności.

Test: przebudowa w trakcie `make churn` i generatora billingu, a na końcu porównanie z prawdą podstawową. Zero
rozjazdów.

## 9. Generator i dowód

`php artisan seller360:simulate` w catalogu (wzorem `analytics:simulate`), z ziarnem:
- `--sellers=20000` tworzy syntetycznych sprzedawców przez domenę SellerPortal (zdarzenia idą prawdziwą drogą
  outboxu). Sprzedawcy mają rozkład miast zgodny z plikiem referencyjnym. Szczegóły:
  - `Seller::register` wymaga `UserId`, a `sellers.user_id` jest unikalne, więc generator tworzy też użytkowników
    (`seller-<n>@synthetic.invalid`, losowe hasło, którego nikt nie zna);
  - obie tabele dostają kolumnę `synthetic` (migracja, domyślnie `false`), która trafia też do zdarzeń i dokumentu;
  - syntetyczni sprzedawcy nie mają ofert, więc nie pojawiają się w sklepie ani w projekcji produktów;
  - listy w panelu moderacji domyślnie ich ukrywają (przełącznik „pokaż syntetycznych”);
  - `seller360:simulate --purge` usuwa syntetycznych użytkowników i sprzedawców, a ich dokumenty w `seller-360`
    i `seller-360-public` kasuje `_delete_by_query` po `synthetic: true`;
- `--billing` wystawia faktury i płatności, część po terminie;
- `--complaints=<plik>` zapisuje CSV z reklamacjami po polsku (słownik szablonów, m.in. „uszkodzony”, „uszkodzona
  paczka”, „nie dotarło”);
- `--plant=N` wybiera N sprzedawców i ustawia im wszystkie cztery warunki z DoD. Dodatkowo kilka „bliskich
  pudeł”: trzy z czterech warunków. Zbiór zasadzonych ID zapisuje do `storage/app/seller360/planted.json`.

**Sprzedaż.** Konwersja dla zasadzonych wymaga zdarzeń sprzedaży. Generator emituje `user.added_to_cart`
i `user.checked_out` (v2) z historycznymi znacznikami czasu na dwa okna po 30 dni, z celowym spadkiem u zasadzonych.
Dotyczy prawdziwych 61 sprzedawców i podzbioru syntetycznych. Pozostali syntetyczni nie mają sekcji `sales`, i to też
jest poprawny przypadek.

**Dowód.** Komenda `seller360:query:verify` uruchamia zapytanie z DoD i porównuje wynik z `planted.json`:
- wymagana precyzja i czułość równe 1,0;
- bliskie pudła nie mogą trafić do wyniku;
- czas zapytania (p50/p99 ze 100 powtórzeń) trafia do POMIARÓW.

**Opóźnione źródło.** Skrypt `tools/seller360/stale-source.sh`:
1. włącza całkowitą awarię mocka;
2. generuje zdarzenia billingu i zmianę profilu;
3. sprawdza, że `billing` i `profile` się zaktualizowały, a `registry` ma `status: stale`;
4. sprawdza, że lag rejestru rośnie, a pozostałych sekcji nie;
5. wyłącza awarię i sprawdza powrót `registry` do `ok`.

## 10. Świeżość

- Lag sekcji to `now − source_ts`, liczony przez `seller360:lag` agregacją po `<sekcja>._meta.source_ts`: percentyle
  i najstarszy rekord.
- Wynik idzie do Prometheusa z ETAPU 11 przez istniejący `MetricsController` jako
  `seller360_section_lag_seconds{section, quantile}`. W Kibanie dochodzi panel na dashboardzie.
- SLO z `05-SPOJNOSC-DANYCH.md`: p99 < 5 min dla sekcji strumieniowych.
- `sales` (co 5 min) i `complaints` (wsadowo) mają własne, jawnie opisane progi.
- Reguła alertu (Kibana, ES|QL, jak w ETAPIE 12): lag p99 sekcji ponad próg przez 10 min.

## 11. Bezpieczeństwo

### 11.1 Główny stos (basic)

- **Widok konsultanta.** Panel (`is_admin`) czyta alias `seller-360` przez użytkownika ES `catalog` z rozszerzoną
  rolą `catalog_app` (read na `seller-360`).
- **Widok sprzedawcy.**
  - Projektor pisze drugi, okrojony dokument do `seller-360-public`, z listą dozwolonych pól: `profile`, `offers`,
    `sales`, a z `registry` tylko ocenę kuriera.
  - Bez `billing` i `complaints.recent_texts`.
  - Nowy użytkownik ES `catalog_seller_view` z rolą read **tylko** na `seller-360-public`.
- **Zawężenie do własnego dokumentu (DLS na basic).** Odczyt po `_id` własnego sprzedawcy w jednej klasie
  `SellerStatsReader`, bez wyszukiwania. Test architektury pilnuje, że nic innego nie używa klienta
  `catalog_seller_view`.
- Przewodnik mówi wprost, czym to się różni od DLS: ochrona jest w aplikacji, nie w ES. Błąd w catalogu ujawnia
  dokumenty innych sprzedawców, choć nadal bez sekcji `billing`.
- **Testy negatywne.** Klient `catalog_seller_view` dostaje 403 na `seller-360`. Strona sprzedawcy nie zawiera pól
  billingu (asercja na props Inertii).

### 11.2 Węzeł z trialem: prawdziwe FLS/DLS

`make seller360-fls-demo` uruchamia `tools/seller360/fls-demo.sh`, który:
1. stawia jednowęzłowy ES 9.5.1 (`-Xmx768m`, osobna sieć, losowy port, bez wolumenu) i włącza trial;
2. ładuje szablon `seller-360` i próbkę 200 dokumentów zrzuconych z głównego klastra (`_search` ze sortowaniem po
   `_id`, bez danych spoza indeksu);
3. tworzy role:
   - `seller_self` z DLS jako szablonem `{"term": {"seller_id": "{{_user.metadata.seller_id}}"}}` i FLS
     `except: ["billing.*", "complaints.recent_texts"]`;
   - `consultant` z pełnym odczytem;
4. tworzy dwóch użytkowników `seller_self` (różne `seller_id`) i jednego konsultanta;
5. wykonuje asercje:
   - sprzedawca widzi jeden dokument;
   - sprzedawca nie widzi billingu ani w `_source`, ani w agregacji;
   - zapytanie po `billing.overdue_cents` nic mu nie zwraca;
   - konsultant widzi wszystko;
6. sprząta kontener (`trap`), także po błędzie.

Hasła demo są generowane losowo w skrypcie i nigdzie nie zapisywane. Repo jest publiczne.

Przewodnik zestawia oba warianty w tabeli: co gwarantuje ES, a co aplikacja.

## 12. UI

- **Panel: `Panel/Sellers360`.**
  - Wyszukiwarka sprzedawców po nazwie, NIP, mieście i treści reklamacji.
  - Filtry: województwo, status VAT, zaległość, spadek konwersji, kurier.
  - Sortowanie po sprzedaży.
  - Karta sprzedawcy z sekcjami i znacznikiem świeżości przy każdej sekcji („3 min temu”, „nieaktualne: rejestr
    niedostępny”).
  - Gotowy przycisk „zapytanie z DoD”, który ustawia filtry.
- **Panel sprzedawcy.** Na istniejącym `seller/Dashboard` dochodzi blok „Twoje statystyki” z `seller-360-public`
  (Inertia deferred props, bo ES nie może blokować strony). Gdy ES leży, blok pokazuje komunikat, a strona działa.

## 13. Podział na części

**13a: agregat i źródła**
- NIP w SellerPortal;
- zdarzenia `seller.*` w outboxie;
- kontekst Billing;
- `user.*` v2 i data stream `events-seller-default`;
- rename i relocate w domenie SellerPortal;
- mock rejestru;
- projektor z wersjonowanymi sekcjami;
- koalescencja ofert;
- import reklamacji z enrich;
- świeżość i alert;
- generator;
- ekran konsultanta;
- dowód zapytania i skrypt opóźnionego źródła.

Przewodnik `docs/blog/etap-13a-agregat-seller-360.md`.

**13b: skala i bezpieczeństwo**
- fan-out kuriera z pomiarem;
- przebudowa bez przestoju pod obciążeniem;
- widok sprzedawcy na basic;
- demo FLS/DLS na trialu;
- manifesty k8s dla `seller360-consumer`, `seller360-scheduler` i `registry-mock`.

Przewodnik `docs/blog/etap-13b-skala-i-bezpieczenstwo.md`.

Obie części: POMIARY §17, wpisy w RUNBOOK, aktualizacja `06-PLAN-WDROZENIA.md`.

## 14. Co zostaje budującemu

- Dokładne mapowanie `seller-360` (typy pól sekcji, `dynamic: strict` z wyjątkiem `_meta`), byle sekcja 7 i zapytanie
  z DoD działały na analizatorze polskim.
- Scheduler czy cron (§5.1).
- Treść słownika reklamacji i rozkład miast.
- Wygląd ekranu konsultanta w obrębie istniejących komponentów panelu (shadcn/Tailwind jak reszta).
- Nazwy metryk poza tymi wymienionymi.

## 15. Ryzyka

- **Pamięć.** Maszynę dzielą inne projekty. 20 tys. sprzedawców to mały indeks, ale węzeł trial dokłada chwilowo
  około 1,5 GB. Demo nie startuje, jeśli `docker info` pokazuje za mało wolnej pamięci, i mówi to wprost.
- **Migracja NIP** dotyka istniejących sprzedawców i testów SellerPortal. Generator NIP-ów jest deterministyczny,
  żeby fixture'y były stabilne.
- **`user.*` v2** zmienia kontrakt ETAPU 12. Mapper musi przyjąć v1 i v2, a testy ETAPU 12 mają przejść bez zmian.
- **RabbitMQ.** W kontenerze wiszą procesy healthchecku (zauważone 2026-11-20). Restart kontenera przed długimi
  pomiarami, opisany w RUNBOOK.
