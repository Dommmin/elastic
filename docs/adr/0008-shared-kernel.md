# ADR-0008: Shared Kernel dla portalu sprzedawcy i zamówień

- **Status:** przyjęta
- **Data:** 2026-10-16
- **Kontekst decyzji:** etap „Zamówienia” ([spec, sekcja 2](../superpowers/specs/2026-10-16-zamowienia-ddd-design.md), [moduł 3 w `10-DDD.md`](../10-DDD.md))

## Kontekst

Ordering potrzebuje pieniędzy (cena pozycji, suma zamówienia, kwota wpłaty
i zwrotu) i tych samych klocków taktycznych co portal: korzeń agregatu
zapisujący zdarzenia, bazowe zdarzenie domenowe, wyjątek domeny ze stałym
`CODE`, konflikt wersji. Wszystko to istniało już w
`SellerPortal\Domain\Shared`.

Test architektury zabrania `Ordering\Domain` i `Ordering\Application`
używania `SellerPortal\*`, a `SellerPortal\*` używania `Ordering\*`
(`apps/catalog/tests/Arch/OrderingLayersTest.php`). Import z portalu odpada.

Fakty:
- Cena z oferty staje się ceną pozycji zamówienia, a rezerwacja porównuje
  cenę zamówienia z obecną ceną oferty (`Offer::reserve`, H5). Oba konteksty
  muszą mieć **identyczne** pojęcie kwoty i równości kwot.
- `Money` ma reguły (grosze jako `int`, jedna waluta w arytmetyce, próg
  procentowy na liczbach całkowitych), które już raz poprawiał przegląd
  bezpieczeństwa (przewodnik portalu, Krok 1).
- Klasy bazowe nie mają reguł biznesowych i od etapu portalu się nie zmieniały.

## Decyzja

Nowy katalog `apps/catalog/src/SharedKernel/` (PSR-4 `SharedKernel\`) z ośmioma
klasami przeniesionymi z `SellerPortal\Domain\Shared` jednym mechanicznym
commitem (`8a2853c`, zmiana importów, bez zmiany zachowania):

`Money`, `Currency`, `CurrencyMismatch`, `InvalidValue`, `AggregateRoot`,
`DomainEvent`, `DomainException`, `ConcurrencyConflict`.

W `SellerPortal\Domain\Shared` zostaje to, co dotyczy tylko portalu:
`UserId`, `ModeratorId`, `Reason`. Ordering ma **własne** `OfferId`,
`SellerId`, `BuyerId`, `Reason`. Dziś `Reason` w obu kontekstach ma podobne
reguły (niepusty po przycięciu, ≤ 500 znaków), ale to przypadek, nie
kontrakt: powód anulowania zamówienia może zacząć się różnić od powodu
blokady sprzedawcy bez pytania drugiego kontekstu o zgodę.

Shared Kernel nie zna nikogo: ani frameworka, ani żadnego z kontekstów, ani
zegara (`apps/catalog/tests/Arch/SharedKernelTest.php`).

Czego świadomie **nie** dzielimy, mimo identycznego kodu: porty aplikacji
(`Clock`, `TransactionManager`, `DomainEventDispatcher`) i `RetryOnConflict`
istnieją w obu kontekstach jako kopie
(`apps/catalog/src/Ordering/Application/RetryOnConflict.php`). Shared Kernel
jest dla **modelu**, nie dla warstwy aplikacji; kopia kilkunastu linii bez
reguł jest tańsza niż wspólny moduł, który każdy kontekst ciągnie w swoją
stronę.

## Rozważane opcje

| Opcja | Za | Przeciw |
|---|---|---|
| **A. Shared Kernel z ośmioma klasami (wybrana)** | jedna definicja pieniądza; zmiana zaokrągleń w jednym miejscu; mały, stabilny zbiór | zmiana wymaga zgody obu kontekstów (tu: tego samego zespołu); jeden mechaniczny commit przez 99 plików |
| B. Kopia `Money` w Orderingu | pełna niezależność kontekstów | dwie definicje równości kwot przy regule, która je porównuje (H5); rozjadą się przy pierwszej poprawce |
| C. Ordering importuje `SellerPortal\Domain\Shared` | zero przenoszenia | zamówienia zależą od modelu portalu; test architektury przestaje rozróżniać „wspólne” od „cudze” |
| D. Ordering przekazuje kwoty jako `int` + `string` | brak wspólnego kodu | gubimy regułę „jedna waluta” i typ w sygnaturach domeny; prymitywy tam, gdzie moduł 4 uczy value objectów |

## Konsekwencje

**Pozytywne**
- Cena widziana przez kupującego, cena pozycji i cena oferty to ten sam typ
  z tą samą równością.
- Granice pilnuje CI: `SharedKernel` bez `Illuminate`, `App`, `SellerPortal`,
  `Ordering` i bez funkcji zegara.
- Przeniesienie odbyło się przy pełnym zestawie testów portalu przed i po
  (260 → 262 testy, dwa nowe testy architektury).

**Negatywne**
- Shared Kernel kusi, żeby dorzucać „wspólne” rzeczy. Kryterium wejścia:
  klasa modelu, stabilna, bez reguł jednego kontekstu. `RetryOnConflict` go
  nie spełnia (warstwa aplikacji) i został zduplikowany.
- Test architektury Shared Kernelu przechodzi także wtedy, gdy katalog jest
  pusty (Pest `arch()` nie pada na brak klas); zauważone w Tasku 1, odłożone.
- Infrastruktura Orderingu i tak sięga do portalu poza Shared Kernelem
  (`SqlTimestamp` w `OrderEventLog`, komendy rezerwacji w adapterze). To
  dozwolone: z `SellerPortal\*` korzysta wyłącznie `Ordering\Infrastructure`.
