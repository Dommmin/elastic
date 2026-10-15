# 11 — Słownik portalu sprzedawcy (język wszechobecny)

Ten słownik obowiązuje **wewnątrz kontekstu Seller Portal**. To samo słowo
w innym kontekście może znaczyć co innego (np. „oferta” w dokumencie
wyszukiwarki to zagnieżdżony obiekt z ceną, a nie agregat z cyklem życia;
patrz [`10-DDD.md`](10-DDD.md), moduł 2).

Zasady:
- **Rozmowa, UI i dokumentacja po polsku, kod po angielsku.** Mapowanie jest
  1:1. Jeśli w kodzie pojawia się nowe pojęcie, dopisz je tutaj w tym samym
  commicie. Jeśli w UI pojawia się słowo bez pary w kodzie, to sygnał, że
  model ma dziurę.
- **Nie używaj synonimów.** „Oferta aktywna”, „oferta włączona”, „oferta
  dostępna” nie istnieją. Są tylko statusy poniżej i dwa stany pochodne.
- Kolumna „Gdzie” podaje symbol. Ścieżki są względne wobec
  `apps/catalog/src/SellerPortal/`, chyba że zaczynają się od `apps/` lub `app/`
  (wtedy względem korzenia repo albo `apps/catalog/`).

---

## Aktorzy i konto

| PL | Kod | Definicja | Gdzie |
|---|---|---|---|
| użytkownik | `UserId` | konto logowania (Fortify, tabela `users`). Seller Portal bierze jego ID jak jest (Conformist) | `Domain/Shared/UserId.php` |
| sprzedawca | `Seller` | konto handlowe firmy sprzedającej, 1:1 z użytkownikiem. Agregat. Nie przechowuje swoich ofert | `Domain/Seller/Seller.php` |
| moderator | `ModeratorId` | użytkownik z uprawnieniem `can:panel` działający w roli moderatora. Osobny typ, żeby nie dało się podać ID sprzedawcy tam, gdzie decyduje moderator | `Domain/Shared/ModeratorId.php` |
| aktor | `Actor` (`Seller`, `Moderator`) | kto pyta o dozwolone akcje przy ofercie. Ta sama oferta daje sprzedawcy i moderatorowi różne ruchy | `Domain/Offer/Actor.php` |
| nazwa sprzedawcy | `SellerName` | 3–120 znaków po przycięciu | `Domain/Seller/SellerName.php` |
| miasto | `City` | niepuste, ≤ 120 znaków | `Domain/Seller/City.php` |
| zgłoszenie (rejestracja) | `Seller::register`, `RegisterSeller` | użytkownik prosi o konto sprzedawcy. Powstaje sprzedawca w statusie „oczekuje” | `Application/Seller/RegisterSellerHandler.php` |

### Status sprzedawcy (`SellerStatus`)

| PL | Kod | Znaczenie | Przejścia |
|---|---|---|---|
| oczekuje | `Pending` | zgłoszenie czeka na moderatora | → aktywny (`approve`), → odrzucony (`reject`) |
| aktywny | `Active` | może wystawiać i zmieniać oferty (`canSell()`) | → zablokowany (`block`) |
| odrzucony | `Rejected` | **stan końcowy**. Użytkownik ma najwyżej jeden profil sprzedawcy, więc odrzucenie jest ostateczne | brak |
| zablokowany | `Blocked` | nie sprzedaje; widzi swoje oferty i może je tylko **wycofać** | → aktywny (`unblock`) |

| PL | Kod | Definicja |
|---|---|---|
| zatwierdzenie / akceptacja konta | `Seller::approve`, `ApproveSeller` | moderator wpuszcza sprzedawcę |
| odrzucenie konta | `Seller::reject(…, Reason)` | z powodem, ostatecznie |
| zablokowanie | `Seller::block(…, Reason)` | z powodem. Uruchamia politykę zawieszenia ofert |
| odblokowanie | `Seller::unblock` | uruchamia politykę przywrócenia ofert (tylko tych zawieszonych przez blokadę) |
| może sprzedawać | `Seller::canSell()` | `status = Active`. Pytanie, nie wyjątek |
| nie może sprzedawać | `SellerCannotSell` (`seller_cannot_sell`) | próba sprzedaży przez konto inne niż aktywne |
| sprzedawca już zarejestrowany | `SellerAlreadyRegistered` (`seller_already_registered`) | drugie zgłoszenie tego samego użytkownika, także przy wyścigu (indeks `sellers.user_id`) |

## Oferta

| PL | Kod | Definicja | Gdzie |
|---|---|---|---|
| oferta | `Offer` | propozycja sprzedaży **jednego** produktu z katalogu przez **jednego** sprzedawcę: cena, stan magazynowy, stan egzemplarza, czas wysyłki. Agregat | `Domain/Offer/Offer.php` |
| produkt | `ProductId` | produkt z katalogu (inny kontekst). Portal zna tylko jego ID i pyta katalog, czy istnieje | `Domain/Offer/ProductId.php`, port `Application/Port/ProductCatalog.php` |
| kwota, cena | `Money` | grosze (int > 0) plus waluta. Arytmetyka tylko w tej samej walucie | `Domain/Shared/Money.php` |
| waluta | `Currency` | dziś tylko PLN | `Domain/Shared/Currency.php` |
| stan magazynowy, stan | `Stock` | 0–100 000 sztuk | `Domain/Offer/Stock.php` |
| stan egzemplarza | `Condition` (`New`, `Used`) | nowy / używany. Opis oferty, nie produktu | `Domain/Offer/Condition.php` |
| czas wysyłki | `ShippingTime` | 1–30 dni | `Domain/Offer/ShippingTime.php` |
| szczegóły oferty | `changeDetails(Condition, ShippingTime)` | stan egzemplarza i czas wysyłki razem | `Offer::changeDetails` |

### Status oferty (`OfferStatus`)

| PL | Kod | Znaczenie | Przejścia |
|---|---|---|---|
| szkic | `Draft` | wystawiona, niewidoczna. Cena zmienia się bez strażnika | → opublikowana, → wycofana |
| opublikowana | `Published` | widoczna dla kupujących, **o ile** stan > 0 | → zawieszona, → wycofana |
| zawieszona | `Suspended` | zdjęta przez moderatora albo przez blokadę sprzedawcy. Sprzedawca może zmienić stan, anulować zgłoszoną cenę albo wycofać | → opublikowana (`reinstate`), → wycofana |
| wycofana | `Withdrawn` | **stan końcowy**. Nie blokuje nowej oferty na ten sam produkt | brak |

### Stany pochodne (nie statusy!)

| PL | Kod | Definicja | Dlaczego nie status |
|---|---|---|---|
| **widoczna** (w wyszukiwarce) | `Offer::isListed()`; po stronie odczytu scope `listed()` (`apps/catalog/app/Models/Offer.php`) | opublikowana **i** stan > 0 | to jedyna definicja „widocznej”; projekcja do ES bierze tylko takie oferty |
| **wyprzedana** | `Offer::isSoldOut()`; filtr `sold_out` w `Infrastructure/ReadModel/SellerOfferList.php` | opublikowana **i** stan = 0 | gdyby była statusem, trzeba by ją synchronizować ze stanem przy każdej zmianie. [ADR-0004](adr/0004-wyprzedana-jako-stan-pochodny.md) |
| **żywa** oferta | `OfferRepository::hasLiveOffer` | każda poza wycofaną (szkic, opublikowana, zawieszona) | reguła na zbiorze: sprzedawca ma najwyżej jedną żywą ofertę na produkt (`LiveOfferAlreadyExists`, `live_offer_exists`) |

### Operacje na ofercie

| PL | Kod | Kto | Uwagi |
|---|---|---|---|
| wystawienie (szkic) | `Seller::draftOffer` → `Offer::draft`, `DraftOffer` | sprzedawca | zaczyna się u sprzedawcy, bo tylko on wie, czy może sprzedawać |
| publikacja | `Offer::publish(Seller, now)` | sprzedawca | ze szkicu, stan > 0 (`OfferCannotBePublishedWithoutStock`, `offer_without_stock`), sprzedawca aktywny |
| zmiana ceny | `Offer::changePrice`, `ChangeOfferPrice` | sprzedawca | w szkicu od razu; w opublikowanej przez strażnika ceny |
| zmiana stanu | `Offer::changeStock` | sprzedawca | w opublikowanej przejście > 0 → 0 to „towar się skończył”, 0 → > 0 to „towar wrócił” |
| zawieszenie | `Offer::suspend(SuspensionReason)` | moderator albo polityka | tylko z opublikowanej |
| przywrócenie | `Offer::reinstate` | moderator albo polityka | z zawieszonej do opublikowanej |
| wycofanie | `Offer::withdraw` | sprzedawca (także zablokowany) | kasuje oczekującą zmianę ceny |
| akcja | `OfferAction` | — | nazwa operacji, którą UI może zaproponować (`publish`, `change_price`, `cancel_pending_price_change`, `change_stock`, `change_details`, `withdraw`, `suspend`, `reinstate`, `approve_price_change`, `reject_price_change`) |
| dozwolone akcje | `Offer::allowedActionsFor(Actor)`; dla sprzedawcy przez `SellerOfferAccess::sellerActions` | — | **jedyne** źródło przycisków w UI. Zablokowany sprzedawca dostaje z tej listy tylko `withdraw` |
| niedozwolone przejście | `InvalidOfferTransition` (`invalid_offer_transition`), `InvalidSellerTransition` (`invalid_seller_transition`) | — | akcja niedozwolona w obecnym statusie |
| cudza oferta | `OfferNotOwned` (`offer_not_owned`) | — | dla użytkownika to **404**, nie 403: nie zdradzamy, że oferta istnieje |

## Strażnik ceny

| PL | Kod | Definicja |
|---|---|---|
| strażnik ceny | `PriceChangePolicy` | reguły zmiany ceny opublikowanej oferty. Czysta klasa domeny, czas z zewnątrz |
| próg | `PriceChangePolicy::THRESHOLD_PERCENT = 30` | zmiana o **ściśle więcej** niż 30% idzie do akceptacji. Liczone od **obecnej** ceny: `|nowa − stara| × 100 > stara × 30`, na liczbach całkowitych |
| limit dobowy | `PriceChangePolicy::MAX_CHANGES_PER_DAY = 5` | najwyżej 5 zmian w **kroczącym oknie 24 h**. Liczą się zmiany wprowadzone i zgłoszone do akceptacji. Akceptacje moderatora się nie liczą, anulowanie nie zwraca miejsca |
| okno kroczące | `PriceChangePolicy::withinWindow` | zmiany z `changed_at > now − 24 h` (otwarte z lewej: zmiana sprzed dokładnie 24 h już się nie liczy) |
| pozostałe zmiany na dobę | `PriceChangePolicy::changesLeft` | to samo, co pokazuje UI („pozostały 3 zmiany na dobę”) |
| decyzja o zmianie ceny | `PriceChangeDecision` (`AppliedNow`, `AwaitingApproval`) | wynik `changePrice`: obowiązuje od razu albo czeka na moderatora |
| zmiana ceny do akceptacji, oczekująca zmiana | `PendingPriceChange` | proponowana cena i chwila zgłoszenia. Część agregatu oferty (VO). Do decyzji obowiązuje stara cena |
| zgłoszenie zmiany | zdarzenie `OfferPriceChangeRequested` | sprzedawca zaproponował cenę powyżej progu |
| akceptacja / odrzucenie zmiany | `approvePriceChange(ModeratorId)` / `rejectPriceChange(ModeratorId, Reason)` | moderator rozstrzyga. Działa też na zawieszonej ofercie |
| anulowanie zgłoszenia | `cancelPendingPriceChange` | sprzedawca wycofuje własną propozycję |
| zmiana już czeka | `PriceChangeAlreadyPending` (`price_change_pending`) | nowej zmiany nie zgłosisz, dopóki poprzednia czeka |
| limit przekroczony | `PriceChangeLimitExceeded` (`price_change_limit`) | szósta zmiana w oknie |
| brak oczekującej zmiany | `NoPendingPriceChange` (`no_pending_price_change`) | np. druga akceptacja tego samego zgłoszenia z dwóch kart |
| kolejka zmian cen | `Infrastructure/ReadModel/PriceChangeQueue.php` | model odczytu moderatora: stara i nowa cena, różnica w %, sprzedawca |

## Zawieszenie

| PL | Kod | Definicja |
|---|---|---|
| powód zawieszenia | `SuspensionReason` | rodzaj plus (przy moderacji) kto i dlaczego. Dwie fabryki: `byModerator(ModeratorId, Reason)` i `sellerBlocked()` |
| rodzaj zawieszenia | `SuspensionKind` (`Moderation` = `moderation`, `SellerBlocked` = `seller_blocked`) | odpowiada na hot spot z Event Stormingu: odblokowanie sprzedawcy przywraca **tylko** oferty zawieszone przez blokadę |
| powód, uzasadnienie | `Reason` | niepusty po przycięciu (także twardych spacji i znaków niewidocznych), ≤ 500 znaków, bez znaków sterujących poza `\n\r\t` |

## Zdarzenia

**Zdarzenie domenowe** (`DomainEvent`): fakt w czasie przeszłym, zapisany
przez agregat (`recordThat`), słuchany w tym samym kontekście.
**Zdarzenie integracyjne**: wiersz outboxu `offer.*`, publiczny kontrakt dla
innych kontekstów (search). Tłumaczy je `Infrastructure/Persistence/OfferIntegrationEvents.php`.

| PL | Zdarzenie domenowe | Zdarzenie integracyjne |
|---|---|---|
| oferta wystawiona | `OfferDrafted` | `offer.drafted` |
| oferta opublikowana | `OfferPublished` | `offer.published` |
| cena zmieniona | `OfferPriceChanged` | `offer.price_changed` |
| zmiana ceny zgłoszona | `OfferPriceChangeRequested` | `offer.price_change_requested` |
| zmiana ceny zaakceptowana | `OfferPriceChangeApproved` | `offer.price_change_approved` |
| zmiana ceny odrzucona | `OfferPriceChangeRejected` | `offer.price_change_rejected` |
| zgłoszenie anulowane | `OfferPriceChangeCancelled` | `offer.price_change_cancelled` (odstępstwo od specu: spec go pominął) |
| stan zmieniony | `OfferStockChanged` | `offer.stock_changed` |
| towar się skończył | `OfferSoldOut` | `offer.sold_out` |
| towar wrócił | `OfferBackInStock` | `offer.back_in_stock` |
| szczegóły zmienione | `OfferDetailsChanged` | `offer.details_changed` |
| oferta zawieszona | `OfferSuspended` | `offer.suspended` |
| oferta przywrócona | `OfferReinstated` | `offer.reinstated` |
| oferta wycofana | `OfferWithdrawn` | `offer.withdrawn` |
| sprzedawca zgłosił się | `SellerRegistered` | **brak** (zdarzenia sprzedawcy nie wychodzą z kontekstu, [ADR-0003](adr/0003-zdarzenia-sprzedawcy-wewnetrzne.md)) |
| konto zatwierdzone / odrzucone | `SellerApproved` / `SellerRejected` | brak |
| sprzedawca zablokowany / odblokowany | `SellerBlocked` / `SellerUnblocked` | brak (search dowiaduje się z `offer.suspended` / `offer.reinstated` każdej oferty) |

| PL | Kod | Definicja |
|---|---|---|
| outbox | tabela `outbox` | zdarzenia integracyjne zapisane w tej samej transakcji co stan. Publikuje je `outbox-publisher` |
| sequence | kolumna `outbox.sequence` | wersja oferty po zapisie. Kilka zdarzeń z jednego zapisu ma ten sam `sequence` |

## Elementy modelu i warstwy

| PL | Kod | Definicja | Gdzie |
|---|---|---|---|
| agregat, korzeń agregatu | `AggregateRoot` | jedyne wejście do grupy obiektów, która musi być spójna po każdej operacji. Zapisuje zdarzenia, nie publikuje ich | `Domain/Shared/AggregateRoot.php` |
| odtworzenie (rekonstytucja) | `Offer::reconstitute`, `Seller::reconstitute` | zbudowanie agregatu ze stanu w bazie, bez zdarzeń i bez reguł przejść | |
| repozytorium | `OfferRepository`, `SellerRepository` | „kolekcja” agregatów. Interfejs w domenie, implementacja SQL w infrastrukturze (`Sql*Repository`) | `Domain/*/…Repository.php`, `Infrastructure/Persistence/` |
| nowa tożsamość | `nextIdentity()` | ID z sekwencji bazy przed zapisem, bo agregat ma ID od urodzenia | `Infrastructure/Persistence/NextId.php` |
| wersja, konflikt wersji | kolumna `version`, `ConcurrencyConflict` (`concurrency_conflict`) | optymistyczna blokada: ktoś zapisał ofertę między naszym odczytem a zapisem. UI: „Ktoś w międzyczasie zmienił tę ofertę — odśwież stronę” | `Infrastructure/Persistence/SqlOfferRepository.php` |
| niepoprawna wartość | `InvalidValue` (`invalid_value`) | VO odmówił utworzenia. Za walidacją formularza to błąd w kodzie, więc **jest** logowany | `Domain/Shared/InvalidValue.php` |
| kod reguły | stała `CODE` każdego `DomainException` | stabilny identyfikator złamanej reguły. Tekst dla człowieka nadaje `apps/catalog/app/Http/DomainErrorMessages.php` | |
| komenda | `DraftOffer`, `ChangeOfferPrice`, `BlockSeller`, … | prosta klasa readonly: intencja aktora | `Application/*/` |
| handler | `…Handler` | orkiestruje: transakcja → wczytaj → wywołaj → zapisz → roześlij zdarzenia. Bez reguł biznesowych | `Application/*/` |
| port | `Clock`, `TransactionManager`, `DomainEventDispatcher`, `PolicyJobQueue`, `ProductCatalog` | interfejs, który aplikacja definiuje dla świata zewnętrznego | `Application/Port/` |
| dostęp sprzedawcy do oferty | `SellerOfferAccess` (`forUser`, `forActiveSeller`, `sellerActions`) | reguły dostępu **aktora**: czyja oferta, czy konto aktywne, co wolno zablokowanemu | `Application/Offer/SellerOfferAccess.php` |
| polityka | `QueueSuspensionWhenSellerBlocked`, `SuspendOffersOfBlockedSeller`, `QueueReinstatementWhenSellerUnblocked`, `ReinstateOffersOfUnblockedSeller` | reakcja „gdy X, to Y” w obrębie kontekstu. Listener kolejkuje job w tej samej transakcji, job wykonuje pracę później | `Application/Policy/` |
| job polityki | `SuspendOffersOfBlockedSellerJob`, `ReinstateOffersOfUnblockedSellerJob` | zadanie w tabeli `jobs`, wykonuje je `catalog-worker` (`queue:work database`). RUNBOOK [#040](RUNBOOK.md#040) | `Infrastructure/Job/` |
| spójność ostateczna | — | po blokadzie oferty znikają z wyszukiwarki po kilku sekundach, nie natychmiast. Świadomy kompromis | [`10-DDD.md`](10-DDD.md), moduł 8 |
| model odczytu | `Infrastructure/ReadModel/*` | zapytania SQL dla UI (lista ofert, pulpit, kolejki moderatora, wyszukiwarka produktów), bez agregatów | |
| zegar | `Clock` → `SystemClock` | „teraz” przekazywane do domeny. Domena nigdy nie pyta o czas sama | `Application/Port/Clock.php` |
| projekcja | `ProductProjectionController` | dokument produktu dla wyszukiwarki, zawiera tylko oferty widoczne (`listed()`) | `apps/catalog/app/Http/Controllers/Internal/ProductProjectionController.php` |
