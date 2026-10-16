# Zamówienia: saga, rezerwacje i kompensacje, krok po kroku

*ETAP F projektu Marketplace Search Platform. Kupujący dodaje do koszyka
oferty kilku sprzedawców, składa zamówienia (jedno na sprzedawcę), towar
zostaje zarezerwowany w ofertach, kupujący płaci w symulowanej bramce,
a sprzedawca wysyła albo anuluje ze zwrotem pieniędzy. Portal sprzedawcy
(ETAP E) uczył DDD na regułach **jednego** agregatu. Ten etap uczy reguł,
które obejmują **kilka agregatów naraz**: process manager (saga), spójność
ostateczna, kompensacja, idempotencja i wyścigi.*

> **Stan:** kod zrobiony lokalnie na gałęzi `zamowienia` (Taski 1–14 planu,
> kroki 1–4 Taska 14). Testy na hoście zielone: domena Orderingu 80 testów
> w 41 ms, domena + aplikacja 122 testy w 48 ms, `tests/Feature/Ordering`
> 144 testy, cały catalog 740 testów (725 zielonych, 15 pominiętych).
> **Jeszcze nie zrobione:** uruchomienie na żywym stacku. Kontener
> `catalog-app` montuje główny checkout, więc E2E (Task 15) i
> `orders:race-test` na Postgresie (Task 14, krok 5) czekają na merge
> `zamowienia` do `main`, który musi zatwierdzić użytkownik. Gałąź porównawcza
> `zamowienia-ddd-lite` (Task 17) jest zrobiona lokalnie, porównanie:
> [`saga-vs-transakcja.md`](saga-vs-transakcja.md). Wdrożenie na k3s jeszcze nie istnieje.
> Wszystkie miejsca na liczby z pomiarów są oznaczone „Do uzupełnienia po
> pomiarze”; żadna liczba w tym przewodniku nie jest zgadnięta.
>
> Moduły nauki: [`10-DDD.md`](../10-DDD.md), moduły 11 (process manager
> i spójność ostateczna) i 12 (kompensacja i idempotencja), oba z prawdziwymi
> „zepsuj i napraw”. Słownik: [`12-ZAMOWIENIA-SLOWNIK.md`](../12-ZAMOWIENIA-SLOWNIK.md).
> Decyzje: [ADR-0006](../adr/0006-rezerwacje-w-ofercie.md),
> [ADR-0007](../adr/0007-process-manager-ze-stanem-w-zamowieniu.md),
> [ADR-0008](../adr/0008-shared-kernel.md). Spec:
> [`superpowers/specs/2026-10-16-zamowienia-ddd-design.md`](../superpowers/specs/2026-10-16-zamowienia-ddd-design.md),
> plan: [`superpowers/plans/2026-10-16-zamowienia-ddd.md`](../superpowers/plans/2026-10-16-zamowienia-ddd.md).

---

## Spis treści

1. [Dlaczego ten etap](#1-dlaczego-ten-etap)
2. [Najpierw Event Storming, potem spec](#2-najpierw-event-storming-potem-spec)
3. [Mapa: co powstało i gdzie](#3-mapa-co-powstało-i-gdzie)
4. [Jak czytać ten przewodnik i odtwarzać kroki](#4-jak-czytać-ten-przewodnik-i-odtwarzać-kroki)
5. [Krok 1 — Shared Kernel](#krok-1--shared-kernel)
6. [Krok 2 — Rezerwacje w agregacie Offer](#krok-2--rezerwacje-w-agregacie-offer)
7. [Krok 3 — Komendy rezerwacji i „zarezerwowane” w UI sprzedawcy](#krok-3--komendy-rezerwacji-i-zarezerwowane-w-ui-sprzedawcy)
8. [Krok 4 — Agregat Order](#krok-4--agregat-order)
9. [Krok 5 — Persystencja zamówień i dziennik zdarzeń](#krok-5--persystencja-zamówień-i-dziennik-zdarzeń)
10. [Krok 6 — Warstwa aplikacji: składanie, płatność, anulowanie](#krok-6--warstwa-aplikacji-składanie-płatność-anulowanie)
11. [Krok 7 — Process manager, kompensacje i wyścigi](#krok-7--process-manager-kompensacje-i-wyścigi)
12. [Krok 8 — Infrastruktura sagi: joby, sweep, scheduler](#krok-8--infrastruktura-sagi-joby-sweep-scheduler)
13. [Krok 9 — Symulowana bramka i webhook (ACL)](#krok-9--symulowana-bramka-i-webhook-acl)
14. [Krok 10 — Strona produktu, koszyk, składanie](#krok-10--strona-produktu-koszyk-składanie)
15. [Krok 11 — Zamówienia kupującego](#krok-11--zamówienia-kupującego)
16. [Krok 12 — Zamówienia sprzedawcy](#krok-12--zamówienia-sprzedawcy)
17. [Krok 13 — orders:race-test](#krok-13--ordersrace-test)
18. [Krok 14 — Jak uruchomić i co zobaczyć](#krok-14--jak-uruchomić-i-co-zobaczyć)
19. [Znane ograniczenia (świadomie odłożone)](#znane-ograniczenia-świadomie-odłożone)
20. [Bilans na dziś](#bilans-na-dziś)
21. [Ściąga](#ściąga)
22. [Twoja kolej](#twoja-kolej)

---

## 1. Dlaczego ten etap

W portalu sprzedawcy każda reguła mieściła się w jednym agregacie: zmiana
ceny to `Offer`, blokada to `Seller`, a jedyna reguła między nimi
(„zablokowany sprzedawca nie sprzedaje”) była prostą polityką: job po
blokadzie zawiesza oferty. Zamówienia tak nie działają. Jedno zdanie
biznesu, „złożone zamówienie trzyma towar”, dotyczy zamówienia **i** kilku
ofert, a dalej proces trwa w czasie: płatność przychodzi z zewnątrz
(albo nie przychodzi), termin mija, sprzedawca anuluje po godzinie.
Każdy krok może paść w połowie albo przyjść dwa razy.

Pragmatyczny zespół zrobiłby to w jednej transakcji z `lockForUpdate` na
ofertach. To działa i ten wariant powstanie na gałęzi porównawczej (Task 17).
Tutaj świadomie robimy wersję „podręcznikową”: każdy agregat we własnej
transakcji, a spina je process manager. Nie dlatego, że jest lepsza, tylko
dlatego, że uczy rzeczy, których jedna transakcja nie uczy: co widzi
użytkownik „pomiędzy”, jak sprząta się po kroku, który już się wydarzył,
i co się dzieje, gdy ten sam krok wykona się dwa razy naraz.

---

## 2. Najpierw Event Storming, potem spec

W portalu Event Storming był rekonstrukcją po fakcie (moduł 1 `10-DDD.md`).
Tu **warsztat odbył się przed specem** i jest jego pierwszą sekcją
([spec, sekcja 1](../superpowers/specs/2026-10-16-zamowienia-ddd-design.md#1-event-storming-kupujący-zamawia-z-oferty)).
Najważniejsze, co z niego wyszło:

- **Trzy przełomy na osi czasu:** zamówienie złożone (ceny zamrożone, towar
  trzymany), zamówienie opłacone (rezerwacja staje się sprzedażą), paczka
  wysłana (od tej chwili tylko zwroty towaru, inny proces).
- **„Zarezerwuj” daje kilka różnych zdarzeń** (zarezerwowano, brak towaru,
  cena się zmieniła, oferta niedostępna). Kto decyduje, musi znać stan
  magazynu, więc decyduje **oferta**, nie zamówienie. Stąd rezerwacje
  w `Offer` ([ADR-0006](../adr/0006-rezerwacje-w-ofercie.md)).
- **Dwanaście hot spotów (H1–H12)**, każdy z miejscem w kodzie. Przykłady:
  H1 „kiedy zmniejszamy `stock`?” (przy złożeniu rezerwujemy, przy płatności
  sprzedajemy), H6 „wpłata po terminie” (zwrot, nie ratowanie zamówienia),
  H8 „sprzedawca anuluje opłacone: stan wraca?” (nie), H10 „duplikaty”
  (każdy krok idempotentny), H11 „zablokowany sprzedawca z opłaconymi
  zamówieniami” (**otwarte**, poza zakresem).
- **Polityki** z kroku 4 to dosłownie tabela kroków process managera
  (Krok 7 niżej).

Uczciwie: spec z warsztatu nie był kompletny. Implementacja dodała
rozstrzygnięcia, których na ścianie nie było (R12, R15, R18; opisane przy
krokach), a jedno z nich (R18) **poprawia** regułę ze specu.

---

## 3. Mapa: co powstało i gdzie

```
apps/catalog/src/SharedKernel/        PSR-4 SharedKernel\ (ADR-0008)        8 plików, 218 linii
                   Money, Currency, AggregateRoot, DomainEvent, DomainException, ConcurrencyConflict…
apps/catalog/src/SellerPortal/        + rezerwacje w Offer, komendy ReserveStock / ReleaseReservation /
                                        ConfirmReservation, tabela offer_reservations (ADR-0006)
apps/catalog/src/Ordering/            PSR-4 Ordering\, nowy bounded context
  Domain/          Order, OrderLine, statusy, płatność, zwroty, VO       42 pliki, 1643 linie
  Application/     komendy + handlery, porty, Process/ (saga)            42 pliki, 1537 linii
  Infrastructure/  SqlOrderRepository, OrderEventLog, joby sagi, adaptery
                   (SellerPortalStockReservations, FakeGatewayPaymentGateway), ReadModel/*   21 plików, 1282 linie
apps/catalog/app/FakePaymentGateway/  symulowana bramka: sesje, decyzja, webhook, zwroty       4 pliki, 314 linii
apps/catalog/app/Http/Controllers/Shop/*            strona produktu, koszyk, zamówienia kupującego
apps/catalog/app/Http/Controllers/Seller/SellerOrderController.php   zamówienia sprzedawcy
apps/catalog/app/Http/Controllers/Webhooks/PaymentWebhookController.php   ACL bramki
apps/catalog/app/Console/Commands/{ExpireOverdueOrders,OrdersRaceTest,OrdersRaceTestBuyer}Command.php
apps/catalog/tests/Unit/Ordering/{Domain,Application,InMemory}     bez bazy i frameworka
apps/catalog/tests/Feature/Ordering/{Infrastructure,Http}          SQLite w pamięci
apps/catalog/tests/Arch/{OrderingLayersTest,SharedKernelTest}.php  granice kontekstów
compose.yaml                           nowy kontener catalog-scheduler (schedule:work)
infra/caddy/Caddyfile                  wewnętrzna trasa /webhooks/* dla workera
```

(Liczby: `find … -name '*.php' | wc -l` i `cat | wc -l`, 2026-10-16.)

Przepływ szczęśliwej ścieżki:

```
POST /cart/checkout
  → CartController::checkout             (blokada wierszy koszyka, formularz = koszyk w bazie?)
  → PlaceOrdersFromCartHandler           (widoczne? nie własne? ceny jak widziane? → N × Order::place, pusty koszyk)
      → OrderProcessManager::onPlaced    (w tej samej transakcji: job ReserveOrderLines do tabeli jobs)
catalog-worker: ReserveOrderLines        (dla każdej pozycji: T1 Offer::reserve, T2 Order::markLineReserved)
      → OrderAwaitingPayment             („Czeka na płatność”, odliczanie 15 min)
POST /orders/{id}/pay → bramka → „Zapłać” → SendPaymentWebhookJob (2 s) → POST /webhooks/payments
  → PaymentWebhookController (HMAC) → RecordPaymentHandler → Order::recordPayment → OrderPaid
catalog-worker: ConfirmOrderReservations (Offer::confirmReservation: stock −= ilość)
sprzedawca: „Oznacz jako wysłane” → Order::ship → Shipped
outbox offer.stock_reserved / reservation_confirmed → RabbitMQ → search → projekcja (offers[].stock = dostępne)
```

---

## 4. Jak czytać ten przewodnik i odtwarzać kroki

Każdy krok to jeden albo dwa Taski planu i ich commity na gałęzi `zamowienia`:

```bash
git log --oneline --reverse c2a0100..zamowienia
```

Testy **na hoście**, z `apps/catalog` (`phpunit.xml` wymusza SQLite w pamięci
i `QUEUE_CONNECTION=sync`). Nigdy w kontenerze (RUNBOOK
[#018](../RUNBOOK.md#018), [#026](../RUNBOOK.md#026)).

```bash
cd apps/catalog
php artisan test --compact tests/Unit/Ordering/Domain        # domena zamówień: bez bazy, ~40 ms
php artisan test --compact tests/Unit/Ordering               # + saga i wyścigi na fałszywych portach
php artisan test --compact tests/Arch                        # granice: Ordering, SellerPortal, SharedKernel
php artisan test --compact tests/Feature/Ordering            # SQL, joby, bramka, webhook, HTTP
# tak sprawdzane są „zepsuj i napraw” z modułów 11–12:
php artisan test --compact tests/Unit/Ordering tests/Feature/Ordering tests/Unit/SellerPortal tests/Feature/SellerPortal tests/Arch
```

Testy HTTP zamówień przełączają kolejkę sagi na `sync` (`tests/Pest.php`,
`beforeEach` dla `Feature/Ordering`), żeby kroki wykonały się w żądaniu.
Katalog `tests/Feature/Ordering/Http` to kontrakt wspólny z gałęzią lite
(bajt w bajt), więc nie sprawdza stanów pośrednich (`Placed`) ani nie sięga
po agregat; brak towaru przy składaniu, jedyna świadoma różnica, żyje
w `tests/Feature/Ordering/OutOfStockCheckoutTest.php`.

---

## Krok 1 — Shared Kernel

**Commit:** `8a2853c`. **Moduł nauki:** 3. **ADR:** [0008](../adr/0008-shared-kernel.md).

Zanim powstała jedna linijka zamówień, osiem klas wyjechało z
`SellerPortal\Domain\Shared` do `apps/catalog/src/SharedKernel/` jednym
mechanicznym commitem (99 plików, same importy). Powód: rezerwacja porównuje
cenę zamówienia z ceną oferty (H5), więc oba konteksty muszą mieć ten sam
`Money` z tą samą równością. Kopia rozjechałaby się przy pierwszej poprawce.

Ten sam commit dodał `tests/Arch/SharedKernelTest.php`: Shared Kernel nie zna
frameworka, portalu ani zamówień. Czego **nie** wyniesiono, choć kod jest
identyczny: `RetryOnConflict` i porty aplikacji. Shared Kernel jest dla
modelu, nie dla warstwy aplikacji.

```bash
php artisan test --compact tests/Arch tests/Unit/SharedKernel
```

---

## Krok 2 — Rezerwacje w agregacie Offer

**Commity:** `e02d8d3` (domena), `8c03dd0` (baza, outbox, projekcja).
**Moduł nauki:** 12. **ADR:** [0006](../adr/0006-rezerwacje-w-ofercie.md),
aktualizuje [0004](../adr/0004-wyprzedana-jako-stan-pochodny.md).

Oferta dostała listę aktywnych rezerwacji i dwie wartości pochodne:
`reserved()` i `available() = stock − reserved()`. „Widoczna” i „wyprzedana”
liczą się od dostępności, nie od stanu. Cztery nowe metody
(`apps/catalog/src/SellerPortal/Domain/Offer/Offer.php`): `reserve` (`:215`),
`releaseReservation` (`:253`), `confirmReservation` (`:276`) i nowy
niezmiennik w `changeStock` (`:182`, `StockBelowReserved`). `withdraw` celowo
**nie** zwalnia rezerwacji (H4): ktoś mógł już zapłacić.

Najważniejsza lekcja tego kroku to zdanie ze specu: **idempotencja wymaga
pamięci**. Agregat zna tylko aktywne rezerwacje, a ponowiony krok sagi
przychodzi z kluczem już rozliczonym. Stąd tabela `offer_reservations` ze
statusem `active|confirmed|released` i pytanie
`OfferRepository::reservationStatus`. Repozytorium wczytuje do agregatu tylko
`active` (`SqlOfferRepository.php:94`).

Po stronie odczytu scope `listed()` odejmuje aktywne rezerwacje
podzapytaniem (`apps/catalog/app/Models/Offer.php:25`), projekcja podaje
wyszukiwarce `offers[].stock` jako **dostępne**, a outbox dostał trzy nowe
nazwy: `offer.stock_reserved`, `offer.reservation_released`,
`offer.reservation_confirmed`. Klucz rezerwacji do payloadu nie trafia.

### Co się zepsuło: rezerwacja rozliczonego klucza (R12)

Przy przeglądzie Taska 3 wyszła dziura między agregatem a bazą (naprawiona
w Tasku 4, `0aedf3a`): agregat nie widzi
rozliczonych rezerwacji, więc `reserve` z kluczem już `released` albo
`confirmed` spróbowałby wstawić drugi wiersz i trafił na indeks unikalny
`(offer_id, reservation_key)` surowym `QueryException`. Rozstrzygnięcie R12:
`ReserveStockHandler` pyta o status klucza przed agregatem i rzuca
`ReservationConflict`
(`apps/catalog/src/SellerPortal/Application/Reservation/ReserveStockHandler.php:42`).
Maszyna stanów `Order` takiej kolejności nie robi, więc to obrona przed
błędem w sadze, a nie zwykła ścieżka.

---

## Krok 3 — Komendy rezerwacji i „zarezerwowane” w UI sprzedawcy

**Commity:** `0aedf3a`, `9aa3ef1`. **Moduł nauki:** 8, 12.

Trzy komendy aplikacyjne portalu (`SellerPortal\Application\Reservation\`):
`ReserveStock`, `ReleaseReservation`, `ConfirmReservation`. Każda działa
w transakcji na jednej ofercie i ponawia `ConcurrencyConflict` do 3 razy:
komendy są systemowe, nikt nie „odświeży strony”. `ConfirmReservationHandler`
kończy cicho dla klucza już `confirmed` (`:35`), a dla zwolnionego albo
nieznanego rzuca `ReservationNotFound`, bo sprzedaż bez zmniejszenia stanu
jest gorsza niż błąd (moduł 12, ćwiczenie 3).

Sprzedawca widzi w szczegółach oferty „Na stanie N · zarezerwowane R ·
dostępne A”, a próba zejścia poniżej zarezerwowanego kończy się komunikatem
„Nie możesz zejść poniżej liczby zarezerwowanych sztuk”.

### Co się zepsuło: lista ofert liczyła „wyprzedane” po staremu

Pierwsza wersja zmieniła definicję w domenie i w `listed()`, ale lista ofert
sprzedawcy i licznik na pulpicie dalej liczyły „wyprzedaną” z surowego
`stock`. Oferta z 3 sztukami, wszystkimi zarezerwowanymi, była w wyszukiwarce
niewidoczna, a u sprzedawcy „w sprzedaży”. Poprawka (`9aa3ef1`,
rozstrzygnięcie R13): każdy model odczytu, który wyprowadza „widoczna”
albo „wyprzedana”, liczy z dostępności. To ten sam problem co w ADR-0004:
im więcej definicji tej samej reguły, tym więcej miejsc do zapomnienia.

---

## Krok 4 — Agregat Order

**Commit:** `d1eab4f`. **Moduł nauki:** 5, 11, 12.

`apps/catalog/src/Ordering/Domain/Order.php`: pozycje jednego sprzedawcy
z zamrożoną ceną (1–20 pozycji, każda oferta raz, ilość 1–10, jedna waluta),
termin płatności (`PAYMENT_WINDOW = PT15M`), karencja na spóźniony webhook
(`WEBHOOK_GRACE = PT5M`), wpłata, zwroty, anulowanie, wysyłka. Maszyna stanów
w docblocku (`:26`). Trzy decyzje, które warto przeczytać w kodzie:

1. **Stan procesu żyje w zamówieniu** (`:35`, ADR-0007). `Placed` to
   jednocześnie „saga rezerwuje” i „Sprawdzamy dostępność” w UI.
2. **`recordPayment` nigdy nie rzuca dla „złego” statusu** (`:42`, `:196`).
   Pieniądze już przyszły; wyjątek ich nie cofnie. W każdym statusie agregat
   przyjmuje wpłatę, rozpoznaje duplikat albo zleca zwrot.
3. **Domena nadaje `RefundId`** (`refund-{orderId}-{n}`), bo to klucz
   idempotencji w bramce. Losowy identyfikator dawałby nowy zwrot przy
   każdym ponowieniu.

### Rozstrzygnięcie z implementacji: R15

Spec mówi „`LatePaymentReceived` + `RefundRequested`” dla każdej wpłaty,
której zamówienie nie przyjmuje. Ale nie każda z nich jest „po terminie”:
bywa podwójna albo z inną kwotą. Rozstrzygnięcie R15: `LatePaymentReceived`
poprzedza `RefundRequested` na **każdej** ścieżce zwrotu wpłaty, a co to
było, mówi `RefundReason` (`LatePayment`, `DuplicatePayment`,
`AmountMismatch`). Koszt: nazwa zdarzenia pasuje dosłownie tylko do
jednego z trzech przypadków. Zysk: jedno zdarzenie „przyszła wpłata, której
nie bierzemy”.

```bash
php artisan test --compact tests/Unit/Ordering/Domain   # 80 testów, ~40 ms
```

---

## Krok 5 — Persystencja zamówień i dziennik zdarzeń

**Commity:** `c4ae3ca`, `5aa5911`. **Moduł nauki:** 7.

Tabele `orders`, `order_lines`, `order_refunds`, `order_events` i
`SqlOrderRepository` z optymistyczną blokadą (`UPDATE … WHERE version = ?`),
jak w portalu. Zdarzenia `Order` **nie** idą do outboxu: nie mają konsumenta
(zasada z ADR-0003). Zapisują się do `order_events`, z których powstaje oś
„Historia zamówienia” u kupującego.

### Co się zepsuło: nazwy zdarzeń z refleksji

Pierwsza wersja wyprowadzała typ zdarzenia (`order.<snake_case klasy>`)
i payload refleksją z publicznych pól zdarzenia. Przegląd odrzucił to tym samym
argumentem co w portalu: zapisane dane czyta później oś czasu, więc zmiana
nazwy klasy albo parametru zmieniłaby historię po cichu. Poprawka
(`5aa5911`): jawna mapa `OrderEventLog`
(`apps/catalog/src/Ordering/Infrastructure/Persistence/OrderEventLog.php:53`),
a nowe zdarzenie bez wpisu przerywa zapis (`LogicException`), tak jak
`OfferIntegrationEvents`.

---

## Krok 6 — Warstwa aplikacji: składanie, płatność, anulowanie

**Commit:** `32d22e6`. **Moduł nauki:** 8.

Porty (`Ordering\Application\Port\`): `StockReservations`, `OfferSnapshots`,
`PaymentGateway`, `SellerAccounts`, `BuyerCart`, `ProcessJobQueue`, `Clock`,
`TransactionManager`, `DomainEventDispatcher`. Ordering nie importuje
niczego z portalu (`tests/Arch/OrderingLayersTest.php`), a `RetryOnConflict`
jest kopią portalowego.

`PlaceOrdersFromCartHandler` (`:52`) odmawia **przed** zapisem (oferta
niewidoczna, własna oferta, cena inna niż widziana; `:64`–`:66`), grupuje po
sprzedawcy i w jednej transakcji tworzy N zamówień i czyści koszyk (`:70`).
Czy to łamie „jeden agregat na transakcję”? Nie: zasada chroni przed
rywalizacją o **istniejące** agregaty, a nowych zamówień nikt inny nie
blokuje. Dostępność nie jest sprawdzana: o niej decyduje rezerwacja,
a sprawdzenie teraz i tak byłoby nieaktualne za sekundę.

---

## Krok 7 — Process manager, kompensacje i wyścigi

**Commit:** `b0e521b`. **Moduły nauki:** 11, 12. **ADR:** [0007](../adr/0007-process-manager-ze-stanem-w-zamowieniu.md).

Serce etapu: `apps/catalog/src/Ordering/Application/Process/`.

| Zdarzenie | Krok | Kompensacja / uwagi |
|---|---|---|
| `OrderPlaced` | `ReserveOrderLines` | pierwsza odmowa → `reject` → zwolnienie wszystkich pozycji |
| `OrderRejected`, `OrderExpired`, `OrderCancelled(by: Buyer)` | `ReleaseOrderReservations` | zwalnia **każdą** pozycję, także `Pending` |
| `OrderPaid` | `ConfirmOrderReservations` | także po wysyłce i anulowaniu przez sprzedawcę (R7, H8) |
| `RefundRequested` | `RefundPayment` | bramka przed transakcją, `refundId` jako klucz idempotencji |

Krok rezerwacji to dwie transakcje dwóch agregatów: T1 rezerwacja w ofercie
(przez port), T2 `markLineReserved` w zamówieniu. Między nimi świat się
zmienia, więc każdy wyścig ze specu (R1–R10) ma test aplikacji z ręcznie
ustawionym przeplotem (`OrderRacesTest`, hak `afterReserve`). Cały plik
działa w pamięci, w milisekundach.

### Co się zepsuło: reguła R1 ze specu była zbyt ogólna (R18)

Spec mówił: w T2 „zamówienie w innym statusie niż `Placed` → zwolnij tę
pozycję” (R1: kupujący anulował w trakcie rezerwacji, a zwolnienie po
anulowaniu przeszło za wcześnie i nie miało czego zwolnić). Przy pisaniu
testów wyścigów powstał test, którego w specu nie było: **ten sam job
w dwóch workerach naraz** (pierwszy przekroczył `retry_after`). Drugi
przebieg doprowadza zamówienie do `AwaitingPayment` między T1 a T2
pierwszego. T1 pierwszego trafiło w aktywną rezerwację drugiego (no-op po
kluczu), a reguła ze specu kazałaby ją zwolnić: zamówienie czeka na płatność
bez towaru, sztuka wraca do sprzedaży. Overselling.

Kod zwalnia więc tylko wtedy, gdy zamówienie **samo zrezygnowało**
z rezerwacji: `Rejected`, `Expired` albo `Cancelled` przez kupującego
(`ReserveOrderLines.php:103` i `:153`). Kontroler zatwierdził to jako
rozstrzygnięcie R18, a spec dostał dopisek w sekcji 5. Test:
`OrderRacesTest` › „R1: zdublowany krok rezerwacji nie zwalnia sztuk
zamówienia, które czeka na płatność”. Moduł 12, ćwiczenie 2 pozwala to
zepsuć samemu.

Drugi, mniejszy skutek tego samego scenariusza (R19): zdublowany krok, który
trwałby przez płatność **i** potwierdzenie, spróbowałby zarezerwować klucz
już `confirmed`. Fałszywe rezerwacje w testach by na to pozwoliły, ale
prawdziwy `ReserveStockHandler` rzuca `ReservationConflict` (R12), więc job
trafia głośno do `failed_jobs`. Zostało opisane, nie rozwiązane.

```bash
php artisan test --compact tests/Unit/Ordering/Application
```

---

## Krok 8 — Infrastruktura sagi: joby, sweep, scheduler

**Commit:** `3339808`. **Moduł nauki:** 11. **RUNBOOK:** [#044](../RUNBOOK.md#044), [#045](../RUNBOOK.md#045), [#046](../RUNBOOK.md#046).

- Cztery joby (`Ordering\Infrastructure\Job\`) z samymi skalarami w payloadzie,
  `tries = 5`, `backoff = [5, 30, 120]`.
- `LaravelProcessJobQueue` wstawia je na połączenie
  `queue.ordering_process_connection` (domyślnie `database`), w transakcji
  zapisu zamówienia: outbox komend. Ta sama lekcja co w portalu
  (przewodnik ETAPU E, Krok 8), tym razem od początku w configu.
- Adapter `SellerPortalStockReservations` to mały ACL: tłumaczy
  `InsufficientStock`, `ReservationPriceMismatch`, `OfferNotReservable`,
  `SellerCannotSell`, `OfferNotFound` na `ReservationResult`, a cała reszta
  leci dalej jako błąd joba.
- Sweep `orders:expire-overdue` co minutę (`withoutOverlapping`) i nowy
  kontener `catalog-scheduler` (`schedule:work`) w `compose.yaml`, plus
  `make scheduler-logs`. Komenda zapisuje czas przebiegu w cache, a `/health`
  pokazuje go jako `ordering_sweep_last_run_at`.

`docker compose up` z worktree nie był uruchamiany (maszyna współdzielona,
drugi projekt compose). Sprawdzono tylko `docker compose --profile apps config -q`.

---

## Krok 9 — Symulowana bramka i webhook (ACL)

**Commity:** `400b90c`, `6c30000`. **Moduł nauki:** 3, 12.

`apps/catalog/app/FakePaymentGateway/` udaje zewnętrznego dostawcę
i celowo jest **poza** Orderingiem: własne tabele (`fake_gateway_payments`,
`fake_gateway_refunds`), własne trasy (`/fake-gateway/payments/{id}`), własny
job webhooka. Sesja wygasa w `paymentDeadline`. Decyzja „Zapłać”/„Odmów”
kolejkuje podpisany webhook z opóźnieniem 2 s, ponawiany, aż przyjdzie 2xx.
W `local` i testach są dwa przyciski deweloperskie: „Wyślij webhook ponownie”
(R4) i „Zapłać mimo wygaśnięcia” (H6).

`PaymentWebhookController` to jedyna klasa, która zna format bramki: HMAC
`X-Signature` (zły → 401), walidacja payloadu, `reference` → `OrderId`,
a potem komendy domeny. Nieznane zamówienie albo zepsuty payload: ostrzeżenie
w logu i **200**, bo wiadomość nie stanie się lepsza po ponowieniu.

### Co się zepsuło: trzy rzeczy, których test w pamięci nie widział

1. **Webhook z workera by nie dotarł.** Wewnętrzny blok Caddy
   `http://catalog-app` obsługiwał tylko `/api/*`, reszta to 404. Worker
   wysyła webhook pod `http://catalog-app/webhooks/payments`, więc doszła
   trasa `route /webhooks/*` (`infra/caddy/Caddyfile:98`).
2. **Połączenie joba webhooka.** Job musi iść na połączenie, które czyta
   `catalog-worker` (`queue:work database`), a nie na `queue.default`.
   Pierwsza wersja miała `database` wpisane w kodzie; przegląd przeniósł je
   do configu (`services.fake_gateway.queue_connection`, domyślnie `database`).
3. **Dwuklik w bramce.** Druga decyzja mogła zamienić „zapłacone” na
   „odmówione”. `decide` to teraz atomowy `UPDATE … WHERE decided_at IS NULL`,
   a webhook kolejkuje tylko zwycięzca. Do tego test H6 od końca do końca:
   zamówienie wygasa, bramka przyjmuje „Zapłać mimo wygaśnięcia”, webhook
   kończy się zwrotem `LatePayment`.

---

## Krok 10 — Strona produktu, koszyk, składanie

**Commity:** `1c64ada`, `30c6840`. **Moduł nauki:** 10 (koszyk to supporting,
Laravel way).

Strona `/products/{id}` czyta oferty z bazy (nie z ES) i pokazuje dostępne
sztuki. Koszyk to zwykła tabela `cart_items` i kontroler, bez agregatu:
nie ma w nim reguł, które warto chronić modelem.

### Co się zepsuło: koszyk nie pamiętał, jaką cenę widział kupujący

Pierwsza wersja zapisywała przy dodaniu **obecną** cenę oferty, a nie tę,
którą kupujący widział na stronie, a ponowne dodanie ją odświeżało.
„Akceptuj nową cenę” przyjmowało cenę, jaka akurat była w bazie. Zmiana
ceny między wyświetleniem a kliknięciem przechodziła więc niezauważona,
a strażnik H5 przy składaniu porównywał cenę, której kupujący nie widział.
Poprawki z przeglądu (`30c6840`):

- dodanie do koszyka wymaga ceny wyświetlonej na stronie i zapisuje ją jako
  `added_price_cents`; ponowne dodanie jej nie odświeża,
- „Akceptuję nową cenę” wysyła `accept_price_cents` i działa tylko, gdy ta
  cena jest dalej obecna,
- checkout czyta koszyk pod `lockForUpdate` i woła handler w tej samej
  transakcji (`apps/catalog/app/Http/Controllers/Shop/CartController.php:125`):
  dwa równoległe „Złóż zamówienia” dają jedno złożenie, drugie widzi pusty
  koszyk (R9), a formularz niezgodny z koszykiem w bazie jest odrzucany.

---

## Krok 11 — Zamówienia kupującego

**Commity:** `3fe7179`, `77bc8d6`. **Moduł nauki:** 11.

`/orders` i `/orders/{id}`: status po polsku (`OrderStatusLabel`), pozycje,
oś zdarzeń z `order_events`, odliczanie do terminu, „Zapłać” i „Anuluj
zamówienie” z `Order::allowedActionsFor(Actor::Buyer)`. W `Placed` strona
odświeża się co sekundę: to spójność ostateczna, pokazana wprost.

### Co się zepsuło: utknięcie w „Czekamy na potwierdzenie płatności”

Po powrocie z bramki (`?payment=returned`) strona pokazuje „Czekamy na
potwierdzenie płatności” i chowa „Zapłać”, bo webhook przychodzi ~2 s
później. Pierwsza wersja nie miała z tego stanu wyjścia poza ręcznym wejściem na
adres zamówienia bez parametru: jeśli webhook nie przyszedł (bramka leży,
worker nie działa), odświeżanie strony dalej chowało „Zapłać”. Teraz po 30 s strona zdejmuje `?payment=returned`
i „Zapłać” wraca. Drugi błąd z tego samego przeglądu: „Zapłać” było widoczne
po terminie, ale przed sweepem (bramka i tak by odmówiła). Teraz znika
z chwilą terminu.

---

## Krok 12 — Zamówienia sprzedawcy

**Commit:** `78d7085`. **Moduł nauki:** 8, 11.

`/seller/orders` z zakładkami „Do wysłania” (`Paid`) i „Wszystkie”, licznik
„Do wysłania” na pulpicie, `/seller/orders/{id}` z „Oznacz jako wysłane”
(numer przesyłki 3–64 znaki) i „Anuluj ze zwrotem” (powód). Potwierdzenie
anulowania mówi wprost: „Kupujący dostanie zwrot … Stan oferty nie wróci
automatycznie.” To jest H8 przełożone na zdanie dla człowieka: process
manager nie zwalnia rezerwacji po anulowaniu przez sprzedawcę
(`OrderProcessManager.php:52`).

---

## Krok 13 — orders:race-test

**Commity:** `e2d3d68`, `07652b8`. **Moduł nauki:** 11, 12.

`php artisan orders:race-test {--buyers=50} {--stock=10} {--workers=4} {--keep} {--dry-run}`.
Tworzy w jednej transakcji fixture (N kupujących, sprzedawca, produkt,
opublikowana oferta ze stanem `--stock`), startuje N procesów
`orders:race-test:buyer` ze wspólną chwilą startu (inaczej start Laravela
rozsunąłby je w czasie i nie rywalizowałyby), puszcza workery rundami, płaci
za każde `awaiting_payment`, czeka na potwierdzenia i drukuje raport:
złożone, odmowy, opłacone, odrzucone, końcowy `stock`, aktywne rezerwacje,
overselling, konflikty wersji (oferta/zamówienie), p50/p95 złożenia,
`failed_jobs` od startu. Kod wyjścia 1 przy oversellingu albo złej liczbie
opłaconych. Działa tylko na Postgresie (na SQLite odmawia, kod 1).

Konflikty wersji liczy opt-in ślad `ConcurrencyConflictTrace`: tylko gdy
proces ma `LOG_CONCURRENCY_PATH`, repozytoria dopisują linię przy
`UPDATE … WHERE version = ?` = 0 wierszy. Komenda ustawia tę zmienną
procesom potomnym jawnie, bo `config:cache` w kontenerze zamraża `env()`.

### Co się zepsuło: sprzątanie zostawiało produkt w wyszukiwarce

Pierwsza wersja kasowała fixture zwykłym `DELETE`. Zdarzenia oferty z
przebiegu mogły już przejść przez outbox do ES, a usunięty produkt zostawałby
w `products-search` na zawsze. Teraz produkt jest kasowany przez
`Product::deleteWithOutbox('product.deleted')`, tym samym mechanizmem co
reszta katalogu, a projekcja usuwa dokument. Przy okazji test fixture
złapał błąd, który na Postgresie wywróciłby każdą rezerwację: sprzedawca
w fixture nie miał miasta, a `SqlSellerRepository::hydrate` odrzuca taki
wiersz.

> **Do uzupełnienia po pomiarze (Task 14/15):** wynik `orders:race-test` na
> prawdziwym Postgresie z workerami. Po merge `zamowienia` do `main`
> (zgoda użytkownika) i `make up-apps`:
> ```bash
> docker compose exec catalog-app php artisan migrate --force
> docker compose exec catalog-app php artisan orders:race-test --dry-run
> docker compose exec catalog-app php artisan orders:race-test --buyers=50 --stock=10 --workers=4
> ```
> Zapisać cały raport: opłacone (oczekiwane dokładnie 10), odrzucone,
> overselling (oczekiwane 0), konflikty wersji oferta/zamówienie, p50/p95
> złożenia, `failed_jobs` od startu, czas całego przebiegu. Liczba konfliktów
> jest dolną granicą: joby przejęte przez działający równolegle
> `catalog-worker` nie mają `LOG_CONCURRENCY_PATH`.

---

## Krok 14 — Jak uruchomić i co zobaczyć

Wszystko poniżej wymaga kodu w głównym checkoucie (kontener `catalog-app`
montuje `apps/catalog` z `/Users/…/elastic`, nie z worktree), czyli merge
`zamowienia` do `main`. Do tego czasu sprawdzaj zachowanie testami.

1. **Stack i migracje.**
   ```bash
   make up-apps
   docker compose exec catalog-app php artisan migrate --force
   docker compose ps catalog-worker catalog-scheduler     # oba healthy
   docker compose --profile apps build catalog-app && docker compose --profile apps up -d catalog-app
   curl -sk https://catalog.localhost/api/health | jq .ordering_sweep_last_run_at   # nie null po minucie
   ```
   Przebudowa `catalog-app` jest potrzebna, bo Caddyfile (z nową trasą
   `/webhooks/*`) jest wbudowany w obraz. Bez niej webhook z workera dostaje
   404 i zamówienie nigdy nie przechodzi do „Opłacone” (RUNBOOK
   [#047](../RUNBOOK.md#047)).
2. **Sprzedawca z opublikowaną ofertą.** Jak w przewodniku portalu
   ([Twoja kolej, pkt 1](etap-e-portal-sprzedawcy-ddd.md#twoja-kolej)):
   drugi użytkownik zgłasza się jako sprzedawca, moderator akceptuje go
   w `/panel/sellers`, sprzedawca publikuje ofertę ze stanem 1.
3. **Kupujący.** Trzeci użytkownik: `/search` → karta produktu →
   `/products/{id}` → „Dodaj do koszyka” → `/cart` → „Złóż zamówienia”.
   Na `/orders/{id}` przez chwilę „Sprawdzamy dostępność”, potem „Czeka na
   płatność” z odliczaniem. U sprzedawcy: „Na stanie 1 · zarezerwowane 1 ·
   dostępne 0”, a oferta znika z `/search`.
4. **Płatność.** „Zapłać” → strona bramki → „Zapłać” → powrót, „Czekamy na
   potwierdzenie płatności”, po ~2 s „Opłacone”. U sprzedawcy „Na stanie 0”
   i zamówienie w „Do wysłania”.
5. **Podgląd sagi w bazie:**
   ```bash
   make psql db=catalog
   select event_type, occurred_at from order_events where order_id = <ID> order by id;
   select reservation_key, offer_id, quantity, status from offer_reservations order by id desc limit 5;
   select id, queue, attempts, left(payload, 80) from jobs order by id;
   select id, failed_at, left(exception, 150) from failed_jobs order by id desc limit 5;
   select event_type, occurred_at, published_at from outbox where aggregate_type = 'offer' and aggregate_id = '<OFERTA>' order by id desc limit 5;
   ```
6. **Ścieżki niepogodne:** „Odmów” w bramce (zamówienie dalej czeka, H7),
   „Wyślij webhook ponownie” (nic się nie zmienia, R4), zamówienie bez
   płatności przez 20 minut (sweep → „Wygasło”, oferta wraca do `/search`),
   „Zapłać mimo wygaśnięcia” na wygasłym (zwrot `LatePayment`), anulowanie
   przez sprzedawcę opłaconego (zwrot, stan nie wraca). Z
   `FAKE_GATEWAY_FAIL_FIRST_REFUND=true` zwrot najpierw jest „w toku”, a po
   ponowieniu joba „zwrócono”.

> **Do uzupełnienia po pomiarze (Task 15):** obserwacje E2E z przeglądarki
> dla scenariuszy ze specu (sekcja 8): zakup u dwóch sprzedawców → dwie
> płatności → wysyłka; brak towaru; wygaśnięcie; wpłata po terminie → zwrot;
> zdublowany webhook; zniknięcie ostatniej sztuki z `/search` i jej powrót.
> Do zmierzenia: **czas od rezerwacji ostatniej sztuki do zniknięcia
> z `/search`** (od `occurred_at` zdarzenia `offer.stock_reserved` w outboxie,
> zapytanie z pkt 5, do chwili, w której
> `curl -sk "https://catalog.localhost/api/search?q=<nazwa produktu>"`
> przestaje zwracać tę ofertę; powtórzyć kilka razy) i ten sam czas
> w drugą stronę po anulowaniu przez kupującego. Wyniki do
> [POMIARY](../POMIARY.md) i [ADR-0006](../adr/0006-rezerwacje-w-ofercie.md).

---

## Znane ograniczenia (świadomie odłożone)

- **H11, zablokowany sprzedawca z opłaconymi zamówieniami:** otwarte.
  Nowych rezerwacji nie dostaje (`reserve` wymaga `canSell()`), ale co
  z opłaconymi, nie rozstrzygnięto (masowe zwroty? realizacja?).
- **Brak rekonsyliacji rezerwacji.** `Order` nie śledzi, czy potwierdzenie
  po płatności przeszło; sygnałem trwałej awarii jest tylko `failed_jobs`
  (RUNBOOK [#046](../RUNBOOK.md#046)). Polecenie „aktywne rezerwacje zamówień
  opłaconych” to dalszy krok.
- **R19:** zdublowany krok rezerwacji trwający przez płatność i
  potwierdzenie kończy się `ReservationConflict` w `failed_jobs`.
- **Wiersze `confirmed`/`released` w `offer_reservations` nigdy nie są
  przycinane.**
- **Karencja ma dwie definicje** (domena i zapytanie sweepa), wiąże je tylko
  to, że obie mają testy (moduł 11, ćwiczenie 3).
- **Reguła „ma rezerwacje”** jest powtórzona w `ReserveOrderLines::wantsReservations`
  i `ConfirmOrderReservations::holdsSoldReservations`; kandydat na
  `Order::holdsReservations()`.
- **Koszt konfliktów w gorącej ofercie** nie jest jeszcze zmierzony
  (`orders:race-test` czeka na Postgresa).
- Polling co 1 s, odliczanie i 30-sekundowe wyjście z „Czekamy na
  potwierdzenie płatności” sprawdzone tylko buildem i typami, nie
  w przeglądarce (Task 15).
- Literówka w toaście sprzedawcy po anulowaniu
  (`apps/catalog/app/Http/Controllers/Seller/SellerOrderController.php:66`,
  „zwrot zleczony” zamiast „zlecony”) czeka na poprawkę w końcowej fali.
- `catalog-scheduler` i `catalog-worker` są tylko w `compose.yaml`; manifesty
  k3s dla zamówień nie istnieją (wdrożenie poza zakresem).

---

## Bilans na dziś

Pełne porównanie z liczbami („saga kontra jedna transakcja”):
[`saga-vs-transakcja.md`](saga-vs-transakcja.md). Na dziś:

| | Wartość (2026-10-16) |
|---|---|
| Kod Orderingu | 105 plików PHP, 4462 linie (domena 1643, aplikacja 1537, infrastruktura 1282) |
| Shared Kernel | 8 plików, 218 linii |
| Bramka (obcy system) | 4 pliki, 314 linii |
| Testy domeny Orderingu | 80 w 41 ms (kryterium specu: < 1 s) |
| Testy domeny + aplikacji (saga, wyścigi R1–R10) | 122 w 48 ms |
| `tests/Feature/Ordering` | 144 testy |

**Dało:** każdy wyścig ze specu jest testem w pamięci z ręcznym przeplotem,
więc można go zepsuć i zobaczyć (moduły 11–12); kompensacje są nazwane
i mają po jednym miejscu; kupujący widzi prawdziwy stan procesu; reguły
oferty (brak towaru, cena, niedostępność) zostały w jednym agregacie, a
Ordering dostaje gotowy wynik przez port. **Kosztowało:** stan `Placed`
i polling, którego jedna transakcja by nie potrzebowała; worker i scheduler
jako warunek działania; dwanaście wyścigów do przemyślenia, z których jeden
(R18) spec rozstrzygnął źle; dwie definicje karencji i „ma rezerwacje”.

Porównanie z gałęzią `zamowienia-ddd-lite` (zmierzone 2026-10-16): kod
zamówień 4155 linii w DDD kontra 1389 w lite, testy reguł 27 ms kontra
~1 s, nowa reguła 12 plików (+113/−3) kontra 7 (+86/−4). Szczegóły, sondy
obejścia i werdykt: [`saga-vs-transakcja.md`](saga-vs-transakcja.md).

> **Do uzupełnienia po pomiarze (Task 17):** `orders:race-test` na obu
> gałęziach i awaria w połowie (ubity worker kontra ubite żądanie) —
> komendy w [`saga-vs-transakcja.md`](saga-vs-transakcja.md), rozdziały 6 i 7.

---

## Ściąga

| Chcę… | Komenda |
|---|---|
| testy domeny zamówień | `cd apps/catalog && php artisan test --compact tests/Unit/Ordering/Domain` |
| testy sagi i wyścigów | `php artisan test --compact tests/Unit/Ordering/Application` |
| wszystko pod „zepsuj i napraw” | `php artisan test --compact tests/Unit/Ordering tests/Feature/Ordering tests/Unit/SellerPortal tests/Feature/SellerPortal tests/Arch` |
| logi workera (kroki sagi, webhooki) | `make worker-logs` |
| logi schedulera (sweep) | `make scheduler-logs` (RUNBOOK [#044](../RUNBOOK.md#044)) |
| ręczny sweep | `docker compose exec catalog-app php artisan orders:expire-overdue` |
| zamówienie wisi w „Sprawdzamy dostępność” | RUNBOOK [#045](../RUNBOOK.md#045) |
| nieudany krok sagi, ponowienie | RUNBOOK [#046](../RUNBOOK.md#046) |
| przyciski deweloperskie bramki | RUNBOOK [#047](../RUNBOOK.md#047) |
| plan wyścigu bez uruchamiania | `docker compose exec catalog-app php artisan orders:race-test --dry-run` |
| oś zdarzeń zamówienia | `/orders/{id}` albo `select event_type, occurred_at from order_events where order_id = <ID> order by id` |

---

## Twoja kolej

1. **Zrób pięć „zepsuj i napraw” z modułów 11 i 12** w
   [`10-DDD.md`](../10-DDD.md) (czwarte w module 11 jest tylko do
   przeczytania). Zanim uruchomisz testy, zgadnij, ile będzie czerwonych
   i na którym poziomie. Najciekawsze: moduł 12, ćwiczenie 2 (reguła ze
   specu „działa” dla scenariusza, który spec rozpisał).
2. **Dopisz wyścig, którego nie ma.** Sprzedawca wycofuje ofertę, gdy
   zamówienie jest już `AwaitingPayment` (H4: rezerwacja ma być dotrzymana).
   Napisz test w `OrderRacesTest`: płatność po wycofaniu zamienia rezerwację
   w sprzedaż. Przejdzie od razu? Dlaczego?
3. **Zaprojektuj na sucho rekonsyliację:** polecenie, które znajduje aktywne
   rezerwacje zamówień `Paid`/`Shipped` starsze niż godzina i je potwierdza.
   Gdzie żyje (Ordering czy portal)? Jak uniknąć wyścigu z normalnym krokiem
   potwierdzenia?
4. **Rozstrzygnij H11 jako ekspert domeny:** zablokowany sprzedawca ma pięć
   opłaconych zamówień. Masowy zwrot czy realizacja? Który agregat i która
   polityka by to robiły?

Pytania kontrolne:

- Dlaczego rezerwacje są w ofercie, a nie w zamówieniu ani w osobnym agregacie?
- Co widzi kupujący w `Placed` i dlaczego to nie jest błąd?
- Dlaczego job sagi musi powstać w tej samej transakcji co zapis zamówienia?
- Dlaczego `recordPayment` nie rzuca wyjątku dla wygasłego zamówienia?
- Jaki scenariusz psuje regułę „inny status → zwolnij” i kto go znalazł?
- Dlaczego potwierdzenie rezerwacji potrzebuje tabeli ze statusami, skoro agregat ma listę rezerwacji?
