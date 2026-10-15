# Portal sprzedawcy: pełne DDD na prawdziwej funkcji, krok po kroku

*ETAP E projektu Marketplace Search Platform. Sprzedawcy zakładają konta,
moderator je akceptuje, sprzedawcy sami prowadzą oferty (szkic, publikacja,
cena ze strażnikiem, stan magazynowy, wycofanie), a moderator zawiesza oferty,
rozstrzyga duże zmiany cen i blokuje sprzedawców. Zbudowane jako osobny
bounded context w pełnym DDD: czysta domena bez Laravela, agregaty,
value objecty, repozytoria bez Eloquenta, zdarzenia domenowe tłumaczone na
zdarzenia integracyjne, polityki ze spójnością ostateczną. Celem nie jest
„DDD, bo modne”, tylko nauczyć się go na funkcji, która naprawdę ma reguły,
i umieć potem powiedzieć, ile kosztował.*

> **Stan:** zrobione lokalnie (Taski 1–12 planu). Testy portalu zielone
> (domena i aplikacja: 130 testów w 44 ms; cały catalog: 362 testy),
> `make smoke` 25/25, wszystkie cztery kryteria funkcjonalne specu
> sprawdzone ręcznie na żywym stacku z wyszukiwarką, nDCG@10 0.779 → 0.786
> po `make reproject` ([POMIARY](../POMIARY.md)).
> **Jeszcze nie zrobione:** wdrożenie na k3s (czeka na Twoją zgodę, bo to
> zmiana na serwerze). Porównanie z DDD-lite (kryterium 7 specu) jest
> zrobione lokalnie: [`ddd-vs-ddd-lite.md`](ddd-vs-ddd-lite.md), gałąź
> `ddd-lite-porownanie` czeka na wypchnięcie.
>
> Moduł nauki z teorią i ćwiczeniami: [`docs/10-DDD.md`](../10-DDD.md).
> Słownik: [`docs/11-SELLER-PORTAL-SLOWNIK.md`](../11-SELLER-PORTAL-SLOWNIK.md).
> Decyzje: [`docs/adr/`](../adr/). Spec: [`docs/superpowers/specs/2026-10-15-portal-sprzedawcy-ddd-design.md`](../superpowers/specs/2026-10-15-portal-sprzedawcy-ddd-design.md),
> plan: [`docs/superpowers/plans/2026-10-15-portal-sprzedawcy-ddd.md`](../superpowers/plans/2026-10-15-portal-sprzedawcy-ddd.md).

---

## Spis treści

1. [Dlaczego DDD akurat tutaj](#1-dlaczego-ddd-akurat-tutaj)
2. [Mapa: co powstało i gdzie](#2-mapa-co-powstało-i-gdzie)
3. [Jak czytać ten przewodnik i odtwarzać kroki](#3-jak-czytać-ten-przewodnik-i-odtwarzać-kroki)
4. [Krok 1 — Szkielet modułu, Money, test architektury](#krok-1--szkielet-modułu-money-test-architektury)
5. [Krok 2 — Agregat Seller](#krok-2--agregat-seller)
6. [Krok 3 — Agregat Offer: cykl życia i stan](#krok-3--agregat-offer-cykl-życia-i-stan)
7. [Krok 4 — Strażnik ceny i utwardzenie value objectów](#krok-4--strażnik-ceny-i-utwardzenie-value-objectów)
8. [Krok 5 — Migracje: status zamiast `active`, `listed()`](#krok-5--migracje-status-zamiast-active-listed)
9. [Krok 6 — Repozytoria SQL, optymistyczna blokada, outbox](#krok-6--repozytoria-sql-optymistyczna-blokada-outbox)
10. [Krok 7 — Warstwa aplikacji: komendy, handlery, porty](#krok-7--warstwa-aplikacji-komendy-handlery-porty)
11. [Krok 8 — Polityki blokady i catalog-worker](#krok-8--polityki-blokady-i-catalog-worker)
12. [Krok 9 — Onboarding sprzedawcy (HTTP + UI)](#krok-9--onboarding-sprzedawcy-http--ui)
13. [Krok 10 — Oferty sprzedawcy (HTTP + UI)](#krok-10--oferty-sprzedawcy-http--ui)
14. [Krok 11 — Moderacja](#krok-11--moderacja)
15. [Krok 12 — Weryfikacja end-to-end i pomiary](#krok-12--weryfikacja-end-to-end-i-pomiary)
16. [Znane ograniczenia (świadomie odłożone)](#znane-ograniczenia-świadomie-odłożone)
17. [Bilans: co DDD dało, a co kosztowało](#bilans-co-ddd-dało-a-co-kosztowało)
18. [Ściąga](#ściąga)
19. [Twoja kolej](#twoja-kolej)

---

## 1. Dlaczego DDD akurat tutaj

Do tego etapu oferty były wierszami, które admin edytował w panelu:
`Offer::updateWithOutbox()` plus kontroler na 42 linie. To był CRUD i był
w porządku, bo nie było reguł. Portal sprzedawcy zmienia naturę problemu.
Pojawiają się:

- **stany z regułami przejść** (szkic, opublikowana, zawieszona, wycofana;
  sprzedawca oczekujący, aktywny, odrzucony, zablokowany),
- **reguły, które się zmieniają** (próg 30% dla zmiany ceny, limit 5 zmian
  na dobę),
- **dwóch aktorów z różnymi prawami** do tej samej rzeczy (sprzedawca
  i moderator),
- **skutki rozciągnięte w czasie** (blokada sprzedawcy zawiesza tysiące
  ofert, odblokowanie przywraca tylko część).

To jest złożoność biznesowa, a nie techniczna, i dokładnie dla niej DDD
powstało. Katalog produktów i wyszukiwarka zostały bez zmian w stylu, bo tam
tej złożoności nie ma ([`10-DDD.md`](../10-DDD.md), moduł 10).

Model powstał przed kodem, w specu, w dialogu projektowym: pytania
o reguły i decyzje spisywane od razu jako tekst. Event Storming się nie
odbył. Moduł 1 `10-DDD.md` pokazuje jego **rekonstrukcję** (zdarzenia →
komendy → aktorzy → polityki → hot spoty → agregaty), czyli to, jak zespół
doszedłby do tego samego modelu na ścianie. Przy zamówieniach (podprojekt C)
zrób go naprawdę, zanim powstanie spec. Najważniejsze, co spec musiał
rozstrzygnąć, to trzy pytania, które bez tego rozstrzygnąłby programista
w ifie: „czy odblokowanie przywraca też oferty
zawieszone przez moderatora?” (nie: stąd `SuspensionKind`), „czy wyprzedana
to status?” (nie: [ADR-0004](../adr/0004-wyprzedana-jako-stan-pochodny.md)),
„czy do limitu liczą się akceptacje moderatora?” (nie).

---

## 2. Mapa: co powstało i gdzie

```
apps/catalog/src/SellerPortal/          PSR-4 SellerPortal\  (nowy katalog bazowy, ADR-0001)
  Domain/          czyste PHP: Offer/, Seller/, Shared/       61 plików, 2241 linii
                   agregaty, VO, zdarzenia, wyjątki z CODE, interfejsy repozytoriów, PriceChangePolicy
  Application/     komendy + handlery, porty, SellerOfferAccess, polityki       45 plików, 1310 linii
  Infrastructure/  Sql*Repository (Query Builder), OfferIntegrationEvents (outbox),
                   joby polityk, adaptery Laravela, ReadModel/* (SQL dla UI)   19 plików, 1207 linii
apps/catalog/app/Http/Controllers/Seller/*               UI sprzedawcy (Inertia)
apps/catalog/app/Http/Controllers/Panel/Moderation/*     UI moderatora
apps/catalog/app/Providers/SellerPortalServiceProvider.php   spinanie portów z implementacjami
apps/catalog/tests/Unit/SellerPortal/{Domain,Application,InMemory}   bez bazy i frameworka
apps/catalog/tests/Feature/SellerPortal/{Infrastructure,Http}       SQLite w pamięci
apps/catalog/tests/Arch/SellerPortalLayersTest.php       reguły zależności warstw
compose.yaml, compose.prod.yaml, deploy/k8s/catalog.yaml catalog-worker (queue:work database)
```

Zależności płyną tylko do środka: `Infrastructure` → `Application` →
`Domain`. Kontrolery (`App\`) używają aplikacji i modeli odczytu. Domena
i aplikacja nie znają Laravela, czego pilnuje test architektury.

Przepływ jednej komendy (np. zmiana ceny):

```
PUT /seller/offers/{id}/price
  → SellerOfferController::updatePrice          (walidacja formularza, „1 299,99” → 129999)
  → ChangeOfferPriceHandler::handle             (transakcja)
      → SellerOfferAccess::forActiveSeller      (czyja oferta? konto aktywne?)  → 404 / błąd domain
      → Offer::changePrice($cena, $now)         (oczekująca? limit? próg 30%?) → PriceChangeDecision
      → SqlOfferRepository::save                (UPDATE … WHERE version = ?, historia cen, outbox offer.*)
      → DomainEventDispatcher::dispatch         (polityki w procesie, w tej samej transakcji)
  ← toast „Cena zmieniona” albo „Zmiana o ponad 30% czeka na akceptację moderatora”
outbox-publisher → RabbitMQ offer.# → search-consumer → GET projekcja → ES
```

---

## 3. Jak czytać ten przewodnik i odtwarzać kroki

Każdy krok to jeden Task planu i jeden albo kilka commitów na gałęzi
`portal-sprzedawcy`:

```bash
git log --oneline --reverse main..portal-sprzedawcy
```

Testy uruchamiasz **na hoście**, z `apps/catalog` (`phpunit.xml` wymusza
SQLite w pamięci). Nigdy w kontenerze, bo tam trafiłyby w bazę deweloperską
(RUNBOOK [#018](../RUNBOOK.md#018), [#026](../RUNBOOK.md#026)).

```bash
cd apps/catalog
php artisan test --compact tests/Unit/SellerPortal/Domain     # domena: bez bazy, bez frameworka
php artisan test --compact tests/Unit/SellerPortal            # + warstwa aplikacji na repozytoriach w pamięci
php artisan test --compact tests/Arch                         # zależności warstw
php artisan test --compact tests/Feature/SellerPortal         # SQL, outbox, joby, HTTP
```

Chcesz zobaczyć stan po konkretnym kroku: `git switch --detach <commit>`
i testy dla tej warstwy. Uwaga: kontener `catalog-app` montuje
`apps/catalog` z tego checkoutu, więc przełączenie gałęzi zmienia też kod
w działającej aplikacji. Wróć przez `git switch portal-sprzedawcy`.

---

## Krok 1 — Szkielet modułu, Money, test architektury

**Commit:** `0e7003e`. **Moduł nauki:** 2 i 4.

Pierwszy commit nie ma żadnej funkcji. Ma *granicę*: katalog
`apps/catalog/src/SellerPortal/` z autoloadem `SellerPortal\` w
`composer.json`, `src` w `<source>` w `phpunit.xml`, osobny suite `Arch`
i test architektury:

```php
// apps/catalog/tests/Arch/SellerPortalLayersTest.php
arch('domena nie zna frameworka ani innych warstw')
    ->expect('SellerPortal\Domain')
    ->not->toUse(['Illuminate', 'App', 'SellerPortal\Application', 'SellerPortal\Infrastructure']);
```

Dlaczego test przed kodem: granica, której nic nie pilnuje, przestaje
istnieć w pierwszym tygodniu. `arch()` jest wbudowane w Pest, więc zero
nowych zależności.

Do tego wspólne klocki domeny: `AggregateRoot` (zapisuje zdarzenia, nie
publikuje), `DomainException` ze stałym `CODE` (domena mówi kodem reguły,
UI tłumaczy go na zdanie), `Money` w groszach, `Reason`, identyfikatory.

```bash
php artisan test --compact tests/Arch tests/Unit/SellerPortal/Domain/MoneyTest.php
```

### Co się zepsuło: przegląd bezpieczeństwa znalazł dwie dziury w VO

Automatyczny przegląd bezpieczeństwa commitu z Taska 1 zgłosił dwie rzeczy,
których żaden test nie łapał:

1. **`Money::differsByMoreThanPercent` miał domyślny punkt odniesienia.**
   Zamiana argumentów zmienia wynik: 10000 → 13001 to +30,01% (do akceptacji),
   a 13001 → 10000, liczone od 13001, to ≈ 23% (przechodzi od razu). Ktoś,
   kto kiedyś wywoła to w odwrotnej kolejności, po cichu odwróci strażnika ceny.
2. **`Reason` z samych twardych spacji (U+00A0) liczył się jako niepusty**,
   bo `trim()` ich nie zna. Moderator mógłby odrzucić zgłoszenie z „powodem”,
   który wygląda na pusty.

Obie poprawki weszły w Kroku 4 (ten sam obszar kodu).

---

## Krok 2 — Agregat Seller

**Commit:** `b1ee47f`. **Moduł nauki:** 5.

`Seller` (`apps/catalog/src/SellerPortal/Domain/Seller/Seller.php`): prywatny
konstruktor, `register` (status `Pending`, zdarzenie `SellerRegistered`),
`reconstitute` (odczyt z bazy bez zdarzeń), przejścia `approve`, `reject`,
`block`, `unblock`, każde z `requireStatus` i zdarzeniem.

```
Pending ──approve──▶ Active ──block──▶ Blocked ──unblock──▶ Active
   └─reject──▶ Rejected (koniec)
```

Dwie decyzje warte zapamiętania:
- `canSell()` zwraca `bool`, a nie rzuca wyjątku. To pytanie, a wywołujący
  decyduje, co z odpowiedzią zrobić.
- `Seller` **nie** trzyma kolekcji ofert, choć blokada ich dotyczy. Uzasadnienie
  (pamięć, konflikty wersji, wielka transakcja) w module 5 `10-DDD.md`.
  Oferta zaczyna się jednak u sprzedawcy: `Seller::draftOffer` to fabryka,
  bo tylko sprzedawca wie, czy wolno mu sprzedawać.

`Rejected` jest końcowy. To doprecyzowano w Kroku 10, gdy okazało się, że
komentarz w enumie obiecywał „może złożyć nowe zgłoszenie”, a model na to
nie pozwalał (użytkownik ma najwyżej jeden profil sprzedawcy).

```bash
php artisan test --compact tests/Unit/SellerPortal/Domain/SellerTest.php
```

---

## Krok 3 — Agregat Offer: cykl życia i stan

**Commit:** `06c9584`. **Moduł nauki:** 5 i 1 (stany pochodne).

`Offer` (`apps/catalog/src/SellerPortal/Domain/Offer/Offer.php`):

```
Draft ──publish──▶ Published ──suspend──▶ Suspended ──reinstate──▶ Published
Draft | Published | Suspended ──withdraw──▶ Withdrawn (koniec)
```

- `publish(Seller $seller, $now)`: tylko ze szkicu, stan > 0, sprzedawca
  aktywny. Sprzedawca przychodzi jako argument tylko do odczytu, bo oferta
  trzyma wyłącznie `SellerId`.
- `changeStock`: w opublikowanej ofercie przejście > 0 → 0 to `OfferSoldOut`,
  0 → > 0 to `OfferBackInStock`, inaczej `OfferStockChanged`. Ten sam stan
  to brak zdarzenia.
- `suspend(SuspensionReason)`: powód ma **rodzaj** (`byModerator` albo
  `sellerBlocked`). To jest rozstrzygnięty w specu hot spot (w rekonstrukcji Event Stormingu
z modułu 1) zamieniony w typ.
- `isListed()` i `isSoldOut()` to stany pochodne, nie statusy
  ([ADR-0004](../adr/0004-wyprzedana-jako-stan-pochodny.md)).
- `allowedActionsFor(Actor)` to jedyne źródło prawdy o tym, które przyciski
  pokazać sprzedawcy i moderatorowi. Test z tabelą wszystkich kombinacji
  (status × aktor × oczekująca zmiana × stan): `OfferLifecycleTest` ›
  „allowedActionsFor zgodne z tabelą”.

```bash
php artisan test --compact tests/Unit/SellerPortal/Domain/OfferLifecycleTest.php tests/Unit/SellerPortal/Domain/OfferStockTest.php
```

---

## Krok 4 — Strażnik ceny i utwardzenie value objectów

**Commit:** `ac06c9a`. **Moduł nauki:** 4 i 5.

`PriceChangePolicy` (`Domain/Offer/PriceChangePolicy.php`) to czysta klasa
z nazwanymi stałymi: `THRESHOLD_PERCENT = 30`, `MAX_CHANGES_PER_DAY = 5`.
Osobno od `Offer`, bo progi zmieniają się niezależnie od cyklu życia oferty
i da się je testować bez agregatu.

`Offer::changePrice($proposed, $now)` w opublikowanej ofercie sprawdza
w stałej kolejności:

1. oczekująca zmiana → `PriceChangeAlreadyPending`,
2. limit 5 zmian w kroczącym oknie 24 h → `PriceChangeLimitExceeded`
   (liczą się zmiany wprowadzone i zgłoszone, nie akceptacje),
3. próg: `|nowa − stara| × 100 > stara × 30` na liczbach całkowitych →
   `PendingPriceChange` + `OfferPriceChangeRequested`, do decyzji obowiązuje
   stara cena; inaczej `OfferPriceChanged`.

W szkicu cena zmienia się od razu, bez limitu, bo nikt jej jeszcze nie widzi.
Zegar przychodzi z zewnątrz, więc test „szósta zmiana w 24 h rzuca PriceChangeLimitExceeded” nie czeka doby.

**Poprawki z przeglądu bezpieczeństwa (Krok 1):**
- `Money::differsByMoreThanPercent`: punkt odniesienia nazwany w docblocku
  (`$this` = obecna cena), test asymetrii (`MoneyTest` ›
  „differsByMoreThanPercent jest asymetryczne…”), walidacja procentu 0–100,
  komentarz w jedynym miejscu wywołania.
- `Reason`: przycinanie po klasach Unicode `[\p{Z}\p{C}]`, odrzucanie znaków
  sterujących poza `\n\r\t` (powód przychodzi z textarea), długość w znakach.
- `SuspensionReason::restore` odtwarza z bazy tylko dwie dozwolone kombinacje
  (blokada bez moderatora i notatki, moderacja z obojgiem).

```bash
php artisan test --compact tests/Unit/SellerPortal/Domain
# → 95 testów samej domeny (bez warstwy aplikacji), kilkadziesiąt ms
```

---

## Krok 5 — Migracje: status zamiast `active`, `listed()`

**Commit:** `98d89af`. **Moduł nauki:** 9 i 10.

Trzy migracje (`apps/catalog/database/migrations/2026_10_15_10000{0,1,2}_*`):

- `sellers`: `user_id` (nullable, unique, FK), `status` z CHECK, `status_reason`,
  `status_changed_at`. Istniejący sprzedawcy z seeda dostają `active`.
- `offers`: `status` z CHECK i migracją danych (`active = true` →
  `published`, `false` → `withdrawn`), kolumny zawieszenia i oczekującej
  ceny, usunięcie `active`, indeks `(product_id, status, stock)` pod projekcję
  i **częściowy indeks unikalny** `(seller_id, product_id) WHERE status <> 'withdrawn'`
  (moduł 9: gwarancja reguły „jedna żywa oferta na produkt”).
- `offer_price_changes (offer_id, changed_at)`: historia pod limit dobowy.

Po stronie odczytu Eloquent `Offer` dostał scope `listed()` (`published`
i stan > 0), który zastąpił **wszystkie** `where('active', true)`: projekcję
dla searcha, panel produktów i ślad zdarzeń. Jedna definicja „widocznej”
po stronie odczytu, zgodna z `Offer::isListed()` w domenie.

Zniknęły ścieżki zapisu admina (`Panel/OfferController`, 42 linie,
`Offer::updateWithOutbox`). Admin już nie edytuje cen sprzedawców, tylko
moderuje. Razem z nimi, za Twoją zgodą wyrażoną przy akceptacji specu,
zniknął `tests/Feature/Panel/OfferUpdateTest.php` (8 z 9 testów opisywało
usuniętą funkcję; test sortowania przeniesiono do `ProductEditTest`),
a dwa testy z `OutboxEventsTest` przeszły z `Offer` na inne modele.

```bash
make up-apps
docker compose exec catalog-app php artisan migrate --force
```

---

## Krok 6 — Repozytoria SQL, optymistyczna blokada, outbox

**Commit:** `014e896`. **Moduł nauki:** 6 i 7.

`SqlOfferRepository` i `SqlSellerRepository` mapują agregaty ręcznie przez
Query Builder ([ADR-0002](../adr/0002-czysta-domena-bez-eloquenta.md)):

- `nextIdentity()` bierze `nextval()` z sekwencji, więc agregat ma ID od urodzenia,
- `save()` w **jednej transakcji**: INSERT (wersja 0) albo
  `UPDATE … WHERE id = ? AND version = ?` (0 wierszy → `ConcurrencyConflict`),
  historia cen i wiersze outboxu,
- `OfferIntegrationEvents` tłumaczy 14 zdarzeń domenowych na `offer.*` jawną
  mapą. Niezmapowane zdarzenie to `LogicException`, nie cisza. `sequence` =
  nowa wersja oferty,
- zdarzenia sprzedawcy **nie** trafiają do outboxu
  ([ADR-0003](../adr/0003-zdarzenia-sprzedawcy-wewnetrzne.md)),
- `hydrate()` nie ufa bazie (niespójny wiersz → wyjątek z numerem oferty),
- naruszenie częściowego indeksu unikalnego → `LiveOfferAlreadyExists`, nie surowy `QueryException`.

```bash
php artisan test --compact tests/Feature/SellerPortal/Infrastructure
```

### Co się zepsuło: precyzja `timestamptz` (RUNBOOK #041)

Migracje z Kroku 5 nie były wcześniej uruchomione na Postgresie, tylko na
SQLite w testach. W Kroku 6 sprawdziliśmy je na jednorazowym kontenerze
Postgres 18.4 (osobna nazwa i port, usunięty po teście; cudzych kontenerów
na tej maszynie nie ruszaliśmy): `migrate:fresh`, `rollback --step=3`,
`migrate` i `nextval` działały. Wyszła jednak wada, której SQLite nie pokaże:

`timestampTz('changed_at')` bez precyzji to w Postgresie `timestamptz(0)`,
czyli zaokrąglenie do sekund. `savePriceChanges` dopisuje tylko wpisy nowsze
od najnowszego zapisanego, więc dwie zmiany ceny z tej samej sekundy mogłyby
skleić się w jedną i limit „5 na dobę” by przeciekał. Naprawa: precyzja 6
w obu kolumnach (`offer_price_changes.changed_at`, `offers.pending_requested_at`),
sprawdzona przez `information_schema` (`datetime_precision = 6`).

**Ważne dla Ciebie:** poprawka zmieniła migracje, które już były w repo.
Jeśli Twoja baza deweloperska uruchomiła je przed tą poprawką, Laravel ich
nie powtórzy. Sprawdź i napraw wg RUNBOOK [#041](../RUNBOOK.md#041)
(`migrate:rollback --step=3` + `migrate`, albo `migrate:fresh` + `make seed`).

---

## Krok 7 — Warstwa aplikacji: komendy, handlery, porty

**Commit:** `f1ed55d`. **Moduł nauki:** 8.

16 par komenda + handler (`Application/{Seller,Offer,Moderation}/`), każdy
handler w tym samym kształcie:

```php
return $this->transactions->run(function () use ($command) {
    [, $offer] = $this->access->forActiveSeller(UserId::fromInt($command->userId), OfferId::fromInt($command->offerId));
    $decision = $offer->changePrice(Money::fromCents($command->priceCents), $this->clock->now());
    $this->offers->save($offer);
    $this->events->dispatch($offer->releaseEvents());
    return $decision;
});
```

Porty (`Application/Port/`): `Clock`, `TransactionManager`,
`DomainEventDispatcher`, `PolicyJobQueue`, `ProductCatalog`. Adaptery
Laravela w `Infrastructure/`, spięte w `SellerPortalServiceProvider`.
`SellerOfferAccess` pilnuje reguł dostępu aktora: `forUser` (czyja oferta;
cudza → `OfferNotOwned` → 404) i `forActiveSeller` (do tego konto aktywne).

Testy aplikacji chodzą na repozytoriach w pamięci
(`tests/Unit/SellerPortal/InMemory/`), więc cała warstwa jest sprawdzana
w milisekundach.

```bash
php artisan test --compact tests/Unit/SellerPortal/Application
```

### Co się zepsuło: dwa problemy znalezione w przeglądzie

1. **Wyścig przy rejestracji.** Dwa równoległe kliknięcia „zgłoś się” mogą
   oba przejść `findByUserId` w handlerze, a drugie trafiało w unikalny
   indeks `sellers.user_id` i kończyło się 500. Poprawka: repozytorium
   tłumaczy naruszenie indeksu na `SellerAlreadyRegistered` (tak samo jak
   `LiveOfferAlreadyExists` dla ofert). To ogólna lekcja modułu 9:
   sprawdzenie w aplikacji daje czytelny komunikat, a indeks daje gwarancję.
2. **Dispatcher pod Octane.** Dispatcher zdarzeń trzyma słuchaczy, więc jest
   `scoped` (nowy na żądanie). Gdyby słuchacze polityk byli rejestrowani
   w `boot()`, po pierwszym żądaniu Octane wyczyściłby instancję, a nowa
   byłaby pusta: blokada sprzedawcy przestałaby zawieszać oferty *bez
   żadnego błędu*. Ustalenie z przeglądu (wykonane w Kroku 8): słuchacze są
   rejestrowani wewnątrz fabryki scoped, a test „słuchacze polityk
   przeżywają flush instancji scoped (Octane)” woła `forgetScopedInstances()`.

---

## Krok 8 — Polityki blokady i catalog-worker

**Commity:** `2e6242d`, `e564f3a`. **Moduł nauki:** 8. **RUNBOOK:** [#040](../RUNBOOK.md#040).

Polityka „gdy sprzedawca zablokowany → zawieś jego oferty” ma dwie części:

1. **Listener** (`QueueSuspensionWhenSellerBlocked`) tylko kolejkuje job,
   w tej samej transakcji co zmiana statusu sprzedawcy. Albo blokada i job,
   albo nic (ten sam pomysł co outbox, tylko wewnątrz kontekstu).
2. **Job** (`SuspendOffersOfBlockedSellerJob` → `SuspendOffersOfBlockedSeller::run`)
   wykonuje się w nowym procesie `catalog-worker` (`queue:work database`):
   sprawdza, czy sprzedawca *nadal* jest zablokowany (szybkie „zablokuj →
   odblokuj” nic nie zostawia), pobiera ID ofert paczkami po 500, każdą
   ofertę zawiesza w osobnej transakcji, przy konflikcie wersji ponawia
   ofertę 3 razy, jest idempotentny.

Odblokowanie działa tak samo w drugą stronę, ale przywraca **tylko** oferty
zawieszone z rodzajem `seller_blocked`.

`catalog-worker` doszedł do `compose.yaml`, `compose.prod.yaml`
i `deploy/k8s/catalog.yaml`, z healthcheckiem, plus `make worker-logs`.

```bash
make up-apps
docker compose ps catalog-worker      # healthy
make worker-logs
php artisan test --compact tests/Unit/SellerPortal/Application/SellerPoliciesTest.php tests/Feature/SellerPortal/Infrastructure/SellerPolicyJobsTest.php
```

### Co się zepsuło: atomowość zależała od zmiennej środowiskowej

Pierwsza wersja (`2e6242d`) dispatchowała job na **domyślne** połączenie
kolejki. Gwarancja „blokada i job atomowo” była więc prawdziwa tylko przy
`QUEUE_CONNECTION=database`. Przy `redis` job wyszedłby mimo wycofanej
blokady, przy `sync` cała praca wykonałaby się w żądaniu HTTP moderatora.
Testy tego nie widziały, bo `phpunit.xml` ustawia `sync`, a test atomowości
przełączał kolejkę ręcznie.

Poprawka (`e564f3a`): połączenie przypięte w kodzie,
`config('queue.seller_policies_connection')` (domyślnie `database`),
niezależnie od `queue.default`. `after_commit => false` na połączeniu
`database` z komentarzem, dlaczego nie wolno go zmienić. Test „job trafia
do tabeli jobs mimo innego queue.default” z datasetem połączeń. Przy okazji
drugi problem z tego samego przeglądu: sprzedawca z tysiącami ofert nie
zmieściłby się w domyślnych 60 s workera. Job ma `timeout = 600`, worker
`--timeout=600`, a `retry_after = 660`. Laravel wymaga `timeout < retry_after`,
inaczej drugi worker wziąłby ten sam wolny job, zanim pierwszy skończy.

---

## Krok 9 — Onboarding sprzedawcy (HTTP + UI)

**Commit:** `5e8eb35`.

- `/seller/apply` (formularz: nazwa, miasto) i `/seller/status` („oczekuje”,
  „odrzucony z powodem”).
- `/panel/sellers`: kolejka oczekujących (akceptuj / odrzuć z powodem)
  i lista wszystkich (zablokuj z powodem / odblokuj).
- Middleware `seller` (ma profil sprzedawcy) i `seller.approved` (aktywny
  albo zablokowany; zablokowany widzi baner nad całym portalem).
- **Jedno miejsce tłumaczenia błędów domeny:** `bootstrap/app.php` mapuje
  `OfferNotOwned`/`OfferNotFound`/`SellerNotFound` na 404, a każdy inny
  `DomainException` na powrót z błędem `domain`, którego tekst daje
  `App\Http\DomainErrorMessages` (kod → zdanie). Kontrolery nie mają
  `try/catch` na reguły.

Przed sprawdzeniem w przeglądarce: migracje na bazie deweloperskiej
(`docker compose exec catalog-app php artisan migrate --force`) i healthy
`catalog-worker`. Sprawdzone ręcznie: rejestracja → zgłoszenie → status
„oczekuje” → akceptacja przez admina → blokada z powodem (baner u
sprzedawcy, job wykonany w workerze, `jobs` i `failed_jobs` puste) → odblokowanie.

```bash
make admin email=ty@example.com       # konto do /panel, hasło wypisane raz
make urls                             # adres catalogu
php artisan test --compact tests/Feature/SellerPortal/Http/SellerOnboardingTest.php
```

---

## Krok 10 — Oferty sprzedawcy (HTTP + UI)

**Commity:** `98b0894`, `eae3b9d`, `a0c69ba`. **Moduł nauki:** 8.

- `/seller` (pulpit z licznikami), `/seller/offers` (lista z filtrami, w tym
  „wyprzedane” i „oczekujące zmiany ceny”), `/seller/offers/create` (wybór
  produktu wyszukiwaniem po nazwie lub EAN **w Postgresie**, nie w ES, bo
  zapis nie może zależeć od dostępności wyszukiwarki), `/seller/offers/{id}`
  (szczegóły, oś czasu zdarzeń z outboxu, formularz ceny z podpowiedzią na
  żywo „zmiana o 42% — trafi do akceptacji”, „pozostały 3 zmiany na dobę”).
- Próg i licznik w podpowiedzi przychodzą z tej samej `PriceChangePolicy`,
  której używa domena, a decyzję i tak podejmuje agregat.
- Cena „1 299,99” (spacja, przecinek) → 129999 groszy, tak samo jak w panelu.
- Przyciski wyłącznie z `allowed_actions`.

Poprawki z przeglądu w `eae3b9d`: odrzucenie sprzedawcy jest ostateczne
(i UI mówi to wprost), złamane reguły domeny nie trafiają do logu (to
oczekiwane błędy użytkownika; wyjątek: `InvalidValue`, który za walidacją
formularza oznacza błąd w kodzie), link „Portal sprzedawcy” w menu.

```bash
php artisan test --compact tests/Feature/SellerPortal/Http/SellerOfferTest.php tests/Feature/SellerPortal/Http/PriceGuardTest.php
```

### Co się zepsuło: reguła biznesowa w kontrolerze

Reguła „zablokowany sprzedawca może tylko wycofać ofertę” wylądowała
najpierw w `SellerOfferController::show`, przy budowaniu listy przycisków.
Działało, testy HTTP były zielone. Przegląd odrzucił to jako podręcznikowy
przeciek reguły do UI: każda inna ścieżka (API, konsola, przyszły ekran)
musiałaby ją powtórzyć, a test jednostkowy jej nie widział.

Poprawka (`a0c69ba`): `SellerOfferAccess::sellerActions` w warstwie
aplikacji, obok podziału `forUser`/`forActiveSeller`, który i tak już tam
był, plus `SellerOfferAccessTest`. W module 8 `10-DDD.md` jest ćwiczenie,
które cofa tę poprawkę: testy HTTP zostają zielone, czerwony jest tylko
test warstwy aplikacji. Przecieki reguł do UI wyłapuje przegląd kodu i test
we właściwej warstwie, a nie test end-to-end.

---

## Krok 11 — Moderacja

**Commit:** `b577cb1`.

- `/panel/moderation/price-changes`: kolejka zmian cen (stara i nowa cena,
  różnica w %, sprzedawca), akceptuj / odrzuć z powodem, licznik w menu admina.
- Strona produktu w panelu: wiersz oferty pokazuje status, sprzedawcę i jego
  status. Akcje moderatora (zawieś z notatką, przywróć, rozpatrz zmianę ceny)
  pochodzą z `allowedActionsFor(Actor::Moderator)`.
- Testy HTTP blokady end-to-end: zawieszenie wszystkich opublikowanych
  i selektywne przywrócenie (`SellerBlockingTest`).

```bash
php artisan test --compact tests/Feature/SellerPortal/Http/ModerationTest.php tests/Feature/SellerPortal/Http/SellerBlockingTest.php
```

---

## Krok 12 — Weryfikacja end-to-end i pomiary

**Commity:** `372d5cb`, `319e7b8`. **Wyniki:** [POMIARY](../POMIARY.md).

### Kryteria sukcesu specu, sprawdzone na żywym stacku

| # | Kryterium | Wynik |
|---|---|---|
| 1 | Rejestracja → akceptacja → szkic → publikacja → oferta w `/search` w ciągu kilku sekund | ✅ szkic z ceną „1 299,99”, publikacja, w ES `price_min = 129999` |
| 2 | Zmiana > 30% nie trafia do wyszukiwarki przed akceptacją; szósta zmiana w dobie odrzucona | ✅ +50%: podpowiedź, toast, ES dalej 129999; po akceptacji w panelu ES 195000. Szósta zmiana: test HTTP `PriceGuardTest` |
| 3 | Stan 0 zdejmuje ofertę z wyszukiwarki, uzupełnienie przywraca | ✅ stan 0 → znika z ES, stan 5 → wraca |
| 4 | Blokada zawiesza opublikowane oferty; odblokowanie przywraca tylko zawieszone przez blokadę | ✅ sprzedawca z seeda Senger LLC: oferta #40 zawieszona wcześniej przez moderatora; blokada sprzedawcy zawiesiła pozostałe opublikowane oferty (m.in. #68 i #311) jako `seller_blocked` i zniknęły z ES; po odblokowaniu wróciły, a #40 została zawieszona |
| 5 | Domena nie importuje Laravela, pilnuje test w CI | ✅ `tests/Arch` (z jedną luką: globalna funkcja `now()`, patrz moduł 8) |
| 6 | Testy domeny < 1 s | ✅ `tests/Unit/SellerPortal`: 130 testów, 44 ms |
| 7 | Gałąź DDD-lite, te same testy HTTP, porównanie na liczbach | ⏳ następne zadanie |
| 8 | Commity + przewodnik | ✅ ten dokument |

Po drodze dodatkowy, niezamierzony dowód: przy sprawdzaniu kryterium 4
najpierw kliknięty został zły wiersz i zablokowany sprzedawca Orn Ltd.
Wszystkie jego 76 opublikowanych ofert zostało zawieszonych jako
`seller_blocked`, a po odblokowaniu wszystkie 76 wróciły.

### Wyszukiwarka po zmianie `active` → `listed()`

```bash
make reproject      # przelicz wszystkie 1500 dokumentów z projekcji
make eval           # nDCG@10 na 13 zapytaniach kontrolnych
make smoke          # 25/25
```

nDCG@10: **0.779 → 0.786**. Punkt odniesienia 0.779 zmierzono tego samego
dnia na tym samym stacku *przed* migracjami portalu (starsze 0.967 pochodzi
z innych danych i nie jest porównywalne). 89 z 4518 ofert (ok. 2%) miało
stan 0 i `listed()` je odfiltrowuje. Interpretacja różnicy to hipotezy
z krokami weryfikacji, nie fakty: [POMIARY](../POMIARY.md), sekcja „Portal
sprzedawcy”. `make reproject` jest potrzebne, bo samo przetwarzanie zdarzeń
odświeża tylko ruszone produkty, a indeks byłby niespójny.

**Nie zrobione w tym kroku:** wdrożenie na k3s. To zmiana na serwerze i czeka
na Twoją zgodę. Gdy ją dasz, kolejność to `make k3s-deploy` (Job migracji
wykona trzy migracje portalu), sprawdzenie `catalog-worker` w
`kubectl -n marketplace get deploy catalog-worker`, `make k3s-reproject`
i `php artisan search:eval` w podzie `catalog-app` na k3s.

---

## Znane ograniczenia (świadomie odłożone)

Uczciwa lista z przeglądów kodu, rzeczy nienaprawione w tym etapie:

- **Dwa pody workera przy rolling update** mogą jednocześnie wykonać
  zawieszanie i przywracanie dla tego samego sprzedawcy, bo status
  sprzedawcy jest sprawdzany raz na starcie joba. Możliwe naprawy:
  ponowne sprawdzenie statusu przed każdą paczką albo strategia `Recreate`
  dla `catalog-worker`.
- `ACTIONS_WITHOUT_ACTIVE_SELLER` to ręczne lustro handlerów używających
  `forUser`. Nic nie wymusza ich zgodności.
- `offer_price_changes` nigdy nie jest przycinane (repozytorium czyta tylko
  ostatnie 24 h, ale stare wiersze zostają).
- UX szczegółów oferty: alert błędu `domain` znika po ≤ 2 s, bo polling osi
  czasu robi partial reload. Formularze stanu i szczegółów resetują się po
  każdym przeładowaniu.
- Strona produktu w panelu ładuje agregat na każdą ofertę (N odczytów).
- Test architektury nie widzi globalnych funkcji (`now()`, `time()`) w domenie.
  Reguła, która to zamyka, jest w ćwiczeniu w module 8 `10-DDD.md`.

---

## Bilans: co DDD dało, a co kosztowało

Zmierzone na tym repo 2026-10-15 (`find … | wc -l`, wyniki testów z POMIARÓW):

| | Przed etapem (CRUD admina) | Portal sprzedawcy (pełne DDD) |
|---|---|---|
| Kod zapisu ofert | model `Offer` 84 linie + `Panel/OfferController` 42 linie | `src/SellerPortal`: 125 plików PHP, 4758 linii (domena 2241, aplikacja 1310, infrastruktura 1207) |
| Mapowanie na bazę | Eloquent, za darmo | 598 linii (`Infrastructure/Persistence`) |
| Gdzie żyją reguły | kontroler i model | agregat (stan), `SellerOfferAccess` (aktor), `PriceChangePolicy` (progi), polityki (skutki w czasie) |
| Testy reguł | z bazą | 130 testów domeny i aplikacji w 44 ms, bez bazy |
| Obejście reguły | `Offer::query()->update(...)` | w kodzie portalu nic nie zapisuje poza agregatem, ale model odczytu `App\Models\Offer` nadal przyjmuje zapis (jak w lite) — granicę trzyma konwencja i przegląd kodu; DDD zmienia domyślną drogę, nie możliwość |
| Granica | brak | test architektury w CI |
| Dodanie kolumny | migracja + `$fillable` | migracja, `row`, `hydrate`, `reconstitute`, konstruktor, getter |

Funkcjonalnie te dwie kolumny nie robią tego samego (portal ma sprzedawców,
moderację, strażnika ceny i polityki), więc 4758 do 126 **nie** jest ceną
DDD. Cenę DDD względem DDD-lite przy tych samych regułach mierzy
[`ddd-vs-ddd-lite.md`](ddd-vs-ddd-lite.md): 4523 vs 2277 linii, ten sam koszt
nowej reguły; lite nie sprawdza wierszy z bazy i testuje reguły ~35× wolniej.

**DDD dało:** model, który odpowiada na pytania biznesu w jednym miejscu
(„co się dzieje z ofertami po odblokowaniu?” → `ReinstateOffersOfUnblockedSeller`
i `SuspensionKind`); testy reguł w milisekundach, więc łatwo przetestować
każdy narożnik (granica 30%, okno 24 h co do sekundy, kolejność sprawdzeń);
UI, które nie zna reguł (przyciski z `allowedActionsFor`, komunikaty z kodów);
jawny kontrakt z wyszukiwarką (mapa `offer.*`), który można zmieniać
świadomie; granicę pilnowaną automatycznie.

**DDD kosztowało:** dużo kodu ceremonii (16 handlerów o identycznym
kształcie, porty z jedną implementacją, ręczne mapowanie); wolniejsze proste
zmiany; nowy katalog bazowy i nowe pojęcia do opanowania; ryzyko, że
mapowanie rozjedzie się ze schematem (broni test „zapis i odczyt zachowuje
wszystkie pola”); typy kolumn, które trzeba sprawdzać na prawdziwym
Postgresie, bo SQLite przyjmie wszystko (#041).

**Kiedy warto:** gdy funkcja ma stany, reguły, kilku aktorów i skutki
rozciągnięte w czasie, i gdy reguły będą się zmieniać. **Kiedy nie:** katalog
produktów, słowniki, projekcje, wszystko, gdzie najtrudniejsze pytanie to
„jakie pola ma formularz” ([`10-DDD.md`](../10-DDD.md), moduł 10).

---

## Ściąga

| Chcę… | Komenda |
|---|---|
| testy domeny (bez bazy) | `cd apps/catalog && php artisan test --compact tests/Unit/SellerPortal/Domain` |
| testy domeny + aplikacji | `php artisan test --compact tests/Unit/SellerPortal` |
| test architektury | `php artisan test --compact tests/Arch` |
| testy SQL, outboxu, jobów, HTTP | `php artisan test --compact tests/Feature/SellerPortal` |
| stack z aplikacjami i workerem | `make up-apps` |
| migracje portalu na dev | `docker compose exec catalog-app php artisan migrate --force` |
| dev DB ze starą precyzją dat | RUNBOOK [#041](../RUNBOOK.md#041) |
| konto admina do `/panel` | `make admin email=ty@example.com` |
| adresy | `make urls` |
| logi workera polityk | `make worker-logs` (RUNBOOK [#040](../RUNBOOK.md#040)) |
| zaległe / nieudane joby | `docker compose exec postgres psql -U $CATALOG_DB_USER $CATALOG_DB -c "select id, attempts from jobs"` |
| przeliczyć ES z projekcji | `make reproject` |
| jakość wyszukiwania | `make eval` |
| dymny test stacku | `make smoke` |
| „Ktoś w międzyczasie zmienił tę ofertę” | RUNBOOK [#042](../RUNBOOK.md#042) |
| zdarzenia jednej oferty | oś czasu na `/seller/offers/{id}` albo `select sequence, event_type from outbox where aggregate_type = 'offer' and aggregate_id = '<ID>'` |

---

## Twoja kolej

1. **Przejdź kryteria 1–4 sam**, na swoim stacku: `make up-apps`, migracje,
   `make admin`, zarejestruj drugiego użytkownika jako sprzedawcę, zaakceptuj
   go w `/panel/sellers`, wystaw i opublikuj ofertę, znajdź ją w `/search`.
   Potem +50% ceny (sprawdź w ES, że cena się nie zmieniła), stan 0, blokada
   i odblokowanie. Przy każdym kroku zajrzyj do osi czasu oferty i do outboxu.
2. **Zrób wszystkie „zepsuj i napraw” z [`10-DDD.md`](../10-DDD.md)** (jest ich
   trzynaście). Zanim przeczytasz, które testy padają, zgadnij. Szczególnie
   moduł 8, ćwiczenie 2: reguła przeniesiona do kontrolera, a testy HTTP
   zielone. Zastanów się, co w Twoim zespole by to wyłapało.
3. **Dodaj regułę** (to samo zadanie zrobimy potem na gałęzi DDD-lite
   i porównamy diff): „zmiana ceny w dół o więcej niż 50% też idzie do
   akceptacji, ale zmiana w dół o 30–50% już nie”. Gdzie dopisujesz kod
   i testy? Ile plików ruszasz? Zapisz liczbę, przyda się do porównania.
4. **Zaprojektuj na sucho** naprawę ograniczenia „dwa pody workera przy
   rolling update”. Ponowne sprawdzenie statusu per paczka czy `Recreate`?
   Co każda opcja kosztuje przy sprzedawcy z 10 000 ofert?

Pytania kontrolne:

- Dlaczego `Seller` nie trzyma listy swoich ofert, skoro blokada ich dotyczy?
- Co by się stało, gdyby polityka blokady zawieszała oferty od razu, w handlerze?
- Dlaczego job polityki sprawdza status sprzedawcy, skoro powstał przy blokadzie?
- Dlaczego zdarzenia sprzedawcy nie idą do outboxu, a zdarzenia oferty idą?
- Co chroni przed drugą żywą ofertą tego samego sprzedawcy na ten sam produkt, gdy dwa żądania przyjdą naraz?
- Dlaczego poprawka precyzji `timestamptz` w już uruchomionej migracji wymaga ręcznej akcji na Twojej bazie, a na produkcji nie?
