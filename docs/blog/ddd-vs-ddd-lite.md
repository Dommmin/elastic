# DDD czy DDD-lite? Te same reguły napisane dwa razy i zmierzone

*Projekt Marketplace Search Platform, po ETAPIE E. Portal sprzedawcy powstał
w pełnym DDD: czysta domena bez Laravela, repozytoria bez Eloquenta, porty,
handlery, zdarzenia domenowe. Moduł 10 w [`10-DDD.md`](../10-DDD.md) i
[ADR-0002](../adr/0002-czysta-domena-bez-eloquenta.md) obiecywały uczciwe
porównanie: te same reguły w bogatych modelach Eloquenta (DDD-lite), te same
testy HTTP, zmierzone liczby zamiast opinii. To jest to porównanie. Wynik
w skrócie: w tej domenie DDD-lite wystarcza. Kodu jest o połowę mniej, nowa
reguła kosztuje tyle samo, a zachowanie przez HTTP jest identyczne. Za to
lite ma dwie zmierzone dziury, które pełne DDD zamyka (brudna instancja po
konflikcie wersji, niesprawdzane wiersze z bazy), traci test architektury,
a testy reguł chodzą w nim ~35 razy wolniej. Dla zamówień (podprojekt C)
rekomendacja jest inna.*

> **Stan:** gałąź `ddd-lite-porownanie` zrobiona lokalnie (2 commity od
> `portal-sprzedawcy` @ 2277141), **nie wypchnięta** — `git push -u origin
> ddd-lite-porownanie` czeka na Twoją zgodę. Po wypchnięciu:
> <https://github.com/Dommmin/elastic/tree/ddd-lite-porownanie>.
> Gałąź nie jest i nie będzie scalana: to materiał do porównania.
> Pomiary z 2026-10-15, Herd PHP 8.5, SQLite w pamięci (`phpunit.xml`).

---

## Spis treści

1. [Co dokładnie porównujemy](#1-co-dokładnie-porównujemy)
2. [Jak wygląda wersja lite (i dlaczego nie jest chochołem)](#2-jak-wygląda-wersja-lite-i-dlaczego-nie-jest-chochołem)
3. [Liczby](#3-liczby)
4. [Koszt nowej reguły: „cena nie niższa niż 1 zł”](#4-koszt-nowej-reguły-cena-nie-niższa-niż-1-zł)
5. [Gdzie da się obejść reguły](#5-gdzie-da-się-obejść-reguły)
6. [Co chroni który styl, a gdzie przecieka](#6-co-chroni-który-styl-a-gdzie-przecieka)
7. [Kiedy który styl: decyzje dla tego repo](#7-kiedy-który-styl-decyzje-dla-tego-repo)
8. [Jak odtworzyć pomiary](#8-jak-odtworzyć-pomiary)
9. [Twoja kolej](#9-twoja-kolej)

---

## 1. Co dokładnie porównujemy

Dwie gałęzie z tym samym punktem startu (`portal-sprzedawcy` @ 2277141):

| | `portal-sprzedawcy` (pełne DDD) | `ddd-lite-porownanie` (DDD-lite) |
|---|---|---|
| Gdzie żyją reguły | agregaty `SellerPortal\Domain\Offer\Offer`, `Seller\Seller` + `PriceChangePolicy` | modele `App\Models\Offer`, `App\Models\Seller` |
| Zapis | repozytoria `Sql*Repository` (Query Builder, ręczne mapowanie) | metoda modelu sama się zapisuje (`saveRecording`, `SavesWithVersionCheck`) |
| Outbox | zdarzenia domenowe → `OfferIntegrationEvents` | `recordOutboxEvent` z traita `EmitsOutboxEvents` |
| Skutki blokady sprzedawcy | zdarzenie `SellerBlocked` → polityka → job | `Seller::block()` kolejkuje job w tej samej transakcji |
| Czas | `$now` z portu `Clock` | `now()` (w testach `travelTo`) |
| Warstwa aplikacji | 16 handlerów, 5 portów, `SellerOfferAccess` | te same klasy jako cienkie akcje: „znajdź model → metoda” |

Wspólne dla obu: kontrolery (poza trzema metodami, niżej), strony Vue, modele
odczytu (`Infrastructure/ReadModel`), migracje, kody wyjątków i komunikaty
(`DomainErrorMessages`), nazwy i pola zdarzeń `offer.*`.

**Kryterium uczciwości:** testy HTTP z gałęzi DDD przechodzą na lite. Z 64
testów HTTP 63 przechodzą bez zmiany ani jednej linii. Jeden
(`PriceGuardTest` → „równoległa edycja → błąd concurrency”) wstrzykiwał
wyścig dekoratorem `OfferRepository`, czyli klasą, której w lite z definicji
nie ma. Na lite ten sam wyścig wstrzykuje zdarzenie Eloquenta `retrieved`
(+5/−40 linii, same asercje). Bez tej zmiany test padał: wiązanie
`OfferRepository` w kontenerze nikt już nie rozwiązywał, więc wyścigu nie
było, zapis wygrywał, a komunikatu o konflikcie nie było. Warto to
zapamiętać: test, który podmienia port, sprawdza zachowanie *i* architekturę
naraz — zmiana architektury go łamie, choć zachowanie się nie zmieniło.

---

## 2. Jak wygląda wersja lite (i dlaczego nie jest chochołem)

Celem było napisać to tak, jak napisałby dobry zespół Laravela, a nie
zbudować słabą wersję, żeby DDD wypadło lepiej. Co jest w środku
(`apps/catalog` na gałęzi `ddd-lite-porownanie`):

- **`app/Models/Offer.php`** — metody o nazwach z języka domeny: `publish()`,
  `changePrice(Money): PriceChangeDecision`, `changeStock()`,
  `suspendByModerator()`, `suspendBecauseSellerBlocked()`, `reinstate()`,
  `withdraw()`, `approvePriceChange()`, `rejectPriceChange()`,
  `cancelPendingPriceChange()`, `allowedActionsFor(Actor)`,
  `priceChangesLeftToday()`. Każda metoda sprawdza stan, zmienia atrybuty
  i woła `saveRecording()` (`:487`): jedna transakcja, w niej UPDATE
  z kontrolą wersji, wpis do okna zmian ceny i wiersze outboxu.
- **`app/Models/Seller.php`** — `register()`, `approve()`, `reject()`,
  `block()` / `unblock()` (kolejkują job w tej samej transakcji, `:163`),
  `draftOffer()` (sam pyta bazę o produkt i o żywą ofertę — w Active
  Record to naturalne), `suspendOffersAfterBlock()` /
  `reinstateOffersAfterUnblock()` (paczki po 500, 3 próby przy konflikcie,
  idempotentne).
- **`app/Casts/MoneyCast.php`** — `price` i `pending_price` jako `Money`
  (z `withoutObjectCaching`, bo Eloquent inaczej zapisywałby stary obiekt
  z pamięci podręcznej castów).
- **`app/Concerns/SavesWithVersionCheck.php`** — optymistyczna blokada:
  `UPDATE … WHERE id = ? AND version = <wczytana>`, 0 wierszy →
  `ConcurrencyConflict`. Tu wyszła pierwsza ciekawostka: istniejący
  `EmitsOutboxEvents::updateWithOutbox()` podbija wersję, ale zapisuje
  zwykłym `save()`, więc **wyścigu nie wykrywa**. „Domyślna droga Laravela”
  w tym repo przepuściłaby wyścig dwóch kart (ostatni zapis wygrywa po cichu).
- **`app/Jobs/*`** — te same joby (połączenie przypięte do
  `queue.seller_policies_connection`, `timeout` 600 s, `tries` 5, `backoff`).
- **Zostały, bo nie zależą od frameworka i nic nie kosztują:** wyjątki
  z `CODE`, enumy (`OfferStatus`, `OfferAction`…), `Money`, `Reason`.
  Siedzą dalej w przestrzeni `SellerPortal\Domain`, choć to już tylko
  „słownik”, nie domena.
- **Handlery zostały jako cienkie akcje** (`PublishOfferHandler`: 19 linii
  zamiast 35), żeby kontrolery prawie się nie zmieniły. Zmiany
  w kontrolerach: `SellerOfferController::store` (handler zwraca model, nie
  `OfferId`) i `::show` (bez portu `Clock`), `ProductController::offerRows`
  (woła `allowedActionsFor` na już wczytanym modelu zamiast dodatkowego
  `get()` z repozytorium na każdą ofertę — **o 2 zapytania na wiersz mniej**).

**Dwa odstępstwa od specu, oba wymuszone przez „te same testy HTTP”:**

1. Spec mówi „enumy statusów jako casty”. Testy HTTP czytają jednak surowe
   kolumny (`->status->toBe('suspended')`; 27 asercji na `status`, `suspension_kind` i `condition`). Cast enuma
   na `status` złamałby je wszystkie, więc kolumny zostały stringami, a enum
   dają metody `offerStatus()`, `suspensionKind()`, `sellerStatus()`.
   Wniosek do zapamiętania: testy pisane pod jeden model persystencji
   przywiązują się do niego bardziej, niż się wydaje.
2. `PriceGuardTest` — opisane wyżej.

---

## 3. Liczby

### Kod produkcyjny portalu (pliki / linie PHP)

Bez modeli odczytu i kontrolerów, które są wspólne
(`git ls-tree` + `git show … | wc -l`, skrypt w [rozdziale 8](#8-jak-odtworzyć-pomiary)):

| Warstwa | Pełne DDD | DDD-lite |
|---|---|---|
| `src/SellerPortal/Domain` | 61 / 2241 | 24 / 502 (wyjątki, enumy, `Money`, `Reason`) |
| `src/SellerPortal/Application` | 45 / 1310 | 36 / 726 (komendy + cienkie akcje) |
| `Infrastructure/Persistence` (mapowanie) | 5 / 598 | 0 / 0 |
| adaptery portów, joby, provider | 8 / 255 | 2 / 69 (same joby) |
| modele `Offer`, `Seller` (+ cast, trait) | 2 / 112 | 4 / 975 (`Offer` 542, `Seller` 337, `MoneyCast` 53, `SavesWithVersionCheck` 43) |
| **razem** | **121 / 4516** | **66 / 2272** |
| bez zmian w obu: `ReadModel` | 7 / 411 | 7 / 411 |
| kontrolery portalu + `ProductController` | 8 / 609 | 8 / 602 |
| strony Vue | bez zmian | bez zmian |

**Lite to 50% kodu pełnego DDD** (2272 / 4516). Gdzie poszła różnica:
598 linii mapowania, 19 klas zdarzeń domenowych, porty z jedną
implementacją, dispatcher i provider. Same reguły kosztują w obu wersjach
podobnie: agregaty `Offer` + `Seller` + `PriceChangePolicy` to 713 linii,
modele lite 879 (bo niosą też zapis, outbox i casty).

### Testy

| | Pełne DDD | DDD-lite |
|---|---|---|
| testy reguł | `tests/Unit/SellerPortal/Domain` (bez Money/Shared): 6 plików, 794 linie, **77 testów** | `tests/Feature/Lite`: 2 pliki, 731 linii, **76 testów** |
| czas testów reguł (Pest, mediana z 5) | **24 ms** (23–28) | **843 ms** (762–858) — ~35× wolniej |
| czas ściany z bootem (mediana z 5) | 0,29 s | 1,12 s — ~3,9× wolniej |
| na jeden test | ~0,3 ms | ~11 ms (RefreshDatabase + SQLite) |
| testy aplikacji na repozytoriach w pamięci | 12 plików, 951 linii | brak (nie ma czego podmieniać) |
| testy infrastruktury (`Feature/SellerPortal/Infrastructure`) | 6 plików, 860 linii | 3 pliki, 417 linii |
| testy HTTP | 64 testy, 1064 linie | **te same 64**, 1029 linii (`PriceGuardTest` −40/+5) |
| test architektury | 1 (domena i aplikacja bez `Illuminate`) | 0 — **świadoma strata**, nie ma czystej domeny do pilnowania |
| cały catalog | 362 testy (347 + 15 pominiętych), 6,0 s | 305 testów (290 + 15 pominiętych), 5,2 s; po commicie reguły z rozdziału 4: 308 (293 + 15) |
| PHPStan (poziom 7, `app/`; `src/` poza ścieżkami w obu) | 129 błędów (wcześniejsze) | 118 (mniej: typy relacji i `@property` w modelach, prostszy `ProductController`) |

Liczba testów reguł jest prawie równa, bo przypadki przepisałem jeden do
jednego (z drobnymi wyjątkami: `SuspensionReason::restore` nie ma
odpowiednika, bo lite nie waliduje wierszy przy odczycie — rozdział 6).
Różnica w czasie to w całości koszt bazy: każdy test lite migruje SQLite
w pamięci i tworzy wiersze fabrykami. 843 ms to nadal mało; problem
zaczyna się przy setkach przypadków brzegowych (np. siatka statusów ×
aktorów × oczekującej ceny, jak test `allowedActionsFor` z 15 wierszami
danych), gdzie w DDD dopisuje się je za darmo, a w lite każdy kosztuje
~11 ms.

---

## 4. Koszt nowej reguły: „cena nie niższa niż 1 zł”

Ta sama reguła dopisana w obu wersjach jako osobny commit: cena szkicu
i każda nowa cena (w szkicu i w opublikowanej ofercie) ≥ 100 groszy,
wyjątek `PriceBelowMinimum` z kodem i polskim komunikatem.

| | Pełne DDD (gałąź tymczasowa, f0d7266, usunięta po pomiarze) | DDD-lite (882d174, zostaje na gałęzi) |
|---|---|---|
| `git diff --stat` | 6 plików, **+55 / −1** | 6 plików, **+53 / −1** |
| wspólne dla obu (identyczne) | `PriceBelowMinimum.php` +19, `DomainErrorMessages.php` +1, jego test +3, `SellerOfferTest` 1 linia (przypadek „0,5 zł” łamie nową regułę → 1,5 zł) | to samo |
| reguła | `Domain/Offer/Offer.php` +15 | `app/Models/Offer.php` +15 |
| test reguły | `OfferPriceTest.php` +16 (bez bazy) | `OfferRulesTest.php` +14 (z bazą) |
| czas testu reguły (2 przypadki) | 9–12 ms | 175–262 ms |

Rdzeń zmiany obok siebie. **Pełne DDD** (`src/SellerPortal/Domain/Offer/Offer.php`):

```diff
 final class Offer extends AggregateRoot
 {
+    public const int MIN_PRICE_CENTS = 100;
+
@@ public static function draft(
         DateTimeImmutable $now,
     ): self {
+        self::requireMinimumPrice($price);
+
         $offer = new self($id, $sellerId, $productId, $price, …);
@@ public function changePrice(Money $proposed, DateTimeImmutable $now): PriceChangeDecision
         $this->requireStatus([OfferStatus::Draft, OfferStatus::Published], OfferAction::ChangePrice);
+        self::requireMinimumPrice($proposed);
@@
+    /**
+     * @throws PriceBelowMinimum
+     */
+    private static function requireMinimumPrice(Money $price): void
+    {
+        if ($price->cents() < self::MIN_PRICE_CENTS) {
+            throw new PriceBelowMinimum;
+        }
+    }
```

**DDD-lite** (`app/Models/Offer.php`):

```diff
     public const int MAX_PRICE_CHANGES_PER_DAY = 5;
 
+    public const int MIN_PRICE_CENTS = 100;
+
@@ public static function draft(int $sellerId, int $productId, Money $price, …): self
         self::guardStock($stock);
         self::guardShippingDays($shippingDays);
+        self::guardMinimumPrice($price);
@@ public function changePrice(Money $proposed): PriceChangeDecision
         $this->requireStatus([OfferStatus::Draft, OfferStatus::Published], OfferAction::ChangePrice);
+        self::guardMinimumPrice($proposed);
@@
+    /**
+     * @throws PriceBelowMinimum
+     */
+    private static function guardMinimumPrice(Money $price): void
+    {
+        if ($price->cents() < self::MIN_PRICE_CENTS) {
+            throw new PriceBelowMinimum;
+        }
+    }
```

Testy też są bliźniacze: w DDD `offerPriceOffer($status)` buduje agregat
w pamięci (`Offer::reconstitute`), w lite `offerRulesOffer($status)` wstawia
wiersz fabryką; asercje te same.

**Wniosek: dla reguły „wewnątrz jednego agregatu” koszt jest taki sam.**
Mit „w DDD każda zmiana to 5 plików” dotyczy zmian *kształtu danych*
(nowa kolumna: migracja, konstruktor, `reconstitute`, getter, `row`,
`hydrate`, ewentualnie payload outboxu — 6–7 miejsc; w lite: migracja,
`$fillable`, ewentualnie cast i payload — 2–4 miejsca; policzone z kodu, nie
zmierzone zmianą). Zmiana *reguły* kosztuje w obu stylach tyle samo,
różni się tylko szybkość pętli „zmień → test”.

---

## 5. Gdzie da się obejść reguły

### Grep z briefu na obu gałęziach

`grep -rn "Offer::query()\|->update(\|->save()\|::create(" app src`
(w `apps/catalog`). Trafienia dotyczące ofert i sprzedawców:

| Trafienie | Pełne DDD | DDD-lite | Obejście? |
|---|---|---|---|
| zapis repozytorium | `SqlOfferRepository.php:99`, `SqlSellerRepository.php:131` (`->update` z `where version`) | — | nie, to jest właściwa droga |
| zapis modelu | — | `SavesWithVersionCheck.php:35`, `Seller.php:112` (`static::create` w `register`) | nie, to jest właściwa droga |
| `EmitsOutboxEvents.php:45` / `:92` (`static::create` / `$this->save()`) | jest w obu (trait użyty w obu modelach `Offer`) | jest | **tak, potencjalne:** `$offer->updateWithOutbox([...])` to publiczna metoda na `Offer` w obu gałęziach; zapisuje bez reguł i bez kontroli wersji, a do tego wysyła zdarzenie, więc wygląda legalnie |
| `SeedMarketplaceCommand.php:217` `Offer::create([...'status' => 'published'])` | jest | jest | **tak, realne, celowe:** hurtowy import, opisany w komentarzu |
| `Offer::query()->findOr(...)` w akcjach moderatora | — | 5× w `Application/Moderation`, `SellerOfferAccess` | nie, to odczyt |

**Realnych obejść w kodzie: 1 na każdej gałęzi** (ten sam seeder). Grep
tego nie łapie, ale sprawdziłem jeszcze `DB::table('offers')`: w obu
gałęziach tylko `truncate()` w seederze i modele odczytu (same SELECT-y).

### Sondy: co da się zrobić, a czego nie

Grep pokazuje tylko to, co już jest w kodzie. Ważniejsze jest, co da się
napisać jutro. Jednorazowe testy uruchomione na obu gałęziach (nie są
w repo; wklej do `tests/Feature/…`, uruchom, usuń):

```php
// lite: save() po konflikcie wersji
$offer = Offer::factory()->for(Seller::factory()->create())->create(['status' => 'published', 'stock' => 5])->fresh();
$stale = $offer->fresh();
$offer->changeStock(9);
try { $stale->changeStock(7); } catch (ConcurrencyConflict) {}
$stale->save();
expect($offer->fresh()->stock)->toBe(7); // przechodzi: cudza zmiana nadpisana

// obie gałęzie: zapis z pominięciem reguł
$offer = Offer::factory()->for(Seller::factory()->create())->create(['status' => 'withdrawn'])->fresh();
$offer->update(['status' => 'published']);
expect($offer->fresh()->status)->toBe('published')->and(DB::table('outbox')->count())->toBe(0); // przechodzi w obu

// niespójny wiersz: lite wczytuje, DDD (`app(OfferRepository::class)->get(...)`) rzuca UnexpectedValueException
Offer::factory()->for(Seller::factory()->create())->create(['status' => 'suspended', 'suspension_kind' => null]);
```


| Próba | Pełne DDD | DDD-lite |
|---|---|---|
| `Offer::find($id)->update(['status' => 'published'])` na wycofanej ofercie | **przechodzi**, 0 wierszy outboxu | **przechodzi**, 0 wierszy outboxu |
| po `ConcurrencyConflict` zawołać `$offer->save()` na tej samej instancji | nie da się: agregat nie ma `save()`, a `repository->save()` znowu trafia w starą wersję i rzuca konflikt | **nadpisuje cudzą zmianę** (stan 7 zamiast 9 zapisanego przez drugą kartę) |
| wiersz niespójny: `status = suspended`, `suspension_kind = NULL` | `get()` rzuca `UnexpectedValueException` „Uszkodzony wiersz oferty #…” (`SqlOfferRepository.php:219`) | wczytuje się bez błędu, `allowedActionsFor(Moderator)` zwraca akcje |

Najważniejszy wynik: **pierwszy wiersz.** Pełne DDD w tym repo nie usuwa
możliwości zapisu z pominięciem reguł, bo model `App\Models\Offer` dalej
istnieje (fabryki, modele odczytu, seeder) z pełnym `$fillable` i traitem
outboxu. ADR-0002 opisywał opcję B słowami „reguły obchodzi
`Offer::query()->update(...)`” — to prawda, ale **w obu wersjach w równym
stopniu**. Różnica jest w tym, która droga jest „domyślna”: w DDD zapis przez
model Eloquenta wygląda podejrzanie na przeglądzie kodu, w lite ten sam model
ma i metody z regułami, i `update()`, więc łatwiej się pomylić.

---

## 6. Co chroni który styl, a gdzie przecieka

### Pełne DDD chroni (a lite nie)

1. **Instancja po błędzie nie nadaje się do zapisu.** Agregat nie ma
   `save()`. W lite metoda modelu zmienia atrybuty przed zapisem, a po
   `ConcurrencyConflict` brudna instancja zostaje w pamięci: sonda wyżej.
   Naprawa w lite: w `SavesWithVersionCheck` przed rzuceniem wyjątku
   przywrócić `getOriginal()` (2 linie). Nie zrobiłem tego celowo, żeby
   pokazać dziurę taką, jaką zostawiłby typowy zespół.
2. **Wiersz z bazy jest sprawdzany.** `SqlOfferRepository::hydrate`
   (`:206`–`:256`) odrzuca nieznany status, walutę, stan, niespójne
   zawieszenie i oczekującą cenę bez daty; `SuspensionReason::restore`
   pilnuje kombinacji rodzaj/moderator/notatka. Lite ufa bazie. Naprawa
   lepsza dla obu stylów: `CHECK` w migracji (`status = 'suspended'` ⇔
   `suspension_kind IS NOT NULL`).
3. **Czas i baza są parametrami.** Reguły biorą `$now`, więc 77 testów
   w 24 ms bez bazy, każdy narożnik okna 24 h co do sekundy jako czysta
   funkcja (`PriceChangePolicy`). W lite to samo trzeba ustawiać przez
   `travelTo` i wiersze w `offer_price_changes`.
4. **Granica pilnowana automatycznie.** `tests/Arch/SellerPortalLayersTest.php`
   pada, gdy `Illuminate` wejdzie do domeny. W lite nie ma czego pilnować:
   test usunięty, `phpunit.xml` bez zestawu `Arch`.
5. **Kilka zmian w jednym zapisie.** Agregat zbiera zmiany i zapisuje je
   raz (cena + stan = jeden `sequence` w outboxie). W lite każda metoda
   zapisuje się sama: dwie zmiany to dwa UPDATE-y i dwie wersje
   (`OfferIntegrationEventsTest` na lite sprawdza `sequence` 3 i 4 zamiast
   dwóch razy 2). Dla wyszukiwarki to bez znaczenia; przy regułach między
   agregatami (zamówienia) już tak.

### DDD-lite daje (a pełne DDD kosztuje)

1. **Połowa kodu** przy tym samym zachowaniu HTTP (rozdział 3).
2. **Reguły potrzebujące zapytania są na miejscu.** „Czy produkt istnieje”
   i „czy sprzedawca ma już żywą ofertę” to w lite dwie linie w
   `Seller::draftOffer` (`Seller.php:188`). W DDD to port `ProductCatalog`
   + adapter + metoda repozytorium `hasLiveOffer` + handler, który je składa.
3. **Mniej zapytań tam, gdzie i tak mamy model.** Wiersz oferty w panelu:
   DDD robi `OfferRepository::get()` (2 zapytania) na każdą ofertę produktu
   tylko po to, żeby zapytać o `allowedActionsFor`; lite woła metodę na już
   wczytanym modelu.
4. **Jeden obiekt na jedną rzecz.** W DDD istnieją dwa „Offer”
   (`App\Models\Offer` do odczytu i fabryk, agregat do zapisu) i trzeba
   pamiętać, który jest który. W lite jeden.
5. **Znany styl.** Każdy, kto zna Laravela, czyta `Offer.php` bez wstępu
   o portach i adapterach.

### Przecieka w obu

- Zapis przez model Eloquenta albo `DB::table` z pominięciem reguł
  (sonda 1). Ani test architektury, ani PHPStan tego nie łapią
  (`src/` nie jest nawet w ścieżkach PHPStana). Pilnuje przegląd kodu
  i zasada „jeden właściciel zapisu tabeli `offers`”.
- `EmitsOutboxEvents::updateWithOutbox()` jako publiczna metoda na `Offer`:
  zdarzenie poleci, reguły i kontrola wersji nie.

---

## 7. Kiedy który styl: decyzje dla tego repo

Heurystyka z modułu 10 dalej działa: *jeśli najtrudniejsze pytanie to „jakie
pola ma formularz”, to CRUD; jeśli „co ma się stać, gdy…”, to model.*
Pomiary dokładają drugie pytanie: *czy reguły dotyczą jednego obiektu, czy
kilku naraz, i czy pomyłka kosztuje pieniądze?*

| Część systemu | Wybór | Dlaczego (z tego porównania) |
|---|---|---|
| **Katalog** (Product, Brand, Category, Review), panel edycji | zwykły Laravel (CRUD, `updateWithOutbox`) | brak reguł do ochrony; i DDD, i DDD-lite dałyby kod bez zysku |
| **Oferty sprzedawców** (ten portal) | **DDD-lite wystarczyłby.** Pełne DDD zostaje wersją główną (`portal-sprzedawcy`, potem `main`), bo już jest, jest przetestowane i służy do nauki | reguły dotyczą jednego agregatu naraz; nowa reguła kosztuje tyle samo; HTTP identyczne; połowa kodu. Gdybym zaczynał od zera z 1–2 osobami, wybrałbym lite **z trzema poprawkami**: przywracanie atrybutów po konflikcie wersji, `CHECK` w bazie zamiast walidacji przy odczycie, `updateWithOutbox` niedostępny na `Offer` (np. osobny trait bez tej metody) |
| **Zamówienia** (podprojekt C: rezerwacja stanu, płatność, anulowanie, zwroty) | **pełne DDD dla agregatu `Order`**, z tym samym schematem co portal (repozytorium z kontrolą wersji, tłumaczenie zdarzeń na outbox) | reguły obejmują kilka agregatów naraz (zamówienie ↔ stan oferty), są procesy w czasie (rezerwacja wygasa, płatność wraca) i pomyłka kosztuje pieniądze. Dokładnie tu lite przecieka: brudna instancja po konflikcie, auto-zapis każdej metody (nie da się złożyć „zarezerwuj stan + utwórz zamówienie” w jedną zmianę bez ręcznego `DB::transaction` i dwóch wersji) i niesprawdzane wiersze. Siatka stanów zamówienia (płatność × wysyłka × anulowanie) to setki przypadków — 0,3 ms vs 11 ms na test robi różnicę |

**Uczciwie:** gdyby celem było tylko dowieźć portal sprzedawcy, pełne DDD
było przepłacone o ~2200 linii. Te linie kupiły trzy rzeczy, które dało się
zmierzyć (rozdział 6: odporność instancji po błędzie, walidacja wierszy,
testy w milisekundach) i jedną, której nie da się zmierzyć jedną gałęzią:
model, który przenosi się na zamówienia bez przepisywania.

---

## 8. Jak odtworzyć pomiary

Wszystko z `apps/catalog` na hoście (Herd PHP 8.5, SQLite w pamięci). Kontener
`catalog-app` montuje główny checkout, więc po przełączeniu gałęzi wróć na
`portal-sprzedawcy`.

```bash
# 1. pliki i linie per warstwa, bez przełączania gałęzi
ref=ddd-lite-porownanie   # albo portal-sprzedawcy
git ls-tree -r --name-only ${ref} -- apps/catalog/src/SellerPortal/Domain | grep '\.php$' \
  | while read f; do git show "${ref}:${f}"; done | wc -l
#    (w zsh pisz ${ref}:, nie $ref: — `:a`, `:h` itd. to modyfikatory zmiennej)

# 2. czas testów reguł (5 przebiegów, Pest w trybie agenta podaje duration_ms)
git switch portal-sprzedawcy && php artisan test --compact \
  tests/Unit/SellerPortal/Domain/{OfferLifecycle,OfferPrice,OfferStock,PriceChangePolicy,Seller,SuspensionReason}Test.php
git switch ddd-lite-porownanie && composer dump-autoload && php artisan test --compact tests/Feature/Lite

# 3. miejsca obejścia
grep -rn "Offer::query()\|->update(\|->save()\|::create(" app src
grep -rn "DB::table('offers')\|DB::table('sellers')" app src | grep -v ReadModel

# 4. koszt nowej reguły
git diff --stat ddd-lite-porownanie~1 ddd-lite-porownanie

# 5. cały catalog
php artisan test --compact

git switch portal-sprzedawcy && composer dump-autoload
```

---

## 9. Twoja kolej

1. **Załataj dziurę nr 1 w lite** (instancja po `ConcurrencyConflict`).
   Najpierw napisz test z sondy „`save()` po konflikcie nadpisuje cudzą
   zmianę” w `tests/Feature/Lite/OfferRulesTest.php`, zobacz, jak przechodzi
   (czyli dziura jest), potem popraw `SavesWithVersionCheck` i odwróć
   asercję.
2. **Dodaj `CHECK` w migracji** dla pary `status`/`suspension_kind` i sprawdź
   na obu gałęziach, co się dzieje z sondą 3. Który styl teraz wygrywa?
3. **Zrób ćwiczenie 3 z przewodnika etapu E na obu gałęziach**
   („spadek ceny o >50% do akceptacji, o 30–50% nie”) i porównaj
   `git diff --stat`. Czy wynik potwierdza rozdział 4?
4. **Napisz na kartce** listę reguł zamówienia (rezerwacja, wygaśnięcie,
   płatność, anulowanie, zwrot) i dla każdej zaznacz: jeden agregat czy
   kilka? Jeśli większość to „kilka”, wiesz, czemu tabela w rozdziale 7
   mówi „pełne DDD”.

Pytania kontrolne:

- Dlaczego test HTTP z dekoratorem repozytorium padł na lite, choć zachowanie aplikacji było to samo, i co to mówi o testach podmieniających porty?
- Skoro w obu gałęziach da się zapisać ofertę z pominięciem reguł, co *konkretnie* daje pełne DDD w ochronie reguł?
- Dlaczego koszt nowej reguły wyszedł taki sam, a koszt nowej kolumny nie?
- Kiedy 11 ms na test zaczyna mieć znaczenie, a kiedy nie?
