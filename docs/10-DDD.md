# 10 — Domain-Driven Design od zera, na portalu sprzedawcy

Format każdego modułu (jak w [`03-SCIEZKA-NAUKI.md`](03-SCIEZKA-NAUKI.md)):
- **Cel**: co będziesz umiał po module,
- **Teoria**: co to jest i *po co powstało* (problem, nie definicja),
- **W projekcie**: gdzie dokładnie to siedzi w tym repo (`plik:linia`) i dlaczego tak,
- **Ćwiczenia**: w tym „zepsuj i napraw” z dokładną komendą i testem, który ma zrobić się czerwony,
- **Sprawdź się**: pytania, na które odpowiadasz bez zaglądania.

Moduły 1–3 to **strategia** (jak podzielić problem i rozmawiać o nim). Moduły
4–9 to **taktyka** (jak zapisać model w kodzie). Moduł 10 mówi, kiedy całą
resztę odpuścić. Kolejność jest celowa: większość zespołów, które „robiły DDD”
i się sparzyły, zaczęła od taktyki (repozytoria, agregaty, value objecty
wszędzie) i nigdy nie zadała pytania strategicznego, czy ten kawałek systemu
w ogóle na to zasługuje.

Materiał jest zbudowany na prawdziwym module: `apps/catalog/src/SellerPortal`
(etap „Portal sprzedawcy”, przewodnik: [`blog/etap-e-portal-sprzedawcy-ddd.md`](blog/etap-e-portal-sprzedawcy-ddd.md)).
Obok leżą słownik [`11-SELLER-PORTAL-SLOWNIK.md`](11-SELLER-PORTAL-SLOWNIK.md)
i decyzje architektoniczne [`adr/`](adr/). Spec, z którego wszystko wyszło:
[`superpowers/specs/2026-10-15-portal-sprzedawcy-ddd-design.md`](superpowers/specs/2026-10-15-portal-sprzedawcy-ddd-design.md).

> **Ścieżki.** Wszystkie odwołania `plik:linia` są względne wobec korzenia
> repozytorium i zostały sprawdzone na gałęzi `portal-sprzedawcy` (2026-10-15).
> Linie dryfują przy każdej zmianie, więc zawsze podaję też symbol (klasę,
> metodę, nazwę testu). Gdy numer się nie zgadza, szukaj po symbolu:
> `grep -n "function changePrice" apps/catalog/src/SellerPortal/Domain/Offer/Offer.php`.

### Jak uruchamiać testy w ćwiczeniach

Zawsze **na hoście**, z katalogu `apps/catalog` (PHP z Herda). `phpunit.xml`
wymusza SQLite w pamięci. Nigdy nie uruchamiaj testów w kontenerze: tam
trafiłyby w prawdziwą bazę deweloperską (RUNBOOK [#018](RUNBOOK.md#018),
[#026](RUNBOOK.md#026)).

```bash
cd apps/catalog
php artisan test --compact tests/Unit/SellerPortal/Domain        # sama domena: bez bazy, bez frameworka
php artisan test --compact tests/Unit/SellerPortal               # domena + warstwa aplikacji (repozytoria w pamięci)
php artisan test --compact tests/Arch                            # reguły zależności między warstwami
php artisan test --compact tests/Feature/SellerPortal            # SQL, outbox, joby, HTTP
# cały portal naraz (tak sprawdzane są wszystkie „zepsuj i napraw” poniżej, ~2–4 s):
php artisan test --compact tests/Unit/SellerPortal tests/Feature/SellerPortal tests/Arch
```

**Protokół „zepsuj i napraw”:** wprowadź zmianę z bloku `diff` → uruchom cały
portal → przeczytaj, *które* testy są czerwone i *dlaczego* (zanim zajrzysz
do odpowiedzi) → cofnij przez `git checkout -- <plik>`. Niczego z tego nie
commitujesz. Każde zepsucie poniżej zostało wykonane naprawdę, a lista
czerwonych testów pochodzi z tego przebiegu. Jeśli u Ciebie czerwone jest
coś innego, to też jest lekcja: znaczy, że ktoś zmienił testy albo kod od
2026-10-15.

---

## MODUŁ 1 — Język wszechobecny i Event Storming: jak powstaje model

**Cel:** umiesz przeprowadzić Event Storming dla nowej funkcji i dojść z niego
do agregatów, komend i polityk. Rozumiesz, dlaczego nazwy w kodzie to decyzja
modelowa, a nie kosmetyka.

**Teoria**

1. **Model to nie diagram klas.** Model to *uproszczenie rzeczywistości, które
   pozwala odpowiadać na pytania biznesu*. „Czy po odblokowaniu sprzedawcy jego
   oferty wracają do sprzedaży?” to pytanie modelowe. Jeśli kod nie ma na nie
   jednoznacznej odpowiedzi w jednym miejscu, model jest dziurawy, nawet gdy
   wszystkie testy są zielone.
2. **Język wszechobecny (ubiquitous language).** Eksperci domeny, programiści,
   UI, testy i kod używają tych samych słów w tym samym znaczeniu. Nie chodzi
   o uprzejmość. Każde tłumaczenie („biznes mówi *wyprzedana*, a w kodzie to
   `active=false and qty<1`”) to miejsce, w którym gubi się reguła. U nas kod
   jest po angielsku, a rozmowa po polsku, więc słownik
   ([`11-SELLER-PORTAL-SLOWNIK.md`](11-SELLER-PORTAL-SLOWNIK.md)) jest
   **mapowaniem 1:1**, a nie luźnym tłumaczeniem: „wyprzedana” to zawsze
   `Offer::isSoldOut()`, nigdy coś innego.
3. **Język obowiązuje w granicy kontekstu** (moduł 2). „Oferta” w portalu
   sprzedawcy i „oferta” w dokumencie wyszukiwarki to dwa różne pojęcia
   i to jest w porządku.
4. **Event Storming** (Alberto Brandolini) to warsztat, na którym model
   *powstaje*. Ludzie z biznesu i z IT stoją przed długą ścianą z papierem
   i kolorowymi karteczkami. Zaczyna się od **zdarzeń domenowych**, bo to
   najłatwiejsza rzecz, na jaką zgodzi się każdy: „co się wydarzyło, co jest
   ważne dla biznesu?” Czas przeszły, język biznesu: *Oferta opublikowana*,
   a nie *publishOffer()*.

   Kolory (konwencja, której warto się trzymać):

   | Karteczka | Kolor | Pytanie | Przykład |
   |---|---|---|---|
   | Zdarzenie domenowe | pomarańczowa | co się stało? | *Sprzedawca zablokowany* |
   | Komenda | niebieska | co to wywołało? | *Zablokuj sprzedawcę* |
   | Aktor | żółta mała | kto to zrobił? | *Moderator* |
   | Polityka | fioletowa | „za każdym razem, gdy X, to Y” | *Gdy sprzedawca zablokowany → zawieś jego oferty* |
   | Agregat | żółta duża | kto pilnuje reguły, zanim zdarzenie zajdzie? | *Oferta* |
   | Model odczytu | zielona | co aktor musi widzieć, żeby podjąć decyzję? | *Kolejka zmian cen* |
   | System zewnętrzny | różowa szeroka | z czym rozmawiamy? | *Wyszukiwarka* |
   | Hot spot | czerwona/różowa pod kątem | tu się kłócimy / nie wiemy | *Czy odblokowanie przywraca WSZYSTKIE oferty?* |

5. **Trzy poziomy.** *Big picture* (cały biznes, tylko zdarzenia i hot
   spoty, chaos dozwolony), *process level* (jeden proces, dochodzą komendy,
   aktorzy, polityki, modele odczytu) i *design level* (dochodzą agregaty,
   czyli granice spójności). Model w kodzie jest zapisem poziomu trzeciego.
6. **Hot spoty są najcenniejsze.** Każda czerwona karteczka to reguła
   biznesowa, której nikt jeszcze nie spisał. Jeśli zostawisz ją
   nierozstrzygniętą, rozstrzygnie ją programista o 23:00 w ifie kontrolera.

**W projekcie: Event Storming portalu sprzedawcy, tak jak wyglądałby na ścianie**

Tak doszliśmy do modelu w specu. Odtwarzam to krok po kroku, bo kolejność
kroków jest ważniejsza od wyniku.

*Krok 1 — chaos: każdy wiesza zdarzenia, które zna (bez kolejności, bez dyskusji).*

> Sprzedawca zgłosił się · Konto zatwierdzone · Konto odrzucone · Oferta
> wystawiona · Oferta opublikowana · Cena zmieniona · Cena czeka na
> akceptację · Zmiana ceny zaakceptowana · Zmiana ceny odrzucona · Towar się
> skończył · Towar wrócił · Oferta zawieszona · Oferta przywrócona · Oferta
> wycofana · Sprzedawca zablokowany · Sprzedawca odblokowany · Oferta
> pojawiła się w wyszukiwarce · Oferta zniknęła z wyszukiwarki

*Krok 2 — oś czasu i zdarzenia przełomowe (pivotal events).* Układamy od lewej
do prawej i zaznaczamy zdarzenia, po których „świat wygląda inaczej”. Tu
wychodzą dwa przełomy: **Konto zatwierdzone** (od tej chwili wolno sprzedawać)
i **Oferta opublikowana** (od tej chwili kupujący ją widzi). Wszystko, co dzieje
się z ceną i stanem, ma inne reguły przed publikacją i po niej. To jest
pierwszy ślad, że cena w szkicu nie potrzebuje strażnika.

```
 SPRZEDAWCA                         OFERTA
 Sprzedawca zgłosił się             Oferta wystawiona (szkic)
   ├─ Konto odrzucone (koniec)        ║ Oferta opublikowana  ◀── przełom: widzi ją kupujący
   ║ Konto zatwierdzone ◀── przełom   ├─ Cena zmieniona / Cena czeka na akceptację
   ├─ Sprzedawca zablokowany          │     ├─ Zmiana zaakceptowana / odrzucona / anulowana
   └─ Sprzedawca odblokowany          ├─ Towar się skończył / Towar wrócił
                                      ├─ Oferta zawieszona / przywrócona
                                      └─ Oferta wycofana (koniec)
```

*Krok 3 — komendy i aktorzy.* Przed każdym zdarzeniem: co je wywołało i kto.

| Aktor | Komenda (niebieska) | Zdarzenie (pomarańczowe) |
|---|---|---|
| Użytkownik | Zgłoś się jako sprzedawca | Sprzedawca zgłosił się |
| Moderator | Zatwierdź / Odrzuć (z powodem) | Konto zatwierdzone / odrzucone |
| Moderator | Zablokuj (z powodem) / Odblokuj | Sprzedawca zablokowany / odblokowany |
| Sprzedawca | Wystaw ofertę na produkt | Oferta wystawiona |
| Sprzedawca | Opublikuj | Oferta opublikowana |
| Sprzedawca | Zmień cenę | Cena zmieniona **albo** Cena czeka na akceptację |
| Moderator | Zaakceptuj / Odrzuć zmianę ceny | Zmiana zaakceptowana / odrzucona |
| Sprzedawca | Anuluj zgłoszoną zmianę ceny | Zmiana ceny anulowana |
| Sprzedawca | Zmień stan magazynowy | Stan zmieniony / Towar się skończył / Towar wrócił |
| Moderator | Zawieś (z notatką) / Przywróć | Oferta zawieszona / przywrócona |
| Sprzedawca | Wycofaj | Oferta wycofana |

Zauważ dwie rzeczy. Jedna komenda może dać *różne* zdarzenia (*Zmień cenę*
→ zmieniona albo czeka). To znaczy, że ktoś po drodze podejmuje decyzję według
reguły, i ten ktoś będzie agregatem. Druga: „Zmiana ceny anulowana” nie
wisiała na ścianie od początku. Wyszła dopiero przy implementacji i spec ją
pominął (to jedyne świadome odstępstwo od specu w zdarzeniach, opisane
w globalnych ograniczeniach planu). Ściana nigdy nie jest kompletna.

*Krok 4 — polityki (fioletowe).* Szukamy zdań „za każdym razem, gdy…”.

- *Gdy sprzedawca zablokowany → zawieś wszystkie jego opublikowane oferty.*
- *Gdy sprzedawca odblokowany → przywróć oferty.*
- *Gdy oferta opublikowana / cena zmieniona / towar się skończył → zaktualizuj wyszukiwarkę.*

*Krok 5 — hot spoty (tu zaczyna się prawdziwe modelowanie).* Każdy z nich
zamienił się w regułę w kodzie i w test:

| Hot spot | Rozstrzygnięcie | Gdzie żyje |
|---|---|---|
| „Odblokowanie przywraca **wszystkie** zawieszone oferty? Także te, które moderator zawiesił za oszustwo?” | Nie. Powód zawieszenia ma **rodzaj**: moderacja albo blokada sprzedawcy. Przywracamy tylko te drugie. | `SuspensionKind`, `SuspensionReason` |
| „Wyprzedana to osobny status?” | Nie, stan pochodny: opublikowana i stan 0. | `Offer::isSoldOut()`, [ADR-0004](adr/0004-wyprzedana-jako-stan-pochodny.md) |
| „Do limitu 5 zmian na dobę liczą się akceptacje moderatora?” | Nie. Liczy się klikanie sprzedawcy: zmiany wprowadzone i zgłoszone. | `Offer::changePrice`, `PriceChangePolicy` |
| „+30% to już do akceptacji czy jeszcze nie?” | Ściśle więcej niż 30% i liczone od **obecnej** ceny. | `Money::differsByMoreThanPercent` |
| „Zablokowany sprzedawca może cokolwiek?” | Tylko wycofać ofertę. | `SellerOfferAccess::sellerActions` |
| „Zablokuj → odblokuj w 2 sekundy: co z ofertami?” | Nic nie może zostać zawieszone. | `SuspendOffersOfBlockedSeller::run` sprawdza status w chwili wykonania |
| „Dwie oferty tego samego sprzedawcy na ten sam produkt?” | Najwyżej jedna żywa. | moduł 9 |

*Krok 6 — agregaty (design level).* Grupujemy komendy i zdarzenia wokół tego,
**kto musi znać stan, żeby podjąć decyzję**. „Zmień cenę” potrzebuje: obecnej
ceny, statusu, oczekującej zmiany, historii zmian z doby. To jest jedna
oferta, więc **Oferta** jest agregatem. „Zablokuj” potrzebuje tylko statusu
konta, więc **Sprzedawca** jest drugim agregatem. Kuszące byłoby „Sprzedawca
z listą ofert”, bo przecież blokada dotyka ofert. To pułapka, rozbrojona
w module 5: polityka zawiesza oferty *po* blokadzie, każdą osobno.

*Krok 7 — modele odczytu (zielone).* Co aktor musi widzieć, żeby wydać
komendę: pulpit sprzedawcy z licznikami, lista ofert z filtrem „wyprzedane”,
kolejka zmian cen dla moderatora z różnicą w %, oś czasu oferty. W kodzie to
`SellerPortal\Infrastructure\ReadModel\*`: zapytania SQL zwracające tablice,
bez agregatów (moduł 7, CQRS).

Mapa ściany na kod (jedna linijka, jeden plik):

| Na ścianie | W kodzie |
|---|---|
| zdarzenie „Oferta opublikowana” | `apps/catalog/src/SellerPortal/Domain/Offer/Event/OfferPublished.php` |
| komenda „Zmień cenę” | `apps/catalog/src/SellerPortal/Application/Offer/ChangeOfferPrice.php` + `ChangeOfferPriceHandler.php` |
| polityka „Gdy zablokowany → zawieś” | `apps/catalog/src/SellerPortal/Application/Policy/QueueSuspensionWhenSellerBlocked.php` |
| agregat „Oferta” | `apps/catalog/src/SellerPortal/Domain/Offer/Offer.php:49` (`final class Offer`) |
| model odczytu „Kolejka zmian cen” | `apps/catalog/src/SellerPortal/Infrastructure/ReadModel/PriceChangeQueue.php` |
| hot spot „rodzaj zawieszenia” | `apps/catalog/src/SellerPortal/Domain/Offer/SuspensionKind.php:10` |

I miejsca, gdzie język wprost żyje w kodzie:
- `apps/catalog/src/SellerPortal/Domain/Offer/Offer.php:36`: docblock wyjaśnia, dlaczego „wyprzedana” nie jest statusem,
- `apps/catalog/src/SellerPortal/Domain/Offer/OfferStatus.php:11`: cztery statusy, ani jednego więcej,
- `apps/catalog/app/Http/DomainErrorMessages.php:19`: kody reguł domeny (`price_change_limit`) zamienione na zdania z języka użytkownika („Wykorzystano limit 5 zmian ceny na dobę”). Domena mówi kodem, UI zdaniem.

**Ćwiczenia**

1. Przejdź UI sprzedawcy (`/seller`) i moderatora (`/panel/sellers`,
   `/panel/moderation/price-changes`). Wypisz każde słowo z etykiet, toastów
   i komunikatów błędów. Dla każdego znajdź pojęcie w słowniku i symbol w kodzie.
   Słowo bez pary w kodzie to albo dziura w modelu, albo zbędne słowo w UI.
2. **Event Storming na sucho, zamówienia (podprojekt C).** Zanim powstanie
   jedna linijka kodu, rozpisz krok 1–6 dla „kupujący zamawia z oferty”.
   Gwarantowane hot spoty: rezerwacja stanu (kiedy zmniejszamy `stock`?),
   oferta wycofana w trakcie płacenia, zmiana ceny między koszykiem a płatnością.
   Który z nich zmieni agregat `Offer`, a który da nowy agregat?
3. **Zepsuj i napraw: hot spot ze ściany jest testem.** Hot spot „odblokowanie
   nie przywraca ofert zawieszonych przez moderatora” wyrzuć z polityki
   przywracania:

   ```diff
   --- apps/catalog/src/SellerPortal/Application/Policy/ReinstateOffersOfUnblockedSeller.php
   -        while ($ids = $this->offers->idsForSellerPolicy($sellerId, OfferStatus::Suspended, SuspensionKind::SellerBlocked, $afterId, self::BATCH_SIZE)) {
   +        while ($ids = $this->offers->idsForSellerPolicy($sellerId, OfferStatus::Suspended, null, $afterId, self::BATCH_SIZE)) {
   ...
   -                            if ($offer->status() !== OfferStatus::Suspended || $offer->suspension()?->kind() !== SuspensionKind::SellerBlocked) {
   +                            if ($offer->status() !== OfferStatus::Suspended) {
   ```

   Uruchom cały portal. Czerwone są **trzy** testy na trzech poziomach:
   `SellerPoliciesTest` › „odblokowanie przywraca tylko oferty zawieszone przez
   blokadę” (aplikacja, w pamięci), `SellerPolicyJobsTest` › to samo przez job
   na bazie, `SellerBlockingTest` › „odblokowanie przywraca tylko zawieszone
   przez blokadę” (HTTP). Jedna reguła z jednej karteczki, trzy poziomy
   ochrony. Cofnij: `git checkout -- src/SellerPortal/Application/Policy/ReinstateOffersOfUnblockedSeller.php`.

**Sprawdź się**
- Dlaczego Event Storming zaczyna się od zdarzeń, a nie od encji albo tabel?
- Jedna komenda daje dwa różne zdarzenia. Co to mówi o modelu?
- Podaj hot spot z tego etapu, który bez warsztatu rozstrzygnąłby programista,
  i gdzie by go rozstrzygnął.
- „Wyprzedana” w UI, `isSoldOut()` w kodzie. Czy to łamie język wszechobecny?
  (Nie: słownik mapuje 1:1. Złamaniem byłoby, gdyby UI liczyło „wyprzedaną” po swojemu.)

---

## MODUŁ 2 — Poddomeny i bounded contexts

**Cel:** wiesz, w którą część systemu inwestować model, a którą zostawić
prostą, i umiesz wskazać granicę kontekstu w kodzie.

**Teoria**

1. **Poddomena** to kawałek *problemu biznesowego* (przestrzeń problemu).
   Trzy rodzaje:
   - **core**: to, czym firma wygrywa z konkurencją. Tu złożoność biznesowa
     jest powodem istnienia systemu, więc tu warto inwestować w model.
   - **supporting**: potrzebne, specyficzne dla firmy, ale nie wyróżnia jej.
     Robimy najprościej, jak się da.
   - **generic**: każdy ma to samo (logowanie, płatności, wysyłka maili).
     Kupujemy albo bierzemy gotowe.
2. **Bounded context** to granica *rozwiązania* (przestrzeń rozwiązania):
   obszar, w którym jeden model i jeden język mają jednoznaczne znaczenie.
   Poddomena i kontekst często się pokrywają, ale nie muszą.
3. **Kiedy dzielić konteksty.** Gdy rozjeżdża się język albo model (to samo
   słowo znaczy co innego, inne reguły dla tej samej rzeczy). **Nie** dzielimy,
   bo „dochodzi nowy aktor” albo „to inny zespół w UI”.
4. **Kontekst ≠ mikroserwis.** Kontekst to granica modelu. Może żyć jako
   przestrzeń nazw w monolicie (u nas), jako moduł albo jako usługa. Ta
   decyzja jest osobna i zwykle późniejsza ([ADR-0001](adr/0001-modularny-monolit.md)).

**W projekcie**

| Poddomena | Typ | Styl w kodzie | Gdzie |
|---|---|---|---|
| Oferty sprzedawców: cykl życia, cena, moderacja, konta | **core** | pełne DDD | `apps/catalog/src/SellerPortal/` |
| Katalog produktów: Product, Brand, Category, Review | supporting | Laravel way (Eloquent, kontroler) | `apps/catalog/app/Models/`, `apps/catalog/app/Http/Controllers/Panel/ProductController.php` |
| Wyszukiwarka | core, ale złożoność **techniczna** | Symfony + ES, bez modelu domenowego | `apps/search/` |
| Tożsamość (`users`, Fortify) | generic | gotowiec | `apps/catalog/app/Models/User.php` |

Najlepsza ilustracja kontekstów w tym repo to słowo **„oferta”**, które ma
trzy znaczenia w trzech miejscach:

| Kontekst | Czym jest oferta | Kod |
|---|---|---|
| Seller Portal (zapis) | agregat z cyklem życia, strażnikiem ceny, regułami moderacji | `apps/catalog/src/SellerPortal/Domain/Offer/Offer.php:49` |
| Katalog (odczyt) | wiersz tabeli do pokazania i do projekcji; jedyna reguła to „widoczna” | `apps/catalog/app/Models/Offer.php:63` (scope `listed()`) |
| Wyszukiwarka | zagnieżdżony obiekt w dokumencie produktu: cena, sprzedawca, `in_stock` | `apps/catalog/app/Http/Controllers/Internal/ProductProjectionController.php:48` (`$product->listedOffers`) |

Gdybyśmy na siłę zrobili *jeden* model „Offer” dla wszystkich trzech, Eloquent
z projekcji musiałby znać strażnika ceny, a agregat musiałby umieć się
zserializować do ES. Trzy modele to nie duplikacja, tylko trzy odpowiedzi na
trzy różne pytania.

Decyzje z tego etapu:
- **Moderacja nie jest osobnym kontekstem.** Decyzje moderatora zmieniają stan
  tego samego agregatu `Offer` i podlegają tym samym niezmiennikom (np.
  `approvePriceChange` wymaga oczekującej zmiany: `apps/catalog/src/SellerPortal/Domain/Offer/Offer.php:284`).
  Osobny kontekst dałby dwa modele jednej oferty do synchronizacji.
- **Granica jest w kodzie, nie w infrastrukturze.** Osobny katalog bazowy
  `apps/catalog/src/` (PSR-4 `SellerPortal\`), żeby „to nie jest kod
  frameworka” było widać w drzewie plików. Pilnuje tego test architektury:
  `apps/catalog/tests/Arch/SellerPortalLayersTest.php:3` (domena) i `:7` (aplikacja).

**Ćwiczenia**

1. Wypisz wszystkie tabele bazy `catalog` i przypisz każdą do kontekstu. Które
   tabele mają **jednego** właściciela zapisu (`offers`, `sellers`,
   `offer_price_changes` → tylko repozytoria Seller Portalu), a które nie?
2. Znajdź w `apps/search` miejsce, gdzie search „rozumie” ofertę. Czy zna
   słowo `suspended`? (Nie, i nie musi: zna tylko wynik projekcji.)
3. **Zepsuj i napraw: framework wchodzi do domeny.** W `Money` użyj czegoś
   z Laravela:

   ```diff
   --- apps/catalog/src/SellerPortal/Domain/Shared/Money.php
   +use Illuminate\Support\Str;
   +
    final readonly class Money
   ...
   +        Str::length('x');
            return new self($cents, $currency);
   ```

   ```bash
   php artisan test --compact tests/Arch
   ```

   Czerwony: `SellerPortalLayersTest` › „domena nie zna frameworka ani innych
   warstw”, komunikat `Expecting 'SellerPortal\Domain' not to use 'Illuminate'.`
   Cofnij: `git checkout -- src/SellerPortal/Domain/Shared/Money.php`.
   Pytanie do przemyślenia: dlaczego to ma być test w CI, a nie zasada
   w README? (Bo zasady w README łamie się w piątek o 18:00.)

**Sprawdź się**
- Czym różni się poddomena od bounded contextu? Podaj przykład z tego repo,
  w którym się pokrywają, i taki, w którym nie.
- Dlaczego wyszukiwarka jest „core”, a nie ma modelu domenowego?
- Dodajemy rolę „księgowy sprzedawcy”, który widzi tylko ceny. Nowy kontekst? Uzasadnij.

---

## MODUŁ 3 — Mapa kontekstów

**Cel:** rysujesz relacje między kontekstami i nazywasz je wzorcami, które
mówią, kto się do kogo dostosowuje i gdzie stoi tłumacz.

**Teoria**

Relacja ma kierunek: **upstream** (dostawca modelu) i **downstream**
(odbiorca). Wzorce mówią, kto płaci za zmiany:

- **Conformist**: downstream bierze model upstreamu, jaki jest. Tanio, ale
  zmiana u dostawcy uderza w nas.
- **Anti-Corruption Layer (ACL)**: downstream stawia tłumacza, żeby cudzy
  model nie przeciekał do jego modelu. Drożej, ale izoluje.
- **Open Host Service (OHS)**: upstream wystawia stabilne, udokumentowane API
  dla wielu odbiorców, zamiast dopasowywać się do każdego.
- **Published Language**: wspólny, udokumentowany format wymiany (schemat
  zdarzeń, JSON). Zwykle idzie w parze z OHS.
- Do tego: Shared Kernel (wspólny kawałek modelu, wymaga koordynacji),
  Customer/Supplier (downstream ma wpływ na plan upstreamu), Separate Ways
  (nie integrujemy się wcale).

**W projekcie**

```
Tożsamość (users) ──[Conformist]──▶ Seller Portal
                                       │  jedyny zapisujący: offers, sellers, offer_price_changes
                                       │
                                       ├──[Published Language: outbox offer.*]──▶ RabbitMQ ──▶ Search
                                       │                                                       │
Katalog (products…) ◀──[port ProductCatalog, odczyt]──┘                                         │
Katalog ──[Open Host Service: /internal/products/{id}/projection]──[ACL: CatalogProjectionClient]┘
```

| Relacja | Wzorzec | Kod |
|---|---|---|
| Seller Portal ← Tożsamość | **Conformist**: bierzemy `users.id` jak jest, opakowane w `UserId` | `apps/catalog/src/SellerPortal/Domain/Shared/UserId.php` |
| Seller Portal → Katalog | **port po naszej stronie** (lekki ACL): pytamy tylko „czy produkt istnieje”, interfejs definiuje Seller Portal | `apps/catalog/src/SellerPortal/Application/Port/ProductCatalog.php:11`, implementacja `apps/catalog/src/SellerPortal/Infrastructure/DbProductCatalog.php:13` |
| Seller Portal → Search | **Published Language**: nazwy `offer.*` i kształt payloadu to kontrakt | `apps/catalog/src/SellerPortal/Infrastructure/Persistence/OfferIntegrationEvents.php:90` (`translate`) |
| Katalog → Search | **Open Host Service**: projekcja produktu | `apps/catalog/routes/api.php:23`, `apps/catalog/app/Http/Controllers/Internal/ProductProjectionController.php:25` |
| Search ← Katalog | **ACL** po stronie searcha: jedyna klasa, która zna format projekcji | `apps/search/src/Service/CatalogProjectionClient.php:20` |

Dlaczego port `ProductCatalog` jest zdefiniowany *po stronie Seller Portalu*:
gdyby katalog stał się osobną usługą, zmienia się jedna klasa
(`DbProductCatalog` → klient HTTP), a domena i handlery nawet tego nie zauważą.
Koszt: jedna metoda interfejsu i jedna klasa na 19 linii.

**Uczciwie o tym, co tu jest skrótem.** Seller Portal i katalog dzielą jedną
bazę, a `offers.product_id` ma klucz obcy do `products`. W prawdziwym
rozdzieleniu na usługi tego klucza by nie było. Świadomie płacimy tym
sprzężeniem za prostotę (jeden deploy, jedna transakcja dla stanu i outboxu),
patrz [ADR-0001](adr/0001-modularny-monolit.md).

Druga rzecz, której **nie** ma na mapie: zdarzenia sprzedawcy. Search ma
binding `seller.#`, ale Seller Portal nic na ten temat nie publikuje. Search
dowiaduje się o blokadzie z `offer.suspended` każdej oferty
([ADR-0003](adr/0003-zdarzenia-sprzedawcy-wewnetrzne.md)). Gdyby jakiś
`seller.*` jednak przyszedł, handler loguje ostrzeżenie zamiast go po cichu
zgubić: `apps/search/src/MessageHandler/ProductSyncHandler.php:47`.

**Ćwiczenia**

1. Narysuj mapę od nowa z pamięci, ze strzałkami upstream/downstream.
   Porównaj z powyższą.
2. Katalog przechodzi do osobnej usługi z własną bazą. Wypisz każdą zmianę
   w Seller Portalu. (Powinny wyjść: `DbProductCatalog`, klucz obcy
   `offers.product_id`, `ProductLookup` w modelach odczytu. Jeśli wychodzi Ci
   coś w `Domain/`, coś jest nie tak.)
3. **Zepsuj i napraw: złam Published Language.** Zmień nazwę zdarzenia
   integracyjnego, tak jakby ktoś „poprawiał nazewnictwo”:

   ```diff
   --- apps/catalog/src/SellerPortal/Infrastructure/Persistence/OfferIntegrationEvents.php
   -            $event instanceof OfferPriceChanged => ['offer.price_changed', [
   +            $event instanceof OfferPriceChanged => ['offer.price_updated', [
   ```

   Uruchom cały portal. Czerwone: `OfferIntegrationEventsTest` › „payload
   offer.price_changed ma product_id, old/new_price_cents i listed” oraz
   „zmiana ceny i stanu w jednym zapisie: dwa wiersze outboxu z tym samym
   sequence”. Testy HTTP zostają zielone, bo nie patrzą na nazwy zdarzeń.
   Nasz search przeżyłby tę zmianę (binding `offer.#`, a przy każdym
   zdarzeniu oferty i tak pobiera projekcję), ale konsument, który reaguje
   na konkretną nazwę, przestałby dostawać zmiany cen *bez żadnego błędu*.
   Dlatego klasa domenowa
   `OfferPriceChanged` może się nazywać jak chce, a napis `offer.price_changed`
   nie. Cofnij: `git checkout -- src/SellerPortal/Infrastructure/Persistence/OfferIntegrationEvents.php`.

**Sprawdź się**
- Czym ACL różni się od zwykłego klienta HTTP?
- Dlaczego relacja z tożsamością to Conformist, a nie ACL? Kiedy to by się zmieniło?
- OHS i Published Language: który wzorzec dotyczy *kanału*, a który *formatu*?

---

## MODUŁ 4 — Value objects

**Cel:** zamiast walidować to samo w pięciu miejscach, robisz typy, których
niepoprawnej wartości nie da się utworzyć.

**Teoria**

1. **Value object** nie ma tożsamości. Dwie kwoty 100 zł są *tą samą* kwotą.
   Porównuje się wartości, nie referencje. Jest niezmienny: „zmiana” to nowy
   obiekt.
2. **Sam się waliduje przy tworzeniu.** Prywatny konstruktor plus fabryka,
   która odmawia złych danych. Skutek: każdy `Money` w systemie jest poprawny
   *z definicji*, więc agregaty nie walidują pól, tylko pilnują reguł.
3. **Primitive obsession** to antywzorzec, który VO leczy: `int $price` nie
   mówi, czy to grosze czy złotówki, i nie broni się przed `-5`.
4. **Zachowanie należy do wartości.** „Czy różni się o więcej niż 30%” to
   pytanie do kwoty, nie do kontrolera.
5. **Uniemożliwiaj niedozwolone kombinacje** (*make illegal states
   unrepresentable*). Zamiast `?ModeratorId` i `?Reason`, które można złożyć
   w bezsens, dwie fabryki: jedna na każdy dozwolony kształt.

**W projekcie**

- `Money`, int w groszach plus waluta: `apps/catalog/src/SellerPortal/Domain/Shared/Money.php:23`
  (`fromCents` odmawia `<= 0`). `int`, bo `0.1 + 0.2 != 0.3`, a próg 30%
  ma się rozstrzygać co do grosza.
- `Money::differsByMoreThanPercent`: `apps/catalog/src/SellerPortal/Domain/Shared/Money.php:60`.
  **Historia z tego etapu:** automatyczny przegląd bezpieczeństwa po Tasku 1
  zauważył, że punkt odniesienia był *domyślny*. Zamiana argumentów zmienia
  wynik: 10000 → 13001 to +30,01% (do akceptacji), ale 13001 → 10000 liczone
  od 13001 to ≈ 23% (przechodzi). Ktoś, kto kiedyś wywoła to odwrotnie,
  odwróci strażnika ceny. Naprawa w Tasku 4: punkt odniesienia nazwany wprost
  w docblocku, test asymetrii (`apps/catalog/tests/Unit/SellerPortal/Domain/MoneyTest.php:45`),
  walidacja procentu 0–100 i komentarz w jedynym miejscu wywołania
  (`apps/catalog/src/SellerPortal/Domain/Offer/PriceChangePolicy.php:39`).
- `Reason`, uzasadnienie decyzji człowieka: `apps/catalog/src/SellerPortal/Domain/Shared/Reason.php:18`.
  **Druga historia z tego samego przeglądu:** powód złożony z samych twardych
  spacji (U+00A0) przechodził jako niepusty, bo `trim()` ich nie zna. Naprawa:
  przycinanie po klasach Unicode `[\p{Z}\p{C}]` (linia 22), odrzucenie znaków
  sterujących poza `\n\r\t` (linia 34, bo powód przychodzi z textarea), długość
  w znakach (`mb_strlen`), nie w bajtach.
- `SuspensionReason`, dwa dozwolone kształty: `byModerator(ModeratorId, Reason)`
  (`apps/catalog/src/SellerPortal/Domain/Offer/SuspensionReason.php:25`)
  i `sellerBlocked()` (`:30`). „Blokada z moderatorem” jest nieosiągalna.
  Przy odczycie z bazy `restore` (`:42`) powtarza te niezmienniki, bo wiersz
  w bazie mógł zostać zepsuty ręcznie.
- Pozostałe: `Stock` (0–100 000), `ShippingTime` (1–30), `SellerName`
  (3–120), `City`, identyfikatory `OfferId`/`SellerId`/`ProductId`/`UserId`/`ModeratorId`
  (`apps/catalog/src/SellerPortal/Domain/`). `ModeratorId` i `UserId` to oba
  „id użytkownika”, ale typ mówi, *w jakiej roli* ktoś działa:
  `approvePriceChange(ModeratorId …)` nie przyjmie przez pomyłkę id sprzedawcy.

**Ćwiczenia**

1. Znajdź w `apps/catalog/app` (poza portalem) trzy miejsca, gdzie cena jest
   gołym `int`. Co by dał `Money`, a co by kosztował?
2. **Zepsuj i napraw: odwrócony punkt odniesienia.**

   ```diff
   --- apps/catalog/src/SellerPortal/Domain/Offer/PriceChangePolicy.php
   -        return $current->differsByMoreThanPercent($proposed, self::THRESHOLD_PERCENT)
   +        return $proposed->differsByMoreThanPercent($current, self::THRESHOLD_PERCENT)
   ```

   ```bash
   php artisan test --compact tests/Unit/SellerPortal/Domain
   ```

   Czerwone: `PriceChangePolicyTest` › „zmiana o 30% wchodzi od razu, o 30,01%
   czeka” i `OfferPriceTest` › „Published: zmiana powyżej 30% czeka, cena
   zostaje stara, liczy się do limitu”. Zauważ, że kod się kompiluje, typy
   się zgadzają, a różnica to dwa słowa. Tylko test z konkretnymi liczbami
   tego broni. Cofnij: `git checkout -- src/SellerPortal/Domain/Offer/PriceChangePolicy.php`.
3. **Zepsuj i napraw: twarda spacja.**

   ```diff
   --- apps/catalog/src/SellerPortal/Domain/Shared/Reason.php
   -        $trimmed = preg_replace('/^[\p{Z}\p{C}]+|[\p{Z}\p{C}]+$/u', '', $value);
   +        $trimmed = trim($value);
   ```

   Czerwony: `SharedValuesTest` › „Reason traktuje same twarde spacje jako
   puste” (`Exception [InvalidValue] not thrown`). Moderator mógłby odrzucić
   zgłoszenie z powodem, który wygląda na pusty. Cofnij:
   `git checkout -- src/SellerPortal/Domain/Shared/Reason.php`.

**Sprawdź się**
- Dlaczego agregat `Offer` nie sprawdza, czy cena jest dodatnia?
- `Money::equals` porównuje kwotę i walutę. Dlaczego nie wystarczy `==`?
- Kiedy VO dla jednego pola to przesada? (Gdy nie ma ani reguły, ani zachowania.
  `Condition` jest gołym enumem i to wystarcza.)

---

## MODUŁ 5 — Encje i agregaty

**Cel:** umiesz wyznaczyć granicę agregatu i bronisz zasady „jedna transakcja
zmienia jeden agregat”.

**Teoria**

1. **Encja** ma tożsamość i cykl życia. Oferta #4518 pozostaje tą samą ofertą,
   choć zmieniła cenę trzy razy.
2. **Agregat** to grupa obiektów, która musi być spójna *po każdej operacji*,
   z jednym wejściem: **korzeniem**. Z zewnątrz nie zmienia się jego wnętrza
   inaczej niż przez metody korzenia, a każda metoda pilnuje niezmienników.
3. **Reguły Vernona** (warto znać na pamięć):
   - projektuj **małe** agregaty,
   - odwołuj się do innych agregatów **przez ID**, nie przez referencję,
   - **jedna transakcja = jeden agregat**,
   - między agregatami spójność **ostateczna** (eventual consistency, moduł 8).
4. **Jak wyznaczyć granicę:** zapytaj, *jakiej reguły nie wolno złamać nawet
   na milisekundę*. Wszystko, co ta reguła musi widzieć naraz, jest w agregacie.
   Reguły, które mogą być spełnione „za chwilę”, przechodzą między agregatami.
5. **Maszyna stanów zamiast setterów.** `publish()`, `suspend()`, `withdraw()`
   zamiast `setStatus()`. Każda metoda sprawdza dozwolone przejście i zapisuje
   zdarzenie.

**W projekcie**

- `Offer`: `apps/catalog/src/SellerPortal/Domain/Offer/Offer.php:49`.
  Konstruktor prywatny (`:54`); powstaje przez `draft` (`:73`) albo
  `reconstitute` (`:95`, odczyt z bazy, bez zdarzeń).
  - Przejścia: `publish` (`:117`), `changeStock` (`:140`), `changeDetails` (`:161`),
    `suspend` (`:170`), `reinstate` (`:179`), `withdraw` (`:193`).
  - Strażnik ceny: `changePrice` (`:219`). Kolejność sprawdzeń jest stała:
    oczekująca zmiana → limit → próg.
  - `requireStatus` (`:449`) to jedno miejsce, które rzuca `InvalidOfferTransition`.
  - `allowedActionsFor(Actor)` (`:327`) to jedyne źródło prawdy dla UI, które
    przyciski pokazać. Frontend nie zna reguł, tylko renderuje listę.
  - Stany pochodne: `isListed` (`:309`) i `isSoldOut` (`:314`)
    ([ADR-0004](adr/0004-wyprzedana-jako-stan-pochodny.md)).
- `Seller`: `apps/catalog/src/SellerPortal/Domain/Seller/Seller.php:42`.
  Przejścia `approve`/`reject`/`block`/`unblock` (`:83`–`:117`).
  `canSell()` (`:123`) to pytanie, a nie wyjątek, bo wywołujący sam decyduje,
  co zrobić z odpowiedzią.
- **Fabryka w agregacie:** `Seller::draftOffer` (`:135`). Reguła „tylko
  aktywny sprzedawca wystawia” należy do tego, kto zna status, więc oferta
  zaczyna się u sprzedawcy. Sprzedawca jej jednak **nie przechowuje**.
- **Odwołanie przez ID:** `Offer` trzyma `SellerId`, nie `Seller`. Jedyny
  moment, w którym oferta potrzebuje wiedzy o sprzedawcy (publikacja), dostaje
  go jako argument tylko do odczytu: `publish(Seller $seller, …)` (`Offer.php:117`).
- **Dlaczego `Seller` nie ma kolekcji ofert** (najważniejsza decyzja w tym
  module). Kuszące, bo „blokada dotyczy ofert”. Koszty:
  1. sprzedawca z 3000 ofert to 3000 obiektów w pamięci przy *każdej*
     operacji na koncie,
  2. każda zmiana ceny dowolnej oferty zwiększa wersję sprzedawcy, więc dwie
     karty z dwiema różnymi ofertami dostają konflikt wersji (moduł 7),
  3. blokada stałaby się jedną transakcją na tysiące wierszy.

  Cena alternatywy: przez kilka sekund po blokadzie sprzedawca jest
  zablokowany, a jego oferty jeszcze widoczne. Biznes to akceptuje. To jest
  rozmowa, którą trzeba przeprowadzić z biznesem, a nie decyzja programisty.
- **Domena nie zna zegara:** każda metoda zmieniająca stan dostaje
  `DateTimeImmutable $now`. Dzięki temu test „szósta zmiana w 24 h rzuca PriceChangeLimitExceeded”
  (`apps/catalog/tests/Unit/SellerPortal/Domain/OfferPriceTest.php:80`) nie
  czeka dobę, a granica okna jest dokładna co do sekundy.

**Ćwiczenia**

1. Narysuj obie maszyny stanów z pamięci. Porównaj z docblockami w `Offer.php:32`
   i `Seller.php:30`.
2. Przeczytaj tabelę z testu `allowedActionsFor zgodne z tabelą`
   (`apps/catalog/tests/Unit/SellerPortal/Domain/OfferLifecycleTest.php:196`).
   Dlaczego moderator nie ma akcji na szkicu?
3. **Zepsuj i napraw: przejście bez strażnika.**

   ```diff
   --- apps/catalog/src/SellerPortal/Domain/Offer/Offer.php
        public function suspend(SuspensionReason $reason, DateTimeImmutable $now): void
        {
   -        $this->requireStatus([OfferStatus::Published], OfferAction::Suspend);
   -
            $this->status = OfferStatus::Suspended;
   ```

   ```bash
   php artisan test --compact tests/Unit/SellerPortal/Domain
   ```

   Czerwony: `OfferLifecycleTest` › „suspend tylko z Published; reinstate
   tylko z Suspended”. Bez tej linii moderator ze starej karty mógłby
   zawiesić ofertę, którą sprzedawca właśnie wycofał. Dostałaby status
   `suspended`, a późniejsze „przywróć” (`reinstate`: Suspended → Published)
   wystawiłoby wycofaną ofertę z powrotem na sprzedaż. Stan końcowy przestałby
   być końcowy. Cofnij: `git checkout -- src/SellerPortal/Domain/Offer/Offer.php`.

**Sprawdź się**
- Podaj regułę, która musi być spójna natychmiast, i taką, która może być spójna ostatecznie.
- Dlaczego `publish` dostaje cały obiekt `Seller`, a konstruktor oferty tylko `SellerId`?
- Co by się stało z liczbą konfliktów wersji, gdyby `Seller` trzymał oferty?

---

## MODUŁ 6 — Zdarzenia domenowe a zdarzenia integracyjne

**Cel:** odróżniasz zdarzenie, które zostaje w kontekście, od kontraktu dla
innych systemów, i wiesz, dlaczego między nimi stoi tłumacz.

**Teoria**

| | Zdarzenie domenowe | Zdarzenie integracyjne |
|---|---|---|
| Kto słucha | kod w tym samym kontekście | inne konteksty (search) |
| Kształt | klasa PHP, bogata (`OfferPriceChanged(old, new)`) | JSON w outboxie, wersjonowany kontrakt |
| Zmiana | swobodna, zwykły refaktor | ostrożna, bo łamie konsumentów |

1. **Agregat tylko zapisuje zdarzenia** (`recordThat`), nie publikuje ich.
   Gdyby publikował sam, zdarzenie mogłoby wyjść na świat, a zapis stanu się
   wycofać.
2. **Translacja jest jawna.** Klasy domenowe nigdy nie są serializowane wprost.
   Inaczej każdy refaktor nazwy klasy łamałby konsumentów.
3. **Outbox** (znany z ETAPU 6, [`05-SPOJNOSC-DANYCH.md`](05-SPOJNOSC-DANYCH.md)):
   wiersz zdarzenia zapisuje się w tej samej transakcji co stan, a osobny
   proces publikuje go do RabbitMQ.
4. **Nie każde zdarzenie domenowe zasługuje na kontrakt publiczny.** Każde
   opublikowane zdarzenie trzeba utrzymywać latami. Publikujemy to, czego
   ktoś potrzebuje.

**W projekcie**

- `apps/catalog/src/SellerPortal/Domain/Shared/AggregateRoot.php:27`:
  `recordThat`. Dwie metody odczytu: `pendingEvents` (`:35`) tylko podgląda
  (repozytorium pisze outbox, ale zapis może się jeszcze nie udać),
  `releaseEvents` (`:43`) zwraca i czyści (woła handler *po* udanym zapisie).
- 19 klas zdarzeń w `apps/catalog/src/SellerPortal/Domain/Offer/Event/`
  i `apps/catalog/src/SellerPortal/Domain/Seller/Event/`.
- Tłumacz: `apps/catalog/src/SellerPortal/Infrastructure/Persistence/OfferIntegrationEvents.php`.
  `record` (`:50`) wstawia wiersze outboxu w transakcji zapisu; `base` (`:70`)
  to stan oferty po zapisie (search potrzebuje `product_id`, `status`, `listed`);
  `translate` (`:90`) to jawna mapa klasa → nazwa `offer.*`; `default` (`:126`)
  rzuca `LogicException`. Nowe zdarzenie domenowe bez wpisu w mapie kończy
  się głośnym błędem, a nie cichym brakiem zdarzenia.
- `sequence` = nowa wersja oferty. Kilka zdarzeń z jednego zapisu dzieli ten
  sam `sequence`, bo opisują jeden stan (test: `apps/catalog/tests/Feature/SellerPortal/Infrastructure/OfferIntegrationEventsTest.php:105`).
- **Zdarzenia sprzedawcy nie idą do outboxu:**
  `apps/catalog/src/SellerPortal/Infrastructure/Persistence/SqlSellerRepository.php:23`
  (docblock klasy) i test „zapis sprzedawcy nie tworzy wiersza outboxu”
  (`apps/catalog/tests/Feature/SellerPortal/Infrastructure/SqlSellerRepositoryTest.php:64`).
  Uzasadnienie: [ADR-0003](adr/0003-zdarzenia-sprzedawcy-wewnetrzne.md).
- Odstępstwo od specu: doszło `offer.price_change_cancelled`. Anulowanie
  zmienia stan oferty (znika oczekująca cena), więc musi być zdarzeniem,
  choć spec go pominął.

**Ćwiczenia**

1. Dla każdego z 14 zdarzeń oferty odpowiedz: czy search *potrzebuje* tego
   zdarzenia? (Dziś pobiera projekcję przy każdym `offer.*`, więc
   `offer.drafted` czy `offer.price_change_requested` nic w ES nie zmieniają.
   Co by się stało, gdybyśmy ich nie publikowali, i kto mógłby ich kiedyś
   potrzebować, np. analityka?)
2. Dopisz w głowie zdarzenie `SellerRenamed`. Gdzie trzeba by je zmapować,
   gdyby search miał pokazywać nową nazwę? (To jest dokładnie fan-out
   `seller.updated`, odłożony poza zakres.)
3. **Zepsuj i napraw: zdarzenie bez tłumaczenia.**

   ```diff
   --- apps/catalog/src/SellerPortal/Infrastructure/Persistence/OfferIntegrationEvents.php
   -            $event instanceof OfferSoldOut => ['offer.sold_out', []],
   ```

   Uruchom cały portal. Czerwone: `OfferIntegrationEventsTest` › „wyprzedanie
   wstawia offer.sold_out z listed=false” (jako *error*:
   `Brak mapowania na zdarzenie integracyjne dla …OfferSoldOut`) i test HTTP
   `SellerOfferTest` › „stan 0 → wyprzedana, szczegóły i anulowanie
   oczekującej ceny”. Wyjątek wycofał całą transakcję, więc stan 0 też się
   nie zapisał. To jest zamierzone: lepiej odmówić zapisu niż zapisać stan,
   o którym search się nie dowie. Cofnij:
   `git checkout -- src/SellerPortal/Infrastructure/Persistence/OfferIntegrationEvents.php`.

**Sprawdź się**
- Dlaczego `pendingEvents` i `releaseEvents` to dwie różne metody?
- Search nie dostaje `seller.blocked`. Skąd wie, że oferty zablokowanego sprzedawcy mają zniknąć?
- Co tracisz, serializując klasę zdarzenia domenowego wprost do JSON-a?

---

## MODUŁ 7 — Repozytoria i persystencja

**Cel:** umiesz zapisać agregat z prywatnym konstruktorem bez ORM-a, pilnujesz
wersji przy współbieżnych zapisach i rozumiesz, ile to kosztuje.

**Teoria**

1. **Repozytorium** udaje kolekcję agregatów w pamięci: `get(id)`, `save(agregat)`.
   Jedno na agregat (korzeń), nie na tabelę. **Interfejs** należy do domeny,
   **implementacja** do infrastruktury.
2. **Rekonstytucja**: odtworzenie agregatu z bazy *bez* zdarzeń i bez reguł
   przejść. Nic się nie wydarzyło, tylko wczytujemy stan.
3. **Optymistyczne blokowanie**: `UPDATE … WHERE id = ? AND version = ?`.
   0 zmienionych wierszy oznacza, że ktoś zapisał między naszym odczytem
   a zapisem. Zgłaszamy konflikt zamiast nadpisać cudzą zmianę (*lost update*).
4. **Tożsamość przed zapisem**: agregat ma ID od urodzenia, bo zdarzenia
   niosą ID. Bierze się je z sekwencji bazy przed INSERT-em.
5. **CQRS w wersji lekkiej**: zapis przez agregaty, odczyt (listy, pulpit)
   zwykłym SQL-em do DTO. Nie ładujemy 50 agregatów, żeby narysować tabelę.

**W projekcie**

- Interfejs: `apps/catalog/src/SellerPortal/Domain/Offer/OfferRepository.php:11`.
- Implementacja na Query Builderze, bez Eloquenta ([ADR-0002](adr/0002-czysta-domena-bez-eloquenta.md)):
  `apps/catalog/src/SellerPortal/Infrastructure/Persistence/SqlOfferRepository.php:47`.
  - `save` (`:80`): wersja 0 → INSERT, inaczej UPDATE z warunkiem
    `->where('version', $offer->version())` (`:98`); 0 wierszy → `ConcurrencyConflict` (`:105`).
    W tej samej transakcji: historia cen (`savePriceChanges`, `:175`) i outbox (`:110`).
  - `hydrate` (`:206`) nie ufa bazie: nieznany status, „zawieszona bez rodzaju
    zawieszenia” albo cena bez daty zgłoszenia to `UnexpectedValueException`
    z numerem oferty, a nie po cichu zepsuty agregat.
  - `get` (`:58`) ładuje tylko zmiany cen z ostatnich 24 h, bo limit dobowy
    nie potrzebuje starszych.
- Tożsamość: `apps/catalog/src/SellerPortal/Infrastructure/Persistence/NextId.php:21`
  (`nextval('{tabela}_id_seq')` w Postgresie; w SQLite `MAX(id)+1`, tylko dla
  jednowątkowych testów).
- Nazwa `Sql*`, nie `Postgres*` (odstępstwo od specu): testy biegną na SQLite,
  a Query Builder jest przenośny.
- **Repozytoria w pamięci** dla testów warstwy aplikacji:
  `apps/catalog/tests/Unit/SellerPortal/InMemory/InMemoryOfferRepository.php:19`.
  To jest główna nagroda za interfejs w domenie: 130 testów domeny
  i aplikacji w 44 ms ([POMIARY](POMIARY.md)).
- **Historia z tego etapu (Task 6):** migracje z Taska 5 sprawdzono na
  jednorazowym Postgresie 18.4. `timestampTz` bez precyzji to w Postgresie
  `timestamptz(0)`, czyli zaokrąglenie do sekund. `savePriceChanges` pomija
  wpisy nie nowsze od najnowszego zapisanego, więc dwie zmiany ceny z tej
  samej sekundy mogły się skleić w jedną i limit „5 na dobę” by przeciekał.
  Na SQLite tego nie widać. Naprawa: precyzja 6
  (`apps/catalog/database/migrations/2026_10_15_100002_create_offer_price_changes_table.php:19`).
  Baza deweloperska, która uruchomiła wcześniejszą wersję migracji,
  potrzebuje `migrate:fresh` (RUNBOOK [#041](RUNBOOK.md#041)).
- Koszt: cały katalog `Infrastructure/Persistence` to 598 linii (dwa
  repozytoria, tłumacz zdarzeń, `NextId`, `SqlTimestamp`). W Eloquencie
  mapowanie byłoby prawie darmowe. To jest cena pełnego DDD (moduł 10).

**Ćwiczenia**

1. Przeczytaj test „zapis i odczyt oferty zachowuje wszystkie pola”
   (`apps/catalog/tests/Feature/SellerPortal/Infrastructure/SqlOfferRepositoryTest.php:70`).
   Dodaj w głowie nową kolumnę `warranty_months`. W ilu miejscach musisz ją
   dopisać? (Migracja, `row`, `hydrate`, `reconstitute`, konstruktor, getter.
   Przy Eloquencie: migracja i `$fillable`.)
2. **Zepsuj i napraw: utracony zapis (lost update).** Usuń kontrolę wersji:

   ```diff
   --- apps/catalog/src/SellerPortal/Infrastructure/Persistence/SqlOfferRepository.php
                        $updated = DB::table('offers')
                            ->where('id', $offer->id()->value())
   -                        ->where('version', $offer->version())
                            ->update($this->row($offer) + [
   ```

   Uruchom cały portal. Czerwone: `SqlOfferRepositoryTest` › „drugi zapis tej
   samej wersji rzuca ConcurrencyConflict” i `PriceGuardTest` › „równoległa
   edycja → błąd concurrency” (zamiast komunikatu „Ktoś w międzyczasie zmienił
   tę ofertę — odśwież stronę” zapis po cichu przechodzi).

   **Odtwórz to „dwiema kartami” na swojej bazie deweloperskiej** (zmienia
   dane dev, nie testowe). Ten wariant nie był wykonywany przy pisaniu
   dokumentu; jego automatycznym odpowiednikiem jest test „drugi zapis tej
   samej wersji rzuca ConcurrencyConflict”, który robi dokładnie to samo na
   SQLite. Prawdziwe dwie karty przeglądarki rzadko trafią
   w to samo okno czasowe, bo każde żądanie wczytuje ofertę na świeżo.
   Dlatego w tinkerze robisz to, co dzieje się przy wyścigu: dwa odczyty
   przed dwoma zapisami.

   ```bash
   docker compose exec catalog-app php artisan tinker
   ```
   ```php
   use SellerPortal\Domain\Offer\{OfferRepository, OfferId, Stock};
   $repo = app(OfferRepository::class);
   $id = OfferId::fromInt(1);                       // dowolna opublikowana oferta
   $kartaA = $repo->get($id); $kartaB = $repo->get($id);   // obie widzą wersję N
   $kartaA->changeStock(Stock::fromInt(10), new DateTimeImmutable);
   $repo->save($kartaA);                            // baza: wersja N+1, stan 10
   $kartaB->changeStock(Stock::fromInt(20), new DateTimeImmutable);
   $repo->save($kartaB);
   ```

   Z kontrolą wersji ostatnia linia rzuca `ConcurrencyConflict`. Bez niej
   przechodzi: stan 20, zmiana karty A przepadła bez śladu w UI, a w outboxie
   są *dwa* zdarzenia `offer.stock_changed` z tym samym `sequence` N+1, czyli
   dwa różne stany opisane tą samą wersją. Historia oferty kłamie. Cofnij:
   `git checkout -- src/SellerPortal/Infrastructure/Persistence/SqlOfferRepository.php`
   (kontener widzi pliki z tego checkoutu na żywo).

**Sprawdź się**
- Dlaczego interfejs repozytorium leży w `Domain`, a nie w `Infrastructure`?
- Czym różni się `reconstitute` od `draft`? Dlaczego `reconstitute` nie emituje zdarzeń?
- Optymistyczne czy pesymistyczne blokowanie (`SELECT … FOR UPDATE`) dla
  formularza, który użytkownik trzyma otwarty 10 minut? Dlaczego?

---

## MODUŁ 8 — Warstwa aplikacji i polityki

**Cel:** piszesz handlery bez reguł biznesowych, rozpoznajesz, kiedy reguła
przecieka do kontrolera, i umiesz zrobić politykę, która jest spójna
ostatecznie, ale nie gubi pracy.

**Teoria**

1. **Warstwa aplikacji orkiestruje, nie decyduje.** Handler komendy: otwórz
   transakcję → wczytaj agregat → wywołaj metodę → zapisz → roześlij zdarzenia.
   Zasada kontrolna: *w handlerze nie ma `if` z regułą biznesową*.
2. **Porty** to interfejsy, które aplikacja definiuje dla świata zewnętrznego
   (zegar, transakcje, kolejka, katalog). Adaptery w infrastrukturze je
   implementują (architektura heksagonalna).
3. **Reguła dostępu aktora** (kto może dotknąć której oferty) to coś innego
   niż reguła stanu oferty. Może żyć w warstwie aplikacji, ale *nie* w kontrolerze.
4. **Polityka** („gdy X, to Y”) łączy agregaty spójnością ostateczną.
   Trzy rzeczy, które musi mieć: atomowe zlecenie pracy razem ze zdarzeniem,
   sprawdzenie warunku *w chwili wykonania* i idempotencję.

**W projekcie**

- Kształt handlera:
  `apps/catalog/src/SellerPortal/Application/Offer/ChangeOfferPriceHandler.php:28`.
  Cztery linie w transakcji, decyzję zwraca agregat (`PriceChangeDecision`).
- Porty: `apps/catalog/src/SellerPortal/Application/Port/` (`Clock`,
  `TransactionManager`, `DomainEventDispatcher`, `PolicyJobQueue`,
  `ProductCatalog`). Spinanie z implementacjami:
  `apps/catalog/app/Providers/SellerPortalServiceProvider.php:37`.
- **Reguły dostępu:** `apps/catalog/src/SellerPortal/Application/Offer/SellerOfferAccess.php`.
  `forUser` (`:41`) pilnuje właściciela, `forActiveSeller` (`:60`) dokłada
  „konto aktywne”, `sellerActions` (`:83`) zawęża akcje z agregatu dla
  zablokowanego sprzedawcy do `ACTIONS_WITHOUT_ACTIVE_SELLER` (`:28`).
- **Historia z tego etapu (Task 10):** reguła „zablokowany sprzedawca może
  tylko wycofać” wylądowała najpierw w kontrolerze, przy budowaniu listy
  przycisków. Działało, testy HTTP były zielone. Przegląd kodu ją odrzucił,
  bo to podręcznikowy przeciek reguły biznesowej do UI: druga ścieżka (API,
  komenda konsolowa, panel moderatora) musiałaby ją powtórzyć, a test
  jednostkowy jej nie widział. Poprawka (commit `a0c69ba`) przeniosła ją do
  `SellerOfferAccess::sellerActions`, obok podziału `forUser`/`forActiveSeller`,
  który i tak już tam był. Uczciwie: `ACTIONS_WITHOUT_ACTIVE_SELLER` jest
  ręcznym lustrem tego, które handlery używają `forUser`, i nic tego nie
  wymusza automatycznie (docblock `:74`).
- Błędy domeny trafiają do UI w jednym miejscu:
  `apps/catalog/bootstrap/app.php:57` (`OfferNotOwned`, `OfferNotFound`,
  `SellerNotFound` → 404, nie 403, żeby nie zdradzać istnienia cudzej oferty),
  `:64` (każdy `DomainException` → powrót z błędem `domain`), `:50` (złamana
  reguła to oczekiwany błąd użytkownika, nie trafia do logu, z wyjątkiem
  `InvalidValue`) i `apps/catalog/app/Http/DomainErrorMessages.php:15` (kod → zdanie).
- **Polityka blokady, cztery decyzje:**
  1. Listener tylko kolejkuje:
     `apps/catalog/src/SellerPortal/Application/Policy/QueueSuspensionWhenSellerBlocked.php:15`.
     Sprzedawca może mieć tysiące ofert, a handler blokady działa w żądaniu HTTP.
  2. **Atomowość „blokada + job”.** Job trafia do tabeli `jobs` *w tej samej
     transakcji* co zmiana statusu: albo oba, albo nic (ten sam pomysł co
     outbox). **Historia z Taska 8:** pierwsza wersja dispatchowała na
     domyślne połączenie kolejki, więc gwarancja zależała od zmiennej
     `QUEUE_CONNECTION`. Przy `redis` job wyszedłby mimo wycofanej blokady,
     a przy `sync` wykonałby się w żądaniu HTTP. Przegląd to złapał. Poprawka
     przypina połączenie w kodzie: `apps/catalog/config/queue.php:38`
     (`seller_policies_connection`, domyślnie `database`), `after_commit => false`
     (`:59`) i adapter `apps/catalog/src/SellerPortal/Infrastructure/LaravelPolicyJobQueue.php:25`.
     Do tego job ma `timeout = 600` s
     (`apps/catalog/src/SellerPortal/Infrastructure/Job/SuspendOffersOfBlockedSellerJob.php:24`),
     a `retry_after` wynosi 660 s (`config/queue.php:54`). Gdyby `retry_after`
     był mniejszy, drugi worker wziąłby wolny job, zanim pierwszy skończy.
  3. **Warunek w chwili wykonania:**
     `apps/catalog/src/SellerPortal/Application/Policy/SuspendOffersOfBlockedSeller.php:46`.
     Jeśli moderator zdążył odblokować, job kończy bez działania. Szybkie
     „zablokuj → odblokuj” nie zostawia zawieszonych ofert.
  4. **Paczki po 500, transakcja na ofertę, 3 próby przy konflikcie,
     idempotencja** (`:53`–`:79`). Ponowienie całego joba nic nie psuje
     i nie dubluje zdarzeń outboxu.
- **Historia z Taska 7/8: Octane i znikający słuchacze.** Dispatcher zdarzeń
  jest `scoped` (nowy na żądanie), bo trzyma listę słuchaczy. Rejestracja
  słuchaczy w `boot()` działałaby na pierwszym żądaniu, a po tym, jak Octane
  wyczyści instancje scoped, nowy dispatcher byłby pusty: blokada sprzedawcy
  przestałaby zawieszać oferty *bez żadnego błędu*. Dlatego słuchacze są
  rejestrowani wewnątrz fabryki scoped
  (`apps/catalog/app/Providers/SellerPortalServiceProvider.php:48`), a test
  „słuchacze polityk przeżywają flush instancji scoped (Octane)”
  (`apps/catalog/tests/Feature/SellerPortal/Infrastructure/SellerPolicyJobsTest.php:122`)
  woła `forgetScopedInstances()` i sprawdza, że polityka dalej działa.
- Co się dzieje, gdy worker nie działa: RUNBOOK [#040](RUNBOOK.md#040).

**Ćwiczenia**

1. Przejrzyj 16 handlerów w `apps/catalog/src/SellerPortal/Application/`.
   Znajdź jeden `if`, który *jest* regułą biznesową. (Kandydaci:
   `DraftOfferHandler.php:48` `hasLiveOffer`, moduł 9; `RegisterSellerHandler.php:34`.
   Oba to reguły na zbiorach, których agregat nie widzi. Czy to łamie zasadę?)
2. **Zepsuj i napraw: reguła ucieka do kontrolera** (dokładnie to, co
   zdarzyło się w Tasku 10, tylko odwrotnie). Usuń regułę z warstwy
   aplikacji i odtwórz ją w kontrolerze:

   ```diff
   --- apps/catalog/src/SellerPortal/Application/Offer/SellerOfferAccess.php
            $actions = $offer->allowedActionsFor(Actor::Seller);
   -
   -        if ($seller->canSell()) {
   -            return $actions;
   -        }
   +        return $actions;
   --- apps/catalog/app/Http/Controllers/Seller/SellerOfferController.php   (metoda show)
   -            'allowed_actions' => array_map(fn (OfferAction $action): string => $action->value, $access->sellerActions($seller, $aggregate)),
   +            'allowed_actions' => array_map(fn (OfferAction $action): string => $action->value, $seller->canSell() ? $access->sellerActions($seller, $aggregate) : array_values(array_filter($access->sellerActions($seller, $aggregate), fn (OfferAction $a): bool => $a === OfferAction::Withdraw))),
   ```

   Uruchom cały portal. Czerwony jest **tylko jeden** test:
   `SellerOfferAccessTest` › „zablokowany sprzedawca z opublikowaną ofertą
   może tylko wycofać”. Wszystkie testy HTTP są **zielone**, bo z perspektywy
   przeglądarki nic się nie zmieniło. To jest sedno lekcji: test HTTP sprawdza
   zachowanie jednej ścieżki, a test warstwy aplikacji sprawdza, *gdzie żyje
   reguła*. Gdy ktoś przeniesie regułę i „naprawi” test jednostkowy przez jego
   usunięcie, nic go nie zatrzyma. Dlatego taki ruch musi wyłapać przegląd
   kodu. Test architektury też tego nie złapie, bo kontroler ma prawo używać
   aplikacji. Cofnij: `git checkout -- src app`.
3. **Zepsuj i napraw: polityka bez sprawdzenia warunku.**

   ```diff
   --- apps/catalog/src/SellerPortal/Application/Policy/SuspendOffersOfBlockedSeller.php
   -        if ($this->sellers->get($sellerId)->status() !== SellerStatus::Blocked) {
   -            return 0;
   -        }
   ```

   Czerwone: `SellerPoliciesTest` › „job po szybkim odblokowaniu nic nie
   robi” (aplikacja, w pamięci) i `SellerPolicyJobsTest` › to samo na bazie.
   Moderator kliknął „zablokuj”, po sekundzie „odblokuj” (pomyłka), a job
   i tak zawiesza wszystkie oferty aktywnego sprzedawcy. Cofnij:
   `git checkout -- src/SellerPortal/Application/Policy/SuspendOffersOfBlockedSeller.php`.
4. **Zepsuj i napraw: luka w teście architektury.** Globalne ograniczenie
   brzmi „domena nie woła `now()`/`time()`”. Sprawdź, czy ktoś tego pilnuje:

   ```diff
   --- apps/catalog/src/SellerPortal/Domain/Offer/Offer.php   (metoda publish)
   -        $this->recordThat(new OfferPublished($this->id, $now));
   +        $this->recordThat(new OfferPublished($this->id, now()->toDateTimeImmutable()));
   ```

   Uruchom cały portal: **wszystko zielone**. `now()` to globalna funkcja
   Laravela, a nie klasa z przestrzeni `Illuminate`, więc reguła
   `not->toUse(['Illuminate', …])` jej nie widzi. Napraw lukę (zostaw
   zepsucie w `Offer.php`):

   ```php
   // apps/catalog/tests/Arch/SellerPortalLayersTest.php — dopisz na końcu
   arch('domena nie czyta zegara sama')
       ->expect('SellerPortal\Domain')
       ->not->toUse(['now', 'time', 'date']);
   ```

   Teraz czerwony jest nowy test: `Expecting 'SellerPortal\Domain' not to use 'now'.`
   Cofnij `Offer.php` (`git checkout -- src`): zielone, bo obecny kod tej
   reguły nie łamie. Czy reguła powinna zostać na stałe? To dobra decyzja do
   zrobienia przez Ciebie, osobnym commitem.

**Sprawdź się**
- Jak rozpoznać, że reguła biznesowa przeciekła do kontrolera, skoro testy HTTP są zielone?
- Dlaczego job polityki sprawdza status sprzedawcy, skoro powstał właśnie dlatego, że sprzedawca został zablokowany?
- Co się stanie z atomowością „blokada + job”, jeśli ktoś ustawi `after_commit => true`?
- Dlaczego odpowiedź na cudzą ofertę to 404, a nie 403?

---

## MODUŁ 9 — Reguły na zbiorach (unikalność)

**Cel:** umiesz pilnować reguły, której nie widzi żaden pojedynczy agregat,
i wiesz, dlaczego „sprawdź, potem zapisz” nie wystarcza.

**Teoria**

Reguła „sprzedawca ma najwyżej jedną żywą ofertę na produkt” dotyczy
**zbioru** agregatów. `Offer` zna siebie, a nie inne oferty. Opcje:

| Opcja | Jak | Koszt |
|---|---|---|
| większy agregat | `SellerProductOffers` trzyma wszystkie oferty sprzedawcy na produkt | sztuczny agregat tylko dla jednej reguły, konflikty wersji |
| sprawdzenie w aplikacji | `hasLiveOffer()` przed zapisem | **wyścig**: dwa żądania naraz przechodzą sprawdzenie |
| ograniczenie w bazie | indeks unikalny | brzydki błąd SQL, jeśli go nie przetłumaczysz |
| rezerwacja / usługa domenowa | osobna tabela „zajętych par” | dodatkowy model |
| akceptuj i kompensuj | wykryj duplikat po fakcie i wycofaj | biznes musi to akceptować |

Standard (i nasz wybór): **sprawdzenie w aplikacji dla czytelnego komunikatu
plus ograniczenie w bazie jako gwarancja**, a naruszenie ograniczenia tłumaczone
na ten sam wyjątek domenowy.

**W projekcie**

- Sprawdzenie: `apps/catalog/src/SellerPortal/Application/Offer/DraftOfferHandler.php:48`.
- Gwarancja: częściowy indeks unikalny `(seller_id, product_id) WHERE status <> 'withdrawn'`:
  `apps/catalog/database/migrations/2026_10_15_100001_add_status_to_offers_table.php:44`.
  Wycofane oferty nie blokują nowej.
- Tłumaczenie naruszenia indeksu na `LiveOfferAlreadyExists`:
  `apps/catalog/src/SellerPortal/Infrastructure/Persistence/SqlOfferRepository.php:113`
  i `violatesLiveOfferIndex` (`:195`; Postgres podaje nazwę indeksu, SQLite kolumny).
  Wyjątek łapany *poza* transakcją, żeby Postgres nie został w przerwanej transakcji.
- To samo dla `sellers.user_id`. **Historia z Taska 7:** dwa równoległe
  kliknięcia „zgłoś się” mogą oba przejść `findByUserId`, a drugie dostawało
  surowy `QueryException` (500). Teraz indeks jest mapowany na
  `SellerAlreadyRegistered`:
  `apps/catalog/src/SellerPortal/Infrastructure/Persistence/SqlSellerRepository.php:65`.

**Ćwiczenia**

1. **Zepsuj i napraw: zostaw samą gwarancję.** Usuń sprawdzenie w aplikacji:

   ```diff
   --- apps/catalog/src/SellerPortal/Application/Offer/DraftOfferHandler.php
   -            if ($this->offers->hasLiveOffer($seller->id(), $productId)) {
   -                throw new LiveOfferAlreadyExists;
   -            }
   ```

   Uruchom cały portal. Czerwony: `OfferHandlersTest` › „szkic gdy sprzedawca
   ma żywą ofertę na produkt rzuca LiveOfferAlreadyExists” (repozytorium
   w pamięci świadomie *nie* udaje indeksu). Test HTTP `SellerOfferTest` ›
   „oferta na produkt z żywą ofertą → błąd domain” zostaje **zielony**, bo
   indeks w bazie i tłumaczenie w repozytorium dają ten sam komunikat. Tak
   wygląda obrona w głąb: jedna warstwa padła, użytkownik nic nie zauważył.
   Cofnij: `git checkout -- src/SellerPortal/Application/Offer/DraftOfferHandler.php`.
2. **Zepsuj i napraw: zostaw samo sprawdzenie.** Usuń indeks z migracji
   (testy migrują świeżą bazę SQLite):

   ```diff
   --- apps/catalog/database/migrations/2026_10_15_100001_add_status_to_offers_table.php
   -        DB::statement("CREATE UNIQUE INDEX offers_one_live_per_seller_product ON offers (seller_id, product_id) WHERE status <> 'withdrawn'");
   +        // usunięte
   ...
   -        DB::statement('DROP INDEX offers_one_live_per_seller_product');
   +        // usunięte
   ```

   Czerwone: `OfferReadModelTest` › „indeks nie pozwala na drugą żywą ofertę
   sprzedawcy na produkt, wycofana nie blokuje” oraz w `SqlOfferRepositoryTest`
   › „druga żywa oferta → LiveOfferAlreadyExists, nie QueryException” i „po
   nieudanym zapisie drugiej żywej oferty nie zostaje wiersz outboxu”. Testy
   HTTP są zielone, bo żądania w testach idą po kolei. Wyścig, przed którym
   chroni indeks, w zwykłym teście nie wystąpi. Dlatego indeks ma osobny test
   integracyjny. Cofnij: `git checkout -- database`.

**Sprawdź się**
- Dlaczego samo `hasLiveOffer()` nie wystarcza nawet przy jednym serwerze aplikacji?
- Dlaczego indeks jest częściowy (`WHERE status <> 'withdrawn'`)?
- Kiedy wybrałbyś „akceptuj i kompensuj” zamiast indeksu?

---

## MODUŁ 10 — Kiedy NIE stosować DDD

**Cel:** umiesz uczciwie policzyć, ile DDD kosztuje, i wskazać części systemu,
w których byłoby marnotrawstwem.

**Teoria**

1. **DDD to inwestycja w model.** Zwraca się tam, gdzie *złożoność biznesowa*
   jest istotą problemu: dużo reguł, stany, wyjątki, reguły, które się
   zmieniają, eksperci, którzy mówią innym językiem niż tabele.
2. **Złożoność techniczna to coś innego.** Wydajność, rozproszenie, indeksy,
   wersjonowanie dokumentów: tu agregaty i value objecty nie pomagają, bo nie
   ma reguł biznesowych do ochrony.
3. **Strategiczne DDD jest prawie darmowe** (język, konteksty, mapa) i warto
   je robić zawsze. **Taktyczne DDD jest drogie** (agregaty bez ORM-a,
   repozytoria, porty, handlery) i robi się je tylko w core.
4. **DDD-lite** (bogate modele Eloquenta z metodami domenowymi, enumy, casty
   VO, bez osobnej warstwy repozytoriów) to rozsądny środek. Ta sama logika
   w modelu, ale bez ceny ręcznego mapowania. Za to reguły da się obejść
   jednym `Offer::query()->update(...)`.
5. Heurystyka: *jeśli najtrudniejsze pytanie o funkcję to „jakie pola ma
   formularz”, to CRUD. Jeśli najtrudniejsze pytanie to „co ma się stać,
   gdy…”, to model.*

**W projekcie: gdzie DDD byłoby marnotrawstwem**

- **Katalog produktów** (supporting). Product, Brand, Category, Review to dane
  z walidacją. Panel edycji produktu to `apps/catalog/app/Http/Controllers/Panel/ProductController.php`
  (146 linii) i model `apps/catalog/app/Models/Product.php` (58 linii).
  Nie ma tam maszyny stanów, progów ani polityk. Agregat `Product`
  z repozytorium dałby kilkaset linii mapowania i zero ochrony, bo nie ma
  czego chronić.
- **Projekcja do wyszukiwarki** (`ProductProjectionController`, cały `apps/search`).
  To transformacja danych plus złożoność techniczna: external versioning,
  idempotencja konsumenta, mapowanie ES, PIT i `search_after`. Model
  domenowy niczego tu nie upraszcza. Dobre narzędzia to testy kontraktu
  projekcji i RUNBOOK. Jedyna reguła biznesowa („która oferta jest widoczna”)
  ma *jedną* definicję po stronie odczytu: `apps/catalog/app/Models/Offer.php:63`
  (`listed()`), zgodną z `Offer::isListed()` w domenie.
- **Tożsamość** (generic): Fortify. Własny model użytkownika byłby
  budowaniem czegoś, co każdy już ma.
- **Sam Seller Portal też ma części, gdzie DDD nie wchodzi:** modele
  odczytu (`apps/catalog/src/SellerPortal/Infrastructure/ReadModel/`) to zwykły
  SQL. Lista ofert to tabela, nie zachowanie.

**Koszt DDD w tym repo, zmierzony** (2026-10-15, `find … | wc -l`):

| Część | Pliki | Linie |
|---|---|---|
| `src/SellerPortal/Domain` | 61 (w tym 19 klas zdarzeń i 13 wyjątków domeny) | 2241 |
| `src/SellerPortal/Application` | 45 (16 par komenda + handler, 5 portów, 3 wyjątki aplikacji) | 1310 |
| `src/SellerPortal/Infrastructure` | 19 (w tym `Persistence`: 598 linii mapowania) | 1207 |
| **razem PHP modułu** | **125** | **4758** |
| HTTP (kontrolery sprzedawcy i moderacji, provider, middleware, `DomainErrorMessages`) | 11 | 627 |
| Vue (strony i komponenty sprzedawcy i moderacji) + `types/seller.ts` | | 2020 + 130 |
| testy (`tests/Unit/SellerPortal`, `tests/Feature/SellerPortal`, `tests/Arch`) | 33 | 3836 (w tym 314 linii repozytoriów w pamięci) |

Dla porównania: przed tym etapem edycja ofert przez admina to był model
Eloquenta `Offer` (84 linie, z `updateWithOutbox`) i `Panel/OfferController`
(42 linie). Funkcjonalnie to nie to samo, bo portal ma sprzedawców,
moderację, strażnika ceny i polityki, więc 4758 do 126 nie jest uczciwym
porównaniem. Uczciwe porównanie tych samych reguł w stylu DDD-lite to
osobna gałąź `ddd-lite-porownanie` i dokument `docs/blog/ddd-vs-ddd-lite.md`
(następny krok etapu, **jeszcze nie zrobiony**). Do tego czasu traktuj
liczby powyżej jako górną granicę kosztu, nie jako wynik porównania.

**Co DDD tu dało** (też zmierzone): 130 testów domeny i aplikacji w 44 ms
bez bazy i frameworka; każda reguła ma jedno miejsce (próg 30% to stała
`PriceChangePolicy::THRESHOLD_PERCENT`, `apps/catalog/src/SellerPortal/Domain/Offer/PriceChangePolicy.php:21`,
której używa i domena, i podpowiedź w UI); test architektury pada w CI,
gdy framework wejdzie do domeny; przyciski w UI pochodzą wyłącznie
z `allowedActionsFor`. **Co kosztowało:** ręczne mapowanie (każda nowa
kolumna w 5–6 miejscach), 16 handlerów z prawie identycznym docblockiem,
porty z jedną implementacją, ceremonia przy prostych zmianach (zmiana
nazwy pola to 4 pliki zamiast 1).

**Ćwiczenia**

1. Policz sam (wynik powinien się zgadzać z tabelą, chyba że kod się zmienił):
   ```bash
   cd apps/catalog
   for d in Domain Application Infrastructure; do
     echo "$d: $(find src/SellerPortal/$d -name '*.php' | wc -l) plików, $(find src/SellerPortal/$d -name '*.php' -exec cat {} + | wc -l) linii"
   done
   ```
2. Dla każdej z tych funkcji zdecyduj: CRUD, DDD-lite czy pełne DDD.
   Uzasadnij jednym zdaniem: (a) zapisane alerty wyszukiwania (`SavedAlert`),
   (b) zamówienia z rezerwacją stanu, (c) słownik kategorii, (d) zwroty
   z regułami czasu i stanu towaru, (e) opinie o produktach z moderacją.
3. Przeczytaj „Bilans” w przewodniku etapu i dopisz jeden koszt, którego tam
   brakuje, a który odczułeś przy ćwiczeniach z modułów 1–9.

**Sprawdź się**
- Podaj dwie części tego repo, w których DDD byłoby marnotrawstwem, i dlaczego.
- Czym różni się złożoność biznesowa od technicznej? Przykład każdej z tego repo.
- Co *konkretnie* tracisz, przechodząc z pełnego DDD na DDD-lite?
- Startup, 2 programistów, 3 miesiące do premiery, reguły jeszcze nieznane. Co robisz ze strategią, a co z taktyką?
