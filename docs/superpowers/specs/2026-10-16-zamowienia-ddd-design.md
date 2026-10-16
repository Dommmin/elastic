# Zamówienia i rezerwacja stanu: pełne DDD, saga + porównanie z DDD-lite

Data: 2026-10-16 · Status: projekt do przeglądu, przed planem

## Cel

Nauczyć się tego, czego portal sprzedawcy nie wymagał: **reguł obejmujących
kilka agregatów naraz**. Konkretnie: process manager (saga), eventual
consistency, kompensacja, idempotencja i wyścigi. Do tego porównanie
z alternatywą, którą wybrałby pragmatyczny zespół: jedna transakcja
z blokadami pesymistycznymi.

Funkcja: **kupujący zamawia z ofert**. Koszyk z ofertami wielu sprzedawców,
przy złożeniu dzielony na zamówienia per sprzedawca. Zamówienie rezerwuje
towar, czeka na płatność przez (symulowaną) bramkę, sprzedawca je wysyła
albo anuluje ze zwrotem pieniędzy.

Fundament: kontekst Seller Portal (`apps/catalog/src/SellerPortal`), agregat
`Offer` ze `Stock` ([spec portalu](2026-10-15-portal-sprzedawcy-ddd-design.md)).

W odróżnieniu od portalu (rekonstrukcja Event Stormingu w module 1
`10-DDD.md`) tu **warsztat odbył się przed specem** (sekcja 1). Hot spoty
z warsztatu to reguły tego specu.

Ustalony zakres (decyzje użytkownika):
- koszyk wielosprzedawcowy, **jedno zamówienie na sprzedawcę, każde płacone osobno**,
- **symulowana bramka płatności** w repo (bez prawdziwego dostawcy),
- po płatności: **wysyłka i anulowanie przez sprzedawcę** (ze zwrotem),
- **gałąź porównawcza DDD-lite** z pomiarami; wszystko lokalnie, bez k3s.

## Kryteria sukcesu

1. Kupujący dodaje do koszyka oferty dwóch sprzedawców, składa zamówienia
   (powstają dwa), płaci za nie przez bramkę, a każdy sprzedawca widzi swoje
   zamówienie w „Do wysłania” i oznacza je jako wysłane.
2. Ostatnia sztuka zarezerwowana przez kupującego znika z `/search` w ciągu
   kilku sekund i wraca po wygaśnięciu albo anulowaniu zamówienia.
3. Brak towaru na jednej pozycji odrzuca zamówienie i zwalnia pozostałe
   pozycje. Dostępność ofert wraca co do sztuki.
4. Zmieniona cena blokuje złożenie zamówień z komunikatem, która cena się
   zmieniła. Zmiana ceny po złożeniu nie dotyka zamówienia.
5. Wpłata po terminie, podwójna wpłata i anulowanie opłaconego zamówienia
   przez sprzedawcę kończą się zwrotem pieniędzy. Zdublowany webhook nie
   zmienia niczego.
6. Sprzedawca nie zmniejszy stanu poniżej liczby zarezerwowanych sztuk.
7. `orders:race-test` (50 równoległych zamówień na 10 ostatnich sztuk, prawdziwy
   Postgres i workery): dokładnie 10 opłaconych, zero oversellingu, w obu gałęziach.
8. Testy domeny Orderingu trwają łącznie poniżej sekundy. Test architektury
   pilnuje granic obu kontekstów i pada w CI.
9. Gałąź `zamowienia-ddd-lite` przechodzi **te same** testy HTTP zamówień.
   Dokument porównawczy opiera się na zmierzonych liczbach.
10. Etap kończą commity i przewodnik krok po kroku w `docs/blog/`.

Poza zakresem (świadomie): doręczenie i zwroty towaru (osobny podprojekt),
koszty wysyłki, jedna płatność za wiele zamówień, koszyk gościa, los zamówień
zablokowanego sprzedawcy (hot spot H11), powiadomienia e-mail, rekonsyliacja
rezerwacji (sekcja 5), zdarzenia `order.*` w outboxie, wdrożenie na k3s
(osobny krok, razem z zaległym pushem portalu sprzedawcy).

## 1. Event Storming: „kupujący zamawia z oferty”

### Krok 1: chaos

> Dodano do koszyka · Zamówienie złożone · Towar zarezerwowany · Brak towaru
> przy rezerwacji · Cena się zmieniła · Płatność rozpoczęta · Płatność
> przyjęta · Płatność odrzucona · Termin płatności minął · Rezerwacja
> zwolniona · Zamówienie opłacone · Towar sprzedany · Zamówienie anulowane
> przez kupującego · Zamówienie anulowane przez sprzedawcę · Paczka wysłana ·
> Paczka doręczona · Zwrot pieniędzy zlecony · Pieniądze zwrócone · Wpłata po
> terminie · *(z portalu)* Oferta wycofana · Oferta zawieszona · Cena zmieniona ·
> Stan zmieniony · Towar się skończył

### Krok 2: oś czasu i przełomy

```
KOSZYK         ZAMÓWIENIE                                          PO PŁATNOŚCI
Dodano ──▶ ║ Zamówienie złożone ◀── przełom 1: ceny zamrożone, towar trzymany
           │   ├─ Towar zarezerwowany (każda pozycja)  /  Brak towaru → Odrzucone
           │   ├─ Płatność odrzucona (można ponowić)
           │   ├─ Termin minął → Wygasło  /  Kupujący anulował
           ║ Zamówienie opłacone ◀── przełom 2: pieniądze są, rezerwacja staje się sprzedażą
           │   ├─ Sprzedawca anulował → Zwrot pieniędzy
           ║ Paczka wysłana ◀── przełom 3: odtąd tylko zwroty towaru (inny proces)
```

„Paczka doręczona” zostaje na ścianie, ale poza zakresem: należy do procesu zwrotów towaru.

### Krok 3: komendy i aktorzy

| Aktor | Komenda | Zdarzenie(a) |
|---|---|---|
| Kupujący | Złóż zamówienia (z koszyka, z cenami, które widział) | Zamówienie złożone **albo** odmowa „cena się zmieniła” |
| *Proces* | Zarezerwuj towar (pozycja) | Towar zarezerwowany **albo** Brak towaru / Cena się zmieniła / Oferta niedostępna |
| Kupujący | Zapłać → **bramka płatności** (system zewnętrzny) | Płatność przyjęta / odrzucona |
| Zegar | Wygaś nieopłacone | Zamówienie wygasło |
| Kupujący | Anuluj (przed płatnością) | Zamówienie anulowane |
| Sprzedawca | Anuluj opłacone (z powodem) | Zamówienie anulowane + Zwrot zlecony |
| Sprzedawca | Oznacz jako wysłane (numer przesyłki) | Paczka wysłana |
| *Proces* | Zleć zwrot → bramka | Pieniądze zwrócone |

*Zarezerwuj* daje kilka różnych zdarzeń, więc ktoś po drodze decyduje według
reguły. Ten ktoś musi znać stan magazynu, więc to **oferta**, nie zamówienie.

### Krok 4: polityki

- Gdy zamówienie złożone → zarezerwuj każdą pozycję.
- Gdy jakaś pozycja nie dostała towaru → odrzuć zamówienie **i zwolnij już zarezerwowane** (kompensacja).
- Gdy wszystko zarezerwowane → czekaj na płatność do terminu.
- Gdy płatność przyjęta → zamień rezerwacje na sprzedaż (stan maleje).
- Gdy termin minął / kupujący anulował / zamówienie odrzucone → zwolnij rezerwacje.
- Gdy wpłata przyszła po terminie albo drugi raz → zleć zwrot (kompensacja pieniędzy).
- Gdy sprzedawca anulował opłacone → zleć zwrot.
- Gdy dostępność oferty się zmieniła → zaktualizuj wyszukiwarkę (istniejący mechanizm outboxu).

### Krok 5: hot spoty i rozstrzygnięcia

| # | Hot spot | Rozstrzygnięcie | Gdzie żyje |
|---|---|---|---|
| H1 | Kiedy zmniejszamy `stock`? W koszyku, przy złożeniu, przy płatności? | Przy złożeniu **rezerwujemy**, przy płatności **sprzedajemy**. Koszyk niczego nie blokuje (porzucone koszyki trzymałyby towar w nieskończoność). Zmniejszanie dopiero przy płatności to overselling: dwóch kupujących płaci za ostatnią sztukę. | `Offer::reserve`, `Offer::confirmReservation` |
| H2 | Rezerwacja zmniejsza `stock` od razu czy osobno? | Osobno. `stock` to dalej „sztuki na stanie” deklarowane przez sprzedawcę, oferta ma listę rezerwacji, a **dostępne = stan − zarezerwowane** jest stanem pochodnym. Przy bezpośrednim zmniejszaniu sprzedawca ustawiający „10” nadpisałby 3 trzymane sztuki. | `Offer::available`, ADR-0005 |
| H3 | Sprzedawca zmniejsza stan poniżej zarezerwowanego? | Nie wolno: te sztuki są obiecane komuś, kto właśnie płaci. | `Offer::changeStock`, `StockBelowReserved` |
| H4 | Oferta wycofana albo zawieszona w trakcie płacenia | Istniejące rezerwacje są dotrzymywane: płatność może je zamienić w sprzedaż, a zwolnienie działa zawsze. Oferta przestaje przyjmować **nowe** rezerwacje. | `Offer::reserve` (tylko `Published`), `release`/`confirm` w każdym statusie |
| H5 | Cena zmieniona między koszykiem a płatnością | Zamówienie zamraża cenę w chwili złożenia. Kupujący wysyła cenę, którą widział; składanie odmawia przy rozbieżności, a rezerwacja sprawdza ją jeszcze raz (wzorzec `expectedPrice` z moderacji). Po złożeniu zmiana ceny nie dotyka zamówienia. | `PlaceOrdersFromCart`, `Offer::reserve` |
| H6 | Wpłata po terminie | Zamówienia nie ratujemy (towar mógł już trafić do kogoś innego); zwrot pieniędzy. | `Order::recordPayment` |
| H7 | Płatność odrzucona kończy zamówienie? | Nie. Kupujący może ponowić do terminu. | `Order::recordPaymentFailure` |
| H8 | Sprzedawca anuluje opłacone: stan wraca? | Nie. Anuluje zwykle dlatego, że towaru nie ma; jeśli jest, poprawi stan sam. | `Order::cancelBySeller` (bez kroku zwrotu stanu) |
| H9 | Rezerwacja i zamówienie w jednej transakcji? (ta sama baza) | Nie: każdy agregat we własnej transakcji, spina je process manager. Proces i tak trwa w czasie (płatność z zewnątrz, termin), więc process manager jest potrzebny niezależnie; zamówienie z kilku ofert blokowałoby kilka gorących wierszy naraz. Alternatywa (jedna transakcja) żyje na gałęzi lite i jest mierzona. | sekcja 3, ADR-0006 |
| H10 | Duplikaty: webhook dwa razy, job ponowiony | Każdy krok jest idempotentny: rezerwacja ma klucz, płatność ma ID z bramki, zwrot ma klucz idempotencji. | sekcja 3 |
| H11 | Zablokowany sprzedawca z opłaconymi zamówieniami | **Otwarte, poza zakresem.** Wymaga decyzji biznesowej (masowe zwroty? realizacja mimo blokady?). Nowych rezerwacji zablokowany sprzedawca nie dostaje (`reserve` wymaga `canSell()`). | — |
| H12 | Sprzedawca kupuje u siebie | Zabronione: pompuje statystyki, nie ma sensu biznesowego. | `PlaceOrdersFromCart`, `CannotBuyOwnOffer` |

### Krok 6: agregaty

- **`Order`** (nowy kontekst Ordering): pozycje z zamrożoną ceną, status, termin,
  płatność, zwroty. Pilnuje: jeden sprzedawca, jedna waluta, przejścia statusów,
  „wysłać można tylko opłacone”.
- **`Offer`** (Seller Portal, rozszerzony): rezerwacje żyją **w ofercie**, bo
  niezmiennik „nie zarezerwujesz więcej, niż jest dostępne” dotyczy stanu
  i wszystkich rezerwacji naraz. Osobny agregat „Rezerwacja” zostawiłby ten
  niezmiennik bez właściciela.
- **Koszyk** to nie agregat: zwykła tabela (poddomena supporting, moduł 10).
- **Płatności** to poddomena generic: bramka jako system zewnętrzny za portem i ACL.

### Krok 7: modele odczytu

„Moje zamówienia” (status na żywo, bo rezerwacja jest asynchroniczna),
„Zamówienia do wysłania” u sprzedawcy, „zarezerwowane / dostępne” w szczegółach
oferty, koszyk z aktualnymi cenami i dostępnością (sekcja 4).

## 2. Strategia: poddomeny, konteksty, mapa

| Poddomena | Typ | Styl w kodzie | Gdzie |
|---|---|---|---|
| Zamówienia: `Order`, process manager | **core** | pełne DDD | `apps/catalog/src/Ordering/` (Domain / Application / Infrastructure) |
| Oferty sprzedawców + **rezerwacje** | core (istniejący) | pełne DDD, rozszerzenie | `apps/catalog/src/SellerPortal/` |
| Koszyk | supporting | Laravel way (Eloquent, kontroler) | `apps/catalog/app/Models/CartItem.php`, kontroler koszyka |
| Płatności | generic | symulowana bramka jako „obcy system” | `apps/catalog/app/FakePaymentGateway/` |

```
Tożsamość ──[Conformist: BuyerId = users.id]──▶ Ordering
Ordering ──[port StockReservations → komendy aplikacyjne Seller Portal]──▶ Seller Portal
         (Customer/Supplier: Ordering jest klientem; portal wystawia reserve / release / confirm,
          domena portalu zna tylko ReservationKey, nie Order)
Ordering ──[port OfferSnapshots (odczyt)]──▶ Seller Portal / katalog
Ordering ──[port PaymentGateway]──▶ Fake gateway ──[podpisany webhook]──▶ ACL (PaymentWebhookController)
Seller Portal ──[outbox offer.*, nowe nazwy rezerwacji]──▶ RabbitMQ ──▶ Search
Ordering + Seller Portal ──[Shared Kernel: Money, Currency, klasy bazowe]
Ordering ──✗── outbox (zdarzenia order.* bez konsumenta, zasada ADR-0003)
```

| Relacja | Wzorzec | Uzasadnienie |
|---|---|---|
| Ordering → Seller Portal (rezerwacje) | **Customer/Supplier** przez port po stronie Orderingu | Ordering definiuje, czego potrzebuje (`StockReservations`); adapter w `Ordering\Infrastructure` woła komendy aplikacyjne portalu i tłumaczy jego wyjątki na `ReservationResult`. Przy rozdzieleniu na usługi zmienia się tylko adapter. |
| Ordering ↔ Seller Portal (pieniądze) | **Shared Kernel** | Jedna definicja pieniądza. Kopia `Money` rozjechałaby się przy pierwszej zmianie zaokrągleń. Koszt (zmiana wymaga zgody obu kontekstów) jest niski, bo te klasy są stabilne. ADR-0007. |
| Ordering ← bramka | **ACL** | Kontroler webhooka jest jedyną klasą, która zna format bramki. |
| Ordering ← Tożsamość | **Conformist** | `BuyerId` opakowuje `users.id`, tak jak `UserId` w portalu. |

Zdarzenia `Order` są wyłącznie domenowe: słucha ich process manager, outbox
ich nie publikuje (brak konsumenta). Search dowiaduje się o skutkach przez
zdarzenia ofert.

**Shared Kernel** `apps/catalog/src/SharedKernel/` (PSR-4 `SharedKernel\`):
`Money`, `Currency`, `CurrencyMismatch`, `InvalidValue`, `AggregateRoot`,
`DomainEvent`, `DomainException`, `ConcurrencyConflict`. Przenosimy je
z `SellerPortal\Domain\Shared` jednym mechanicznym commitem (zmiana
importów, bez zmiany zachowania). W `SellerPortal\Domain\Shared` zostaje to,
co dotyczy tylko portalu (`UserId`, `ModeratorId`, `Reason`).

## 3. Zmiany w agregacie `Offer` (Seller Portal)

Nowe value objects w `SellerPortal\Domain\Offer`:
- `ReservationKey`: nieprzezroczysty napis (1–64 znaki) nadany przez klienta
  rezerwacji. Portal nie wie, że to ID zamówienia; Ordering podaje `order-{id}`.
- `Quantity`: liczba sztuk 1–100 000.
- `Reservation(key, quantity, reservedAt)`.

Oferta trzyma listę **aktywnych** rezerwacji (`list<Reservation>`).

| Metoda | Reguły | Zdarzenie |
|---|---|---|
| `reserved(): int`, `available(): int` | `available = stock − Σ quantity`; stan pochodny | — |
| `reserve(Seller, ReservationKey, Quantity, Money expectedUnitPrice, now)` | tylko `Published` (inaczej `OfferNotReservable`); `seller->canSell()`, inaczej `SellerCannotSell` (blokada zawiesza oferty asynchronicznie, a bez tego warunku jest okno, w którym zablokowany sprzedaje); `expectedUnitPrice` równa obecnej cenie, inaczej `ReservationPriceMismatch` (oczekująca zmiana ceny nie ma znaczenia, liczy się obecna); `quantity ≤ available`, inaczej `InsufficientStock`; ten sam klucz z tą samą ilością to no-op, z inną ilością `ReservationConflict` | `OfferStockReserved(offerId, key, quantity, available, listed)` |
| `releaseReservation(ReservationKey, now)` | w **każdym** statusie (H4); brak aktywnej rezerwacji to no-op | `OfferReservationReleased(offerId, key, quantity, available, listed)` |
| `confirmReservation(ReservationKey, now)` | w każdym statusie; `stock −= quantity`, rezerwacja znika, `available` się nie zmienia; brak aktywnej rezerwacji → `ReservationNotFound` | `OfferReservationConfirmed(offerId, key, quantity, stock)` |
| `changeStock(Stock, now)` | **nowy niezmiennik:** `stock ≥ reserved()`, inaczej `StockBelowReserved`; `OfferSoldOut` / `OfferBackInStock` liczone od przejścia `available` przez zero (dalej tylko na `Published`) | jak dziś |
| `isListed()` / `isSoldOut()` | `Published` i `available > 0` / `available = 0` | — |
| `publish` | bez zmian (`stock > 0`; szkic nie ma rezerwacji) | — |
| `withdraw` | **nie** zwalnia rezerwacji (H4) | — |

**Idempotencja potwierdzenia.** `confirmReservation` na brakującym kluczu nie
może być cichym no-opem: kolejność „zwolniona, potem potwierdzona” oznaczałaby
sprzedaż bez zmniejszenia stanu. Maszyna stanów `Order` takiej kolejności nie
dopuszcza, ale obrona ma być w modelu, nie w nadziei. Dlatego:
- tabela `offer_reservations(offer_id, reservation_key, quantity, status
  active|confirmed|released, reserved_at, settled_at)` z unikalnym
  `(offer_id, reservation_key)`,
- agregat wczytuje tylko wiersze `active`,
- handler `ConfirmReservationHandler`: jeśli oferta nie ma aktywnej rezerwacji
  z tym kluczem, pyta repozytorium o status klucza. `confirmed` → no-op
  (ponowiony job), `released` albo brak → `ReservationNotFound` (błąd, job
  ląduje w `failed_jobs`).

Wniosek do przewodnika: **idempotencja wymaga pamięci**.

Komendy aplikacyjne portalu (`SellerPortal\Application\Reservation\`):
`ReserveStock`, `ReleaseReservation`, `ConfirmReservation` + handlery. Każdy
działa w transakcji na jednej ofercie i ponawia `ConcurrencyConflict` do 3 razy
(wzorzec `SuspendOffersOfBlockedSeller`).

**Zdarzenia integracyjne** (`OfferIntegrationEvents::translate`), nowe nazwy:
`offer.stock_reserved`, `offer.reservation_released`,
`offer.reservation_confirmed`, z `available` i `listed` w payloadzie. Klucz
rezerwacji **nie** trafia do payloadu (to szczegół Orderingu). Projekcja
produktu (`ProductProjectionController`) liczy `in_stock` i `offers[].stock`
z dostępności, a scope `listed()` na modelu Eloquenta `Offer` uwzględnia
rezerwacje (`stock > (aktywne rezerwacje)`). Domenowa definicja `isListed()`
i scope muszą pozostać zgodne; pilnuje tego test (jak dziś w ADR-0004).

UI portalu: w szczegółach oferty „Na stanie N · zarezerwowane R · dostępne A”;
formularz stanu z komunikatem dla `stock_below_reserved` w `DomainErrorMessages`.

## 4. Agregat `Order` (kontekst Ordering)

```
Placed ──wszystkie pozycje zarezerwowane──▶ AwaitingPayment ──płatność──▶ Paid ──wysłane──▶ Shipped
  │ brak towaru / cena / oferta niedostępna        │ termin / kupujący            │ sprzedawca anuluje (+zwrot)
  ▼                                                ▼                              ▼
Rejected                                    Expired / Cancelled               Cancelled
(z Placed: także Expired i Cancelled przez kupującego)
```

Status `OrderStatus`: `Placed`, `AwaitingPayment`, `Paid`, `Shipped`,
`Rejected`, `Expired`, `Cancelled`. Końcowe: `Shipped`, `Rejected`, `Expired`,
`Cancelled` (końcowe dla statusu; zwroty mogą się jeszcze rozliczać).

**Stan:**
- `OrderId`, `BuyerId`, `SellerId` (własne VO Orderingu; bez importu z portalu),
- `lines: list<OrderLine>`; `OrderLine(OfferId, productName, unitPrice Money,
  quantity LineQuantity, status Pending|Reserved)`,
- `total()` jako pochodna,
- `placedAt`, `paymentDeadline` = `placedAt + 15 min`,
- `payment: ?Payment(paymentId, amount, paidAt)` (zaksięgowana wpłata),
- `refunds: list<Refund(refundId, paymentId, amount, reason, status Requested|Completed)>`,
- `rejection: ?RejectionReason` (`OutOfStock`, `PriceChanged`, `OfferUnavailable`) z ID oferty,
- `cancellation: ?Cancellation(by Buyer|Seller, reason?)`,
- `shipment: ?Shipment(trackingNumber, shippedAt)`,
- `version` (optymistyczna blokada w repozytorium, jak w portalu).

**Niezmienniki:** 1–20 pozycji; każda oferta najwyżej raz; `LineQuantity` 1–10;
jedna waluta; jeden sprzedawca na zamówienie; przejścia tylko jak na diagramie.

| Metoda | Dozwolone w | Skutek | Zdarzenie |
|---|---|---|---|
| `place(...)` | — | `Placed`, termin | `OrderPlaced` |
| `markLineReserved(OfferId)` | `Placed` | pozycja `Reserved`; gdy wszystkie → `AwaitingPayment`; ponowienie dla zarezerwowanej → no-op | `OrderLineReserved`, potem `OrderAwaitingPayment` |
| `reject(RejectionReason, OfferId)` | `Placed` | `Rejected` | `OrderRejected` |
| `recordPayment(paymentId, amount, now)` | każdy | patrz niżej | `OrderPaid` / `LatePaymentReceived` + `RefundRequested` |
| `recordPaymentFailure(paymentId, now)` | `AwaitingPayment` (w innych no-op) | bez zmiany statusu (H7) | `OrderPaymentFailed` |
| `expire(now)` | `Placed`, `AwaitingPayment`, gdy `now ≥ paymentDeadline + karencja` | `Expired`; w innym statusie no-op | `OrderExpired` |
| `cancelByBuyer(now)` | `Placed`, `AwaitingPayment` | `Cancelled` | `OrderCancelled(by: Buyer)` |
| `cancelBySeller(Reason, now)` | `Paid` | `Cancelled`, zwrot całej wpłaty | `OrderCancelled(by: Seller)` + `RefundRequested` |
| `ship(TrackingNumber, now)` | `Paid` | `Shipped` | `OrderShipped` |
| `markRefundCompleted(refundId, now)` | dowolny, z istniejącym zwrotem `Requested` | zwrot `Completed`; ponowienie → no-op | `RefundCompleted` |
| `allowedActionsFor(Actor)` | — | lista akcji dla UI (jak `Offer`) | — |

**`recordPayment`, czyli H6 i H10 w jednym miejscu:**
- `AwaitingPayment` i `amount = total` → `Paid` (`OrderPaid`).
- `Paid`/`Shipped`/`Cancelled` z tym samym `paymentId` co zaksięgowana wpłata → no-op (zdublowany webhook).
- Każdy inny przypadek (inny `paymentId` przy zaksięgowanej wpłacie, czyli podwójna
  płatność; status `Placed`, `Expired`, `Cancelled`, `Rejected`; zła kwota)
  → `LatePaymentReceived` + `RefundRequested` dla tej wpłaty. Ten sam
  `paymentId`, który już ma zwrot → no-op.

**Karencja.** Sesja płatności w bramce wygasa dokładnie w `paymentDeadline`,
więc bramka nie przyjmie wpłaty po terminie. Sweep wygasza zamówienie dopiero po
`paymentDeadline + 5 min` (karencja na spóźniony webhook). Zwrot dostaje tylko
wpłata, która naprawdę przyszła po czasie. Stałe w `Order`:
`PAYMENT_WINDOW = 15 min`, `WEBHOOK_GRACE = 5 min`.

**Składanie z koszyka** (`Ordering\Application\PlaceOrdersFromCart`):
1. Wejście: `BuyerId` i lista `(offerId, quantity, expectedUnitPrice)` z formularza.
2. Port `OfferSnapshots` (odczyt) zwraca dla każdej oferty: `sellerId`,
   `sellerUserId`, nazwę produktu, obecną cenę i czy jest `listed`.
3. Odmowy przed zapisem (żadne zamówienie nie powstaje):
   - cena ≠ `expectedUnitPrice` → `PriceChangedSinceCart` z listą różnic (H5),
   - oferta niewidoczna → `OfferNoLongerAvailable`,
   - `sellerUserId = buyer` → `CannotBuyOwnOffer` (H12).
4. Grupuje po sprzedawcy i w **jednej transakcji** tworzy N zamówień
   i czyści koszyk. To nie łamie zasady „jeden agregat na transakcję”: zasada
   chroni przed rywalizacją o *istniejące* agregaty, a nowych zamówień nikt
   inny nie blokuje. Kupujący oczekuje „wszystko albo nic”.

Dostępność **nie** jest sprawdzana przy składaniu (tylko widoczność): o niej
decyduje rezerwacja, a sprawdzenie wcześniej i tak byłoby nieaktualne.

## 5. Process manager, kompensacje, wyścigi

**Mechanika.** `OrderProcessManager` (`Ordering\Application\Process\`) słucha
zdarzeń `Order` przez dispatcher w procesie. Każda reakcja to job w kolejce
`database`, wstawiany **w tej samej transakcji** co zapis zamówienia (port
`ProcessJobQueue`, połączenie przypięte jak w `LaravelPolicyJobQueue`,
`after_commit = false`). Działa to jak outbox komend: nie ma zapisu zamówienia
bez kroku sagi i nie ma kroku bez zapisu. Stan procesu żyje w `Order`
(ADR-0006): każdy stan procesu (`Placed` = „sprawdzamy dostępność”,
`AwaitingPayment`) jest też stanem biznesowym, który widzi kupujący, więc
osobna tabela sagi byłaby duplikatem.

Zegar: polecenie `orders:expire-overdue` co minutę (`routes/console.php`,
scheduler). Sweep jest odporniejszy niż opóźniony job, bo zgubiony job nie
zostawia wiszącego zamówienia. Wymaga procesu `schedule:work`, czyli nowego
kontenera `scheduler` w compose. Sweep przetwarza zamówienia paczkami,
każde we własnej transakcji, a `ConcurrencyConflict` pomija (następny przebieg
spróbuje znowu).

**Port `StockReservations`** (`Ordering\Application\Port`):
`reserve(ReservationKey, OfferId, qty, Money unitPrice): ReservationResult`,
`release(ReservationKey, OfferId)`, `confirm(ReservationKey, OfferId)`.
`ReservationResult` to enum `Reserved | OutOfStock | PriceChanged | OfferUnavailable`.
Adapter `SellerPortalStockReservations` (`Ordering\Infrastructure`) woła komendy
aplikacyjne portalu i tłumaczy wyjątki: `InsufficientStock` → `OutOfStock`,
`ReservationPriceMismatch` → `PriceChanged`, `OfferNotReservable`
i `SellerCannotSell` → `OfferUnavailable`. To mały ACL: Ordering nigdy nie widzi
wyjątków domeny portalu.

| Zdarzenie `Order` | Job | Sukces | Porażka → kompensacja |
|---|---|---|---|
| `OrderPlaced` | `ReserveOrderLines` | wszystkie pozycje → `AwaitingPayment` | pierwsza odmowa → `reject(powód)` → `OrderRejected` → zwolnienie **wszystkich** pozycji |
| `OrderPaid` | `ConfirmOrderReservations` | stan ofert zmniejszony | brak porażki biznesowej; błąd techniczny → ponowienia, potem `failed_jobs` |
| `OrderExpired`, `OrderCancelled(by: Buyer)`, `OrderRejected` | `ReleaseOrderReservations` | rezerwacje zwolnione | — (zwolnienie jest idempotentne) |
| `RefundRequested` | `RefundPayment` | `markRefundCompleted` | ponowienia z backoffem; zwrot widoczny jako „w toku” |

**Krok rezerwacji:**

```
dla każdej pozycji Pending (w kolejności pozycji):
  T1: StockReservations.reserve(order-{id}, offer, qty, unitPrice)   ← transakcja oferty
      odmowa → T2': Order.reject(powód) i koniec
  T2: wczytaj Order:
        Placed      → markLineReserved                                 ← transakcja zamówienia
        inny status → StockReservations.release tej pozycji i koniec   ◀── R1
```

Przy `ConcurrencyConflict` na `Order` w T2 krok ponawia T2 na świeżym stanie
(do 3 razy, potem ponowienie całego joba; T1 jest idempotentne).

**Wyścigi** (każdy ma osobny test aplikacyjny z ręcznie ustawioną kolejnością kroków):

| # | Scenariusz | Rozstrzygnięcie |
|---|---|---|
| R1 | Kupujący anuluje, gdy saga właśnie zarezerwowała pozycję | Job zwalniający po anulowaniu przeszedł wcześniej i nie miał czego zwolnić. Gałąź „inny status → release” w T2 zwalnia tę pozycję. Bez niej sztuka wisiałaby zarezerwowana na zawsze. |
| R2 | Awaria między T1 a T2 | Ponowiony job: `reserve` z tym samym kluczem i ilością to no-op, potem T2. Jeśli zamówienie w międzyczasie wygasło, `OrderExpired` zwalnia wszystkie klucze, także ten. |
| R3 | Webhook płatności w chwili wygaśnięcia | Oba kroki zapisują `Order`, a wersja przepuszcza jeden. Przegrany ponawia: wygasło → zwrot; zapłacone → `expire` to no-op. Karencja 5 min sprawia, że wpłaty przyjęte przez bramkę w terminie nie trafiają w wygaśnięcie. |
| R4 | Zdublowany webhook | Ten sam `paymentId` to no-op. Endpoint odpowiada 200, żeby bramka przestała ponawiać. |
| R5 | Sprzedawca zmniejsza stan w trakcie rezerwacji | Brak wyścigu: niezmiennik w jednym agregacie, wersja oferty przepuszcza jeden zapis, a drugi po ponowieniu dostaje `StockBelowReserved` albo `OutOfStock`. Główny argument za ADR-0005. |
| R6 | Dwóch kupujących, ostatnia sztuka | Jak R5: drugi dostaje `OutOfStock`, jego zamówienie → `Rejected`. |
| R7 | Sprzedawca anuluje opłacone przed potwierdzeniem rezerwacji | Potwierdzenie i tak się wykonuje (H8), zwrot idzie niezależnie. |
| R8 | Oferta wycofana między złożeniem a rezerwacją | `OfferUnavailable` → `Rejected`. Rezerwacje już istniejące są dotrzymywane (H4). |
| R9 | Podwójny klik „Złóż zamówienia” | Koszyk czyszczony w transakcji składania; drugie żądanie widzi pusty koszyk i przekierowuje do `/orders`. |
| R10 | Zamówienie utknęło w `Placed` (padł worker) | Sweep wygasza także `Placed` po terminie i karencji, a zwolnienie jest idempotentne. System sam się naprawia. |

Świadomie nie robimy: `Order` nie śledzi, czy potwierdzenie rezerwacji po
płatności przeszło. Sygnałem trwałej awarii jest `failed_jobs`. Polecenie
rekonsyliacji („aktywne rezerwacje zamówień opłaconych”) to dalszy krok.

## 6. Bramka płatności (symulowana)

Moduł `apps/catalog/app/FakePaymentGateway/` udaje zewnętrznego dostawcę
w stylu Przelewy24 / Stripe Checkout i jest celowo **poza Orderingiem**: ma
własną tabelę `fake_gateway_payments` i własne trasy.

- `createPayment(reference, amount, expiresAt, returnUrl)` → `paymentId` i URL strony bramki.
- Strona `/fake-gateway/payments/{id}`: przyciski **Zapłać** i **Odmów**.
  Po `expiresAt` pokazuje „sesja wygasła” i nie przyjmuje wpłaty.
- Decyzja → job z opóźnieniem ~2 s → `POST /webhooks/payments` z podpisem HMAC-SHA256
  (`X-Signature`, sekret `FAKE_GATEWAY_WEBHOOK_SECRET`); ponawiany z backoffem, dopóki nie przyjdzie 200.
  Payload: `payment_id`, `reference`, `status` (`succeeded|failed`), `amount_cents`, `currency`, `occurred_at`.
- Przyciski deweloperskie (tylko `local` i testy): **Wyślij webhook ponownie** (R4)
  i **Zapłać mimo wygaśnięcia** (H6).
- `refund(paymentId, amount, idempotencyKey)`: synchroniczne API; ten sam klucz
  zwraca ten sam wynik. Flaga `FAKE_GATEWAY_FAIL_FIRST_REFUND` pokazuje ponowienia.

Po stronie Orderingu port `PaymentGateway` (`startPayment(Order) → redirectUrl`,
`refund(refundId, paymentId, amount)`) i adapter `FakeGatewayPaymentGateway`.
`PaymentWebhookController` (ACL) weryfikuje podpis (zły → 401), mapuje
`reference` na `OrderId`, a payload na komendy `RecordPayment`
i `RecordPaymentFailure`. Nieznana `reference` → 200 i ostrzeżenie w logu
(żeby bramka nie ponawiała w nieskończoność). Wymiana na prawdziwego
dostawcę to nowy adapter i kontroler, bez zmian w domenie.

`startPayment` dozwolone tylko w `AwaitingPayment`. Każde kliknięcie „Zapłać”
tworzy nową sesję w bramce (ponowienie po odmowie, H7).

## 7. UI i modele odczytu

**Kupujący** (wymaga zalogowania; koszyk gościa poza zakresem):

| Strona | Zawartość |
|---|---|
| `/products/{id}` (nowa) | produkt, lista ofert `listed`: sprzedawca, cena, dostępne, wybór ilości (1–min(10, dostępne)), „Dodaj do koszyka”. Odczyt z bazy, nie z ES. Link z `ProductCard` w wyszukiwarce. |
| `/cart` | pozycje pogrupowane per sprzedawca („zostaną złożone 2 zamówienia”), aktualne ceny i dostępność, zmiana ilości, usunięcie, „Złóż zamówienia”. Formularz wysyła widziane ceny. Przy odmowie (H5, H12, niedostępna) nic nie powstaje, a strona pokazuje, co się zmieniło. |
| `/orders` | „Moje zamówienia”: numer, sprzedawca, kwota, status po polsku. |
| `/orders/{id}` | pozycje, oś zdarzeń; `Placed`: „Sprawdzamy dostępność…” i odświeżanie co 1 s (polling Inertii); `AwaitingPayment`: „Zapłać”, odliczanie do terminu, „Anuluj”; potem status płatności, wysyłki (numer przesyłki) i zwrotów. |

**Sprzedawca** (`/seller`):
- `/seller/orders`: zakładki „Do wysłania” (`Paid`) i „Wszystkie”,
- `/seller/orders/{id}`: „Oznacz jako wysłane” (numer przesyłki), „Anuluj z powodem”
  (komunikat: kupujący dostanie zwrot pieniędzy),
- szczegóły oferty: na stanie / zarezerwowane / dostępne,
- pulpit: licznik „Do wysłania”.

Przyciski powstają z `Order::allowedActionsFor(Actor)`. Komunikaty błędów domeny
idą przez `DomainErrorMessages` (kod reguły → zdanie dla użytkownika).

**Dostęp:** kupujący widzi tylko swoje zamówienia, sprzedawca tylko te ze swoim
`SellerId`. Sprawdza to handler (wzorzec `SellerOfferAccess`); obce zamówienie → 404.

**Modele odczytu** (`Ordering\Infrastructure\ReadModel\`, SQL do tablic):
`BuyerOrderList`, `BuyerOrderDetails` (z osią zdarzeń z tabeli
`order_events`, zapisywanej przez repozytorium z zdarzeń domenowych),
`SellerOrderList`, `SellerOrderDetails`, `CartView`. Po stronie portalu
`OfferReservationSummary`.

**Persystencja Orderingu:** tabele `orders`, `order_lines`, `order_refunds`,
`order_events`; `SqlOrderRepository` z kontrolą wersji (wzorzec
`SqlOfferRepository`). Koszyk: `cart_items(user_id, offer_id, quantity)`
z unikalnym `(user_id, offer_id)`.

## 8. Testy

| Poziom | Zakres | Narzędzia |
|---|---|---|
| Domena | `Order`: każde przejście, `recordPayment` w każdym statusie, niezmienniki pozycji; `Offer`: rezerwacje, `available`, `StockBelowReserved`, idempotencja, `isListed` od dostępności | Pest, w pamięci, łącznie < 1 s |
| Aplikacja | process manager: szczęśliwa ścieżka, każda kompensacja, R1–R10; `PlaceOrdersFromCart` (H5, H12, grupowanie) | repozytoria w pamięci, fałszywe porty, sterowany zegar |
| Infrastruktura | repozytoria (wersja, statusy `offer_reservations`), atomowość joba z zapisem (wycofana transakcja nie zostawia joba), podpis webhooka, sweep z karencją, zgodność `isListed()` ze scope `listed()`, nowe zdarzenia integracyjne | baza testowa |
| HTTP | ścieżki kupującego i sprzedawcy, webhook, dostęp do cudzych zamówień → 404 | `QUEUE_CONNECTION=sync`; katalog `tests/Feature/Ordering` to **kontrakt wspólny z gałęzią lite** i nie asertuje stanów pośrednich (`Placed`) |
| Architektura | `Ordering\Domain` nie zna Laravela, `SellerPortal\*` ani warstw wyższych; `SellerPortal\Domain` nie zna `Ordering\*`; `SellerPortal\*` dotyka tylko `Ordering\Infrastructure`; `SharedKernel` nie zna nikogo | Pest arch |
| Współbieżność | `orders:race-test`: N kupujących, M ostatnich sztuk, równoległe procesy na Postgresie z workerami; raport: opłacone, odrzucone, overselling, konflikty, p95 złożenia | polecenie artisan, uruchamiane w kontenerze |

E2E na lokalnym stacku, w przeglądarce: zakup u dwóch sprzedawców → płatność →
wysyłka; brak towaru; wygaśnięcie; wpłata po terminie → zwrot; zdublowany
webhook; zniknięcie ostatniej sztuki z `/search` i jej powrót.

## 9. Gałąź porównawcza `zamowienia-ddd-lite`

- Odgałęzia się od `main` **po** skończonym C. Nie jest scalana.
- Wymienia tylko `src/Ordering` na styl lite: model Eloquent `Order` z metodami
  reguł, kontrolery bez portów i handlerów, bramka wołana wprost.
- Złożenie zamówień w **jednej transakcji**: `lockForUpdate` na ofertach
  posortowanych po `id` (inaczej dwa koszyki z tymi samymi ofertami w odwrotnej
  kolejności dają deadlock), rezerwacja przez istniejące komendy portalu wewnątrz
  tej transakcji, zamówienie od razu w `AwaitingPayment` albo odmowa.
  Reguły oferty zostają w jednym miejscu, więc zmienną porównania jest
  **koordynacja (saga kontra transakcja)** i styl Orderingu.
- Płatność, termin i zwrot zostają asynchroniczne (jobs + sweep), bo inaczej się nie da.
- Wspólne: koszyk, bramka, UI, Seller Portal, testy HTTP. Dowód uczciwości:
  pusty `git diff main..zamowienia-ddd-lite -- apps/catalog/tests/Feature/Ordering`.

Pomiary:
- linie kodu per warstwa,
- czas testów,
- miejsca obejścia reguł,
- `orders:race-test` (overselling, konflikty, p95),
- awaria w połowie (ubity worker w sadze kontra ubite żądanie w transakcji),
- **koszt nowej reguły**, ustalony z góry: „kupujący ma najwyżej 3 nieopłacone
  zamówienia naraz”, mierzony jak „cena nie niższa niż 1 zł” w porównaniu
  portalu (reguła dodana na gałęzi pomiarowej i cofnięta).

## 10. Dokumentacja

- ten spec (z Event Stormingiem),
- `docs/adr/0005-rezerwacje-w-ofercie.md` (aktualizuje ADR-0004),
  `0006-process-manager-ze-stanem-w-zamowieniu.md`, `0007-shared-kernel.md`,
- `docs/12-ZAMOWIENIA-SLOWNIK.md`, słownik 1:1 jak `11-SELLER-PORTAL-SLOWNIK.md`;
  w `11-…` dochodzą „rezerwacja”, „zarezerwowane”, „dostępne”,
- `docs/10-DDD.md`: moduł 11 (process manager i eventual consistency) i moduł 12
  (kompensacja i idempotencja), z ćwiczeniami „zepsuj i napraw” (np. usuń
  gałąź R1 z kroku rezerwacji i zobacz, które testy robią się czerwone);
  moduł 1, ćwiczenie 2, odsyła do sekcji 1 tego specu,
- `docs/blog/etap-f-zamowienia-saga.md`: przewodnik krok po kroku,
- `docs/blog/saga-vs-transakcja.md`: porównanie z liczbami,
- `RUNBOOK.md`: kontener schedulera, podgląd sagi (oś zdarzeń, `jobs`,
  `failed_jobs`), ręczne ponowienie, przyciski deweloperskie bramki.

## 11. Ryzyka

| Ryzyko | Skutek | Ograniczenie |
|---|---|---|
| Gorąca oferta (wiele rezerwacji naraz) daje serię `ConcurrencyConflict` | wolniejsze rezerwacje, ponowienia | ponowienia w handlerze i jobie; zmierzone w `orders:race-test` |
| Dwie definicje „widoczna” (`isListed()` i scope `listed()`) z rezerwacjami | oferta widoczna bez dostępnego towaru | test zgodności na tych samych danych |
| Scheduler nie działa | zamówienia nie wygasają, towar wisi | kontener `scheduler` w compose, opis w RUNBOOK, `/health` pokazuje czas ostatniego przebiegu sweepa |
| Przeniesienie do Shared Kernel psuje portal | regresja w B | osobny commit, cały zestaw testów portalu przed i po |
| Polling co 1 s na `/orders/{id}` | obciążenie przy wielu kupujących | polling tylko w `Placed` (sekundy); świadomie bez websocketów |
