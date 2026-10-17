# ADR-0007: Process manager zamówienia, ze stanem procesu w agregacie `Order`

- **Status:** przyjęta
- **Data:** 2026-10-16
- **Kontekst decyzji:** etap „Zamówienia” ([spec, sekcje 4 i 5](../superpowers/specs/2026-10-16-zamowienia-ddd-design.md), [moduły 11 i 12 w `10-DDD.md`](../10-DDD.md))

## Kontekst

Złożenie zamówienia dotyka dwóch agregatów w dwóch kontekstach: `Order`
(Ordering) i jednej albo kilku `Offer` (Seller Portal). Potem proces trwa
w czasie: kupujący płaci w zewnętrznej bramce (sekundy albo minuty), termin
mija po 15 minutach, sprzedawca wysyła albo anuluje po godzinach. Każdy
z tych kroków może paść w połowie albo przyjść dwa razy.

Hot spot **H9** z Event Stormingu: czy rezerwacja i zapis zamówienia mają
być w jednej transakcji, skoro to ta sama baza?

Fakty:
- Płatność i termin są asynchroniczne **niezależnie** od tego, jak złożymy
  zamówienie. Jakiś koordynator procesu i tak musi istnieć.
- Zamówienie z kilku ofert w jednej transakcji blokuje kilka gorących
  wierszy naraz, a dwa koszyki z tymi samymi ofertami w odwrotnej kolejności
  dają deadlock (trzeba sortować blokady).
- Każdy krok procesu jest stanem, który widzi kupujący: „Sprawdzamy
  dostępność” (`Placed`), „Czeka na płatność” (`AwaitingPayment`).
- Zasada z portalu: polityka kolejkuje pracę **w tej samej transakcji** co
  zdarzenie, w kolejce `database`, a job sprawdza warunek w chwili wykonania
  (moduł 8 `10-DDD.md`).

## Decyzja

**Process manager (`OrderProcessManager`) bez własnego stanu.** Słucha
zdarzeń `Order` przez dispatcher w procesie i na każde zleca jeden krok:

| Zdarzenie `Order` | Krok (job) | Co robi |
|---|---|---|
| `OrderPlaced` | `ReserveOrderLines` | rezerwuje pozycje po kolei; pierwsza odmowa → `reject` |
| `OrderRejected`, `OrderExpired`, `OrderCancelled(by: Buyer)` | `ReleaseOrderReservations` | zwalnia **każdą** pozycję (no-op, gdy nie ma czego) |
| `OrderPaid` | `ConfirmOrderReservations` | zamienia rezerwacje w sprzedaż |
| `RefundRequested` | `RefundPayment` | zleca zwrot w bramce, potem `markRefundCompleted` |

`OrderCancelled(by: Seller)` **nie** zwalnia rezerwacji (H8: sprzedawca
anuluje zwykle dlatego, że towaru nie ma).

**Stan procesu żyje w `Order`.** Nie ma tabeli sagi. Status zamówienia
i status pozycji (`Pending`/`Reserved`) mówią, gdzie proces jest, a kroki
wracają do zamówienia z wynikiem (`markLineReserved`, `reject`). Docblock
`apps/catalog/src/Ordering/Domain/Order.php:35`.

**Mechanika:**
- Słuchacze tylko kolejkują
  (`apps/catalog/src/Ordering/Application/Process/OrderProcessManager.php:25`).
  Job jest wstawiany do tabeli `jobs` w transakcji zapisu zamówienia, na
  połączeniu przypiętym w configu (`queue.ordering_process_connection`,
  domyślnie `database`, `apps/catalog/config/queue.php:50`;
  `after_commit => false`, `:71`). To działa jak outbox komend: nie ma zapisu
  bez kroku i kroku bez zapisu (`apps/catalog/src/Ordering/Infrastructure/LaravelProcessJobQueue.php:14`).
- Każdy krok jest idempotentny: rezerwacja po kluczu `order-{id}`, płatność
  po `paymentId` z bramki, zwrot po `refundId` (klucz idempotencji w bramce).
- Krok rezerwacji to dwie transakcje dwóch agregatów: T1 w ofercie, T2
  w zamówieniu. T2 ponawia `ConcurrencyConflict` do 3 razy na świeżym stanie,
  joby mają `tries = 5` i `backoff = [5, 30, 120]`.
- Zegar to sweep `orders:expire-overdue` co minutę (`withoutOverlapping`,
  `apps/catalog/routes/console.php:13`) w nowym kontenerze
  `catalog-scheduler` (`schedule:work`), nie opóźniony job: zgubiony job nie
  zostawi wiszącego zamówienia (R10).
- Zdarzenia `Order` **nie** idą do outboxu (brak konsumenta, zasada
  z ADR-0003). Trafiają do `order_events` przez jawną mapę
  `OrderEventLog` (oś czasu kupującego, `apps/catalog/src/Ordering/Infrastructure/Persistence/OrderEventLog.php:53`).

## Rozważane opcje

| Opcja | Za | Przeciw |
|---|---|---|
| **A. Process manager, stan w `Order` (wybrana)** | jedna prawda o tym, gdzie jest proces, i ta sama prawda w UI; każdy agregat we własnej transakcji; awaria kroku = ponowienie joba | eventual consistency widoczna dla kupującego (polling 1 s w `Placed`); wymaga workera i schedulera; wyścigi R1–R10 trzeba rozpisać i przetestować |
| B. Process manager z osobną tabelą sagi | stan procesu oddzielony od biznesu; łatwo dodać kroki techniczne | druga kopia tej samej prawdy („saga: rezerwuje” i „zamówienie: Placed”) do synchronizacji; dwie kopie się rozjeżdżają |
| C. Choreografia (agregaty reagują na swoje zdarzenia nawzajem, bez koordynatora) | brak centralnej klasy | reguła „odmowa → zwolnij wszystko” rozsmarowana po słuchaczach; nie widać całego procesu w jednym miejscu; trudno testować przeploty |
| D. Jedna transakcja z `lockForUpdate` na ofertach | brak stanu `Placed`, kupujący od razu widzi wynik; mniej wyścigów | płatność i termin i tak asynchroniczne; blokady kilku gorących wierszy naraz, deadlocki bez sortowania; żyje na gałęzi `zamowienia-ddd-lite` jako porównanie (Task 17) |

## Konsekwencje

**Pozytywne**
- Kupujący widzi dokładnie stan procesu: „Sprawdzamy dostępność” to
  `OrderStatus::Placed`, bez tłumaczenia stanu sagi na status.
- Awaria w dowolnym miejscu naprawia się sama: ponowienie joba (idempotentne
  kroki), sweep wygasza zamówienia, które utknęły (R10), a zwolnienie jest
  bezpieczne do powtórzenia.
- Wszystkie wyścigi są testami aplikacji w pamięci, z ręcznie ustawionym
  przeplotem (`apps/catalog/tests/Unit/Ordering/Application/OrderRacesTest.php`),
  w milisekundach.

**Negatywne**
- Statusy `Order` niosą też znaczenie procesu (`Placed` = „rezerwacja w toku”).
  Nowy krok techniczny, którego kupujący nie widzi, nie ma gdzie żyć bez
  nowego statusu albo pola.
- `Order` nie śledzi, czy potwierdzenie rezerwacji po płatności przeszło.
  Sygnałem trwałej awarii jest tylko `failed_jobs`
  ([RUNBOOK #046](../RUNBOOK.md#046)); rekonsyliacja to dalszy krok.
- Bez `catalog-worker` proces stoi w `Placed`, bez `catalog-scheduler`
  zamówienia nie wygasają i trzymają towar ([RUNBOOK #044](../RUNBOOK.md#044),
  [#045](../RUNBOOK.md#045)). `/health` pokazuje czas ostatniego sweepa
  (`ordering_sweep_last_run_at`).
- Ten sam job może wykonać się dwa razy naraz (worker przekroczył
  `retry_after`). Spec zakładał, że w T2 „inny status niż `Placed` →
  zwolnij pozycję”; test zdublowanego kroku pokazał, że to overselling,
  i reguła została zawężona do `Rejected`/`Expired`/`Cancelled` przez
  kupującego (rozstrzygnięcie R18, moduł 12 `10-DDD.md`).
- Atomowość „zapis + krok” zależy od połączenia kolejki w tej samej bazie.
  Zmiana `ORDERING_PROCESS_QUEUE_CONNECTION` na Redis po cichu ją rozpina.
