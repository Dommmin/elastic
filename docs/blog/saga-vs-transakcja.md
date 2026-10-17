# Saga czy jedna transakcja? Zamówienia napisane dwa razy i zmierzone

*Projekt Marketplace Search Platform, ETAP F. Zamówienia powstały w pełnym
DDD z sagą: agregat `Order` bez Laravela, repozytorium z kontrolą wersji,
porty, process manager i cztery joby kroków (rezerwacja, zwolnienie,
potwierdzenie, zwrot). Porównanie DDD i DDD-lite przy portalu sprzedawcy
([`ddd-vs-ddd-lite.md`](ddd-vs-ddd-lite.md)) zostawiło jedną prognozę do
sprawdzenia: „dla zamówień pełne DDD, bo reguły obejmują kilka agregatów
naraz”. Tu ta prognoza idzie pod pomiar. To samo zachowanie napisałem drugi
raz jako DDD-lite: modele Eloquenta z metodami reguł i **jedna transakcja
z blokadami** zamiast sagi. Testy HTTP są te same, liczby są zmierzone.
Wynik w skrócie: lite to **33% kodu** (1389 zamiast 4155 linii), nowa
reguła kosztuje w nim mniej (7 plików zamiast 12), a połowa wyścigów sagi
w ogóle nie istnieje. Za to lite nie sprawdza wierszy z bazy, a zapomniany
`lockForUpdate()` po cichu skleja dwa stany zamówienia (pełne DDD rzuca
wtedy konflikt wersji). Testy reguł chodzą w nim ~37× wolniej. Prognoza
z poprzedniego porównania potwierdza się dla agregatu, ale **nie** dla sagi.*

> **Stan:** gałąź `zamowienia-ddd-lite` zrobiona lokalnie (commit `65df43f`,
> od `zamowienia` @ `01fe299`), **nie wypchnięta**: `git push -u origin
> zamowienia-ddd-lite` czeka na Twoją zgodę. Gałąź nie będzie scalana, to
> materiał do porównania. Gałęzie pomiarowe nowej reguły
> (`zamowienia-pomiar-reguly-ddd` @ `5dba825`,
> `zamowienia-pomiar-reguly-lite` @ `eae6915`) też są tylko lokalne.
> Pomiary z 2026-10-16, Herd PHP 8.5, SQLite w pamięci (`phpunit.xml`).
>
> **Czego jeszcze nie zmierzyłem:** dwóch pomiarów, które wymagają Postgresa
> i workerów w kontenerze (`orders:race-test` na obu gałęziach oraz awaria
> w połowie: ubity worker kontra ubite żądanie). Kontener `catalog-app`
> montuje główny checkout, więc te pomiary czekają na merge `zamowienia` do
> `main`, który musi zatwierdzić użytkownik. W rozdziałach 6 i 7 są bloki
> „Do uzupełnienia po pomiarze” z dokładnymi komendami. To, co tam piszę
> o oczekiwanym wyniku, wynika z czytania kodu i jest **hipotezą, nie
> pomiarem**.

---

## Spis treści

1. [Co dokładnie porównujemy](#1-co-dokładnie-porównujemy)
2. [Jak wygląda wersja lite (i dlaczego nie jest chochołem)](#2-jak-wygląda-wersja-lite-i-dlaczego-nie-jest-chochołem)
3. [Liczby](#3-liczby)
4. [Koszt nowej reguły: „najwyżej 3 nieopłacone zamówienia naraz”](#4-koszt-nowej-reguły-najwyżej-3-nieopłacone-zamówienia-naraz)
5. [Gdzie da się obejść reguły](#5-gdzie-da-się-obejść-reguły)
6. [Wyścigi: co znika, co zostaje](#6-wyścigi-co-znika-co-zostaje)
7. [Awaria w połowie](#7-awaria-w-połowie)
8. [Co chroni który styl, a gdzie przecieka](#8-co-chroni-który-styl-a-gdzie-przecieka)
9. [Kiedy który styl: werdykt z `ddd-vs-ddd-lite.md` pod pomiarem](#9-kiedy-który-styl-werdykt-z-ddd-vs-ddd-litemd-pod-pomiarem)
10. [Jak odtworzyć pomiary](#10-jak-odtworzyć-pomiary)
11. [Twoja kolej](#11-twoja-kolej)

---

## 1. Co dokładnie porównujemy

Dwie gałęzie z tym samym punktem startu (`zamowienia` @ `01fe299`):

| | `zamowienia` (pełne DDD + saga) | `zamowienia-ddd-lite` (DDD-lite + jedna transakcja) |
|---|---|---|
| Gdzie żyją reguły | agregat `Ordering\Domain\Order` (+ value objecty, 12 klas zdarzeń) | model `App\Models\Order` (+ `OrderLine`, `OrderRefund`) |
| Zapis | `SqlOrderRepository`: `UPDATE … WHERE version = ?`, 0 wierszy → `ConcurrencyConflict`, ponowienia do 3 razy | metoda modelu sama się zapisuje; każdy, kto zmienia zamówienie, robi to w `DB::transaction` po `lockForUpdate()` |
| Składanie | transakcja zapisuje zamówienia `Placed` + job; rezerwacja w ofertach w osobnych transakcjach (po jednej na pozycję) | **jedna** transakcja: blokada ofert rosnąco po ID → odmowy → zamówienia → rezerwacja (`ReserveStockHandler` portalu) → pusty koszyk |
| Brak towaru | zamówienie `Rejected` z powodem (kupujący widzi je na liście) | cała transakcja się wycofuje, kupujący zostaje w koszyku z komunikatem |
| Płatność, anulowanie, wygaśnięcie | zmiana zamówienia, zdarzenie, process manager kolejkuje krok (potwierdzenie / zwolnienie rezerwacji) | zmiana zamówienia i potwierdzenie / zwolnienie rezerwacji w tej samej transakcji |
| Zwrot pieniędzy | job `RefundPaymentJob` po `RefundRequested` | job `RefundPaymentJob` kolejkowany przez model w transakcji decyzji |
| Joby | 4 (`ReserveOrderLines`, `ReleaseOrderReservations`, `ConfirmOrderReservations`, `RefundPayment`) | 1 (`RefundPayment`): jedyne wywołanie zewnętrzne |
| Czas | `$now` z portu `Clock` | `now()` (w testach `travelTo`); sweep dostaje jedno `$now` na przebieg |

**Wspólne dla obu, bez zmian:** portal sprzedawcy (agregat `Offer` i jego
handlery rezerwacji), koszyk, symulowana bramka, strony Vue, migracje, modele
odczytu (`src/Ordering/Infrastructure/ReadModel`, na lite zmienił się tylko
import enuma statusu), kody wyjątków i komunikaty (`DomainErrorMessages`),
nazwy zdarzeń osi czasu w `order_events`, kontrakt HTTP kontrolerów.

**Kryterium uczciwości:** katalogi `tests/Feature/Ordering/Http` i
`tests/Feature/FakePaymentGateway` są na obu gałęziach **bajt w bajt
identyczne**:

```bash
git diff zamowienia zamowienia-ddd-lite -- apps/catalog/tests/Feature/Ordering/Http apps/catalog/tests/Feature/FakePaymentGateway
# (pusto)
```

Wszystkie 67 testów HTTP zamówień przechodzi na obu gałęziach.

> **Stan po końcowym przeglądzie `zamowienia`:** poprawki z przeglądu
> (komunikat `invalid_order_lines`, toast sprzedawcy, utwardzenie webhooka)
> zmieniły `CheckoutTest`, `SellerOrdersTest` i dodały
> `PaymentWebhookHardeningTest`. Dopóki te same poprawki nie trafią na
> `zamowienia-ddd-lite`, `git diff` powyżej nie jest pusty; liczby w tym
> dokumencie są sprzed tych poprawek.

**Historia, bo uczy (ta sama lekcja co przy portalu):** pierwsza wersja
`PaymentWebhookTest` budowała stan agregatem (`Order::place`,
`markLineReserved`, `OrderRepository::save`) i sprawdzała go przez
repozytorium. Takiego testu nie da się uruchomić na gałęzi, która nie ma
agregatu ani repozytorium, choć zachowanie jest to samo. Przed porównaniem
przepisałem go na `zamowienia` (commit `01fe299`): stan buduje prawdziwy
checkout, sweep woła komenda `orders:expire-overdue`, asercje czytają kolumny
w bazie. Treść asercji się nie zmieniła, liczba testów też nie (740).

**Jedna świadoma różnica zachowania:** brak towaru przy składaniu. Saga
przyjmuje zamówienie i odrzuca je dopiero krok rezerwacji; jedna transakcja
odmawia od razu. Tego przypadku nie da się opisać jednym testem dla obu
gałęzi, więc wyszedł z katalogu Http do
`tests/Feature/Ordering/OutOfStockCheckoutTest.php`. Plik ma tę samą nazwę na
obu gałęziach i różną treść: na `zamowienia` „zamówienie powstaje,
rezerwacja je odrzuca, kupujący widzi powód”, na lite „odmowa w koszyku,
żadne zamówienie nie powstaje, koszyk zostaje”.

---

## 2. Jak wygląda wersja lite (i dlaczego nie jest chochołem)

Celem było napisać to tak, jak napisałby dobry zespół Laravela, a nie
zbudować słabą wersję, żeby saga wypadła lepiej. Co jest w środku
(`apps/catalog` na gałęzi `zamowienia-ddd-lite`):

- **`app/Models/Order.php`** (534 linie) — metody o nazwach z języka domeny:
  `open()` (od razu `awaiting_payment`, pozycje `reserved`), `recordPayment()`
  (z prywatnym `markPaid()`, ta sama kolejność warunków co w agregacie:
  duplikat → przyjęcie → zwrot), `recordPaymentFailure()`, `expire($now)`,
  `cancelByBuyer()`, `cancelBySeller($reason)`, `ship($trackingNumber)`,
  `completeRefund()`, `requirePayable()`, `allowedActionsFor(OrderActor)`,
  `isDueForExpiry()`. Status to cast enuma `App\Enums\OrderStatus`. Strażnicy
  wartości (1–20 pozycji, każda oferta raz, jedna waluta, ilość 1–10, numer
  przesyłki 3–64 znaki po przycięciu Unicode, powód niepusty ≤ 500) są
  prywatnymi metodami modelu. `$fillable` nie zawiera `status` ani pól
  wpłaty, anulowania i wysyłki, więc `update([...])` z tablicy nie zmieni
  stanu z pominięciem metod.
- **`app/Services/Ordering/PlaceOrders.php`** (188 linii) — jedna transakcja:
  `Offer::query()->whereIn('id', …)->orderBy('id')->lockForUpdate()` (blokady
  w stałej kolejności, przeciw zakleszczeniom), te same odmowy co w sadze
  (niedostępna, własna, zmieniona cena — pod blokadą, więc bez okna wyścigu),
  `Order::open` per sprzedawca, potem **`ReserveStockHandler` portalu** dla
  każdej pozycji (reguły oferty zostają w jednym miejscu; handler trafia
  w zablokowany wiersz, więc jego kontrola wersji nie ma z kim przegrać).
  Brak towaru w którejkolwiek pozycji → `OutOfStock` z listą wszystkich
  takich ofert → wycofanie całości.
- **`OrderPayments`** (start sesji w bramce, webhook, sweep z tą samą
  5-minutową karencją i paczkami po 100), **`OrderFulfilment`** (anulowania
  i wysyłka), **`OrderRefunds`** (bramka przed transakcją, potem
  `completeRefund` pod blokadą), **`OrderStock`** (blokada ofert i handlery
  portalu `ReleaseReservation` / `ConfirmReservation`). Razem 5 serwisów,
  479 linii.
- **`app/Jobs/Ordering/RefundPaymentJob.php`** — jedyny job (`tries` 5,
  `backoff` [5, 30, 120]), na połączeniu `queue.ordering_process_connection`
  (kolejka w bazie, więc job powstaje albo ginie razem z wierszem zwrotu),
  jak w sadze.
- **Zostały, bo nie zależą od stylu:** wyjątki z `CODE` (przeniesione do
  `app/Exceptions/Ordering`), enumy statusu, akcji, aktora i powodu zwrotu
  (`app/Enums`), modele odczytu, `Money` z Shared Kernel.

**Co lite robi inaczej, bo może:**

1. Brak statusów pośrednich. `Placed` i `Rejected` zostały w enumie (kontrakt
   bazy i modeli odczytu), ale lite ich nie nadaje.
2. Oś czasu zamówienia ma `order.placed` i `order.awaiting_payment`, bez
   `order.line_reserved` (nie ma kroku, który by je zapisał). Testy HTTP
   sprawdzają tylko, że oś czasu zawiera `order.paid`.
3. Nowy kod odmowy `out_of_stock` z komunikatem „Części ofert nie ma już
   w takiej liczbie sztuk — zmniejsz ilość w koszyku”.

**Testy usunięte na lite i dlaczego** (testowały klasy, których w lite nie
ma): `tests/Unit/Ordering` (23 pliki: domena, saga i wyścigi na fałszywych
portach, repozytorium w pamięci), `tests/Arch/OrderingLayersTest.php`,
`tests/Feature/Ordering/Infrastructure/{OrderingWiringTest, ProcessJobsTest,
SqlOrderRepositoryTest, StockReservationsAdapterTest}.php`. Z
`OrdersRaceTestCommandTest` wypadł jeden test („konflikt wersji zamówienia
trafia do pliku”): w lite zamówienie nie ma optymistycznej blokady.
`ExpireOverdueOrdersCommandTest` sprawdza teraz, że wygaśnięcie zwalnia
rezerwację w tej samej transakcji (zamiast „kolejkuje job”). Zastępstwo:
`tests/Feature/Lite/OrderRulesTest.php` (te same przypadki co testy domeny)
i `OrderTransactionsTest.php` (składanie, wycofanie, joby, zwrot z awarią
bramki).

---

## 3. Liczby

### Kod produkcyjny zamówień (pliki / linie PHP)

Bez modeli odczytu, kontrolerów i komend, które są wspólne
(`git ls-tree` + `git show … | wc -l`, skrypt w [rozdziale 10](#10-jak-odtworzyć-pomiary)):

| Warstwa | Pełne DDD (`zamowienia`) | DDD-lite (`zamowienia-ddd-lite`) |
|---|---|---|
| `src/Ordering/Domain` | 42 / 1643 (w tym `Order.php` 530, 12 klas zdarzeń 315) | — |
| `src/Ordering/Application` | 42 / 1537 (komendy, handlery, wyjątki 815; 12 portów 247; proces 435; `RetryOnConflict` 40) | — |
| `Infrastructure/Persistence` (repozytorium, dziennik zdarzeń) | 2 / 423 | 0 / 0 |
| adaptery portów + provider | 9 / 343 + 1 / 74 | 0 / 0 |
| joby | 4 / 135 | 1 / 35 |
| słownik: enumy + wyjątki | (w Domain i Application) | 12 / 226 |
| modele `Order`, `OrderLine`, `OrderRefund` | — | 3 / 649 (`Order` 534) |
| serwisy `App\Services\Ordering` | — | 5 / 479 |
| **razem** | **100 / 4155** | **21 / 1389** |
| bez zmian w obu: `ReadModel` | 6 / 381 | 6 / 381 |
| kontrolery, `CheckoutRequest`, 3 komendy | 8 / 1025 | 8 / 1016 |

**Lite to 33% kodu pełnego DDD** (1389 / 4155). Przy portalu było 50%.
Różnica jest większa, bo tu odpada nie tylko mapowanie (423 linie) i porty
(247), ale cały process manager z krokami (435 linii procesu, 135 linii
jobów, 4 adaptery): to, co saga rozkłada na kroki i kompensacje, jedna
transakcja robi w miejscu. Same reguły kosztują podobnie: `Order.php` w DDD
530 linii, w lite 534 (bo niesie też zapis, oś czasu i strażników wartości,
które w DDD są osobnymi value objectami).

### Testy

| | Pełne DDD | DDD-lite |
|---|---|---|
| testy reguł | `tests/Unit/Ordering/Domain`: 6 plików, 790 linii, **80 testów** | `tests/Feature/Lite/OrderRulesTest.php`: 1 plik, 544 linie, **73 testy** |
| czas testów reguł (Pest `duration_ms`, mediana z 5) | **27 ms** (26–41) | **1015 ms** (958–1139) — ~37× wolniej |
| czas ściany z bootem (mediana z 5) | 0,28 s | 1,26 s — ~4,5× wolniej |
| na jeden test | ~0,34 ms | ~14 ms (RefreshDatabase + SQLite + fabryki) |
| testy aplikacji i sagi na fałszywych portach | 17 plików, 1607 linii, 42 testy w 45 ms (w tym 11 testów wyścigów R1–R10) | brak (nie ma czego podmieniać) |
| testy transakcji na bazie | — | `OrderTransactionsTest.php`: 194 linie, 11 testów, 347 ms |
| `tests/Feature/Ordering/Infrastructure` | 8 plików, 922 linie, 76 testów | 4 pliki, 271 linii, 15 testów |
| testy HTTP (`Feature/Ordering/Http`) | 6 plików, 998 linii, 67 testów | **te same pliki, bajt w bajt** |
| test architektury Orderingu | 1 plik, 4 testy | 0 — świadoma strata, nie ma czystej domeny do pilnowania |
| cały catalog | 740 testów (725 + 15 pominiętych), 8,5 s | 638 testów (623 + 15 pominiętych), 8,6 s |
| PHPStan (poziom z `phpstan.neon`, ścieżka `app/`) | 133 błędy | 148 (+15; 17 zgłoszeń w nowych plikach lite, głównie typy generyczne kolekcji). **Nieporównywalne:** Ordering z DDD leży w `src/`, poza ścieżkami PHPStana |

Skąd 80 kontra 73 przypadki: 12 przypadków rezerwacji (`markLineReserved`,
`reject`) w lite nie ma odpowiednika w modelu, bo nie ma statusu
pośredniego; te same reguły (wszystko zarezerwowane → czeka na płatność,
pierwsza odmowa kończy całe złożenie) sprawdzają 3 testy w
`OrderTransactionsTest`. Wypadło też 6 przypadków identyfikatorów jako
value objectów (lite używa intów i stringów). Strażnicy wartości dostali za
to więcej wierszy danych (ilość, numer przesyłki, powód). Reszta to te
same przypadki jeden do jednego, z tymi samymi oczekiwaniami.

Profil (`pest --profile`, najwolniejsze testy): w DDD najwolniejszy test to
0,01 s, w lite pierwszy test pliku 0,27 s (migracja SQLite), kolejne
0,01–0,03 s. Surowe wyjścia są w raporcie zadania.

---

## 4. Koszt nowej reguły: „najwyżej 3 nieopłacone zamówienia naraz”

Ta sama reguła dopisana w obu wersjach jako osobny commit na gałęzi
pomiarowej: kupujący, który ma już zamówienia `placed` albo
`awaiting_payment`, nie złoży nowych, jeśli razem byłoby ich więcej niż 3
(całe złożenie liczy się naraz: koszyk na dwóch sprzedawców to dwa
zamówienia). Wyjątek `TooManyUnpaidOrders` z kodem `too_many_unpaid_orders`
i komunikatem w koszyku. Reguły nie ma w specu, więc gałęzie pomiarowe
zostają lokalnie i nie są scalane.

| | Pełne DDD (`zamowienia-pomiar-reguly-ddd` @ `5dba825`) | DDD-lite (`zamowienia-pomiar-reguly-lite` @ `eae6915`) |
|---|---|---|
| `git diff --stat` | **12 plików, +113 / −3** | **7 plików, +86 / −4** |
| wspólne (identyczne) | `DomainErrorMessages` +1, jego test +3/−1, `CartController` +2/−1 (łapanie odmowy), test HTTP w `CheckoutTest` +18 | to samo |
| wyjątek | `Application/Order/TooManyUnpaidOrders.php` +20 | `app/Exceptions/Ordering/TooManyUnpaidOrders.php` +18 |
| reguła | `PlaceOrdersFromCartHandler` +10 | `PlaceOrders` +16/−2 |
| dojście do danych | port `OrderRepository::countUnpaidForBuyer` +5, `SqlOrderRepository` +8, `InMemoryOrderRepository` +8 | — (zapytanie Eloquenta w regule) |
| testy reguły | `PlaceOrdersFromCartTest` +23 (2 testy, w pamięci), `SqlOrderRepositoryTest` +13 (1 test SQL) | `OrderTransactionsTest` +28 (2 testy, na bazie) |
| zepsute istniejące testy | **1**: `OrderCommandsTest` „przechodzi przez wszystkie paczki” składał 101 zamówień jednemu kupującemu (+2/−1) | 0 (żaden test lite nie składał więcej niż 3 zamówień jednemu kupującemu) |
| zmienione pliki testów | 6 | 3 |
| czas testów reguły (2 przypadki, 5 przebiegów) | 8–20 ms (mediana 12) | 176–235 ms (mediana 185) |

Rdzeń zmiany obok siebie. **Pełne DDD** (`PlaceOrdersFromCartHandler`):

```diff
+    public const int MAX_UNPAID_ORDERS = 3;
@@ return $this->transactions->run(function () use ($buyerId, $groups): array {
+            if ($this->orders->countUnpaidForBuyer($buyerId) + count($groups) > self::MAX_UNPAID_ORDERS) {
+                throw new TooManyUnpaidOrders(self::MAX_UNPAID_ORDERS);
+            }
```

plus port i jego dwie implementacje:

```diff
 interface OrderRepository
+    public function countUnpaidForBuyer(BuyerId $buyerId): int;

 final class SqlOrderRepository implements OrderRepository
+    public function countUnpaidForBuyer(BuyerId $buyerId): int
+    {
+        return DB::table('orders')
+            ->where('buyer_user_id', $buyerId->value())
+            ->whereIn('status', [OrderStatus::Placed->value, OrderStatus::AwaitingPayment->value])
+            ->count();
+    }

 final class InMemoryOrderRepository implements OrderRepository   // tests/Unit
+    public function countUnpaidForBuyer(BuyerId $buyerId): int
+    {
+        return count(array_filter($this->stored, static fn (Order $order): bool => $order->buyerId()->equals($buyerId)
+            && in_array($order->status(), [OrderStatus::Placed, OrderStatus::AwaitingPayment], true)));
+    }
```

**DDD-lite** (`PlaceOrders`):

```diff
+    public const int MAX_UNPAID_ORDERS = 3;
@@ return DB::transaction(function () use ($buyerUserId, $lines): Collection {
-            $orders = collect($lines)
-                ->groupBy(fn (array $line): int => $offers[$line['offer_id']]->seller_id)
+            $groups = collect($lines)->groupBy(fn (array $line): int => $offers[$line['offer_id']]->seller_id);
+            $unpaid = Order::query()->where('buyer_user_id', $buyerUserId)
+                ->whereIn('status', [OrderStatus::Placed, OrderStatus::AwaitingPayment])->count();
+
+            if ($unpaid + $groups->count() > self::MAX_UNPAID_ORDERS) {
+                throw new TooManyUnpaidOrders(self::MAX_UNPAID_ORDERS);
+            }
+
+            $orders = $groups
```

**Wniosek: reguła „na wielu zamówieniach naraz” jest w pełnym DDD droższa**
(+27 linii, 5 plików więcej), a różnica to dokładnie dojście do danych: port,
implementacja SQL, implementacja w pamięci i test SQL. Przy portalu nowa
reguła kosztowała w obu stylach tyle samo, bo dotyczyła jednego agregatu
i nie potrzebowała zapytania. Tu reguła potrzebuje zapytania o *inne*
agregaty, a w DDD każde takie zapytanie to nowa metoda portu. Sama decyzja
(„ile jest, ile dojdzie, czy przekracza 3”) to w obu wersjach te same trzy
linie. Pętla „zmień → test” jest w DDD ~15× szybsza (12 ms kontra 185 ms),
a jedna zmiana złamała w DDD stary test, który zakładał nieograniczoną liczbę
zamówień jednego kupującego.

Uwaga do obu wersji: reguła liczona jest w transakcji, a kontroler checkoutu
trzyma blokadę koszyka kupującego (`lockForUpdate` na `cart_items`), więc dwa
równoległe składania tego samego kupującego idą po kolei. Bez tej blokady
licznik miałby okno wyścigu w obu stylach. To argument z kodu; równoległych
checkoutów jednego kupującego nie mierzyłem.

---

## 5. Gdzie da się obejść reguły

### Grep z briefu na obu gałęziach

`grep -rn "Order::query()\|->update(\|->save()" app src` (w `apps/catalog`),
plus `DB::table('order…')` poza modelami odczytu. Trafienia dotyczące zamówień:

| Trafienie | Pełne DDD | DDD-lite | Obejście? |
|---|---|---|---|
| zapis repozytorium | `SqlOrderRepository.php:99` (`->update` z `where version`), `:84`, `:174`, `:191` (insert / upsert pozycji i zwrotów), `OrderEventLog.php:41` | — | nie, to właściwa droga |
| zapis modelu | — | `Order.php` 7× `$this->save()` (w metodach reguł), `OrderRefund.php:59`, `Order.php:444` (`order_events`) | nie, to właściwa droga |
| `Order::query()` | — | 6× w serwisach, każde z `lockForUpdate()` albo tylko do odczytu | nie |
| `RaceFixture.php:111` `DB::table('orders')->delete()` | jest | jest | **tak, celowe:** sprzątanie fixture pomiaru |

**Realnych obejść w kodzie: 0 na każdej gałęzi** (poza sprzątaniem fixture).

### Sondy: co da się zrobić, a czego nie

Grep pokazuje, co już jest w kodzie. Ważniejsze, co da się napisać jutro.
Jednorazowe testy uruchomione na obu gałęziach (nie są w repo; pełne
pliki i wyniki w raporcie zadania). Zamówienie złożone prawdziwym checkoutem:

| Próba | Pełne DDD | DDD-lite |
|---|---|---|
| `DB::table('orders')->where('id', $id)->update(['status' => 'shipped'])` | **przechodzi** | **przechodzi** |
| `Order::find($id)->update(['status' => 'shipped'])` | nie ma modelu Eloquenta `Order` (`class_exists` → `false`) | nic nie zmienia: `status` poza `$fillable` |
| `$order->status = OrderStatus::Shipped; $order->save();` | nie ma modelu | **przechodzi**, bez wpisu na osi czasu |
| wiersz `paid` bez `payment_id` | wczytuje się (repozytorium tego nie sprawdza) | wczytuje się |
| pozycja z ilością 50 / numer przesyłki `x` | repozytorium rzuca `UnexpectedValueException` „Uszkodzony wiersz zamówienia #…” (`hydrate`) | wczytuje się bez błędu |
| nieznany status `bogus` | `UnexpectedValueException` | `ValueError` z casta enuma przy odczycie `status` |
| **dwie instancje tego samego zamówienia bez blokady:** `$a->cancelByBuyer()`, potem `$b->recordPayment(...)` | drugi `save()` rzuca `ConcurrencyConflict` | **przechodzi:** wiersz ma `status = paid` i `cancelled_by = buyer` naraz, oba wpisy na osi czasu |

Dwa wyniki, które warto zapamiętać:

1. **Tu pełne DDD naprawdę zamyka drzwi, których przy portalu nie
   zamykało.** W portalu model `App\Models\Offer` istniał obok agregatu
   (fabryki, seeder, modele odczytu) i przyjmował zapis w obu wersjach.
   Zamówienia w DDD nie mają modelu Eloquenta wcale: zostaje tylko surowy
   `DB::table('orders')`, który na przeglądzie kodu wygląda podejrzanie.
2. **Ostatni wiersz to najdroższa dziura lite.** Bezpieczeństwo lite stoi na
   tym, że *każde* wejście do zamówienia przechodzi przez `lockForUpdate()`
   (serwisy to robią: `OrderPayments.php:64`, `:81`, `:112`,
   `Order::lockedForBuyer`, `lockedForSellerUser`, `OrderRefunds.php:41`).
   Ktoś, kto jutro napisze `Order::find($id)->cancelByBuyer()` w nowym
   kontrolerze, nie dostanie błędu, tylko po cichu przegrany wyścig. W DDD
   ochrona wynika z kształtu kodu (każdy `save()` sprawdza wersję), w lite
   z linii, o której trzeba pamiętać. To ta sama lekcja co przy portalu
   (brudna instancja po konflikcie), tylko z wyższą stawką: tu zlepia się
   „anulowane” z „opłaconym”.

Naprawa lepsza dla obu stylów (nie zrobiona, bo nie ma jej w specu):
`CHECK` w migracji (`status = 'paid'` ⇒ `payment_id IS NOT NULL`,
`cancelled_by IS NULL OR status = 'cancelled'`). Zamknęłaby sondę 4 w lite
i sondę 3 w obu.

---

## 6. Wyścigi: co znika, co zostaje

Saga ma 12 testów w `tests/Unit/Ordering/Application/OrderRacesTest.php`
(11 wyścigów R1–R10 ze specu i 1 o kolejce kroków), wszystkie w pamięci. W lite żadnego z nich nie da się napisać tak samo, bo nie
ma fałszywych portów i ręcznego przeplotu. Część wyścigów po prostu nie
istnieje:

| Wyścig ze specu | Saga | Jedna transakcja |
|---|---|---|
| R1: kupujący anuluje w trakcie rezerwacji | test; krok sam zwalnia rezerwację, jeśli zamówienie przestało być `Placed` | **nie istnieje**: zamówienie widać dopiero po commicie, a wtedy rezerwacja już jest |
| R2: awaria między T1 (oferta) a T2 (zamówienie), ponowiony krok | 2 testy; idempotencja po kluczu `order-{id}` | **nie istnieje**: jedna transakcja, nie ma „między” |
| R3: webhook i sweep w tej samej chwili | 2 testy; konflikt wersji, ponowienie na świeżym stanie | serializowany blokadą wiersza zamówienia; test „opłacone nie wygasa” na SQLite, sama blokada tylko na Postgresie |
| R4: zdublowany webhook | test | test w `OrderRulesTest` (to reguła, nie wyścig) |
| R6: dwóch kupujących, ostatnia sztuka | test; jeden `AwaitingPayment`, drugi `Rejected` | serializowany blokadą oferty; drugi dostaje odmowę w koszyku |
| R7: sprzedawca anuluje przed potwierdzeniem rezerwacji | test; krok potwierdzenia i tak się wykonuje | **nie istnieje**: potwierdzenie jest w transakcji wpłaty |
| R8: oferta wycofana przed rezerwacją | test; `Rejected` z `offer_unavailable` | sprawdzane pod blokadą oferty; odmowa w koszyku |
| R10: zamówienie utknięte w `Placed` | test; sweep wygasza też `Placed` | **nie istnieje**: lite nie nadaje `Placed` |

Bilans: z 8 wyścigów w tabeli 4 (R1, R2, R7, R10) znikają razem ze stanem
pośrednim, a 4 zamieniają się w czekanie na blokadę, którego **SQLite nie
sprawdzi** (SQLite blokuje całą bazę, `FOR UPDATE` nic tam nie znaczy).
Jedyny test kolejności blokad w lite (`OrderTransactionsTest`, „oferty są
czytane do blokady rosnąco po ID”) sprawdza tylko `ORDER BY` w SQL. Czy
blokady faktycznie trzymają przy 50 równoległych kupujących, pokaże dopiero
race-test na Postgresie.

> **Do uzupełnienia po pomiarze:** `orders:race-test` na obu gałęziach
> w kontenerze. Po merge `zamowienia` do `main` (zgoda użytkownika),
> `make up-apps` i migracjach. Kontener montuje główny checkout, a gałęzie
> są wyewidencjonowane w worktree, więc główny checkout przełączamy w trybie
> detached i po pomiarze wracamy na `main`:
> ```bash
> # 1. saga (stan po merge)
> git -C /Users/domin/projects/elastic switch main
> docker compose exec catalog-app php artisan optimize:clear
> docker compose restart catalog-app catalog-worker catalog-scheduler
> docker compose exec catalog-app php artisan orders:race-test --buyers=50 --stock=10 --workers=4
>
> # 2. jedna transakcja
> git -C /Users/domin/projects/elastic switch --detach zamowienia-ddd-lite
> docker compose exec catalog-app php artisan optimize:clear
> docker compose restart catalog-app catalog-worker catalog-scheduler
> docker compose exec catalog-app php artisan orders:race-test --buyers=50 --stock=10 --workers=4
>
> # 3. powrót
> git -C /Users/domin/projects/elastic switch main
> docker compose exec catalog-app php artisan optimize:clear
> docker compose restart catalog-app catalog-worker catalog-scheduler
> ```
> Porównać w tabeli: opłacone (oba: dokładnie 10), overselling (oba: 0),
> odmowy i ich kody (saga: odmowy przy składaniu + `rejected` po rezerwacji;
> lite: same odmowy `out_of_stock`, 0 `rejected`), konflikty wersji oferty
> i zamówienia (saga: > 0 ofert, ponawiane; lite: oczekiwane 0, bo kupujący
> czekają na blokadzie), p50/p95 złożenia (lite trzyma blokadę oferty przez
> całe składanie, saga tylko przez jedną rezerwację: hipoteza, że p95 lite
> rośnie z liczbą kupujących), `failed_jobs` od startu, czas całego
> przebiegu (saga czeka na workery, lite nie). Powtórzyć 3 razy, podać
> rozrzut. Oczekiwania w nawiasach to hipotezy z kodu.

---

## 7. Awaria w połowie

Najciekawsza różnica jest tam, gdzie proces pada w trakcie. Z kodu wynika
(**hipoteza, nie pomiar**):

- **Saga, ubity worker w trakcie `ReserveOrderLinesJob`:** pozycje
  zarezerwowane przed śmiercią zostają w ofertach (każda w osobnej,
  zatwierdzonej transakcji), zamówienie zostaje `Placed` z częścią pozycji
  `pending`, job wraca do kolejki po `retry_after`. Ponowiony krok trafia
  w te same rezerwacje (klucz `order-{id}`), dokańcza resztę i przesuwa
  zamówienie do `AwaitingPayment`. Do tego czasu kupujący widzi „Sprawdzamy
  dostępność”, a sztuki są zajęte. Gdyby worker nie wrócił, sweep wygasi
  zamówienie po 20 minutach i zwolni wszystkie pozycje (R10).
- **Jedna transakcja, ubite żądanie w trakcie składania:** Postgres wycofuje
  transakcję zerwanego połączenia. Nie ma zamówień, rezerwacji ani wierszy
  outboxu, koszyk jest nietknięty. Kupujący dostaje błąd połączenia i klika
  jeszcze raz. Nic nie trzeba sprzątać i nic nie czeka na worker.

> **Do uzupełnienia po pomiarze:** awaria w połowie na obu gałęziach,
> w kontenerze, z przełączaniem głównego checkoutu jak w rozdziale 6.
> Żeby trafić „w połowę” powtarzalnie, przytrzymujemy blokadę w psql
> i zabijamy proces, który na niej czeka:
> ```bash
> # Saga: ubity worker między rezerwacją pierwszej a drugiej pozycji.
> # 1) zamówienie z dwiema ofertami jednego sprzedawcy (UI albo tinker), worker zatrzymany:
> docker compose stop catalog-worker
> # 2) w psql przytrzymaj DRUGĄ ofertę:
> docker compose exec postgres psql -U "$CATALOG_DB_USER" "$CATALOG_DB" -c "BEGIN; SELECT id FROM offers WHERE id = <druga> FOR UPDATE; SELECT pg_sleep(30); COMMIT;" &
> # 3) jeden worker ręcznie, zabity po 5 s (pierwsza pozycja już zarezerwowana, druga czeka):
> docker compose exec catalog-app sh -c 'php artisan queue:work database --once & sleep 5; pkill -9 -f "queue:work"'
> # 4) stan po awarii:
> docker compose exec postgres psql -U "$CATALOG_DB_USER" "$CATALOG_DB" -c "SELECT status FROM orders WHERE id = <id>; SELECT offer_id, status FROM order_lines WHERE order_id = <id>; SELECT offer_id, status, quantity FROM offer_reservations WHERE reservation_key = 'order-<id>'; SELECT attempts, reserved_at FROM jobs;"
> # 5) restart i stan po retry_after:
> docker compose start catalog-worker
>
> # Lite: ubite żądanie w trakcie transakcji składania.
> # 1) w psql przytrzymaj ofertę z koszyka:
> docker compose exec postgres psql -U "$CATALOG_DB_USER" "$CATALOG_DB" -c "BEGIN; SELECT id FROM offers WHERE id = <oferta> FOR UPDATE; SELECT pg_sleep(30); COMMIT;" &
> # 2) checkout w przeglądarce (żądanie wisi na blokadzie), po 5 s:
> docker compose restart catalog-app
> # 3) stan po awarii:
> docker compose exec postgres psql -U "$CATALOG_DB_USER" "$CATALOG_DB" -c "SELECT count(*) FROM orders WHERE buyer_user_id = <kupujący>; SELECT count(*) FROM offer_reservations WHERE reserved_at > now() - interval '5 minutes'; SELECT count(*) FROM cart_items WHERE user_id = <kupujący>;"
> ```
> (`CATALOG_DB_USER` i `CATALOG_DB` z `.env`, jak w `compose.yaml`;
> maszyna jest współdzielona, nie zatrzymywać cudzych kontenerów.)
> Porównać: stan zamówienia, pozycji i rezerwacji tuż po awarii i po
> restarcie, czas do stanu spójnego, czy cokolwiek wymagało ręcznego
> sprzątania, co widział kupujący.

---

## 8. Co chroni który styl, a gdzie przecieka

### Pełne DDD + saga chroni (a lite nie)

1. **Każdy zapis sprawdza wersję, z definicji.** Zapomniany krok nie zlepi
   dwóch stanów; dostaniesz `ConcurrencyConflict` (rozdział 5, sonda 4).
   Lite trzyma to samo na konwencji „zawsze `lockForUpdate()`”.
2. **Wiersz z bazy jest sprawdzany** (`SqlOrderRepository::hydrate`): ilość,
   numer przesyłki, waluta, powody. Lite łapie tylko nieznany status (cast
   enuma).
3. **Nie ma modelu Eloquenta zamówień**, więc nie ma `save()` do nadużycia.
4. **Reguły i wyścigi są czystymi funkcjami.** 80 testów reguł w 27 ms,
   a każdy wyścig ze specu jest testem z ręcznym przeplotem w pamięci
   (moduły 11–12), który można zepsuć i zobaczyć. W lite wyścigi da się
   sprawdzić tylko na Postgresie.
5. **Granica pilnowana testem architektury.**
6. **Saga skaluje się na osobne bazy.** Kroki rozmawiają z ofertą przez port
   i są idempotentne, więc ten sam kod zadziała, gdy stan ofert przeniesie
   się do innej usługi. Jedna transakcja wymaga, żeby zamówienia i oferty
   były w tej samej bazie.

### DDD-lite + transakcja daje (a saga kosztuje)

1. **Jedna trzecia kodu** przy tym samym zachowaniu HTTP (rozdział 3).
2. **Połowa wyścigów nie istnieje** (R1, R2, R7, R10): nie ma stanu
   pośredniego, nie ma kompensacji do zaprojektowania i przetestowania.
3. **Natychmiastowa odpowiedź.** Brak towaru to komunikat w koszyku, nie
   zamówienie „Odrzucone” na liście. Nie ma stanu „Sprawdzamy dostępność”
   ani pollingu.
4. **Mniej ruchomych części.** Składanie, płatność, anulowanie i wygaśnięcie
   działają bez workera; worker jest potrzebny tylko do zwrotów, scheduler
   tylko do sweepu.
5. **Reguły potrzebujące zapytania są tanie** (rozdział 4: 7 plików
   zamiast 12).

### Przecieka w obu

- Surowy `DB::table('orders')` z pominięciem reguł (sonda 1).
- Wiersz `paid` bez `payment_id` (sonda 3a): żadna wersja nie sprawdza tej
  zależności. Właściwe miejsce dla obu: `CHECK` w bazie.
- Kontrakt „koszyk zablokowany na czas składania” (rozdział 4): trzyma go
  kontroler, nie Ordering, w obu wersjach.

---

## 9. Kiedy który styl: werdykt z `ddd-vs-ddd-lite.md` pod pomiarem

Poprzednie porównanie rekomendowało dla zamówień „**pełne DDD dla agregatu
`Order`**” z trzech powodów. Każdy pod pomiarem:

| Powód z `ddd-vs-ddd-lite.md`, rozdz. 7 | Pomiar | Werdykt |
|---|---|---|
| „Reguły obejmują kilka agregatów naraz; lite nie złoży »zarezerwuj stan + utwórz zamówienie« w jedną zmianę bez ręcznego `DB::transaction` i dwóch wersji” | Lite składa to w **jednym** `DB::transaction` z blokadami, w 188 liniach `PlaceOrders`, reużywając handler portalu. Wyszło prościej niż saga, a nie trudniej. Dwie wersje (oferty i zamówienia) w lite po prostu nie przeszkadzają, bo wiersze są zablokowane | **nie potwierdzony** dla tego repo, gdzie oba konteksty dzielą jedną bazę |
| „Niesprawdzane wiersze” | sondy 3b–3c: lite wczytuje pozycję z ilością 50 i numer przesyłki `x`; DDD odrzuca | **potwierdzony** |
| „Siatka stanów to setki przypadków; 0,3 ms vs 11 ms na test robi różnicę” | 0,34 ms vs ~14 ms; 80 przypadków to 27 ms vs ~1 s | **potwierdzony co do proporcji, nie co do skali:** przy dzisiejszych 80 przypadkach to sekunda; zaboli przy setkach |

Do tego dochodzi pomiar, którego poprzednie porównanie nie miało: **sonda 4**
(zapomniany `lockForUpdate()` zlepia „anulowane” z „opłaconym”). To jest
najmocniejszy argument za agregatem z kontrolą wersji, mocniejszy niż ten,
który był w prognozie.

Decyzje dla tego repo:

| Część | Wybór | Dlaczego (z tego porównania) |
|---|---|---|
| **Reguły zamówienia** (`Order`) | pełne DDD zostaje | sprawdzanie wierszy, kontrola wersji przy każdym zapisie i testy w milisekundach to rzeczy zmierzone; reguły będą rosły (zwroty częściowe, reklamacje) |
| **Koordynacja zamówienie ↔ oferta** | **saga zostaje na `zamowienia`, bo jest już zrobiona, przetestowana i uczy procesów; gdybym zaczynał od zera z tą samą bazą, wybrałbym jedną transakcję** | jedna transakcja: 1/3 kodu, 4 wyścigi mniej, natychmiastowa odmowa, brak zależności od workera przy składaniu. Saga zarabia na siebie dopiero, gdy oferty i zamówienia przestaną dzielić bazę albo gdy krok rezerwacji zacznie wołać coś zewnętrznego i wolnego |
| **Zwroty** | job w obu wersjach | bramka to system zewnętrzny; tego nie da się wciągnąć do transakcji w żadnym stylu |

**Uczciwie:** to, czy jedna transakcja wytrzyma 50 równoległych kupujących
na jednej ofercie bez wzrostu p95, nie jest jeszcze zmierzone (rozdział 6).
Jeśli race-test pokaże, że blokada oferty trzymana przez całe składanie
dławi przepustowość, ten werdykt trzeba będzie poprawić. Hybryda „agregat
`Order` z kontrolą wersji + jedna transakcja z blokadą ofert” wygląda na
najlepsze z obu, ale jej nie napisałem, więc to **hipoteza, nie pomiar**.

---

## 10. Jak odtworzyć pomiary

Wszystko z `apps/catalog` na hoście (Herd PHP 8.5, SQLite w pamięci), bez
przełączania gałęzi w głównym checkoutcie.

```bash
# 1. pliki i linie per warstwa, bez przełączania gałęzi
ref=zamowienia-ddd-lite   # albo zamowienia
git ls-tree -r --name-only ${ref} -- apps/catalog/app/Models/Order.php apps/catalog/app/Models/OrderLine.php \
  apps/catalog/app/Models/OrderRefund.php | grep '\.php$' | while read f; do git show "${ref}:${f}"; done | wc -l
#    (w zsh pisz ${ref}:, nie $ref: — `:a`, `:h` itd. to modyfikatory zmiennej)

# 2. czas testów reguł (5 przebiegów; Pest w trybie agenta podaje duration_ms)
#    worktree zamowienia:
php artisan test --compact tests/Unit/Ordering/Domain
#    worktree zamowienia-ddd-lite:
php artisan test --compact tests/Feature/Lite/OrderRulesTest.php
#    profil (bez trybu agenta):
env -u CLAUDECODE -u AI_AGENT -u CLAUDE_CODE_ENTRYPOINT vendor/bin/pest --profile --colors=never tests/Unit/Ordering/Domain

# 3. miejsca obejścia
grep -rn "Order::query()\|->update(\|->save()" app src
grep -rn "DB::table('order" app src | grep -v ReadModel

# 4. koszt nowej reguły
git show --stat 5dba825   # DDD
git show --stat eae6915   # lite

# katalogi testów HTTP i bramki identyczne na obu gałęziach (wynik: pusto)
git diff zamowienia zamowienia-ddd-lite -- apps/catalog/tests/Feature/Ordering/Http apps/catalog/tests/Feature/FakePaymentGateway

# 5. cały catalog
php artisan test --compact
```

---

## 11. Twoja kolej

1. **Zepsuj lite jednym słowem.** Na gałęzi `zamowienia-ddd-lite` w
   `OrderFulfilment::cancelByBuyer` zamień `Order::lockedForBuyer(...)` na
   `Order::forBuyer(...)` (bez blokady). Który test pada? (Podpowiedź: na
   SQLite żaden. Dlaczego? Co musiałbyś mieć, żeby padł?) Potem przywróć.
2. **Dodaj `CHECK`** dla par `status`/`payment_id` i `status`/`cancelled_by`
   w migracji i uruchom sondę 4 z rozdziału 5 na lite. Co się zmienia?
3. **Dopisz regułę „sprzedawca może mieć najwyżej 50 zamówień do wysłania”**
   na obu gałęziach i porównaj `git diff --stat`. Czy wynik potwierdza
   rozdział 4 (reguła z zapytaniem jest w DDD droższa)?
4. **Napisz na kartce**, co musiałoby się zmienić w lite, gdyby stan ofert
   przeniósł się do osobnej usługi z własną bazą. Ile z sagi musiałbyś
   napisać od nowa?

Pytania kontrolne:

- Dlaczego `PaymentWebhookTest` w pierwotnej wersji nie mógł być wspólny dla obu gałęzi i co to mówi o testach, które budują stan obiektem domeny?
- Które wyścigi ze specu znikają w jednej transakcji, a które zamieniają się w czekanie na blokadę? Czego o tych drugich nie powie SQLite?
- Dlaczego nowa reguła kosztowała przy portalu tyle samo w obu stylach, a tu w DDD więcej?
- Co *konkretnie* chroni kontrola wersji w agregacie, czego nie chroni `lockForUpdate()`, i odwrotnie?
- Kiedy saga przestaje być przepłacona?
