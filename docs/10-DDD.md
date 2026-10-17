# 10 — Domain-Driven Design od zera, na portalu sprzedawcy

Format każdego modułu (jak w [`03-SCIEZKA-NAUKI.md`](03-SCIEZKA-NAUKI.md)):
- **Cel**: co będziesz umiał po module,
- **Teoria**: co to jest i *po co powstało* (problem, nie definicja),
- **W projekcie**: gdzie dokładnie to siedzi w tym repo (`plik:linia`) i dlaczego tak,
- **Ćwiczenia**: w tym „zepsuj i napraw” z dokładną komendą i testem, który ma zrobić się czerwony,
- **Sprawdź się**: pytania, na które odpowiadasz bez zaglądania.

Moduły 1–3 to **strategia** (jak podzielić problem i rozmawiać o nim). Moduły
4–9 to **taktyka** (jak zapisać model w kodzie). Moduł 10 mówi, kiedy całą
resztę odpuścić. Moduły 11–12 (etap „Zamówienia”) to reguły, które obejmują
**kilka agregatów naraz**: process manager, spójność ostateczna, kompensacja
i idempotencja. Kolejność jest celowa: większość zespołów, które „robiły DDD”
i się sparzyły, zaczęła od taktyki (repozytoria, agregaty, value objecty
wszędzie) i nigdy nie zadała pytania strategicznego, czy ten kawałek systemu
w ogóle na to zasługuje.

Materiał jest zbudowany na prawdziwym module: `apps/catalog/src/SellerPortal`
(etap „Portal sprzedawcy”, przewodnik: [`blog/etap-e-portal-sprzedawcy-ddd.md`](blog/etap-e-portal-sprzedawcy-ddd.md)).
Obok leżą słownik [`11-SELLER-PORTAL-SLOWNIK.md`](11-SELLER-PORTAL-SLOWNIK.md)
i decyzje architektoniczne [`adr/`](adr/). Spec, z którego wszystko wyszło:
[`superpowers/specs/2026-10-15-portal-sprzedawcy-ddd-design.md`](superpowers/specs/2026-10-15-portal-sprzedawcy-ddd-design.md).
Moduły 11–12 stoją na kontekście `apps/catalog/src/Ordering` (zamówienia,
przewodnik: [`blog/etap-f-zamowienia-saga.md`](blog/etap-f-zamowienia-saga.md),
słownik: [`12-ZAMOWIENIA-SLOWNIK.md`](12-ZAMOWIENIA-SLOWNIK.md), spec:
[`superpowers/specs/2026-10-16-zamowienia-ddd-design.md`](superpowers/specs/2026-10-16-zamowienia-ddd-design.md)).

> **Ścieżki.** Wszystkie odwołania `plik:linia` są względne wobec korzenia
> repozytorium i zostały sprawdzone na gałęzi `portal-sprzedawcy` (2026-10-15),
> a w modułach 11–12 na gałęzi `zamowienia` (2026-10-16).
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
# moduły 11–12: zamówienia i portal razem (rezerwacje żyją w ofercie), ~6 s:
php artisan test --compact tests/Unit/Ordering tests/Feature/Ordering tests/Unit/SellerPortal tests/Feature/SellerPortal tests/Arch
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

**Uczciwie: ten warsztat się nie odbył.** Model portalu powstał w specu,
w dialogu projektowym (pytania, odpowiedzi, decyzje spisane od razu jako
tekst). Poniżej jest **rekonstrukcja**: tak doszedłby do tego samego modelu
zespół stojący przed ścianą z karteczkami. Rozpisuję ją krok po kroku, bo
kolejność kroków jest ważniejsza od wyniku, a hot spoty poniżej to dokładnie
te pytania, które w specu trzeba było rozstrzygnąć. Następną funkcję
(zamówienia, podprojekt C) zrób odwrotnie: najpierw prawdziwy Event Storming
(choćby sam, na kartce), dopiero potem spec i kod (ćwiczenie 2).

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
pojawiła się w specu. Wyszła dopiero przy implementacji i spec ją
pominął (to jedyne świadome odstępstwo od specu w zdarzeniach, opisane
w globalnych ograniczeniach planu). Żaden model, ani ze ściany, ani ze specu,
nie jest od razu kompletny.

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
| „Moderator może przywrócić ofertę zablokowanego sprzedawcy?” | Nie. Przywrócenie wystawia ofertę kupującym, tak jak publikacja, więc wymaga sprzedawcy, który może sprzedawać. Hot spot znaleziony dopiero w przeglądzie końcowym. | `Offer::reinstate(Seller)`, `SellerOfferAccess::moderatorActions` |
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
| agregat „Oferta” | `apps/catalog/src/SellerPortal/Domain/Offer/Offer.php:50` (`final class Offer`) |
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
2. **Event Storming na sucho, zamówienia (podprojekt C).** Zanim przeczytasz
   odpowiedź, rozpisz krok 1–7 dla „kupujący zamawia z oferty”.
   Gwarantowane hot spoty: rezerwacja stanu (kiedy zmniejszamy `stock`?),
   oferta wycofana w trakcie płacenia, zmiana ceny między koszykiem a płatnością.
   Który z nich zmieni agregat `Offer`, a który da nowy agregat? Potem porównaj
   z prawdziwym warsztatem, który tym razem odbył się **przed** specem:
   [spec zamówień, sekcja 1](superpowers/specs/2026-10-16-zamowienia-ddd-design.md#1-event-storming-kupujący-zamawia-z-oferty)
   (12 hot spotów H1–H12, z których każdy ma miejsce w kodzie). Ile z nich
   znalazłeś sam? Których zabrakło i dlaczego (zwykle: wpłata po terminie H6
   i zdublowany webhook H10, bo wychodzą dopiero z pytania „co, jeśli to samo
   przyjdzie dwa razy albo za późno?”).
3. **Zepsuj i napraw: rozstrzygnięty hot spot jest testem.** Hot spot „odblokowanie
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
- Podaj hot spot z tego etapu, który bez specu i warsztatu rozstrzygnąłby programista,
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
| Seller Portal (zapis) | agregat z cyklem życia, strażnikiem ceny, regułami moderacji | `apps/catalog/src/SellerPortal/Domain/Offer/Offer.php:50` |
| Katalog (odczyt) | wiersz tabeli do pokazania i do projekcji; jedyna reguła to „widoczna” | `apps/catalog/app/Models/Offer.php:70` (scope `listed()`) |
| Wyszukiwarka | zagnieżdżony obiekt w dokumencie produktu: cena, sprzedawca, `in_stock` | `apps/catalog/app/Http/Controllers/Internal/ProductProjectionController.php:48` (`$product->listedOffers`) |

Gdybyśmy na siłę zrobili *jeden* model „Offer” dla wszystkich trzech, Eloquent
z projekcji musiałby znać strażnika ceny, a agregat musiałby umieć się
zserializować do ES. Trzy modele to nie duplikacja, tylko trzy odpowiedzi na
trzy różne pytania.

Decyzje z tego etapu:
- **Moderacja nie jest osobnym kontekstem.** Decyzje moderatora zmieniają stan
  tego samego agregatu `Offer` i podlegają tym samym niezmiennikom (np.
  `approvePriceChange` wymaga oczekującej zmiany: `apps/catalog/src/SellerPortal/Domain/Offer/Offer.php:303`).
  Osobny kontekst dałby dwa modele jednej oferty do synchronizacji.
- **Granica jest w kodzie, nie w infrastrukturze.** Osobny katalog bazowy
  `apps/catalog/src/` (PSR-4 `SellerPortal\`), żeby „to nie jest kod
  frameworka” było widać w drzewie plików. Pilnuje tego test architektury:
  `apps/catalog/tests/Arch/SellerPortalLayersTest.php:3` (domena), `:7` (aplikacja)
  i `:14` (domena nie woła `now()`/`time()`/`date()`/`microtime()`).

**Ćwiczenia**

1. Wypisz wszystkie tabele bazy `catalog` i przypisz każdą do kontekstu. Które
   tabele mają **jednego** właściciela zapisu (`offers`, `sellers`,
   `offer_price_changes` → tylko repozytoria Seller Portalu), a które nie?
2. Znajdź w `apps/search` miejsce, gdzie search „rozumie” ofertę. Czy zna
   słowo `suspended`? (Nie, i nie musi: zna tylko wynik projekcji.)
3. **Zepsuj i napraw: framework wchodzi do domeny.** W `Money` użyj czegoś
   z Laravela:

   ```diff
   --- apps/catalog/src/SharedKernel/Money.php
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

   Czerwony: `SharedKernelTest` › „shared kernel nie zna nikogo”,
   komunikat `Expecting 'SharedKernel' not to use 'Illuminate'.`
   Cofnij: `git checkout -- src/SharedKernel/Money.php`.
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

**Mapa po etapie zamówień.** Doszedł kontekst Ordering, Shared Kernel i obcy
system (bramka płatności). Strzałka idzie od tego, kto woła:

```
Tożsamość (users) ──[Conformist: BuyerId = users.id]──▶ Ordering
Ordering ──[port StockReservations → komendy aplikacyjne portalu]──▶ Seller Portal     (Customer/Supplier)
Ordering ──[port OfferSnapshots, port SellerAccounts: odczyt]──▶ Seller Portal / katalog
Ordering ──[port PaymentGateway]──▶ Fake gateway ──[podpisany webhook]──▶ ACL: PaymentWebhookController ──▶ Ordering
Seller Portal ──[outbox offer.*: + stock_reserved, reservation_released, reservation_confirmed]──▶ RabbitMQ ──▶ Search
Ordering + Seller Portal ──[Shared Kernel: Money, Currency, AggregateRoot, DomainEvent, …]
Ordering ──✗── outbox (zdarzenia order.* nie wychodzą z kontekstu, jak w ADR-0003)
```

| Relacja | Wzorzec | Kod |
|---|---|---|
| Ordering → Seller Portal (rezerwacje) | **Customer/Supplier**: Ordering definiuje port, portal wystawia komendy `ReserveStock`/`ReleaseReservation`/`ConfirmReservation`, adapter tłumaczy wyjątki portalu na `ReservationResult` (mały ACL) | `apps/catalog/src/Ordering/Application/Port/StockReservations.php`, `apps/catalog/src/Ordering/Infrastructure/SellerPortalStockReservations.php:41` |
| Ordering ↔ Seller Portal (pieniądze, klasy bazowe) | **Shared Kernel** | `apps/catalog/src/SharedKernel/`, [ADR-0008](adr/0008-shared-kernel.md) |
| Ordering ← bramka | **ACL**: jedyna klasa, która zna format bramki (podpis, `reference`, `status`) | `apps/catalog/app/Http/Controllers/Webhooks/PaymentWebhookController.php:34` |
| Ordering ← Tożsamość | **Conformist** | `apps/catalog/src/Ordering/Domain/BuyerId.php` |
| Seller Portal → Search | **Published Language**, rozszerzony o trzy nazwy rezerwacji i pole `available`; klucz rezerwacji nie wychodzi | `apps/catalog/src/SellerPortal/Infrastructure/Persistence/OfferIntegrationEvents.php:123` |

Kierunek jest jeden: portal nie zna zamówień (`arch('portal sprzedawcy nie zna
zamówień')`, `apps/catalog/tests/Arch/OrderingLayersTest.php:20`), a z
`SellerPortal\*` korzysta wyłącznie `Ordering\Infrastructure`. Gdyby portal
stał się osobną usługą, zmieniają się trzy adaptery (`SellerPortalStockReservations`,
`DbOfferSnapshots`, `DbSellerAccounts`), domena i aplikacja Orderingu nie.

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

- `Money`, int w groszach plus waluta: `apps/catalog/src/SharedKernel/Money.php:23`
  (`fromCents` odmawia `<= 0`). `int`, bo `0.1 + 0.2 != 0.3`, a próg 30%
  ma się rozstrzygać co do grosza.
- `Money::differsByMoreThanPercent`: `apps/catalog/src/SharedKernel/Money.php:60`.
  **Historia z tego etapu:** automatyczny przegląd bezpieczeństwa po Tasku 1
  zauważył, że punkt odniesienia był *domyślny*. Zamiana argumentów zmienia
  wynik: 10000 → 13001 to +30,01% (do akceptacji), ale 13001 → 10000 liczone
  od 13001 to ≈ 23% (przechodzi). Ktoś, kto kiedyś wywoła to odwrotnie,
  odwróci strażnika ceny. Naprawa w Tasku 4: punkt odniesienia nazwany wprost
  w docblocku, test asymetrii (`apps/catalog/tests/Unit/SharedKernel/MoneyTest.php:45`),
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

- `Offer`: `apps/catalog/src/SellerPortal/Domain/Offer/Offer.php:50`.
  Konstruktor prywatny (`:55`); powstaje przez `draft` (`:74`) albo
  `reconstitute` (`:96`, odczyt z bazy, bez zdarzeń).
  - Przejścia: `publish` (`:118`), `changeStock` (`:141`), `changeDetails` (`:162`),
    `suspend` (`:171`), `reinstate` (`:189`), `withdraw` (`:207`).
  - Strażnik ceny: `changePrice` (`:233`). Kolejność sprawdzeń jest stała:
    oczekująca zmiana → limit → próg.
  - `requireStatus` (`:483`) to jedno miejsce, które rzuca `InvalidOfferTransition`.
  - `allowedActionsFor(Actor)` (`:355`) to jedyne źródło prawdy dla UI, które
    przyciski pokazać. Frontend nie zna reguł, tylko renderuje listę.
  - Stany pochodne: `isListed` (`:332`) i `isSoldOut` (`:337`)
    ([ADR-0004](adr/0004-wyprzedana-jako-stan-pochodny.md)).
- `Seller`: `apps/catalog/src/SellerPortal/Domain/Seller/Seller.php:42`.
  Przejścia `approve`/`reject`/`block`/`unblock` (`:83`–`:117`).
  `canSell()` (`:123`) to pytanie, a nie wyjątek, bo wywołujący sam decyduje,
  co zrobić z odpowiedzią.
- **Fabryka w agregacie:** `Seller::draftOffer` (`:135`). Reguła „tylko
  aktywny sprzedawca wystawia” należy do tego, kto zna status, więc oferta
  zaczyna się u sprzedawcy. Sprzedawca jej jednak **nie przechowuje**.
- **Odwołanie przez ID:** `Offer` trzyma `SellerId`, nie `Seller`. Dwa
  momenty, w których oferta potrzebuje wiedzy o sprzedawcy (publikacja
  i przywrócenie), dostają go jako argument tylko do odczytu:
  `publish(Seller $seller, …)` (`Offer.php:118`) i `reinstate(Seller $seller, …)`.
  **Historia z przeglądu końcowego:** pierwsza wersja `reinstate` nie brała
  sprzedawcy. Moderator widział „Przywróć” przy ofercie zawieszonej przez
  blokadę i jednym kliknięciem wracał ją do wyszukiwarki, choć konto było
  wciąż zablokowane. Lekcja: przywrócenie to też decyzja zależna od
  sprzedawcy, jak publikacja — każde przejście, które **wystawia ofertę
  kupującym**, musi zapytać `Seller::canSell()`.
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
  (`apps/catalog/tests/Unit/SellerPortal/Domain/OfferPriceTest.php:81`) nie
  czeka dobę, a granica okna jest dokładna co do sekundy.

**Ćwiczenia**

1. Narysuj obie maszyny stanów z pamięci. Porównaj z docblockami w `Offer.php:32`
   i `Seller.php:30`.
2. Przeczytaj tabelę z testu `allowedActionsFor zgodne z tabelą`
   (`apps/catalog/tests/Unit/SellerPortal/Domain/OfferLifecycleTest.php:205`).
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

- `apps/catalog/src/SharedKernel/AggregateRoot.php:27`:
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
   Uwaga: „nasz odczyt” to odczyt w *tym samym żądaniu*. Człowiek widział
   ofertę wcześniej, na wyrenderowanej stronie. Żeby ochronić **jego**
   decyzję, formularz odsyła wersję, którą wyświetlił, a handler porównuje ją
   z wersją wczytaną teraz. Dopiero oba sprawdzenia razem chronią przed
   nadpisaniem z karty otwartej kwadrans temu.
4. **Tożsamość przed zapisem**: agregat ma ID od urodzenia, bo zdarzenia
   niosą ID. Bierze się je z sekwencji bazy przed INSERT-em.
5. **CQRS w wersji lekkiej**: zapis przez agregaty, odczyt (listy, pulpit)
   zwykłym SQL-em do DTO. Nie ładujemy 50 agregatów, żeby narysować tabelę.

**W projekcie**

- Interfejs: `apps/catalog/src/SellerPortal/Domain/Offer/OfferRepository.php:11`.
- Implementacja na Query Builderze, bez Eloquenta ([ADR-0002](adr/0002-czysta-domena-bez-eloquenta.md)):
  `apps/catalog/src/SellerPortal/Infrastructure/Persistence/SqlOfferRepository.php:54`.
  - `save` (`:87`): wersja 0 → INSERT, inaczej UPDATE z warunkiem
    `->where('version', $offer->version())` (`:105`); 0 wierszy → `ConcurrencyConflict` (`:112`).
    W tej samej transakcji: historia cen (`savePriceChanges`, `:182`) i outbox (`:117`).
  - `hydrate` (`:213`) nie ufa bazie: nieznany status, „zawieszona bez rodzaju
    zawieszenia” albo cena bez daty zgłoszenia to `UnexpectedValueException`
    z numerem oferty, a nie po cichu zepsuty agregat.
  - `get` (`:65`) ładuje tylko zmiany cen z ostatnich 24 h, bo limit dobowy
    nie potrzebuje starszych.
- Wersja z formularza: `apps/catalog/src/SellerPortal/Application/ExpectedVersion.php`.
  Każda komenda z UI, która zmienia ofertę (6 sprzedawcy, 4 moderatora),
  niesie `expectedVersion`; handler zaraz po wczytaniu woła
  `ExpectedVersion::check($offer, $command->expectedVersion)`. Polityki
  (joby blokady i odblokowania) jej nie używają: działają na najświeższym
  stanie, nikt im niczego nie wyświetlał.
  - Akceptacja i odrzucenie zmiany ceny niosą dodatkowo kwotę, którą moderator
    widział w kolejce. To już reguła domeny (`Offer::approvePriceChange(…,
    Money $expectedPrice, …)` → `PendingPriceChangeMismatch`), a nie tylko
    wersja: bez niej sprzedawca mógł anulować zgłoszenie na 131 zł, zgłosić
    900 zł, a moderator klikający „Akceptuj” przy 131 zł wprowadzał 900 zł.
    Handler sprawdza tę regułę **przed** wersją, żeby moderator dostał
    konkretny powód („Sprzedawca zmienił zgłoszoną cenę — odśwież kolejkę”).
  - **Historia z przeglądu końcowego:** pierwsza wersja miała tylko blokadę
    w repozytorium, a docblock i RUNBOOK obiecywały ochronę „dwóch kart”. Test
    wyścigu przechodził, bo symulował zapis w tych samych milisekundach.
    Prawdziwe karty przeglądarki się nie nakładają: druga wczytywała świeżą
    ofertę i nadpisywała zmianę pierwszej po cichu.
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
   w to samo okno czasowe, bo każde żądanie wczytuje ofertę na świeżo
   (karty chroni wersja z formularza, ćwiczenie 3). Dlatego w tinkerze robisz
   to, co dzieje się przy wyścigu: dwa odczyty przed dwoma zapisami.

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
3. **Zepsuj i napraw: druga karta.** Usuń linię
   `ExpectedVersion::check($offer, $command->expectedVersion);` z
   `apps/catalog/src/SellerPortal/Application/Offer/ChangeOfferStockHandler.php`.
   Czerwone: `PriceGuardTest` › „druga karta z nieaktualną wersją → błąd
   concurrency, stan bez zmian” (druga karta po cichu ustawia 42 sztuki)
   i `OfferHandlersTest` › „komenda z nieaktualną wersją…” (zestaw `stan`).
   Zielony zostaje test „równoległa edycja”: blokada w repozytorium dalej
   działa, tylko nie widzi kart, które się nie nakładają. W przeglądarce:
   otwórz ofertę w dwóch kartach, zapisz stan w pierwszej, potem w drugiej.
   Z kontrolą: alert „Ktoś w międzyczasie zmienił tę ofertę — odśwież stronę”.
   Cofnij przez `git checkout -- <plik>`.

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
  `apps/catalog/src/SellerPortal/Application/Offer/ChangeOfferPriceHandler.php:29`.
  Pięć linii w transakcji (wczytaj z kontrolą dostępu, porównaj wersję z
  formularza, zmień, zapisz, roześlij), decyzję zwraca agregat
  (`PriceChangeDecision`). Porównanie wersji to jedyny `if` w handlerze i nie
  jest regułą biznesową, tylko faktem o tym, co człowiek widział (moduł 7).
- Porty: `apps/catalog/src/SellerPortal/Application/Port/` (`Clock`,
  `TransactionManager`, `DomainEventDispatcher`, `PolicyJobQueue`,
  `ProductCatalog`). Spinanie z implementacjami:
  `apps/catalog/app/Providers/SellerPortalServiceProvider.php:37`.
- **Reguły dostępu:** `apps/catalog/src/SellerPortal/Application/Offer/SellerOfferAccess.php`.
  `forUser` (`:43`) pilnuje właściciela, `forActiveSeller` (`:62`) dokłada
  „konto aktywne”, `sellerActions` (`:85`) zawęża akcje z agregatu dla
  zablokowanego sprzedawcy do `ACTIONS_WITHOUT_ACTIVE_SELLER` (`:30`),
  a `moderatorActions` robi to samo dla panelu: bez „Przywróć”, gdy
  sprzedawca nie może sprzedawać (`Offer::allowedActionsFor` sprzedawcy nie
  widzi, a `Offer::reinstate(Seller)` i tak by odmówił).
- **Historia z tego etapu (Task 10):** reguła „zablokowany sprzedawca może
  tylko wycofać” wylądowała najpierw w kontrolerze, przy budowaniu listy
  przycisków. Działało, testy HTTP były zielone. Przegląd kodu ją odrzucił,
  bo to podręcznikowy przeciek reguły biznesowej do UI: druga ścieżka (API,
  komenda konsolowa, panel moderatora) musiałaby ją powtórzyć, a test
  jednostkowy jej nie widział. Poprawka (commit `a0c69ba`) przeniosła ją do
  `SellerOfferAccess::sellerActions`, obok podziału `forUser`/`forActiveSeller`,
  który i tak już tam był. Uczciwie: `ACTIONS_WITHOUT_ACTIVE_SELLER` jest
  ręcznym lustrem tego, które handlery używają `forUser`, i nic tego nie
  wymusza automatycznie (docblock `:76`).
- Błędy domeny trafiają do UI w jednym miejscu:
  `apps/catalog/bootstrap/app.php:66` (`OfferNotOwned`, `OfferNotFound`,
  `SellerNotFound` → 404, nie 403, żeby nie zdradzać istnienia cudzej oferty),
  `:73` (każdy `DomainException` → powrót z błędem `domain`), `:57` (złamana
  reguła w żądaniu HTTP to oczekiwany błąd użytkownika i nie trafia do logu,
  z wyjątkiem `InvalidValue`; w konsoli i workerze kolejki trafia, bo job
  polityki, który padł, nie ma komu pokazać komunikatu) i `apps/catalog/app/Http/DomainErrorMessages.php:15` (kod → zdanie).
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
   brzmi „domena nie woła `now()`/`time()`”. Zepsuj je:

   ```diff
   --- apps/catalog/src/SellerPortal/Domain/Offer/Offer.php   (metoda publish)
   -        $this->recordThat(new OfferPublished($this->id, $now));
   +        $this->recordThat(new OfferPublished($this->id, now()->toDateTimeImmutable()));
   ```

   Uruchom `php artisan test --compact tests/Arch`. Czerwony jest test
   „domena nie woła funkcji zegara”: `Expecting 'SellerPortal\Domain' not to
   use 'now'.` Teraz zakomentuj tę regułę w
   `apps/catalog/tests/Arch/SellerPortalLayersTest.php` i uruchom cały
   portal: **wszystko zielone**. `now()` to globalna funkcja Laravela, a nie
   klasa z przestrzeni `Illuminate`, więc reguła
   `not->toUse(['Illuminate', …])` jej nie widzi. Tak wyglądał portal do
   przeglądu końcowego: ograniczenie żyło tylko w dokumentacji i w oczach
   recenzenta, dopiero osobna reguła `not->toUse(['now', 'time', 'date',
   'microtime'])` zrobiła z niego strażnika w CI. Cofnij oba pliki
   (`git checkout -- src tests/Arch`). Czego ta reguła nadal nie widzi:
   `new DateTimeImmutable()` bez argumentu — to konstruktor klasy, którego
   domena legalnie używa z argumentem.

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
  `apps/catalog/src/SellerPortal/Infrastructure/Persistence/SqlOfferRepository.php:120`
  i `violatesLiveOfferIndex` (`:202`; Postgres podaje nazwę indeksu, SQLite kolumny).
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
   w modelu, ale bez ceny ręcznego mapowania. Reguły da się obejść jednym
   `Offer::query()->update(...)` — ale w tym repo **tak samo w pełnym DDD**,
   bo model odczytu `App\Models\Offer` istnieje i przyjmuje zapis. Pełne DDD
   zmienia domyślną drogę zapisu, nie możliwość obejścia; granicę w obu
   trzyma konwencja i przegląd kodu.
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
  ma *jedną* definicję po stronie odczytu: `apps/catalog/app/Models/Offer.php:70`
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
porównaniem. Uczciwe porównanie tych samych reguł w stylu DDD-lite:
[`docs/blog/ddd-vs-ddd-lite.md`](blog/ddd-vs-ddd-lite.md) (gałąź
`ddd-lite-porownanie`). Wynik: lite to 2277 linii zamiast 4523 przy
identycznych testach HTTP i tym samym koszcie nowej reguły; nie sprawdza
wierszy z bazy, nie ma testu architektury, a testy reguł chodzą ~35× wolniej.
Drugie porównanie, dla zamówień (saga kontra jedna transakcja z blokadami):
[`docs/blog/saga-vs-transakcja.md`](blog/saga-vs-transakcja.md) (gałąź
`zamowienia-ddd-lite`). Wynik: lite to 1407 linii zamiast 4231, reguła
„najwyżej 3 nieopłacone” kosztuje 7 plików zamiast 12, wyścigi R1, R2, R7
i R10 znikają; za to zapomniany `lockForUpdate()` po cichu skleja dwa stany
zamówienia, a wiersze z bazy nie są sprawdzane. Na Postgresie
(`orders:race-test`, 50 kupujących na 10 sztuk) oba style dały 0
oversellingu w 3/3 przebiegach. Prognoza „pełne DDD dla zamówień”
potwierdza się dla agregatu, nie dla sagi.

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

---

## MODUŁ 11 — Process manager i spójność ostateczna

**Cel:** rozpoznajesz regułę, której nie da się zamknąć w jednym agregacie,
umiesz spiąć kilka agregatów procesem (process manager) i wiesz, gdzie ma
żyć stan tego procesu. Rozumiesz, co kupujący widzi „pomiędzy” krokami.

**Teoria**

1. **Reguła na wielu agregatach.** „Złożone zamówienie trzyma towar w każdej
   ofercie” dotyczy `Order` i kilku `Offer` naraz. Moduł 5 mówi: jedna
   transakcja zmienia jeden agregat. Opcje są trzy: większy agregat (tu
   absurdalny: zamówienie z ofertami?), jedna transakcja na kilku agregatach
   (blokady, deadlocki; to robi gałąź lite) albo **proces**: każdy agregat we
   własnej transakcji, a coś pomiędzy pilnuje kolejności i sprząta po
   porażkach.
2. **Saga i process manager.** *Saga* (Garcia-Molina, Salem, 1987) to ciąg
   lokalnych transakcji, z których każda ma kompensację. *Process manager*
   (Hohpe, Woolf, „Enterprise Integration Patterns”) to komponent, który
   odbiera zdarzenia i decyduje o następnym kroku. W praktyce mówi się
   „saga” na oba. Ważniejsze rozróżnienie: **orkiestracja** (jeden
   koordynator zna cały proces) kontra **choreografia** (każdy reaguje na
   cudze zdarzenia, nikt nie widzi całości). Tu jest orkiestracja.
3. **Gdzie żyje stan procesu.** Process manager musi wiedzieć, gdzie proces
   jest („rezerwuję pozycję 2 z 3”, „czekam na płatność”). Można go trzymać
   w osobnej tabeli sagi albo w agregacie, którego proces dotyczy. Jeśli
   każdy krok procesu jest też stanem, który widzi użytkownik, osobna tabela
   to druga kopia tej samej prawdy, a dwie kopie się rozjeżdżają.
4. **Spójność ostateczna ma twarz.** Między krokami świat jest „pomiędzy”:
   zamówienie istnieje, a towar jeszcze nie jest zarezerwowany. Nie ukrywaj
   tego. Nazwij ten stan w języku użytkownika i pokaż go w UI. Tu to
   „Sprawdzamy dostępność” (`Placed`) z odświeżaniem co sekundę.
5. **Trzy warunki z modułu 8 obowiązują dalej:** krok zlecony atomowo ze
   zdarzeniem (outbox komend), warunek sprawdzany w chwili wykonania,
   idempotencja (moduł 12). Dochodzi czwarty: **czas jest aktorem.** Termin
   płatności nie przyjdzie jako zdarzenie od kupującego. Ktoś musi co jakiś
   czas zapytać „co już minęło?”.

**W projekcie**

- Process manager:
  `apps/catalog/src/Ordering/Application/Process/OrderProcessManager.php:25`.
  Sześć słuchaczy, każdy jedną linijką kolejkuje krok, zero stanu. Docblock
  (`:19`) mówi, dlaczego tylko kolejkuje: wywołanie portalu albo bramki
  w transakcji zamówienia trzymałoby blokadę na czas cudzej operacji.
- Stan procesu w `Order`, nie w tabeli sagi
  ([ADR-0007](adr/0007-process-manager-ze-stanem-w-zamowieniu.md)):
  docblock `apps/catalog/src/Ordering/Domain/Order.php:35`. Status pozycji
  (`LineStatus::Pending`/`Reserved`) to jedyny kawałek stanu, którego
  kupujący nie widzi wprost.
- Atomowość „zapis zamówienia + krok sagi”: job w tabeli `jobs`, w tej samej
  transakcji, na połączeniu przypiętym w configu
  (`apps/catalog/src/Ordering/Infrastructure/LaravelProcessJobQueue.php:14`,
  `apps/catalog/config/queue.php:50`, `after_commit => false` w `:71`).
  Testy: `ProcessJobsTest` › „złożenie zamówienia wstawia job w tej samej
  transakcji” i „wycofana transakcja nie zostawia joba”
  (`apps/catalog/tests/Feature/Ordering/Infrastructure/ProcessJobsTest.php:55`, `:67`).
- Słuchacze sagi rejestrowani w fabryce `scoped` dispatchera, tak jak
  w portalu (Octane, moduł 8):
  `apps/catalog/app/Providers/OrderingServiceProvider.php:59`, test „słuchacze
  sagi przeżywają flush instancji scoped (Octane)” (`ProcessJobsTest.php:144`).
- Kto zwalnia, a kto nie: `onCancelled` (`OrderProcessManager.php:52`)
  przepuszcza tylko anulowanie przez kupującego. Sprzedawca anuluje opłacone
  zamówienie, bo towaru nie ma (H8), więc oddanie sztuk wystawiłoby je
  następnemu kupującemu, który też by ich nie dostał.
- Spójność ostateczna w UI: `Placed` → „Sprawdzamy dostępność”
  (`apps/catalog/src/Ordering/Infrastructure/ReadModel/OrderStatusLabel.php:17`)
  i polling co 1 s tylko w tym statusie, najwyżej 120 s, potem „Sprawdzanie
  trwa dłużej niż zwykle”
  (`apps/catalog/resources/js/pages/shop/orders/Show.vue:38`). Drugi stan
  „pomiędzy” to powrót z bramki przed webhookiem: „Czekamy na potwierdzenie
  płatności” (`apps/catalog/app/Http/Controllers/Shop/OrderController.php:43`).
  Nie jest statusem zamówienia, bo zamówienie jeszcze nic nie wie.
- Czas jako aktor: sweep `orders:expire-overdue` co minutę
  (`apps/catalog/routes/console.php:13`), w kontenerze `catalog-scheduler`
  (`compose.yaml:518`). Handler
  (`apps/catalog/src/Ordering/Application/Order/ExpireOverdueOrdersHandler.php:39`):
  paczki po 100, każde zamówienie we własnej transakcji, konflikt wersji
  pomija (następny przebieg za minutę), a przed `expire` sprawdza
  `isDueForExpiry` jeszcze raz na świeżym stanie (`:72`). Każdy inny błąd
  jednego zamówienia handler zbiera do wyniku i idzie dalej; komenda
  loguje go i kończy się kodem FAILURE. Sweep zamiast
  opóźnionego joba, bo zgubiony job zostawiłby zamówienie wiszące na zawsze,
  a sweep naprawia także zamówienia, w których saga utknęła (R10).
  `/health` pokazuje czas ostatniego przebiegu
  (`apps/catalog/app/Http/Controllers/HealthController.php:51`).
- Karencja na spóźniony webhook ma **dwie** definicje, jak `listed()`
  w module 10: w domenie (`Order::isDueForExpiry`, `Order.php:361`) i w
  zapytaniu sweepa (`SqlOrderRepository::idsDueForExpiry`,
  `apps/catalog/src/Ordering/Infrastructure/Persistence/SqlOrderRepository.php:114`;
  repozytorium w pamięci ją powtarza:
  `apps/catalog/tests/Unit/Ordering/InMemory/InMemoryOrderRepository.php:77`).
  Ćwiczenie 3 pokazuje, co z tego wynika.
- Wszystkie wyścigi (R1–R10 ze specu) to testy aplikacji w pamięci
  z ręcznie ustawionym przeplotem:
  `apps/catalog/tests/Unit/Ordering/Application/OrderRacesTest.php`. Hak
  `afterReserve` wstrzykuje cudzy krok między T1 a T2. Cały plik trwa
  milisekundy, bo nie ma w nim bazy ani kolejki.

**Ćwiczenia**

1. Narysuj z pamięci przepływ od „Złóż zamówienia” do „Opłacone” i do
   „Wygasło”: każde zdarzenie, każdy job, każda transakcja (T1, T2). Zaznacz,
   w którym miejscu kupujący widzi „Sprawdzamy dostępność”. Porównaj ze
   specem, sekcja 5.
2. **Zepsuj i napraw: process manager zwalnia także po anulowaniu przez
   sprzedawcę.** Ktoś „upraszcza” słuchacza, bo „anulowane to anulowane”:

   ```diff
   --- apps/catalog/src/Ordering/Application/Process/OrderProcessManager.php   (metoda onCancelled)
   -        if ($event->by !== CancelledBy::Buyer) {
   -            return;
   -        }
   -
            $this->jobs->releaseReservations($event->orderId);
   ```

   Uruchom zamówienia i portal (komenda z „Jak uruchamiać testy”). Czerwone
   są **trzy** testy, wszystkie w warstwie aplikacji, w pamięci:
   `OrderProcessTest` › „anulowanie przez kupującego zwalnia, przez sprzedawcę
   nie” (w kolejce jest `releaseReservations` przed `refund`),
   `OrderRacesTest` › „R7: anulowanie przez sprzedawcę przed potwierdzeniem:
   potwierdzenie i tak się wykonuje” (kolejka `confirmReservations`,
   `releaseReservations`, `refund` zamiast `confirmReservations`, `refund`)
   i `OrderProcessTest` › „ponowiony zwrot po zakończeniu nie woła bramki drugi
   raz” (błąd typu: test bierze pierwszy job z kolejki jako zwrot, a pierwszy
   jest teraz zwolnieniem). Wszystkie testy HTTP sprzedawcy są zielone.
   Zwróć uwagę, dlaczego żaden test nie pokazał „sztuka wróciła do
   sprzedaży”: w tych scenariuszach zwolnienie wykonuje się **po**
   potwierdzeniu, a zwolnienie potwierdzonego klucza to w ofercie no-op. Gdyby
   kolejność była odwrotna (zwolnienie przed potwierdzeniem), potwierdzenie
   dostałoby `ReservationNotFound` i job wylądowałby w `failed_jobs`:
   pieniądze są, a stan oferty nie spadł. Test przypina tu **decyzję** (H8),
   nie jej skutek. Cofnij:
   `git checkout -- src/Ordering/Application/Process/OrderProcessManager.php`.
3. **Zepsuj i napraw: karencja tylko w jednej z dwóch definicji.** Usuń
   karencję z domeny, zostaw w zapytaniu sweepa:

   ```diff
   --- apps/catalog/src/Ordering/Domain/Order.php   (metoda isDueForExpiry)
   -            && $now >= $this->paymentDeadline->add(new DateInterval(self::WEBHOOK_GRACE));
   +            && $now >= $this->paymentDeadline;
   ```

   Czerwone są **dwa** przypadki jednego testu domeny:
   `OrderLifecycleTest` › „wygasa dopiero po terminie i 5 min karencji”
   z datasetem `Placed` i `AwaitingPayment`. Testy sweepa (`OrderCommandsTest` ›
   „ExpireOverdueOrders wygasza tylko po karencji i pomija konflikty”,
   `ExpireOverdueOrdersCommandTest` › „orders:expire-overdue szanuje karencję
   i zapisuje czas przebiegu”) są **zielone**, bo zapytanie repozytorium
   dalej odcina zamówienia sprzed karencji. Sweep zachowuje się poprawnie,
   a mimo to model kłamie: każdy, kto zapyta agregat „czy już wygasa?” poza
   sweepem (np. przycisk „Zapłać” w przyszłości), dostanie złą odpowiedź.
   Dwie definicje tej samej reguły pilnuje tylko to, że obie mają testy.
   Cofnij: `git checkout -- src/Ordering/Domain/Order.php`.
4. **Zepsuj i napraw: kolejka sagi na domyślnym połączeniu.** Maszyna
   bywa współdzielona i na `127.0.0.1:6379` może działać cudzy Redis, więc
   test z datasetem `redis` kieruje Redisa na port 1 (nic tam nie słucha),
   a komendę uruchamiaj z `REDIS_PORT=1` jako drugim zabezpieczeniem:

   ```diff
   --- apps/catalog/src/Ordering/Infrastructure/LaravelProcessJobQueue.php   (metoda reserveLines)
   -        ReserveOrderLinesJob::dispatch($orderId->value())->onConnection($this->connection());
   +        ReserveOrderLinesJob::dispatch($orderId->value());
   ```

   ```bash
   REDIS_PORT=1 php artisan test --compact tests/Unit/Ordering tests/Feature/Ordering tests/Unit/SellerPortal tests/Feature/SellerPortal tests/Arch
   ```

   Czerwonych jest sześć: `ProcessJobsTest` › „złożenie zamówienia wstawia
   job w tej samej transakcji”, „kolejka sagi trafia na połączenie z configu
   mimo queue.default” (oba datasety: `sync` z asercją, `redis` z błędem
   `Connection refused`), „słuchacze sagi przeżywają flush instancji scoped
   (Octane)” i dwa testy `ExpireOverdueOrdersCommandTest`: „orders:expire-overdue
   szanuje karencję i zapisuje czas przebiegu” oraz „błąd jednego zamówienia:
   reszta wygasa, błąd w logu, kod wyjścia FAILURE” (zamówienie jest od razu
   `awaiting_payment`, bo `phpunit.xml` ma `QUEUE_CONNECTION=sync` i krok
   wykonał się w żądaniu). Cofnij:
   `git checkout -- src/Ordering/Infrastructure/LaravelProcessJobQueue.php`.

   Historia, bo uczy: przy pierwszym przebiegu tego ćwiczenia test nie
   przestawiał Redisa i dataset `redis` **nie** padł: Laravel wysłał job do
   prawdziwego Redisa z `.env` (Redis innego projektu na tej samej maszynie,
   został w nim jeden obcy job). Dwie lekcje. Atomowość „zapis + krok”
   zależy od jednej linijki i od tego, że kolejka jest w tej samej bazie.
   Test z datasetem „zły config” jest bezpieczny tylko dopóty, dopóki kod
   jest dobry, chyba że sam odetnie się od prawdziwej infrastruktury; bez
   tego zepsuty kod robi z niego test integracyjny na cudzym serwerze.

**Sprawdź się**
- Dlaczego nie da się złożyć zamówienia i zarezerwować towaru w jednej
  transakcji jednego agregatu? Co by kosztowała jedna transakcja na kilku?
- Process manager w tym repo nie ma ani jednego pola. Gdzie jest stan procesu
  i co by się stało, gdyby był w dwóch miejscach?
- Kupujący widzi „Sprawdzamy dostępność”. Jaki to status i co musi się stać,
  żeby się zmienił?
- Dlaczego zegar to sweep co minutę, a nie job z opóźnieniem 20 minut?
- Dlaczego anulowanie przez sprzedawcę nie zwalnia rezerwacji, a anulowanie
  przez kupującego tak?
- Karencja ma dwie definicje. Która jest „prawdziwa” i jak byś je związał?

---

## MODUŁ 12 — Kompensacja i idempotencja

**Cel:** umiesz zaprojektować krok procesu, który znosi powtórzenie,
i kompensację, która sprząta po kroku, gdy dalszy ciąg się nie udał. Wiesz,
dlaczego „idempotencja wymaga pamięci” i dlaczego najgroźniejszy duplikat to
ten, który przychodzi **równolegle**.

**Teoria**

1. **Kompensacja to nie rollback.** Rollback cofa transakcję, która się nie
   zatwierdziła. Kompensacja to **nowa** operacja biznesowa, która znosi
   skutek czegoś, co już się wydarzyło: zwolnienie rezerwacji, zwrot pieniędzy.
   Ma własne awarie (zwrot w bramce może paść), więc sama potrzebuje ponowień
   i idempotencji. Wpłaty nie da się „cofnąć wyjątkiem”: pieniądze już są
   u nas, odmowa przyjęcia zostawia je bez właściciela.
2. **Dostarczenie co najmniej raz.** Kolejka ponawia job po awarii, bramka
   ponawia webhook, aż dostanie 2xx, człowiek klika dwa razy. Każdy krok musi
   znieść, że wykona się drugi raz. To jest **idempotencja**: ten sam krok
   powtórzony daje ten sam stan.
3. **Idempotencja wymaga pamięci.** Żeby rozpoznać powtórzenie, trzeba
   pamiętać, co już się stało: klucz (deterministyczny, nie losowy) i status
   tego, co pod nim zrobiono. Losowy klucz przy każdym ponowieniu to nowa
   operacja, nie powtórzenie.
4. **Trzy rodzaje duplikatów,** od najłatwiejszego:
   - *sekwencyjny* (job padł, kolejka go ponawia): wystarczy klucz,
   - *zewnętrzny* (webhook drugi raz): klucz z cudzego systemu (`paymentId`),
   - *równoległy* (ten sam job w dwóch workerach naraz, bo pierwszy
     przekroczył `retry_after`): oba przebiegi widzą stan sprzed drugiego.
     Klucz pomaga, ale **decyzja** o kompensacji może być zła, jeśli patrzy
     tylko na „czy jest po mojemu”, a nie „kto to zrobił”.
5. **Kompensacja przed skutkiem.** Najmniej intuicyjny wyścig: krok
   zwalniający przeszedł, *zanim* krok rezerwujący się zatwierdził, więc nie
   miał czego zwolnić. Wtedy krok rezerwujący, wracając, musi sam posprzątać
   (gałąź R1).
6. **Gdy kompensacji brak**, sygnałem musi być coś, co ktoś zobaczy:
   `failed_jobs`, log, alert. Cicha porażka jest gorsza niż głośna.

**W projekcie**

- **Wpłata nigdy nie jest odrzucana wyjątkiem:**
  `apps/catalog/src/Ordering/Domain/Order.php:42` (docblock) i
  `Order::recordPayment` (`:196`). Kolejność warunków: duplikat tej samej
  wpłaty (no-op), wpłata, która ma już zwrot (no-op), jedyna ścieżka
  przyjęcia (`AwaitingPayment` i kwota = suma), cała reszta to
  `LatePaymentReceived` + `RefundRequested`. Rozstrzygnięcie R15:
  `LatePaymentReceived` poprzedza zwrot na **każdej** ścieżce (po terminie,
  podwójna wpłata, zła kwota), a różnicę niesie `RefundReason`.
- **Zwrot jako kompensacja z własną idempotencją:**
  `apps/catalog/src/Ordering/Application/Process/RefundPayment.php:20`.
  Bramka jest wołana **przed** transakcją zamówienia (`:51`), bo wywołanie
  sieci w transakcji trzymałoby blokadę. To bezpieczne tylko dlatego, że
  `refundId` (`refund-{orderId}-{n}`, nadany przez domenę) jest kluczem
  idempotencji w bramce:
  `apps/catalog/app/FakePaymentGateway/FakePaymentGateway.php:115`
  (`insertOrIgnore` po `idempotency_key`). Jeśli zapis po udanym zwrocie
  padnie, ponowiony job zleci ten sam zwrot, a bramka wykona go raz.
- **Zwolnienie wszystkich pozycji, nie tylko oznaczonych:**
  `apps/catalog/src/Ordering/Application/Process/ReleaseOrderReservations.php:13`.
  Status pozycji w zamówieniu bywa w tyle za ofertą (awaria między T1 a T2),
  a zwolnienie nieistniejącej rezerwacji jest no-opem, więc „wszystkie” jest
  zawsze bezpieczne, a „tylko `Reserved`” gubiłoby sztuki.
- **Klucz deterministyczny:** `order-{id}`
  (`apps/catalog/src/Ordering/Application/Process/OrderReservationKey.php:10`).
  Ponowiony krok rezerwacji trafia w tę samą rezerwację (R2), a oferta
  traktuje „ten sam klucz, ta sama ilość” jako no-op
  (`apps/catalog/src/SellerPortal/Domain/Offer/Offer.php:215`).
- **Pamięć rozliczonych kluczy** (bo agregat zna tylko aktywne): tabela
  `offer_reservations` ze statusem i `SqlOfferRepository::reservationStatus`
  ([ADR-0006](adr/0006-rezerwacje-w-ofercie.md)). Dwa handlery z niej
  korzystają:
  - `ConfirmReservationHandler`
    (`apps/catalog/src/SellerPortal/Application/Reservation/ConfirmReservationHandler.php:35`):
    klucz już `confirmed` → no-op. Potwierdzenie bez aktywnej rezerwacji to
    w agregacie błąd (`Offer.php:278`), nie no-op: „zwolniona, potem
    potwierdzona” byłoby sprzedażą bez zmniejszenia stanu.
  - `ReserveStockHandler`
    (`apps/catalog/src/SellerPortal/Application/Reservation/ReserveStockHandler.php:42`):
    klucz już rozliczony → `ReservationConflict` (rozstrzygnięcie R12),
    zamiast surowego błędu indeksu unikalnego.
- **Kompensacja przed skutkiem (R1):**
  `apps/catalog/src/Ordering/Application/Process/ReserveOrderLines.php:96`.
  Kupujący anulował między T1 a T2. Job zwalniający po anulowaniu już
  przeszedł i nie zastał rezerwacji, bo T1 jeszcze się nie zatwierdziło.
  Krok rezerwacji, wracając do zamówienia, sam zwalnia tę pozycję.
- **R1 i R2 naraz (poprawka z końcowego przeglądu):** jak wyżej, ale worker
  pada po T1, zanim dojdzie do T2, więc gałąź R1 się nie wykonuje. Kolejka
  ponawia job, a ten zastaje zamówienie już `Cancelled`. Pierwsza wersja
  kończyła się wtedy samym `return` i sztuka wisiała zarezerwowana na
  zawsze. Teraz krok na starcie, gdy zamówienie nie jest `Placed` i nie
  trzyma rezerwacji, zwalnia każdą pozycję (no-op, gdy nic nie wisi):
  `ReserveOrderLines.php:63` (komentarz) i `:71`. Test: `OrderRacesTest` ›
  „R1+R2: T1 zatwierdzone po zwolnieniu, worker padł przed T2…”.
- **Duplikat równoległy (R18): spec był zbyt ogólny, test duplikatu go
  poprawił.** Spec mówił: w T2 „inny status niż `Placed` → zwolnij tę
  pozycję”. Przy implementacji (Task 8) powstał test zdublowanego joba: drugi
  worker dostał ten sam krok (pierwszy przekroczył `retry_after`)
  i doprowadził zamówienie do `AwaitingPayment` między T1 a T2 pierwszego.
  T1 pierwszego trafiło wtedy w **cudzą** aktywną rezerwację (no-op po
  kluczu), a reguła ze specu kazałaby ją zwolnić: zamówienie czeka na
  płatność bez towaru, sztuka wraca do sprzedaży, overselling. Kod zwalnia
  więc tylko wtedy, gdy zamówienie **samo zrezygnowało** z rezerwacji:
  `Rejected`, `Expired` albo `Cancelled` przez kupującego
  (`ReserveOrderLines.php:117` docblock, `:132`). Intencja R1 zostaje,
  zmienia się warunek: nie „czy status jest inny niż oczekiwałem”, tylko
  „czy zamówienie nadal chce tych sztuk”. Ta reguła to wiedza o zamówieniu,
  więc mieszka w agregacie: `Order::holdsReservations`
  (`apps/catalog/src/Ordering/Domain/Order.php:352`); pytają o nią krok
  rezerwacji i krok potwierdzenia
  (`ConfirmOrderReservations.php:35`: wpłata + rezerwacje trzymane = sprzedaż).
  Spec ma tę poprawkę dopisaną w sekcji 5.
- **Znany skrót (R19):** zdublowany krok rezerwacji, który trwałby przez
  płatność **i** potwierdzenie, spróbowałby zarezerwować klucz już
  `confirmed`. Fałszywa oferta w testach pamięci by na to pozwoliła, ale
  prawdziwy `ReserveStockHandler` rzuca `ReservationConflict` (R12), więc job
  głośno trafia do `failed_jobs` (RUNBOOK [#046](RUNBOOK.md#046)). Wymaga
  to, żeby człowiek zapłacił w trakcie jednego przebiegu joba, więc zostało
  opisane, nie rozwiązane.
- **Webhook: 200 zamiast ponowień w nieskończoność.**
  `apps/catalog/app/Http/Controllers/Webhooks/PaymentWebhookController.php:22`.
  Nieznane zamówienie, zły payload: ostrzeżenie w logu i 200, bo wiadomość
  nie stanie się lepsza po ponowieniu. Tylko zły podpis daje 401.
- **ACL przepuszcza błędy programisty:**
  `apps/catalog/src/Ordering/Infrastructure/SellerPortalStockReservations.php:26`.
  Brak towaru, zmiana ceny i niedostępność to wyniki (`ReservationResult`),
  po których zamówienie jest `Rejected`. `ReservationConflict` nie jest
  wynikiem: job ma się wywrócić, a nie po cichu odrzucić zamówienie.

**Ćwiczenia**

Wszystkie z katalogu `apps/catalog`, komendą „zamówienia i portal” z sekcji
[„Jak uruchamiać testy”](#jak-uruchamiać-testy-w-ćwiczeniach).

1. **Zepsuj i napraw: usuń gałąź R1.**

   ```diff
   --- apps/catalog/src/Ordering/Application/Process/ReserveOrderLines.php   (metoda run)
   -            if ($outcome === self::ABANDONED) {
   -                $this->reservations->release($key, $line->offerId());
   -            }
   ```

   Czerwony jest **jeden** test: `OrderRacesTest` › „R1: anulowanie w trakcie
   rezerwacji nie zostawia rezerwacji” (w fałszywych rezerwacjach zostaje
   aktywne `order-1|1`). Testy na bazie i HTTP są zielone, bo żaden z nich nie
   umie wstawić anulowania **między** T1 a T2: w teście HTTP kroki idą po
   kolei. Na produkcji ta sztuka wisiałaby zarezerwowana na zawsze (zamówienie
   jest `Cancelled`, więc sweep go nie ruszy, a zwolnienie już się wykonało).
   Jedyną ochroną przed tym błędem jest test z ręcznym przeplotem. Cofnij:
   `git checkout -- src/Ordering/Application/Process/ReserveOrderLines.php`.
2. **Zepsuj i napraw: wróć do reguły ze specu („inny status → zwolnij”).**

   ```diff
   --- apps/catalog/src/Ordering/Application/Process/ReserveOrderLines.php   (metoda markReserved)
   -                return $order->holdsReservations() ? self::HELD_BY_ORDER : self::ABANDONED;
   +                return self::ABANDONED;
   ```

   Czerwony jest **jeden** test: `OrderRacesTest` › „R1: zdublowany krok
   rezerwacji nie zwalnia sztuk zamówienia, które czeka na płatność”
   (`Failed asserting that false is true.`: zamówienie czeka na płatność,
   a jego rezerwacji już nie ma). Test R1 z anulowaniem jest zielony, więc
   ta wersja „działa” dla scenariusza, który spec rozpisał. Lekcja: spec był
   zbyt ogólny, a błąd wyszedł dopiero z pytania „co, jeśli ten sam krok
   wykona się dwa razy **naraz**?”. Cofnij jak wyżej.
3. **Zepsuj i napraw: ponowiony krok, który tylko wraca.** Przywróć
   pierwszą wersję startu kroku (samo `return`, gdy zamówienie nie jest
   `Placed`):

   ```diff
   --- apps/catalog/src/Ordering/Application/Process/ReserveOrderLines.php   (metoda run)
            if ($order->status() !== OrderStatus::Placed) {
   -            if (! $order->holdsReservations()) {
   -                foreach ($order->lines() as $line) {
   -                    $this->reservations->release($key, $line->offerId());
   -                }
   -            }
   -
                return;
            }
   ```

   Czerwony jest **jeden** test: `OrderRacesTest` › „R1+R2: T1 zatwierdzone
   po zwolnieniu, worker padł przed T2: ponowiony krok zwalnia sztukę”
   (zostaje aktywne `order-1|1`). Dwa wyścigi z osobna (R1: gałąź w T2, R2:
   ponowienie po kluczu) są obsłużone i mają zielone testy; dziura była
   dopiero w ich złożeniu, a znalazł ją przegląd całej gałęzi, nie test.
   Lekcja: przy każdej gałęzi „tu nic nie robię” zapytaj, czy poprzedni
   przebieg tego samego kroku mógł zostawić coś, o czym wie już tylko on.
   Cofnij jak wyżej.
4. **Zepsuj i napraw: idempotencja bez pamięci.** Usuń sprawdzenie statusu
   klucza w potwierdzeniu:

   ```diff
   --- apps/catalog/src/SellerPortal/Application/Reservation/ConfirmReservationHandler.php
   -            if ($this->offers->reservationStatus($offerId, $key) === ReservationStatus::Confirmed) {
   -                return;
   -            }
   -
   ```

   Czerwone są **dwa** testy na dwóch poziomach, oba z błędem
   `Nie ma aktywnej rezerwacji o tym kluczu.` (`ReservationNotFound`
   z `Offer.php:278`): `ReservationHandlersTest` › „ConfirmReservation drugi
   raz to no-op” (portal, w pamięci) i `ProcessJobsTest` › „confirm po
   płatności zmniejsza stan w bazie, ponowienie joba nic nie zmienia”
   (zamówienia, na bazie). Bez pamięci rozliczonych kluczy agregat nie
   odróżni „już potwierdzone” od „nigdy nie było” i każdy ponowiony job
   potwierdzenia skończyłby w `failed_jobs`, choć nic złego się nie stało.
   Gdyby ktoś „naprawił” to, robiąc z braku rezerwacji no-op w agregacie,
   otworzyłby drogę „zwolniona, potem potwierdzona” = sprzedaż bez
   zmniejszenia stanu. Cofnij:
   `git checkout -- src/SellerPortal/Application/Reservation/ConfirmReservationHandler.php`.

**Sprawdź się**
- Czym kompensacja różni się od rollbacku? Podaj dwie kompensacje z tego
  modułu i powiedz, co może pójść źle w każdej z nich.
- Dlaczego `refundId` nadaje domena, a nie bramka i nie `random_bytes`?
- Dlaczego potwierdzenie brakującej rezerwacji jest błędem, a zwolnienie
  brakującej rezerwacji no-opem?
- Opisz wyścig R1 w trzech zdaniach. Kto sprząta i dlaczego nikt inny nie może?
- Spec mówił „inny status → zwolnij”. Jaki scenariusz to psuje i jakie
  pytanie powinno paść przy projektowaniu, żeby go znaleźć?
- Webhook dla nieznanego zamówienia dostaje 200. Dlaczego nie 404?
