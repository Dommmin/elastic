# ADR-0004: „Wyprzedana” to stan pochodny, nie status oferty

- **Status:** przyjęta
- **Data:** 2026-10-15
- **Kontekst decyzji:** etap „Portal sprzedawcy” ([spec, język wszechobecny](../superpowers/specs/2026-10-15-portal-sprzedawcy-ddd-design.md), [moduł 1 i 5 w `10-DDD.md`](../10-DDD.md))

## Kontekst

Sprzedawca i kupujący mówią „oferta wyprzedana”. Przy projektowaniu specu
(i w rekonstrukcji Event Stormingu w module 1 `10-DDD.md`) pojawiły się
zdarzenia „Towar się skończył” i „Towar wrócił”, a z nimi pytanie: czy „wyprzedana” to piąty status obok szkicu, opublikowanej,
zawieszonej i wycofanej?

Fakty:
- „Wyprzedana” zależy wyłącznie od dwóch rzeczy, które oferta już ma:
  statusu i stanu magazynowego.
- Stan magazynowy zmienia się często i może się zmieniać w każdym statusie
  poza wycofaną (np. sprzedawca uzupełnia stan zawieszonej oferty).
- Wyszukiwarka potrzebuje odpowiedzi na pytanie „czy pokazać ofertę”, a nie
  „jaki ma status”.

## Decyzja

`OfferStatus` ma cztery wartości: `Draft`, `Published`, `Suspended`,
`Withdrawn`. Do tego dwa **stany pochodne**, liczone z `status` i `stock`:

- `Offer::isSoldOut()`: opublikowana **i** stan = 0,
- `Offer::isListed()`: opublikowana **i** stan > 0 (widoczna w wyszukiwarce).

Po stronie odczytu ta sama definicja to scope `listed()` na modelu Eloquenta
`Offer`. Zastąpił on wszystkie dawne `where('active', true)` (projekcja,
panel produktów, ślad zdarzeń). Przejście przez zero na opublikowanej ofercie
daje osobne zdarzenia `OfferSoldOut` / `OfferBackInStock` (`offer.sold_out`,
`offer.back_in_stock`), bo to wtedy zmienia się to, co widzi kupujący.

## Rozważane opcje

| Opcja | Za | Przeciw |
|---|---|---|
| **A. Stan pochodny (wybrana)** | jedna prawda (`status` + `stock`), nie da się jej rozjechać; mniej przejść w maszynie stanów | filtr „wyprzedane” w UI to warunek na dwóch kolumnach, nie na jednej |
| B. Status `SoldOut` | prosty filtr po jednej kolumnie | każda zmiana stanu musi pamiętać o zmianie statusu; co z zawieszoną ofertą, której stan spadł do 0: `Suspended` czy `SoldOut`? Po przywróceniu: `Published` czy `SoldOut`? Kombinatoryka przejść rośnie, a każde przeoczenie to oferta widoczna bez towaru albo niewidoczna z towarem |
| C. Flaga `sold_out` obok statusu | filtr po jednej kolumnie | dwie prawdy o tym samym (flaga i `stock`), do synchronizacji przy każdym zapisie; klasyczne źródło niespójności |

## Konsekwencje

**Pozytywne**
- Stan „wyprzedana” nie może się rozjechać ze stanem magazynowym, bo nie
  jest nigdzie zapisany.
- Maszyna stanów oferty ma 4 statusy i 6 przejść (krawędzi) zamiast kilkunastu.
- Jedna definicja widoczności po obu stronach (domena `isListed()`, odczyt
  `listed()`), przypięta testami (`OfferReadModelTest` › „listed() =
  published i stock > 0”).

**Negatywne**
- Indeks pod projekcję musi obejmować obie kolumny: `(product_id, status, stock)`.
- **Zmiana zachowania wyszukiwarki, zmierzona:** wcześniej oferty ze stanem 0
  trafiały do projekcji (z `in_stock: false`), teraz z niej znikają.
  Na danych deweloperskich 89 z 4518 ofert (ok. 2%) miało stan 0;
  po `make reproject` nDCG@10 zmieniło się z 0.779 na 0.786
  ([POMIARY](../POMIARY.md), z hipotezami, dlaczego).
- Dwie definicje (domena i scope Eloquenta) muszą pozostać zgodne. Nic poza
  testami i tym ADR-em ich nie wiąże.
