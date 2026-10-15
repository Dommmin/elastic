# Portal sprzedawcy: pełne DDD + porównanie z DDD-lite

Data: 2026-10-15 · Status: projekt do przeglądu, przed planem

## Cel

Nauczyć się Domain-Driven Design od strategii (poddomeny, bounded contexts,
mapa kontekstów, język) po taktykę (agregaty, value objects, zdarzenia
domenowe, repozytoria, polityki) na prawdziwej funkcji, a nie na
przykładzie z książki. Potem zobaczyć tę samą funkcję w stylu DDD-lite
i umieć powiedzieć, kiedy który styl wybrać.

Funkcja: **portal sprzedawcy**. Sprzedawcy zakładają konta, admin je
akceptuje, sprzedawcy sami prowadzą swoje oferty, admin moderuje.

Kryteria sukcesu:

1. Sprzedawca rejestruje się, czeka na akceptację, po akceptacji wystawia
   ofertę na istniejący produkt, publikuje ją, a w ciągu kilku sekund widać
   ją w `/search`.
2. Zmiana ceny o > 30% nie trafia do wyszukiwarki, dopóki admin jej nie
   zaakceptuje. Szósta zmiana ceny w ciągu doby jest odrzucona.
3. Stan 0 zdejmuje ofertę z wyszukiwarki, uzupełnienie stanu ją przywraca.
4. Zablokowanie sprzedawcy zawiesza wszystkie jego opublikowane oferty.
   Odblokowanie przywraca tylko te, które zawiesiła blokada, a nie te
   zawieszone przez moderatora.
5. Warstwa domeny nie importuje niczego z Laravela. Pilnuje tego test
   architektury, który pada w CI.
6. Testy domeny (bez bazy i frameworka) trwają łącznie poniżej sekundy.
7. Gałąź `ddd-lite-porownanie` implementuje te same reguły na bogatych
   modelach Eloquenta i przechodzi **te same** testy HTTP. Dokument
   porównawczy opiera się na zmierzonych liczbach, nie na opiniach.
8. Etap kończą commity i przewodnik krok po kroku w `docs/blog/`.

Poza zakresem (świadomie): zamówienia i rezerwacja stanu (podprojekt C,
następny), zespoły i role w koncie sprzedawcy, edycja profilu sprzedawcy
(i fan-out `seller.updated` do searcha), powiadomienia e-mail.

## 1. Strategia: poddomeny, konteksty, język

### Poddomeny

| Poddomena | Typ | Styl w kodzie |
|---|---|---|
| Oferty sprzedawców: cykl życia, cena, moderacja, konta | **Core** | pełne DDD (nowy moduł) |
| Katalog produktów: Product, Brand, Category, Review | Supporting | bez zmian, „Laravel way” |
| Wyszukiwarka (search, Symfony + ES) | Core, ale złożoność techniczna | bez zmian w kodzie |
| Tożsamość: Fortify, `users` | Generic | bez zmian, korzystamy |

**Dlaczego to ważne:** DDD nie stosuje się „do całego systemu”. Inwestycję
w model robi się tam, gdzie złożoność biznesowa jest powodem istnienia
systemu. Katalog produktów to CRUD z walidacją, więc pełne DDD byłoby tam
czystym kosztem.

### Bounded context „Seller Portal”

Nowy kontekst wewnątrz aplikacji `catalog` (modularny monolit). Dwóch
aktorów: sprzedawca i moderator (admin).

- **Moderacja nie jest osobnym kontekstem.** Decyzje moderatora zmieniają
  stan tego samego agregatu `Offer` i podlegają tym samym niezmiennikom.
  Osobny kontekst oznaczałby dwa modele jednej rzeczy do synchronizacji.
  Reguła: dzielimy konteksty, gdy rozjeżdża się język i model, a nie gdy
  dochodzi aktor.
- **Kontekst ≠ usługa.** Nowy kontekst nie dostaje osobnego deploymentu ani
  bazy (ADR-0001). Granica jest w kodzie: osobna przestrzeń nazw, zakaz
  zależności sprawdzany testem architektury, a tabele `offers` i `sellers`
  mają jednego właściciela zapisu.

### Mapa kontekstów

```
Tożsamość (users) ──[Conformist]──▶ Seller Portal
                                       │  jedyny zapisujący: offers, sellers, offer_price_changes
                                       │
                                       ├──[Published Language: outbox offer.*]──▶ RabbitMQ ──▶ Search
                                       │                                                       │
Katalog (products…) ◀──[port ProductCatalog, odczyt]──┘                                         │
Katalog ──[Open Host Service: /internal/products/{id}/projection]──[ACL: CatalogProjectionClient]┘
```

- **Conformist** wobec tożsamości: bierzemy `users.id`, jakie jest.
- **Port `ProductCatalog`**: Seller Portal pyta katalog, czy produkt
  istnieje i jak się nazywa, przez interfejs zdefiniowany *po swojej
  stronie*. Implementacja czyta tabelę `products`. Gdyby katalog stał się
  kiedyś osobną usługą, zmienia się jedna klasa.
- **Published Language**: zdarzenia `offer.*` w outboxie to kontrakt
  z searchem. Search dalej pobiera projekcję z catalogu, więc decyzja
  „widoczna w wyszukiwarce czy nie” zapada w jednym miejscu: w projekcji.
- **CQRS**: zapis wyłącznie przez agregaty Seller Portalu. Odczyt (listy,
  projekcja, panel produktu) przez istniejące modele Eloquenta i zapytania
  SQL. Model Eloquent `Offer` traci metody zapisu i zostaje modelem odczytu.

### Język wszechobecny

Kod po angielsku (jak reszta repo), rozmowa i UI po polsku. Pełny słownik
z definicjami: `docs/11-SELLER-PORTAL-SLOWNIK.md`.

| PL | Kod | Znaczenie |
|---|---|---|
| Sprzedawca | `Seller` | konto firmy sprzedającej, 1:1 z użytkownikiem |
| oczekuje / aktywny / odrzucony / zablokowany | `SellerStatus` | cykl życia konta |
| Oferta | `Offer` | propozycja sprzedaży produktu przez sprzedawcę |
| szkic / opublikowana / zawieszona / wycofana | `OfferStatus` | cykl życia oferty |
| wyprzedana | `Offer::isSoldOut()` | opublikowana ze stanem 0; stan pochodny, nie status |
| widoczna (w wyszukiwarce) | `Offer::isListed()` | opublikowana i stan > 0 |
| zmiana ceny do akceptacji | `PendingPriceChange` | nowa cena różna o > 30%, czeka na moderatora |
| zawieszenie (z powodem) | `SuspensionReason` | decyzja moderatora albo skutek blokady sprzedawcy |

## 2. Taktyka: agregaty, value objects, reguły

### Agregat `Seller`

```
Pending ──approve()──▶ Active ──block(powód)──▶ Blocked
   │                     ▲                         │
   └─reject(powód)─▶ Rejected (końcowy)            └──unblock()──┘
```

- `Seller::register(SellerId, UserId, SellerName, City)` → `Pending`, zdarzenie `SellerRegistered`.
- `approve(ModeratorId)`, `reject(ModeratorId, Reason)`, `block(ModeratorId, Reason)`,
  `unblock(ModeratorId)`. Każda metoda pilnuje dozwolonego przejścia
  (`InvalidSellerTransition`) i emituje zdarzenie (`SellerApproved`,
  `SellerRejected`, `SellerBlocked`, `SellerUnblocked`).
- **Fabryka ofert:** `$seller->draftOffer(OfferId, ProductId, Money, Stock, Condition, ShippingTime)`
  rzuca `SellerCannotSell`, gdy status ≠ `Active`. Regułę „tylko aktywny
  sprzedawca wystawia” pilnuje ten, kto zna status.
- `Seller` **nie** trzyma kolekcji ofert (patrz „Granice”).
- Sprzedawcy z seeda migrują jako `Active` z `user_id = null`. Istnieją,
  ale nikt się na nich nie loguje.

### Agregat `Offer`

```
Draft ──publish()──▶ Published ──suspend(powód)──▶ Suspended
  │                   │    ▲                           │
  │                   │    └────────reinstate()────────┘
  └──withdraw()──▶ Withdrawn (końcowy) ◀──withdraw()───┘  (z każdego stanu nie-końcowego)
```

| Operacja | Aktor | Reguły |
|---|---|---|
| `publish(Seller)` | sprzedawca | z `Draft`; stan > 0; sprzedawca `Active` |
| `changePrice(Money, now)` | sprzedawca | tylko `Draft`/`Published`; ta sama waluta; w `Published` strażnik ceny |
| `cancelPendingPriceChange()` | sprzedawca | istnieje oczekująca zmiana |
| `approvePriceChange(ModeratorId)` / `rejectPriceChange(ModeratorId, Reason)` | moderator | istnieje oczekująca zmiana |
| `changeStock(Stock)` | sprzedawca | każdy stan poza `Withdrawn`; w `Published` przejście >0→0 = `OfferSoldOut`, 0→>0 = `OfferBackInStock`, inaczej `OfferStockChanged` |
| `changeDetails(Condition, ShippingTime)` | sprzedawca | `Draft`/`Published` |
| `suspend(SuspensionReason)` | moderator / polityka | tylko z `Published` |
| `reinstate()` | moderator / polityka | z `Suspended` → `Published` |
| `withdraw()` | sprzedawca | stan nie-końcowy; czyści oczekującą zmianę ceny |
| `allowedActions(): list<OfferAction>` | UI | jedyne źródło prawdy o tym, które przyciski pokazać |

Operacje sprzedawcy dodatkowo wymagają, by sprzedawca był `Active` i był
właścicielem oferty. Sprawdza to warstwa aplikacji: wczytuje `Seller`
i porównuje `offer->sellerId()`. Wyjątki: `SellerCannotSell`, `OfferNotOwned`.

**Strażnik ceny** (`PriceChangePolicy`, obiekt domeny z nazwanymi stałymi):

1. Gdy istnieje oczekująca zmiana, kolejna jest odrzucona
   (`PriceChangeAlreadyPending`).
2. Limit **5 zmian na 24 h** w oknie kroczącym. Liczą się zmiany wprowadzone
   i zgłoszone do akceptacji, nie liczą się akceptacje moderatora.
   Przekroczenie: `PriceChangeLimitExceeded`. Agregat trzyma znaczniki czasu
   zmian z ostatniej doby. Zegar jest przekazywany (`now`), domena nie woła
   `time()`.
3. Zmiana o **> 30%** (`|nowa − stara| × 100 > stara × 30`, liczby
   całkowite, bez floatów) tworzy `PendingPriceChange`
   i `OfferPriceChangeRequested`. Do akceptacji obowiązuje stara cena.
4. Zmiana ≤ 30% wchodzi od razu: `OfferPriceChanged(old, new)`.

W `Draft` strażnik nie działa, bo oferty nikt jeszcze nie widzi.

**Powód zawieszenia ma rodzaj:** `SuspensionReason::byModerator(ModeratorId, tekst)`
albo `SuspensionReason::sellerBlocked()`. Bez tego rozróżnienia
odblokowanie sprzedawcy przywróciłoby też oferty zawieszone za łamanie
zasad.

### Value objects

`Money` (grosze > 0, `Currency`; operacje tylko w tej samej walucie,
`differsByMoreThanPercent()`), `Stock` (0–100 000), `ShippingTime`
(1–30 dni), `Condition` (new/used), `SellerName` (3–120 znaków),
`City`, `Reason` (niepusty, ≤ 500 znaków), `SuspensionReason`,
`PendingPriceChange`, identyfikatory `OfferId`, `SellerId`, `ProductId`,
`UserId`, `ModeratorId`. Niepoprawnej wartości nie da się utworzyć, więc
agregaty nie walidują pól, tylko pilnują reguł.

### Granice agregatów i spójność

- **Jedna transakcja = jeden agregat.** Blokada sprzedawcy zapisuje
  tylko `Seller`. Oferty zawiesza polityka (sekcja 3), każdą w osobnej
  transakcji. Przez kilka sekund sprzedawca jest zablokowany, a oferty
  jeszcze widoczne. To świadomy kompromis: alternatywa to transakcja
  blokująca tysiące wierszy i ładowanie wszystkich ofert do pamięci.
- **„Jedna żywa oferta sprzedawcy na produkt”** to reguła na zbiorze
  agregatów, której żaden pojedynczy agregat nie widzi. Rozwiązanie
  standardowe: sprawdzenie w warstwie aplikacji
  (`OfferRepository::hasLiveOffer(sellerId, productId)`) dla czytelnego
  komunikatu plus częściowy indeks unikalny w Postgresie
  (`WHERE status <> 'withdrawn'`) jako gwarancja przy wyścigu. Seed losuje
  różnych sprzedawców na produkt, więc obecne dane spełniają regułę.
- **Optymistyczne blokowanie** na istniejącej kolumnie `version`:
  `UPDATE … WHERE id = ? AND version = ?`. 0 wierszy oznacza
  `ConcurrencyConflict`, a UI pokazuje „ktoś zmienił ofertę, odśwież”.
  `version` dalej trafia jako `sequence` do outboxu.
- **Tożsamość przed zapisem:** `nextIdentity()` bierze `nextval()`
  z sekwencji tabeli. Agregat ma ID od urodzenia, ID zostają liczbowe
  i zgodne z searchem.

## 3. Zdarzenia: domenowe a integracyjne

To dwie różne rzeczy:

| | Zdarzenie domenowe | Zdarzenie integracyjne |
|---|---|---|
| Kto słucha | kod w tym samym kontekście | inne konteksty (search) |
| Kształt | klasa PHP, bogata (`OfferPriceChanged(old, new)`) | JSON w outboxie, kontrakt wersjonowany |
| Zmiana | swobodna, refaktor | ostrożna, łamie konsumentów |

**Przepływ:**

1. Agregat zapisuje zdarzenia w sobie (`recordThat()`), a repozytorium
   odbiera je przez `releaseEvents()` przy zapisie.
2. `OfferRepository::save()` w **jednej transakcji**: UPDATE/INSERT stanu
   (z kontrolą wersji), a następnie translacja zdarzeń domenowych na wiersze
   outboxu przez `OfferIntegrationEvents` (jawna mapa w Infrastructure,
   klasy domenowe nigdy nie są serializowane wprost).
3. Po zapisie, wciąż w tej samej transakcji, `DomainEventDispatcher`
   przekazuje zdarzenia do polityk w procesie.

**Mapa offer → outbox** (aggregate_type `offer`, routing `offer.#`, czyli
istniejące bindingi):
`offer.drafted`, `offer.published`, `offer.price_changed` (nazwa bez
zmian), `offer.price_change_requested`, `offer.price_change_approved`,
`offer.price_change_rejected`, `offer.stock_changed` (bez zmian),
`offer.sold_out`, `offer.back_in_stock`, `offer.details_changed`,
`offer.suspended`, `offer.reinstated`, `offer.withdrawn`.
Payload: dotychczasowe pola wiersza oferty (z `product_id`, z którego
korzysta `ProductSyncHandler`), do tego `status` i `listed`, plus pola
specyficzne dla zdarzenia (np. `old_price_cents`). Klucz `active` znika.
Jedyny konsument, czyli search, go nie czyta: pobiera projekcję.

**Zdarzenia sprzedawcy nie wychodzą poza kontekst.** Kolejka searcha
ma binding `seller.#`, a handler loguje dla `seller.*` ostrzeżenie „fan-out
niezaimplementowany”. Search dowiaduje się o skutkach blokady przez
`offer.suspended` każdej oferty. To lekcja: **nie każde zdarzenie domenowe
zasługuje na kontrakt publiczny.** Publikujemy to, czego ktoś potrzebuje,
bo każde opublikowane zdarzenie trzeba potem utrzymywać (ADR-0003).

**Polityki** (reakcje na zdarzenia w obrębie kontekstu):

- `SellerBlocked` → `SuspendOffersOfBlockedSeller`
- `SellerUnblocked` → `ReinstateOffersOfUnblockedSeller`

Listener nie wykonuje pracy, tylko wstawia job do kolejki `database`
**w tej samej transakcji** co zapis sprzedawcy. To ten sam pomysł co
outbox, użyty wewnątrz kontekstu: albo blokada i job, albo nic. Job
(worker `catalog-worker`):

1. Sprawdza, czy sprzedawca wciąż jest `Blocked` (odpowiednio `Active`).
   Jeśli status zdążył się zmienić, kończy bez działania, bo szybkie
   „zablokuj → odblokuj” nie może zostawić zawieszonych ofert.
2. Pobiera ID ofert do zmiany paczkami po 500 (zapytanie odczytu).
3. Dla każdej: wczytuje agregat, wywołuje `suspend(sellerBlocked())` albo
   `reinstate()` tylko dla ofert zawieszonych z rodzajem `seller_blocked`,
   zapisuje w osobnej transakcji.
4. Jest idempotentny: oferta już w docelowym stanie jest pomijana.
   Konflikt wersji ponawia ofertę (3 próby). Job jako całość ma
   `tries=5` i backoff.

## 4. Warstwy i persystencja

```
apps/catalog/src/SellerPortal/         PSR-4: SellerPortal\
  Domain/          czyste PHP: Offer/, Seller/, Shared/ (Money, identyfikatory),
                   wyjątki domenowe, interfejsy repozytoriów, PriceChangePolicy, Clock
  Application/     komendy + handlery (PublishOffer, ChangeOfferPrice, ApproveSeller…),
                   porty (ProductCatalog, TransactionManager, DomainEventDispatcher),
                   polityki, zapytania odczytu (SellerOfferList, ModerationQueue…) → DTO
  Infrastructure/  PostgresOfferRepository, PostgresSellerRepository (Query Builder,
                   bez Eloquenta), OfferIntegrationEvents, LaravelTransactionManager,
                   jobs polityk, DbProductCatalog, SystemClock
apps/catalog/app/Http/Controllers/Seller/*            UI sprzedawcy (Inertia)
apps/catalog/app/Http/Controllers/Panel/Moderation/*  UI moderatora
```

Decyzje:

- **Bez Eloquenta w repozytoriach** (ADR-0002). Eloquent to Active Record:
  model jest wierszem. Agregat z niezmiennikami ma konstruktor prywatny
  i nie da się go „zfillować”. Mapowanie ręczne (Query Builder) jest
  jawne i testowalne. Kosztuje około 100 linii na agregat i to jest cena
  pełnego DDD, którą pokaże porównanie z wersją lite.
- **Rekonstytucja:** `Offer::reconstitute(...)` odtwarza agregat z bazy
  bez emitowania zdarzeń i bez sprawdzania reguł przejść.
- **Komendy jako proste klasy readonly i handlery wywoływane z kontenera**,
  bez biblioteki command bus. Handler otwiera transakcję
  (`TransactionManager`), wczytuje agregat, woła metodę i zapisuje.
  Zasada: handler nie zawiera `if` z regułami biznesowymi.
- **Odczyt omija agregaty.** Listy dla UI to zapytania SQL zwracające DTO.
  Nie ładujemy 50 agregatów, żeby narysować tabelę.
- **Test architektury** (Pest `arch()`): `SellerPortal\Domain` nie używa
  `Illuminate\*`, `App\*` ani `SellerPortal\Application|Infrastructure`.
  `Application` nie używa `Infrastructure` ani `Illuminate\*`.

### Migracje

- `sellers`: `user_id` (nullable, unique, FK users), `status`
  (varchar + CHECK), `status_reason` (text null), `status_changed_at`.
  Istniejący wiersz dostaje `active`. `rating` zostaje z domyślnym 0.
- `offers`: `status` (varchar + CHECK, migracja danych: `active=true`
  → `published`, `false` → `withdrawn`), `suspension_kind`,
  `suspension_note`, `suspended_by`, `pending_price_cents`,
  `pending_requested_at`; usunięcie `active`; częściowy unikalny indeks
  `(seller_id, product_id) WHERE status <> 'withdrawn'`; indeks
  `(product_id, status, stock)` pod projekcję.
- `offer_price_changes (offer_id, changed_at)`: historia pod limit
  dobowy. Repozytorium ładuje tylko wiersze z ostatnich 24 h.
- Model odczytu `Offer` (Eloquent) dostaje scope `listed()`
  (`status = 'published' AND stock > 0`), który zastępuje wszystkie
  `where('active', true)`: projekcję, panel produktów i `EventTrace`.
  To jedna definicja „widoczna” po stronie odczytu, zgodna
  z `Offer::isListed()` w domenie.

**Skutek do zmierzenia:** oferty ze stanem 0, dziś widoczne w projekcji
(z `in_stock: false`), znikną z tablicy `offers` dokumentu. Sam produkt
zostaje w indeksie: projekcja (`ProductProjectionController`) dla produktu
bez ofert zwraca dokument z `offers: []`, `price_min/max: null`,
`in_stock: false`, czyli tak samo jak dziś dla produktu bez aktywnych
ofert. Kontrakt projekcji nie zmienia kształtu. Zmienia się tylko to, które
oferty do niego trafiają. Po wdrożeniu trzeba ponownie uruchomić
`search:eval` i zapisać wynik nDCG@10 w `docs/POMIARY.md` z wyjaśnieniem
ewentualnej różnicy.

### Usunięte ścieżki zapisu

`Panel/OfferController::update/deactivate` i ich trasy znikają, razem
z `Offer::updateWithOutbox/deactivateWithOutbox`. Admin nie edytuje cen
sprzedawców, tylko moderuje.

Testy, które przez to znikają albo zmieniają treść (do akceptacji razem
ze specem, bo reguły projektu zabraniają usuwania testów bez zgody):

- `tests/Feature/Panel/OfferUpdateTest.php`: 8 z 9 testów opisuje
  usuwaną edycję ceny i stanu przez admina oraz `offer.deactivated`.
  Plik zastępują testy moderacji (zawieś/przywróć) i testy HTTP
  sprzedawcy. Test „strona edycji produktu zwraca oferty posortowane po
  id” przenosi się do testów `ProductEdit`.
- `tests/Feature/OutboxEventsTest.php`: dwa testy oparte na
  `Offer::updateWithOutbox` (dwa zdarzenia naraz, monotoniczne
  `sequence`) przechodzą na `Seller` albo `Product`, które dalej używają
  traita. Test `offer.deactivated` znika, bo tego zdarzenia już nie ma,
  a jego odpowiednikiem jest `offer.withdrawn` w testach integracyjnych.

Nowy katalog bazowy `apps/catalog/src/` (reguła projektu: nowe katalogi
bazowe tylko za zgodą) jest częścią tej decyzji: kod domeny celowo nie
leży w `app/`, żeby granica „to nie jest kod frameworka” była widoczna
w strukturze. Nie dochodzą żadne nowe zależności Composera ani npm:
`arch()` jest wbudowane w Pest 5. Seeder tworzy oferty przez agregat (wolniej,
ale jedną drogą); jeśli przy 5 mln produktów okaże się to za wolne,
seeder zapisuje wiersze wprost, ale z tymi samymi wartościami statusów.
Decyzja należy do planu, po pomiarze.

## 5. Frontend (Inertia + Vue + shadcn-vue, istniejące komponenty)

**Sprzedawca** (`/seller`, osobna sekcja w sidebarze, middleware: zalogowany i ma sprzedawcę):

- `/seller/apply`: formularz zgłoszenia (nazwa, miasto) po rejestracji
  Fortify. Zalogowany użytkownik bez sprzedawcy trafia tutaj.
- Ekran statusu: „oczekuje na akceptację”, „odrzucony (powód)” oraz baner
  „konto zablokowane (powód)” nad całym portalem.
- `/seller`: pulpit z licznikami według statusu, wyprzedanymi
  i oczekującymi zmianami ceny.
- `/seller/offers`: lista z filtrami statusu (w tym „wyprzedane”).
- `/seller/offers/create`: wybór produktu (wyszukiwanie po nazwie/EAN
  w Postgresie, nie w ES, bo zapis nie może zależeć od dostępności
  wyszukiwarki), cena, stan, stan towaru, czas wysyłki → szkic.
- `/seller/offers/{id}`: szczegóły; przyciski wyłącznie z
  `allowedActions`; formularz ceny z podpowiedzią na żywo („zmiana o 42% —
  trafi do akceptacji”, „pozostały 3 zmiany na dobę”). Próg i licznik
  przychodzą z serwera, a decyzję i tak podejmuje domena. Do tego oś
  czasu zdarzeń oferty z outboxu (wzorem `EventTrace`).
- Błędy domenowe (np. `PriceChangeLimitExceeded`) mapuje jedno miejsce
  na komunikat pod polem albo toast, nie każdy kontroler osobno.

**Moderator** (`/panel`, istniejące `can:panel`):

- `/panel/sellers`: kolejka „oczekujący” (akceptuj / odrzuć z powodem)
  i lista wszystkich (zablokuj / odblokuj z powodem).
- `/panel/moderation/price-changes`: kolejka zmian ceny do akceptacji
  ze starą i nową ceną, różnicą w % i sprzedawcą.
- Strona produktu (`ProductEdit`): wiersz oferty pokazuje status
  i sprzedawcę, akcje moderatora to zawieś (z powodem) i przywróć.
  Edycja ceny i stanu przez admina znika.

## 6. Testy, gałąź DDD-lite, dokumentacja, ops

### Piramida testów

| Poziom | Gdzie | Co | Baza |
|---|---|---|---|
| Domena | `tests/Unit/SellerPortal/Domain` | każda reguła i przejście, VO | nie |
| Aplikacja | `tests/Unit/SellerPortal/Application` | handlery z repozytoriami in-memory, polityki | nie |
| Integracja | `tests/Feature/SellerPortal/Infrastructure` | mapowanie zapis/odczyt, konflikt wersji, indeks unikalny, wiersze outboxu, job polityki | Postgres |
| HTTP | `tests/Feature/SellerPortal/Http` | ścieżki z kryteriów sukcesu przez kontrolery i Inertia | Postgres |
| Architektura | `tests/Arch` | reguły zależności warstw | nie |
| E2E ręczne | przeglądarka w aplikacji | kryteria 1–4 na żywym stacku z searchem | pełny stack |

Testy HTTP są **kontraktem dla gałęzi lite**: opisują zachowanie przez
żądania i odpowiedzi, nie przez klasy domeny.

### Gałąź `ddd-lite-porownanie`

Wychodzi z `main` po zakończeniu pełnej wersji i nie jest scalana.
Zawiera te same reguły w bogatych modelach Eloquenta (`$offer->publish()`,
enumy statusów, casty `Money`, zdarzenia przez istniejący trait
`EmitsOutboxEvents`), cieńszą warstwę aplikacji, te same kontrolery,
widoki i **niezmienione testy HTTP**. Testy domeny przepisane na testy
modeli z bazą.

Dokument `docs/blog/ddd-vs-ddd-lite.md` (na `main`) porównuje zmierzone
wartości: liczbę plików i linii per warstwa, czas testów reguł, liczbę
miejsc, w których można obejść regułę (`Offer::query()->update(...)`
w lite), koszt dodania nowej reguły (ta sama zmiana w obu wersjach,
diff), oraz kiedy który styl wybrać.

### Dokumentacja (definicja „etap skończony”)

- `docs/10-DDD.md`: moduł nauki w formacie `03-SCIEZKA-NAUKI.md`
  (Cel / Teoria / W projekcie / Ćwiczenia z „zepsuj i napraw” /
  Sprawdź się), od strategii do taktyki i „kiedy NIE stosować”.
- `docs/11-SELLER-PORTAL-SLOWNIK.md`: język wszechobecny.
- `docs/adr/0001-modularny-monolit.md`, `0002-czysta-domena-bez-eloquenta.md`,
  `0003-zdarzenia-sprzedawcy-wewnetrzne.md`, `0004-wyprzedana-jako-stan-pochodny.md`.
- `docs/blog/etap-e-portal-sprzedawcy-ddd.md`: przewodnik krok po kroku.
- `docs/blog/ddd-vs-ddd-lite.md`: porównanie.
- `README.md`: status etapu. `RUNBOOK.md`: worker, job polityki,
  konflikt wersji.

### Ops

- Nowa usługa `catalog-worker` (`php artisan queue:work database`)
  w `compose.yaml`, `compose.prod.yaml` i `deploy/k8s` (Deployment obok
  catalog), z healthcheckiem w stylu `outbox-publisher`.
- `make` cele: testy domeny, testy architektury.
- Po wdrożeniu: `search:eval` i wpis w `docs/POMIARY.md`.

## Ryzyka

| Ryzyko | Skutek | Obrona |
|---|---|---|
| Usunięcie `active` zepsuje zapomniany odczyt | 500 albo błędna projekcja | grep po całym repo w planie, testy projekcji |
| Zniknięcie ofert ze stanem 0 obniży nDCG | gorsze wyniki eval | pomiar przed i po, opis w POMIARY |
| Seeder przez agregat za wolny przy 5 mln | długi seed | pomiar, fallback opisany wyżej |
| Worker nie działa na k3s | blokada nie zawiesza ofert | test w checkliście wdrożenia, wpis RUNBOOK |
| Ręczne mapowanie rozjedzie się ze schematem | utrata pól | test integracyjny zapis → odczyt → porównanie wszystkich pól |
