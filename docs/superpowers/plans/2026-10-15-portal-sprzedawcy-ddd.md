# Portal sprzedawcy (DDD) — plan implementacji

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Kontekst Seller Portal w `apps/catalog`: sprzedawcy rejestrują się, admin ich akceptuje, sprzedawcy prowadzą oferty, admin moderuje; reguły w czystej domenie; potem gałąź DDD-lite do porównania.

**Architecture:** Modularny monolit. `apps/catalog/src/SellerPortal/{Domain,Application,Infrastructure}` (PSR-4 `SellerPortal\`), UI w `app/Http/Controllers/{Seller,Panel/Moderation}` + Inertia/Vue. Zapis wyłącznie przez agregaty `Seller` i `Offer`, odczyt przez SQL/Eloquent. Zdarzenia domenowe → outbox (istniejąca tabela, `offer.*`) i polityki w procesie (job w kolejce `database` w tej samej transakcji).

**Tech Stack:** PHP 8.5, Laravel 13 + Octane, Pest 5 (z `arch()`), Inertia v3 + Vue 3 + shadcn-vue, Wayfinder, Postgres (prod) / SQLite in-memory (testy), RabbitMQ, k3s.

**Spec:** `docs/superpowers/specs/2026-10-15-portal-sprzedawcy-ddd-design.md` — czytaj razem z planem; uzasadnienia decyzji są tam.

## Global Constraints

- `SellerPortal\Domain` nie używa `Illuminate\*`, `App\*`, `SellerPortal\Application\*`, `SellerPortal\Infrastructure\*`. `SellerPortal\Application` nie używa `Illuminate\*` ani `SellerPortal\Infrastructure\*`.
- Domena nie woła `time()`/`now()`/`new DateTimeImmutable()` bez argumentu: każda metoda zmieniająca stan dostaje `DateTimeImmutable $now`.
- Progi: zmiana ceny `> 30%` (`|nowa − stara| × 100 > stara × 30`, int) → do akceptacji; limit `5` zmian ceny w kroczącym oknie `24 h` (liczą się wprowadzone i zgłoszone, nie akceptacje).
- Zakresy VO: `Money` > 0 groszy; `Stock` 0–100 000; `ShippingTime` 1–30; `SellerName` 3–120 znaków; `Reason` niepusty po trim, ≤ 500 znaków; `City` niepusty, ≤ 120.
- Zero nowych zależności Composera i npm.
- Nazwy zdarzeń outboxu: `offer.drafted`, `offer.published`, `offer.price_changed`, `offer.price_change_requested`, `offer.price_change_approved`, `offer.price_change_rejected`, `offer.price_change_cancelled`, `offer.stock_changed`, `offer.sold_out`, `offer.back_in_stock`, `offer.details_changed`, `offer.suspended`, `offer.reinstated`, `offer.withdrawn`. Zdarzenia sprzedawcy NIE trafiają do outboxu.
  - Odstępstwo od specu: dochodzi `offer.price_change_cancelled` — anulowanie zmienia stan oferty, więc musi być zdarzeniem; spec go pominął.
  - Odstępstwo od specu: repozytoria nazywają się `Sql*Repository` (nie `Postgres*`), bo testy chodzą na SQLite in-memory (`phpunit.xml`), a Query Builder jest przenośny.
- Testy uruchamiamy na hoście (Herd PHP 8.5) z `apps/catalog`: `php artisan test --compact <ścieżka>` — `phpunit.xml` wymusza SQLite in-memory (RUNBOOK 018/026, nie ruszać).
- Każdy dotknięty plik PHP: `vendor/bin/pint --dirty --format agent`. Frontend: `npm run lint:check && npm run format:check && npm run types:check`. Wayfinder zawsze `php artisan wayfinder:generate --with-form`.
- Komentarze i komunikaty UI po polsku, kod po angielsku; w docblockach „dlaczego”, nie „co” (styl repo).
- Commit kończy się linią `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Prefiks tematu: `Portal sprzedawcy: …`.

## Review Focus

1. **Podwójne kliknięcie / wyścig dwóch kart** (sprzedawca i moderator zmieniają tę samą ofertę) → jeden zapis wygrywa, drugi dostaje komunikat „Ktoś w międzyczasie zmienił tę ofertę — odśwież” (HTTP 302 z błędem), nigdy cichego nadpisania ani 500. Test w Task 6 i Task 10.
2. **Szybkie „zablokuj → odblokuj” przed wykonaniem joba** → żadna oferta nie zostaje zawieszona. Test w Task 8.
3. **Sprzedawca próbuje akcji na cudzej ofercie po ID z URL** → 404 (nie 403 — nie zdradzamy istnienia), stan bez zmian. Test w Task 10.
4. **Cena wpisana z przecinkiem i spacjami („1 299,99”)** — panel już to obsługuje, portal musi tak samo → 129999 groszy. Test w Task 10.
5. **Druga żywa oferta tego samego sprzedawcy na ten sam produkt** (także wyścig, gdy sprawdzenie aplikacji przepuści) → czytelny błąd formularza; indeks unikalny jako gwarancja → też czytelny błąd, nie 500. Test w Task 6 (indeks) i Task 10 (HTTP).

---

## Mapa plików

```
apps/catalog/src/SellerPortal/
  Domain/Shared/      AggregateRoot, DomainEvent, DomainException, ConcurrencyConflict,
                      Money, Currency, CurrencyMismatch, InvalidValue, Reason, UserId, ModeratorId
  Domain/Seller/      Seller, SellerId, SellerName, City, SellerStatus, SellerRepository,
                      InvalidSellerTransition, SellerCannotSell, SellerNotFound,
                      Event/{SellerRegistered,SellerApproved,SellerRejected,SellerBlocked,SellerUnblocked}
  Domain/Offer/       Offer, OfferId, ProductId, Stock, ShippingTime, Condition, OfferStatus,
                      OfferAction, Actor, SuspensionKind, SuspensionReason, PendingPriceChange,
                      PriceChangePolicy, PriceChangeDecision, OfferRepository,
                      InvalidOfferTransition, OfferCannotBePublishedWithoutStock,
                      PriceChangeAlreadyPending, NoPendingPriceChange, PriceChangeLimitExceeded,
                      OfferNotFound, LiveOfferAlreadyExists,
                      Event/{OfferDrafted, OfferPublished, OfferPriceChanged, OfferPriceChangeRequested,
                             OfferPriceChangeApproved, OfferPriceChangeRejected, OfferPriceChangeCancelled,
                             OfferStockChanged, OfferSoldOut, OfferBackInStock, OfferDetailsChanged,
                             OfferSuspended, OfferReinstated, OfferWithdrawn}
  Application/        Port/{TransactionManager, DomainEventDispatcher, ProductCatalog, Clock, PolicyJobQueue}
                      Seller/{RegisterSeller(+Handler), ApproveSeller, RejectSeller, BlockSeller, UnblockSeller}
                      Offer/{DraftOffer, PublishOffer, ChangeOfferPrice, CancelPendingPriceChange,
                             ChangeOfferStock, ChangeOfferDetails, WithdrawOffer, SellerOfferAccess, OfferNotOwned}
                      Moderation/{SuspendOffer, ReinstateOffer, ApprovePriceChange, RejectPriceChange}
                      Policy/{QueueSuspensionWhenSellerBlocked, QueueReinstatementWhenSellerUnblocked,
                              SuspendOffersOfBlockedSeller, ReinstateOffersOfUnblockedSeller}
                      SellerAlreadyRegistered, ProductNotFound
  Infrastructure/     Persistence/{SqlSellerRepository, SqlOfferRepository, OfferIntegrationEvents, NextId}
                      LaravelTransactionManager, InProcessDomainEventDispatcher, DbProductCatalog,
                      SystemClock, LaravelPolicyJobQueue,
                      Job/{SuspendOffersOfBlockedSellerJob, ReinstateOffersOfUnblockedSellerJob}
                      ReadModel/{SellerOfferList, SellerOfferDetails, SellerDashboardStats,
                                 PendingSellers, SellerDirectory, PriceChangeQueue, ProductLookup}
apps/catalog/app/Providers/SellerPortalServiceProvider.php
apps/catalog/app/Http/Middleware/{EnsureHasSeller, EnsureSellerApproved}.php
apps/catalog/app/Http/DomainErrorMessages.php
apps/catalog/app/Http/Controllers/Seller/{SellerApplicationController, SellerDashboardController,
                                         SellerOfferController, ProductLookupController}.php
apps/catalog/app/Http/Controllers/Panel/Moderation/{SellerModerationController,
                                         PriceChangeModerationController, OfferModerationController}.php
apps/catalog/resources/js/pages/seller/{Apply,Status,Dashboard}.vue, seller/offers/{Index,Create,Show}.vue
apps/catalog/resources/js/pages/panel/sellers/Index.vue, panel/moderation/PriceChanges.vue
apps/catalog/resources/js/components/seller/{OfferStatusBadge,PriceChangeForm,OfferTimeline,BlockedBanner}.vue
apps/catalog/resources/js/types/seller.ts
tests/Unit/SellerPortal/Domain/*, tests/Unit/SellerPortal/Application/*, tests/Unit/SellerPortal/InMemory/*
tests/Feature/SellerPortal/{Infrastructure,Http}/*, tests/Arch/SellerPortalLayersTest.php
```

---

### Task 1: Szkielet modułu, wspólne VO, test architektury

**Files:**
- Modify: `apps/catalog/composer.json` (autoload psr-4 `"SellerPortal\\": "src/SellerPortal/"`), potem `composer dump-autoload`
- Modify: `apps/catalog/phpunit.xml` (testsuite `Arch` → `tests/Arch`; `<source><include>` dodać `src`)
- Create: `src/SellerPortal/Domain/Shared/*` (lista w mapie plików)
- Test: `tests/Unit/SellerPortal/Domain/MoneyTest.php`, `tests/Unit/SellerPortal/Domain/SharedValuesTest.php`, `tests/Arch/SellerPortalLayersTest.php`

**Interfaces — Produces:**
- `abstract class AggregateRoot { protected function recordThat(DomainEvent $e): void; /** @return list<DomainEvent> */ public function pendingEvents(): array; /** @return list<DomainEvent> */ public function releaseEvents(): array; }` — `pendingEvents` podgląda (czyta repozytorium), `releaseEvents` zwraca i czyści (woła handler po zapisie).
- `interface DomainEvent { public function occurredAt(): DateTimeImmutable; }`
- `abstract class DomainException extends \DomainException` — każdy wyjątek domeny ma stały `public const string CODE` (np. `'price_change_limit_exceeded'`), używany przez UI do komunikatu.
- `final class ConcurrencyConflict extends DomainException` (CODE `concurrency_conflict`), `final class InvalidValue extends DomainException` (CODE `invalid_value`), `final class CurrencyMismatch extends DomainException`.
- `enum Currency: string { case PLN = 'PLN'; case EUR = 'EUR'; }`
- `final readonly class Money { static fromCents(int $cents, Currency $c = Currency::PLN): self; cents(): int; currency(): Currency; equals(Money): bool; differsByMoreThanPercent(Money $other, int $percent): bool; }` — porównanie z inną walutą rzuca `CurrencyMismatch`.
- `final readonly class Reason { static fromString(string): self; value(): string }`, `UserId`, `ModeratorId` (`static fromInt(int)`, `value(): int`, `equals`).

- [ ] **Step 1: Testy VO (failing)**

```php
test('Money odrzuca 0 i ujemne', fn () => expect(fn () => Money::fromCents(0))->toThrow(InvalidValue::class));
test('30% to jeszcze nie „więcej niż 30%”', function () {
    expect(Money::fromCents(10000)->differsByMoreThanPercent(Money::fromCents(13000), 30))->toBeFalse()
        ->and(Money::fromCents(10000)->differsByMoreThanPercent(Money::fromCents(13001), 30))->toBeTrue()
        ->and(Money::fromCents(10000)->differsByMoreThanPercent(Money::fromCents(6999), 30))->toBeTrue();
});
test('porównanie walut PLN i EUR rzuca CurrencyMismatch', ...);
test('Reason przycina białe znaki i odrzuca pusty oraz > 500 znaków', ...);
```

- [ ] **Step 2: Test architektury (failing, bo brak katalogu)**

```php
arch('domena nie zna frameworka ani innych warstw')
    ->expect('SellerPortal\Domain')
    ->not->toUse(['Illuminate', 'App', 'SellerPortal\Application', 'SellerPortal\Infrastructure']);
arch('aplikacja nie zna frameworka ani infrastruktury')
    ->expect('SellerPortal\Application')
    ->not->toUse(['Illuminate', 'App', 'SellerPortal\Infrastructure']);
```

- [ ] **Step 3: `php artisan test --compact tests/Unit/SellerPortal tests/Arch` → FAIL (brak klas)**
- [ ] **Step 4: Implementacja Shared/** (VO `final readonly`, konstruktory prywatne + nazwane fabryki)
- [ ] **Step 5: Testy → PASS; `pint`**
- [ ] **Step 6: Commit** `Portal sprzedawcy: szkielet modułu SellerPortal, Money i test architektury`

---

### Task 2: Agregat Seller

**Files:**
- Create: `src/SellerPortal/Domain/Seller/*`
- Test: `tests/Unit/SellerPortal/Domain/SellerTest.php`

**Interfaces:**
- Consumes: Task 1.
- Produces:
  - `enum SellerStatus: string { Pending='pending'; Active='active'; Rejected='rejected'; Blocked='blocked'; }`
  - `final class Seller extends AggregateRoot`:
    `static register(SellerId, UserId, SellerName, City, DateTimeImmutable $now): self`,
    `static reconstitute(SellerId, ?UserId, SellerName, City, SellerStatus, ?Reason $statusReason, int $version): self`,
    `approve(ModeratorId, $now)`, `reject(ModeratorId, Reason, $now)`, `block(ModeratorId, Reason, $now)`, `unblock(ModeratorId, $now)`,
    `canSell(): bool` (tylko `Active`),
    `draftOffer(OfferId, ProductId, Money, Stock, Condition, ShippingTime, DateTimeImmutable $now): Offer` (Task 3 dostarcza `Offer::draft`; ten test dopisuje Task 3),
    gettery `id() userId() name() city() status() statusReason() version()`.
  - Zdarzenia (`final readonly`, pola publiczne, wszystkie z `SellerId $sellerId` i `DateTimeImmutable $occurredAt`): `SellerRegistered(+UserId)`, `SellerApproved(+ModeratorId)`, `SellerRejected(+ModeratorId, Reason)`, `SellerBlocked(+ModeratorId, Reason)`, `SellerUnblocked(+ModeratorId)`.
  - `InvalidSellerTransition` (CODE `invalid_seller_transition`, `static from(SellerStatus, string $action)`), `SellerCannotSell` (CODE `seller_cannot_sell`), `SellerNotFound`.
  - `interface SellerRepository { nextIdentity(): SellerId; get(SellerId): Seller /* SellerNotFound */; findByUserId(UserId): ?Seller; save(Seller): void /* ConcurrencyConflict */; }`

- [ ] **Step 1: Testy (failing)** — po jednym na przejście i niedozwolone przejście:

```php
test('rejestracja daje Pending i SellerRegistered', ...);           // status Pending, 1 zdarzenie
test('approve z Pending → Active, SellerApproved z moderatorem', ...);
test('reject z Pending → Rejected z powodem; Rejected jest końcowy', ...); // approve po reject rzuca InvalidSellerTransition
test('block tylko z Active; unblock tylko z Blocked', ...);         // block(Pending) rzuca, unblock(Active) rzuca
test('canSell tylko dla Active', ...);                              // Pending/Rejected/Blocked → false
test('reconstitute nie emituje zdarzeń', ...);                      // pendingEvents() === []
```

- [ ] **Step 2: FAIL** → **Step 3: Implementacja** → **Step 4: PASS, pint**
- [ ] **Step 5: Commit** `Portal sprzedawcy: agregat Seller`

---

### Task 3: Agregat Offer — cykl życia, stan, zawieszenie

**Files:**
- Create: `src/SellerPortal/Domain/Offer/*` (bez ceny-strażnika — Task 4)
- Modify: `src/SellerPortal/Domain/Seller/Seller.php` (`draftOffer`)
- Test: `tests/Unit/SellerPortal/Domain/OfferLifecycleTest.php`, `OfferStockTest.php`, dopisać do `SellerTest.php`

**Interfaces:**
- Consumes: Task 1–2.
- Produces:
  - `enum OfferStatus: string { Draft='draft'; Published='published'; Suspended='suspended'; Withdrawn='withdrawn'; }`
  - `enum Condition: string { New='new'; Used='used'; }`, `enum SuspensionKind: string { Moderation='moderation'; SellerBlocked='seller_blocked'; }`
  - `final readonly class SuspensionReason { static byModerator(ModeratorId, Reason): self; static sellerBlocked(): self; kind(): SuspensionKind; note(): ?Reason; moderatorId(): ?ModeratorId; }`
  - `enum Actor { case Seller; case Moderator; }`
  - `enum OfferAction: string { Publish='publish'; ChangePrice='change_price'; CancelPendingPriceChange='cancel_pending_price_change'; ChangeStock='change_stock'; ChangeDetails='change_details'; Withdraw='withdraw'; Suspend='suspend'; Reinstate='reinstate'; ApprovePriceChange='approve_price_change'; RejectPriceChange='reject_price_change'; }`
  - `final class Offer extends AggregateRoot`:
    `static draft(OfferId, SellerId, ProductId, Money, Stock, Condition, ShippingTime, DateTimeImmutable $now): self` (docblock: „wołaj przez `Seller::draftOffer`”; nowy agregat ma `version() === 0` — repozytorium po tym rozpoznaje INSERT, Task 6; tak samo `Seller::register`),
    `static reconstitute(OfferId, SellerId, ProductId, Money $price, Stock, Condition, ShippingTime, OfferStatus, ?SuspensionReason, ?PendingPriceChange, list<DateTimeImmutable> $recentPriceChanges, int $version): self`,
    `publish(Seller $seller, $now)`, `changeStock(Stock, $now)`, `changeDetails(Condition, ShippingTime, $now)`, `suspend(SuspensionReason, $now)`, `reinstate($now)`, `withdraw($now)`,
    `isListed(): bool` (Published && stock>0), `isSoldOut(): bool` (Published && stock==0),
    `/** @return list<OfferAction> */ allowedActionsFor(Actor): array`,
    gettery `id() sellerId() productId() price() stock() condition() shippingTime() status() suspension() pendingPriceChange() recentPriceChanges() version()`.
  - Zdarzenia (`offerId`, `occurredAt` + pola): `OfferDrafted(SellerId, ProductId)`, `OfferPublished`, `OfferStockChanged(Stock $old, Stock $new)`, `OfferSoldOut`, `OfferBackInStock(Stock $new)`, `OfferDetailsChanged(Condition, ShippingTime)`, `OfferSuspended(SuspensionReason)`, `OfferReinstated`, `OfferWithdrawn`.
  - Wyjątki: `InvalidOfferTransition` (CODE `invalid_offer_transition`, `static from(OfferStatus, OfferAction)`), `OfferCannotBePublishedWithoutStock` (CODE `offer_without_stock`), `OfferNotFound`, `LiveOfferAlreadyExists` (CODE `live_offer_exists`).
  - `interface OfferRepository { nextIdentity(): OfferId; get(OfferId): Offer; save(Offer): void; hasLiveOffer(SellerId, ProductId): bool; /** @return list<OfferId> */ idsForSellerPolicy(SellerId, OfferStatus, ?SuspensionKind, int $afterId, int $limit): array; }`

Tabela dozwolonych akcji (`allowedActionsFor`) — jedyne źródło prawdy dla UI, test parametryzowany (dataset) musi ją pokryć 1:1:

| Status | Seller | Moderator |
|---|---|---|
| Draft | Publish (tylko gdy stock>0), ChangePrice, ChangeStock, ChangeDetails, Withdraw | — |
| Published | ChangePrice (gdy brak pending), CancelPendingPriceChange (gdy pending), ChangeStock, ChangeDetails, Withdraw | Suspend, ApprovePriceChange+RejectPriceChange (gdy pending) |
| Suspended | ChangeStock, Withdraw, CancelPendingPriceChange (gdy pending) | Reinstate, ApprovePriceChange+RejectPriceChange (gdy pending) |
| Withdrawn | — | — |

(ChangePrice w Draft zawsze; pending w Draft nie powstaje — Task 4.)

- [ ] **Step 1: Testy (failing)**

```php
test('Seller::draftOffer tworzy Draft i OfferDrafted', ...);
test('Seller nieaktywny nie wystawi oferty', ...);                     // Pending → SellerCannotSell
test('publish: Draft → Published, OfferPublished', ...);
test('publish bez stanu rzuca OfferCannotBePublishedWithoutStock', ...);
test('publish, gdy sprzedawca zablokowany, rzuca SellerCannotSell', ...);
test('stan >0→0 na opublikowanej daje OfferSoldOut i isSoldOut()', ...);   // isListed false
test('stan 0→5 na opublikowanej daje OfferBackInStock', ...);
test('stan 3→7 daje OfferStockChanged(old 3, new 7)', ...);
test('zmiana stanu w Draft daje OfferStockChanged, nigdy SoldOut', ...);
test('suspend tylko z Published; reinstate tylko z Suspended', ...);
test('withdraw z każdego stanu poza Withdrawn; Withdrawn jest końcowy', ...); // changeStock po withdraw rzuca
test('suspend zapamiętuje rodzaj powodu', ...);                           // sellerBlocked()->kind() === SellerBlocked
test('allowedActionsFor zgodne z tabelą', ...)->with([...]);             // dataset: status × actor × pending? × stock
```

- [ ] **Step 2: FAIL** → **Step 3: Implementacja** → **Step 4: PASS, pint**
- [ ] **Step 5: Commit** `Portal sprzedawcy: agregat Offer — cykl życia i stan magazynowy`

---

### Task 4: Strażnik ceny

**Files:**
- Create: `Domain/Offer/{PriceChangePolicy, PriceChangeDecision, PendingPriceChange}.php`, zdarzenia cenowe, wyjątki cenowe
- Modify: `Domain/Offer/Offer.php`
- Test: `tests/Unit/SellerPortal/Domain/PriceChangePolicyTest.php`, `OfferPriceTest.php`

**Interfaces:**
- Produces:
  - `enum PriceChangeDecision { case AppliedNow; case AwaitingApproval; }`
  - `final class PriceChangePolicy { public const int THRESHOLD_PERCENT = 30; public const int MAX_CHANGES_PER_DAY = 5; static decide(Money $current, Money $proposed, list<DateTimeImmutable> $recent, DateTimeImmutable $now): PriceChangeDecision /* PriceChangeLimitExceeded */; static changesLeft(list<DateTimeImmutable> $recent, DateTimeImmutable $now): int; /** @return list<DateTimeImmutable> */ static withinWindow(array $recent, DateTimeImmutable $now): array; }` — okno: `changedAt > now − 24h`.
  - `final readonly class PendingPriceChange { __construct(Money $price, DateTimeImmutable $requestedAt) }`
  - `Offer::changePrice(Money, $now): PriceChangeDecision`, `cancelPendingPriceChange($now)`, `approvePriceChange(ModeratorId, $now)`, `rejectPriceChange(ModeratorId, Reason, $now)`.
  - Zdarzenia: `OfferPriceChanged(Money $old, Money $new)`, `OfferPriceChangeRequested(Money $current, Money $proposed)`, `OfferPriceChangeApproved(Money $old, Money $new, ModeratorId)`, `OfferPriceChangeRejected(Money $proposed, ModeratorId, Reason)`, `OfferPriceChangeCancelled(Money $proposed)`.
  - Wyjątki: `PriceChangeAlreadyPending` (CODE `price_change_pending`), `NoPendingPriceChange` (CODE `no_pending_price_change`), `PriceChangeLimitExceeded` (CODE `price_change_limit`).

Reguły (z `Global Constraints`): w `Draft` cena zmienia się od razu, bez limitu i bez zapisu do `recentPriceChanges`; w `Published` — pending blokuje, potem limit, potem próg; zarówno `AppliedNow`, jak i `AwaitingApproval` dopisują `$now` do `recentPriceChanges`; akceptacja moderatora nie dopisuje; `withdraw()` czyści pending (bez zdarzenia anulowania — `OfferWithdrawn` wystarcza).

- [ ] **Step 1: Testy (failing)**

```php
test('zmiana o 30% wchodzi od razu, o 30,01% czeka', ...);   // 10000→13000 AppliedNow; 10000→13001 AwaitingApproval, price() nadal 10000
test('szósta zmiana w 24 h rzuca PriceChangeLimitExceeded', ...);  // 5 zmian o 1% co godzinę, szósta rzuca
test('zmiana sprzed 24 h i 1 s nie liczy się do limitu', ...);
test('zgłoszenie do akceptacji liczy się do limitu', ...);
test('przy oczekującej zmianie kolejna rzuca PriceChangeAlreadyPending', ...);
test('approve ustawia nową cenę i nie dopisuje do limitu', ...);
test('reject zostawia starą cenę, czyści pending', ...);
test('cancel tylko gdy pending, inaczej NoPendingPriceChange', ...);
test('w Draft zmiana o 90% wchodzi od razu i nie liczy się do limitu', ...);
test('zmiana ceny w Suspended rzuca InvalidOfferTransition', ...);
test('zmiana waluty rzuca CurrencyMismatch', ...);
test('changesLeft = 5 − zmiany w oknie, min 0', ...);
```

- [ ] **Step 2: FAIL** → **Step 3: Implementacja** → **Step 4: PASS (cały `tests/Unit/SellerPortal` < 1 s), pint**
- [ ] **Step 5: Commit** `Portal sprzedawcy: strażnik ceny (30%, 5 zmian na dobę)`

---

### Task 5: Migracje, model odczytu, usunięcie starych ścieżek zapisu

**Files:**
- Create: `database/migrations/2026_10_15_100000_add_seller_portal_columns_to_sellers_table.php`, `…100001_add_status_to_offers_table.php`, `…100002_create_offer_price_changes_table.php`
- Modify: `app/Models/Offer.php` (usuń `updateWithOutbox`, `deactivateWithOutbox`, `active` z fillable/casts; dodaj `scopeListed`), `app/Models/Product.php` (`activeOffers()` → `listedOffers()` = `offers()->listed()`), `app/Models/Seller.php` (fillable/casts nowych kolumn), `app/Http/Controllers/Internal/ProductProjectionController.php` (`listedOffers`), `app/Http/Controllers/Panel/ProductController.php` (`listed` w withCount/withMin; w `edit` pole `status` zamiast `active`, plus `seller_status`), `app/Services/Panel/EventTrace.php` (`listedOffers`), `app/Console/Commands/SeedMarketplaceCommand.php` (insert `status => 'published'`, sprzedawcy `status => 'active'`), `database/factories/OfferFactory.php` (`status => 'published'`, stany `draft()`, `withdrawn()`, `soldOut()`), `database/factories/SellerFactory.php` (`status => 'active'`, stany `pending()`, `blocked()`, `forUser(User)`), `routes/web.php` (usuń 2 trasy `panel.offers.*`), `resources/js/components/panel/OfferRow.vue` (tylko odczyt: status, cena, stan — akcje moderacji w Task 11)
- Delete: `app/Http/Controllers/Panel/OfferController.php`, `app/Http/Requests/Panel/UpdateOfferRequest.php`, `tests/Feature/Panel/OfferUpdateTest.php` (zgoda w specu)
- Modify tests: `tests/Feature/OutboxEventsTest.php` (testy „dwa zdarzenia naraz” i „sequence rośnie” na `Seller::updateWithOutbox` z mapą `['name' => 'seller.renamed', 'city' => 'seller.relocated']` — tylko w teście; test `offer.deactivated` usunięty), test „oferty posortowane po id” przenieść do `tests/Feature/Panel/ProductEditTest.php`; `ProductIndexTest`, `ProductProjectionTest`, `EventTraceTest` — `active` → `status`.
- Test: `tests/Feature/SellerPortal/Infrastructure/OfferReadModelTest.php`

Schemat:
- `sellers`: `user_id` foreignId nullable unique → users nullOnDelete; `status` string(16) default `'active'` + CHECK in (pending,active,rejected,blocked); `status_reason` text null; `status_changed_at` timestampTz null.
- `offers`: `status` string(16) not null default `'draft'` + CHECK; dane: `UPDATE offers SET status = CASE WHEN active THEN 'published' ELSE 'withdrawn' END`; potem `dropColumn('active')` i stary indeks `['product_id','active']`; `suspension_kind` string(16) null; `suspension_note` text null; `suspended_by` unsignedBigInteger null; `pending_price_cents` unsignedBigInteger null; `pending_requested_at` timestampTz null; indeks `['product_id','status','stock']`; częściowy unikalny: `DB::statement('CREATE UNIQUE INDEX offers_one_live_per_seller_product ON offers (seller_id, product_id) WHERE status <> \'withdrawn\'')` (działa w Postgresie i SQLite).
- `offer_price_changes`: `id`, `offer_id` FK cascade, `changed_at` timestampTz, indeks `['offer_id','changed_at']`.
- `down()` odwraca (przywraca `active` z `status = 'published'`).

- [ ] **Step 1: Testy modelu odczytu (failing)**

```php
test('listed() = published i stock > 0', ...);  // factory: published/5 ✓, published/0 ✗, draft/5 ✗, suspended/5 ✗, withdrawn/5 ✗
test('projekcja pomija ofertę wyprzedaną i zawieszoną', ...); // GET /internal/products/{id}/projection → offers zawiera tylko listed
test('produkt bez widocznych ofert: offers [], price_min null, in_stock false', ...);
test('indeks nie pozwala na drugą żywą ofertę sprzedawcy na produkt, wycofana nie blokuje', ...); // QueryException przy 2× published; OK przy withdrawn + published
```

- [ ] **Step 2: FAIL** → **Step 3: Migracje + zmiany listy plików**
- [ ] **Step 4: `grep -rn "active" app routes resources/js/pages resources/js/components/panel database tests | grep -i offer` → brak trafień dotyczących `offers.active`**
- [ ] **Step 5: `php artisan test --compact` (cały zestaw) → PASS; wayfinder `--with-form`; `npm run types:check && npm run lint:check`; pint**
- [ ] **Step 6: Commit** `Portal sprzedawcy: status oferty zamiast active, model odczytu listed(), koniec edycji ofert przez admina`

---

### Task 6: Repozytoria SQL i translacja zdarzeń do outboxu

**Files:**
- Create: `Infrastructure/Persistence/{SqlSellerRepository, SqlOfferRepository, OfferIntegrationEvents, NextId}.php`
- Test: `tests/Feature/SellerPortal/Infrastructure/{SqlSellerRepositoryTest, SqlOfferRepositoryTest, OfferIntegrationEventsTest}.php`

**Interfaces:**
- Consumes: interfejsy repozytoriów z Task 2–3, tabele z Task 5.
- Produces:
  - `NextId::for(string $table): int` — `pgsql`: `SELECT nextval('{table}_id_seq')`; inne sterowniki: `COALESCE(MAX(id),0)+1` (docblock: tylko testy SQLite, jednowątkowe).
  - `SqlOfferRepository implements OfferRepository`; `save()`: w `DB::transaction` — INSERT gdy `version === 0` (nowy agregat ma `version 0` przed pierwszym zapisem), inaczej `UPDATE … WHERE id=? AND version=?` (0 wierszy → `ConcurrencyConflict`); zapis `version+1`; wstawia nowe wiersze `offer_price_changes` (te z `recentPriceChanges()` późniejsze niż najnowszy zapisany); `QueryException` z naruszenia `offers_one_live_per_seller_product` → `LiveOfferAlreadyExists`; potem `OfferIntegrationEvents::record(Offer $offer, list<DomainEvent> $events, int $sequence)` dla `pendingEvents()`. Ładowanie: `offer_price_changes` tylko z ostatnich 24 h względem `Clock`.
  - `SqlSellerRepository implements SellerRepository`; INSERT generuje `slug = Str::slug(name).'-'.id`, ustawia `rating 0`, `lat/lon null`; `status_changed_at` = `occurredAt` ostatniego zdarzenia. Bez outboxu.
  - `OfferIntegrationEvents::record(...)`: mapa klasa → `event_type` (Global Constraints); wiersz `outbox`: `event_id` ULID, `aggregate_type 'offer'`, `aggregate_id`, `sequence` = nowa wersja, `occurred_at` ze zdarzenia, `payload` = `['id','product_id','seller_id','price_cents','currency','stock','condition','shipping_days','status','listed','version']` + pola zdarzenia: price_changed/approved `old_price_cents,new_price_cents`; requested `proposed_price_cents`; rejected `proposed_price_cents,reason`; cancelled `proposed_price_cents`; stock_changed `old_stock,new_stock`; suspended `suspension_kind,suspension_note`.

- [ ] **Step 1: Testy (failing)**

```php
test('zapis i odczyt oferty zachowuje wszystkie pola', ...);   // w tym suspension, pending, recentPriceChanges, version 1
test('drugi zapis tej samej wersji rzuca ConcurrencyConflict', ...);  // dwa get(), oba changeStock, drugi save rzuca
test('druga żywa oferta → LiveOfferAlreadyExists, nie QueryException', ...);
test('hasLiveOffer ignoruje wycofane', ...);
test('zmiana ceny i stanu w jednym zapisie: dwa wiersze outboxu z tym samym sequence', ...);
test('payload offer.price_changed ma product_id, old/new_price_cents i listed', ...);
test('zapis sprzedawcy nie tworzy wiersza outboxu', ...);
test('slug sprzedawcy = slug nazwy + id', ...);
test('idsForSellerPolicy stronicuje po id i filtruje rodzaj zawieszenia', ...);
```

- [ ] **Step 2: FAIL** → **Step 3: Implementacja** → **Step 4: PASS, pint**
- [ ] **Step 5: Commit** `Portal sprzedawcy: repozytoria SQL, optymistyczne blokowanie, zdarzenia offer.* do outboxu`

---

### Task 7: Warstwa aplikacji — komendy, handlery, porty

**Files:**
- Create: `Application/**` (bez Policy — Task 8), `Infrastructure/{LaravelTransactionManager, InProcessDomainEventDispatcher, DbProductCatalog, SystemClock}.php`, `app/Providers/SellerPortalServiceProvider.php` (rejestracja w `bootstrap/providers.php`)
- Test: `tests/Unit/SellerPortal/InMemory/{InMemoryOfferRepository, InMemorySellerRepository, FixedClock, ImmediateTransactionManager, RecordingDispatcher, FakeProductCatalog}.php`, `tests/Unit/SellerPortal/Application/{SellerHandlersTest, OfferHandlersTest, ModerationHandlersTest}.php`

**Interfaces:**
- Produces:
  - Porty: `TransactionManager::run(callable $work): mixed`; `DomainEventDispatcher::dispatch(list<DomainEvent>): void`; `ProductCatalog::exists(ProductId): bool`; `Clock::now(): DateTimeImmutable`.
  - Komendy (`final readonly`, prymitywy): `RegisterSeller(int $userId, string $name, string $city)`, `ApproveSeller(int $sellerId, int $moderatorId)`, `RejectSeller(int $sellerId, int $moderatorId, string $reason)`, `BlockSeller(int, int, string)`, `UnblockSeller(int, int)`, `DraftOffer(int $userId, int $productId, int $priceCents, int $stock, string $condition, int $shippingDays)`, `PublishOffer(int $userId, int $offerId)`, `ChangeOfferPrice(int $userId, int $offerId, int $priceCents)`, `CancelPendingPriceChange(int $userId, int $offerId)`, `ChangeOfferStock(int $userId, int $offerId, int $stock)`, `ChangeOfferDetails(int $userId, int $offerId, string $condition, int $shippingDays)`, `WithdrawOffer(int $userId, int $offerId)`, `SuspendOffer(int $moderatorId, int $offerId, string $note)`, `ReinstateOffer(int $moderatorId, int $offerId)`, `ApprovePriceChange(int $moderatorId, int $offerId)`, `RejectPriceChange(int $moderatorId, int $offerId, string $reason)`.
  - Handler na komendę: `{Command}Handler::handle({Command}): T` — `RegisterSellerHandler` → `SellerId`, `DraftOfferHandler` → `OfferId`, `ChangeOfferPriceHandler` → `PriceChangeDecision`, reszta `void`. Schemat: `transactions->run(fn: wczytaj → metoda agregatu(now) → repo->save → dispatcher->dispatch($agg->releaseEvents()))`. Zero `if` z regułami biznesowymi.
  - `SellerOfferAccess` (serwis aplikacji):
    - `forUser(UserId, OfferId): array{0: Seller, 1: Offer}` — brak sprzedawcy dla usera → `SellerNotFound`; oferta innego sprzedawcy → `OfferNotOwned` (CODE `offer_not_owned`, UI → 404).
    - `forActiveSeller(UserId, OfferId): array{0: Seller, 1: Offer}` — jak wyżej, plus `!$seller->canSell()` → `SellerCannotSell`.
    - Wszystkie komendy sprzedawcy na ofercie używają `forActiveSeller`, z wyjątkiem `WithdrawOffer` (`forUser`): zablokowany sprzedawca może wycofać ofertę, ale nic poza tym. Reguła „aktywny sprzedawca” jest regułą dostępu aktora, nie stanu oferty, dlatego siedzi w warstwie aplikacji, a nie w każdej metodzie `Offer` (wyjątek: `publish(Seller)`, bo spec czyni ją warunkiem publikacji).
  - `RegisterSellerHandler`: `findByUserId` ≠ null → `SellerAlreadyRegistered` (CODE `seller_already_registered`). `DraftOfferHandler`: `ProductCatalog::exists` false → `ProductNotFound`; `hasLiveOffer` → `LiveOfferAlreadyExists`.
  - `SellerPortalServiceProvider`: `bind` (nie `singleton` — Octane) interfejsów do implementacji Sql*/Laravel*/System*/Db*.

- [ ] **Step 1: Testy na in-memory (failing)**

```php
test('rejestracja drugi raz dla tego samego usera rzuca SellerAlreadyRegistered', ...);
test('szkic na nieistniejący produkt rzuca ProductNotFound', ...);
test('szkic gdy sprzedawca ma żywą ofertę na produkt rzuca LiveOfferAlreadyExists', ...);
test('cudza oferta rzuca OfferNotOwned', ...);
test('zablokowany sprzedawca może wycofać ofertę, ale nie zmienić ceny', ...);
test('handler dispatchuje zdarzenia po zapisie i czyści agregat', ...); // RecordingDispatcher ma OfferPublished; pendingEvents() pusty
test('ChangeOfferPrice zwraca AwaitingApproval dla +50%', ...);
```

- [ ] **Step 2: FAIL** → **Step 3: Implementacja** → **Step 4: PASS + test architektury PASS, pint**
- [ ] **Step 5: Commit** `Portal sprzedawcy: komendy i handlery warstwy aplikacji`

---

### Task 8: Polityki blokady sprzedawcy, worker kolejki

**Files:**
- Create: `Application/Port/PolicyJobQueue.php`, `Application/Policy/*`, `Infrastructure/LaravelPolicyJobQueue.php`, `Infrastructure/Job/*`
- Modify: `SellerPortalServiceProvider` (rejestracja listenerów w dispatcherze), `compose.yaml` (usługa `catalog-worker`), `compose.prod.yaml`, `deploy/k8s/catalog.yaml` (Deployment `catalog-worker`), `Makefile` (cel `worker-logs`), `docs/RUNBOOK.md`
- Test: `tests/Unit/SellerPortal/Application/SellerPoliciesTest.php`, `tests/Feature/SellerPortal/Infrastructure/SellerPolicyJobsTest.php`

**Interfaces:**
- Produces:
  - `PolicyJobQueue { suspendOffersOf(SellerId): void; reinstateOffersOf(SellerId): void; }`
  - Listenery: `QueueSuspensionWhenSellerBlocked::__invoke(SellerBlocked)`, `QueueReinstatementWhenSellerUnblocked::__invoke(SellerUnblocked)` — tylko kolejkują.
  - `SuspendOffersOfBlockedSeller::run(SellerId): int` (liczba zawieszonych) — jeśli sprzedawca ≠ `Blocked` → 0; paczki po 500 przez `idsForSellerPolicy(seller, Published, null, afterId, 500)`; każda oferta w osobnej transakcji, `suspend(SuspensionReason::sellerBlocked())`; `ConcurrencyConflict` → do 3 prób na ofertę; oferta już nie-`Published` → pomiń.
  - `ReinstateOffersOfUnblockedSeller::run(SellerId): int` — symetrycznie: sprzedawca `Active`, oferty `Suspended` z `SuspensionKind::SellerBlocked`.
  - Joby: `ShouldQueue`, `$tries = 5`, `$backoff = [5, 30, 120]`, `handle()` woła serwis polityki; `LaravelPolicyJobQueue` używa `dispatch()` na połączeniu `database` (tabela `jobs` w tej samej bazie i transakcji — upewnij się, że `config/queue.php` `database.after_commit` = `false`).
  - `catalog-worker` (compose): wzorzec `outbox-publisher`, `command: ["php","artisan","queue:work","database","--sleep=1","--tries=5","--max-time=3600"]`, healthcheck `grep -q 'queue:work' /proc/1/cmdline`, `mem_limit: 256m`, bez RabbitMQ w env.

- [ ] **Step 1: Testy (failing)**

```php
test('blokada kolejkuje job w tej samej transakcji', ...);        // Queue::fake(); BlockSellerHandler; Queue::assertPushed(SuspendOffersOfBlockedSellerJob)
test('wyjątek po blokadzie wycofuje i blokadę, i job', ...);      // QUEUE_CONNECTION=database w teście; rzut w dispatcherze → status Active, jobs pusty
test('job zawiesza tylko opublikowane oferty sprzedawcy', ...);    // draft i withdrawn bez zmian; outbox ma offer.suspended z suspension_kind seller_blocked
test('odblokowanie przywraca tylko zawieszone przez blokadę', ...); // oferta zawieszona przez moderatora zostaje Suspended
test('job po szybkim odblokowaniu nic nie robi', ...);             // block → unblock → uruchom suspend job → 0 zmian
test('ponowne uruchomienie joba jest idempotentne', ...);          // drugi run → 0, brak nowych wierszy outboxu
```

- [ ] **Step 2: FAIL** → **Step 3: Implementacja + infra**
- [ ] **Step 4: PASS; `docker compose --profile apps config -q` bez błędów; `kubectl kustomize deploy/k8s >/dev/null` bez błędów; pint**
- [ ] **Step 5: `make up` (lub odpowiednik z Makefile) → `docker compose ps catalog-worker` = healthy** (nie zatrzymywać cudzych kontenerów — maszyna współdzielona)
- [ ] **Step 6: Commit** `Portal sprzedawcy: polityki blokady sprzedawcy i catalog-worker`

---

### Task 9: Onboarding sprzedawcy (HTTP + frontend sprzedawcy i admina)

**Files:**
- Create: `app/Http/Middleware/{EnsureHasSeller, EnsureSellerApproved}.php` (aliasy `seller`, `seller.approved` w `bootstrap/app.php`), `app/Http/DomainErrorMessages.php`, `app/Http/Controllers/Seller/SellerApplicationController.php`, `app/Http/Controllers/Panel/Moderation/SellerModerationController.php`, `Infrastructure/ReadModel/{PendingSellers, SellerDirectory}.php`, `resources/js/pages/seller/{Apply,Status}.vue`, `resources/js/pages/panel/sellers/Index.vue`, `resources/js/components/seller/BlockedBanner.vue`, `resources/js/types/seller.ts`
- Modify: `bootstrap/app.php` (render `SellerPortal\Domain\Shared\DomainException` dla żądań Inertia → `back()->withErrors(['domain' => DomainErrorMessages::for($e)])`; `OfferNotOwned`, `OfferNotFound`, `SellerNotFound` → 404), `app/Http/Middleware/HandleInertiaRequests.php` (shared `auth.seller: {id, name, status, status_reason} | null` — closure, jedno zapytanie), `routes/web.php`, `resources/js/components/AppSidebar.vue` (pozycja „Portal sprzedawcy” → `/seller` gdy `auth.seller`, „Zostań sprzedawcą” → `/seller/apply` gdy brak; admin: „Sprzedawcy”), `resources/js/pages/Dashboard.vue` (karta CTA)
- Test: `tests/Feature/SellerPortal/Http/SellerOnboardingTest.php`

Trasy:
- `GET/POST seller/apply` (`auth`, `verified`) → `seller.apply`, `seller.apply.store`; z istniejącym sprzedawcą GET przekierowuje na `seller.status`.
- `GET seller/status` (`auth`, `seller`) → `seller.status`.
- `GET panel/sellers` → `panel.sellers.index` (props: `pending`, `sellers` paginowane po 25, filtr `status`), `POST panel/sellers/{seller}/{approve|reject|block|unblock}` → `panel.sellers.approve|reject|block|unblock` (reject/block wymagają `reason`).
- `DomainErrorMessages::for(DomainException): string` — mapa CODE → polski komunikat; brak w mapie → „Operacja niedozwolona w obecnym stanie”. Komunikaty: `price_change_limit` „Wykorzystano limit 5 zmian ceny na dobę”, `price_change_pending` „Ta oferta czeka na decyzję moderatora — anuluj zgłoszenie, aby zmienić cenę”, `concurrency_conflict` „Ktoś w międzyczasie zmienił tę ofertę — odśwież stronę”, `live_offer_exists` „Masz już aktywną ofertę na ten produkt”, `offer_without_stock` „Nie można opublikować oferty bez towaru”, `seller_cannot_sell` „Twoje konto sprzedawcy nie jest aktywne”, `seller_already_registered` „To konto ma już profil sprzedawcy”.

- [ ] **Step 1: Testy HTTP (failing)**

```php
test('zalogowany bez sprzedawcy składa zgłoszenie i widzi status oczekuje', ...); // POST seller/apply → redirect seller.status; sellers.status pending, user_id
test('nazwa krótsza niż 3 znaki → błąd walidacji name', ...);
test('drugie zgłoszenie → przekierowanie na status, bez nowego wiersza', ...);
test('admin widzi oczekujących i akceptuje', ...);                   // POST panel/sellers/{id}/approve → status active
test('odrzucenie bez powodu → błąd reason', ...);
test('nie-admin dostaje 403 na panel/sellers', ...);
test('akceptacja odrzuconego → błąd domain „Operacja niedozwolona…”', ...);
test('shared auth.seller zawiera status', ...);                       // Inertia assert has auth.seller.status
```

- [ ] **Step 2: FAIL** → **Step 3: Backend** → **Step 4: PASS**
- [ ] **Step 5: Frontend** — strony z istniejących komponentów (`Card`, `Input`, `Label`, `Button`, `Badge`, `Dialog` na powód); `useForm`; Wayfinder `--with-form`; `npm run lint:check && npm run format:check && npm run types:check` → bez błędów
- [ ] **Step 6: Sprawdzenie w przeglądarce w aplikacji** (`/register` → `/seller/apply` → status; admin akceptuje w `/panel/sellers`) — zrzut ekranu każdej strony; brak błędów w konsoli
- [ ] **Step 7: Commit** `Portal sprzedawcy: rejestracja sprzedawcy i akceptacja w panelu`

---

### Task 10: Oferty sprzedawcy (HTTP + frontend)

**Files:**
- Create: `app/Http/Controllers/Seller/{SellerDashboardController, SellerOfferController, ProductLookupController}.php`, `app/Http/Requests/Seller/{StoreOfferRequest, UpdateOfferPriceRequest, UpdateOfferStockRequest, UpdateOfferDetailsRequest}.php`, `Infrastructure/ReadModel/{SellerOfferList, SellerOfferDetails, SellerDashboardStats, ProductLookup}.php`, `resources/js/pages/seller/Dashboard.vue`, `resources/js/pages/seller/offers/{Index,Create,Show}.vue`, `resources/js/components/seller/{OfferStatusBadge,PriceChangeForm,OfferTimeline}.vue`
- Test: `tests/Feature/SellerPortal/Http/{SellerOfferTest, PriceGuardTest}.php`

Trasy (`auth`, `seller`, `seller.approved`, prefiks `seller`, nazwy `seller.*`):
`GET /` → `seller.dashboard`; `GET offers` → `seller.offers.index` (filtr `status` ∈ draft|published|sold_out|suspended|withdrawn|pending_price); `GET offers/create`; `POST offers`; `GET offers/{offer}` → `seller.offers.show`; `POST offers/{offer}/publish`; `PUT offers/{offer}/price`; `DELETE offers/{offer}/pending-price`; `PUT offers/{offer}/stock`; `PUT offers/{offer}/details`; `POST offers/{offer}/withdraw`; `GET products/lookup?q=` → JSON max 10 (`id, name, brand, ean`), `q` ≥ 2 znaki, liczba 8–13 cyfr szuka po EAN, inaczej `ILIKE`/`LIKE` po nazwie (pomija produkty, na które sprzedawca ma żywą ofertę).

Kontrakt props `seller/offers/Show`: `offer: {id, product: {id, name}, price_cents, currency, stock, condition, shipping_days, status, is_sold_out, is_listed, suspension: {kind, note} | null, pending_price: {price_cents, requested_at} | null, version}`, `allowed_actions: OfferAction[]` (z `Offer::allowedActionsFor(Actor::Seller)` — kontroler wczytuje agregat przez repozytorium wyłącznie po to), `price_guard: {threshold_percent: 30, changes_left_today: int}`, `timeline` — 20 ostatnich wierszy outboxu oferty (`type, occurred_at, published`), partial reload co 2 s gdy ostatnie nieopublikowane.

Cena z formularza: ta sama normalizacja co dotychczasowy `UpdateOfferRequest` (usunięty w Task 5 — odtwórz zachowanie: spacje usunięte, przecinek → kropka, `> 0`, max 2 miejsca, na grosze `(int) round(x*100)`).
Flash po `PUT price`: `AppliedNow` → „Cena zmieniona”, `AwaitingApproval` → „Zmiana o ponad 30% czeka na akceptację moderatora”.

`PriceChangeForm.vue` liczy na żywo `|nowa − obecna| × 100 > obecna × threshold` i pokazuje „trafi do akceptacji” oraz „pozostało N zmian dziś”; przycisk wyłączony, gdy `changes_left_today === 0` — ostateczna decyzja i tak po stronie serwera.

- [ ] **Step 1: Testy HTTP (failing)**

```php
test('sprzedawca tworzy szkic, publikuje, oferta jest listed', ...);
test('cena „1 299,99” zapisuje 129999 groszy', ...);
test('oferta na produkt z żywą ofertą → błąd domain', ...);
test('cudza oferta → 404 i brak zmian', ...);
test('pending/rejected sprzedawca jest przekierowany na status', ...);
test('zablokowany widzi oferty i baner, zmiana ceny → błąd domain, wycofanie działa', ...);
test('allowed_actions dla opublikowanej bez pending', ...);   // ['change_price','change_stock','change_details','withdraw'] (kolejność z enumu)
test('lookup po EAN i po nazwie, max 10, bez produktów z własną żywą ofertą', ...);
// PriceGuardTest:
test('+50% → flash o akceptacji, cena w projekcji bez zmian', ...);
test('szósta zmiana w dobie → błąd „Wykorzystano limit 5 zmian ceny na dobę”', ...);  // Carbon::setTestNow; SystemClock używa now()
test('równoległa edycja → błąd concurrency', ...);              // w teście bind OfferRepository → dekorator SqlOfferRepository, którego get() po wczytaniu robi DB::table('offers')->where('id', $id)->increment('version'); PUT stock → błąd „Ktoś w międzyczasie…”, stan w bazie bez zmian
```

- [ ] **Step 2: FAIL** → **Step 3: Backend** → **Step 4: PASS**
- [ ] **Step 5: Frontend** + wayfinder + `lint:check`, `format:check`, `types:check`
- [ ] **Step 6: Przeglądarka:** pełna ścieżka z kryterium 1 i 2 specu na żywym stacku (szkic → publikacja → `/search` pokazuje ofertę w ciągu kilku sekund; +50% → `/search` dalej stara cena); zrzuty ekranu
- [ ] **Step 7: Commit** `Portal sprzedawcy: oferty sprzedawcy — szkic, publikacja, cena, stan, wycofanie`

---

### Task 11: Moderacja (HTTP + frontend)

**Files:**
- Create: `app/Http/Controllers/Panel/Moderation/{PriceChangeModerationController, OfferModerationController}.php`, `Infrastructure/ReadModel/PriceChangeQueue.php`, `resources/js/pages/panel/moderation/PriceChanges.vue`
- Modify: `routes/web.php`, `app/Http/Controllers/Panel/ProductController.php` (`edit`: oferty z `status`, `seller`, `suspension`, `allowed_actions` dla `Actor::Moderator`), `resources/js/components/panel/OfferRow.vue` (zawieś z powodem w `Dialog` / przywróć), `AppSidebar.vue` (admin: „Zmiany cen” z licznikiem z shared prop `moderation.pending_price_changes` — tylko dla admina)
- Test: `tests/Feature/SellerPortal/Http/{ModerationTest, SellerBlockingTest}.php`

Trasy (`auth`, `can:panel`): `GET panel/moderation/price-changes` → `panel.moderation.price-changes` (props: `items: {offer_id, product, seller, current_price_cents, proposed_price_cents, change_percent, requested_at}[]`, najstarsze pierwsze); `POST panel/offers/{offer}/price-change/approve|reject`; `POST panel/offers/{offer}/suspend` (`note` wymagany), `POST panel/offers/{offer}/reinstate`.

- [ ] **Step 1: Testy (failing)**

```php
test('kolejka pokazuje zgłoszenie z różnicą w %', ...);        // 10000→15000 → change_percent 50
test('akceptacja zmienia cenę i daje offer.price_change_approved', ...);
test('odrzucenie bez powodu → błąd reason', ...);
test('zawieszenie znika ofertę z projekcji, przywrócenie wraca', ...);
test('sprzedawca nie może przywrócić zawieszonej (brak trasy/akcji)', ...); // allowed_actions sprzedawcy bez 'reinstate'
// SellerBlockingTest (QUEUE_CONNECTION=sync):
test('blokada sprzedawcy zawiesza jego opublikowane oferty', ...);
test('odblokowanie przywraca tylko zawieszone przez blokadę', ...);
```

- [ ] **Step 2: FAIL** → **Step 3: Backend** → **Step 4: PASS**
- [ ] **Step 5: Frontend** + lint/format/types
- [ ] **Step 6: Przeglądarka:** kryteria 3 i 4 specu na żywym stacku z `catalog-worker` (stan 0 → oferta znika z `/search`; blokada → wszystkie oferty znikają po kilku sekundach; odblokowanie → wracają tylko te z blokady); zrzuty
- [ ] **Step 7: Commit** `Portal sprzedawcy: moderacja cen, zawieszanie ofert, blokada sprzedawców`

---

### Task 12: Weryfikacja end-to-end i pomiary

**Files:**
- Modify: `docs/POMIARY.md`, `README.md` (tabela statusu etapów)

- [ ] **Step 1:** `php artisan test --compact` (cały zestaw) → PASS; zanotuj czas `tests/Unit/SellerPortal` (kryterium 6: < 1 s)
- [ ] **Step 2:** `make eval` przed (z `main` sprzed Task 5, jeśli dostępny zapis w POMIARY) i po → wpis w `docs/POMIARY.md`: nDCG@10, liczba ofert odfiltrowanych przez `listed()`, wyjaśnienie różnicy
- [ ] **Step 3:** `make smoke` (`tools/smoke-test.sh`) → PASS
- [ ] **Step 4:** Wdrożenie na k3s zgodnie z `docs/blog/etap-d2-k3s.md` (`make k3s-deploy`) → `catalog-worker` Running; migracje przeszły; kryterium 1 na VPS przez tunel SSH (`ssh elastic-vps`)
- [ ] **Step 5: Commit** `Portal sprzedawcy: pomiary po wdrożeniu`

---

### Task 13: Dokumentacja nauki i przewodnik

**Files:**
- Create: `docs/10-DDD.md`, `docs/11-SELLER-PORTAL-SLOWNIK.md`, `docs/adr/0001-modularny-monolit.md`, `docs/adr/0002-czysta-domena-bez-eloquenta.md`, `docs/adr/0003-zdarzenia-sprzedawcy-wewnetrzne.md`, `docs/adr/0004-wyprzedana-jako-stan-pochodny.md`, `docs/blog/etap-e-portal-sprzedawcy-ddd.md`
- Modify: `docs/RUNBOOK.md` (worker, job polityki, konflikt wersji — jeśli nie w Task 8), `README.md`

Treść:
- `10-DDD.md` — format `03-SCIEZKA-NAUKI.md` (Cel / Teoria / W projekcie / Ćwiczenia z co najmniej jednym „zepsuj i napraw” / Sprawdź się). Moduły: 1 Język wszechobecny i Event Storming (na portalu), 2 Poddomeny i bounded contexts, 3 Mapa kontekstów (na tym repo: Conformist, OHS, ACL, Published Language), 4 Value objects, 5 Encje i agregaty (granice, jedna transakcja = jeden agregat), 6 Zdarzenia domenowe vs integracyjne, 7 Repozytoria i persystencja (mapowanie, optymistyczne blokowanie), 8 Warstwa aplikacji i polityki (eventual consistency), 9 Reguły na zbiorach (unikalność), 10 Kiedy NIE stosować DDD. Każdy „W projekcie” wskazuje plik:linia. Przykład „zepsuj i napraw”: usuń sprawdzenie wersji w `SqlOfferRepository::save` i odtwórz utracony zapis dwiema kartami.
- ADR-y w formacie: Kontekst / Decyzja / Rozważane opcje / Konsekwencje.
- Przewodnik — krok po kroku jak zbudowano etap (kolejność Task 1–12), z komendami do odtworzenia i tym, co się zepsuło po drodze.

- [ ] **Step 1:** Napisz dokumenty; wszystkie ścieżki `plik:linia` istnieją (`grep` każdej)
- [ ] **Step 2: Commit** `Portal sprzedawcy: moduł nauki DDD, słownik, ADR-y, przewodnik`

---

### Task 14: Gałąź DDD-lite i porównanie

**Files (na gałęzi `ddd-lite-porownanie` z `main` po Task 13):**
- Delete: `src/SellerPortal/Domain`, `src/SellerPortal/Infrastructure/Persistence`, `tests/Unit/SellerPortal/Domain`, `tests/Unit/SellerPortal/InMemory`, `tests/Arch/SellerPortalLayersTest.php`
- Modify: `app/Models/{Offer,Seller}.php` — reguły jako metody modeli (`publish()`, `changePrice()`…), enumy statusów jako casty, cast `Money`, zdarzenia przez `EmitsOutboxEvents`; `Application` handlery wołają modele Eloquenta
- Create: `tests/Feature/Lite/{OfferRulesTest,SellerRulesTest}.php` (te same przypadki co testy domeny, na bazie)
- Unchanged (wymóg): `tests/Feature/SellerPortal/Http/*`, kontrolery, strony Vue
- Create na `main`: `docs/blog/ddd-vs-ddd-lite.md`

- [ ] **Step 1:** `git switch -c ddd-lite-porownanie`
- [ ] **Step 2:** Przepisz; `git diff main -- tests/Feature/SellerPortal/Http` → pusty
- [ ] **Step 3:** `php artisan test --compact` → PASS
- [ ] **Step 4:** Pomiary obu gałęzi: `find … | xargs wc -l` per warstwa, czas testów reguł (`--profile`), lista miejsc, gdzie regułę można obejść (`grep -rn "Offer::query()\|->update(\|->save()" app src`), koszt nowej reguły: dodaj w obu wersjach „cena nie może spaść poniżej 1 zł” i zapisz `git diff --stat`
- [ ] **Step 5:** Commit na gałęzi `Portal sprzedawcy (DDD-lite): te same reguły w modelach Eloquenta`; `git push -u origin ddd-lite-porownanie` (po potwierdzeniu użytkownika)
- [ ] **Step 6:** `git switch main`; `docs/blog/ddd-vs-ddd-lite.md`: tabela zmierzonych liczb, diffy nowej reguły, „kiedy który styl”; link do gałęzi
- [ ] **Step 7: Commit** `Portal sprzedawcy: porównanie DDD i DDD-lite`
