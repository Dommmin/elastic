# ADR-0003: Zdarzenia sprzedawcy zostają wewnątrz kontekstu

- **Status:** przyjęta
- **Data:** 2026-10-15
- **Kontekst decyzji:** etap „Portal sprzedawcy” ([spec, sekcja 3](../superpowers/specs/2026-10-15-portal-sprzedawcy-ddd-design.md), [moduł 6 w `10-DDD.md`](../10-DDD.md))

## Kontekst

Agregat `Seller` emituje pięć zdarzeń domenowych: `SellerRegistered`,
`SellerApproved`, `SellerRejected`, `SellerBlocked`, `SellerUnblocked`.
Outbox catalogu publikuje zdarzenia do RabbitMQ, a kolejka searcha ma już
binding `seller.#` (z ETAPU 6, z myślą o `seller.updated`).

Pytanie: czy publikować zdarzenia sprzedawcy jako zdarzenia integracyjne
`seller.*`?

Fakty:
- Jedyny konsument, search, potrzebuje wiedzieć, **które oferty są
  widoczne**. Status sprzedawcy sam w sobie go nie interesuje.
- Skutek blokady dla searcha (oferty znikają) i tak przychodzi jako
  `offer.suspended` dla każdej oferty, emitowane przez politykę
  `SuspendOffersOfBlockedSeller`.
- Search przy każdym `offer.*` pobiera projekcję produktu, a projekcja bierze
  tylko oferty `listed()`. Decyzja „widoczna czy nie” zapada w jednym miejscu.
- Każde opublikowane zdarzenie to kontrakt, który trzeba utrzymywać
  (nazwa, kształt payloadu, kompatybilność wstecz).

## Decyzja

Zdarzenia sprzedawcy są **wyłącznie zdarzeniami domenowymi**: słuchają ich
polityki w procesie (`InProcessDomainEventDispatcher`), ale
`SqlSellerRepository` nie zapisuje ich do outboxu. Search dowiaduje się
o skutkach blokady i odblokowania przez `offer.suspended` i `offer.reinstated`
każdej oferty. Handler searcha dla `seller.*` (gdyby jakiś przyszedł) loguje
ostrzeżenie „fan-out niezaimplementowany” zamiast gubić go po cichu.

Zasada ogólna: **publikujemy to, czego ktoś potrzebuje, a nie wszystko, co
się wydarzyło.**

## Rozważane opcje

| Opcja | Za | Przeciw |
|---|---|---|
| **A. Zdarzenia sprzedawcy tylko w kontekście (wybrana)** | zero nowego kontraktu; jedna droga dla searcha (projekcja); swoboda zmian modelu sprzedawcy | inny konsument, który zechce wiedzieć o blokadzie (np. analityka, powiadomienia), wymaga nowej decyzji |
| B. Publikować `seller.*` i niech search sam zawiesza oferty w ES (`update_by_query`) | szybciej w ES (jedno zdarzenie zamiast N) | reguła „która oferta jest widoczna” w dwóch miejscach (catalog i search), więc się rozjadą; search musiałby znać `SuspensionKind`, żeby przy odblokowaniu nie przywrócić ofert zawieszonych przez moderatora |
| C. Publikować `seller.*` „na zapas”, bez konsumenta | „może się przyda” | kontrakt bez konsumenta to koszt bez zysku; nie wiadomo, jaki kształt będzie potrzebny |

## Konsekwencje

**Pozytywne**
- Model sprzedawcy (statusy, powody) można zmieniać bez koordynacji z searchem.
- Search ma jedną regułę widoczności (projekcja), więc blokada i ręczne
  zawieszenie przez moderatora wyglądają dla niego identycznie.
- Test „zapis sprzedawcy nie tworzy wiersza outboxu” pilnuje decyzji.

**Negatywne**
- Blokada sprzedawcy z tysiącami ofert to tysiące zdarzeń `offer.suspended`
  i tyle samo pobrań projekcji. W weryfikacji E2E na lokalnym stacku blokada
  sprzedawcy Orn Ltd zawiesiła jego 76 opublikowanych ofert i wszystkie
  zniknęły z wyszukiwarki. Czasu nie mierzyliśmy; przy wielokrotnie większej
  skali trzeba to zrobić.
- Zmiana nazwy sprzedawcy dalej nie trafia do searcha (fan-out
  `seller.updated` świadomie poza zakresem).
- Gdy pojawi się konsument statusu sprzedawcy, trzeba będzie zaprojektować
  kontrakt `seller.*` od zera (z tłumaczem, jak `OfferIntegrationEvents`).
