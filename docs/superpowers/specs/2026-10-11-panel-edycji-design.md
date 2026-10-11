# Panel edycji katalogu + wersjonowanie dokumentu po czasie odczytu

Data: 2026-10-11 · Status: zaakceptowany projekt, przed planem

## Cel

Zmiana w katalogu ma dać się zrobić z przeglądarki, nie z tinkera, i ma
przejść tą samą drogą co w produkcji: outbox → RabbitMQ → search-consumer
→ Elasticsearch. Panel ma tę drogę **pokazywać**: widać, czy zdarzenie
czeka w outboxie, czy już wyszło i czy ES ma nowy stan. To narzędzie
do nauki, które działa lokalnie (Compose) i na k3s.

Kryteria sukcesu:

1. Zmiana ceny dowolnej oferty, także drugiej i trzeciej oferty tego samego
   produktu, w ciągu kilku sekund widać w `/search`.
2. Na stronie produktu widać każdy krok drogi zdarzenia. Gdy search-consumer
   jest zatrzymany, zdarzenia stoją na kroku „opublikowane”.
3. Bez zalogowania panel jest niedostępny. Konto zakłada się jedną komendą,
   lokalnie i na k3s.

## Część 1: wersja dokumentu ES = czas odczytu projekcji

### Problem

`ElasticsearchIndexer` zapisuje dokument z `version_type: external`,
a `version = sequence` zdarzenia. `sequence` to lokalny licznik agregatu:
`Product.version` i `Offer.version` liczą się osobno, każdy od 1. Jeden
dokument ES powstaje z kilku agregatów, więc numery się zderzają.

Zmierzone lokalnie 2026-10-11: dokument produktu 1 ma `_version` 3 (dwie
zmiany oferty 1), a oferta 4 tego produktu (najtańsza) ma `version` 1.
Zmiana jej ceny dałaby `sequence` 2, ES odpowiedziałby 409, handler uznałby
to za sukces, a w wyszukiwarce zostałaby stara cena. Ograniczenie jest
opisane w docblocku `ElasticsearchIndexer` i odłożone od ETAPU 6. Panel
trafiałby w nie od razu.

### Rozwiązanie

Wersja dokumentu to **moment, w którym catalog odczytał stan produktu**,
w mikrosekundach, z zegara Postgresa. Search-consumer i tak przy każdym
zdarzeniu pobiera pełną, aktualną projekcję. O tym, czy dokument jest
świeższy, decyduje więc czas odczytu, a nie to, który agregat się zmienił.

**catalog: `Internal\ProductProjectionController::show`**

- Pierwsza instrukcja metody, **przed** jakimkolwiek odczytem danych:
  `SELECT (extract(epoch from clock_timestamp()) * 1000000)::bigint`.
  Kolejność ma znaczenie. Przy READ COMMITTED każde późniejsze zapytanie
  widzi co najmniej to, co było zatwierdzone w chwili pomiaru, więc dane
  nigdy nie są starsze niż ich wersja. Odwrotna kolejność mogłaby opisać
  stare dane nowym czasem.
- Odpowiedź 200 dostaje pole `projected_at_us` (int).
- Brak produktu: 404 z ciałem JSON `{"projected_at_us": <int>}` zamiast
  domyślnego `findOrFail`. Usunięcie z ES używa wtedy tego samego zegara.

**search: `CatalogProjectionClient`**

- `fetchProductProjection()` zwraca obiekt wyniku: albo projekcję, albo
  „brak produktu”. W obu przypadkach z `projectedAtUs`.
- Odpowiedź bez `projected_at_us` (np. stary catalog w trakcie
  wdrożenia) → `ProjectionUnavailableException`, czyli ponowienie
  wiadomości. Nie zgadujemy wersji.

**search: `ProductSyncHandler` i `ElasticsearchIndexer`**

- `indexProduct($projection, $projectedAtUs)` i
  `deleteProduct($productId, $projectedAtUs)`: argument nazywa się
  wersją dokumentu, nie sekwencją zdarzenia.
- 409 dalej oznacza „ES ma już świeższy odczyt” i dalej jest sukcesem
  (`IndexOutcome`).
- Log „Zaindeksowano produkt” zawiera `sequence` (śledzenie zdarzenia)
  i `doc_version` (wersja dokumentu).
- Docblock „ZNANE OGRANICZENIE” zastępuje opis nowej zasady i tego,
  dlaczego naprawia problem.

**Zgodność z danymi:** obecne `_version` w ES to małe liczby (1–3),
a czas w µs to ok. 1.79e15. Pierwsze zdarzenie po wdrożeniu nadpisze
każdy dokument. Reindeks nie jest potrzebny.

**Ryzyko, które przyjmujemy:** dwa catalog-app z różnymi zegarami.
Zegar jest jeden, Postgresa, bo pomiar idzie przez bazę. To zaleta
wariantu 1 i powód, żeby nie brać `microtime()` z PHP.

## Część 2: panel

### Dostęp

- Trasy `/panel/...` w grupie `middleware(['auth', 'can:panel'])`, **bez** `verified`.
  Maile idą do logu (`MAIL_MAILER=log`), więc potwierdzanie adresu byłoby
  szukaniem linku w logach.
- Flaga `users.is_admin` (boolean, domyślnie false, poza `$fillable`) i Gate
  `panel`. Rejestracja Fortify jest otwarta, więc samo `auth` wpuściłoby do
  edycji katalogu każdego, kto założy konto; uprawnienie nadaje tylko
  `marketplace:admin`.
- Komenda `php artisan marketplace:admin {email}`:
  - konta nie ma → tworzy je z losowym hasłem (20 znaków), ustawia
    `email_verified_at` i `is_admin`;
  - konto jest → generuje nowe hasło i nadaje `is_admin`, nie ruszając
    `email_verified_at`.
  - Hasło wypisuje raz na stdout i nigdzie go nie zapisuje.
- Makefile: `make admin email=...` (Compose) i `make k3s-admin email=...`
  (`kubectl exec deploy/catalog-app`).
- Sidebar: link „Panel” widoczny tylko dla `is_admin`.

### Ekrany

**`GET /panel/products`** (`Panel\ProductController@index`, strona
`panel/Products.vue`)

- Tabela z Postgresa: ID, nazwa, marka, kategoria („Dział › Podkategoria”),
  liczba aktywnych ofert, najniższa cena aktywnej oferty.
- Pole `q`: liczba → dokładne ID, tekst → `name ILIKE %q%`.
- Paginacja Laravela, 25 na stronę.

**`GET /panel/products/{product}`** (`Panel\ProductController@edit`,
strona `panel/ProductEdit.vue`)

- **Formularz produktu:** nazwa (wymagana, max 255), marka (select ze
  wszystkich marek), kategoria (select tylko z podkategorii, bo produkty
  w indeksie mają ścieżkę liścia).
  `PUT /panel/products/{product}` → `updateWithOutbox($validated,
  'product.updated')`. Bez zmian w polach nie powstaje żadne zdarzenie
  (patrz niżej).
- **Tabela ofert:** sprzedawca, cena (zł, 2 miejsca po przecinku), stan,
  aktywna tak/nie. Cena i stan edytowalne w wierszu, przycisk „Zapisz” na
  wiersz. `PUT /panel/offers/{offer}` → `Offer::updateWithOutbox()`, który
  sam daje `offer.price_changed` i/lub `offer.stock_changed`.
  „Dezaktywuj” (tylko przy aktywnej) → `POST /panel/offers/{offer}/deactivate`
  → `deactivateWithOutbox()`.
- Walidacja w Form Requestach: `UpdateProductRequest`, `UpdateOfferRequest`
  (cena > 0 i ≤ 1 000 000 zł, przeliczana na grosze w requeście; stan:
  liczba całkowita ≥ 0).
- Po zapisie redirect z flashem „Zapisano, zdarzenie w drodze”.

**Zapis bez zmian:** jeśli żadne pole nie jest „dirty”, kontroler nie woła
`updateWithOutbox` i nie podbija wersji. Flash: „Bez zmian”. Inaczej każdy
pusty zapis generowałby zdarzenie i reindeks.

### Droga zdarzenia

Sekcja na stronie edycji, prop `trace` (closure, leniwy):

- Ostatnie 10 wierszy outboxu, gdzie
  `(aggregate_type = 'product' AND aggregate_id = {id})` lub
  `(aggregate_type = 'offer' AND aggregate_id IN (oferty produktu))`,
  posortowane malejąco po `id`.
- Każde zdarzenie: typ, czas, i trzy kroki:
  1. **zapisane:** wiersz istnieje;
  2. **opublikowane:** `published_at` niepuste;
  3. **w ES:** `_version` dokumentu produktu ≥ `occurred_at` zdarzenia w µs.
- Wersję dokumentu pobiera jeden `GET products-search/_doc/{id}` (z
  `_source`). Ta sama odpowiedź zasila porównanie **Postgres vs ES**:
  nazwa, kategoria (etykieta), `price_min`, `in_stock`. Różnice są
  wyróżnione.
- ES niedostępny → sekcja pokazuje „ES nieosiągalny”, a krok 3 jest
  nieznany. Strona się nie wywraca.
- **Odświeżanie:** dopóki któreś zdarzenie nie ma kroku 3, strona robi
  partial reload `only: ['trace']` co 1 s (`usePoll` z Inertii, start/stop
  zależnie od stanu). Gdy wszystko dotrze, polling się zatrzymuje.

Znane okno niedokładności kroku 3: `occurred_at` ustawia PHP w transakcji,
przed commitem. Jeśli w tych kilku milisekundach consumer odczyta produkt
z powodu **innego** zdarzenia, krok 3 może zapalić się chwilę za wcześnie.
Własne zdarzenie i tak za moment da nowszą wersję z tą zmianą. Opisane
w przewodniku, bez naprawy (wymagałoby `track_commit_timestamp`).

## Testy

**catalog (Pest, SQLite in-memory, ES mockowany jak w
`SearchPropsLazinessTest`):**

- gość na `/panel/*` → redirect na login;
- `PUT` produktu → jeden wiersz outboxu `product.updated`, wersja +1;
  zapis bez zmian → zero wierszy;
- `PUT` oferty z ceną i stanem → dwa wiersze (`price_changed`,
  `stock_changed`), cena w groszach;
- dezaktywacja → `offer.deactivated`, `active = false`;
- walidacja: ujemny stan, cena 0, kategoria-dział → błędy;
- `marketplace:admin`: tworzy konto z potwierdzonym mailem, drugi raz
  zmienia hasło (stare przestaje działać);
- projekcja: jest `projected_at_us` (int); 404 ma `projected_at_us`;
  dwa kolejne odczyty dają rosnące wartości. Na SQLite nie ma
  `clock_timestamp()`, więc odczyt zegara siedzi w jednej metodzie,
  podmienialnej w teście, a prawdziwe SQL sprawdza test na Postgresie
  w weryfikacji ręcznej;
- `trace`: kroki 1–3 dla podanego `_version` z mocka ES; ES rzuca
  wyjątek → `es_available: false`.

**search (PHPUnit):**

- handler przekazuje `projected_at_us` do indexera; brak pola →
  wyjątek ponowienia;
- **regresja** (integracyjny, żywy ES, jak `tests/Integration`): zapis
  dokumentu z wersją A, potem z wersją B > A wygrywa, a C < B dostaje 409
  i jest sukcesem. Scenariusz „dwie oferty z `sequence` 2” odtworzony
  wprost na danych.

**Ręcznie (lista w przewodniku):** zmiana ceny 2. oferty w panelu → widać
w `/search`; `docker compose stop search-consumer` → zdarzenia stoją na
„opublikowane”, `start` → doganiają; to samo na k3s po `make k3s-deploy`.

## Dokumentacja

- `docs/blog/panel-edycji.md`: przewodnik krok po kroku (co, kod,
  dlaczego, jak sprawdzić, błędy po drodze, zadania domowe).
- `docs/blog/rabbitmq-podglad.md`: eksperymenty 1 i 2 przez panel
  zamiast tinkera.
- README: link do przewodnika.

## Poza zakresem

Tworzenie i usuwanie produktów oraz ofert, reaktywacja oferty, opinie,
role poza jedną flagą is_admin, historia zmian
poza outboxem.
