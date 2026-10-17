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
Wynik w skrócie: lite to **33% kodu** (1407 zamiast 4231 linii), nowa
reguła kosztuje w nim mniej (7 plików zamiast 12), a połowa wyścigów sagi
w ogóle nie istnieje. Na Postgresie (50 kupujących na 10 sztuk) **oba style
dały 0 oversellingu** w 3/3 przebiegach: saga odrzuca nadmiar asynchronicznie
z konfliktami wersji, jedna transakcja odmawia od razu, bez konfliktów.
Za to lite nie sprawdza wierszy z bazy, a zapomniany
`lockForUpdate()` po cichu skleja dwa stany zamówienia (pełne DDD rzuca
wtedy konflikt wersji). Testy reguł chodzą w nim ~37× wolniej. Prognoza
z poprzedniego porównania potwierdza się dla agregatu, ale **nie** dla sagi.*

> **Stan:** gałąź `zamowienia-ddd-lite` zrobiona lokalnie (`65df43f`, od
> `zamowienia` @ `01fe299`; po końcowym przeglądzie zsynchronizowana
> poprawkami w `0b44155`), **nie wypchnięta**: `git push -u origin
> zamowienia-ddd-lite` czeka na Twoją zgodę. Gałąź nie będzie scalana, to
> materiał do porównania. Gałęzie pomiarowe nowej reguły
> (`zamowienia-pomiar-reguly-ddd` @ `5dba825`,
> `zamowienia-pomiar-reguly-lite` @ `eae6915`) też są tylko lokalne.
> Liczby kodu i testów zmierzone ponownie 2026-10-17 (DDD @ `c7a956b` po
> merge do `main`, lite @ `0b44155`), Herd PHP 8.5, SQLite w pamięci
> (`phpunit.xml`); surowe wyjścia:
> `.superpowers/sdd/2026-10-16-zamowienia-ddd/measurements/remeasure.txt` (lokalnie, katalog poza gitem).
> Rozdział 4 (nowa reguła) jest z 2026-10-16, z gałęzi pomiarowych.
>
> **Na żywym stacku (2026-10-17, Postgres, workery):** `orders:race-test`
> na obu gałęziach po 3 razy (rozdział 6) i ubity worker w sadze
> (rozdział 7). **Nie zmierzyłem** ubitego żądania w połowie transakcji
> lite; to, co o nim piszę, wynika z kodu i z testu na SQLite.

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

Wszystkie 70 testów HTTP zamówień przechodzi na obu gałęziach.

> **Po końcowym przeglądzie `zamowienia`:** poprawki z przeglądu
> (komunikat `invalid_order_lines`, toast sprzedawcy, utwardzenie webhooka)
> zmieniły `CheckoutTest`, `SellerOrdersTest` i dodały
> `PaymentWebhookHardeningTest` (67 → 70 testów). Te same poprawki
> kontrolerów i te same pliki testów trafiły na lite w `0b44155`, więc
> `git diff` powyżej znów jest pusty (sprawdzone 2026-10-17, gałąź
> `zamowienia-pomiary` kontra `zamowienia-ddd-lite`).

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

- **`app/Models/Order.php`** (540 linii) — metody o nazwach z języka domeny:
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
- **`app/Services/Ordering/PlaceOrders.php`** (189 linii) — jedna transakcja:
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
  491 linii.
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
(`find … -name '*.php'` + `wc -l` w worktree każdej gałęzi, skrypt w
[rozdziale 10](#10-jak-odtworzyć-pomiary); stan 2026-10-17, po końcowym
przeglądzie i synchronizacji lite):

| Warstwa | Pełne DDD (`zamowienia` → `main` @ `c7a956b`) | DDD-lite (`zamowienia-ddd-lite` @ `0b44155`) |
|---|---|---|
| `src/Ordering/Domain` | 42 / 1674 (w tym `Order.php` 552, 12 klas zdarzeń 315) | — |
| `src/Ordering/Application` | 44 / 1578 (komendy, handlery, wyjątki 861; 12 portów 255; proces 422; `RetryOnConflict` 40) | — |
| `Infrastructure/Persistence` (repozytorium, dziennik zdarzeń) | 2 / 423 | 0 / 0 |
| adaptery portów + provider | 9 / 347 + 1 / 74 | 0 / 0 |
| joby | 4 / 135 | 1 / 35 |
| słownik: enumy + wyjątki | (w Domain i Application) | 12 / 226 |
| modele `Order`, `OrderLine`, `OrderRefund` | — | 3 / 655 (`Order` 540) |
| serwisy `App\Services\Ordering` | — | 5 / 491 |
| **razem** | **102 / 4231** | **21 / 1407** |
| bez zmian w obu: `ReadModel` | 6 / 381 | 6 / 381 |
| kontrolery, `CheckoutRequest`, 3 komendy | 8 / 1059 | 8 / 1037 |

**Lite to 33% kodu pełnego DDD** (1407 / 4231). Przy portalu było 50%.
Różnica jest większa, bo tu odpada nie tylko mapowanie (423 linie) i porty
(255), ale cały process manager z krokami (422 linie procesu, 135 linii
jobów, 4 adaptery): to, co saga rozkłada na kroki i kompensacje, jedna
transakcja robi w miejscu. Same reguły kosztują podobnie: `Order.php` w DDD
552 linie, w lite 540 (bo niesie też zapis, oś czasu i strażników wartości,
które w DDD są osobnymi value objectami). Końcowy przegląd dołożył DDD 76
linii (`holdsReservations`, wynik sweepu z listą błędów, strażnik numeru
przesyłki), a lite 18; proporcja się nie zmieniła.

### Testy

| | Pełne DDD | DDD-lite |
|---|---|---|
| testy reguł | `tests/Unit/Ordering/Domain`: 6 plików, 814 linii, **94 testy** | `tests/Feature/Lite/OrderRulesTest.php`: 1 plik, 544 linie, **73 testy** |
| czas testów reguł (Pest `duration_ms`, mediana z 5) | **32 ms** (32–35) | **1179 ms** (1166–2221) — ~37× wolniej |
| czas ściany z bootem (mediana z 5) | 0,31 s (0,29–0,57) | 1,44 s (1,42–1,69) — ~4,6× wolniej |
| na jeden test | ~0,34 ms | ~16 ms (RefreshDatabase + SQLite + fabryki) |
| testy aplikacji i sagi na fałszywych portach | 17 plików, 1682 linie, 45 testów (w tym 14 w `OrderRacesTest`: 13 o wyścigach, 1 o kolejce kroków); cały `tests/Unit/Ordering` (139 testów) w 61 ms | brak (nie ma czego podmieniać) |
| testy transakcji na bazie | — | `OrderTransactionsTest.php`: 194 linie, 11 testów, 334 ms |
| `tests/Feature/Ordering/Infrastructure` | 9 plików, 1008 linii, 78 testów | 4 pliki, 271 linii, 15 testów |
| testy HTTP (`Feature/Ordering/Http`) | 7 plików, 1090 linii, 70 testów | **te same pliki, bajt w bajt** |
| test architektury Orderingu | 1 plik, 4 testy | 0 — świadoma strata, nie ma czystej domeny do pilnowania |
| cały catalog (Pest `duration_ms`, mediana z 3) | 764 testy (749 + 15 pominiętych), 9,4 s (9,35–9,79) | 642 testy (627 + 15 pominiętych), 9,2 s (9,18–9,79) |
| PHPStan (poziom z `phpstan.neon`, ścieżka `app/`; **z 2026-10-16, sprzed końcowego przeglądu, nie mierzone ponownie**) | 133 błędy | 148 (+15; 17 zgłoszeń w nowych plikach lite, głównie typy generyczne kolekcji). **Nieporównywalne:** Ordering z DDD leży w `src/`, poza ścieżkami PHPStana |

Cały suite trwa w obu stylach tyle samo: ~9 s to w większości testy
wspólne (portal, katalog, HTTP), a różnica w testach reguł (~1 s) ginie
w rozrzucie między przebiegami. Wcześniejsze „8,5 s / 8,6 s” w tym
dokumencie nie miały surowego wyjścia, więc zastąpiłem je pomiarem.

Skąd 94 kontra 73 przypadki: 12 przypadków rezerwacji (`markLineReserved`,
`reject`) w lite nie ma odpowiednika w modelu, bo nie ma statusu
pośredniego; te same reguły (wszystko zarezerwowane → czeka na płatność,
pierwsza odmowa kończy całe złożenie) sprawdzają 3 testy w
`OrderTransactionsTest`. Wypadło też 6 przypadków identyfikatorów jako
value objectów (lite używa intów i stringów). Końcowy przegląd dołożył
w DDD 14 przypadków: 8 dla `holdsReservations` (lite nie ma kroku
rezerwacji, który by tę regułę potrzebował) i 6 dla znaków sterujących
w numerze przesyłki. Ten strażnik (`\p{C}`) trafił do modelu lite przy
synchronizacji, ale **bez** nowych przypadków w `OrderRulesTest`, więc
w lite nie jest pokryty testem reguł. Strażnicy wartości lite dostali za
to więcej wierszy danych (ilość, numer przesyłki, powód). Reszta to te
same przypadki jeden do jednego, z tymi samymi oczekiwaniami.

Profil (`pest --profile`, najwolniejsze testy): w DDD najwolniejszy test to
0,02 s, cały katalog (6 plików) 0,13 s; w lite pierwszy test pliku 0,17 s (migracja
SQLite), kolejne 0,02–0,03 s, cały plik 1,17 s. Surowe wyjścia są w
`remeasure.txt`.

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
   (serwisy to robią: `OrderPayments.php:65`, `:82`, `:119`,
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

Saga ma 14 testów w `tests/Unit/Ordering/Application/OrderRacesTest.php`
(11 wyścigów R1–R10 ze specu, 2 dopisane po końcowym przeglądzie: R1+R2
naraz i „ponowiony krok po opłaceniu niczego nie zwalnia”, oraz 1 o kolejce
kroków), wszystkie w pamięci. W lite żadnego z nich nie da się napisać tak samo, bo nie
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
blokady faktycznie trzymają przy 50 równoległych kupujących, pokazał
dopiero race-test na Postgresie.

### Race-test na Postgresie (2026-10-17)

`orders:race-test --buyers=50 --stock=10 --workers=4`, po 3 przebiegi na
gałąź, lokalny stack (Postgres, `catalog-worker`, `catalog-scheduler`).
Surowe wyjścia: `.superpowers/sdd/2026-10-16-zamowienia-ddd/measurements/race-ddd*.out`
i `race-lite*.out` (lokalnie, katalog poza gitem).

| Przebieg | Złożone | Opłacone | Odrzucone (saga) | Odmowy przy składaniu | Overselling | Konflikty wersji oferta / zamówienie | p50 / p95 złożenia (ms) |
|---|---|---|---|---|---|---|---|
| saga 1 | 50 | 10 | 40 | 0 | 0 | 61 / 0 | 613,6 / 776,8 |
| saga 2 | 50 | 10 | 40 | 0 | 0 | 24 / 0 | 2451,0 / 2747,2 |
| saga 3 | 50 | 10 | 40 | 0 | 0 | 12 / 0 | 6968,8 / 7435,5 |
| transakcja 1 | 10 | 10 | 0 | `offer_no_longer_available`: 40 | 0 | 0 / 0 | 2992,7 / 3652,1 |
| transakcja 2 | 10 | 10 | 0 | `offer_no_longer_available`: 40 | 0 | 0 / 0 | 4450,4 / 13411,2 |
| transakcja 3 | 10 | 10 | 0 | `offer_no_longer_available`: 40 | 0 | 0 / 0 | 7950,7 / 11523,2 |

We wszystkich sześciu: końcowy `offer.stock` 0, aktywne rezerwacje 0,
`failed_jobs` od startu 0, kod wyjścia 0.

Co z tego wynika:

1. **Poprawność: remis.** Oba style 6/6 bez oversellingu, dokładnie 10
   opłaconych. Blokady w lite trzymają przy 50 równoległych kupujących, a
   kontrola wersji w sadze też.
2. **Ten sam wyścig, dwa różne kształty.** Saga przyjmuje wszystkie 50
   zamówień i nadmiar odrzuca asynchronicznie krok rezerwacji (40
   `Rejected`), płacąc konfliktami wersji oferty (61 / 24 / 12), które
   ponawia. Jedna transakcja odmawia od razu w koszyku (40 odmów) i nie ma
   ani jednego konfliktu: kupujący czekają w kolejce na blokadzie wiersza
   oferty, więc nie ma z kim przegrać wersji. Liczba konfliktów sagi to dolna
   granica: joby przejęte przez działający obok `catalog-worker` nie
   zapisują śladu.
3. **Kod odmowy w lite to `offer_no_longer_available`, nie `out_of_stock`**
   (przed pomiarem spodziewałem się `out_of_stock`). Po rezerwacji dziesiątej sztuki
   dostępne = 0, a scope `listed()` wymaga `stock > zarezerwowane`
   (`app/Models/Offer.php:82` na lite). `PlaceOrders` sprawdza listę
   `listed` pod blokadą (`app/Services/Ordering/PlaceOrders.php:65`, `:67`
   na lite) **przed** rezerwacją, więc odmawia „oferta niedostępna”, zanim
   dojdzie do `ReserveStockHandler`, gdzie padłoby `OutOfStock`. Przy
   ilości 1 na kupującego `out_of_stock` jest osiągalne tylko wtedy, gdy
   dostępnych jest mniej niż zamawiana ilość, ale więcej niż 0. Dla
   kupującego różnica jest w treści komunikatu: „oferta niedostępna” zamiast
   „zmniejsz ilość”, co tutaj jest prawdą.
4. **Latencja: brak wniosku.** p50 i p95 rosły z przebiegu na przebieg
   w obu stylach przy tym samym kodzie, bo maszyna jest współdzielona
   (inne projekty na tych samych zasobach), a w trakcie węzły ES
   restartowały się pod presją pamięci VM Dockera 15,6 GB (es03: 20
   restartów). Hipotezy „p95 lite rośnie z liczbą kupujących, bo blokada
   oferty trwa przez całe składanie” ten pomiar **ani nie potwierdza, ani
   nie obala**. Do tego potrzebny jest przebieg na maszynie bez innych
   projektów.

Jak to uruchomić (tak to faktycznie trzeba było zrobić). `docker compose
exec catalog-app php artisan orders:race-test …` ginie po ~2 s bez komunikatu
z kodem 137: `catalog-app` ma 512m, a 50 procesów PHP się w nim nie mieści
([RUNBOOK #049](../RUNBOOK.md#049)). Zamiast tego jednorazowy kontener z
tego samego obrazu, z limitem 3g, w sieci stacku i ze zmiennymi
`catalog-app`. `-v` wskazuje checkout mierzonej gałęzi, więc głównego
checkoutu nie trzeba przełączać:
```bash
# z katalogu głównego checkoutu; stack: make up-apps, migracje zrobione
env_file=$(mktemp)
docker inspect marketplace-catalog-app-1 --format '{{range .Config.Env}}{{println .}}{{end}}' > "$env_file"

# 1. saga (main)
docker run --rm --memory 3g --network marketplace_marketplace --env-file "$env_file" \
  -v "$PWD/apps/catalog:/app" -w /app marketplace-catalog-app \
  php artisan orders:race-test --buyers=50 --stock=10 --workers=4

# 2. jedna transakcja (worktree gałęzi zamowienia-ddd-lite)
docker run --rm --memory 3g --network marketplace_marketplace --env-file "$env_file" \
  -v "$PWD/.claude/worktrees/zamowienia-lite/apps/catalog:/app" -w /app marketplace-catalog-app \
  php artisan orders:race-test --buyers=50 --stock=10 --workers=4

rm "$env_file"
```
Ścieżka worktree lite jest z tej maszyny; podaj swoją. Każdy przebieg
powtórz 3 razy. Na współdzielonej maszynie po przebiegach
sprawdź ES i search-consumera: w tym pomiarze konsument po restartach
węzłów przestał indeksować ([RUNBOOK #050](../RUNBOOK.md#050)).

---

## 7. Awaria w połowie

Najciekawsza różnica jest tam, gdzie proces pada w trakcie. Z kodu wynika
(to jest hipoteza; co z niej zmierzyłem, jest niżej):

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

### Co zmierzyłem na stacku (2026-10-17)

**Saga, worker nieobecny w chwili złożenia.** `docker compose stop
catalog-worker`, kupujący składa zamówienie. Po 10 s zamówienie dalej
`placed`, w tabeli `jobs` jeden job (`ReserveOrderLinesJob`), kupujący
widzi „Sprawdzamy dostępność”. `docker compose start catalog-worker` →
`awaiting_payment` po **28,1 s** od startu, wliczając start kontenera.
Nic nie wymagało ręcznego sprzątania: saga domknęła się sama, bo stan
procesu jest w zamówieniu, a krok czekał w kolejce w bazie. Dla porównania
z działającym workerem to samo przejście trwało ~1 s.

Uczciwie: to jest awaria **przed** krokiem, nie **w połowie** kroku.
Wariantu „worker ubity między rezerwacją pierwszej a drugiej pozycji”
(niżej, z blokadą trzymaną w psql) nie uruchamiałem. Ten przeplot pokrywają
testy w pamięci R2 i R1+R2 w `OrderRacesTest`.

**Jedna transakcja, ubite żądanie w trakcie składania: nie mierzone.**
Trzeba by zabić proces PHP w trakcie otwartej transakcji. Atomowość
wycofania na gałęzi lite pokrywa `OrderTransactionsTest` (na SQLite),
a nie żywy Postgres. Wniosek „nic do sprzątania” zostaje hipotezą z kodu.

Komendy dla obu wariantów, gdybyś chciał je domknąć. Wszystkie z katalogu
głównego checkoutu; zmienne bazy wczytaj z `.env` najpierw, bo zwykła
powłoka ich nie zna (`$CATALOG_DB_USER` byłby pusty i `psql` połączyłby
się jako zły użytkownik):
```bash
set -a; . ./.env; set +a

# Saga: ubity worker między rezerwacją pierwszej a drugiej pozycji.
# 1) zamówienie z dwiema ofertami jednego sprzedawcy (UI albo tinker), worker zatrzymany:
docker compose stop catalog-worker
# 2) w psql przytrzymaj DRUGĄ ofertę:
docker compose exec postgres psql -U "$CATALOG_DB_USER" "$CATALOG_DB" -c "BEGIN; SELECT id FROM offers WHERE id = <druga> FOR UPDATE; SELECT pg_sleep(30); COMMIT;" &
# 3) jeden worker ręcznie, zabity po 5 s (pierwsza pozycja już zarezerwowana, druga czeka):
docker compose exec catalog-app sh -c 'php artisan queue:work database --once & sleep 5; pkill -9 -f "queue:work"'
# 4) stan po awarii:
docker compose exec postgres psql -U "$CATALOG_DB_USER" "$CATALOG_DB" -c "SELECT status FROM orders WHERE id = <id>; SELECT offer_id, status FROM order_lines WHERE order_id = <id>; SELECT offer_id, status, quantity FROM offer_reservations WHERE reservation_key = 'order-<id>'; SELECT attempts, reserved_at FROM jobs;"
# 5) restart i stan po retry_after:
docker compose start catalog-worker

# Lite: ubite żądanie w trakcie transakcji składania
# (wymaga kodu lite w catalog-app, czyli głównego checkoutu na tej gałęzi).
# 1) w psql przytrzymaj ofertę z koszyka:
docker compose exec postgres psql -U "$CATALOG_DB_USER" "$CATALOG_DB" -c "BEGIN; SELECT id FROM offers WHERE id = <oferta> FOR UPDATE; SELECT pg_sleep(30); COMMIT;" &
# 2) checkout w przeglądarce (żądanie wisi na blokadzie), po 5 s:
docker compose restart catalog-app
# 3) stan po awarii:
docker compose exec postgres psql -U "$CATALOG_DB_USER" "$CATALOG_DB" -c "SELECT count(*) FROM orders WHERE buyer_user_id = <kupujący>; SELECT count(*) FROM offer_reservations WHERE reserved_at > now() - interval '5 minutes'; SELECT count(*) FROM cart_items WHERE user_id = <kupujący>;"
```
Maszyna jest współdzielona: nie zatrzymuj cudzych kontenerów.

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
4. **Reguły i wyścigi są czystymi funkcjami.** 94 testy reguł w 32 ms,
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
| „Reguły obejmują kilka agregatów naraz; lite nie złoży »zarezerwuj stan + utwórz zamówienie« w jedną zmianę bez ręcznego `DB::transaction` i dwóch wersji” | Lite składa to w **jednym** `DB::transaction` z blokadami, w 189 liniach `PlaceOrders`, reużywając handler portalu. Wyszło prościej niż saga, a nie trudniej. Dwie wersje (oferty i zamówienia) w lite po prostu nie przeszkadzają, bo wiersze są zablokowane | **nie potwierdzony** dla tego repo, gdzie oba konteksty dzielą jedną bazę |
| „Niesprawdzane wiersze” | sondy 3b–3c: lite wczytuje pozycję z ilością 50 i numer przesyłki `x`; DDD odrzuca | **potwierdzony** |
| „Siatka stanów to setki przypadków; 0,3 ms vs 11 ms na test robi różnicę” | 0,34 ms vs ~16 ms; 94 przypadki w 32 ms vs 73 w ~1,2 s | **potwierdzony co do proporcji, nie co do skali:** przy dzisiejszych kilkudziesięciu przypadkach to sekunda; zaboli przy setkach |

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

**Po pomiarze na Postgresie (rozdział 6) werdykt się nie zmienia.** Jedna
transakcja wytrzymała 50 równoległych kupujących na jednej ofercie bez
oversellingu i bez konfliktów, tak samo poprawnie jak saga. To, czy przy tym
nie rośnie p95, **dalej nie jest zmierzone**: na współdzielonej maszynie
czasy rosły z przebiegu na przebieg w obu stylach i nie dają się porównać.
Jeśli czysty przebieg pokaże, że blokada oferty trzymana przez całe
składanie dławi przepustowość, ten werdykt trzeba będzie poprawić. Ubity
worker w sadze zmierzyłem (sama się domyka, rozdział 7); ubitego żądania
w lite nie. Hybryda „agregat
`Order` z kontrolą wersji + jedna transakcja z blokadą ofert” wygląda na
najlepsze z obu, ale jej nie napisałem, więc to **hipoteza, nie pomiar**.

---

## 10. Jak odtworzyć pomiary

Rozdziały 3–5: z `apps/catalog` na hoście (Herd PHP 8.5, SQLite
w pamięci), w worktree każdej gałęzi, bez przełączania gałęzi w głównym
checkoutcie. Rozdziały 6–7: komendy są tam, przy wynikach. Surowe wyjścia
pomiaru z 2026-10-17: `.superpowers/sdd/2026-10-16-zamowienia-ddd/measurements/remeasure.txt` (lokalnie, katalog poza gitem).

```bash
# 1. pliki i linie per warstwa (w worktree danej gałęzi, w apps/catalog)
find src/Ordering/Domain -name '*.php' | xargs wc -l | tail -1                  # DDD
find src/Ordering/Application -name '*.php' | xargs wc -l | tail -1             # DDD
find src/Ordering/Infrastructure -maxdepth 1 -name '*.php' | xargs wc -l | tail -1   # DDD: adaptery portów
find app/Services/Ordering -name '*.php' | xargs wc -l | tail -1                # lite
wc -l app/Models/Order.php app/Models/OrderLine.php app/Models/OrderRefund.php  # lite
#    bez worktree, z samego gita (w zsh pisz ${ref}:, nie $ref: — `:a`, `:h` to modyfikatory zmiennej):
ref=zamowienia-ddd-lite
git ls-tree -r --name-only ${ref} -- apps/catalog/app/Services/Ordering | while read f; do git show "${ref}:${f}"; done | wc -l

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

# 5. cały catalog (3 przebiegi, brać duration_ms Pesta, nie czas ściany)
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
