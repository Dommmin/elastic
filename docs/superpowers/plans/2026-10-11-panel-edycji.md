# Panel edycji katalogu: plan wykonania

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Cel:** Panel w catalog-app do edycji produktów i ofert. Każda zmiana idzie
outbox → RabbitMQ → ES, a panel pokazuje jej drogę. Do tego naprawa
wersjonowania dokumentów ES, bez której zmiany drugiej oferty produktu
giną po cichu.

**Architektura:** catalog mierzy czas odczytu projekcji zegarem Postgresa
(`projected_at_us`), a search używa go jako `version` (external) zamiast
`sequence` zdarzenia. Panel to strony Inertia/Vue w catalog-app, które
zapisują przez istniejące `updateWithOutbox` / `deactivateWithOutbox`.
Sekcja „Droga zdarzenia” czyta outbox z Postgresa i `_version` dokumentu
z ES, a odświeża się przez `usePoll`.

**Stack:** Laravel 13 + Octane, Inertia v3 + Vue 3 + shadcn-vue, Pest
(SQLite in-memory); Symfony 8 + Messenger + PHPUnit; Elasticsearch 9,
RabbitMQ; Docker Compose lokalnie, k3s na VPS.

**Spec:** `docs/superpowers/specs/2026-10-11-panel-edycji-design.md`

## Ograniczenia globalne

- Trasy panelu: prefiks `/panel`, middleware `auth` **bez** `verified`.
- Wersja dokumentu ES = `projected_at_us` = `(extract(epoch from clock_timestamp()) * 1000000)::bigint`, mierzone **przed** odczytem danych produktu.
- Odpowiedź projekcji bez `projected_at_us` → `ProjectionUnavailableException` (ponowienie), nigdy zgadywana wersja.
- Zapis bez zmian w polach → brak wiersza outboxu, brak podbicia `version`, flash „Bez zmian”.
- Flash po zapisie ze zmianą: „Zapisano, zdarzenie w drodze”.
- Cena w formularzu w zł: > 0 i ≤ 1 000 000, w bazie grosze (`price_cents`); stan: liczba całkowita ≥ 0.
- Kategoria w formularzu produktu: tylko podkategorie (`parent_id` nie null).
- Lista produktów: 25 na stronę; `q` liczbowe = dokładne ID, tekst = `name ILIKE %q%`.
- Droga zdarzenia: 10 ostatnich wierszy outboxu produktu i jego ofert, polling co 1000 ms tylko do chwili, aż wszystkie dotrą do ES.
- Hasło z `marketplace:admin`: 20 znaków, wypisane raz, nigdzie nie zapisywane.
- Teksty UI i komentarze po polsku, jak w reszcie repo. Komentarze tłumaczą „dlaczego”.
- Catalog: `vendor/bin/pint --dirty --format agent`, `npx prettier --write`, `npx eslint`, `npx vue-tsc --noEmit` przed każdym commitem z PHP/TS.
- Testy catalog: `cd apps/catalog && php artisan test --compact <plik>`. Testy search: `docker compose exec -T search-consumer vendor/bin/phpunit <plik>` (kontener montuje główny checkout, więc pracuj na `main` albo weryfikuj według memory „kontener montuje główny checkout”).
- Commity w stylu repo, po polsku, ze stopką `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`. Bez pusha do Task 8.

## Na co uważać przy review

1. **Druga oferta tego samego produktu** (`sequence` niższe niż `_version` dokumentu) ma się zaindeksować: test regresyjny w Task 2 i ręczny krok w Task 8.
2. **`product.deleted`** po zmianie: handler nie ma już osobnej ścieżki, usunięcie wynika z 404 projekcji z `projected_at_us`. Test w Task 2.
3. **Cena z przecinkiem** („19,99”) z polskiej klawiatury ma przejść walidację jako 19.99, nie dawać błędu: test w Task 6.
4. **Edycja oferty innego produktu niż na URL-u** (oferta podpięta pod inny produkt): `PUT /panel/offers/{offer}` działa po samej ofercie i wraca na stronę *jej* produktu: test w Task 6.
5. **ES nieosiągalny** na stronie edycji: strona się renderuje, `trace.es_available = false`, polling się nie zapętla w nieskończoność z błędami (zatrzymuje się, gdy ES niedostępny): test w Task 7.

---

### Task 1: catalog: `projected_at_us` w projekcji

**Pliki:**
- Create: `apps/catalog/app/Support/DatabaseClock.php`
- Modify: `apps/catalog/app/Http/Controllers/Internal/ProductProjectionController.php`
- Test: `apps/catalog/tests/Feature/ProductProjectionTest.php` (dopisz testy)

**Interfejsy:**
- Produces: `App\Support\DatabaseClock::nowMicros(): int`. Na `pgsql` zwraca wynik SQL z ograniczeń globalnych, na innym sterowniku (SQLite w testach) `(int) (microtime(true) * 1_000_000)`. Klasa jest zwykłym serwisem (wstrzykiwana), bez stanu, więc bezpieczna pod Octane.
- Produces (kontrakt HTTP dla Task 2): 200 → dotychczasowe JSON + `"projected_at_us": int`; brak produktu → 404 z `{"projected_at_us": int}`.

- [ ] **Krok 1: testy, które padają** (w `ProductProjectionTest.php`):
  - `projekcja zawiera projected_at_us jako int`: `getJson(.../projection)` → `assertJsonPath` typu int (`expect($json['projected_at_us'])->toBeInt()->toBeGreaterThan(1_700_000_000_000_000)`).
  - `dwa kolejne odczyty dają rosnące projected_at_us`: drugi > pierwszy.
  - `brak produktu: 404 z projected_at_us`: `/api/internal/products/999999/projection` → 404, `projected_at_us` int.
- [ ] **Krok 2:** `php artisan test --compact tests/Feature/ProductProjectionTest.php`. Oczekiwane: 3 nowe FAIL.
- [ ] **Krok 3:** `DatabaseClock` + kontroler: `$projectedAtUs = $clock->nowMicros()` jako **pierwsza** instrukcja `show()` (komentarz: dlaczego przed odczytem, READ COMMITTED). `findOrFail` zamień na `find` i ręczne 404 z ciałem. Pole `projected_at_us` w odpowiedzi 200.
- [ ] **Krok 4:** testy z kroku 2 → PASS, plus cały plik zielony.
- [ ] **Krok 5:** sprawdź SQL na prawdziwym Postgresie: `docker compose exec -T catalog-app php artisan tinker --execute 'echo app(App\Support\DatabaseClock::class)->nowMicros();'` → liczba 16 cyfr.
- [ ] **Krok 6:** commit `Panel [1/8]: projekcja niesie projected_at_us (zegar Postgresa, przed odczytem)`.

### Task 2: search: wersja dokumentu = `projected_at_us`

**Pliki:**
- Create: `apps/search/src/Service/ProductProjection.php`
- Modify: `apps/search/src/Service/CatalogProjectionClient.php`, `apps/search/src/Service/ElasticsearchIndexer.php`, `apps/search/src/MessageHandler/ProductSyncHandler.php`, `apps/search/config/services.yaml` (tylko jeśli autowiring argumentu `alias` tego wymaga)
- Test: `apps/search/tests/Integration/ElasticsearchIndexerVersioningTest.php`, `apps/search/tests/Unit/MessageHandler/ProductSyncHandlerTest.php`

**Interfejsy:**
- Consumes: kontrakt HTTP z Task 1.
- Produces:
  - `final readonly class ProductProjection { public function __construct(public ?array $data, public int $projectedAtUs) {} public function exists(): bool }` (`$data === null` = produktu nie ma).
  - `CatalogProjectionClient::fetchProductProjection(string $productId): ProductProjection`. Brak lub nie-int `projected_at_us` (w 200 i w 404) → `ProjectionUnavailableException`.
  - `ElasticsearchIndexer::__construct(Client $client, string $alias = 'products-search')`.
  - `ElasticsearchIndexer::indexProduct(array $projection, int $docVersion): IndexOutcome` i `deleteProduct(string $productId, int $docVersion): IndexOutcome`.
  - Handler: zawsze pobiera projekcję (także dla `product.deleted`, usuń osobną gałąź), `exists()` → index, inaczej delete, oba z `$projection->projectedAtUs`. Log ma `sequence` i `doc_version`.

- [ ] **Krok 1: test integracyjny, który pada** `ElasticsearchIndexerVersioningTest` (`#[Group('integration')]`, ES z tej samej konfiguracji co aplikacja; brak ES → `markTestSkipped`). Tworzy jednorazowy indeks `products-versioning-test-<uniqid>` (mapowanie z `infra/elasticsearch/mappings/products-v1.json`), sprząta w `tearDown`. Indexer z `alias:` tego indeksu.
  - `test_druga_oferta_z_tym_samym_sequence_nie_ginie`: index wersją `1_000`, potem `2_000` z inną `price_min` → `Indexed`, dokument ma nową cenę.
  - `test_starszy_odczyt_przegrywa_i_jest_sukcesem`: po `2_000` index `1_500` → `Stale`, cena bez zmian.
  - `test_delete_starszy_niz_dokument_jest_stale`: delete `1_000` po `2_000` → `Stale`, dokument istnieje.
- [ ] **Krok 2: test jednostkowy, który pada** `ProductSyncHandlerTest` (mocki `CatalogProjectionClient`, `ElasticsearchIndexer`, `ProcessedEventRepository` zwracające `true`):
  - `offer.price_changed` → `indexProduct($data, 1790000000000123)` dla `ProductProjection(data, 1790000000000123)`.
  - `product.deleted` + `ProductProjection(null, 1790000000000456)` → `deleteProduct('7', 1790000000000456)`, `indexProduct` nie wołane.
  - klient rzuca `ProjectionUnavailableException` → handler rzuca `RecoverableMessageHandlingException`.
- [ ] **Krok 3:** `docker compose exec -T search-consumer vendor/bin/phpunit tests/Unit tests/Integration/ElasticsearchIndexerVersioningTest.php`. Oczekiwane: FAIL (brak klas i sygnatur).
- [ ] **Krok 4:** implementacja według interfejsów. Docblock „ZNANE OGRANICZENIE” w indexerze zastąp opisem zasady: wersja = czas odczytu, dlaczego to rozwiązuje kolizję agregatów, link do speca.
- [ ] **Krok 5:** testy z kroku 3 → PASS; `ProductSyncRetryTest` dalej zielony (albo skip z tym samym powodem co przed zmianą).
- [ ] **Krok 6: end-to-end lokalnie.** `docker compose restart search-consumer`, zmiana ceny **oferty 4** produktu 1 (ta z `version` 1, a dokument ma `_version` 3) przez tinker `updateWithOutbox(["price_cents" => 1999])` → `api/search?q=Batz-Beer%20Ultra%20Boost` pokazuje `price_min` 1999. Przywróć 87080. Dokument ma `_version` ~1.79e15.
- [ ] **Krok 7:** commit `Panel [2/8]: wersja dokumentu ES = czas odczytu projekcji (naprawa kolizji sequence między agregatami)`.

### Task 3: konto do panelu

**Pliki:**
- Create: `apps/catalog/app/Console/Commands/CreateAdminCommand.php` (`php artisan make:command`)
- Modify: `Makefile` (sekcja obok `seed` oraz obok `k3s-mq-status`)
- Test: `apps/catalog/tests/Feature/CreateAdminCommandTest.php`

**Interfejsy:**
- Produces: `php artisan marketplace:admin {email}` → linia `Hasło: <20 znaków>`, exit 0. Nowe konto: `name` = część maila przed `@`, `email_verified_at` = now.
- Makefile: `make admin email=...` → `$(DC) exec catalog-app php artisan marketplace:admin $(email)`; `make k3s-admin email=...` → `$(KUBECTL_VPS) -n marketplace exec deploy/catalog-app -- php artisan marketplace:admin $(email)` (z tym samym `nc -z ... || ssh -fN elastic-vps-k8s` co `k3s-mq-status`). Brak `email` → komunikat i exit 1.

- [ ] **Krok 1: testy, które padają:**
  - `tworzy konto z potwierdzonym mailem`: `artisan('marketplace:admin', ['email' => 'a@b.pl'])->expectsOutputToContain('Hasło:')->assertExitCode(0)`; user istnieje, `hasVerifiedEmail()` true.
  - `drugie wywołanie zmienia hasło`: wyłap hasło z outputu (`Artisan::call` + `Artisan::output()`), po drugim wywołaniu `Hash::check(stare, user->password)` false, `Hash::check(nowe, ...)` true; liczba userów 1.
  - `niepoprawny email`: exit code ≠ 0, brak usera.
- [ ] **Krok 2:** run → FAIL.
- [ ] **Krok 3:** implementacja (`Str::password(20, symbols: false)`, walidacja maila przez `Validator`).
- [ ] **Krok 4:** run → PASS. `make admin email=test@example.com` lokalnie wypisuje hasło (zapisz je tylko lokalnie do dalszych kroków, nie w repo i nie na czacie).
- [ ] **Krok 5:** commit `Panel [3/8]: marketplace:admin + make admin / k3s-admin`.

### Task 4: lista produktów + wspólne etykiety kategorii

**Pliki:**
- Create: `apps/catalog/app/Services/CategoryLabels.php`, `apps/catalog/app/Http/Controllers/Panel/ProductController.php`, `apps/catalog/resources/js/pages/panel/Products.vue`
- Modify: `apps/catalog/app/Http/Controllers/SearchController.php` (użyj `CategoryLabels`), `apps/catalog/routes/web.php`, `apps/catalog/resources/js/components/AppSidebar.vue` (link „Panel” w `mainNavItems`, tylko gdy zalogowany)
- Test: `apps/catalog/tests/Feature/Panel/ProductIndexTest.php`; istniejący `SearchCategoryFacetLabelsTest` musi zostać zielony.

**Interfejsy:**
- Produces: `CategoryLabels::forPaths(array $paths): array<string, string>` (ścieżka „5.6” → „Odzież › Buty do biegania”; brak którejś kategorii → sama ścieżka; jedno zapytanie `whereIn`). `SearchController::labelCategoryFacets` korzysta z tego i zachowuje sortowanie z `\x01`.
- Trasy (nazwane, Wayfinder): `panel.products.index` GET `/panel/products`. Kolejne zadania dokładają do tej samej grupy `Route::middleware('auth')->prefix('panel')->name('panel.')`.
- Props `panel/Products`: `products` (paginator: `id, name, brand, category_label, active_offers_count, min_price_cents`), `filters.q`.

- [ ] **Krok 1: testy, które padają** (`ProductIndexTest`, fabryki):
  - `gość jest przekierowany na login`: `get('/panel/products')->assertRedirect('/login')`.
  - `lista pokazuje produkty z etykietą kategorii i najniższą aktywną ceną`: 2 oferty aktywne (500, 300) + 1 nieaktywna (100) → `min_price_cents` 300, `active_offers_count` 2, `category_label` „Dział › Pod”.
  - `q liczbowe szuka po ID, tekst po nazwie bez wielkości liter`.
  - `25 na stronę`: 30 produktów → `products.data` ma 25.
- [ ] **Krok 2:** run → FAIL.
- [ ] **Krok 3:** implementacja. `ILIKE` nie istnieje w SQLite: użyj `whereRaw('lower(name) like ?', ['%'.mb_strtolower($q).'%'])`, działa w obu bazach. Strona: tabela shadcn, input `q` z debounce 300 ms (`router.get` z `preserveState`), wiersze bez linku (link do edycji dokłada Task 5).
- [ ] **Krok 4:** run → PASS, plus `SearchCategoryFacetLabelsTest` i `SearchPropsLazinessTest`.
- [ ] **Krok 5:** commit `Panel [4/8]: lista produktów, wspólne etykiety kategorii, link w sidebarze`.

### Task 5: edycja produktu

**Pliki:**
- Create: `apps/catalog/app/Http/Requests/Panel/UpdateProductRequest.php`, `apps/catalog/resources/js/pages/panel/ProductEdit.vue`
- Modify: `Panel/ProductController.php` (`edit`, `update`), `routes/web.php`, `Products.vue` (link wiersza)
- Test: `apps/catalog/tests/Feature/Panel/ProductUpdateTest.php`

**Interfejsy:**
- Trasy: `panel.products.edit` GET `/panel/products/{product}`, `panel.products.update` PUT `/panel/products/{product}`.
- Props `panel/ProductEdit`: `product {id, name, brand_id, category_id, version}`, `brands [{id, name}]`, `categories [{id, label}]` (tylko podkategorie, posortowane po etykiecie), `offers` (Task 6), `trace` (Task 7, na razie nie dodawaj).
- Walidacja: `name` required|string|max:255; `brand_id` required|exists:brands,id; `category_id` required, exists w `categories` z `parent_id` not null.
- `update`: `$product->fill($validated)`; `isDirty()` false → back z flash „Bez zmian”; true → `updateWithOutbox($validated, 'product.updated')`, back z „Zapisano, zdarzenie w drodze”. Flash przez mechanizm Inertia v3 (`Inertia::flash` albo `with()` udostępnione w `HandleInertiaRequests`, sprawdź, co już jest w middleware).

- [ ] **Krok 1: testy, które padają:**
  - `zmiana nazwy tworzy jedno zdarzenie product.updated i podbija wersję`: `Outbox::where('event_type','product.updated')->count()` 1, `version` +1.
  - `zapis bez zmian nie tworzy zdarzenia`: count 0, `version` bez zmian, flash „Bez zmian”.
  - `kategoria-dział jest odrzucana`: `assertSessionHasErrors('category_id')`.
  - `strona edycji ma tylko podkategorie z etykietami`: `has('categories', N)`, `categories.0.label` zawiera „ › ”.
- [ ] **Krok 2:** run → FAIL.
- [ ] **Krok 3:** implementacja + strona (`useForm`, selecty shadcn, flash nad formularzem).
- [ ] **Krok 4:** run → PASS + lint/types.
- [ ] **Krok 5:** commit `Panel [5/8]: edycja produktu (product.updated, bez zdarzenia gdy brak zmian)`.

### Task 6: oferty: cena, stan, dezaktywacja

**Pliki:**
- Create: `apps/catalog/app/Http/Controllers/Panel/OfferController.php`, `apps/catalog/app/Http/Requests/Panel/UpdateOfferRequest.php`, `apps/catalog/resources/js/components/panel/OfferRow.vue`
- Modify: `routes/web.php`, `Panel/ProductController@edit` (prop `offers`), `ProductEdit.vue`
- Test: `apps/catalog/tests/Feature/Panel/OfferUpdateTest.php`

**Interfejsy:**
- Trasy: `panel.offers.update` PUT `/panel/offers/{offer}`, `panel.offers.deactivate` POST `/panel/offers/{offer}/deactivate`. Obie po zapisie: `redirect()->route('panel.products.edit', $offer->product_id)`.
- Prop `offers [{id, seller, price_cents, stock, active, version}]`, posortowane po `id`.
- `UpdateOfferRequest`: `prepareForValidation` zamienia `,` na `.` w `price`; reguły `price` required|numeric|gt:0|lte:1000000, `stock` required|integer|min:0; metoda `attributesForUpdate(): array{price_cents: int, stock: int}` (`(int) round(price * 100)`).
- Brak zmian → bez zdarzenia (jak w Task 5). Dezaktywacja nieaktywnej → back z flash „Oferta już nieaktywna”, bez zdarzenia.

- [ ] **Krok 1: testy, które padają:**
  - `cena i stan naraz dają dwa zdarzenia`: `price=19.99, stock=5` → outbox `offer.price_changed` i `offer.stock_changed`, `price_cents` 1999.
  - `cena z przecinkiem jest akceptowana`: `price='19,99'` → `price_cents` 1999.
  - `cena 0 i ujemny stan są odrzucane`: błędy `price`, `stock`.
  - `dezaktywacja daje offer.deactivated`; `ponowna dezaktywacja nie daje zdarzenia`.
  - `redirect wraca na stronę produktu oferty`: oferta produktu B → `assertRedirect(route('panel.products.edit', B))`.
  - `gość nie może zapisać oferty`: redirect na login, brak zdarzeń.
- [ ] **Krok 2:** run → FAIL.
- [ ] **Krok 3:** implementacja. `OfferRow.vue`: własny `useForm` na wiersz (cena w zł z `zlotyFromCents`, `preserveScroll`), przycisk „Dezaktywuj” tylko przy aktywnej, z `confirm()`.
- [ ] **Krok 4:** run → PASS + lint/types.
- [ ] **Krok 5:** commit `Panel [6/8]: oferty: cena, stan, dezaktywacja przez outbox`.

### Task 7: droga zdarzenia

**Pliki:**
- Create: `apps/catalog/app/Services/Search/IndexedProductReader.php`, `apps/catalog/app/Services/Panel/EventTrace.php`, `apps/catalog/resources/js/components/panel/EventTrace.vue`
- Modify: `Panel/ProductController@edit` (prop `trace`), `ProductEdit.vue`, `resources/js/types/` (typ `Trace`)
- Test: `apps/catalog/tests/Feature/Panel/EventTraceTest.php`

**Interfejsy:**
- `IndexedProductReader::find(int $productId): ?array{version: int, source: array}`. `GET products-search/_doc/{id}` przez `Elastic\Elasticsearch\Client`; 404 → null; inne błędy rzuca dalej.
- `EventTrace::forProduct(Product $product): array` →
  `{es_available: bool, events: [{id, type, occurred_at, published: bool, in_es: bool|null}], comparison: [{field, postgres, es, differs: bool}], pending: bool}`.
  - `in_es` = `es_version >= occurred_at_us` (µs z `occurred_at`), `null` gdy `es_available` false.
  - `comparison`: pola `name`, `category` (etykieta z `CategoryLabels` po obu stronach), `price_min`, `in_stock`; strona Postgres liczona jak w projekcji (aktywne oferty).
  - `pending` = `es_available && któreś in_es === false`.
  - Wyjątek z readera → `es_available` false, `comparison` z `es: null`. Nie łap `Throwable` szerzej niż wyjątki klienta ES i transportu.
- Prop `trace` jako closure w `edit` (partial reload `only: ['trace']` nie liczy reszty).
- Front: `usePoll(1000, { only: ['trace'] }, { autoStart: false })`, `watch(() => props.trace.pending, p => p ? start() : stop(), { immediate: true })`. Kroki jako trzy znaczniki (zapisane / opublikowane / w ES), różnice w porównaniu wyróżnione.

- [ ] **Krok 1: testy, które padają** (`IndexedProductReader` mockowany przez `$this->mock`):
  - `zdarzenie po wersji dokumentu nie jest jeszcze w ES`: outbox `occurred_at` = T, reader `version` = T_us − 1 → `in_es` false, `pending` true.
  - `zdarzenie przed wersją dokumentu jest w ES`: version = T_us + 1 → `in_es` true, `pending` false.
  - `nieopublikowane zdarzenie`: `published_at` null → `published` false.
  - `bierze zdarzenia produktu i jego ofert, max 10, najnowsze pierwsze`: 12 zdarzeń (produkt + 2 oferty) i 1 zdarzenie oferty innego produktu → 10, bez obcego.
  - `ES nieosiągalny`: reader rzuca `NoNodeAvailableException` → `es_available` false, `in_es` null, `pending` false, strona 200.
  - `porównanie wyróżnia różną cenę`: Postgres min 300, ES `price_min` 500 → `differs` true.
- [ ] **Krok 2:** run → FAIL.
- [ ] **Krok 3:** implementacja.
- [ ] **Krok 4:** run → PASS + lint/types.
- [ ] **Krok 5: ręcznie lokalnie** (zrzut przez `agent-browser --ignore-https-errors`, `https://catalog.localhost:8443/panel/products`, logowanie kontem z Task 3): zmiana ceny drugiej oferty → kroki zapalają się po kolei, polling staje; `docker compose stop search-consumer` → zdarzenie stoi na „opublikowane”, porównanie pokazuje różnicę; `start` → dogania.
- [ ] **Krok 6:** commit `Panel [7/8]: droga zdarzenia: outbox → RabbitMQ → ES na żywo`.

### Task 8: przewodnik, wdrożenie, weryfikacja na k3s

**Pliki:**
- Create: `docs/blog/panel-edycji.md`
- Modify: `docs/blog/rabbitmq-podglad.md` (eksperymenty przez panel), `README.md` (link)

- [ ] **Krok 1:** przewodnik według memory „etap skończony”: co budujemy, kod z wyjaśnieniem, kolizja wersji z pomiarem (`_version` 3 vs `version` 1) i dlaczego wariant 1, okno niedokładności kroku „w ES”, jak sprawdzić, błędy po drodze z diagnozą, lista ręcznej weryfikacji, zadania domowe.
- [ ] **Krok 2:** pełne testy: `php artisan test --compact` (catalog), `docker compose exec -T search-consumer vendor/bin/phpunit` (search). Oczekiwane: zielone; skipy tylko te, co przed zmianą.
- [ ] **Krok 3:** commit `Panel [8/8]: przewodnik + rabbitmq-podglad przez panel`, `git push origin main`, poczekaj na `images` w CI (`gh run watch`).
- [ ] **Krok 4:** `make k3s-deploy tag=<SHA>` → „OK — działa wersja …”; `make k3s-verify` → `FAIL=0`.
- [ ] **Krok 5:** konto na k3s zakłada użytkownik sam (`make k3s-admin email=...`), żeby hasło nie szło przez czat. Do weryfikacji użyj własnego, tymczasowego konta testowego i usuń je potem (`User::where('email', ...)->delete()` przez `kubectl exec`). Przez `make k3s-ui`: zmiana ceny drugiej oferty → widać w `/search` i w drodze zdarzenia; `make k3s-mq-status` po wszystkim → kolejki puste.
