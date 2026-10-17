# 12 — Słownik zamówień (język wszechobecny kontekstu Ordering)

Ten słownik obowiązuje **wewnątrz kontekstu Ordering** (zamówienia kupujących)
i na jego styku z portalem sprzedawcy. Słowa portalu („oferta”, „stan”,
„rezerwacja”, „dostępne”) definiuje
[`11-SELLER-PORTAL-SLOWNIK.md`](11-SELLER-PORTAL-SLOWNIK.md); tu są tylko
w takim znaczeniu, w jakim widzi je zamówienie. Decyzje:
[ADR-0006](adr/0006-rezerwacje-w-ofercie.md) (rezerwacje w ofercie),
[ADR-0007](adr/0007-process-manager-ze-stanem-w-zamowieniu.md) (process
manager), [ADR-0008](adr/0008-shared-kernel.md) (Shared Kernel).

Zasady jak w słowniku portalu:
- **Rozmowa, UI i dokumentacja po polsku, kod po angielsku.** Mapowanie 1:1.
  Słowo w UI bez pary w kodzie to dziura w modelu albo zbędne słowo.
- **Bez synonimów.** Nie ma „zamówienia w realizacji”, „zamówienia
  aktywnego” ani „zamówienia zakończonego”. Są statusy poniżej.
- Kolumna „Gdzie” podaje symbol. Ścieżki są względne wobec
  `apps/catalog/src/Ordering/`, chyba że zaczynają się od `apps/`, `app/`
  albo `resources/` (wtedy względem korzenia repo albo `apps/catalog/`).

---

## Aktorzy

| PL | Kod | Definicja | Gdzie |
|---|---|---|---|
| kupujący | `BuyerId` | zalogowany użytkownik, który składa zamówienia. Ordering bierze `users.id` jak jest (Conformist) | `Domain/BuyerId.php` |
| sprzedawca (w zamówieniu) | `SellerId` | **własny** VO Orderingu z ID sprzedawcy z portalu. Zamówienie nie zna agregatu `Seller` | `Domain/SellerId.php` |
| konto sprzedawcy zalogowanego | port `SellerAccounts::sellerIdForUser` | który sprzedawca należy do zalogowanego użytkownika (pytanie do portalu) | `Application/Port/SellerAccounts.php`, `Infrastructure/DbSellerAccounts.php` |
| aktor | `Actor` (`Buyer`, `Seller`) | kto pyta o dozwolone akcje przy zamówieniu | `Domain/Actor.php` |
| bramka płatności | `PaymentGateway` (port), `FakePaymentGateway` (symulacja) | system zewnętrzny: przyjmuje wpłaty i zwraca pieniądze | `Application/Port/PaymentGateway.php`, `apps/catalog/app/FakePaymentGateway/` |
| zegar, sweep | `orders:expire-overdue`, `ExpireOverdueOrdersHandler` | „aktor” Zegar z Event Stormingu: co minutę wygasza zamówienia po terminie i karencji | `Application/Order/ExpireOverdueOrdersHandler.php`, `apps/catalog/routes/console.php` |

## Koszyk (poddomena supporting, bez agregatu)

| PL (UI) | Kod | Definicja |
|---|---|---|
| koszyk | tabela `cart_items`, `App\Models\CartItem`, `CartView` | dane robocze kupującego: oferta, ilość, cena widziana. **Niczego nie rezerwuje** (H1) |
| „Dodaj do koszyka” | `CartController::store` | dodaje pozycję po cenie, którą kupujący **widział na stronie** (`unit_price_cents` z formularza); inna niż obecna → błąd |
| cena widziana / zaakceptowana | kolumna `cart_items.added_price_cents` | cena, na którą kupujący się zgodził. Ponowne dodanie jej nie odświeża |
| „Akceptuję nową cenę” | `PATCH cart/items/{offer}` z `accept_price_cents` | kupujący przyjmuje nową cenę; działa tylko, gdy wysłana cena = obecna („Cena oferty zmieniła się ponownie — sprawdź nową cenę”) |
| pozycja koszyka: w porządku / cena się zmieniła / „Niedostępna” | `CartView::OK`, `PRICE_CHANGED`, `UNAVAILABLE` | `price_changed` = obecna cena ≠ zaakceptowana; `unavailable` = oferta spoza `listed()` |
| „Z tego koszyka powstanie N zamówień (po jednym na sprzedawcę)” | `PlaceOrdersFromCartHandler::groupBySeller` | jedno zamówienie na sprzedawcę, każde płacone osobno |
| „Do zapłaty” | `CartView` (`total_cents`) | suma wszystkich przyszłych zamówień |
| „Złóż zamówienia” | `PlaceOrdersFromCart` + `PlaceOrdersFromCartHandler`, `CartController::checkout` | patrz „Składanie” niżej |
| „Koszyk jest pusty” | `CartEmpty` (`cart_empty`) | |
| „Koszyk zmienił się w międzyczasie — odśwież stronę i sprawdź pozycje” | `CartController::checkout` (`errors.cart`) | formularz nie odpowiada koszykowi w bazie (zmiana w innej karcie) |

## Składanie zamówień

| PL | Kod | Definicja | Gdzie |
|---|---|---|---|
| złożenie zamówień | `PlaceOrdersFromCart`, `PlaceOrdersFromCartHandler` | z koszyka i cen, które kupujący widział, powstaje N zamówień `Placed`, a z koszyka znikają złożone oferty (pozycja dodana w międzyczasie w innej karcie zostaje), w jednej transakcji | `Application/Order/` |
| pozycja wejścia | `CartLine(offerId, quantity, expectedUnitPriceCents)` | to, co przyszło z formularza koszyka | `Application/Order/CartLine.php` |
| migawka oferty | port `OfferSnapshots` → `OfferSnapshot` | co Ordering wie o ofercie w chwili składania: sprzedawca, użytkownik sprzedawcy, nazwa produktu, cena, czy widoczna | `Application/Port/`, `Infrastructure/DbOfferSnapshots.php` |
| „Ceny w koszyku się zmieniły — sprawdź i złóż zamówienie ponownie” | `PriceChangedSinceCart` (`price_changed_since_cart`), lista `PriceChange` | cena oferty ≠ widziana (H5). Nic nie powstaje | `Application/Order/PriceChangedSinceCart.php` |
| „Część ofert w koszyku nie jest już dostępna” | `OfferNoLongerAvailable` (`offer_no_longer_available`) | oferta niewidoczna (`listed` fałsz). **Dostępności nie sprawdzamy** — o niej decyduje rezerwacja | `Application/Order/OfferNoLongerAvailable.php` |
| „Nie możesz kupić własnej oferty” | `CannotBuyOwnOffer` (`cannot_buy_own_offer`) | użytkownik sprzedawcy = kupujący (H12) | `Application/Order/CannotBuyOwnOffer.php` |
| blokada koszyka przy składaniu | `lockForUpdate` w `CartController::checkout` | drugie równoległe „Złóż zamówienia” czeka i widzi pusty koszyk (R9) | `apps/catalog/app/Http/Controllers/Shop/CartController.php` |

## Zamówienie

| PL | Kod | Definicja | Gdzie |
|---|---|---|---|
| zamówienie | `Order` | pozycje **jednego** sprzedawcy z zamrożoną ceną, termin płatności, wpłata, zwroty. Agregat | `Domain/Order.php` |
| numer zamówienia („Zamówienie #12”) | `OrderId` | ID z sekwencji bazy | `Domain/OrderId.php` |
| pozycja | `OrderLine(OfferId, ProductName, unitPrice, LineQuantity, LineStatus)` | jedna oferta w zamówieniu; każda oferta najwyżej raz, 1–`Order::MAX_LINES` (20) pozycji | `Domain/OrderLine.php` |
| ilość | `LineQuantity` | 1–10 sztuk | `Domain/LineQuantity.php` |
| oferta (w zamówieniu) | `OfferId` (Orderingu) | tylko ID oferty z portalu; własny VO, bez importu z portalu | `Domain/OfferId.php` |
| nazwa produktu | `ProductName` | skopiowana przy złożeniu; późniejsza zmiana w katalogu nie zmienia zamówienia | `Domain/ProductName.php` |
| cena jednostkowa (zamrożona) | `OrderLine::unitPrice()` (`Money`) | cena z chwili złożenia. Zmiana ceny oferty po złożeniu nie dotyka zamówienia (H5) | |
| „Razem” | `Order::total()` | suma pozycji, stan pochodny | |
| termin płatności („Termin: …”) | `paymentDeadline` = `placedAt + Order::PAYMENT_WINDOW` (15 min) | do kiedy bramka przyjmie wpłatę | |
| „Zostało … na zapłatę” | `PaymentCountdown.vue`, `seconds_to_deadline` | odliczanie do terminu liczone po stronie serwera | `resources/js/components/orders/PaymentCountdown.vue` |
| karencja | `Order::WEBHOOK_GRACE` (5 min) | sweep wygasza dopiero po terminie **i** karencji, żeby spóźniony webhook wpłaty przyjętej w terminie nie skończył się zwrotem (R3) | `Order::isDueForExpiry` |
| klucz rezerwacji | `OrderReservationKey::for` → `order-{id}` | jak zamówienie podpisuje się w ofertach; jeden klucz na zamówienie | `Application/Process/OrderReservationKey.php` |

### Status zamówienia (`OrderStatus`) i etykieta w UI

Etykiety w jednym miejscu: `Infrastructure/ReadModel/OrderStatusLabel.php`.

| PL (UI) | Kod | Znaczenie | Przejścia |
|---|---|---|---|
| **Sprawdzamy dostępność** | `Placed` | złożone; saga rezerwuje pozycje. Strona odświeża się co sekundę, najwyżej 120 s | → czeka na płatność (wszystkie pozycje zarezerwowane), → odrzucone, → wygasło, → anulowane (kupujący) |
| **Czeka na płatność** | `AwaitingPayment` | wszystkie pozycje zarezerwowane; „Zapłać” do terminu | → opłacone, → wygasło, → anulowane (kupujący) |
| **Opłacone** | `Paid` | wpłata zaksięgowana; u sprzedawcy w „Do wysłania” | → wysłane, → anulowane (sprzedawca, ze zwrotem) |
| **Wysłane** | `Shipped` | **końcowy** dla statusu | brak |
| **Odrzucone** | `Rejected` | któraś pozycja nie dostała towaru; powód w `Rejection` | brak |
| **Wygasło** | `Expired` | termin + karencja minęły bez wpłaty („Czas na płatność minął.”) | brak |
| **Anulowane** | `Cancelled` | przez kupującego (przed płatnością) albo sprzedawcę (po płatności) | brak |

„Końcowy” dotyczy statusu: zwroty mogą się w nim jeszcze rozliczać.

| PL (UI) | Kod | Definicja |
|---|---|---|
| „Czekamy na potwierdzenie płatności” | prop `awaiting_confirmation` (`OrderController::show`) | kupujący wrócił z bramki (`?payment=returned`), a webhook jeszcze nie dotarł. To **nie** jest status; „Zapłać” jest wtedy ukryte przez 30 s |
| status pozycji | `LineStatus` (`Pending`, `Reserved`) | czy saga już zarezerwowała tę pozycję. W UI nie występuje |
| zarezerwowanie pozycji | `Order::markLineReserved` | T2 kroku rezerwacji; gdy ostatnia → `AwaitingPayment` |

### Powody i rozliczenia

| PL (UI) | Kod | Definicja |
|---|---|---|
| odmowa, powód odrzucenia | `Rejection(RejectionReason, OfferId)`, `Order::reject` | pierwsza odmowa rezerwacji kończy całe zamówienie |
| „Zabrakło towaru: {produkt}” | `RejectionReason::OutOfStock` (`out_of_stock`) | rezerwacja: więcej niż dostępne |
| „Cena się zmieniła: {produkt}” | `RejectionReason::PriceChanged` (`price_changed`) | rezerwacja: cena zamówienia ≠ obecna cena oferty |
| „Oferta niedostępna: {produkt}” | `RejectionReason::OfferUnavailable` (`offer_unavailable`) | rezerwacja: oferta nieopublikowana, sprzedawca nie może sprzedawać albo oferty nie ma |
| anulowanie | `Cancellation(CancelledBy, ?Reason)` | kto i (u sprzedawcy) dlaczego |
| „Anulowałeś zamówienie” | `Order::cancelByBuyer`, `CancelledBy::Buyer` | w `Placed` i `AwaitingPayment`. Zwalnia rezerwacje |
| „Sprzedawca anulował zamówienie: {powód}” | `Order::cancelBySeller(Reason)`, `CancelledBy::Seller` | tylko w `Paid`; zwrot całej wpłaty. **Stanu ofert nie oddaje** (H8) |
| powód anulowania | `Reason` (Orderingu) | niepusty po przycięciu, ≤ 500 znaków |
| „Numer przesyłki” | `TrackingNumber`, `Shipment(trackingNumber, shippedAt)` | 3–64 znaki po przycięciu |
| „Oznacz jako wysłane” | `Order::ship`, `ShipOrder` | „wysłać można tylko opłacone” |

## Płatność i zwroty

| PL (UI) | Kod | Definicja | Gdzie |
|---|---|---|---|
| „Zapłać” (w sklepie) | `StartPayment`, `StartPaymentHandler`, `Order::requirePayable` | otwiera **nową** sesję w bramce (ponowienie po odmowie, H7); tylko w `AwaitingPayment`. Po terminie (jeszcze przed sweepem) przycisk znika, bo bramka i tak by nie przyjęła wpłaty | `Application/Order/StartPaymentHandler.php` |
| sesja płatności | `fake_gateway_payments`, `FakePaymentGateway::createPayment` | wygasa dokładnie w `paymentDeadline` („Sesja wygasła — wróć do sklepu i rozpocznij płatność”) | `apps/catalog/app/FakePaymentGateway/FakePaymentGateway.php` |
| „Zapłać” / „Odmów” (w bramce) | `FakePaymentGateway::decide` | decyzja kupującego; druga decyzja tej samej sesji to no-op | |
| webhook płatności | `POST /webhooks/payments`, `PaymentWebhookController` | podpisany HMAC-SHA256 (`X-Signature`); ACL tłumaczy payload na `RecordPayment` / `RecordPaymentFailure`. Pusty sekret w konfiguracji = 500 (fail closed), kwota < 1 gr = zepsuty payload | `apps/catalog/app/Http/Controllers/Webhooks/PaymentWebhookController.php` |
| ID płatności | `PaymentId` | identyfikator z bramki; po nim rozpoznajemy zdublowany webhook (R4) | `Domain/PaymentId.php` |
| wpłata (zaksięgowana) | `Payment(paymentId, amount, paidAt)`, `Order::recordPayment` | działa w **każdym** statusie: przyjmuje, rozpoznaje duplikat albo zleca zwrot (H6, H10) | `Domain/Order.php` |
| „Płatność przyjęta” | `OrderPaid` | `AwaitingPayment` i kwota = suma | |
| „Płatność nieudana” | `OrderPaymentFailed`, `Order::recordPaymentFailure` | status bez zmian, można ponowić (H7) | |
| „Wpłata po terminie” | `LatePaymentReceived` | **każda** wpłata, której zamówienie nie przyjmuje, także podwójna i z błędną kwotą (rozstrzygnięcie R15). Zawsze przed `RefundRequested`, a różnicę niesie `RefundReason` | `Domain/Event/LatePaymentReceived.php` |
| zwrot | `Refund(refundId, paymentId, amount, reason, status)` | pieniądze do oddania kupującemu | `Domain/Refund.php` |
| ID zwrotu | `RefundId` = `refund-{orderId}-{n}` | nadaje domena; to też **klucz idempotencji** w bramce | `Domain/RefundId.php` |
| powód zwrotu | `RefundReason`: `LatePayment`, `DuplicatePayment`, `AmountMismatch`, `CancelledBySeller` | po terminie / druga wpłata / zła kwota / anulowanie przez sprzedawcę | `Domain/RefundReason.php` |
| „Zwrot X — w toku” / „— zwrócono” | `RefundStatus::Requested` / `Completed` | `Requested`, dopóki bramka nie potwierdzi; job ponawia z backoffem | `Domain/RefundStatus.php` |
| „Zlecono zwrot” / „Zwrot zakończony” | `RefundRequested` / `RefundCompleted`, `Order::markRefundCompleted` | | |

## Przyciski i dostęp

| PL (UI) | Kod | Definicja |
|---|---|---|
| akcja | `OrderAction` (`Pay`, `Cancel`, `Ship`, `CancelBySeller`) | operacja, którą UI może pokazać |
| dozwolone akcje | `Order::allowedActionsFor(Actor)` | **jedyne** źródło przycisków („Zapłać”, „Anuluj zamówienie”, „Oznacz jako wysłane”, „Anuluj ze zwrotem”) |
| „Anuluj ze zwrotem” | `OrderAction::CancelBySeller`, `CancelOrderBySeller` | potwierdzenie mówi: „Kupujący dostanie zwrot … Stan oferty nie wróci automatycznie.” |
| „Tej operacji nie można wykonać na zamówieniu w obecnym stanie” | `InvalidOrderTransition` (`invalid_order_transition`) | akcja niedozwolona w statusie |
| cudze zamówienie | `OrderAccess::forBuyer`, `OrderAccess::forSeller`, `OrderNotFound` (`order_not_found`) | dla użytkownika **404**, nie 403 |
| niepoprawne pozycje | `InvalidOrderLines` (`invalid_order_lines`) | 0 albo > 20 pozycji, oferta dwa razy, inna waluta. Z koszyka osiągalne są dwie: > 20 ofert jednego sprzedawcy i dwie waluty u jednego sprzedawcy; koszyk pokazuje wtedy „Tego koszyka nie da się złożyć — u jednego sprzedawcy zamówisz najwyżej 20 różnych ofert, wszystkie w jednej walucie”. Pozostałe to błąd w kodzie |

## Proces (saga)

| PL | Kod | Definicja | Gdzie |
|---|---|---|---|
| process manager, saga | `OrderProcessManager` | słucha zdarzeń `Order` i kolejkuje następny krok. Bez własnego stanu ([ADR-0007](adr/0007-process-manager-ze-stanem-w-zamowieniu.md)) | `Application/Process/OrderProcessManager.php` |
| krok sagi | `ReserveOrderLines`, `ReleaseOrderReservations`, `ConfirmOrderReservations`, `RefundPayment` | jednostka pracy uruchamiana przez job; każdy idempotentny | `Application/Process/` |
| job sagi | `ReserveOrderLinesJob`, `ReleaseOrderReservationsJob`, `ConfirmOrderReservationsJob`, `RefundPaymentJob` | wiersz w `jobs` na połączeniu `queue.ordering_process_connection` (`database`), `tries = 5`, `backoff = [5, 30, 120]` | `Infrastructure/Job/` |
| kolejka sagi | port `ProcessJobQueue` → `LaravelProcessJobQueue` | wstawia job w transakcji zapisu zamówienia (outbox komend) | `Infrastructure/LaravelProcessJobQueue.php` |
| T1 / T2 | — | dwie transakcje kroku rezerwacji: T1 w ofercie (port), T2 `markLineReserved` w zamówieniu | `ReserveOrderLines::run` |
| kompensacja | `ReleaseOrderReservations`, `RefundPayment`, gałąź R1 w `ReserveOrderLines` i jej odpowiednik na starcie ponowionego kroku (R1 + R2) | cofnięcie skutku kroku, który już się wydarzył (oddanie sztuk, oddanie pieniędzy) | [`10-DDD.md`](10-DDD.md), moduł 12 |
| trzyma rezerwacje | `Order::holdsReservations` | zamówienie wciąż potrzebuje sztuk: `Placed` (jeszcze rezerwuje), `AwaitingPayment`, `Paid`, `Shipped`, `Cancelled` przez sprzedawcę (H8). `Rejected`, `Expired`, `Cancelled` przez kupującego z nich zrezygnowały | `Domain/Order.php` |
| rezerwacje (port) | `StockReservations` (`reserve`, `release`, `confirm`) → `SellerPortalStockReservations` | Ordering mówi, czego potrzebuje; adapter woła komendy portalu i tłumaczy jego wyjątki | `Application/Port/StockReservations.php`, `Infrastructure/SellerPortalStockReservations.php` |
| wynik rezerwacji | `ReservationResult` (`Reserved`, `OutOfStock`, `PriceChanged`, `OfferUnavailable`) | odmowy biznesowe jako wynik; reszta (np. `ReservationConflict`) leci jako wyjątek | `Application/Port/ReservationResult.php` |
| ponowienie przy konflikcie | `Ordering\Application\RetryOnConflict` | do 3 prób na świeżym stanie; kopia portalowej klasy ([ADR-0008](adr/0008-shared-kernel.md)) | `Application/RetryOnConflict.php` |
| wygaszenie | `Order::expire`, `ExpireOverdueOrders` | `Placed` albo `AwaitingPayment` po terminie i karencji → `Expired` (R10). Błąd jednego zamówienia nie przerywa przebiegu: trafia do `ExpireOverdueOrdersResult::failures`, komenda go loguje | |
| kontener schedulera | `catalog-scheduler` (`schedule:work`) | uruchamia sweep co minutę; `/health` pokazuje `ordering_sweep_last_run_at`. RUNBOOK [#044](RUNBOOK.md#044) | `compose.yaml` |

## Zdarzenia

Zdarzenia `Order` są **wyłącznie domenowe**: słucha ich process manager,
outbox ich nie publikuje (nie ma konsumenta, zasada z
[ADR-0003](adr/0003-zdarzenia-sprzedawcy-wewnetrzne.md)). Zapisuje je
`Infrastructure/Persistence/OrderEventLog.php` do tabeli `order_events`
(jawna mapa nazw) pod oś „Historia zamówienia” (`OrderTimeline.vue`).

| PL (oś zdarzeń) | Zdarzenie domenowe | `order_events.event_type` |
|---|---|---|
| Złożono zamówienie | `OrderPlaced` | `order.placed` |
| Zarezerwowano towar | `OrderLineReserved` | `order.line_reserved` |
| Czeka na płatność | `OrderAwaitingPayment` | `order.awaiting_payment` |
| Zamówienie odrzucone | `OrderRejected` | `order.rejected` |
| Płatność przyjęta | `OrderPaid` | `order.paid` |
| Płatność nieudana | `OrderPaymentFailed` | `order.payment_failed` |
| Wpłata po terminie | `LatePaymentReceived` | `order.late_payment_received` |
| Zamówienie wygasło | `OrderExpired` | `order.expired` |
| Zamówienie anulowane | `OrderCancelled` (z `by`) | `order.cancelled` |
| Zamówienie wysłane | `OrderShipped` | `order.shipped` |
| Zlecono zwrot | `RefundRequested` | `order.refund_requested` |
| Zwrot zakończony | `RefundCompleted` | `order.refund_completed` |

Po stronie oferty (portal, Published Language, outbox): `offer.stock_reserved`,
`offer.reservation_released`, `offer.reservation_confirmed`
(słownik portalu, sekcja „Rezerwacje”).

## Sprzedawca

| PL (UI) | Kod | Definicja |
|---|---|---|
| „Do wysłania” (zakładka, licznik na pulpicie) | `SellerOrderList` z filtrem `Paid`, `SellerDashboardStats` (`orders_to_ship`) | opłacone zamówienia tego sprzedawcy |
| „Wszystkie” | `SellerOrderList` bez filtra | |
| „Nic nie czeka na wysyłkę.” | pusta lista „Do wysłania” | |
| „Złożone {data}” | `Order::placedAt()` | |

## Bramka: narzędzia deweloperskie

| PL (UI bramki) | Kod | Definicja |
|---|---|---|
| „Wyślij webhook ponownie” | akcja `resend` w `FakePaymentGateway::decide` | ten sam webhook drugi raz (R4); tylko `local` i testy (`developerToolsEnabled`) |
| „Zapłać mimo wygaśnięcia” | akcja `pay_expired` | wpłata po terminie sesji (H6); tylko `local` i testy |
| pierwszy zwrot pada | `FAKE_GATEWAY_FAIL_FIRST_REFUND` | pokazuje ponowienie joba zwrotu („w toku” → „zwrócono”) |

## Modele odczytu

`Infrastructure/ReadModel/`: `BuyerOrderList` („Moje zamówienia”),
`BuyerOrderDetails` (szczegóły z osią zdarzeń), `SellerOrderList`,
`SellerOrderDetails`, `CartView` (koszyk z aktualnymi cenami i dostępnością),
`OrderStatusLabel` (etykiety statusów). Zapytania SQL do tablic, bez agregatów,
jak w portalu.
