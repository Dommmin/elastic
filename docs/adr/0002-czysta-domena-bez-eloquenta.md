# ADR-0002: Czysta domena, repozytoria na Query Builderze, bez Eloquenta

- **Status:** przyjęta
- **Data:** 2026-10-15
- **Kontekst decyzji:** etap „Portal sprzedawcy” ([spec](../superpowers/specs/2026-10-15-portal-sprzedawcy-ddd-design.md), [moduły 4, 5, 7 w `10-DDD.md`](../10-DDD.md))

## Kontekst

Agregaty `Offer` i `Seller` mają niezmienniki, których nie wolno złamać
nawet na chwilę: przejścia statusów, strażnik ceny (próg 30%, limit 5 zmian
na dobę), rodzaj zawieszenia. Chcemy, żeby:

- niepoprawnego agregatu nie dało się zbudować inaczej niż przez metody
  domenowe (prywatny konstruktor, fabryki `draft`/`register`, odtworzenie
  `reconstitute`),
- reguły dało się testować bez bazy i frameworka, szybko (kryterium sukcesu
  specu: testy domeny < 1 s),
- reguły nie dało się obejść jednym `->update([...])`.

Eloquent to Active Record: model *jest* wierszem. Ma publiczny konstruktor,
`fill()`, `save()` na każdym obiekcie i `Model::query()->update()` na całej
tabeli. Agregat z prywatnym konstruktorem i niezmiennikami nie da się w nim
wyrazić bez walki z frameworkiem.

## Decyzja

1. `SellerPortal\Domain` to **czyste PHP**: zero `Illuminate\*`, zero
   `App\*`, czas przekazywany jako `DateTimeImmutable $now`. Pilnuje tego
   test architektury.
2. Repozytoria (`SqlOfferRepository`, `SqlSellerRepository`) mapują agregaty
   **ręcznie przez Query Builder** (`DB::table(...)`): `row()` przy zapisie,
   `hydrate()` przy odczycie, `UPDATE … WHERE version = ?` dla
   optymistycznej blokady. Nazwa `Sql*`, nie `Postgres*`, bo testy chodzą
   na SQLite w pamięci, a Query Builder jest przenośny.
3. Odczyt dla UI (listy, pulpit, kolejki moderatora, projekcja) **omija
   agregaty**: zapytania SQL do tablic (`Infrastructure/ReadModel/*`) albo
   istniejące modele Eloquenta jako modele odczytu (scope `listed()`).
   Eloquent `Offer` stracił metody zapisu.
4. `hydrate()` nie ufa bazie: niespójny wiersz (np. `suspended` bez
   `suspension_kind`) kończy się wyjątkiem z numerem oferty, a nie po cichu
   zepsutym agregatem.

## Rozważane opcje

| Opcja | Za | Przeciw |
|---|---|---|
| **A. Czysta domena + ręczne mapowanie (wybrana)** | agregat chroni się sam; domena testowana w ms bez frameworka; mapowanie jawne i testowalne | ~600 linii mapowania i tłumaczenia zdarzeń; każda nowa kolumna w 5–6 miejscach |
| B. Bogate modele Eloquenta (DDD-lite) | mało kodu, znany styl, relacje i casty za darmo | reguły obchodzi `Offer::query()->update(...)`; testy reguł wymagają bazy; publiczny konstruktor i `fill()` |
| C. Doctrine ORM (Data Mapper) | mapowanie bez ręcznego kodu, encje jako czyste obiekty | nowa, ciężka zależność w aplikacji Laravela (globalne ograniczenie: zero nowych pakietów); drugi ORM obok Eloquenta |
| D. Eloquent jako „rekord” + agregat budowany z rekordu | mniej SQL-a | dwa obiekty na jedną rzecz, łatwo zapisać rekord z pominięciem agregatu |

## Konsekwencje

**Pozytywne**
- 130 testów domeny i aplikacji w 44 ms ([POMIARY](../POMIARY.md)), na
  repozytoriach w pamięci (`tests/Unit/SellerPortal/InMemory/`).
- Reguł nie da się obejść z kodu portalu: jedyna droga zapisu to metoda
  agregatu + repozytorium. (Surowy SQL w innym miejscu aplikacji nadal by
  je obszedł. Granicę trzyma przegląd kodu i zasada jednego właściciela zapisu.)
- Optymistyczna blokada jest jawna i przetestowana (konflikt → komunikat
  „Ktoś w międzyczasie zmienił tę ofertę — odśwież stronę”, nigdy ciche nadpisanie).

**Negatywne**
- Koszt mapowania: `Infrastructure/Persistence` to 598 linii (dwa
  repozytoria, tłumacz zdarzeń, `NextId`, `SqlTimestamp`). Nowe pole agregatu
  = migracja, `row`, `hydrate`, `reconstitute`, konstruktor, getter.
- Rozjazd mapowania ze schematem nie jest wyłapywany przez kompilator.
  Broni przed nim test „zapis i odczyt oferty zachowuje wszystkie pola”.
- Typy kolumn trzeba sprawdzać na prawdziwym Postgresie: `timestampTz` bez
  precyzji to `timestamptz(0)`, czego SQLite nie pokaże (naprawione
  w Tasku 6, RUNBOOK [#041](../RUNBOOK.md#041)).
- Ile to kosztuje względem DDD-lite: [`docs/blog/ddd-vs-ddd-lite.md`](../blog/ddd-vs-ddd-lite.md)
  (gałąź `ddd-lite-porownanie`). Lite to połowa kodu przy tych samych testach
  HTTP; „obejście przez `Offer::query()->update(...)`” z tabeli wyżej działa
  w obu wersjach, bo model `App\Models\Offer` istnieje i tutaj.
