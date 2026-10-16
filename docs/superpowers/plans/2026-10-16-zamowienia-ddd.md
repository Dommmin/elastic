# Zamówienia i rezerwacja stanu (DDD, saga): plan implementacji

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Kontekst Ordering w `apps/catalog`: koszyk wielosprzedawcowy dzielony na zamówienia per sprzedawca, rezerwacja stanu w agregacie `Offer`, płatność przez symulowaną bramkę, wysyłka i anulowanie przez sprzedawcę ze zwrotem. Wszystko spina process manager z kompensacjami. Potem gałąź DDD-lite (jedna transakcja z blokadami) i zmierzone porównanie.

**Architecture:** Modularny monolit, jak portal. Nowy `apps/catalog/src/Ordering/{Domain,Application,Infrastructure}` (PSR-4 `Ordering\`) i wydzielony `apps/catalog/src/SharedKernel/` (PSR-4 `SharedKernel\`). Seller Portal dostaje rezerwacje w agregacie `Offer` i trzy komendy aplikacyjne (reserve/release/confirm), które Ordering woła przez własny port. Kroki sagi to joby w kolejce `database`, wstawiane w transakcji zapisu zamówienia. Wygaszanie to sweep co minutę w schedulerze. Bramka to osobny moduł `app/FakePaymentGateway` z podpisanym webhookiem.

**Tech Stack:** PHP 8.5, Laravel 13 + Octane, Pest 5 (z `arch()`), Inertia v3 + Vue 3 + shadcn-vue, Wayfinder, Postgres (prod) / SQLite in-memory (testy), kolejka `database`, scheduler Laravela.

**Spec:** `docs/superpowers/specs/2026-10-16-zamowienia-ddd-design.md`. Czytaj razem z planem: hot spoty (H1–H12) i wyścigi (R1–R10) są tam uzasadnione, a plan odwołuje się do nich po numerach.

## Global Constraints

- Warstwy: `Ordering\Domain` nie używa `Illuminate\*`, `App\*`, `SellerPortal\*`, `Ordering\Application\*`, `Ordering\Infrastructure\*`. `Ordering\Application` nie używa `Illuminate\*`, `App\*`, `SellerPortal\*`, `Ordering\Infrastructure\*`. `SellerPortal\*` nie używa `Ordering\*`. `SharedKernel` nie używa `Illuminate\*`, `App\*`, `SellerPortal\*`, `Ordering\*`. Z `SellerPortal\*` korzysta wyłącznie `Ordering\Infrastructure`.
- Domena (obu kontekstów) nie woła `now()`/`time()`/`date()`/`microtime()`; każda zmiana stanu dostaje `DateTimeImmutable $now`.
- Wartości ze specu (stałe w kodzie, nie w konfiguracji):
  - `Order::PAYMENT_WINDOW = 'PT15M'`, `Order::WEBHOOK_GRACE = 'PT5M'`,
  - `Order::MAX_LINES = 20`, `LineQuantity` 1–10,
  - `Quantity` (portal) 1–100 000, `ReservationKey` 1–64 znaki, Ordering nadaje `order-{id}`,
  - `TrackingNumber` 3–64 znaki po trim, `Reason` (Ordering) niepusty po trim, ≤ 500.
- Ponowienia: `ConcurrencyConflict` do **3** prób w handlerze lub kroku; joby `$tries = 5`, `$backoff = [5, 30, 120]`. Sweep `orders:expire-overdue` co minutę (`withoutOverlapping`).
- Bramka: webhook `POST /webhooks/payments`, nagłówek `X-Signature` = HMAC-SHA256 surowego body, sekret `FAKE_GATEWAY_WEBHOOK_SECRET`, opóźnienie wysyłki 2 s (`FAKE_GATEWAY_WEBHOOK_DELAY_SECONDS`), flaga `FAKE_GATEWAY_FAIL_FIRST_REFUND`. Payload: `payment_id`, `reference`, `status` (`succeeded|failed`), `amount_cents`, `currency`, `occurred_at`.
- Nowe zdarzenia outboxu (Published Language): `offer.stock_reserved`, `offer.reservation_released`, `offer.reservation_confirmed`. Payload bazowy oferty dostaje pole `available`. Klucz rezerwacji **nie** trafia do payloadu. Zdarzenia `Order` NIE trafiają do outboxu.
- Kody reguł (`CODE`) i komunikaty w `app/Http/DomainErrorMessages.php`:
  - `stock_below_reserved` → „Nie możesz zejść poniżej liczby zarezerwowanych sztuk”,
  - `price_changed_since_cart` → „Ceny w koszyku się zmieniły — sprawdź i złóż zamówienie ponownie”,
  - `offer_no_longer_available` → „Część ofert w koszyku nie jest już dostępna”,
  - `cannot_buy_own_offer` → „Nie możesz kupić własnej oferty”,
  - `invalid_order_transition` → „Tej operacji nie można wykonać na zamówieniu w obecnym stanie”,
  - `cart_empty` → „Koszyk jest pusty”,
  - pozostałe kody domeny rezerwacji (`offer_not_reservable`, `reservation_price_mismatch`, `insufficient_stock`, `reservation_conflict`, `reservation_not_found`) do UI nie docierają (tłumaczy je adapter) i dostają FALLBACK.
- Zero nowych zależności Composera i npm.
- Testy na hoście (Herd PHP 8.5) z `apps/catalog`: `php artisan test --compact <ścieżka>`. `phpunit.xml` wymusza SQLite in-memory i `QUEUE_CONNECTION=sync` (nie ruszać). Testów nigdy nie uruchamiamy w kontenerze (RUNBOOK #018/#026).
- Każdy dotknięty plik PHP: `vendor/bin/pint --dirty --format agent`. Frontend: `npm run lint:check && npm run format:check && npm run types:check`. Wayfinder zawsze `php artisan wayfinder:generate --with-form`.
- Komentarze i komunikaty UI po polsku, kod po angielsku; w docblockach „dlaczego”, nie „co” (styl repo; wzorzec: `Offer.php`, `SuspendOffersOfBlockedSeller.php`).
- Maszyna współdzielona: nie zatrzymywać cudzych kontenerów; kontener `catalog-app` montuje główny checkout (`/Users/domin/projects/elastic`), więc E2E (Task 15) robimy na głównym checkoutcie.
- Commit kończy się linią `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Prefiks tematu: `Zamówienia: …` (na gałęzi lite: `Zamówienia (DDD-lite): …`).

## Review Focus

1. **Oferta z koszyka zmieniła się po dodaniu** (cena, wycofanie, wyprzedanie) → koszyk oznacza takie pozycje („cena zmieniona z X na Y”, „niedostępna”), a „Złóż zamówienia” odmawia dla całego koszyka z komunikatem; żadne zamówienie nie powstaje. Test w Task 11.
2. **Ta sama oferta dodana dwa razy albo ilość ponad limit** → ilości się sumują, ale nie przekraczają `min(10, dostępne)`; przy przekroczeniu błąd walidacji, a koszyk zostaje bez zmian. Test w Task 11.
3. **Powrót z bramki, zanim przyszedł webhook** → strona zamówienia pokazuje „Czekamy na potwierdzenie płatności”, bez ponownego „Zapłać”, i odświeża się przez 30 s; nie pokazuje „nieopłacone”. Test w Task 12.
4. **Odliczanie do terminu przy złym zegarze klienta** → strona dostaje `seconds_to_deadline` z serwera i liczy od niego, nie od `Date.now()` kontra termin. Test w Task 12.
5. **Numer przesyłki z samych spacji, pusty albo za długi** → błąd walidacji przy polu, zamówienie zostaje `Paid`. Test w Task 13.

---

## Mapa plików

```
apps/catalog/src/SharedKernel/           Money, Currency, CurrencyMismatch, InvalidValue, AggregateRoot,
                                         DomainEvent, DomainException, ConcurrencyConflict      (przeniesione z SellerPortal\Domain\Shared)
apps/catalog/src/SellerPortal/
  Domain/Offer/                          + ReservationKey, Quantity, Reservation, ReservationStatus,
                                           OfferNotReservable, ReservationPriceMismatch, InsufficientStock,
                                           ReservationConflict, ReservationNotFound, StockBelowReserved,
                                           Event/{OfferStockReserved, OfferReservationReleased, OfferReservationConfirmed}
  Application/Reservation/               ReserveStock(+Handler), ReleaseReservation(+Handler), ConfirmReservation(+Handler)
  Application/RetryOnConflict.php
  Infrastructure/Persistence/            SqlOfferRepository, OfferIntegrationEvents (zmiany)
apps/catalog/src/Ordering/
  Domain/                                Order, OrderId, BuyerId, SellerId, OfferId, OrderLine, LineQuantity, LineStatus,
                                         ProductName, OrderStatus, Payment, PaymentId, Refund, RefundId, RefundStatus,
                                         RefundReason, Rejection, RejectionReason, Cancellation, CancelledBy, Reason,
                                         Shipment, TrackingNumber, OrderAction, Actor, OrderRepository,
                                         InvalidOrderTransition, InvalidOrderLines, OrderNotFound,
                                         Event/{OrderPlaced, OrderLineReserved, OrderAwaitingPayment, OrderRejected,
                                                OrderPaid, OrderPaymentFailed, LatePaymentReceived, RefundRequested,
                                                RefundCompleted, OrderExpired, OrderCancelled, OrderShipped}
  Application/Port/                      Clock, TransactionManager, DomainEventDispatcher, StockReservations,
                                         ReservationResult, OfferSnapshots, OfferSnapshot, PaymentGateway,
                                         ProcessJobQueue, BuyerCart, SellerAccounts
  Application/Order/                     PlaceOrdersFromCart(+Handler), CartLine, PriceChangedSinceCart, PriceChange,
                                         OfferNoLongerAvailable, CannotBuyOwnOffer, CartEmpty, StartPayment(+Handler),
                                         RecordPayment(+Handler), RecordPaymentFailure(+Handler),
                                         CancelOrderByBuyer(+Handler), CancelOrderBySeller(+Handler),
                                         ShipOrder(+Handler), ExpireOverdueOrders(+Handler), OrderAccess
  Application/Process/                   OrderProcessManager, OrderReservationKey, ReserveOrderLines,
                                         ReleaseOrderReservations, ConfirmOrderReservations, RefundPayment
  Infrastructure/                        Persistence/SqlOrderRepository, SellerPortalStockReservations,
                                         DbOfferSnapshots, DbSellerAccounts, EloquentBuyerCart,
                                         FakeGatewayPaymentGateway, LaravelProcessJobQueue,
                                         LaravelTransactionManager, SystemClock, InProcessDomainEventDispatcher,
                                         Job/{ReserveOrderLinesJob, ReleaseOrderReservationsJob,
                                              ConfirmOrderReservationsJob, RefundPaymentJob},
                                         ReadModel/{CartView, BuyerOrderList, BuyerOrderDetails,
                                                    SellerOrderList, SellerOrderDetails}
apps/catalog/app/FakePaymentGateway/     FakePaymentGateway, Http/FakeGatewayController, Jobs/SendPaymentWebhookJob
apps/catalog/app/Providers/OrderingServiceProvider.php
apps/catalog/app/Models/CartItem.php
apps/catalog/app/Console/Commands/{ExpireOverdueOrdersCommand, OrdersRaceTestCommand}.php
apps/catalog/app/Http/Controllers/Shop/{ProductPageController, CartController, OrderController}.php
apps/catalog/app/Http/Controllers/Seller/SellerOrderController.php
apps/catalog/app/Http/Controllers/Webhooks/PaymentWebhookController.php
apps/catalog/resources/js/pages/shop/{Product,Cart}.vue, shop/orders/{Index,Show}.vue,
                         seller/orders/{Index,Show}.vue, fake-gateway/Payment.vue
apps/catalog/resources/js/components/orders/{OrderStatusBadge,OrderTimeline,PaymentCountdown}.vue
apps/catalog/resources/js/types/orders.ts
tests/Unit/SharedKernel/*, tests/Unit/SellerPortal/{Domain,Application}/*Reservation*,
tests/Unit/Ordering/{Domain,Application,InMemory}/*, tests/Feature/Ordering/{Infrastructure,Http}/*,
tests/Feature/FakePaymentGateway/*, tests/Arch/{OrderingLayersTest,SharedKernelTest}.php
```

---

### Task 1: Shared Kernel

**Files:**
- Move: `src/SellerPortal/Domain/Shared/{Money,Currency,CurrencyMismatch,InvalidValue,AggregateRoot,DomainEvent,DomainException,ConcurrencyConflict}.php` → `src/SharedKernel/` (namespace `SharedKernel`), przez `git mv`
- Modify: `composer.json` (`"SharedKernel\\": "src/SharedKernel/"`), każdy import w `src/`, `app/`, `bootstrap/app.php`, `tests/`; ścieżki do tych plików w `docs/10-DDD.md`, `docs/11-SELLER-PORTAL-SLOWNIK.md`, `docs/blog/etap-e-portal-sprzedawcy-ddd.md` (grep `Domain/Shared/(Money|Currency|AggregateRoot|DomainEvent|DomainException|ConcurrencyConflict|InvalidValue|CurrencyMismatch)`); ćwiczenie „Zepsuj i napraw” z `Money` w module 2 dostaje nową ścieżkę.
- Move: `tests/Unit/SellerPortal/Domain/MoneyTest.php` → `tests/Unit/SharedKernel/MoneyTest.php`
- Create: `tests/Arch/SharedKernelTest.php`

**Interfaces:**
- Produces: `SharedKernel\{Money, Currency, CurrencyMismatch, InvalidValue, AggregateRoot, DomainEvent, DomainException, ConcurrencyConflict}`, bez zmian API. W `SellerPortal\Domain\Shared` zostają `UserId`, `ModeratorId`, `Reason`.

- [ ] **Step 1:** Uruchom pełny zestaw portalu i zapisz wynik (liczba testów): `php artisan test --compact tests/Unit/SellerPortal tests/Feature/SellerPortal tests/Arch`. Oczekiwane: PASS.
- [ ] **Step 2: Test architektury (failing)**

```php
arch('shared kernel nie zna nikogo')
    ->expect('SharedKernel')
    ->not->toUse(['Illuminate', 'App', 'SellerPortal', 'Ordering']);
arch('shared kernel nie woła funkcji zegara')
    ->expect('SharedKernel')->not->toUse(['now', 'time', 'date', 'microtime']);
```

Run: `php artisan test --compact tests/Arch/SharedKernelTest.php`. Expected: FAIL (brak przestrzeni nazw; pusta oczekiwana jako „no classes” to też porażka do naprawienia przeniesieniem).
- [ ] **Step 3:** `git mv`, zmiana namespace, `composer dump-autoload`, poprawa importów (`grep -rln "SellerPortal\\\\Domain\\\\Shared\\\\\(Money\|Currency\|…\)"`), ścieżki w dokumentacji.
- [ ] **Step 4:** Ten sam zestaw co w Step 1 + `tests/Unit/SharedKernel` → PASS, ta sama liczba testów; `pint`.
- [ ] **Step 5: Commit** `Zamówienia: Shared Kernel (Money, klasy bazowe) wydzielony z portalu sprzedawcy`

---

### Task 2: Rezerwacje w agregacie `Offer` (domena)

**Files:**
- Create: `src/SellerPortal/Domain/Offer/{ReservationKey, Quantity, Reservation, ReservationStatus, OfferNotReservable, ReservationPriceMismatch, InsufficientStock, ReservationConflict, ReservationNotFound, StockBelowReserved}.php`, `Event/{OfferStockReserved, OfferReservationReleased, OfferReservationConfirmed}.php`
- Modify: `src/SellerPortal/Domain/Offer/Offer.php` (konstruktor, `reconstitute`, `changeStock`, `isListed`, `isSoldOut`, nowe metody, docblock klasy), `tests/Unit/SellerPortal/InMemory/InMemoryOfferRepository.php` (nowy parametr `reconstitute`)
- Test: `tests/Unit/SellerPortal/Domain/OfferReservationTest.php`, uzupełnienie `OfferStockTest.php`

**Interfaces:**
- Produces:
  - `ReservationKey::fromString(string): self` (1–64 znaki, inaczej `InvalidValue`), `value(): string`, `equals()`.
  - `Quantity::fromInt(int): self` (1–100 000), `value(): int`.
  - `Reservation(ReservationKey $key, Quantity $quantity, DateTimeImmutable $reservedAt)` (readonly).
  - `enum ReservationStatus: string { Active='active'; Confirmed='confirmed'; Released='released'; }`.
  - `Offer::reconstitute(..., array $recentPriceChanges, list<Reservation> $reservations, int $version)`: nowy parametr przed `$version`.
  - `Offer::reserved(): int`, `Offer::available(): int`, `Offer::reservations(): list<Reservation>`.
  - `Offer::reserve(Seller $seller, ReservationKey $key, Quantity $quantity, Money $expectedUnitPrice, DateTimeImmutable $now): void`. Kolejność sprawdzeń: status `Published` → `canSell()` → cena (`equals`) → istniejący klucz (ta sama ilość = return, inna = `ReservationConflict`) → `quantity ≤ available`.
  - `Offer::releaseReservation(ReservationKey, DateTimeImmutable): void`, `Offer::confirmReservation(ReservationKey, DateTimeImmutable): void`.
  - Zdarzenia (readonly, implementują `DomainEvent`):
    - `OfferStockReserved(OfferId $offerId, ReservationKey $key, Quantity $quantity, int $available, bool $listed, DateTimeImmutable $occurredAt)`,
    - `OfferReservationReleased(` te same pola `)`,
    - `OfferReservationConfirmed(OfferId, ReservationKey, Quantity, Stock $stock, DateTimeImmutable)`.
  - `CODE`: `offer_not_reservable`, `reservation_price_mismatch`, `insufficient_stock`, `reservation_conflict`, `reservation_not_found`, `stock_below_reserved`.

- [ ] **Step 1: Testy (failing)**

```php
test('rezerwacja zmniejsza dostępne, nie stan', ...);                 // stock 5, reserve 2 → stock 5, reserved 2, available 3; zdarzenie OfferStockReserved(available: 3, listed: true)
test('rezerwacja poza Published rzuca OfferNotReservable', ...);      // dataset: draft, suspended, withdrawn
test('zablokowany sprzedawca nie rezerwuje', ...);                    // SellerCannotSell
test('cena inna niż obecna rzuca ReservationPriceMismatch', ...);
test('oczekująca zmiana ceny nie przeszkadza: liczy się obecna cena', ...);
test('więcej niż dostępne rzuca InsufficientStock', ...);             // stock 3, reserve 2 (key a), reserve 2 (key b) → wyjątek
test('ten sam klucz i ilość to no-op bez zdarzenia', ...);
test('ten sam klucz, inna ilość rzuca ReservationConflict', ...);
test('ostatnia sztuka zarezerwowana: oferta wyprzedana i niewidoczna', ...); // isListed false, isSoldOut true, OfferStockReserved(listed: false)
test('zwolnienie działa w każdym statusie, także po wycofaniu', ...); // H4
test('zwolnienie nieznanego klucza to no-op bez zdarzenia', ...);
test('potwierdzenie zmniejsza stan, dostępne bez zmian', ...);       // stock 5, reserve 2, confirm → stock 3, reserved 0, available 3
test('potwierdzenie nieznanego klucza rzuca ReservationNotFound', ...);
test('wycofanie nie zwalnia rezerwacji', ...);
test('stan poniżej zarezerwowanego rzuca StockBelowReserved, równy przechodzi', ...); // reserved 3: changeStock(2) rzuca, changeStock(3) OK
test('powrót towaru liczony od dostępności', ...);                    // stock 2 reserved 2 → changeStock(3) daje OfferBackInStock
test('wyprzedanie liczone od dostępności', ...);                      // stock 5 reserved 2 → changeStock(2) daje OfferSoldOut
```

- [ ] **Step 2:** `php artisan test --compact tests/Unit/SellerPortal/Domain` → FAIL
- [ ] **Step 3: Implementacja.** W docblocku `Offer` dopisz, dlaczego rezerwacje są w ofercie (ADR-0005), dlaczego `withdraw` ich nie zwalnia (H4) i dlaczego `reserve` wymaga `Seller`.
- [ ] **Step 4:** `php artisan test --compact tests/Unit/SellerPortal tests/Arch` → PASS; `pint`
- [ ] **Step 5: Commit** `Zamówienia: rezerwacje stanu w agregacie Offer (dostępne jako stan pochodny)`

---

### Task 3: Rezerwacje w bazie, outboxie, projekcji i stronie odczytu

**Files:**
- Create: `database/migrations/2026_10_16_100000_create_offer_reservations_table.php`
- Modify:
  - `src/SellerPortal/Domain/Offer/OfferRepository.php` (`reservationStatus`),
  - `src/SellerPortal/Infrastructure/Persistence/{SqlOfferRepository, OfferIntegrationEvents}.php`,
  - `app/Models/Offer.php` (scope `listed`, scope `withAvailable`, relacja `activeReservations`),
  - `app/Http/Controllers/Internal/ProductProjectionController.php`,
  - `tests/Unit/SellerPortal/InMemory/InMemoryOfferRepository.php`
- Test: `tests/Feature/SellerPortal/Infrastructure/{SqlOfferRepositoryTest, OfferIntegrationEventsTest, OfferReadModelTest}.php` (dopisane przypadki), `tests/Feature/ProductProjectionTest.php`

**Interfaces:**
- Consumes: Task 2.
- Produces:
  - Tabela `offer_reservations`: `id`, `offer_id` (FK `offers`, cascade), `reservation_key` string(64), `quantity` int, `status` string(16), `reserved_at`, `settled_at` nullable (format jak `SqlTimestamp`). Unikalny `(offer_id, reservation_key)`, indeks `(offer_id, status)`.
  - `OfferRepository::reservationStatus(OfferId $offerId, ReservationKey $key): ?ReservationStatus`.
  - `SqlOfferRepository::get` wczytuje tylko `active`. `save()` (w tej samej transakcji, przed outboxem) przekłada zdarzenia rezerwacji z `pendingEvents()` na wiersze:
    - `OfferStockReserved` → INSERT `active`,
    - `OfferReservationReleased` → UPDATE `released`, `settled_at`,
    - `OfferReservationConfirmed` → UPDATE `confirmed`, `settled_at`.

    DLACZEGO ze zdarzeń: agregat nie pamięta, czy zniknięta rezerwacja została zwolniona, czy potwierdzona, a zdarzenie to mówi.
  - `OfferIntegrationEvents`: mapowania na `offer.stock_reserved`, `offer.reservation_released`, `offer.reservation_confirmed`; pola zdarzenia `quantity`; bazowy payload dostaje `available`.
  - Eloquent `Offer`: `scopeListed` = `status='published' AND stock > COALESCE((SELECT SUM(quantity) FROM offer_reservations WHERE offer_id = offers.id AND status='active'), 0)`; `scopeWithAvailable` dodaje kolumnę `available`.
  - Projekcja: `listedOffers` z `withAvailable()`; `offers[].stock` = `available`, `in_stock` = jakakolwiek `available > 0`.
  - `InMemoryOfferRepository::reservationStatus` liczy status z zapisanych zdarzeń.

- [ ] **Step 1: Testy (failing)**

```php
test('rezerwacje wracają z bazy, tylko aktywne', ...);                 // reserve a, reserve b, release a → get() ma tylko b
test('reservationStatus rozróżnia confirmed i released', ...);
test('dwie rezerwacje w jednym zapisie: dwa wiersze outboxu offer.stock_reserved', ...);
test('payload offer.stock_reserved ma quantity, available, listed i nie ma klucza', ...); // assert !array_key_exists('reservation_key', $payload)
test('listed() pomija ofertę z całym stanem zarezerwowanym', ...);
test('listed() i Offer::isListed() zgadzają się na tych samych danych', ...); // dataset: stock/reserved/status kombinacje
test('projekcja pokazuje dostępne zamiast stanu', ...);                // stock 5, reserved 2 → offers[0].stock = 3
```

- [ ] **Step 2:** `php artisan test --compact tests/Feature/SellerPortal tests/Feature/ProductProjectionTest.php` → FAIL
- [ ] **Step 3:** Migracja, repozytorium, translator, scope, projekcja
- [ ] **Step 4:** `php artisan test --compact tests/Unit/SellerPortal tests/Feature/SellerPortal tests/Feature/ProductProjectionTest.php tests/Arch` → PASS; `pint`
- [ ] **Step 5: Commit** `Zamówienia: rezerwacje w bazie, zdarzenia offer.* rezerwacji i dostępność w projekcji`

---

### Task 4: Komendy rezerwacji w Seller Portal + UI stanu sprzedawcy

**Files:**
- Create: `src/SellerPortal/Application/RetryOnConflict.php`, `src/SellerPortal/Application/Reservation/{ReserveStock, ReserveStockHandler, ReleaseReservation, ReleaseReservationHandler, ConfirmReservation, ConfirmReservationHandler}.php`
- Modify:
  - `src/SellerPortal/Infrastructure/ReadModel/SellerOfferDetails.php` (`reserved`, `available`),
  - `resources/js/pages/seller/offers/Show.vue` („Na stanie N · zarezerwowane R · dostępne A”),
  - `resources/js/types/seller.ts`,
  - `app/Http/DomainErrorMessages.php` (`stock_below_reserved`)
- Test: `tests/Unit/SellerPortal/Application/ReservationHandlersTest.php`, `tests/Feature/SellerPortal/Http/SellerOfferTest.php` (dopisane)

**Interfaces:**
- Consumes: Task 2–3; istniejące porty `TransactionManager`, `Clock`, `DomainEventDispatcher`, `OfferRepository`, `SellerRepository`.
- Produces:
  - `RetryOnConflict::run(callable $work, int $attempts = 3): mixed`: ponawia na `ConcurrencyConflict`, po wyczerpaniu rzuca dalej.
  - Komendy (prymitywy, `final readonly`):
    - `ReserveStock(string $reservationKey, int $offerId, int $quantity, int $expectedUnitPriceCents, string $currency)`,
    - `ReleaseReservation(string $reservationKey, int $offerId)`,
    - `ConfirmReservation(string $reservationKey, int $offerId)`.
  - Handlery `handle(): void`: każdy `RetryOnConflict::run(fn => transactions->run(wczytaj ofertę → metoda → save → dispatch))`. `ReserveStockHandler` wczytuje sprzedawcę oferty (`SellerRepository::get($offer->sellerId())`). Wyjątki domeny przepuszcza (tłumaczy adapter w Task 9).
  - `ConfirmReservationHandler`: gdy oferta nie ma aktywnej rezerwacji z kluczem i `reservationStatus === Confirmed` → return; inaczej woła `confirmReservation` (który rzuci `ReservationNotFound`).

- [ ] **Step 1: Testy (failing)**

```php
test('ReserveStock rezerwuje i rozsyła OfferStockReserved', ...);
test('ConfirmReservation drugi raz to no-op', ...);                    // ponowiony job po awarii
test('ConfirmReservation po zwolnieniu rzuca ReservationNotFound', ...); // obrona przed sprzedażą bez zmniejszenia stanu
test('konflikt wersji jest ponawiany do 3 razy', ...);                 // InMemoryOfferRepository::failNextSaves(2) → sukces; failNextSaves(3) → ConcurrencyConflict
// HTTP:
test('sprzedawca nie zejdzie ze stanem poniżej zarezerwowanego', ...); // PUT stock → błąd "Nie możesz zejść poniżej liczby zarezerwowanych sztuk", stan bez zmian
test('szczegóły oferty pokazują zarezerwowane i dostępne', ...);       // assertInertia offer.reserved, offer.available
```

- [ ] **Step 2:** FAIL → **Step 3:** Implementacja (w tym `InMemoryOfferRepository::failNextSaves(int)`)
- [ ] **Step 4:** `php artisan test --compact tests/Unit/SellerPortal tests/Feature/SellerPortal tests/Arch` → PASS; `pint`; frontend checks
- [ ] **Step 5: Commit** `Zamówienia: komendy rezerwacji w portalu sprzedawcy i zarezerwowane sztuki w UI`

---

### Task 5: Agregat `Order` (domena Orderingu)

**Files:**
- Create: wszystkie pliki `src/Ordering/Domain/**` z mapy plików
- Modify: `composer.json` (`"Ordering\\": "src/Ordering/"`)
- Test: `tests/Unit/Ordering/Domain/{OrderPlacementTest, OrderReservationTest, OrderPaymentTest, OrderLifecycleTest, OrderValuesTest}.php`, `tests/Arch/OrderingLayersTest.php`

**Interfaces:**
- Consumes: `SharedKernel\*`.
- Produces:
  - VO z `fromInt`/`fromString`, `value()` i `equals()`:
    - `OrderId`, `BuyerId`, `SellerId`, `OfferId` (int > 0),
    - `PaymentId` (string 1–64), `RefundId` (string 1–64),
    - `ProductName` (1–255 po trim),
    - `LineQuantity` (1–10), `TrackingNumber` (3–64 po trim), `Reason` (1–500 po trim).
  - Enumy:
    - `OrderStatus` (`placed, awaiting_payment, paid, shipped, rejected, expired, cancelled`),
    - `LineStatus` (`pending, reserved`),
    - `RejectionReason` (`out_of_stock, price_changed, offer_unavailable`),
    - `CancelledBy` (`buyer, seller`),
    - `RefundStatus` (`requested, completed`),
    - `RefundReason` (`late_payment, duplicate_payment, amount_mismatch, cancelled_by_seller`),
    - `Actor` (`buyer, seller`),
    - `OrderAction` (`pay, cancel, ship, cancel_by_seller`).
  - `OrderLine::create(OfferId, ProductName, Money $unitPrice, LineQuantity): self` (status `Pending`); `lineTotal(): Money`.
  - `Order::place(OrderId, BuyerId, SellerId, list<OrderLine>, DateTimeImmutable $now): self`. Rzuca `InvalidOrderLines` (CODE `invalid_order_lines`) przy 0 lub > 20 pozycjach, powtórzonej ofercie albo różnych walutach. `paymentDeadline = now + PAYMENT_WINDOW`.
  - Metody: zgodnie z tabelą w sekcji 4 specu:
    - `markLineReserved(OfferId, $now)`, `reject(RejectionReason, OfferId, $now)`,
    - `recordPayment(PaymentId, Money, $now)`, `recordPaymentFailure(PaymentId, $now)`,
    - `expire($now)`, `cancelByBuyer($now)`, `cancelBySeller(Reason, $now)`, `ship(TrackingNumber, $now)`,
    - `markRefundCompleted(RefundId, $now)`, `requirePayable(): void` (`AwaitingPayment`, inaczej `InvalidOrderTransition`),
    - `allowedActionsFor(Actor): list<OrderAction>` (Buyer: `Placed` → `[cancel]`, `AwaitingPayment` → `[pay, cancel]`; Seller: `Paid` → `[ship, cancel_by_seller]`; reszta `[]`),
    - `isDueForExpiry(DateTimeImmutable): bool` (`Placed|AwaitingPayment` i `now ≥ deadline + WEBHOOK_GRACE`).
  - `RefundId` nadaje agregat deterministycznie: `refund-{orderId}-{n}` (n = liczba zwrotów + 1). Domena nie losuje.
  - `InvalidOrderTransition::from(OrderStatus, OrderAction|string)` (CODE `invalid_order_transition`). `OrderNotFound` (CODE `order_not_found`).
  - Zdarzenia (każde z `OrderId $orderId` i `DateTimeImmutable $occurredAt`, plus):
    - `OrderPlaced(BuyerId, SellerId, Money $total)`, `OrderLineReserved(OfferId)`, `OrderAwaitingPayment(DateTimeImmutable $deadline)`,
    - `OrderRejected(RejectionReason, OfferId)`, `OrderPaid(PaymentId, Money)`, `OrderPaymentFailed(PaymentId)`,
    - `LatePaymentReceived(PaymentId, Money)`, `RefundRequested(RefundId, PaymentId, Money, RefundReason)`, `RefundCompleted(RefundId)`,
    - `OrderExpired()`, `OrderCancelled(CancelledBy, ?Reason)`, `OrderShipped(TrackingNumber)`.
  - `OrderRepository { nextIdentity(): OrderId; get(OrderId): Order /* OrderNotFound */; save(Order): void /* ConcurrencyConflict */; idsDueForExpiry(DateTimeImmutable $now, int $afterId, int $limit): list<OrderId>; }`.

- [ ] **Step 1: Testy (failing)**

```php
// OrderPlacementTest
test('złożenie: status Placed, termin za 15 min, suma pozycji', ...);   // 2 × 1999 + 1 × 500 = 4498 groszy
test('zamówienie bez pozycji, z 21 pozycjami, z powtórzoną ofertą albo dwiema walutami rzuca InvalidOrderLines', ...);
// OrderReservationTest
test('wszystkie pozycje zarezerwowane → AwaitingPayment', ...);        // zdarzenia: OrderLineReserved ×2, OrderAwaitingPayment
test('ponowne markLineReserved to no-op', ...);
test('markLineReserved poza Placed rzuca InvalidOrderTransition', ...);
test('odrzucenie zapisuje powód i ofertę', ...);
// OrderPaymentTest — recordPayment w każdym statusie
test('właściwa kwota w AwaitingPayment → Paid', ...);
test('ten sam paymentId drugi raz to no-op', ...);                     // dataset: Paid, Shipped, Cancelled(by seller)
test('druga wpłata innym paymentId → zwrot duplicate_payment', ...);
test('wpłata w Expired, Cancelled, Rejected, Placed → LatePaymentReceived i zwrot late_payment', ...);
test('zła kwota → zwrot amount_mismatch, status bez zmian', ...);
test('wpłata, która ma już zwrot, to no-op', ...);
test('nieudana płatność nie zmienia statusu', ...);                    // H7
// OrderLifecycleTest
test('wygasa dopiero po terminie i 5 min karencji', ...);              // 14:59 + 15 min + 4:59 → nie; + 5:00 → Expired
test('expire w Paid to no-op', ...);
test('kupujący anuluje w Placed i AwaitingPayment, nie w Paid', ...);
test('sprzedawca anuluje opłacone: Cancelled i zwrot cancelled_by_seller na całą wpłatę', ...);
test('wysyłka tylko z Paid', ...);
test('zwrot zakończony; drugi raz no-op', ...);
test('identyfikatory zwrotów są kolejne: refund-7-1, refund-7-2', ...);
test('allowedActionsFor dla kupującego i sprzedawcy w każdym statusie', ...);
```

Arch (`tests/Arch/OrderingLayersTest.php`): reguły z Global Constraints dla `Ordering\Domain`, `Ordering\Application`, „domena nie woła funkcji zegara” oraz `SellerPortal` nie używa `Ordering`.
- [ ] **Step 2:** `php artisan test --compact tests/Unit/Ordering tests/Arch` → FAIL
- [ ] **Step 3: Implementacja.** Docblock `Order`: maszyna stanów, dlaczego stan procesu jest w zamówieniu (ADR-0006), dlaczego `recordPayment` nigdy nie rzuca dla „złego” statusu (kompensacja zamiast błędu: pieniądze już przyszły).
- [ ] **Step 4:** PASS; czas `tests/Unit/Ordering/Domain` < 1 s (`--profile`); `pint`
- [ ] **Step 5: Commit** `Zamówienia: agregat Order (statusy, płatność, zwroty, wygaśnięcie)`

---

### Task 6: Persystencja Orderingu

**Files:**
- Create:
  - `database/migrations/2026_10_16_100001_create_orders_tables.php` (`orders`, `order_lines`, `order_refunds`, `order_events`),
  - `src/Ordering/Infrastructure/Persistence/SqlOrderRepository.php`,
  - `tests/Unit/Ordering/InMemory/InMemoryOrderRepository.php`
- Test: `tests/Feature/Ordering/Infrastructure/SqlOrderRepositoryTest.php`

**Interfaces:**
- Consumes: Task 5; `SellerPortal\Infrastructure\Persistence\{NextId, SqlTimestamp}` (dozwolone: warstwa Infrastructure).
- Produces:
  - `orders`:
    - `id`, `buyer_user_id` (FK `users`), `seller_id` (FK `sellers`), `status`, `currency`, `total_cents`,
    - `placed_at`, `payment_deadline`, `payment_id` null, `paid_amount_cents` null, `paid_at` null,
    - `rejection_reason` null, `rejection_offer_id` null, `cancelled_by` null, `cancellation_reason` null,
    - `tracking_number` null, `shipped_at` null, `version`, `created_at`, `updated_at`.
    - Indeksy: `(status, payment_deadline)`, `(buyer_user_id, id)`, `(seller_id, status)`.
  - `order_lines`: `order_id` (FK cascade), `position`, `offer_id` (FK `offers`), `product_name`, `unit_price_cents`, `quantity`, `status`; unikalny `(order_id, offer_id)`.
  - `order_refunds`: `refund_id` (unikalny), `order_id`, `payment_id`, `amount_cents`, `reason`, `status`, `requested_at`, `completed_at` null.
  - `order_events`: `id`, `order_id`, `event_type` (np. `order.placed`, `order.line_reserved`, `order.refund_requested`), `payload` json, `occurred_at`. Indeks `(order_id, id)`.
  - `SqlOrderRepository implements OrderRepository`: wersjonowanie jak `SqlOfferRepository` (INSERT przy `version 0`, inaczej `UPDATE … WHERE version = ?`, 0 wierszy → `ConcurrencyConflict`); pozycje i zwroty przez upsert; każde zdarzenie z `pendingEvents()` → wiersz `order_events`. `idsDueForExpiry`: status `placed|awaiting_payment` i `payment_deadline <= now − WEBHOOK_GRACE`, po `id`.
  - `InMemoryOrderRepository`: ta sama semantyka wersji (klon przy zapisie i odczycie) oraz `failNextSaves(int)`.

- [ ] **Step 1: Testy (failing)**

```php
test('zapis i odczyt zamówienia zachowuje wszystkie pola', ...);       // pozycje w kolejności, zwroty, wysyłka, version
test('drugi zapis tej samej wersji rzuca ConcurrencyConflict', ...);
test('każde zdarzenie zapisuje wiersz order_events', ...);
test('idsDueForExpiry uwzględnia karencję i pomija opłacone', ...);
test('odczyt nieistniejącego rzuca OrderNotFound', ...);
```

- [ ] **Step 2:** FAIL → **Step 3:** Implementacja → **Step 4:** PASS; `pint`
- [ ] **Step 5: Commit** `Zamówienia: tabele i repozytorium SQL zamówień`

---

### Task 7: Warstwa aplikacji Orderingu: porty, komendy, handlery

**Files:**
- Create: `src/Ordering/Application/Port/*`, `src/Ordering/Application/Order/*`
- Create (testy): `tests/Unit/Ordering/InMemory/{OrderingWorld, FixedClock, ImmediateTransactionManager, RecordingDispatcher, FakeOfferSnapshots, FakeStockReservations, FakePaymentGateway, FakeProcessJobQueue, FakeBuyerCart, FakeSellerAccounts}.php`
- Test: `tests/Unit/Ordering/Application/{PlaceOrdersFromCartTest, OrderCommandsTest}.php`

**Interfaces:**
- Consumes: Task 5.
- Produces:
  - Porty generyczne (własne, nie z portalu): `Clock::now()`, `TransactionManager::run(callable): mixed`, `DomainEventDispatcher::dispatch(list<DomainEvent>)`.
  - `OfferSnapshots::forOffers(list<OfferId>): array<int, OfferSnapshot>` (klucz = id oferty; brakująca oferta = brak klucza).
  - `OfferSnapshot(OfferId $offerId, SellerId $sellerId, int $sellerUserId, ProductName $productName, Money $price, bool $listed)`.
  - `SellerAccounts::sellerIdForUser(int $userId): ?SellerId`. `BuyerCart::clear(BuyerId): void`.
  - `PaymentGateway`:
    - `startPayment(OrderId $orderId, Money $amount, DateTimeImmutable $expiresAt, string $returnUrl): string /* redirect URL */`,
    - `refund(RefundId $refundId, PaymentId $paymentId, Money $amount): void /* rzuca PaymentGatewayUnavailable przy porażce */`.
  - `StockReservations`:
    - `reserve(string $key, OfferId, LineQuantity, Money $unitPrice): ReservationResult`,
    - `release(string $key, OfferId): void`, `confirm(string $key, OfferId): void`.
  - `enum ReservationResult { Reserved; OutOfStock; PriceChanged; OfferUnavailable; }`.
  - `ProcessJobQueue`: `reserveLines(OrderId)`, `releaseReservations(OrderId)`, `confirmReservations(OrderId)`, `refund(OrderId, RefundId)`.
  - `PlaceOrdersFromCart(int $buyerUserId, list<CartLine> $lines)`, `CartLine(int $offerId, int $quantity, int $expectedUnitPriceCents)`. Handler zwraca `list<OrderId>`. Algorytm:
    1. pusty → `CartEmpty`;
    2. snapshoty;
    3. brak albo `!listed` → `OfferNoLongerAvailable(list<int> $offerIds)`;
    4. `sellerUserId === buyer` → `CannotBuyOwnOffer`;
    5. ceny ≠ → `PriceChangedSinceCart(list<PriceChange(int $offerId, int $expectedCents, int $currentCents)>)`;
    6. grupowanie po `sellerId` (kolejność: rosnące `sellerId`, pozycje w kolejności koszyka);
    7. w jednej `transactions->run`: `Order::place` × N, `save`, `BuyerCart::clear`, `dispatch`.

    Wyjątki niosą listy, żeby UI oznaczyło pozycje.
  - Pozostałe komendy (prymitywy) i handlery:
    - `StartPayment(int $buyerUserId, int $orderId, string $returnUrl): string`: `requirePayable`, `expiresAt = paymentDeadline`, bez zapisu zamówienia;
    - `RecordPayment(int $orderId, string $paymentId, int $amountCents, string $currency)`, `RecordPaymentFailure(int $orderId, string $paymentId)`;
    - `CancelOrderByBuyer(int $buyerUserId, int $orderId)`, `CancelOrderBySeller(int $sellerUserId, int $orderId, string $reason)`, `ShipOrder(int $sellerUserId, int $orderId, string $trackingNumber)`;
    - `ExpireOverdueOrders(): int`: paczki po 100 z `idsDueForExpiry`, każde w osobnej transakcji, `ConcurrencyConflict` pominięty, zwraca liczbę wygaszonych.

    Każdy handler zapisujący: do 3 prób przy `ConcurrencyConflict` przez własną klasę `Ordering\Application\RetryOnConflict::run(callable, int $attempts = 3)` (kopia ~15 linii z portalu, bo `Ordering\Application` nie importuje `SellerPortal\*`) → `transactions->run(wczytaj → metoda(now) → save → dispatch)`.
  - `OrderAccess`: `forBuyer(BuyerId, OrderId): Order` i `forSeller(int $sellerUserId, OrderId): Order`, w obu obce → `OrderNotFound` (UI 404).
  - CODE: `cart_empty`, `offer_no_longer_available`, `cannot_buy_own_offer`, `price_changed_since_cart`.

- [ ] **Step 1: Testy (failing)**

```php
// PlaceOrdersFromCartTest
test('koszyk z ofertami dwóch sprzedawców daje dwa zamówienia i czyści koszyk', ...);
test('zmieniona cena: żadne zamówienie, wyjątek z listą różnic', ...); // PriceChange(offerId, 1999, 2499)
test('niewidoczna oferta: żadne zamówienie, wyjątek z listą ofert', ...);
test('kupowanie własnej oferty rzuca CannotBuyOwnOffer', ...);          // H12
test('pusty koszyk rzuca CartEmpty', ...);
test('dostępność nie jest sprawdzana przy składaniu', ...);            // snapshot listed, ale rezerwacja odmówi później
test('złożenie dispatchuje OrderPlaced dla każdego zamówienia', ...);
// OrderCommandsTest
test('StartPayment poza AwaitingPayment rzuca InvalidOrderTransition', ...);
test('StartPayment przekazuje termin zamówienia jako wygaśnięcie sesji', ...);
test('kupujący nie widzi cudzego zamówienia', ...);                    // OrderNotFound
test('sprzedawca nie wyśle cudzego zamówienia', ...);
test('ExpireOverdueOrders wygasza tylko po karencji i pomija konflikty', ...);
```

- [ ] **Step 2:** FAIL → **Step 3:** Implementacja → **Step 4:** `php artisan test --compact tests/Unit/Ordering tests/Arch` PASS; `pint`
- [ ] **Step 5: Commit** `Zamówienia: komendy i handlery (składanie z koszyka, płatność, anulowanie, wysyłka)`

---

### Task 8: Process manager i kompensacje (warstwa aplikacji)

**Files:**
- Create: `src/Ordering/Application/Process/{OrderProcessManager, OrderReservationKey, ReserveOrderLines, ReleaseOrderReservations, ConfirmOrderReservations, RefundPayment}.php`
- Test: `tests/Unit/Ordering/Application/OrderProcessTest.php`, `tests/Unit/Ordering/Application/OrderRacesTest.php`

**Interfaces:**
- Consumes: Task 7 (porty, `RetryOnConflict`, `OrderingWorld`).
- Produces:
  - `OrderReservationKey::for(OrderId): string` → `order-{id}`.
  - `OrderProcessManager`: metody słuchaczy, każda tylko kolejkuje przez `ProcessJobQueue`:
    - `onPlaced(OrderPlaced)` → `reserveLines`,
    - `onRejected(OrderRejected)`, `onExpired(OrderExpired)` → `releaseReservations`,
    - `onCancelled(OrderCancelled)` → `releaseReservations` **tylko** gdy `by === Buyer` (H8: anulowanie sprzedawcy nie oddaje stanu),
    - `onPaid(OrderPaid)` → `confirmReservations`,
    - `onRefundRequested(RefundRequested)` → `refund`.
  - `ReserveOrderLines::run(OrderId): void`: algorytm poniżej.
  - `ReleaseOrderReservations::run(OrderId)`: `release` dla **każdej** pozycji (idempotentne).
  - `ConfirmOrderReservations::run(OrderId)`: `confirm` dla każdej pozycji, tylko gdy zamówienie `Paid|Shipped|Cancelled(by seller)` z zaksięgowaną wpłatą; inaczej return.
  - `RefundPayment::run(OrderId, RefundId)`: wczytaj zwrot; `Completed` → return; `PaymentGateway::refund` (klucz idempotencji = `refundId`) → transakcja `markRefundCompleted`. Wyjątek bramki leci dalej (ponowi job).

  ```
  ReserveOrderLines::run(orderId):
    order = get(orderId); if order.status != Placed: return
    for line in order.lines where line.status == Pending:
      result = reservations.reserve(key, line.offerId, line.quantity, line.unitPrice)      // T1
      if result != Reserved:
        retry(transaction: order = get; if order.status == Placed: order.reject(map(result), line.offerId); save; dispatch)
        return                                    // OrderRejected → ReleaseOrderReservations zwalnia wszystkie
      applied = retry(transaction: order = get                                              // T2
                  if order.status == Placed: order.markLineReserved(line.offerId); save; dispatch; return true
                  return false)
      if not applied: reservations.release(key, line.offerId); return                       // R1
  ```

- [ ] **Step 1: Testy (failing)**

```php
// OrderProcessTest
test('szczęśliwa ścieżka: złożone → zarezerwowane → opłacone → potwierdzone', ...); // FakeStockReservations: stock spada dopiero po confirm
test('brak towaru na drugiej pozycji: odrzucone, pierwsza zwolniona', ...);         // Kryterium 3
test('zmieniona cena przy rezerwacji: odrzucone z powodem price_changed', ...);
test('wygaśnięcie zwalnia wszystkie pozycje', ...);
test('anulowanie przez kupującego zwalnia, przez sprzedawcę nie', ...);
test('wpłata po terminie zleca zwrot, a zwrot się kończy', ...);
test('nieudany zwrot zostaje Requested, ponowienie kończy', ...);    // FakePaymentGateway::failNextRefunds(1)
// OrderRacesTest — po jednym na wyścig ze specu
test('R1: anulowanie w trakcie rezerwacji nie zostawia rezerwacji', ...);   // FakeStockReservations::afterReserve(fn => cancelByBuyer) ; po run: aktywne rezerwacje = 0
test('R2: ponowiony krok po awarii między T1 a T2 rezerwuje raz', ...);     // InMemoryOrderRepository::failNextSaves(3) przy pierwszym run → wyjątek; drugi run → reserved 1× w FakeStockReservations
test('R3: płatność i wygaśnięcie w tym samym momencie: zawsze jeden wynik', ...); // dwa porządki: expire→pay daje zwrot; pay→expire daje Paid
test('R4: zdublowany webhook nie robi nic', ...);
test('R6: dwóch kupujących, ostatnia sztuka: jeden AwaitingPayment, drugi Rejected', ...);
test('R7: anulowanie przez sprzedawcę przed potwierdzeniem: potwierdzenie i tak się wykonuje', ...);
test('R8: oferta wycofana przed rezerwacją: Rejected z offer_unavailable', ...);
test('R10: zamówienie utknięte w Placed wygasa i zwalnia rezerwacje', ...);
```

(R5 to brak wyścigu w jednym agregacie; pokrywa go Task 2 i `orders:race-test`. R9 pokrywa Task 11.)
- [ ] **Step 2:** FAIL → **Step 3:** Implementacja (scriptowalne dublety w `OrderingWorld`) → **Step 4:** `php artisan test --compact tests/Unit/Ordering tests/Arch` PASS; `pint`
- [ ] **Step 5: Commit** `Zamówienia: process manager, kompensacje i wyścigi R1–R10`

---

### Task 9: Infrastruktura sagi: adaptery, joby, sweep, scheduler

**Files:**
- Create: `src/Ordering/Infrastructure/{SellerPortalStockReservations, DbOfferSnapshots, DbSellerAccounts, LaravelProcessJobQueue, LaravelTransactionManager, SystemClock, InProcessDomainEventDispatcher}.php`, `src/Ordering/Infrastructure/Job/*`, `app/Providers/OrderingServiceProvider.php` (w `bootstrap/providers.php`), `app/Console/Commands/ExpireOverdueOrdersCommand.php`
- Modify:
  - `config/queue.php` (`'ordering_process_connection' => env('ORDERING_PROCESS_QUEUE_CONNECTION', 'database')`),
  - `routes/console.php`,
  - `app/Http/Controllers/HealthController.php`,
  - `compose.yaml` (usługa `catalog-scheduler`),
  - `Makefile` (`scheduler-logs`),
  - `.env.example`
- Test: `tests/Feature/Ordering/Infrastructure/{StockReservationsAdapterTest, ProcessJobsTest, ExpireOverdueOrdersCommandTest, OrderingWiringTest}.php`

**Interfaces:**
- Consumes: Task 4 (komendy portalu), Task 6–8.
- Produces:
  - `SellerPortalStockReservations implements StockReservations`: woła handlery z Task 4 i mapuje wyjątki:
    - `InsufficientStock` → `OutOfStock`,
    - `ReservationPriceMismatch` → `PriceChanged`,
    - `OfferNotReservable`, `SellerCannotSell`, `OfferNotFound` → `OfferUnavailable`.

    `ReservationConflict` i inne lecą dalej (błąd programisty).
  - `DbOfferSnapshots`: jedno zapytanie (`offers` + `sellers.user_id` + `products.name`), `listed` przez scope `listed()`.
  - `LaravelProcessJobQueue`: `dispatch(...)->onConnection(config('queue.ordering_process_connection'))`; docblock jak `LaravelPolicyJobQueue` (atomowość z transakcją, `after_commit = false`).
  - Joby `ReserveOrderLinesJob(int $orderId)`, `ReleaseOrderReservationsJob(int $orderId)`, `ConfirmOrderReservationsJob(int $orderId)`, `RefundPaymentJob(int $orderId, string $refundId)`: `ShouldQueue`, `$tries = 5`, `$backoff = [5, 30, 120]`, `handle()` woła krok z Task 8.
  - `OrderingServiceProvider`:
    - bindy portów,
    - `scoped` dispatcher z rejestracją słuchaczy `OrderProcessManager` w fabryce (wzorzec `SellerPortalServiceProvider`, Octane),
    - `OrderRepository` → `SqlOrderRepository`.
  - `orders:expire-overdue`: woła `ExpireOverdueOrdersHandler`, wypisuje liczbę, zapisuje `Cache::put('ordering:sweep:last_run_at', now()->toIso8601String())`. W `routes/console.php`: `Schedule::command('orders:expire-overdue')->everyMinute()->withoutOverlapping()`.
  - `/health`: pole `ordering_sweep_last_run_at` (null, gdy brak), bez wpływu na status HTTP.
  - `catalog-scheduler` w `compose.yaml`: kopia `catalog-worker` z `command: ["php", "artisan", "schedule:work"]`, healthcheck `grep -q 'schedule:work' /proc/1/cmdline`, `mem_limit: 128m`. Komentarz: bez tego procesu zamówienia nie wygasają, a towar zostaje zarezerwowany (RUNBOOK, Task 16).

- [ ] **Step 1: Testy (failing)**

```php
test('adapter tłumaczy wyjątki portalu na ReservationResult', ...);    // dataset 4 przypadków + Reserved
test('złożenie zamówienia wstawia job w tej samej transakcji', ...);   // ORDERING_PROCESS_QUEUE_CONNECTION=database, config override; jobs ma 1 wiersz
test('wycofana transakcja nie zostawia joba', ...);                    // wyjątek w dispatcherze po zapisie → orders i jobs puste
test('saga na bazie: złożone → AwaitingPayment, oferta ma rezerwację', ...); // sync
test('confirm po płatności zmniejsza stan w bazie, ponowienie joba nic nie zmienia', ...);
test('orders:expire-overdue szanuje karencję i zapisuje czas przebiegu', ...); // travelTo
test('provider rozwiązuje wszystkie porty i handlery', ...);
```

- [ ] **Step 2:** FAIL → **Step 3:** Implementacja + infra
- [ ] **Step 4:** PASS; `docker compose --profile apps config -q` bez błędów; `pint`
- [ ] **Step 5:** `docker compose --profile apps up -d catalog-scheduler` → `docker compose ps catalog-scheduler` = healthy (nie zatrzymywać cudzych kontenerów)
- [ ] **Step 6: Commit** `Zamówienia: adaptery sagi, joby w kolejce database, sweep i kontener schedulera`

---

### Task 10: Symulowana bramka płatności i webhook (ACL)

**Files:**
- Create:
  - `app/FakePaymentGateway/{FakePaymentGateway, Http/FakeGatewayController, Jobs/SendPaymentWebhookJob}.php`,
  - `database/migrations/2026_10_16_100002_create_fake_gateway_tables.php`,
  - `src/Ordering/Infrastructure/FakeGatewayPaymentGateway.php`, `src/Ordering/Application/Port/PaymentGatewayUnavailable.php`,
  - `app/Http/Controllers/Webhooks/PaymentWebhookController.php`,
  - `resources/js/pages/fake-gateway/Payment.vue`
- Modify:
  - `config/services.php` (`fake_gateway`: `webhook_secret`, `webhook_url`, `webhook_delay_seconds` = 2, `fail_first_refund` = false),
  - `routes/web.php`, `bootstrap/app.php` (wyłączenie CSRF dla `webhooks/*`),
  - `compose.yaml` (env `FAKE_GATEWAY_*` dla `catalog-app` i `catalog-worker`; `FAKE_GATEWAY_WEBHOOK_URL` na wewnętrzny adres `catalog-app` w sieci `marketplace`, z portem z jego definicji),
  - `.env.example`
- Test: `tests/Feature/FakePaymentGateway/FakeGatewayTest.php`, `tests/Feature/Ordering/Http/PaymentWebhookTest.php`

**Interfaces:**
- Consumes: Task 7 (`PaymentGateway`, komendy `RecordPayment`/`RecordPaymentFailure`).
- Produces:
  - Tabele:
    - `fake_gateway_payments`: `id` (ULID), `reference`, `amount_cents`, `currency`, `status` `pending|succeeded|failed`, `expires_at`, `return_url`, `decided_at` null,
    - `fake_gateway_refunds`: `idempotency_key` (unikalny), `payment_id`, `amount_cents`, `status`, `attempts`.
  - `FakePaymentGateway`:
    - `createPayment(string $reference, int $amountCents, string $currency, DateTimeImmutable $expiresAt, string $returnUrl): array{payment_id: string, url: string}`,
    - `decide(string $paymentId, string $action): void` (`pay|decline|resend|pay_expired`; `pay` po `expires_at` → `SessionExpired`; `resend` i `pay_expired` tylko gdy `app()->environment(['local','testing'])`, inaczej 404),
    - `refund(string $paymentId, int $amountCents, string $idempotencyKey): void` (ten sam klucz → ten sam wynik; `fail_first_refund` → pierwsza próba danego klucza rzuca `RuntimeException`),
    - `signedWebhook(string $paymentId): array{body: string, signature: string}` (używane przez job i testy).
  - `SendPaymentWebhookJob(string $paymentId)`: `Http::withBody(body)->withHeaders(['X-Signature' => …])->post(webhook_url)`, ≠ 2xx → `release` z backoffem `[2, 10, 30]`, `$tries = 10`. Kolejkowany z `->delay(webhook_delay_seconds)`.
  - Trasy:
    - `GET /fake-gateway/payments/{payment}` (`fake-gateway.show`),
    - `POST /fake-gateway/payments/{payment}/decide` (`fake-gateway.decide`) → redirect na `return_url`,
    - `POST /webhooks/payments` (`webhooks.payments`).
  - `PaymentWebhookController`: `hash_equals` podpisu (zły → 401, stan bez zmian); `reference` w formacie `order-{id}` → `RecordPayment` / `RecordPaymentFailure`; nieznana `reference` albo `OrderNotFound` → 200 + `Log::warning`; zawsze 200 po przetworzeniu (także duplikat).
  - `FakeGatewayPaymentGateway implements PaymentGateway`: mapuje na `FakePaymentGateway` (`reference = order-{id}`); `RuntimeException` → `PaymentGatewayUnavailable`.

- [ ] **Step 1: Testy (failing)**

```php
test('webhook ze złym podpisem → 401, zamówienie bez zmian', ...);
test('webhook succeeded → zamówienie Paid', ...);
test('zdublowany webhook → 200, jedna wpłata, żadnego zwrotu', ...);    // R4
test('webhook dla nieznanego zamówienia → 200 i ostrzeżenie w logu', ...);
test('bramka nie przyjmie wpłaty po wygaśnięciu sesji', ...);           // travelTo po expires_at, decide(pay) → SessionExpired; strona pokazuje „sesja wygasła”
test('„zapłać mimo wygaśnięcia” daje wpłatę po terminie i zwrot', ...); // H6, środowisko testing
test('przyciski deweloperskie zwracają 404 poza local/testing', ...);
test('zwrot z tym samym kluczem jest wykonywany raz', ...);
test('fail_first_refund: pierwsza próba rzuca, druga przechodzi', ...);
```

- [ ] **Step 2:** FAIL → **Step 3:** Implementacja (strona `Payment.vue`: kwota, odbiorca „Marketplace”, termin, przyciski; dev-sekcja wyraźnie oznaczona „tylko środowisko deweloperskie”)
- [ ] **Step 4:** PASS; `php artisan wayfinder:generate --with-form`; frontend checks; `pint`
- [ ] **Step 5: Commit** `Zamówienia: symulowana bramka płatności i podpisany webhook (ACL)`

---

### Task 11: Strona produktu, koszyk, składanie zamówień

**Files:**
- Create:
  - `database/migrations/2026_10_16_100003_create_cart_items_table.php`, `app/Models/CartItem.php`,
  - `src/Ordering/Infrastructure/{EloquentBuyerCart, ReadModel/CartView}.php`,
  - `app/Http/Controllers/Shop/{ProductPageController, CartController}.php`, `app/Http/Requests/Shop/{AddToCartRequest, UpdateCartItemRequest, CheckoutRequest}.php`,
  - `resources/js/pages/shop/{Product,Cart}.vue`, `resources/js/types/orders.ts`
- Modify:
  - `routes/web.php`,
  - `resources/js/components/search/ProductCard.vue` (link do `/products/{id}`),
  - `resources/js/components/AppSidebar.vue` (link „Koszyk (N)” obok „Moje zamówienia”; `HandleInertiaRequests` shared `cart.count`, closure),
  - `app/Http/DomainErrorMessages.php`, `bootstrap/app.php` (render wyjątków Orderingu jak w portalu; `OrderNotFound` → 404)
- Test: `tests/Feature/Ordering/Http/{ProductPageTest, CartTest, CheckoutTest}.php`

**Interfaces:**
- Consumes: Task 3 (scope `listed`/`withAvailable`), Task 7 (`PlaceOrdersFromCart`), Task 9 (wiring).
- Produces:
  - `cart_items(id, user_id FK, offer_id FK, quantity, created_at, updated_at)`, unikalny `(user_id, offer_id)`.
  - Trasy (middleware `auth`; `/products/{product}` publiczne, przycisk koszyka wymaga logowania):
    - `GET /products/{product}` (`products.show`),
    - `GET /cart` (`cart.index`),
    - `POST /cart/items` (`cart.items.store`: `offer_id`, `quantity`),
    - `PATCH /cart/items/{offer}` (`cart.items.update`),
    - `DELETE /cart/items/{offer}` (`cart.items.destroy`),
    - `POST /cart/checkout` (`cart.checkout`: `lines[].offer_id`, `lines[].quantity`, `lines[].unit_price_cents`) → redirect `/orders` z flashem „Złożono N zamówień”.
  - `CartView::forBuyer(int $userId): array` z grupami per sprzedawca:
    - na grupę: `seller {id, name}`, `subtotal_cents`,
    - na pozycję: `offer_id`, `product_name`, `unit_price_cents`, `quantity`, `available`, `status` (`ok|price_changed|unavailable`), `added_price_cents`.

    `cart_items` dostaje `added_price_cents`, żeby pokazać „cena zmieniona z X na Y”.
  - Walidacja dodawania (Review Focus 2): suma ilości (istniejąca + nowa) ≤ `min(10, available)`, oferta `listed`, nie własna (`cannot_buy_own_offer`) — inaczej błąd pola `quantity`/`offer_id`, koszyk bez zmian.
  - Checkout: `PriceChangedSinceCart` / `OfferNoLongerAvailable` → `back()->withErrors(['cart' => komunikat])`, a strona koszyka (świeży `CartView`) oznacza pozycje (Review Focus 1). Pusty koszyk (R9, drugi klik) → redirect `/orders` bez błędu.

- [ ] **Step 1: Testy (failing)**

```php
test('strona produktu pokazuje tylko widoczne oferty z dostępną ilością', ...);
test('dodanie tej samej oferty dwa razy sumuje ilość', ...);
test('ilość ponad min(10, dostępne) to błąd walidacji, koszyk bez zmian', ...);
test('sprzedawca nie doda do koszyka własnej oferty', ...);
test('koszyk grupuje pozycje per sprzedawca', ...);
test('zmieniona cena oznacza pozycję i blokuje cały checkout', ...);   // brak zamówień, errors.cart
test('wycofana oferta oznacza pozycję jako niedostępną i blokuje checkout', ...);
test('checkout z dwoma sprzedawcami: dwa zamówienia, pusty koszyk, flash', ...); // sync: oba AwaitingPayment
test('drugi checkout z pustym koszykiem przekierowuje bez błędu', ...); // R9
test('gość dostaje przekierowanie do logowania', ...);
```

- [ ] **Step 2:** FAIL → **Step 3:** Implementacja → **Step 4:** PASS; wayfinder; frontend checks; `pint`
- [ ] **Step 5: Commit** `Zamówienia: strona produktu, koszyk i składanie zamówień`

---

### Task 12: Zamówienia kupującego (HTTP + frontend)

**Files:**
- Create:
  - `app/Http/Controllers/Shop/OrderController.php`,
  - `src/Ordering/Infrastructure/ReadModel/{BuyerOrderList, BuyerOrderDetails}.php`,
  - `resources/js/pages/shop/orders/{Index,Show}.vue`,
  - `resources/js/components/orders/{OrderStatusBadge, OrderTimeline, PaymentCountdown}.vue`
- Modify: `routes/web.php`, `resources/js/types/orders.ts`, `resources/js/components/AppSidebar.vue` („Moje zamówienia” dla zalogowanego)
- Test: `tests/Feature/Ordering/Http/BuyerOrdersTest.php`

**Interfaces:**
- Consumes: Task 7 (`StartPayment`, `CancelOrderByBuyer`), Task 10 (bramka), Task 11.
- Produces:
  - Trasy (`auth`):
    - `GET /orders` (`orders.index`), `GET /orders/{order}` (`orders.show`),
    - `POST /orders/{order}/pay` (`orders.pay`) → `Inertia::location(redirectUrl)`, `returnUrl = route('orders.show', [$order, 'payment' => 'returned'])`,
    - `POST /orders/{order}/cancel` (`orders.cancel`).
  - Props `orders.show`:
    - `order {id, status, status_label, seller, lines[], total_cents, payment_deadline, seconds_to_deadline, refunds[], tracking_number, rejection_label, cancellation_label}`,
    - `actions` (z `allowedActionsFor(Buyer)`), `timeline[]` (z `order_events`),
    - `awaiting_confirmation: bool` (= `?payment=returned` i status `AwaitingPayment`).
  - Etykiety statusów po polsku (jedno miejsce, `OrderStatusBadge.vue`):
    - `placed` „Sprawdzamy dostępność”, `awaiting_payment` „Czeka na płatność”, `paid` „Opłacone”, `shipped` „Wysłane”,
    - `rejected` „Odrzucone”, `expired` „Wygasło”, `cancelled` „Anulowane”.
  - Powody odrzucenia:
    - `out_of_stock` „Zabrakło towaru: {produkt}”,
    - `price_changed` „Cena się zmieniła: {produkt}”,
    - `offer_unavailable` „Oferta niedostępna: {produkt}”.
  - Zwrot: „Zwrot {kwota} — w toku / zwrócono”.
  - Polling (`usePoll` co 1000 ms): przy `placed` oraz przez 30 s przy `awaiting_confirmation` (wtedy zamiast „Zapłać” baner „Czekamy na potwierdzenie płatności”). `PaymentCountdown` liczy od `seconds_to_deadline` (Review Focus 3, 4).

- [ ] **Step 1: Testy (failing)**

```php
test('lista pokazuje tylko własne zamówienia kupującego', ...);
test('cudze zamówienie → 404', ...);
test('pełna ścieżka: checkout → zapłać → webhook → Opłacone', ...);    // pay zwraca 409 Inertia location z URL bramki; signedWebhook → POST webhooks.payments
test('powrót z bramki przed webhookiem: awaiting_confirmation i brak akcji pay', ...); // Review Focus 3
test('seconds_to_deadline liczone na serwerze', ...);                  // travelTo(+10 min) → 300
test('anulowanie przed płatnością zwalnia rezerwacje', ...);           // offer.available wraca
test('odrzucone zamówienie pokazuje powód z nazwą produktu', ...);
test('wpłata po terminie pokazuje zwrot', ...);
```

- [ ] **Step 2:** FAIL → **Step 3:** Implementacja → **Step 4:** PASS; wayfinder; frontend checks; `pint`
- [ ] **Step 5: Commit** `Zamówienia: strony zamówień kupującego, płatność i anulowanie`

---

### Task 13: Zamówienia sprzedawcy (HTTP + frontend)

**Files:**
- Create:
  - `app/Http/Controllers/Seller/SellerOrderController.php`, `app/Http/Requests/Seller/{ShipOrderRequest, CancelOrderRequest}.php`,
  - `src/Ordering/Infrastructure/ReadModel/{SellerOrderList, SellerOrderDetails}.php`,
  - `resources/js/pages/seller/orders/{Index,Show}.vue`
- Modify:
  - `routes/web.php` (grupa `seller`, `seller.approved`),
  - `src/SellerPortal/Infrastructure/ReadModel/SellerDashboardStats.php` (licznik `orders_to_ship`; zapytanie po `orders` w infrastrukturze odczytu, bez importu z `Ordering`),
  - `resources/js/pages/seller/Dashboard.vue`, nawigacja portalu
- Test: `tests/Feature/Ordering/Http/SellerOrdersTest.php`

**Interfaces:**
- Consumes: Task 7 (`ShipOrder`, `CancelOrderBySeller`), Task 12 (komponenty statusu).
- Produces:
  - Trasy:
    - `GET /seller/orders?tab=to_ship|all` (`seller.orders.index`), `GET /seller/orders/{order}` (`seller.orders.show`),
    - `POST /seller/orders/{order}/ship` (`seller.orders.ship`: `tracking_number`),
    - `POST /seller/orders/{order}/cancel` (`seller.orders.cancel`: `reason`).
  - Walidacja `tracking_number`: `required|string`, po trim 3–64 (Review Focus 5); `reason`: po trim 1–500. Błędy przy polu, stan bez zmian.
  - Komunikat potwierdzenia anulowania w UI: „Kupujący dostanie zwrot {kwota}. Stan oferty nie wróci automatycznie.”

- [ ] **Step 1: Testy (failing)**

```php
test('„Do wysłania” pokazuje tylko opłacone zamówienia tego sprzedawcy', ...);
test('wysyłka z numerem przesyłki → Wysłane', ...);
test('numer przesyłki ze spacji, pusty albo za długi → błąd pola, zamówienie Opłacone', ...); // Review Focus 5
test('anulowanie opłaconego zleca zwrot i nie przywraca stanu', ...); // H8; offer.stock bez zmian
test('cudze zamówienie → 404', ...);
test('pulpit pokazuje licznik do wysłania', ...);
```

- [ ] **Step 2:** FAIL → **Step 3:** Implementacja → **Step 4:** PASS; wayfinder; frontend checks; `pint`
- [ ] **Step 5: Commit** `Zamówienia: zamówienia sprzedawcy (wysyłka, anulowanie ze zwrotem)`

---

### Task 14: Test współbieżności `orders:race-test`

**Files:**
- Create: `app/Console/Commands/OrdersRaceTestCommand.php`
- Test: `tests/Feature/Ordering/Infrastructure/OrdersRaceTestCommandTest.php` (tylko odmowa na SQLite i raport w trybie `--dry-run`)

**Interfaces:**
- Consumes: całość Orderingu.
- Produces: `php artisan orders:race-test {--buyers=50} {--stock=10} {--workers=4} {--keep}`.
  - Odmawia na sterowniku innym niż `pgsql` (kod wyjścia 1, komunikat).
  - Fixture: sprzedawca, oferta `Published` ze stanem `--stock`, `--buyers` kupujących (e-maile `race-{run}-{n}@example.test`).
  - Uruchamia równolegle (`Symfony\Component\Process\Process`, już w vendorze przez Laravel) `--buyers` procesów `php artisan orders:race-test:buyer {userId} {offerId}`: komenda ukryta, woła `PlaceOrdersFromCart` i mierzy czas.
  - Uruchamia `--workers` procesów `queue:work database --stop-when-empty`.
  - Dla każdego `AwaitingPayment` woła `RecordPayment`, potem znowu workery.
  - Raport (tabela):
    - opłacone, odrzucone, w innym statusie, `offer.stock` końcowy, aktywne rezerwacje,
    - overselling = opłacone − `--stock` (> 0 to błąd),
    - konflikty wersji (licznik w logu kroku), p50/p95 złożenia.
  - Kod wyjścia 1, gdy opłacone ≠ `--stock` albo overselling > 0.
  - Sprzątanie fixture, chyba że `--keep`.

- [ ] **Step 1:** Test (failing) → **Step 2:** FAIL → **Step 3:** Implementacja → **Step 4:** PASS; `pint`
- [ ] **Step 5:** W kontenerze (główny checkout; `catalog-worker` może działać równolegle, bo też tylko wykonuje kroki sagi): `docker compose exec catalog-app php artisan orders:race-test`. Oczekiwane: opłacone 10, overselling 0, kod 0. Wynik do `docs/POMIARY.md` (sekcja „Zamówienia: współbieżność”).
- [ ] **Step 6: Commit** `Zamówienia: orders:race-test i pomiar współbieżności`

---

### Task 15: Weryfikacja end-to-end na lokalnym stacku

**Files:**
- Modify: `docs/POMIARY.md` (czas od rezerwacji ostatniej sztuki do zniknięcia z `/search`)

- [ ] **Step 1:** Główny checkout, `make up-apps`; `docker compose ps` pokazuje healthy `catalog-app`, `catalog-worker`, `catalog-scheduler`, `outbox-publisher`, `search-consumer`.
- [ ] **Step 2:** W przeglądarce (wbudowana), konto kupującego i dwóch sprzedawców z seedów (`make admin` dla moderatora, gdy potrzebny). Każdy punkt odhacz z obserwacją:
  1. Dwie oferty różnych sprzedawców → koszyk → 2 zamówienia → zapłata obu → u sprzedawców „Do wysłania” → wysyłka (Kryterium 1).
  2. Oferta ze stanem 1: rezerwacja → oferta znika z `/search` (zmierz czas); anulowanie → wraca (Kryterium 2).
  3. Dwie pozycje, druga z dostępnym < zamawianym → Odrzucone; dostępność pierwszej wraca (Kryterium 3).
  4. Zmiana ceny przez sprzedawcę po dodaniu do koszyka → checkout odmawia z różnicą (Kryterium 4).
  5. Zamówienie `AwaitingPayment` → `make psql db=catalog`: `UPDATE orders SET payment_deadline = now() - interval '6 minutes' WHERE id = …` → poczekaj na przebieg schedulera (≤ 1 min) → Wygasło; potem w bramce „Zapłać mimo wygaśnięcia” → zwrot widoczny u kupującego; „Wyślij webhook ponownie” → bez zmian (Kryterium 5).
  6. Sprzedawca próbuje zmniejszyć stan poniżej zarezerwowanego → komunikat (Kryterium 6).
- [ ] **Step 3:** Pełny zestaw testów na hoście: `php artisan test --compact` → PASS; zanotuj liczbę i czas.
- [ ] **Step 4: Commit** `Zamówienia: weryfikacja end-to-end i pomiary`

---

### Task 16: Dokumentacja nauki i przewodnik

**Files:**
- Create:
  - `docs/adr/0005-rezerwacje-w-ofercie.md` (aktualizuje 0004; w 0004 dopisz „Zaktualizowana przez ADR-0005”),
  - `docs/adr/0006-process-manager-ze-stanem-w-zamowieniu.md`, `docs/adr/0007-shared-kernel.md`,
  - `docs/12-ZAMOWIENIA-SLOWNIK.md`,
  - `docs/blog/etap-f-zamowienia-saga.md`
- Modify:
  - `docs/11-SELLER-PORTAL-SLOWNIK.md` („rezerwacja”, „zarezerwowane”, „dostępne”),
  - `docs/10-DDD.md` (moduł 1, ćwiczenie 2 → odsyłacz do sekcji 1 specu; nowy **moduł 11 „Process manager i eventual consistency”** i **moduł 12 „Kompensacja i idempotencja”** w formacie istniejących modułów: Cel, Teoria, W projekcie z `plik:linia`, Ćwiczenia, Sprawdź się; mapa kontekstów w module 3 z nowymi relacjami),
  - `docs/RUNBOOK.md` (nowe wpisy: kontener schedulera; „zamówienie wisi w Sprawdzamy dostępność”; `failed_jobs` sagi i ręczne ponowienie; przyciski deweloperskie bramki),
  - `README.md` (tabela statusu etapów)

- [ ] **Step 1:** ADR-y w formacie istniejących (Status, Data, Kontekst decyzji, Kontekst, Decyzja, Rozważane opcje, Konsekwencje). W 0005 liczby z Task 14–15.
- [ ] **Step 2:** Słownik 1:1: każde słowo z UI zamówień → symbol w kodzie (statusy, „Sprawdzamy dostępność” = `OrderStatus::Placed`, „dostępne” = `Offer::available()`).
- [ ] **Step 3:** Moduły 11–12 z co najmniej trzema ćwiczeniami „zepsuj i napraw”, **każde wykonane naprawdę**, z listą czerwonych testów z przebiegu:
  - usuń gałąź R1 w `ReserveOrderLines` → czerwony `OrderRacesTest` › R1,
  - usuń sprawdzenie `reservationStatus` w `ConfirmReservationHandler` → czerwony „ConfirmReservation drugi raz to no-op”,
  - przenieś `release` z `onCancelled` także na anulowanie sprzedawcy → czerwony „anulowanie przez kupującego zwalnia, przez sprzedawcę nie”.
- [ ] **Step 4:** Przewodnik krok po kroku (styl `etap-e-portal-sprzedawcy-ddd.md`): od Event Stormingu, przez rezerwacje i agregat, sagę i wyścigi, bramkę, UI, po race-test; jak uruchomić i co zobaczyć.
- [ ] **Step 5:** Linki działają (`grep -o '](\([^)]*\))' … | sprawdź pliki`), odwołania `plik:linia` wskazują istniejące linie.
- [ ] **Step 6: Commit** `Zamówienia: ADR-y, słownik, moduły 11–12 i przewodnik`

---

### Task 17: Gałąź `zamowienia-ddd-lite` i porównanie

**Files (na gałęzi `zamowienia-ddd-lite` z `main` po Task 16):**
- Delete: `src/Ordering/{Domain,Application}`, `src/Ordering/Infrastructure/{Persistence,Job}`, `tests/Unit/Ordering`, `tests/Arch/OrderingLayersTest.php`
- Create:
  - `app/Models/Order.php` (+ `OrderLine`, `OrderRefund`) z metodami reguł (`markPaid`, `recordPayment`, `expire`, `cancelByBuyer`, `cancelBySeller`, `ship`), enum statusu jako cast,
  - `app/Services/Ordering/{PlaceOrders, OrderPayments, OrderRefunds}.php`,
  - `tests/Feature/Lite/OrderRulesTest.php` (te same przypadki co testy domeny `Order`, na bazie)
- Modify: kontrolery `Shop/*`, `Seller/SellerOrderController`, `Webhooks/PaymentWebhookController` wołają serwisy lite; `ExpireOverdueOrdersCommand`
- Unchanged (wymóg): `tests/Feature/Ordering/Http/*`, `tests/Feature/FakePaymentGateway/*`, Seller Portal, strony Vue
- Create na `main`: `docs/blog/saga-vs-transakcja.md`

**Interfaces:**
- `PlaceOrders::fromCart(User $buyer, array $lines): Collection<Order>`:
  1. `DB::transaction`;
  2. `Offer::query()->whereIn('id', …)->orderBy('id')->lockForUpdate()->get()` (kolejność blokad przeciw deadlockom);
  3. te same odmowy co w Task 7;
  4. dla każdej pozycji `ReserveStockHandler` z portalu (przechodzi bez konfliktu, bo wiersz jest zablokowany);
  5. zamówienia od razu `awaiting_payment` albo cała transakcja się wycofuje z odmową (`OutOfStock` → komunikat w koszyku).

  Płatność, wygaśnięcie, zwrot: joby i sweep bez process managera.

- [ ] **Step 1:** `git switch -c zamowienia-ddd-lite`
- [ ] **Step 2:** Przepisz; `git diff main -- apps/catalog/tests/Feature/Ordering/Http apps/catalog/tests/Feature/FakePaymentGateway` → pusty
- [ ] **Step 3:** `php artisan test --compact` → PASS
- [ ] **Step 4:** Pomiary obu gałęzi:
  - `find … | xargs wc -l` per warstwa,
  - czas testów reguł zamówienia (`--profile`),
  - miejsca obejścia reguł (`grep -rn "Order::query()\|->update(\|->save()" app src`),
  - `orders:race-test` na obu (w kontenerze, główny checkout przełączony na gałąź na czas pomiaru, potem z powrotem na `main`),
  - awaria w połowie: ubity worker w trakcie `ReserveOrderLinesJob` (main) kontra ubite żądanie w trakcie transakcji (lite); stan po restarcie,
  - koszt nowej reguły „kupujący ma najwyżej 3 nieopłacone zamówienia naraz” na gałęzi pomiarowej `zamowienia-pomiar-reguly` z każdej wersji: `git diff --stat` i liczba zmienionych testów, potem gałęzie pomiarowe zostają lokalnie.
- [ ] **Step 5:** Commit na gałęzi `Zamówienia (DDD-lite): jedna transakcja z blokadami zamiast sagi`; push dopiero po zgodzie użytkownika
- [ ] **Step 6:** `git switch main`; `docs/blog/saga-vs-transakcja.md`: tabela zmierzonych liczb, diffy nowej reguły, wyniki race-testu i awarii, „kiedy który styl” (odnieść się do werdyktu z `ddd-vs-ddd-lite.md`: potwierdzony czy nie); link w `10-DDD.md` moduł 10 i w przewodniku
- [ ] **Step 7: Commit** `Zamówienia: porównanie sagi i jednej transakcji (DDD vs DDD-lite)`
