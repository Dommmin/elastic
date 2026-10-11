# Panel edycji katalogu: zmiana z przeglądarki, którą widać aż do Elasticsearcha

*Projekt Marketplace Search Platform, po ETAPIE D2. Do tej pory zmianę w katalogu
robiło się w tinkerze i trzeba było wierzyć, że dojdzie do wyszukiwarki. Teraz
robi się ją w panelu `/panel`, a ta sama strona pokazuje, gdzie zdarzenie
jest: w outboxie, w RabbitMQ czy już w ES. Po drodze wyszedł błąd, który
leżał w kodzie od ETAPU 6: druga oferta tego samego produktu mogła po cichu
nie trafić do wyszukiwarki.*

> **Stan:** kod w repo (commity `Panel [1/8]` … `Panel [8/8]`), testy zielone
> lokalnie (catalog: Pest, search: PHPUnit z żywym ES). Ręczna weryfikacja
> lokalnie: zmiana ceny, zatrzymany consumer, dogonienie po starcie. Plan:
> [`docs/superpowers/plans/2026-10-11-panel-edycji.md`](../superpowers/plans/2026-10-11-panel-edycji.md),
> projekt: [`docs/superpowers/specs/2026-10-11-panel-edycji-design.md`](../superpowers/specs/2026-10-11-panel-edycji-design.md).

---

## Spis treści

1. [Po co panel](#1-po-co-panel)
2. [Krok 1 — Błąd, który panel wyciągnął na wierzch: kolizja wersji](#krok-1--błąd-który-panel-wyciągnął-na-wierzch-kolizja-wersji)
3. [Krok 2 — Wersja dokumentu = chwila odczytu projekcji](#krok-2--wersja-dokumentu--chwila-odczytu-projekcji)
4. [Krok 3 — Kto może wejść do panelu](#krok-3--kto-może-wejść-do-panelu)
5. [Krok 4 — Lista i edycja produktu](#krok-4--lista-i-edycja-produktu)
6. [Krok 5 — Oferty: cena z przecinkiem, stan, dezaktywacja](#krok-5--oferty-cena-z-przecinkiem-stan-dezaktywacja)
7. [Krok 6 — Droga zdarzenia na żywo](#krok-6--droga-zdarzenia-na-żywo)
8. [Jak sprawdzić: lista kontrolna](#jak-sprawdzić-lista-kontrolna)
9. [Znane ograniczenia](#znane-ograniczenia)
10. [Zadania domowe](#zadania-domowe)
11. [Mapa plików](#mapa-plików)

---

## 1. Po co panel

Wyszukiwarka nie czyta Postgresa. Każda zmiana idzie drogą:

```
panel (PUT /panel/offers/4)
   │  Offer::updateWithOutbox(): zmiana + wiersz outboxu w jednej transakcji
   ▼
tabela outbox ──► outbox-publisher ──► RabbitMQ (marketplace.events → search.product.sync)
                                              │
                                              ▼
                        search-consumer ── GET /api/internal/products/{id}/projection
                                              │
                                              ▼
                                   ES products-search (wersjonowany zapis)
```

Panel mógłby pisać prosto do bazy i ręcznie wołać reindeks. Wtedy uczyłby
czegoś, czego produkcja nie robi. Dlatego każdy zapis z panelu idzie przez
`updateWithOutbox()`, czyli dokładnie tą samą drogą, którą szły zmiany
z tinkera i seedera. Do tego strona produktu **pokazuje** tę drogę: trzy
lampki przy każdym zdarzeniu i porównanie „Postgres a dokument w ES”.

Cel, który się da sprawdzić:

1. Zmiana ceny dowolnej oferty, także drugiej i trzeciej oferty tego samego
   produktu, po kilku sekundach jest w `/search`.
2. Gdy `search-consumer` stoi, zdarzenia stoją na kroku „opublikowane”.
3. Bez zalogowania (i bez uprawnienia admina) panelu nie ma.

Punkt 1 brzmi banalnie, a przed tą pracą **nie był spełniony**. O tym jest
następny krok.

---

## Krok 1 — Błąd, który panel wyciągnął na wierzch: kolizja wersji

### Jak działało wersjonowanie

`ElasticsearchIndexer` zapisywał dokument z `version_type: external`
i `version = sequence` zdarzenia. ES przyjmuje zapis tylko wtedy, gdy
wersja jest **większa** niż ta, którą dokument już ma. Inaczej odpowiada
409. To chroni przed zdarzeniami, które przyszły w złej kolejności: spóźniony,
starszy zapis nie nadpisze nowszego.

Problem w tym, skąd brał się `sequence`. To kopia kolumny `version` agregatu,
który wywołał zdarzenie: `products.version` dla `product.*`, `offers.version`
dla `offer.*`. Każda oferta ma **własny** licznik, liczony od 1. A dokument
ES jest jeden na produkt i powstaje z produktu i wszystkich jego ofert.

Kod przed zmianą (`apps/search/src/Service/ElasticsearchIndexer.php`):

```php
public function indexProduct(array $projection, int $sequence): IndexOutcome
{
    // ...
    $this->client->index([
        'index' => self::ALIAS,
        'id' => $productId,
        'body' => $document,
        'version' => $sequence,          // licznik JEDNEGO agregatu
        'version_type' => 'external',
    ]);
```

### Pomiar, nie teoria

Ten błąd był znany od ETAPU 6 ([RUNBOOK #017](../RUNBOOK.md#017)) jako
„zwykle nie szkodzi”. Zmierzone lokalnie przed tą pracą:

| Co | Wartość |
|---|---|
| dokument `products-search/_doc/1`, `_version` | **3** (dwie zmiany oferty 1) |
| oferta 4 tego produktu (najtańsza, 870,80 zł), `version` | **1** |

Zmiana ceny oferty 4 podbija jej `version` do 2, więc zdarzenie ma
`sequence: 2`. ES ma już wersję 3, odpowiada 409. Handler traktuje 409 jako
sukces („ktoś zaindeksował nowszą wersję”), loguje to na poziomie `debug`
i potwierdza wiadomość. W Postgresie jest nowa cena, w wyszukiwarce stara,
a w logach nic niepokojącego.

Z tinkera trafiało się w to rzadko, bo zwykle zmieniało się jedną ofertę.
Panel z tabelą ofert trafiałby w to przy pierwszym kliknięciu w drugi wiersz.

### Trzy możliwe naprawy

1. **Jeden licznik na dokument w search-service** (kierunek zapisany w RUNBOOK
   #017): consumer trzyma tabelę „ostatnia wersja dokumentu X” i sam nadaje
   kolejne numery. Działa, ale to nowy stan do utrzymania, a numer nadany
   przy *przetwarzaniu* nie mówi nic o tym, jak świeże dane przyszły
   z catalogu. Dwa consumery równolegle musiałyby się o ten licznik kłócić.
2. **`occurred_at` zdarzenia jako wersja.** Jedna oś czasu dla wszystkich
   agregatów, ale to czas *zdarzenia*, a consumer indeksuje *projekcję*, czyli
   stan odczytany później. Projekcja pobrana dla starego zdarzenia może
   zawierać już nowsze zmiany. Wersja opisywałaby więc co innego niż dane.
3. **Chwila odczytu projekcji** (wybrana): wersja dokumentu to moment,
   w którym catalog odczytał stan produktu. Consumer i tak przy każdym
   zdarzeniu pobiera pełny, aktualny stan produktu. Skoro dokument to zawsze
   „stan z chwili T”, to wersją powinno być właśnie T. Nieważne, który
   agregat się zmienił.

---

## Krok 2 — Wersja dokumentu = chwila odczytu projekcji

### Catalog: zegar przed odczytem

`apps/catalog/app/Support/DatabaseClock.php`:

```php
public function nowMicros(): int
{
    if (DB::connection()->getDriverName() === 'pgsql') {
        return (int) DB::scalar('select (extract(epoch from clock_timestamp()) * 1000000)::bigint');
    }

    // SQLite w testach nie ma odpowiednika clock_timestamp().
    return (int) (microtime(true) * 1_000_000);
}
```

Dwie decyzje w jednej linijce SQL:

- **Zegar Postgresa, nie PHP.** Repliki `catalog-app` mają osobne zegary
  kontenerów. Baza jest jedna, więc jedna jest oś czasu.
- **`clock_timestamp()`, nie `now()`.** `now()` w Postgresie to początek
  transakcji i stoi w miejscu, dopóki ona trwa. Potrzebna jest chwila
  faktycznego wywołania.

`apps/catalog/app/Http/Controllers/Internal/ProductProjectionController.php`:

```php
public function show(int $id, DatabaseClock $clock): JsonResponse
{
    // Czas MUSI być zmierzony przed odczytem danych produktu. (...)
    $projectedAtUs = $clock->nowMicros();

    $product = Product::with(['brand', 'category', 'activeOffers.seller'])
        // ...
        ->find($id);

    if ($product === null) {
        return response()->json(['projected_at_us' => $projectedAtUs], 404);
    }

    return response()->json([
        'id' => $product->id,
        'projected_at_us' => $projectedAtUs,
        // ...
```

**Dlaczego przed odczytem.** Postgres działa w READ COMMITTED: każde
zapytanie widzi wszystko, co było zatwierdzone w chwili jego startu. Jeśli
zegar odczytamy *przed* danymi, dane są co najmniej tak świeże jak wersja.
Zapis zatwierdzony po pomiarze mógł się nie załapać, ale jego własne zdarzenie
da projekcję z późniejszym czasem i ta nadpisze dokument. Odwrotna kolejność
(dane, potem zegar) mogłaby opisać stare dane nowym czasem i zablokować
prawdziwie nowszy zapis.

**Dlaczego 404 też niesie czas.** Usunięcie dokumentu jest wersjonowane tak
samo jak zapis. Spóźnione „produkt nie istnieje” nie może skasować dokumentu,
który powstał po nim.

### Search: brak pola = ponowienie, nie zgadywanie

`apps/search/src/Service/CatalogProjectionClient.php`:

```php
private function projectedAtUs(array $body, string $productId): int
{
    if (! \is_int($body['projected_at_us'] ?? null)) {
        throw new ProjectionUnavailableException(
            "Projekcja produktu {$productId} bez całkowitego projected_at_us — nie zgaduję wersji dokumentu.",
        );
    }

    return $body['projected_at_us'];
}
```

`ProjectionUnavailableException` handler zamienia na
`RecoverableMessageHandlingException`, więc Messenger ponawia wiadomość.
Kiedy to się zdarza? Podczas wdrożenia, gdy nowy consumer już działa,
a stary catalog jeszcze odpowiada. Zgadnięta wersja (np. `0` albo `time()`)
albo przegrałaby po cichu z dokumentem, albo zablokowała przyszłe zapisy.
Ponowień jest kilka w ciągu ok. 7 s, potem wiadomość ląduje w transporcie
`failed` — rollout catalogu trwa zwykle dłużej. Dlatego `make k3s-deploy`
nie dopuszcza do tej sytuacji: na czas zmiany catalogu zatrzymuje consumer
(szczegóły w liście kontrolnej k3s). Ten wyjątek zostaje jako bezpiecznik,
gdyby ktoś wdrażał ręcznie.

### Search: jedna ścieżka dla zapisu i usunięcia

`apps/search/src/Service/ProductSynchronizer.php` (woła go
`ProductSyncHandler`):

```php
// Wersja dokumentu = czas odczytu projekcji, NIE $event->sequence
if ($projection->exists()) {
    return new ProductSyncResult($this->indexer->indexProduct($projection->data, $projection->projectedAtUs), /* … */);
}

return new ProductSyncResult($this->indexer->deleteProduct($productId, $projection->projectedAtUs), /* … */);
```

Wcześniej `product.deleted` miał osobną gałąź, która kasowała dokument bez
pytania catalogu. Teraz o usunięciu decyduje 404 projekcji. Jeśli produkt
w międzyczasie wrócił, świeższy odczyt wygra. Log „Zaindeksowano produkt”
ma teraz oba numery: `sequence` (żeby znaleźć zdarzenie) i `doc_version`
(wersja dokumentu).

Mapowanie `products-v1` ma `dynamic: strict`, więc `toEsDocument()` musi
wyciąć `projected_at_us` z `_source`. Bez tego każdy zapis kończyłby się
błędem mapowania:

```php
unset($document['id'], $document['version'], $document['projected_at_us']);
```

### Jednorazowe przeliczenie wersji: `search:reproject`

Stare `_version` w ES to 1–3. Nowe to mikrosekundy od epoki, ok. 1,79·10¹⁵.
Pierwsze zdarzenie po wdrożeniu nadpisze dokument, bo nowa wersja jest
większa o kilkanaście rzędów wielkości. Ale tylko dokument produktu, który
to zdarzenie dostał. Produkt, którego nikt nie ruszył, zostaje z `_version` 1.
Zapisom to nie przeszkadza. Przeszkadza drodze zdarzenia z kroku 6: porównuje
`_version` z `occurred_at` w µs, więc taki produkt wisi jako „nie w ES”,
a strona odpytuje co sekundę bez końca.

Pełny reindeks do nowego indeksu tu nie jest potrzebny, wystarczy przepisać
każdy dokument z aktualną wersją. Robi to komenda
`apps/search/src/Command/ReprojectCommand.php`:

```bash
make reproject          # lokalnie (Compose)
make k3s-reproject      # na k3s, raz po wdrożeniu tej zmiany
```

Przechodzi scrollem po wszystkich `_id` w aliasie `products-search`
(`_source: false`, paczki po 500). Dla każdego pobiera projekcję i robi
dokładnie to, co handler kolejki. Ta decyzja siedzi w jednej klasie,
`ProductSynchronizer`, której używają oba:

```php
if ($projection->exists()) {
    return new ProductSyncResult(
        $this->indexer->indexProduct($projection->data, $projection->projectedAtUs),
        deleted: false,
        docVersion: $projection->projectedAtUs,
    );
}
```

Niedostępna projekcja jednego produktu liczy się jako błąd, a komenda idzie
dalej. Na końcu wypisuje podsumowanie:

```
 [OK] Zaindeksowano: 1500, nieaktualne (409): 0, usunięte: 0, błędy: 0
```

Przy błędach kod wyjścia ≠ 0. Komendę można wtedy po prostu puścić
jeszcze raz. Wolno ją uruchomić przy działającym consumerze: wyścig
rozstrzyga ES, a starszy odczyt dostaje 409 i trafia do „nieaktualne”.
Test: `apps/search/tests/Integration/Command/ReprojectCommandTest.php`
(dokument z `_version` 1 dostaje wersję z projekcji, a produkt z 404 znika).

### Test regresyjny na żywym ES

`apps/search/tests/Integration/ElasticsearchIndexerVersioningTest.php` tworzy
jednorazowy indeks z prawdziwego mapowania i odtwarza scenariusz wprost:

```php
public function test_druga_oferta_z_tym_samym_sequence_nie_ginie(): void
{
    self::assertSame(IndexOutcome::Indexed, $this->indexer->indexProduct($this->projection(priceMin: 87080), 1_000));
    self::assertSame(IndexOutcome::Indexed, $this->indexer->indexProduct($this->projection(priceMin: 1999), 2_000));

    self::assertSame(1999, $this->document()['_source']['price_min']);
    self::assertSame(2_000, $this->document()['_version']);
}
```

Obok: starszy odczyt przegrywa i to jest `Stale` (sukces), a usunięcie
starsze niż dokument go nie kasuje.

### Sprawdzone end-to-end

Lokalnie, przed zmianą: `_doc/1` miał `_version` 3. Po restarcie
`search-consumer` zmiana ceny oferty 4 na 19,99 zł:

```
Zaindeksowano produkt. {"event_type":"offer.price_changed","sequence":2,"doc_version":1791730685612428}
```

`sequence` 2 < stare `_version` 3, a mimo to zapis przeszedł, bo wersją jest
już `doc_version`. `/api/search` zwrócił `price_min: 1999` z najtańszą ofertą
4. Cena wróciła potem do 870,80 zł tą samą drogą.

---

## Krok 3 — Kto może wejść do panelu

### Pierwsza wersja i dlaczego nie wystarczyła

Projekt zakładał: panel za samym `auth` („każdy zalogowany może edytować”).
Automatyczny przegląd bezpieczeństwa po pierwszym commicie panelu zgłosił
dwie rzeczy:

1. **Wysokie:** rejestracja Fortify jest otwarta. Samo `auth` oznacza, że
   każdy, kto założy konto na `/register`, edytuje katalog.
2. **Średnie:** komenda zakładająca konto nadpisywała hasło istniejącego
   konta i oznaczała je jako zweryfikowane.

### Flaga `is_admin` i Gate `panel`

Migracja dodaje `users.is_admin` (boolean, domyślnie `false`). Flaga
**nie jest w `$fillable`**, więc żadne `User::create($request->all())` jej
nie ustawi. Test rejestracji to sprawdza: `is_admin=1` w żądaniu jest
ignorowane, a `/panel` daje 403.

`apps/catalog/app/Providers/AppServiceProvider.php`:

```php
Gate::define('panel', fn (User $user): bool => $user->is_admin);
```

`apps/catalog/routes/web.php`:

```php
// Panel edycji katalogu: `can:panel` (flaga is_admin), bo rejestracja jest
// otwarta. Bez `verified` — konta zakłada `marketplace:admin`,
// bez potwierdzania e-maila.
Route::middleware(['auth', 'can:panel'])->prefix('panel')->name('panel.')->group(function () {
    Route::get('products', [ProductController::class, 'index'])->name('products.index');
    Route::get('products/{product}', [ProductController::class, 'edit'])->name('products.edit');
    Route::put('products/{product}', [ProductController::class, 'update'])->name('products.update');
    Route::put('offers/{offer}', [OfferController::class, 'update'])->name('offers.update');
    Route::post('offers/{offer}/deactivate', [OfferController::class, 'deactivate'])->name('offers.deactivate');
});
```

Bez `verified`, bo maile idą do logu (`MAIL_MAILER=log`) i potwierdzanie
adresu oznaczałoby szukanie linku w logach. Link „Panel” w sidebarze widzi
tylko admin. Innym nie pokazujemy linku, który kończyłby się 403.

Szczegół, który wyszedł w testach: świeży model `User` bez wartości
w `$attributes` miał `is_admin = null`, a Gate z typem `bool` rzucał
TypeError (500 zamiast 403). Stąd `$attributes = ['is_admin' => false]`
w modelu.

### Komenda `marketplace:admin`

`apps/catalog/app/Console/Commands/CreateAdminCommand.php`, w skrócie:

```php
$user = User::firstOrNew(['email' => $email]);
$nowe = ! $user->exists;
if ($nowe) {
    $user->name = Str::before($email, '@');
    $user->email_verified_at = now();
}
if (! $nowe && $user->email_verified_at === null) {
    $this->error("Konto {$email} istnieje i nie ma potwierdzonego adresu — odmawiam nadania uprawnień administratora.");

    return self::FAILURE;
}

$user->password = $password; // cast 'hashed' haszuje
$user->is_admin = true; // przypisanie, nie fill — flaga nie jest mass-assignable

DB::transaction(function () use ($user, $nowe) {
    if (! $nowe) {
        $user->setRememberToken(Str::random(60));
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
    }
    $user->save();
});
```

Trzy przypadki i dlaczego każdy jest taki:

| Konto | Co robi komenda | Dlaczego |
|---|---|---|
| nie istnieje | tworzy, `email_verified_at`, `is_admin`, losowe hasło | operator podał adres sam, więc go „potwierdza” |
| istnieje, adres potwierdzony | nowe hasło, `is_admin`, nowy `remember_token`, usunięcie sesji | bez tego ktoś zalogowany starym hasłem (albo „zapamiętaj mnie”) zostałby w środku i dostałby uprawnienia admina |
| istnieje, adres **niepotwierdzony** | odmowa, exit 1, zero zmian w bazie | to drugie zgłoszenie z przeglądu: ktoś obcy mógł się zarejestrować na adres operatora, zanim operator wpisał komendę. Nadanie admina przekazałoby mu panel |

Hasło: `Str::password(20, symbols: false)`, wypisane raz na stdout, nigdzie
nie zapisane. Usuwanie sesji działa tylko przy `SESSION_DRIVER=database`
(domyślne w `config/session.php` i ustawione w `.env` catalogu). Przy plikach czy Redisie nie da się wybrać sesji
po użytkowniku.

Skutek uboczny, o którym warto wiedzieć: jeśli zarejestrowałeś się sam przez
`/register`, Twoje konto jest niepotwierdzone i komenda go nie podniesie.
Użyj innego adresu.

```bash
make admin email=ty@example.com          # lokalnie (Compose)
make k3s-admin email=ty@example.com      # na k3s (kubectl exec deploy/catalog-app)
```

---

## Krok 4 — Lista i edycja produktu

### Lista: `GET /panel/products`

Tabela z Postgresa (nie z ES, bo panel pokazuje źródło prawdy): ID, nazwa,
marka, kategoria, liczba aktywnych ofert, najniższa cena aktywnej oferty.
25 na stronę. Pole wyszukiwania: liczba to dokładne ID, tekst to fragment
nazwy.

```php
->when($q !== '', function ($query) use ($q) {
    // `ILIKE` nie istnieje w SQLite (testy), `lower() like` działa w obu bazach.
    ctype_digit($q)
        ? $query->where('id', (int) $q)
        : $query->whereRaw('lower(name) like ?', ['%'.mb_strtolower($q).'%']);
})
```

Etykiety kategorii („Dział › Podkategoria”) liczy `CategoryLabels::forPaths()`
jednym zapytaniem `whereIn`. Ta sama klasa obsługuje teraz facet kategorii
w `/search`, więc etykiety w panelu i w wyszukiwarce się nie rozjadą.

### Edycja: zapis bez zmian = brak zdarzenia

`updateWithOutbox()` zawsze zapisuje zdarzenie i podbija wersję. Dwuklik
w „Zapisz” dałby dwa zdarzenia i dwa reindeksy tego samego. Dlatego
kontroler sprawdza najpierw, czy coś się zmieniło
(`apps/catalog/app/Http/Controllers/Panel/ProductController.php`):

```php
public function update(UpdateProductRequest $request, Product $product): RedirectResponse
{
    $validated = $request->validated();

    if (! $product->fill($validated)->isDirty()) {
        Inertia::flash('status', 'Bez zmian');

        return back();
    }

    $product->updateWithOutbox($validated, 'product.updated');
    Inertia::flash('status', 'Zapisano, zdarzenie w drodze');

    return back();
}
```

Kategoria w formularzu to tylko podkategorie (`parent_id` nie null), bo
produkty w indeksie mają ścieżkę liścia.

---

## Krok 5 — Oferty: cena z przecinkiem, stan, dezaktywacja

### Cena z polskiej klawiatury

Reguła `numeric` rozumie tylko kropkę, a człowiek wpisze „19,99”.
`apps/catalog/app/Http/Requests/Panel/UpdateOfferRequest.php`:

```php
protected function prepareForValidation(): void
{
    if (is_string($this->input('price'))) {
        $this->merge(['price' => str_replace(',', '.', trim($this->input('price')))]);
    }
}

public function rules(): array
{
    return [
        'price' => ['required', 'numeric', 'gt:0', 'lte:1000000'],
        'stock' => ['required', 'integer', 'min:0'],
    ];
}

public function attributesForUpdate(): array
{
    return [
        'price_cents' => (int) round((float) $this->validated('price') * 100),
        'stock' => (int) $this->validated('stock'),
    ];
}
```

W formularzu złotówki, w bazie grosze. Przeliczenie siedzi w requeście,
żeby kontroler nie wiedział o formacie formularza.

### Zdarzenia z oferty

`Offer::updateWithOutbox()` sam wybiera typ zdarzenia po zmienionym polu:
cena → `offer.price_changed`, stan → `offer.stock_changed`. Zmiana obu
w jednym zapisie daje dwa wiersze outboxu. „Dezaktywuj” daje
`offer.deactivated`. Ponowny klik na nieaktywnej ofercie nie dubluje
zdarzenia, tylko pokazuje „Oferta już nieaktywna”.

### Redirect na produkt oferty, nie `back()`

```php
// Oba zapisy wracają na stronę produktu *oferty*, a nie na `back()`:
// oferta może należeć do innego produktu niż strona, z której przyszło żądanie.
return redirect()->route('panel.products.edit', $offer->product_id);
```

`PUT /panel/offers/{offer}` działa po samej ofercie. Gdyby wracał na
`back()`, po edycji oferty innego produktu niż ten na ekranie użytkownik
patrzyłby na drogę zdarzenia złego produktu i nie widziałby swojej zmiany.
Jest na to test.

---

## Krok 6 — Droga zdarzenia na żywo

### Trzy kroki

Sekcja „Droga zdarzenia” pod ofertami pokazuje 10 ostatnich wierszy outboxu
produktu **i wszystkich jego ofert** (także nieaktywnych, bo dezaktywacja
to też zdarzenie). Przy każdym trzy lampki:

| Krok | Skąd wiadomo |
|---|---|
| **zapisane** | wiersz w outboxie istnieje |
| **opublikowane** | `published_at` niepuste (outbox-publisher wysłał do RabbitMQ) |
| **w ES** | `_version` dokumentu produktu ≥ `occurred_at` zdarzenia w µs |

Krok 3 działa tylko dzięki Krokowi 2 tego przewodnika. `_version` to teraz
czas odczytu projekcji w µs, więc „wersja nie starsza niż chwila zdarzenia”
znaczy „ES widział stan po tym zdarzeniu”. Przy starym `sequence` takie
porównanie byłoby bez sensu.

`apps/catalog/app/Services/Panel/EventTrace.php`:

```php
'in_es' => $esAvailable
    ? $esVersion !== null && $esVersion >= (int) $event->occurred_at->format('Uu')
    : null,
```

`format('Uu')` skleja sekundy epoki (`U`) z mikrosekundami (`u`, 6 cyfr), co
daje dokładnie tę samą jednostkę co `projected_at_us`.

Wersję i `_source` dokumentu daje jeden `GET products-search/_doc/{id}`
(`IndexedProductReader`). Ta sama odpowiedź zasila tabelę porównania
Postgres a ES: nazwa, kategoria, najniższa cena, dostępność. Różnice są
oznaczone „(różni się)”.

### Błąd znaleziony przy implementacji: `occurred_at` co do sekundy

Pierwsza wersja kroku 3 nie przeszła testu, w którym zdarzenie ma ułamek
sekundy (`.250000`), a dokument wersję o 1 µs mniejszą. Test oczekiwał
„jeszcze nie w ES”, dostał „w ES”. Diagnoza w dwóch warstwach:

1. Model `Outbox` zapisywał datę w domyślnym formacie `Y-m-d H:i:s`, czyli
   bez ułamka.
2. Ręczna kontrola w bazie pokazała, że nawet z ułamkiem nic by to nie dało:
   kolumna `outbox.occurred_at` to `timestamptz(0)`, więc **Postgres sam
   zaokrąglał do pełnej sekundy** (w bazie `15:20:52+00`, bez ułamka).

Dlaczego to ma znaczenie, w obie strony:

- **Zaokrąglenie w górę wiesza polling.** Zdarzenie z 12:00:00,7 zapisane
  jako 12:00:01. Projekcja z 12:00:00,9 zawiera już tę zmianę, ale
  0,9 < 1,0, więc „w ES” się nie zapala. Strona odpytuje co sekundę, dopóki
  nie przyjdzie jakieś inne zdarzenie.
- **Obcięcie w dół** zapala lampkę do sekundy za wcześnie, dla projekcji
  sprzed zdarzenia.

Poprawka: migracja
`2026_10_11_152126_change_outbox_occurred_at_to_microseconds.php`
(`timestampTz('occurred_at', 6)->change()`) i w modelu:

```php
protected $dateFormat = 'Y-m-d H:i:s.u';
```

Po migracji nowe wiersze mają `15:22:29.456463+00`. Stare wiersze bez
ułamka czytają się dalej poprawnie. Na k3s migrację wykonuje Job `migrate`
w `make k3s-deploy`. `published_at` zostało przy sekundach, bo panel sprawdza
tylko, czy jest puste.

Lekcja: gdy porównujesz dwa znaczniki czasu, sprawdź precyzję **każdej
warstwy**: formatu w PHP, typu kolumny i tego, co faktycznie leży w bazie.

### Znane okno niedokładności

`occurred_at` ustawia PHP (`now()`) w transakcji, **przed** commitem.
Między tą chwilą a commitem mija kilka milisekund. Jeśli w tym oknie consumer
odczyta projekcję produktu z powodu **innego** zdarzenia, dostanie wersję
większą niż `occurred_at`, ale bez tej zmiany (jeszcze niezatwierdzonej).
Lampka „w ES” zapali się wtedy chwilę za wcześnie. Nic się nie gubi: własne
zdarzenie za moment da nowszą projekcję, już z tą zmianą. Dokładna naprawa
wymagałaby czasu commitu (`track_commit_timestamp` w Postgresie). Dla
narzędzia do nauki to za dużo.

### Polling tylko wtedy, gdy jest na co czekać

`apps/catalog/resources/js/components/panel/EventTrace.vue`:

```ts
const { start, stop } = usePoll(
    1000,
    { only: ['trace'] },
    { autoStart: false },
);

watch(
    () => props.trace.pending,
    (pending) => (pending ? start() : stop()),
    { immediate: true },
);
```

Serwer liczy `pending` = „ES dostępny i któreś zdarzenie ma `in_es` = false”.
Gdy wszystko dotrze, polling staje sam. Sprawdzone w przeglądarce: liczba
żądań do `/panel/products/1` przez 3 s po dotarciu zdarzenia się nie zmieniła.

Partial reload `only: ['trace']` pomija tylko propsy będące closure'ami.
Dlatego w `edit()` także `brands`, `categories` i `offers` są closure'ami.
Inaczej każde odpytanie co sekundę liczyłoby kategorie i oferty na nowo:

```php
'brands' => fn () => Brand::query()->orderBy('name')->get(['id', 'name']),
'categories' => fn () => $this->subcategoryOptions(),
'offers' => fn () => $product->offers()->with('seller:id,name')->orderBy('id')->get()->map(...),
'trace' => fn () => $eventTrace->forProduct($product),
```

### Gdy ES nie odpowiada

`EventTrace` łapie tylko błędy ES (`TransportException`,
`ServerResponseException`, `ClientResponseException`), loguje je jako
`warning` i zwraca `es_available: false`. Strona się renderuje, pokazuje
alert „ES nieosiągalny”, lampka „w ES” ma znak „?”, a `pending` jest false.
Polling nie bije więc co sekundę w martwy klaster. Brak dokumentu (404) to
co innego: znaczy „jeszcze nie w ES”, więc `in_es` = false i różnica w każdym
polu porównania.

---

## Jak sprawdzić: lista kontrolna

### Lokalnie (Compose)

Stack działa (`make up`), migracje zastosowane
(`docker compose exec catalog-app php artisan migrate`). Po zmianach w PHP
zrestartuj `catalog-app`, `outbox-publisher` i `search-consumer`
(`docker compose restart ...`).

- [ ] **Testy:**
  ```bash
  cd apps/catalog && php artisan test --compact      # część testów ES jest pomijana bez klastra
  docker compose exec -T search-consumer vendor/bin/phpunit   # z katalogu głównego repo
  ```
- [ ] **Wersje dokumentów:** `make reproject` → `błędy: 0`. Potem
  `_version` produktu, którego nie edytowałeś (np. 500), to 16 cyfr, nie 1
  (curl z punktu „Wersja dokumentu wprost” niżej, z `_doc/500`).
- [ ] **Konto:** `make admin email=ty@example.com`. Zapisz hasło od razu,
  drugi raz się nie pokaże (kolejne wywołanie wygeneruje nowe).
- [ ] **Bez uprawnień:** zaloguj się kontem z `/register` (albo bez logowania)
  i wejdź na https://catalog.localhost:8443/panel/products → 403 albo
  przekierowanie na logowanie. W sidebarze nie ma linku „Panel”.
- [ ] **Lista:** zaloguj się adminem, wpisz `1` w wyszukiwanie → dokładnie
  produkt 1. Wpisz fragment nazwy → pasujące produkty.
- [ ] **Druga oferta (regresja):** w drugim terminalu
  `docker compose logs -f search-consumer`. Na stronie produktu 1 zmień cenę
  oferty, która **nie** jest pierwsza w tabeli, na „19,99” (z przecinkiem).
  Oczekiwane:
  - flash „Zapisano, zdarzenie w drodze”;
  - w drodze zdarzenia `offer.price_changed`: zapisane → opublikowane → w ES
    w ciągu 1–2 s, potem odpytywanie staje;
  - w logu consumera „Zaindeksowano produkt.” z `doc_version` ok. 1,79·10¹⁵;
  - w porównaniu Postgres a ES ta sama najniższa cena;
  - `curl -sk "https://catalog.localhost:8443/api/search?q=Batz-Beer%20Ultra%20Boost&per_page=1"`
    pokazuje `price_min: 1999`.
- [ ] **Wersja dokumentu wprost:**
  ```bash
  set -a; source .env; set +a
  curl -s -u "elastic:${ELASTIC_PASSWORD}" \
    "localhost:${ES_PORT}/products-search/_doc/1?filter_path=_version,_source.price_min"
  ```
  `_version` to 16-cyfrowa liczba mikrosekund.
- [ ] **Bez zmian:** kliknij „Zapisz” jeszcze raz bez zmiany → flash
  „Bez zmian”, w drodze zdarzenia nie przybywa wiersza.
- [ ] **Consumer zatrzymany:**
  ```bash
  docker compose stop search-consumer
  ```
  Zmień cenę jeszcze raz. Zdarzenie stoi na „opublikowane”, strona odpytuje
  co sekundę, porównanie pokazuje „Najniższa cena (różni się)”.
  `make mq-status` → `search.product.sync` ma wiadomość w `messages_ready`.
  ```bash
  docker compose start search-consumer
  ```
  W 1–2 s lampka „w ES” się zapala, polling staje, ceny w porównaniu równe.
- [ ] **Sprzątanie:** przywróć oryginalną cenę oferty w panelu (to kolejne
  zdarzenie, też przejdzie przez kolejkę). `make mq-status` → kolejki puste.

### Na k3s

- [ ] `make k3s-deploy tag=<SHA commita>` → na końcu „OK — działa wersja …”.
  Job `migrate` stosuje migracje (`is_admin`, `occurred_at` w µs) przed
  aplikacjami.
  Krok 3/3 najpierw skaluje `search-consumer` do 0, wdraża `catalog-app`
  i `outbox-publisher`, czeka na ich rollout i dopiero wtedy wdraża nowy
  consumer. Stary consumer z nowym catalogiem dostałby 400 (`dynamic:
  strict` odrzuca `projected_at_us` w `_source`), nowy consumer ze starym
  catalogiem wyczerpałby ponowienia w ~7 s. W obu przypadkach zdarzenie
  trafiłoby do `failed`. Zdarzenia z tego okna czekają w RabbitMQ.
- [ ] Jeśli krok 3/3 padnie (tunel, timeout apply, rollout), skrypt kończy
  się „BŁĄD w kroku 3/3” i consumer może zostać na 0 replik: ES nie dostaje
  zmian, kolejka rośnie. Naprawa: `make k3s-deploy tag=<SHA>` jeszcze raz.
  `make k3s k="-n marketplace scale deploy/search-consumer --replicas=1"`
  tylko wtedy, gdy `catalog-app` i `search-consumer` mają ten sam obraz.
- [ ] **Rollback:** poniżej tej zmiany NIE wystarczy `make k3s-deploy
  tag=<stary SHA>`. Stary consumer dostanie 409 na każdym dokumencie
  (`_version` ~1,79·10¹⁵) i po cichu pominie zapis. Trzeba reindeksu do
  nowego indeksu i przełączenia aliasu (RUNBOOK #017).
- [ ] `make k3s-reproject` (jednorazowo po wdrożeniu tej zmiany) →
  `Zaindeksowano: ~1500, …, błędy: 0`.
- [ ] `make k3s-verify` → `FAIL=0`.
- [ ] `make k3s-admin email=ty@example.com`: konto zakładasz sam, żeby
  hasło nie szło przez czat ani logi.
- [ ] `make k3s-ui`, potem http://localhost:38080/panel/products. Zmień cenę
  drugiej oferty dowolnego produktu. Droga zdarzenia dochodzi do „w ES”, a
  http://localhost:38080/search pokazuje nową cenę.
- [ ] `make k3s k="-n marketplace exec deploy/search-consumer -- php bin/console messenger:failed:show"`
  (`make k3s` sam nie otwiera tunelu do API — wcześniej `make k3s-tunnel`)
  → brak wiadomości („No failed messages were found”). Coś tutaj = zdarzenie
  zgubione przy wdrożeniu; `messenger:failed:retry` po wyjaśnieniu przyczyny.
- [ ] `make k3s-mq-status` → kolejki puste (`messages` 0). `consumers` 0 to
  norma, bo Messenger pobiera wiadomości pollingiem (patrz
  [rabbitmq-podglad](rabbitmq-podglad.md)).
- [ ] Przywróć cenę. Konto testowe, jeśli było tymczasowe, usuń:
  `kubectl -n marketplace exec deploy/catalog-app -- php artisan tinker`
  i w nim `App\Models\User::where('email', 'ty@example.com')->delete();`
  (z `KUBECONFIG=~/.kube/elastic-vps.yaml` i tunelem z `make k3s-tunnel`).

---

## Znane ograniczenia

Świadomie zostawione. Każde jest dobrym tematem do samodzielnej poprawki.

- **Wieczny polling przy braku dokumentu.** Produkt, którego nie ma w ES
  (404), ma `in_es` = false, więc strona odpytuje co sekundę, dopóki jest
  otwarta. Zgodne z założeniem („jeszcze nie w ES”), ale dokument może się
  nigdy nie pojawić. Brak też automatycznego testu tego przypadku.
- **Polling trwa przy błędzie 500 samego polla** i przy odpowiedzi 5xx od
  czegoś, co nie jest ES (np. proxy przed klastrem): taki wyjątek nie jest
  łapany jako „ES nieosiągalny”, więc strona edycji daje 500. Klient ES nie
  ma też timeoutu żądania, więc przy zgubionych pakietach strona może
  wisieć.
- **Brak blokady optymistycznej.** Dwóch adminów edytujących ten sam produkt
  nadpisuje się po cichu: wygrywa ostatni zapis. `version` jest w propsach
  produktu, ale formularz go nie odsyła.
- **Cena z więcej niż 2 miejscami** („19,999”) jest cicho zaokrąglana do
  groszy, a „1e3” przechodzi jako 1000 zł. Poprawka: reguła `decimal:0,2`.
- **Nieaktywną ofertę da się edytować** (zmiana ceny daje `price_changed`
  dla oferty, której i tak nie ma w indeksie). Reaktywacji nie ma wcale.
- **Okno niedokładności kroku „w ES”** opisane wyżej (kilka ms przed
  commitem).
- **`make admin email=...` bez cudzysłowów** w Makefile: adres ze spacją czy
  znakiem powłoki rozbije argument.
- **Usuwanie sesji przy nadaniu admina** działa tylko przy
  `SESSION_DRIVER=database`; przy innym driverze komenda nic o tym nie mówi.
- **Rollback poniżej tej zmiany.** Dokumenty w ES mają teraz `_version`
  ok. 1,79·10¹⁵. Stary `search-consumer` (wersja = `sequence`, małe liczby)
  dostawałby 409 na każdym zapisie i po cichu je pomijał. Powrót do obrazu
  sprzed `Panel [2/8]` wymaga więc reindeksu do nowego indeksu i przełączenia
  aliasu, nie samego `k3s-deploy` ze starym tagiem (RUNBOOK #017). W drugą
  stronę (nowy consumer, stary catalog bez `projected_at_us`) wiadomości
  idą w ponowienia, ale po ~7 s kończą w `failed` — dlatego `k3s-deploy`
  zatrzymuje consumer na czas rolloutu catalogu.

---

## Zadania domowe

1. **Udowodnij błąd, zanim zniknął.** Na kopii repo cofnij się do commita
   sprzed `Panel [2/8]` i odtwórz go: zmień dwa razy cenę oferty 1 produktu,
   potem raz cenę innej oferty. W logu consumera (`-vv`) znajdź „Pominięto
   nieaktualne zdarzenie”, a w `/api/search` starą cenę.
2. **Usuń wieczny polling przy 404.** Zaproponuj zasadę, po której strona
   przestaje odpytywać, gdy dokumentu nie ma przez N sekund. Napisz najpierw
   test w `tests/Feature/Panel/EventTraceTest.php`.
3. **Blokada optymistyczna.** Odeślij `version` z formularza produktu
   i odrzuć zapis, gdy w bazie jest już inna. Jaki komunikat powinien
   zobaczyć drugi admin?
4. **Zamknij okno niedokładności.** Przeczytaj o `track_commit_timestamp`
   i `pg_xact_commit_timestamp()`. Co trzeba by zmienić w outboxie i w
   `EventTrace`, żeby porównywać czas commitu zamiast `occurred_at`? Ile to
   kosztuje?
5. **Dlaczego nie `microtime()` w PHP?** Uruchom dwie repliki `catalog-app`
   i przesuń zegar w jednym kontenerze o sekundę do tyłu (np. `faketime`).
   Co by się stało z kolejnością zapisów w ES, gdyby wersją był czas PHP?

---

## Mapa plików

| Plik | Rola |
|---|---|
| `apps/catalog/app/Support/DatabaseClock.php` | µs z zegara Postgresa (`clock_timestamp()`) |
| `apps/catalog/app/Http/Controllers/Internal/ProductProjectionController.php` | `projected_at_us` w 200 i 404, mierzone przed odczytem |
| `apps/search/src/Service/ProductProjection.php` | wynik projekcji: dane albo brak + `projectedAtUs` |
| `apps/search/src/Service/CatalogProjectionClient.php` | brak `projected_at_us` → ponowienie |
| `apps/search/src/Service/ElasticsearchIndexer.php` | zapis i usunięcie z `version = projected_at_us` |
| `apps/search/src/Service/ProductSynchronizer.php` | jedna ścieżka index/delete, wspólna dla handlera i komendy |
| `apps/search/src/MessageHandler/ProductSyncHandler.php` | idempotencja, ponowienie, log z `doc_version` |
| `apps/search/src/Command/ReprojectCommand.php` | `search:reproject`, `make reproject`, `make k3s-reproject` |
| `apps/search/tests/Integration/ElasticsearchIndexerVersioningTest.php` | regresja na żywym ES |
| `apps/catalog/app/Console/Commands/CreateAdminCommand.php` | `marketplace:admin`, `make admin`, `make k3s-admin` |
| `apps/catalog/app/Providers/AppServiceProvider.php` | Gate `panel` |
| `apps/catalog/routes/web.php` | grupa `/panel` z `auth` + `can:panel` |
| `apps/catalog/app/Http/Controllers/Panel/ProductController.php` | lista, edycja, propsy-closure |
| `apps/catalog/app/Http/Controllers/Panel/OfferController.php` | cena, stan, dezaktywacja |
| `apps/catalog/app/Http/Requests/Panel/Update{Product,Offer}Request.php` | walidacja, przecinek, grosze |
| `apps/catalog/app/Services/Panel/EventTrace.php` | trzy kroki, porównanie, `pending` |
| `apps/catalog/app/Services/Search/IndexedProductReader.php` | `GET _doc/{id}`: wersja + `_source` |
| `apps/catalog/database/migrations/2026_10_11_152126_change_outbox_occurred_at_to_microseconds.php` | `occurred_at` w µs |
| `apps/catalog/resources/js/pages/panel/{Products,ProductEdit}.vue` | strony panelu |
| `apps/catalog/resources/js/components/panel/{OfferRow,EventTrace}.vue` | wiersz oferty, droga zdarzenia z `usePoll` |
| `apps/catalog/tests/Feature/Panel/*.php` | testy panelu (lista, produkt, oferty, droga zdarzenia) |
