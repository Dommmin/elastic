# ADR-0001: Seller Portal jako moduł w aplikacji `catalog` (modularny monolit)

- **Status:** przyjęta
- **Data:** 2026-10-15
- **Kontekst decyzji:** etap „Portal sprzedawcy” ([spec](../superpowers/specs/2026-10-15-portal-sprzedawcy-ddd-design.md), [moduł 2 i 3 w `10-DDD.md`](../10-DDD.md))

## Kontekst

Portal sprzedawcy to nowy bounded context: sprzedawcy zakładają konta,
moderator je akceptuje, sprzedawcy prowadzą oferty, moderator moderuje. To
**core** systemu i jedyna część, w której stosujemy pełne DDD.

Trzeba zdecydować, gdzie ten kontekst *fizycznie* żyje. Fakty:

- Oferty już istnieją w bazie `catalog` (tabela `offers`, klucz obcy do
  `products`), a wyszukiwarka dostaje o nich zdarzenia przez outbox catalogu
  (`offer.*`) i pobiera projekcję produktu z catalogu.
- Każda zmiana oferty musi atomowo zapisać stan i wiersz outboxu (ETAP 6,
  [`05-SPOJNOSC-DANYCH.md`](../05-SPOJNOSC-DANYCH.md)).
- Projekt prowadzi jedna osoba, na jednym serwerze (Compose, potem k3s).
  Każda nowa usługa to obraz, Deployment, sekrety, healthcheck i pozycja
  w RUNBOOK-u.
- Celem etapu jest nauka modelowania, nie systemów rozproszonych.

## Decyzja

Seller Portal jest **modułem w aplikacji `catalog`**: osobna przestrzeń nazw
`SellerPortal\` w nowym katalogu bazowym `apps/catalog/src/` (PSR-4),
z warstwami `Domain`, `Application`, `Infrastructure`. Bez osobnego
deploymentu i bez osobnej bazy.

Granica kontekstu jest pilnowana w kodzie, nie w infrastrukturze:

1. test architektury (Pest `arch()`) zabrania domenie używać `Illuminate\*`,
   `App\*` i pozostałych warstw, a aplikacji `Illuminate\*`, `App\*`
   i `Infrastructure` (`apps/catalog/tests/Arch/SellerPortalLayersTest.php`),
2. tabele `offers`, `sellers`, `offer_price_changes` mają **jednego właściciela
   zapisu**: repozytoria Seller Portalu. Reszta aplikacji czyta je przez
   modele odczytu (Eloquent `Offer` stracił metody zapisu),
3. katalog produktów jest pytany przez port po stronie Seller Portalu
   (`Application/Port/ProductCatalog`), nie przez bezpośrednie użycie modeli
   Eloquenta katalogu,
4. jedyny nowy proces to `catalog-worker` (`queue:work database`) dla polityk.

## Rozważane opcje

| Opcja | Za | Przeciw |
|---|---|---|
| **A. Moduł w `catalog` (wybrana)** | jedna transakcja na stan + outbox; zero nowej infrastruktury; refaktor granicy to zmiana w kodzie | granica tylko tak mocna jak test architektury; wspólna baza kusi do skrótów |
| B. Osobna usługa (nowa aplikacja + baza) | twarda granica, niezależne wdrożenia | dwie bazy = transakcje rozproszone albo saga dla „oferta wymaga istniejącego produktu”; nowy obraz, sekrety, monitoring; dla jednej osoby koszt operacyjny > zysk |
| C. Kod w `app/` obok reszty (bez osobnego katalogu i testu) | najprostsze | granica niewidoczna; za pół roku domena importuje Eloquenta i nikt tego nie zauważy |
| D. Osobny pakiet Composera w monorepo | granica na poziomie zależności | ceremonia (wersjonowanie, autoload) bez realnego zysku przy jednym konsumencie |

## Konsekwencje

**Pozytywne**
- Zapis oferty i jej zdarzenia `offer.*` lądują w jednej transakcji, tak jak
  wcześniej, bez sagi i bez dwufazowego commitu.
- Zmiana „to jednak osobna usługa” jest możliwa później i ograniczona:
  `DbProductCatalog` → klient HTTP, repozytoria → inna baza, klucz obcy
  `offers.product_id` znika. Domena i aplikacja się nie zmieniają.
- Test architektury pada w CI, gdy ktoś złamie zależności warstw.

**Negatywne / do pilnowania**
- Wspólna baza oznacza sprzężenie: `offers.product_id` ma klucz obcy do
  `products`, a modele odczytu (`ProductLookup`, projekcja) robią JOIN-y
  przez granicę kontekstów. Świadomie to akceptujemy.
- Test architektury pilnuje przestrzeni nazw, nie wszystkiego. Na przykład
  globalnej funkcji `now()` w domenie nie widzi (ćwiczenie w module 8
  `10-DDD.md` pokazuje lukę i regułę, która ją zamyka).
- Nowy katalog bazowy `src/` to wyjątek od struktury Laravela, który trzeba
  znać (autoload w `composer.json`, `phpunit.xml` → `<source>`).
- Moduł dzieli procesy z resztą `catalog`: błąd pamięci w portalu dotyka też
  katalogu i projekcji.
