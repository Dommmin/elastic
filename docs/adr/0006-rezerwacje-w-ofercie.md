# ADR-0006: Rezerwacje stanu żyją w agregacie `Offer`, „dostępne” to stan pochodny

- **Status:** przyjęta (aktualizuje [ADR-0004](0004-wyprzedana-jako-stan-pochodny.md))
- **Data:** 2026-10-16
- **Kontekst decyzji:** etap „Zamówienia” ([spec, sekcje 1 i 3](../superpowers/specs/2026-10-16-zamowienia-ddd-design.md), [moduły 11 i 12 w `10-DDD.md`](../10-DDD.md), [słownik zamówień](../12-ZAMOWIENIA-SLOWNIK.md))

## Kontekst

Zamówienie musi trzymać towar od złożenia do płatności (do 15 minut plus
karencja), a dopiero płatność zamienia go w sprzedaż. Event Storming dał
trzy hot spoty, które dotyczą tego samego miejsca (spec, sekcja 1, krok 5):

- **H1** — kiedy zmniejszamy `stock`? Przy płatności to overselling (dwóch
  kupujących płaci za ostatnią sztukę), w koszyku porzucone koszyki trzymałyby
  towar w nieskończoność. Zostaje: przy złożeniu **rezerwujemy**, przy
  płatności **sprzedajemy**.
- **H2** — czy rezerwacja zmniejsza `stock` od razu? Sprzedawca deklaruje
  „mam 10 na półce”. Gdyby rezerwacja odjęła 3 od `stock`, sprzedawca, który
  po inwentaryzacji wpisze „10”, nadpisze trzy sztuki obiecane komuś, kto
  właśnie płaci.
- **H3** — czy sprzedawca może zejść ze stanem poniżej zarezerwowanego? Nie.

Fakty:
- Niezmiennik „nie zarezerwujesz więcej, niż jest dostępne” dotyczy **stanu
  i wszystkich aktywnych rezerwacji naraz**. Kto go pilnuje, musi widzieć
  jedno i drugie w jednej transakcji.
- `Offer` ma już optymistyczną blokadę (`version`) i zdarzenia integracyjne
  `offer.*`, z których wyszukiwarka liczy widoczność (ADR-0004).
- Ordering nie powinien znać modelu oferty, a portal nie powinien znać
  zamówień (kierunek zależności pilnuje `tests/Arch/OrderingLayersTest.php`).

## Decyzja

**Rezerwacje są częścią agregatu `Offer`.** Oferta trzyma listę aktywnych
`Reservation(key, quantity, reservedAt)`. `stock` zostaje deklaracją
sprzedawcy, a dwie wartości są pochodne:

- `reserved()` = suma ilości aktywnych rezerwacji,
- `available()` = `stock − reserved()`.

`isListed()` i `isSoldOut()` z ADR-0004 liczą się teraz **od dostępności**,
nie od stanu: widoczna = opublikowana i `available > 0`, wyprzedana =
opublikowana i `available = 0`. Po stronie odczytu scope `listed()` na modelu
Eloquenta odejmuje aktywne rezerwacje podzapytaniem
(`apps/catalog/app/Models/Offer.php:25`, `RESERVED_SQL`).

Reguły (`apps/catalog/src/SellerPortal/Domain/Offer/Offer.php`):

| Metoda | Reguła |
|---|---|
| `reserve(Seller, ReservationKey, Quantity, Money, now)` (`:215`) | tylko `Published`; sprzedawca `canSell()`; cena równa obecnej; ilość ≤ dostępne; ten sam klucz i ilość → no-op, inna ilość → `ReservationConflict` |
| `releaseReservation(key, now)` (`:253`) | w każdym statusie; brak aktywnej rezerwacji → no-op |
| `confirmReservation(key, now)` (`:276`) | w każdym statusie; `stock −= quantity`, dostępne bez zmian; brak aktywnej → `ReservationNotFound` |
| `changeStock(Stock, now)` (`:169`) | nowy niezmiennik `stock ≥ reserved()` (`StockBelowReserved`, `:182`); `OfferSoldOut`/`OfferBackInStock` od przejścia **dostępności** przez zero |
| `withdraw`, `suspend` | **nie** zwalniają rezerwacji (H4): ktoś mógł już zapłacić |

Klucz rezerwacji (`ReservationKey`, 1–64 znaki) jest dla portalu
nieprzezroczysty. Ordering podpisuje się `order-{id}`
(`apps/catalog/src/Ordering/Application/Process/OrderReservationKey.php:17`),
portal nie wie, że to zamówienie.

**Idempotencja wymaga pamięci.** Agregat zna tylko aktywne rezerwacje, a
ponowione kroki sagi przychodzą z kluczami już rozliczonymi. Dlatego
tabela `offer_reservations` trzyma także wiersze `confirmed` i `released`
(unikalne `(offer_id, reservation_key)`), repozytorium wczytuje do agregatu
tylko `active`
(`apps/catalog/src/SellerPortal/Infrastructure/Persistence/SqlOfferRepository.php:94`),
a o status rozliczonego klucza pytają handlery
(`SqlOfferRepository::reservationStatus`, `:153`):

- `ConfirmReservationHandler`: klucz już `confirmed` → no-op (ponowiony job),
  `released` albo brak → `ReservationNotFound` (job ląduje w `failed_jobs`),
- `ReserveStockHandler`: klucz już `confirmed` albo `released` →
  `ReservationConflict` (rozstrzygnięcie R12 z implementacji; inaczej
  agregat próbowałby wstawić drugi wiersz i trafił na indeks unikalny
  surowym `QueryException`).

**Published Language.** Trzy nowe zdarzenia integracyjne:
`offer.stock_reserved`, `offer.reservation_released`,
`offer.reservation_confirmed`, a payload bazowy oferty dostaje `available`
(`apps/catalog/src/SellerPortal/Infrastructure/Persistence/OfferIntegrationEvents.php:82`,
`:123`–`:125`). Klucz rezerwacji **nie** trafia do payloadu: to szczegół
Orderingu. Projekcja produktu podaje wyszukiwarce `offers[].stock` jako
**dostępne**, a `in_stock` liczy z dostępności
(`apps/catalog/app/Http/Controllers/Internal/ProductProjectionController.php:69`, `:80`).

## Rozważane opcje

| Opcja | Za | Przeciw |
|---|---|---|
| **A. Rezerwacje w ofercie (wybrana)** | niezmiennik ma jednego właściciela; wyścig „rezerwacja kontra zmiana stanu” (R5) i „dwóch kupujących, ostatnia sztuka” (R6) rozstrzyga wersja oferty, bez nowego mechanizmu; `stock` dalej znaczy to, co mówi sprzedawca | gorąca oferta = seria konfliktów wersji (rezerwacje jednej oferty idą po kolei); agregat wczytuje wszystkie aktywne rezerwacje |
| B. Osobny agregat `Reservation` (wiersz na rezerwację) | brak rywalizacji o wiersz oferty | niezmiennik „Σ rezerwacji ≤ stan” nie ma właściciela: dwie rezerwacje naraz przejdą sprawdzenie, a zmiana stanu przez sprzedawcę nie widzi rezerwacji; trzeba by blokady na zbiorze (moduł 9) |
| C. Rezerwacja zmniejsza `stock` od razu | jedna kolumna, zero nowej tabeli | H2: sprzedawca nadpisuje trzymane sztuki; zwolnienie musi „oddać” stan, którego sprzedawca w międzyczasie mógł zmienić; „wyprzedana” mówiłaby co innego niż półka |
| D. Rezerwacje trzyma zamówienie (Ordering) | portal bez zmian | portal nie wie o rezerwacjach, więc nie pilnuje H3 i dalej pokazuje towar w wyszukiwarce; reguła stanu w dwóch kontekstach |

## Konsekwencje

**Pozytywne**
- Brak towaru, zmiana ceny i niedostępność oferty decyduje jedno miejsce
  (`Offer::reserve`); Ordering dostaje gotowy wynik przez port
  (`ReservationResult`) i nie zna wyjątków portalu
  (`apps/catalog/src/Ordering/Infrastructure/SellerPortalStockReservations.php:41`).
- Sprzedawca nie zejdzie poniżej zarezerwowanego, a UI mówi mu, ile sztuk
  jest obiecanych („Na stanie N · zarezerwowane R · dostępne A”).
- Ostatnia zarezerwowana sztuka znika z wyszukiwarki tym samym mechanizmem
  co „wyprzedana” (outbox → projekcja), bez nowego kodu po stronie searcha.
- Zgodność dwóch definicji widoczności (domena i SQL z podzapytaniem) pilnuje
  test na tych samych danych: `OfferReadModelTest` › „listed() i
  Offer::isListed() zgadzają się na tych samych danych”
  (`apps/catalog/tests/Feature/SellerPortal/Infrastructure/OfferReadModelTest.php:98`).

**Negatywne**
- **Gorąca oferta.** Każda rezerwacja to zapis tej samej oferty, więc
  równoległe rezerwacje rywalizują o jej wersję i ponawiają
  (`RetryOnConflict`, 3 próby, potem ponowienie joba). Koszt mierzy
  `orders:race-test`.
- Każda rezerwacja, zwolnienie i potwierdzenie to zdarzenie w outboxie
  i pobranie projekcji przez search. Ruch do wyszukiwarki rośnie
  z liczbą zamówień, nie tylko ze zmianami sprzedawcy.
- `offers[].stock` w dokumencie wyszukiwarki znaczy teraz „dostępne”, nie
  „na stanie”. Nazwa pola została (kontrakt), znaczenie się zmieniło.
- Wiersze `confirmed` i `released` w `offer_reservations` nigdy nie są
  przycinane. Są potrzebne do idempotencji, ale rosną bez końca.
- Dwie definicje „widocznej” (domena i `RESERVED_SQL`) są teraz bardziej
  złożone; wiąże je tylko test i ten ADR, jak w ADR-0004.
- Rekonsyliacji „aktywne rezerwacje zamówień opłaconych” nie ma (spec,
  sekcja 5). Rezerwacja, której potwierdzenie padło na stałe, wisi do
  ręcznej interwencji ([RUNBOOK #046](../RUNBOOK.md#046)).

**Zmierzone 2026-10-17** na lokalnym stacku (Postgres, workery;
szczegóły i surowe wyjścia: [POMIARY, sekcja 11](../POMIARY.md#zamowienia-2026-10-17)):
1. **Wyścig o ostatnie sztuki** (`orders:race-test --buyers=50 --stock=10 --workers=4`,
   3 przebiegi): za każdym razem 10 opłaconych, 40 odrzuconych przez krok
   rezerwacji, overselling 0, `failed_jobs` 0. Konflikty wersji oferty
   61 / 24 / 12 (wszystkie wchłonięte przez ponowienia), konflikty wersji
   zamówienia 0. Gorąca oferta kosztuje więc konflikty i ponowienia, nie
   poprawność. Latencji z tych przebiegów nie wyciągam: maszyna była
   współdzielona i węzły ES restartowały się w trakcie (p50 rosło od 0,6 s
   do 7 s między przebiegami tego samego kodu).
2. **Ostatnia sztuka w `/search`:** od kliknięcia „Złóż zamówienia” do
   zniknięcia oferty z dokumentu ES 1,3 s; od zdarzenia w katalogu do
   zaindeksowania 0,53 s przy rezerwacji i 0,57 s przy zwolnieniu po
   anulowaniu. Wygasłe przez sweep zamówienie też oddało sztukę do indeksu.
