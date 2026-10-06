# Wyszukiwarka dla ludzi: Elasticsearch + Laravel + Inertia/Vue krok po kroku

*ETAP 7 projektu Marketplace Search Platform. Od pustego indeksu do klikalnej
wyszukiwarki z facetami, autocomplete, nieskończonym przewijaniem i zmierzoną
trafnością (nDCG@10 = 0.967) — razem z każdym błędem, który po drodze
wybuchł, i z tym, jak go znaleźć.*

---

## Spis treści

1. [Co budujemy i czego się nauczysz](#1-co-budujemy-i-czego-się-nauczysz)
2. [Punkt startu: kto co wie](#2-punkt-startu-kto-co-wie)
3. [Krok 1 — Dane, które da się przeszukać](#krok-1--dane-które-da-się-przeszukać)
4. [Krok 2 — Kontrakt: `SearchCriteria` i `SearchResult`](#krok-2--kontrakt-searchcriteria-i-searchresult)
5. [Krok 3 — Zapytanie pełnotekstowe](#krok-3--zapytanie-pełnotekstowe)
6. [Krok 4 — Filtry (i sztuczka z przedziałem cen)](#krok-4--filtry-i-sztuczka-z-przedziałem-cen)
7. [Krok 5 — Facety, które nie kłamią](#krok-5--facety-które-nie-kłamią)
8. [Krok 6 — Najtańsza oferta: `nested inner_hits` zamiast `collapse`](#krok-6--najtańsza-oferta-nested-inner_hits-zamiast-collapse)
9. [Krok 7 — Sortowanie i stronicowanie: PIT + `search_after`](#krok-7--sortowanie-i-stronicowanie-pit--search_after)
10. [Krok 8 — Autocomplete i histogram cen](#krok-8--autocomplete-i-histogram-cen)
11. [Krok 9 — Testy serwisu (z ES i bez)](#krok-9--testy-serwisu-z-es-i-bez)
12. [Krok 10 — Kontrolery: cienkie, leniwe, z infinite scrollem](#krok-10--kontrolery-cienkie-leniwe-z-infinite-scrollem)
13. [Krok 11 — Frontend w Vue](#krok-11--frontend-w-vue)
14. [Krok 12 — Dowód: ile zapytań kosztuje każde kliknięcie](#krok-12--dowód-ile-zapytań-kosztuje-każde-kliknięcie)
15. [Krok 13 — Zmierz trafność: `_rank_eval` i `_explain`](#krok-13--zmierz-trafność-_rank_eval-i-_explain)
16. [Pułapki infrastruktury, na które wpadliśmy](#pułapki-infrastruktury-na-które-wpadliśmy)
17. [Twoja kolej: weryfikacja w przeglądarce](#twoja-kolej-weryfikacja-w-przeglądarce)
18. [Zadania domowe](#zadania-domowe)
19. [Mapa plików](#mapa-plików)

---

## 1. Co budujemy i czego się nauczysz

Stronę `/search`, na której użytkownik:

- wpisuje frazę i dostaje podpowiedzi przy każdym znaku,
- zawęża wyniki facetami (marka, kategoria, cena, dostępność) z **poprawnymi
  licznikami**,
- widzi histogram cen, który ładuje się *po* wynikach,
- przewija listę w nieskończoność,
- może skopiować link z filtrami i wkleić go komuś.

Po drodze przerobisz w praktyce większość modułów 6–9 ze
[ścieżki nauki](../03-SCIEZKA-NAUKI.md):

| Technika ES | Gdzie w kodzie | Moduł |
|---|---|---|
| `multi_match best_fields` + boosty + `tie_breaker` | `buildTextQuery()` | 6 |
| query context vs filter context | `buildSearchQuery()` / `buildFilters()` | 6 |
| `nested` + `inner_hits` | `cheapestOfferClause()` | 6 |
| filtered aggregations w `global` | `buildFacetAggs()` | 8 |
| `range`, `terms`, `histogram` | facety + `priceHistogram()` | 8 |
| `search_after` + Point In Time + `_shard_doc` | `search()`, `searchWithPit()` | 9 |
| `track_total_hits` z limitem | `search()` | 9 |
| edge n-gram + `match_bool_prefix` | `suggest()` | 3 |
| `_rank_eval` (nDCG@10), `_explain` | `search:eval`, RUNBOOK | 7 |
| search slowlog | `make search-proof` | 10 |

I kilka rzeczy spoza ES, które w praktyce okazują się równie ważne:
leniwe propsy Inertii, obrona przed wyścigiem żądań, Point In Time, który
wygasa, gdy użytkownik idzie zrobić kawę.

> **Jak czytać ten tekst.** Każdy krok ma ten sam rytm: *co budujemy → kod →
> dlaczego tak → jak sprawdzić*. Kod w tekście to skróty — pełne wersje są
> w repo (ścieżki podaję przy każdym kroku), z komentarzami wyjaśniającymi
> decyzje. Gdy coś wybuchło po drodze, opisuję to tak, jak się stało: z błędem,
> diagnozą i poprawką. To są najcenniejsze fragmenty.

---

## 2. Punkt startu: kto co wie

Po ETAPIE 6 mamy działający pipeline zapisu:

```
Laravel: Product::createWithOutbox()  →  tabela outbox  →  outbox:publish
   →  RabbitMQ  →  search-consumer (Symfony)  →  GET /api/internal/products/{id}/projection
   →  ElasticsearchIndexer  →  indeks products-v1 (alias products-search)
```

ETAP 7 to strona **odczytu**. Zanim napiszesz linijkę kodu, ustal granice —
od nich zależy, gdzie co położysz:

| | Laravel (`catalog`) | Symfony (`search`) |
|---|---|---|
| Zna nazwę fizycznego indeksu `products-v1` | **nie** | tak |
| Zna alias `products-search` | tak | tak |
| Pisze do ES | **nie** (konto `catalog` jest tylko do odczytu) | tak |
| Czyta z ES | tak — wyszukiwarka dla użytkownika | tak |

Dwie zasady z [02-APLIKACJE](../02-APLIKACJE.md), których pilnujemy:

1. **Laravel zna tylko alias i kontrakt zapytań.** Dzięki temu reindeks
   `products-v1 → products-v2` w ETAPIE 10 nie wymaga deployu Laravela.
2. **D-09: logika wyszukiwania w klasie serwisowej**, nie w kontrolerze ani
   w komponencie Vue. Strona Inertii, JSON API i narzędzie do mierzenia
   trafności wołają te same metody.

Klient ES jest już zarejestrowany jako singleton w `AppServiceProvider`
(konto `catalog`), a `compose.yaml` wstrzykuje `ELASTICSEARCH_HOST=http://es01:9200`.
Nie trzeba niczego konfigurować — wystarczy wstrzyknąć
`Elastic\Elasticsearch\Client` do konstruktora.

---

## Krok 1 — Dane, które da się przeszukać

**Co budujemy:** komendę `php artisan marketplace:seed` (`make seed n=1500`),
która wypełnia katalog sensownymi danymi.
**Plik:** `apps/catalog/app/Console/Commands/SeedMarketplaceCommand.php`

### Dlaczego nie losowe `fake()->words()`

Wyszukiwarki nie da się sensownie testować na danych typu *"voluptas dolor
sit"*. Seeder buduje dwupoziomową taksonomię (Elektronika → Laptopy, Odzież →
Buty do biegania...) i nazwy z szablonów:

```php
private const TAXONOMY = [
    'Elektronika' => [
        'Laptopy'   => ['Laptop {model} 14"', 'Laptop {model} 15.6" gamingowy', ...],
        'Smartfony' => ['Smartfon {model} 128GB', 'Smartfon {model} Pro', ...],
    ],
    'Odzież' => [
        'Buty do biegania' => ['Buty do biegania {model}', ...],
    ],
    // ...
];
```

Dzięki temu fraza *"buty do biegania"* naprawdę coś znaczy, a synonimy
z `synonyms.txt` (`laptop, notebook` / `telefon, smartfon, komórka`) mają na
czym działać.

`fake()->seed(42)` — **stały seed Fakera**. Te same dane przy każdym
`--fresh`. Bez tego nie dałoby się w kroku 13 zapisać "dla zapytania X
oczekuję dokumentu o ID 74".

### Dlaczego przez outbox, a nie bulkiem prosto do ES

Bo chcemy, żeby dane przeszły **dokładnie tę samą drogę**, co w produkcji:
outbox → RabbitMQ → consumer → ES. Przy okazji zobaczysz lag kolejki pod
obciążeniem (`make mq-status` w trakcie seedowania).

### Pierwsza pułapka: jeden event na produkt, nie na ofertę

Naturalnie byłoby tworzyć produkt przez `Product::createWithOutbox()`, a każdą
ofertę przez `Offer::createWithOutbox()`. **To gubi oferty.** Dlaczego?

Consumer indeksuje z *external versioning* — wersja dokumentu ES = `sequence`
z eventu. Ale `sequence` to wersja **agregatu**, który wyemitował event:
`product.version` dla eventów produktu, `offer.version` dla ofert. To dwa
niezależne liczniki, oba zaczynające się od 1, piszące do **tego samego**
dokumentu ES. `offer.created` (sequence=1) przychodzi po `product.created`
(sequence=1) → ES odrzuca go jako "nie nowszy" (409). To znane, świadomie
nienaprawione ograniczenie z ETAPU 6 ([RUNBOOK #017](../RUNBOOK.md#017)).

Seeder omija je **strukturalnie**, bez łatania consumera: najpierw buduje
w Postgresie pełny stan produktu (wszystkie oferty), a dopiero potem emituje
**jeden** event:

```php
DB::transaction(function () use (...) {
    $product = Product::create([...]);
    foreach ($offerSellers as $seller) {
        Offer::create([...]);           // bez eventów
    }
    // Jedno zdarzenie, PO ofertach — consumer i tak robi pełny read-back
    // projekcji, więc zobaczy wszystkie oferty naraz.
    Outbox::create([
        'event_type' => 'product.created',
        'aggregate_type' => 'product',
        'aggregate_id' => (string) $product->id,
        'sequence' => $product->version,
        // ...
    ]);
});
```

To działa, bo consumer nie ufa treści eventu — na każdy event pobiera pełną
projekcję produktu z Laravela (read-back). Jeden event wystarcza.

### Sprawdź

```bash
make seed n=1500                 # ~1 min, potem consumer dogania kolejkę
make mq-status                   # search.product.sync -> 0 wiadomości
make es-health                   # products-v1: docs.count ~6000 (!)
```

> **Uwaga na `docs.count` w `_cat/indices`.** Pokaże ~6000, nie 1500. To nie
> błąd: każda zagnieżdżona oferta (`nested`) to osobny dokument Lucene.
> Prawdziwą liczbę produktów da `GET products-search/_count` → 1500.

---

## Krok 2 — Kontrakt: `SearchCriteria` i `SearchResult`

**Pliki:** `app/Services/Search/SearchCriteria.php`, `SearchResult.php`

Zanim powstanie choćby jedno zapytanie, ustalamy kształt wejścia i wyjścia.
To jest ten "kontrakt zapytań", o którym mówi dokumentacja.

```php
final readonly class SearchCriteria
{
    public const SORTS = ['relevance', 'price_asc', 'price_desc', 'newest'];

    public function __construct(
        public string $q,
        public array $brands,          // nazwy marek (pole `brand` to keyword z nazwą)
        public ?string $categoryPath,  // np. "13.14"
        public ?int $priceMin,         // GROSZE
        public ?int $priceMax,
        public ?bool $inStock,
        public string $sort,
        public ?string $cursor,        // kursor kolejnej strony
        public int $perPage,
    ) {}

    public static function fromRequest(Request $request): self { /* ... */ }
    public function toArray(): array { /* echo filtrów do URL/UI */ }
}
```

Trzy decyzje warte zapamiętania:

- **Ceny w groszach wszędzie.** `price_cents` w Postgresie, `price_min`
  w indeksie, `priceMin` w kryteriach. Konwersja zł↔grosze dzieje się tylko
  we froncie (`resources/js/lib/currency.ts`). Jedna jednostka w całym
  backendzie = zero błędów typu "×100 w złym miejscu".
- **`fromRequest()` broni się przed śmieciami.** Nieznany sort → `relevance`;
  `per_page` przycięte do 1–60; puste stringi z `brand[]` wyrzucone.
- **`SearchResult` to jedyne, co wychodzi z serwisu.** Surowa odpowiedź ES
  nigdy nie trafia do kontrolera — gdyby trafiła, kształt mapowania wyciekłby
  do Vue i każda zmiana indeksu łamałaby front.

---

## Krok 3 — Zapytanie pełnotekstowe

**Plik:** `app/Services/ProductSearchService.php` → `buildTextQuery()`

```php
public function buildTextQuery(string $q): array
{
    if ($q === '') {
        return ['match_all' => new \stdClass];   // przegląd bez frazy
    }

    return [
        'multi_match' => [
            'query' => $q,
            'type' => 'best_fields',
            'tie_breaker' => 0.3,
            'fields' => ['name^3', 'name.ac', 'brand^2', 'description'],
        ],
    ];
}
```

### Dlaczego `best_fields` i `tie_breaker: 0.3`

`best_fields` bierze wynik **najlepiej pasującego pola**, a nie sumę wszystkich.
Dla nazwy produktu to dobre zachowanie: dokument, w którym fraza siedzi
w `name`, nie powinien przegrać z dokumentem, w którym te same słowa są
rozsiane po `description`. `tie_breaker: 0.3` dodaje 30% wyniku pozostałych
pól — więc dokument, który pasuje *i* nazwą, *i* marką, wygra z takim, który
pasuje tylko nazwą.

Boosty (`name^3`, `brand^2`) to zdanie biznesowe: trafienie w nazwie znaczy
więcej niż trafienie w opisie. `name.ac` (pole z edge n-gramami) bez boosta —
łapie niedokończone słowa, ale nie powinno dominować.

### `new \stdClass`, nie `[]`

`['match_all' => []]` zserializuje się do `{"match_all":[]}` — tablica, nie
obiekt, i ES odrzuci zapytanie. PHP nie odróżnia pustej tablicy od pustego
obiektu; `new \stdClass` wymusza `{}`.

### Query context vs filter context

```php
public function buildSearchQuery(SearchCriteria $criteria): array
{
    return [
        'bool' => [
            'must' => [$this->buildTextQuery($criteria->q)],  // liczy _score
            'filter' => $this->buildFilters($criteria),       // nie liczy, cache'uje
        ],
    ];
}
```

To najważniejsza zasada modułu 6: **tekst w `must`, filtry w `filter`**.
Filtr (marka = "Nike") to pytanie tak/nie — nie ma sensu, żeby wpływał na
trafność. W `filter` ES go nie punktuje i może cache'ować wynik (bitset
dokumentów spełniających warunek), więc kolejne zapytania z tym samym filtrem
są tańsze.

---

## Krok 4 — Filtry (i sztuczka z przedziałem cen)

**Plik:** `ProductSearchService::buildFilters()`

```php
public function buildFilters(SearchCriteria $criteria, array $exclude = []): array
{
    $filters = [];

    if (! in_array('brand', $exclude, true) && $criteria->brands !== []) {
        $filters[] = ['terms' => ['brand' => $criteria->brands]];
    }
    if (! in_array('category', $exclude, true) && $criteria->categoryPath !== null) {
        $filters[] = ['term' => ['category.path' => $criteria->categoryPath]];
    }
    if (! in_array('price', $exclude, true)) {
        if ($criteria->priceMin !== null) {
            $filters[] = ['range' => ['price_max' => ['gte' => $criteria->priceMin]]];
        }
        if ($criteria->priceMax !== null) {
            $filters[] = ['range' => ['price_min' => ['lte' => $criteria->priceMax]]];
        }
    }
    if (! in_array('in_stock', $exclude, true) && $criteria->inStock !== null) {
        $filters[] = ['term' => ['in_stock' => $criteria->inStock]];
    }

    return $filters;
}
```

### Sztuczka z ceną: nakładanie przedziałów zamiast `nested`

Produkt ma wiele ofert w różnych cenach. Użytkownik pyta: *"pokaż produkty
w przedziale 100–500 zł"*. Co to znaczy? **"Ma choć jedną ofertę w tym
przedziale."**

Naiwnie: zapytanie `nested` po `offers.price`. Działa, ale jest droższe.
Mapowanie ma jednak pola `price_min` i `price_max` na poziomie produktu —
właśnie po to. "Produkt ma ofertę w [A, B]" ≈ "przedział produktu
[price_min, price_max] nachodzi na [A, B]":

```
         A ━━━━━━━━━━━━ B          ← przedział z suwaka
   price_min ━━━━━━━ price_max     ← nachodzą, jeśli:
                                     price_max >= A  ORAZ  price_min <= B
```

Dwa tanie `range` na polach top-level. (To przybliżenie — produkt z ofertami
po 50 zł i 900 zł "nachodzi" na 100–500 zł, choć nie ma oferty w środku.
Dla marketplace'u to akceptowalne; jeśli nie — zadanie domowe nr 2.)

### Parametr `$exclude`

Na razie wygląda na zbędny. Za chwilę będzie kluczowy.

---

## Krok 5 — Facety, które nie kłamią

**Plik:** `ProductSearchService::buildFacetAggs()`

To jest moment, który dokumentacja nazywa *"prawdziwym problemem, który mało
kto rozwiązuje poprawnie"*. I rzeczywiście — pierwsza wersja była błędna.

### Problem

Użytkownik zaznacza markę **Nike**. Lista marek w panelu powinna nadal
pokazywać **Adidas (12)**, **Puma (8)** — żeby mógł zaznaczyć drugą markę.
Jeśli po kliknięciu Nike wszystkie inne marki pokażą 0 albo znikną, facet jest
bezużyteczny (to tzw. *multi-select facet*).

Czyli: **facet marki ma się liczyć z filtrami wszystkich wymiarów OPRÓCZ
marki.** Facet kategorii — ze wszystkimi oprócz kategorii. I tak dalej.

### Pierwsze podejście (błędne)

Dla każdego wymiaru agregacja `filter` z filtrami pozostałych wymiarów:

```json
"aggs": {
  "brand": {
    "filter": { "bool": { "filter": [ /* wszystkie filtry OPRÓCZ marki */ ] } },
    "aggs": { "brand": { "terms": { "field": "brand" } } }
  }
}
```

Test na żywym klastrze:

```
unfiltered brand facet count: 20
filtered by brand=Orn PLC
brand facet buckets after filtering: 1     ← powinno być 20!
```

**Dlaczego?** Agregacje liczą się na wyniku **głównego zapytania** — a główne
zapytanie ma w `bool.filter` markę = Orn PLC (bo wyniki muszą być
przefiltrowane). Mój `filter` w agregacji tylko **dalej zawężał** już
zawężony zbiór. Wykluczenie marki w środku nie mogło niczego "odzyskać".

### Poprawka: `global`

Agregacja `global` resetuje kontekst do **całego indeksu**, ignorując główne
zapytanie. Dopiero wewnątrz niej nakładamy dokładnie te warunki, które mają
obowiązywać:

```php
private function buildFacetAggs(SearchCriteria $criteria): array
{
    $dimensionAggs = [
        'brand'    => ['terms' => ['field' => 'brand', 'size' => 20]],
        'category' => ['terms' => ['field' => 'category.path', 'size' => 30]],
        'price'    => ['range' => ['field' => 'price_min', 'ranges' => self::PRICE_RANGES]],
        'in_stock' => ['terms' => ['field' => 'in_stock', 'size' => 2]],
    ];

    foreach ($dimensionAggs as $dimension => $agg) {
        $filterClauses = $this->buildFilters($criteria, exclude: [$dimension]);

        if ($criteria->q !== '') {
            $filterClauses[] = $this->buildTextQuery($criteria->q);  // ← patrz niżej
        }

        $perDimension[$dimension] = [
            'filter' => $filterClauses === []
                ? ['match_all' => new \stdClass]
                : ['bool' => ['filter' => $filterClauses]],
            'aggs' => [$dimension => $agg],
        ];
    }

    return ['facets' => ['global' => new \stdClass, 'aggs' => $perDimension]];
}
```

Po poprawce: `brand facet buckets after filtering: 20`. ✔

### Druga połowa pułapki: `global` gubi też frazę

`global` ignoruje **całe** główne zapytanie — także tekst. A facety mają
odzwierciedlać wpisaną frazę: dla *"buty do biegania"* lista marek powinna
zawierać tylko te, które sprzedają buty. Dlatego `multi_match` jest
**ręcznie doklejany do filtra każdego wymiaru**. W kontekście `filter` nie
liczy się do `_score`, więc jest tu bezpieczny.

### Dlaczego nie `post_filter`

Klasyczna rada z blogów: *"daj filtry w `post_filter`, wtedy agregacje ich
nie widzą"*. To działa dla **jednego** wymiaru facetów. Przy kilku wymiarach
`post_filter` wyłącza z agregacji **wszystkie** filtry naraz — facet kategorii
przestaje uwzględniać zaznaczoną markę, co też jest błędne.

### Dlaczego w tym samym zapytaniu co wyniki

Facety i wyniki to jedno żądanie do ES, nie dwa. Agregacja w tym samym
requeście korzysta z tego samego parsowania zapytania i jednego przejścia
po shardach.

> **Sprawdź sam:** `tests/Feature/SearchIntegrationTest.php` → test *"filtr
> brand zawęża wyniki, ale facet brand nadal pokazuje wszystkie marki"*.

---

## Krok 6 — Najtańsza oferta: `nested inner_hits` zamiast `collapse`

**Plik:** `ProductSearchService::cheapestOfferClause()`

Moduł 6 ma ćwiczenie: *"`collapse` po `product_id` z `inner_hits` =
najtańsza oferta"*. To ćwiczenie zakłada, że **każda oferta jest osobnym
dokumentem**. Nasze mapowanie jest inne: **jeden dokument = jeden produkt**,
oferty jako `nested`. Tu `collapse` nie ma czego zwijać.

Ten sam efekt biznesowy daje `nested` + `inner_hits`:

```php
private function cheapestOfferClause(): array
{
    return [
        'nested' => [
            'path' => 'offers',
            'query' => ['match_all' => new \stdClass],
            'inner_hits' => [
                'name' => 'cheapest_offer',
                'size' => 1,
                'sort' => [['offers.price' => 'asc']],
            ],
        ],
    ];
}
```

Doklejamy to **zawsze** do `bool.filter`. `match_all` w środku zawsze
przechodzi (każdy produkt ma ofertę), w kontekście filtra nie wpływa na
`_score` — jedyny cel tej klauzuli to "wyciągnij mi przy okazji najtańszą
ofertę". Wynik siedzi w `hit.inner_hits.cheapest_offer.hits.hits[0]._source`.

> Porównanie obu modeli danych (osobny dokument na ofertę + `collapse` vs
> `nested`) — z rozmiarem indeksu i kosztem zmiany ceny — to sekcja 5
> w [POMIARY](../POMIARY.md), do zrobienia w ETAPIE 8.

---

## Krok 7 — Sortowanie i stronicowanie: PIT + `search_after`

To najbardziej "produkcyjny" krok. Trzy próby, dwie porażki.

### Dlaczego nie `from`/`size`

`from: 9990, size: 10` wygląda niewinnie, ale każdy shard musi zwrócić
koordynatorowi **10 000** dokumentów, żeby ten wybrał 10. Koszt rośnie
liniowo z numerem strony, a powyżej `index.max_result_window` (10 000) ES
po prostu odmawia. (Podnoszenie tego limitu to *zła* naprawa — moduł 9.)

`search_after` mówi: *"daj mi 24 dokumenty, które w sortowaniu są po tym
konkretnym"*. Koszt stały niezależnie od głębokości.

### Próba 1: sortuj po `_id` jako tie-breaker

`search_after` potrzebuje **unikalnego** ostatniego pola sortowania — inaczej
dwa produkty z tym samym `_score` mogą zostać pominięte albo zdublowane na
granicy strony. Naturalny kandydat: `_id`.

```
illegal_argument_exception: Fielddata access on the _id field is disallowed,
you can re-enable it by updating the dynamic cluster setting:
indices.id_field_data.enabled
```

`_id` to pole metadanych; sortowanie po nim wymaga *fielddata* (odwróconego
indeksu w pamięci dla pola z tyloma unikalnymi wartościami, co dokumentów).
ES blokuje to celowo. Komunikat podpowiada "włącz" — **nie włączaj**.
([RUNBOOK #019](../RUNBOOK.md#019))

### Próba 2: Point In Time + `_shard_doc`

Poprawny sposób: **Point In Time** (PIT) zamraża widok shardów na czas sesji
przeglądania, a `_shard_doc` to wewnętrzny, zawsze unikalny numer dokumentu
w shardzie — działa tylko z PIT.

```php
public function buildSort(SearchCriteria $criteria): array
{
    return match ($criteria->sort) {
        'price_asc'  => [['price_min' => 'asc'],   ['_shard_doc' => 'asc']],
        'price_desc' => [['price_min' => 'desc'],  ['_shard_doc' => 'asc']],
        'newest'     => [['created_at' => 'desc'], ['_shard_doc' => 'asc']],
        default      => [['_score' => 'desc'],     ['_shard_doc' => 'asc']],
    };
}
```

Pierwsza strona otwiera PIT; zapytanie idzie z `pit` w body i **bez**
parametru `index` (PIT już wie, gdzie szukać):

```php
$body = [
    'query' => [...],
    'sort' => $this->buildSort($criteria),
    'size' => $criteria->perPage,
    'pit' => ['id' => $pitId, 'keep_alive' => '1m'],
    'track_total_hits' => 10_000,
];
$response = $this->client->search(['body' => $body]);   // bez 'index'!
```

Kursor kolejnej strony to `base64(json({pit, sort}))` — PIT **razem**
z wartościami sortowania ostatniego trafienia, bo kolejna strona musi użyć
tego samego zamrożonego widoku.

### `track_total_hits: 10_000`, nie `true`

Dokładne liczenie wszystkich trafień kosztuje. Z limitem ES liczy do 10 000,
a powyżej zwraca `relation: "gte"` — front pokazuje wtedy *"10 000+ wyników"*
(`isLowerBound` w `SearchResult`).

### Próba 3: co, gdy PIT wygaśnie?

Znalezione przy przeglądzie, potwierdzone eksperymentem: PIT żyje
`keep_alive` (1 minuta) od **ostatniego** zapytania. Użytkownik czyta wyniki
przez dwie minuty, przewija — i dostaje **500**:

```
404 search_context_missing_exception: No search context found for id [...]
```

Poprawka (`searchWithPit()`): na stronie kolejnej, przy tym konkretnym 404,
otwórz nowy PIT i kontynuuj z tymi samymi wartościami `search_after`:

```php
try {
    return $this->client->search(['body' => $body])->asArray();
} catch (ClientResponseException $e) {
    if (! $isContinuation || ! $this->isMissingSearchContext($e)) {
        throw $e;
    }
    $body['pit']['id'] = $this->openPit();
    return $this->client->search(['body' => $body])->asArray();
}
```

**Świadomy kompromis:** `_shard_doc` z nowego PIT-a odpowiada staremu tylko,
jeśli w międzyczasie na shardzie nie było zapisów/merge'ów. Najgorszy
przypadek: jeden duplikat (zdejmie go deduplikacja po `id` po stronie
Inertii) albo jedna pominięta pozycja przy wznowieniu po minucie. Dlaczego
nie po prostu `keep_alive: 30m`? Bo otwarty PIT **trzyma segmenty**, które
merge chciałby już usunąć — tysiąc porzuconych kart przeglądarki to tysiąc
trzymanych zestawów segmentów. ([RUNBOOK #023](../RUNBOOK.md#023))

### Facety tylko dla pierwszej strony

Strona 2, 3, 4... tego samego zapytania ma **identyczne** facety. Liczenie
ich przy każdym przewinięciu to czysty koszt:

```php
if ($searchAfter === null) {
    $body['aggs'] = $this->buildFacetAggs($criteria);   // tylko strona 1
} else {
    $body['search_after'] = $searchAfter;
}
```

### Śmieciowy kursor = 400, nie 500

Kursor przychodzi z query stringa, czyli od użytkownika. Ucięty przy
kopiowaniu linku nie może wywalić serwera:

```php
$decoded = json_decode((string) base64_decode($cursor, strict: true), associative: true);
if (! is_array($decoded) || ! is_string($decoded['pit'] ?? null) || ! is_array($decoded['sort'] ?? null)) {
    throw new InvalidSearchCursorException;   // extends BadRequestHttpException -> 400
}
```

---

## Krok 8 — Autocomplete i histogram cen

### Autocomplete

```php
public function suggest(string $q, int $limit = 8): array
{
    if (trim($q) === '') {
        return [];                          // nie pytaj ES o pustą frazę
    }

    $response = $this->client->search([
        'index' => self::ALIAS,
        'body' => [
            'size' => $limit,
            '_source' => ['name', 'brand'],      // tylko to, co pokażesz
            'query' => ['match_bool_prefix' => ['name.ac' => $q]],
        ],
    ])->asArray();
    // ...
}
```

- `name.ac` to pole z analizatorem `pl_autocomplete` (edge n-gramy 2–20) —
  "lap" trafia w "laptop", bo przy indeksowaniu powstały tokeny `la`, `lap`,
  `lapt`...
- `match_bool_prefix` traktuje **ostatnie** słowo jako prefiks — "buty do
  bieg" działa.
- Bez agregacji, z minimalnym `_source`. To zapytanie leci **przy każdym
  znaku** — ma być najtańsze w całej aplikacji.

### Histogram cen

```php
'query' => ['bool' => [
    'must' => [$this->buildTextQuery($criteria->q)],
    'filter' => $this->buildFilters($criteria, exclude: ['price']),  // ← bez własnego filtra
]],
'aggs' => ['price_histogram' => ['histogram' => [
    'field' => 'price_min', 'interval' => 5_000, 'min_doc_count' => 0,
]]],
'size' => 0,
```

Ta sama logika co facety: histogram ignoruje **własny** filtr ceny — inaczej
przesunięcie suwaka obcinałoby histogram do samego siebie. Tu wystarczy
zwykły `bool.filter` (bez `global`), bo całe zapytanie ma tylko ten jeden cel.

Histogram jest **osobną metodą**, nie częścią `search()` — w kroku 10 zrobimy
z niego *deferred prop*, który nie blokuje pierwszego renderu.

---

## Krok 9 — Testy serwisu (z ES i bez)

Dwa poziomy, bo łapią różne klasy błędów.

### Testy jednostkowe DSL — bez ES

**Plik:** `tests/Unit/Services/ProductSearchServiceQueryTest.php`

Metody `buildX()` nie dotykają klienta, więc testujemy kształt zapytania
w milisekundach:

```php
test('filtry lądują WYŁĄCZNIE w bool.filter zapytania, nigdy w must', function () {
    $criteria = criteriaFromQuery(['q' => 'laptop', 'brand' => ['Acme'], 'in_stock' => '1']);

    $query = searchService()->buildSearchQuery($criteria);

    expect($query['bool']['must'])->toHaveCount(1)
        ->and($query['bool']['filter'])->toContain(['terms' => ['brand' => ['Acme']]]);
});
```

Ciekawostka: `Elastic\Elasticsearch\Client` jest klasą **`final`** — Mockery
nie zrobi z niej mocka. Ale `ClientBuilder::create()->build()` tylko buduje
obiekt; połączenie nawiązuje się przy pierwszym wywołaniu API. Wystarczy
prawdziwy, nigdy nieużyty klient.

### Testy integracyjne — na żywym ES

**Plik:** `tests/Feature/SearchIntegrationTest.php`

Dokładnie te błędy z kroków 5 i 7 (`global`, `_id`, PIT) **nie miały żadnego
sygnału** na poziomie budowania DSL. Wyszły dopiero na prawdziwym klastrze.
Stąd testy, które pytają prawdziwy ES:

```php
test('przewinięcie po wygaśnięciu PIT nie kończy się błędem, ...', function () {
    $page1 = service()->search(criteria(['q' => '', 'per_page' => '10']));
    $pitId = json_decode(base64_decode($page1->nextCursor), true)['pit'];
    app(Client::class)->closePointInTime(['body' => ['id' => $pitId]]);   // symulacja wygaśnięcia

    $page2 = service()->search(criteria([..., 'cursor' => $page1->nextCursor]));

    expect($page2->items)->toHaveCount(10)
        ->and(array_intersect($ids1, $ids2))->toBe([]);
});
```

Zamknięcie PIT-a ręcznie daje ten sam efekt co wygaśnięcie, bez czekania
minuty — tak się testuje rzeczy zależne od czasu.

`beforeEach` z **guardem**: jeśli ES nie odpowiada albo indeks ma <50
dokumentów, testy się *pomijają* z czytelnym komunikatem ("uruchom `make
seed`"), zamiast failować w niejasny sposób.

---

## Krok 10 — Kontrolery: cienkie, leniwe, z infinite scrollem

**Plik:** `app/Http/Controllers/SearchController.php`

Trzy adaptery nad tym samym serwisem (D-09):

| Trasa | Kontroler | Dla kogo |
|---|---|---|
| `GET /search` (nazwana `search`) | `SearchController` | przeglądarka, Inertia |
| `GET /api/search` | `Api\SearchController` | klienci zewnętrzni, JSON |
| `GET /api/suggest` | `Api\SuggestController` | autocomplete, zwykły `fetch` |

Kontrolery JSON-owe to po kilka linijek. Ciekawie robi się w kontrolerze
Inertii.

### Leniwe propsy — i błąd, którego nie widać

Pierwsza wersja wyglądała tak:

```php
$result = $service->search($criteria);          // ← zawsze, na starcie

return Inertia::render('Search', [
    'results' => [...$result->items...],
    'facets' => $result->facets,
    'priceHistogram' => Inertia::defer(fn () => $service->priceHistogram($criteria)),
]);
```

Działało, testy przechodziły. Problem: `Inertia::defer()` sprawia, że po
pierwszym renderze przeglądarka wysyła **drugi** request z
`X-Inertia-Partial-Data: priceHistogram`. Ten request przechodzi przez **ten
sam kontroler** — więc wykonuje `$service->search()` od nowa, wylicza wyniki
i facety... i wyrzuca je, bo klient prosił tylko o histogram. Dwa zapytania
do ES tam, gdzie wystarczy jedno.

Kluczowa obserwacja z kodu Inertii (`PropsResolver`): **closure jako wartość
propa wykonuje się tylko wtedy, gdy klient o ten prop prosi.** Więc:

```php
$result = null;
$search = function () use (&$result, $service, $criteria): SearchResult {
    return $result ??= $service->search($criteria);     // memoizacja per request
};

return Inertia::render('Search', [
    'results' => Inertia::scroll(fn () => [...$search()...], ...),
    'facets' => fn () => $search()->facets,
    'filters' => $criteria->toArray(),
    'priceHistogram' => Inertia::defer(fn () => $service->priceHistogram($criteria)),
]);
```

- `results` i `facets` dzielą **jedno** zapytanie (`??=`).
- Request po histogram nie dotyka `$search` w ogóle.

> **Dlaczego zmienna lokalna, a nie pole klasy albo `once()`?** Aplikacja
> działa pod Octane (worker mode). Kontroler może przeżyć jedno żądanie;
> stan per-request musi umrzeć razem z requestem, inaczej użytkownik B
> zobaczy wyniki użytkownika A (decyzja D-07b, "wyciek stanu").

### Infinite scroll: `Inertia::scroll()` z kursorem

Inertia v3 ma wbudowany komponent `<InfiniteScroll>` i jego serwerową
połówkę `Inertia::scroll()`. Domyślnie zakłada paginator Laravela (numery
stron). My mamy kursor. Na szczęście `Inertia::scroll()` przyjmuje własne
metadane przez interfejs `ProvidesScrollMetadata`:

```php
'results' => Inertia::scroll(
    fn () => [
        'data' => $search()->items,         // ← to będzie doklejane
        'total' => $search()->total,
        'took_ms' => $search()->tookMs,
    ],
    wrapper: 'data',
    metadata: fn () => self::cursorMetadata($criteria->cursor, $search()->nextCursor),
)->matchOn('data.id'),                       // ← deduplikacja przy doklejaniu
```

```php
private static function cursorMetadata(?string $current, ?string $next): ProvidesScrollMetadata
{
    return new readonly class($current, $next) implements ProvidesScrollMetadata {
        // ...
        public function getPageName(): string { return 'cursor'; }       // nazwa parametru w URL
        public function getPreviousPage(): ?string { return null; }      // search_after: tylko do przodu
        public function getNextPage(): ?string { return $this->nextCursor; }
        public function getCurrentPage(): ?string { return $this->currentCursor; }
    };
}
```

Odpowiedź Inertii zawiera teraz metadane, które czyta komponent:

```json
"scrollProps":  { "results": { "pageName": "cursor", "nextPage": "eyJwaXQi...", "previousPage": null } },
"mergeProps":   ["results.data"],
"matchPropsOn": ["results.data.id"]
```

Jak to działa po stronie klienta, patrz krok 11.

---

## Krok 11 — Frontend w Vue

**Pliki:** `resources/js/pages/Search.vue`, `resources/js/components/search/*`

```
Search.vue
├── SearchBar        fraza + autocomplete (fetch, nie Inertia)
├── SortSelector     trafność / cena / najnowsze
├── FacetPanel       marka, kategoria, cena (suwak), dostępność
├── <Deferred> PriceHistogram    ładuje się PO wynikach
└── SearchResults    <InfiniteScroll> z kartami produktów
```

### Orkiestracja: `applyFilters()` z `only` i `reset`

Każda zmiana filtra to jedna funkcja:

```ts
function applyFilters(
    partial: Partial<SearchFilters>,
    options: { refreshHistogram?: boolean; replaceHistory?: boolean } = {},
) {
    const next = { ...props.filters, ...partial };
    const only = options.refreshHistogram
        ? ['results', 'facets', 'priceHistogram', 'filters']
        : ['results', 'facets', 'filters'];

    router.get(search.url(), toQuery(next), {
        preserveState: true,
        preserveScroll: true,
        replace: options.replaceHistory ?? false,
        only,
        reset: ['results'],
    });
}

function onSearchText(q: string) {
    applyFilters({ q }, { refreshHistogram: true, replaceHistory: true });
}
```

- **`only`** — partial reload. Klik w facet prosi tylko o wyniki, facety
  i echo filtrów. Histogram **nie** jest liczony (to jest DoD, patrz krok 12).
  Jedyny wyjątek: zmiana frazy — wtedy jawnie prosimy o świeży histogram, bo
  nowy tekst realnie zmienia rozkład cen.
- **`reset: ['results']`** — **obowiązkowe**. `results.data` jest propem
  doklejanym (merge). Bez resetu wyniki nowego filtra zostałyby **dopisane na
  koniec** wyników starego, a `<InfiniteScroll>` trzymałby kursor starego
  zapytania. Z resetem serwer pomija metadane merge i lista jest zastępowana.
- **`search.url()`** — typowana funkcja z Wayfinder, wygenerowana z nazwanej
  trasy `search`. Żadnego `'/search'` wpisanego ręcznie.
- **Stan w URL za darmo.** `router.get` z parametrami zapisuje filtry
  w adresie, a kontroler zwraca je w `filters`. Wklejony link odtwarza
  dokładnie ten widok.
- **`replace` tylko dla frazy.** Pierwsza wersja miała `replace: true` dla
  wszystkiego — i "wstecz" zamiast cofać ostatni filtr, wyrzucało z
  wyszukiwarki. Odwrotna skrajność (nowy wpis zawsze) też jest zła: fraza
  leci z debounce przy pisaniu, więc "wstecz" cofałoby po literce. Kompromis:
  pisanie *podmienia* wpis w historii, a kliknięcia w facety i sort
  *dodają* nowy. Inertia trzyma w historii pełny stan strony, więc "wstecz"
  przywraca poprzednie filtry bez żadnego requestu.

### Autocomplete: obrona przed wyścigiem żądań

**Plik:** `components/search/SearchBar.vue`

Autocomplete idzie przez **zwykły `fetch`**, nie Inertię. Inertia przy każdej
wizycie przeładowuje propsy strony i dopisuje wpis do historii — podpowiedzi
przy każdym znaku nie mogą tego robić.

Ale `fetch` nie anuluje się sam. Użytkownik pisze "lap", "lapt", "lapto" —
trzy żądania w locie, odpowiedzi wracają w losowej kolejności, UI pokazuje
podpowiedzi dla "lapt". Dwie niezależne linie obrony:

```ts
let abortController: AbortController | null = null;
let requestSequence = 0;

async function fetchSuggestions(query: string) {
    abortController?.abort();                       // 1. anuluj poprzednie żądanie

    const sequence = ++requestSequence;
    abortController = new AbortController();

    try {
        const response = await fetch(`/api/suggest?q=${encodeURIComponent(query)}`, {
            signal: abortController.signal,
        });
        const data = await response.json();

        if (sequence !== requestSequence) {          // 2. odrzuć przestarzałą odpowiedź
            return;
        }

        suggestions.value = data.suggestions;
    } catch (error) {
        if (error instanceof DOMException && error.name === 'AbortError') {
            return;
        }
        suggestions.value = [];
    }
}

const debouncedFetchSuggestions = useDebounceFn(fetchSuggestions, 300);
```

Po co dwie linie? `abort()` może się spóźnić — odpowiedź zdążyła dotrzeć
tuż przed. Numer sekwencyjny odrzuca ją **po treści**, nie po kolejności
przyjścia.

> **Zauważ analogię.** To jest *dokładnie* ten sam problem co kolejność
> zdarzeń w RabbitMQ z [05-SPOJNOSC-DANYCH](../05-SPOJNOSC-DANYCH.md):
> wiadomości wracają nie w tej kolejności, w której wyszły, a obroną jest
> numer sekwencyjny i odrzucanie przestarzałych. Tam `sequence` +
> `version_type: external`, tu `requestSequence`. Ta sama idea na dwóch
> zupełnie różnych warstwach systemu.

### Suwak ceny: jeden request na puszczenie kciuka

**Plik:** `components/search/FacetPanel.vue`

```vue
<Slider
    :model-value="priceRange"
    :min="0" :max="SLIDER_MAX_ZLOTY" :step="10"
    @update:model-value="(v) => (priceRange = v as [number, number])"
    @value-commit="commitPriceRange"
/>
```

`update:model-value` aktualizuje **lokalny** stan przy każdej klatce
przeciągania (żeby etykieta "100 zł – 500 zł" żyła). `value-commit` (reka-ui)
strzela dopiero po puszczeniu kciuka — i dopiero to wysyła request. Bez tego
każde 10 zł ruchu suwaka to osobne zapytanie do ES.

### Histogram jako `<Deferred>`

```vue
<Deferred data="priceHistogram">
    <template #fallback>
        <Skeleton class="h-24 w-full" />
    </template>
    <PriceHistogram :buckets="priceHistogram ?? []" />
</Deferred>
```

Użytkownik widzi wyniki natychmiast; histogram dojeżdża osobnym requestem,
a do tego czasu pulsuje szkielet. `PriceHistogram.vue` to kilka `<div>`-ów
ze skalowaną wysokością — bez biblioteki wykresów, bo to jedyne miejsce
w projekcie, gdzie by się przydała.

### Nieskończone przewijanie

**Plik:** `components/search/SearchResults.vue`

```vue
<InfiniteScroll
    data="results"
    only-next
    preserve-url
    :buffer="600"
    class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
>
    <ProductCard v-for="product in results.data" :key="product.id" :product="product" />

    <template #next="{ loading, hasMore }">
        <Spinner v-if="loading" />
        <span v-else-if="!hasMore">To już wszystkie wyniki.</span>
    </template>
</InfiniteScroll>
```

Komponent obserwuje koniec listy i, gdy jest blisko, wysyła:

```
router.reload({ only: ['results'], data: { cursor: <scrollProps.results.nextPage> } })
  + nagłówek X-Inertia-Infinite-Scroll-Merge-Intent: append
```

Serwer odsyła kolejną porcję, Inertia dokleja ją do `results.data`
(`mergeProps`) i zdejmuje duplikaty po `id` (`matchPropsOn`). Parametry:

- **`only-next`** — `search_after` działa tylko do przodu,
- **`preserve-url`** — kursor to ID PIT-a żyjącego minutę. Wkładanie go do
  URL-a dałoby link, który po chwili przestaje działać. URL niesie tylko
  filtry,
- **`:buffer="600"`** — zaczynamy ładować ~2 rzędy kart przed końcem, żeby
  nie było widać "dziury".

---

## Krok 12 — Dowód: ile zapytań kosztuje każde kliknięcie

DoD z [planu](../06-PLAN-WDROZENIA.md) brzmi: *"kliknięcie facetu **nie**
wywołuje niepotrzebnych agregacji w ES (**dowód w slowlogu**)"*. Nie "wydaje
mi się", nie "tak powinno być" — dowód.

### Automatycznie: `make search-proof`

`tools/search-slowlog-proof.sh` włącza slowlog z progiem **0 ms** (czyli
loguje *każde* zapytanie), a potem curlem odtwarza **dokładnie te żądania,
które wysyła przeglądarka** — z tymi samymi nagłówkami Inertii. Po każdym
kroku pokazuje, co trafiło do ES:

```
━━ 1. Pierwsze wejście: GET /search?q=laptop
16:37:32.197  catalog  54 ms  86 hits  wyniki + FACETY
Razem zapytań do ES: 1

━━ 2. Inertia dociąga deferred prop: only=priceHistogram
16:37:35.872  catalog  26 ms  86 hits  HISTOGRAM cen
Razem zapytań do ES: 1

━━ 3. Klik w facet marki "Franecki-Carroll": only=results,facets,filters + reset
16:37:39.759  catalog  14 ms  6 hits   wyniki + FACETY
Razem zapytań do ES: 1

━━ 4. Przewinięcie: <InfiniteScroll> prosi o kolejną stronę (cursor)
16:37:42.623  catalog  16 ms  86 hits  kolejna strona (bez agregacji)
Razem zapytań do ES: 1
```

Każda akcja = **jedno** zapytanie, i dokładnie to, które powinno być. Przed
poprawką z kroku 10 krok 2 pokazywałby **dwa** zapytania (wyniki+facety
i histogram), a krok 4 liczyłby facety na zapas. Liczby w
[POMIARY, sekcja 5b](../POMIARY.md).

Ręcznie, w dowolnym momencie:

```bash
make es-slowlog-on          # próg 0ms na products-search
# ...klikasz w przeglądarce...
make es-slowlog since=60s   # tabela: co, kiedy, ile trwało
make es-slowlog-off
```

### Automatycznie bez ES: test z mockiem

**Plik:** `tests/Feature/SearchPropsLazinessTest.php`

Slowlog to dowód na żywym systemie. Test to dowód, który pilnuje regresji
przy każdym `php artisan test`. Każde wywołanie metody serwisu = jedno
zapytanie do ES, więc licznik wywołań w Mockery jest licznikiem zapytań:

```php
test('automatyczny request po histogram (deferred) NIE przelicza wyszukiwania', function () {
    $this->mock(ProductSearchService::class, function (MockInterface $mock) {
        $mock->shouldReceive('search')->once()->andReturn(fakeSearchResult());   // tylko z 1. żądania
        $mock->shouldReceive('priceHistogram')->once()->andReturn([...]);
    });

    $this->get('/search?q=laptop')
        ->assertInertia(fn (Assert $page) => $page
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->has('priceHistogram', 1)
                ->missing('results')
            )
        );
});
```

Gdyby ktoś kiedyś "uprościł" kontroler z powrotem do `$result =
$service->search(...)` na starcie, `once()` wybuchnie.

---

## Krok 13 — Zmierz trafność: `_rank_eval` i `_explain`

"Wyniki wyglądają dobrze" to opinia. nDCG@10 to liczba.

### Zestaw zapytań kontrolnych

**Plik:** `tests/relevance/queries.yaml` (w roocie repo, nie w aplikacji)

```yaml
queries:
    - query: 'Wyman-Howell Laptop Ultra 14"'
      relevant:
          - { id: '74', grade: 3 }          # dokładnie ten produkt

    - query: 'notebook'                     # SYNONIM laptopa
      relevant:
          - { id: '74', grade: 1 }
          - { id: '80', grade: 1 }
          # ...
```

13 zapytań: dokładne nazwy (ocena 3), kategorie, marka, i trzy testujące
**synonimy** (`notebook`, `komórka`, `telefon`). Zanim je zapisałem,
sprawdziłem ręcznie, że `notebook` zwraca **dokładnie** ten sam zestaw co
`Laptop` — inaczej mierzyłbym coś innego, niż myślę.

### Komenda `search:eval` i najważniejsza zasada

```php
'request' => [
    'query' => $this->searchService->buildSearchQuery($criteria),   // ← TEN SAM template
    'size' => $k,
],
```

Query do oceny pochodzi z **tej samej metody**, z której korzysta `search()`.
Osobny, "podobny" template do ewaluacji byłby bezużyteczny — mierzyłby
trafność zapytania, którego użytkownik nigdy nie zobaczy.

### Pułapka: wszystko 0.000

Pierwsze uruchomienie: nDCG@10 = **0.000** dla wszystkich 13 zapytań, choć
`search()` zwracał oczekiwane produkty na czele. `unrated_docs` pokazywał
**dokładnie** te ID, które oceniłem. Dlaczego się nie dopasowały?

`_rank_eval` paruje oceny z trafieniami po `_index` **i** `_id`, dosłownie.
Oceny miały `"_index": "products-search"` (alias). Ale trafienia **zawsze**
raportują **fizyczny** indeks: `products-v1`. Alias jest przezroczysty dla
*zapytania*, nie dla *odpowiedzi*.

Poprawka: komenda rozwiązuje alias w locie (`GET _alias/products-search`)
i podstawia fizyczną nazwę do ocen. **Tylko w komendzie diagnostycznej** —
`ProductSearchService` dalej nie wie, że `products-v1` istnieje.
([RUNBOOK #020](../RUNBOOK.md#020))

### Wynik

```bash
make eval
```

```
| Wyman-Howell Laptop Ultra 14"  | 1.000 |
| Bailey Ltd Smartfon Nova 128GB | 1.000 |
| Orn PLC                        | 0.571 |    ← patrz niżej
| smartfon                       | 1.000 |
| notebook                       | 1.000 |    ← synonim działa
| komórka                        | 1.000 |    ← synonim działa
| ...                            |       |
Średnie nDCG@10: 0.967 (na 13 zapytaniach)
```

`Orn PLC` (0.571) to nie regresja: sama nazwa marki jako wolny tekst daje
**równy `_score`** dla wszystkich jej produktów, a kolejność remisów jest
arbitralna — część z ocenionych 15 nie trafia do top 10. Gdyby "szukanie po
marce" było realnym przypadkiem użycia, właściwą odpowiedzią byłby filtr
`term`, nie `multi_match`.

To jest **punkt odniesienia** ([POMIARY 5a](../POMIARY.md)). Każda przyszła
zmiana boostów, analizatorów czy synonimów: `make eval` przed i po.

### `_explain`: dlaczego #1 jest pierwszy

Dla `q=Wyman-Howell Laptop Ultra 14"`:
- #1 `Wyman-Howell Laptop Ultra 14"` — score **53.09**
- #2 `Wyman-Howell Laptop Neo 14"` — score **45.62**

Ta sama marka, ta sama kategoria. `POST products-search/_explain/74` pokazuje,
że różnica pochodzi **wyłącznie** z tokenu `ultra`: dokument #1 ma go w nazwie,
a to rzadki term (59 na 1500 dokumentów → wysokie `idf`). Dokument #2 ma
`neo`, którego nie ma w zapytaniu. Pełny rozbiór z liczbami BM25 (`idf`, `tf`,
`k1`, `b`): [RUNBOOK, "Notatka — czytanie `_explain`"](../RUNBOOK.md).

---

## Pułapki infrastruktury, na które wpadliśmy

Kod wyszukiwarki to połowa historii. Druga połowa to środowisko — i tu
każda pułapka ma swój wpis w RUNBOOK-u:

| # | Objaw | Przyczyna w jednym zdaniu |
|---|---|---|
| [#021](../RUNBOOK.md#021) | restart klastra wisi w nieskończoność | `depends_on: service_healthy` + healthcheck wymagający kworum = zakleszczenie (tylko przy restarcie, nie przy pierwszym starcie) |
| [#022](../RUNBOOK.md#022) | `port is already allocated`, stack w połowie | inne projekty na maszynie trzymają te same porty; `make doctor` teraz to sprawdza |
| [#024](../RUNBOOK.md#024) | `Cannot find native binding` w ESLint | kontener Linux nadpisywał `node_modules` hosta macOS; teraz osobny wolumen |
| [#025](../RUNBOOK.md#025) | node'y ES znikają bez logu | OOM na poziomie VM Dockera (nie kontenera!), bo inne projekty zjadły pamięć |

Wspólny morał: **gdy błąd dotyczy wszystkiego naraz** (każdy plik w ESLint,
każdy node ES, każdy test 100× wolniej) — szukaj w środowisku, nie w kodzie.

---

## Twoja kolej: weryfikacja w przeglądarce

To jedyna rzecz z DoD, której nie dało się zautomatyzować z mojej strony:
wbudowana przeglądarka w środowisku, w którym pracowałem, odmawia połączenia
z certyfikatem wystawionym przez lokalne CA Caddy'ego. Wszystko inne (kontrakt
Inertii, liczba zapytań, PIT, kursory) jest sprawdzone testami i slowlogiem —
ale *wygląd* i *klikanie* musisz zobaczyć sam. Zajmie to ~10 minut.

### 0. Przygotowanie

```bash
make up-apps                 # doctor -> porty -> cały stack z aplikacjami
make seed n=1500             # tylko jeśli indeks jest pusty (make es-health)
```

> **Porty na tej maszynie są przesunięte** (RUNBOOK #022): ES na 19200,
> Vite na 15173 itd. — patrz `.env`. Strona dalej jest pod
> **https://catalog.localhost:8443**.

### 1. Certyfikat (jednorazowo)

Caddy wystawia certyfikat z własnego lokalnego CA. Dwie drogi:

- **Szybka:** otwórz https://catalog.localhost:8443/search, w Chrome kliknij
  *Zaawansowane → Przejdź do catalog.localhost (niebezpieczne)*.
- **Porządna (raz na zawsze):** dodaj CA Caddy'ego do pęku kluczy macOS:

  ```bash
  docker compose cp catalog-app:/data/caddy/pki/authorities/local/root.crt ./caddy-root.crt
  ```

  ```bash
  sudo security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain ./caddy-root.crt
  ```

  i usuń `caddy-root.crt` z katalogu repo.

### 2. Lista kontrolna

Otwórz DevTools → zakładka **Network**, filtr `search`.

| # | Zrób | Oczekiwane |
|---|---|---|
| 1 | Wejdź na `/search` | lista produktów, licznik wyników, szkielet histogramu, który po chwili zamienia się w słupki |
| 2 | W Network znajdź **drugi** request do `/search` | nagłówek `X-Inertia-Partial-Data: priceHistogram` |
| 3 | Wpisz w pole "lap" | po ~300 ms (debounce) lista podpowiedzi; w Network request do `/api/suggest` |
| 3a | DevTools → Network → throttling **Slow 3G**, wpisuj "laptop" z przerwami ~0.5 s między literami | wcześniejsze requesty do `/api/suggest` oznaczone jako **(canceled)** — to `AbortController`; podpowiedzi zawsze pasują do OSTATNIEGO tekstu (numer sekwencyjny). Wyłącz throttling. |
| 4 | Wybierz podpowiedź | wyniki dla tej frazy, histogram się odświeża |
| 5 | Zaznacz markę w panelu | wyniki się zawężają; **inne marki nadal mają liczniki** > 0 |
| 6 | Sprawdź ten request w Network | `X-Inertia-Partial-Data: results,facets,filters`, `X-Inertia-Reset: results` — **bez** `priceHistogram` |
| 7 | Zaznacz drugą markę | wyniki z obu marek |
| 8 | Przesuń suwak ceny | etykieta zmienia się płynnie, request leci **dopiero po puszczeniu** |
| 9 | Zmień sortowanie na "Cena: od najniższej" | pierwsze karty mają najniższe ceny |
| 10 | Przewiń do końca listy | doładowują się kolejne karty (spinner); request z `cursor=...` i nagłówkiem `X-Inertia-Infinite-Scroll-Merge-Intent: append`; **URL się nie zmienia** |
| 11 | Przewijaj do skutku | na końcu "To już wszystkie wyniki." |
| 12 | Skopiuj URL, otwórz w nowej karcie | ten sam widok: fraza, marki, sort, cena |
| 13 | Zaznacz markę A, potem markę B, potem kliknij "wstecz" | zostaje tylko marka A (poprzedni stan); drugie "wstecz" — bez marek. Pisanie frazy NIE tworzy wpisów w historii |
| 14 | Zostaw kartę na **2 minuty**, potem przewiń dalej | doładowuje się normalnie (wygasły PIT, RUNBOOK #023) — **bez** błędu |

Jeśli któryś punkt nie działa — zapisz objaw (dokładny komunikat, request
z Network) i to jest gotowy wpis do RUNBOOK-a.

---

## Zadania domowe

Rzeczy, które świadomie zostały poza ETAPEM 7, ułożone od najprostszych.
Każde z nich da się zrobić w jeden wieczór i zmierzyć `make eval`.

1. **Literówki.** Dodaj do `multi_match` `fuzziness: AUTO` + `prefix_length:
   1`. Dopisz do `queries.yaml` dwa zapytania z literówką ("laptpo",
   "smartfn"). Zmierz nDCG przed i po. *Pułapka do znalezienia:* jak
   fuzziness wpływa na zapytania, które były już trafione w 100%?

2. **Sortowanie po prawdziwej najniższej cenie dostępnej oferty.** Teraz sort
   "cena" używa `price_min` (najtańsza oferta, także ze `stock: 0`). Zrób
   `nested` sort po `offers.price` z `mode: min` i `nested.filter: stock > 0`.
   Porównaj `took` w slowlogu.

3. **Precyzyjny filtr ceny.** Zastąp nakładanie przedziałów (krok 4)
   zapytaniem `nested` po `offers.price` i znajdź w danych produkt, który
   wcześniej był fałszywie "w przedziale".

4. **50 zapytań kontrolnych.** Plan mówi ~50; mamy 13. Dopisz kolejne —
   najlepiej z kategorii, w których wynik Cię zaskoczył.

5. **ETAP 7b — SSR.** `curl` na stronę wyników powinien zwracać HTML
   z produktami (SEO). Inertia v3 robi SSR przez plugin Vite; w produkcji
   potrzebny jest osobny proces. DoD: `curl -k https://catalog.localhost:8443/search?q=laptop | grep "Laptop"`.

---

## Mapa plików

```
apps/catalog/
├── app/
│   ├── Console/Commands/
│   │   ├── SeedMarketplaceCommand.php      krok 1   marketplace:seed
│   │   └── SearchEvalCommand.php           krok 13  search:eval (_rank_eval)
│   ├── Http/Controllers/
│   │   ├── SearchController.php            krok 10  Inertia, leniwe propsy, scroll
│   │   └── Api/{Search,Suggest}Controller  krok 10  JSON
│   └── Services/
│       ├── ProductSearchService.php        kroki 3-8 cała logika ES
│       └── Search/
│           ├── SearchCriteria.php          krok 2
│           ├── SearchResult.php            krok 2
│           └── InvalidSearchCursorException.php   krok 7
├── resources/js/
│   ├── pages/Search.vue                    krok 11  orkiestracja
│   ├── components/search/                  krok 11  SearchBar, FacetPanel, ...
│   ├── lib/currency.ts                     grosze <-> zł
│   └── types/search.ts                     kontrakt TS
└── tests/
    ├── Unit/Services/ProductSearchServiceQueryTest.php    krok 9  DSL bez ES
    └── Feature/
        ├── SearchIntegrationTest.php       krok 9  żywy ES
        ├── SearchControllerTest.php        krok 10 trasy i kontrakt
        └── SearchPropsLazinessTest.php     krok 12 dowód leniwości (mock)

tests/relevance/queries.yaml                krok 13  zapytania kontrolne
tools/search-slowlog-proof.sh               krok 12  make search-proof
tools/slowlog-summary.py                    krok 12  make es-slowlog
```

Komendy:

```bash
make seed n=1500     # dane
make eval            # nDCG@10
make search-proof    # dowód: zapytania do ES per akcja
make es-slowlog-on / es-slowlog / es-slowlog-off
```
