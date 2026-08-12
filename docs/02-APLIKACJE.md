# 02 — Plan aplikacji: model, kontrakty, przepływy

---

## 1. Podział odpowiedzialności

| | `catalog` (Laravel 13) | `search` (Symfony 8) |
|---|---|---|
| Rola | **write side** + BFF dla frontu | **read model builder** + operacje na ES |
| Baza | Postgres `catalog` (source of truth) | Postgres `searchsvc` (stan procesów) |
| Pisze do ES? | **nie** (jeden wyjątek: nic) | tak, wyłącznie ono |
| Czyta z ES? | tak (wyszukiwanie dla użytkownika) | tak (percolator, weryfikacja) |
| Kolejka | **publikuje** eventy | **konsumuje** eventy, publikuje zwrotne |
| Skalowanie | po ruchu HTTP | po głębokości kolejek |

**Zasada, której nie łamiemy:** tylko `search-service` zna mapowania i nazwy indeksów.
Laravel zna **alias** (`products-search`) i kontrakt zapytań. Dzięki temu zmiana struktury
indeksu nie wymaga deployu Laravela.

---

## 2. Model domenowy (Postgres `catalog`)

```
sellers        id, name, slug, rating, city, lat, lon, created_at
categories     id, parent_id, name, slug, path (ltree)          ← hierarchia!
brands         id, name, slug
products       id, brand_id, category_id, name, description, attributes(jsonb),
               ean, created_at, updated_at
offers         id, product_id, seller_id, price_cents, currency, stock,
               condition(new|used), shipping_days, active, created_at, updated_at
reviews        id, product_id, user_id, rating, body, created_at
users          id, email, name
saved_alerts   id, user_id, query(jsonb), channel, active         ← percolator
outbox         id, aggregate_type, aggregate_id, event_type, payload(jsonb),
               occurred_at, published_at, version                 ← klucz do niezawodności
```

Dlaczego `products` i `offers` osobno: bo to wymusza naukę **denormalizacji**. Jeden produkt
ma N ofert od różnych sprzedawców. W ES musimy zdecydować:
- 1 dokument = produkt z `nested` ofertami, czy
- 1 dokument = oferta + `collapse` po `product_id`?

**Zrobimy oba** i porównamy (moduł 8). To najlepsza możliwa lekcja modelowania w ES,
bo pokazuje realne kompromisy: aktualizacja ceny jednej oferty przy `nested` oznacza
przeindeksowanie całego produktu (Lucene nie umie aktualizować pojedynczego nested doc).

---

## 3. Kontrakt zdarzeń (to jest API między serwisami)

Format: JSON, koperta w stylu **CloudEvents**, wersjonowana.

```json
{
  "id": "01J8Z...",                    // ULID, klucz idempotencji
  "type": "offer.price_changed",
  "version": 1,
  "source": "catalog",
  "occurred_at": "2026-08-12T13:00:00Z",
  "aggregate": { "type": "offer", "id": "9312" },
  "sequence": 47,                      // wersja agregatu → kolejność zdarzeń!
  "data": { "product_id": 118, "price_cents": 289900, "old_price_cents": 319900 }
}
```

**Dwa pola, które ratują życie:**
- `id` → konsument zapisuje przetworzone ID i odrzuca duplikaty (RabbitMQ gwarantuje
  *at-least-once*, więc duplikaty **będą**).
- `sequence` → mapowane na `version` w ES z `version_type=external`. Jeśli przyjdzie
  starsze zdarzenie po nowszym (bo retry), ES **odrzuci zapis** z konfliktem 409.
  To jest dokładnie ten mechanizm, którego szuka się w rozmowie o „out-of-order events".

### Katalog zdarzeń

| Routing key | Kiedy | Konsument |
|---|---|---|
| `product.created` / `.updated` / `.deleted` | CRUD produktu | sync |
| `offer.created` / `.updated` / `.price_changed` / `.stock_changed` / `.deactivated` | CRUD oferty | sync |
| `seller.updated` | zmiana nazwy/ratingu sprzedawcy | sync (**fan-out!** → reindeks wszystkich jego ofert) |
| `category.renamed` | zmiana kategorii | sync (fan-out, `update_by_query`) |
| `review.created` | nowa opinia | sync (przelicza avg rating) |
| `user.searched` | użytkownik wyszukał | analytics |
| `user.clicked_result` | kliknięcie w wynik | analytics |
| `user.added_to_cart` | koszyk | analytics |
| `alert.matched` | percolator dopasował | notifications (**publikuje Symfony**, konsumuje Laravel) |

`seller.updated` i `category.renamed` są celowo w planie — to **problem fan-outu**:
jedno zdarzenie może wymagać aktualizacji 100 tys. dokumentów. Nauczysz się
`update_by_query` z `conflicts=proceed`, Tasks API, throttlingu (`requests_per_second`)
i tego, kiedy lepiej po prostu przeindeksować.

---

## 4. Wzorzec Transactional Outbox (najważniejszy fragment strony zapisu)

**Problem:** zapisujesz ofertę do Postgresa i publikujesz event do RabbitMQ. Co, jeśli
publikacja padnie po commicie? Event ginie, ES ma stare dane — **na zawsze**, bo nikt się
o tym nie dowie. Odwrotnie: publikacja przed commitem → event o czymś, czego nie ma.

**Rozwiązanie:** w tej samej transakcji SQL zapisujesz encję **i wiersz do `outbox`**.
Osobny proces (`catalog-outbox`) czyta niepublikowane wiersze i wysyła je do RabbitMQ,
oznaczając `published_at` po otrzymaniu **publisher confirm**.

```
BEGIN;
  UPDATE offers SET price_cents = 289900 WHERE id = 9312;
  INSERT INTO outbox (...) VALUES ('offer.price_changed', ...);
COMMIT;
                    ↓ (osobny proces, pętla)
  SELECT * FROM outbox WHERE published_at IS NULL ORDER BY id LIMIT 500
  FOR UPDATE SKIP LOCKED;          ← nauka: konkurencyjne czytanie kolejki w SQL
  → basic_publish z confirm mode
  → UPDATE outbox SET published_at = now()
```

Gwarancja: **at-least-once**. Nigdy nie zgubisz zdarzenia, ale możesz je wysłać dwa razy —
i dlatego konsument musi być idempotentny (patrz `id` w kopercie).

W Laravelu zrobimy to jako:
- event domenowy + listener zapisujący do outboxu (albo model observer),
- komenda `php artisan outbox:publish --loop` z graceful shutdown na SIGTERM.

Publikację zrobimy **jawnie przez `php-amqplib`**, a nie przez gotowy driver kolejki
Laravela — celowo, żebyś zobaczył `channel`, `exchange_declare`, `basic_publish`,
`confirm_select`, `delivery_mode=2`. Dopiero rozumiejąc to, warto sięgać po abstrakcje.

---

## 5. Strona konsumenta (Symfony Messenger)

```
Transport (AMQP)  →  Serializer (nasza koperta)  →  Middleware  →  Handler
                                                     ├ idempotency (Redis/PG)
                                                     ├ logging + correlation_id
                                                     └ metrics
```

**Kluczowe elementy do nauczenia się:**

1. **Batching.** Handler nie indeksuje pojedynczo — zbiera do bufora i woła `_bulk`
   co N dokumentów lub co M ms. Indeksowanie po jednym dokumencie to najczęstsza
   przyczyna „ES jest wolny" (nie jest — Ty go źle używasz).
2. **Retry strategy** — `max_retries: 3`, `multiplier: 2`, `delay: 1000`. Po wyczerpaniu →
   `failure_transport` (DLQ). Zobaczysz, jak Messenger realizuje to na RabbitMQ (osobne
   kolejki `*.retry` z TTL i DLX).
3. **Rozróżnianie błędów**: `RecoverableMessageHandlingException` (ES 503 → ponów) vs
   `UnrecoverableMessageHandlingException` (mapping error → do DLQ od razu, nie zapętlaj).
   To jest sedno odporności.
4. **Prefetch** dopasowany do batcha.
5. **Graceful shutdown** — `messenger:consume --time-limit --memory-limit` i dlaczego
   long-running PHP zawsze powinien mieć limity (wycieki pamięci).

---

## 6. Indeksy w Elasticsearch — plan docelowy

| Alias / data stream | Zawartość | Czego uczy |
|---|---|---|
| `products-search` → `products-v1`, `v2`… | zdenormalizowany produkt + oferty | mapowania, analizatory, aliasy, reindeks |
| `offers-search` | 1 dokument = oferta (wariant B) | collapse, porównanie modeli |
| `products-suggest` | dane do autouzupełniania | completion suggester, edge_ngram |
| `alerts-percolator` | zapisane zapytania użytkowników | percolator |
| `logs-app-*` (data stream) | logi obu aplikacji | data streams, ILM, ECS, ingest pipeline |
| `events-user-*` (data stream) | wyszukiwania, kliknięcia, koszyk | analityka, agregacje, transforms |
| `metrics-*` | Metricbeat | Stack Monitoring |

### Szkic mapowania `products-v1` (będziemy je rozwijać moduł po module)

```jsonc
{
  "settings": {
    "number_of_shards": 1,          // świadoma decyzja — uzasadnimy w module 10
    "number_of_replicas": 0,        // 0 w single-node, 1 w klastrze
    "refresh_interval": "1s",       // podniesiemy do 30s przy bulk-reindeksie
    "analysis": {
      "filter": {
        "pl_stem":     { "type": "polish_stem" },
        "pl_stop":     { "type": "stop", "stopwords": "_polish_" },
        "synonyms_pl": { "type": "synonym_graph", "synonyms_path": "synonyms.txt" },
        "edge2_20":    { "type": "edge_ngram", "min_gram": 2, "max_gram": 20 }
      },
      "analyzer": {
        "pl_index":  { "tokenizer": "standard", "filter": ["lowercase","asciifolding","pl_stop","pl_stem"] },
        "pl_search": { "tokenizer": "standard", "filter": ["lowercase","asciifolding","synonyms_pl","pl_stop","pl_stem"] },
        "pl_autocomplete": { "tokenizer": "standard", "filter": ["lowercase","asciifolding","edge2_20"] }
      },
      "normalizer": {
        "keyword_lc": { "type": "custom", "filter": ["lowercase","asciifolding"] }
      }
    }
  },
  "mappings": {
    "dynamic": "strict",            // TAK. Uczymy się od razu dobrze.
    "properties": {
      "name": {
        "type": "text",
        "analyzer": "pl_index",
        "search_analyzer": "pl_search",
        "fields": {
          "raw":  { "type": "keyword", "normalizer": "keyword_lc" },
          "ac":   { "type": "text", "analyzer": "pl_autocomplete", "search_analyzer": "pl_search" },
          "sayt": { "type": "search_as_you_type" }
        }
      },
      "description": { "type": "text", "analyzer": "pl_index", "search_analyzer": "pl_search" },
      "brand":    { "type": "keyword" },
      "category": {
        "properties": {
          "id":   { "type": "keyword" },
          "path": { "type": "keyword" },      // "elektronika/telefony/smartfony"
          "tree": { "type": "text", "analyzer": "path_hierarchy_analyzer" }  // drill-down!
        }
      },
      "attributes":  { "type": "flattened" },       // dowolne cechy bez eksplozji mapowania
      "price_min":   { "type": "scaled_float", "scaling_factor": 100 },
      "price_max":   { "type": "scaled_float", "scaling_factor": 100 },
      "in_stock":    { "type": "boolean" },
      "rating_avg":  { "type": "half_float" },
      "rating_count":{ "type": "integer" },
      "popularity":  { "type": "rank_feature" },    // zasilane z analityki kliknięć
      "created_at":  { "type": "date" },
      "offers": {
        "type": "nested",                            // wariant A
        "properties": {
          "offer_id":  { "type": "keyword" },
          "seller_id": { "type": "keyword" },
          "seller":    { "type": "keyword" },
          "price":     { "type": "scaled_float", "scaling_factor": 100 },
          "stock":     { "type": "integer" },
          "location":  { "type": "geo_point" }
        }
      },
      "embedding": { "type": "dense_vector", "dims": 384, "index": true, "similarity": "cosine" },
      "indexed_at": { "type": "date" }
    }
  }
}
```

Każdy element tego mapowania ma swój moduł w `03-SCIEZKA-NAUKI.md`. Nie wpiszemy tego
od razu — **zbudujemy je warstwa po warstwie**, za każdym razem sprawdzając w `_analyze`
i `_explain`, co się faktycznie dzieje.

Zwróć uwagę na `"dynamic": "strict"` — świadomie blokujemy dynamiczne mapowanie.
Zobaczysz błąd `strict_dynamic_mapping_exception` i zrozumiesz, dlaczego to jest
**dobra wiadomość**, a nie przeszkoda (mapping explosion = klaster na kolanach).

---

## 7. Przepływy end-to-end

### 7.1 Dodanie oferty (happy path)

```
Sprzedawca → POST /seller/offers (Laravel)
  → walidacja → INSERT offers + INSERT outbox   [jedna transakcja]
  → HTTP 201 (użytkownik nie czeka na ES!)
catalog-outbox → basic_publish offer.created → RabbitMQ
RabbitMQ → search.product.sync
search-consumer-sync
  → sprawdź idempotencję (event id)
  → pobierz kontekst (produkt + pozostałe oferty)   ← skąd? patrz niżej
  → zbuduj dokument → bufor → _bulk
  → ES (refresh za ~1 s)
Użytkownik → GET /search?q=... (Laravel) → ES alias products-search → wyniki
```

**Problem do rozwiązania i przedyskutowania (ważny!):** skąd Symfony bierze „produkt +
pozostałe oferty", skoro nie ma dostępu do bazy Laravela? Trzy opcje, przećwiczymy dwie:

- **A. Fat events** — event niesie komplet danych potrzebnych do zbudowania dokumentu.
  Zaleta: brak zależności. Wada: duże wiadomości, trudne wersjonowanie.
- **B. Read-back API** — Symfony woła `GET /internal/products/{id}/projection` w Laravelu.
  Zaleta: zawsze aktualne, mały event. Wada: sprzężenie i ruch synchroniczny.
- **C. CDC (Debezium)** — czytanie WAL Postgresa. Wspomnimy jako wariant enterprise.

Plan: zaczynamy od **B** (prościej zrozumieć), potem migrujemy do **A** i porównujemy —
w tym problem „thundering herd", gdy 10 tys. eventów wywołuje 10 tys. requestów HTTP.

### 7.2 Wyszukiwanie (ścieżka czytania)

```
GET /search?q=laptop+gamingowy&brand=Asus&price_max=5000&sort=relevance&page=2

Laravel:
  1. parsuje i waliduje parametry (nigdy nie budujemy DSL ze stringów użytkownika!)
  2. buduje Query DSL przez własny QueryBuilder (testowalny, bez ES w testach jednostkowych)
  3. wysyła do aliasu products-search  (timeout! retry! circuit breaker!)
  4. mapuje odpowiedź na DTO
  5. emituje event user.searched (przez outbox → analityka)
  6. zwraca: wyniki + facety + suggestions + total
```

Zapytanie, do którego dojdziemy (moduł po module):
```jsonc
{
  "query": {
    "bool": {
      "must": [{ "multi_match": {
          "query": "laptop gamingowy",
          "fields": ["name^3","name.ac^2","brand^2","description","category.tree"],
          "type": "best_fields", "fuzziness": "AUTO", "operator": "and"
      }}],
      "filter": [                                   // filter context = brak scoringu + cache
        { "term":  { "brand": "Asus" } },
        { "range": { "price_min": { "lte": 5000 } } },
        { "term":  { "in_stock": true } }
      ],
      "should": [
        { "rank_feature": { "field": "popularity", "boost": 2 } },
        { "distance_feature": { "field": "created_at", "origin": "now", "pivot": "30d" } }
      ]
    }
  },
  "aggs": { "brands": {"terms":{"field":"brand","size":20}},
            "price":  {"histogram":{"field":"price_min","interval":500}} },
  "sort": ["_score", {"price_min":"asc"}],
  "collapse": { "field": "product_id", "inner_hits": {"name":"cheapest","size":1,
                "sort":[{"offers.price":"asc"}]} },
  "track_total_hits": 1000,
  "_source": ["name","brand","price_min","rating_avg"]   // nie ciągnij całego dokumentu!
}
```

### 7.3 Alerty (percolator) — przepływ odwrotny

```
Użytkownik zapisuje alert  → Laravel zapisuje w saved_alerts + event alert.registered
search-service → indeksuje ZAPYTANIE do alerts-percolator
Nowa oferta → sync consumer buduje dokument
           → dodatkowo woła _search z percolate query na alerts-percolator
           → dopasowane alerty → publikuje alert.matched do RabbitMQ
Laravel (consumer) → wysyła maila przez Mailpit
```

To odwrócenie logiki wyszukiwania („zapytania są danymi, dokumenty są zapytaniem") jest
jedną z najbardziej niedocenianych funkcji ES — i świetnie pokazuje, jak myśleć o Lucene.

### 7.4 Reindeks bez downtime

```
1. search-service tworzy products-v2 z nowym mapowaniem
2. ustawia refresh_interval=-1, number_of_replicas=0        ← tryb "bulk"
3. POST _reindex  z products-v1  (albo pełny backfill z bazy przez API/eventy)
4. w międzyczasie: nowe eventy lecą do OBU indeksów (dual write)
5. przywraca refresh_interval, replicas; force_merge
6. atomowe przełączenie aliasu:
   POST _aliases {"actions":[{"remove":{"index":"products-v1","alias":"products-search"}},
                             {"add":{"index":"products-v2","alias":"products-search"}}]}
7. weryfikacja (ranking eval na zestawie zapytań!), potem usunięcie v1
```

Zbudujemy to jako komendę Symfony ze stanem w bazie `searchsvc`, żeby dało się wznowić.

---

## 8. API, które powstanie

**Catalog (Laravel) — trzy rodzaje endpointów, jedna warstwa logiki**

Wszystkie trzy korzystają z tych samych klas serwisowych (`ProductSearchService`,
`OfferService`). Kontrolery są cienkimi adapterami — to jest decyzja D-09 i dzięki niej
zmiana frontendu nigdy nie dotyka logiki.

*1. Inertia (przeglądarka, propsy Vue):*
```
GET  /search                       props: results, facets, suggestions, filters
                                   partial reload: only=['results','facets']
                                   deferred: priceHistogram
GET  /products/{slug}              karta produktu (Postgres, nie ES!)
GET  /seller/dashboard             statystyki sprzedawcy
POST /seller/offers                CRUD (useForm + walidacja + redirect back)
POST /me/alerts                    zapisany alert
```

*2. JSON API (autocomplete, telemetria, klienci zewnętrzni):*
```
GET  /api/search?q=                to samo co wyżej, ale czysty JSON
GET  /api/suggest?q=               autouzupełnianie (wywoływane z fetch, nie Inertią)
POST /api/events/click             telemetria kliknięć (→ outbox → analityka)
```

*3. Internal API (tylko sieć dockerowa, dla `search-service`):*
```
GET  /internal/products/{id}/projection
GET  /health                       zależności: PG, Redis, ES, RabbitMQ (z degradacją!)
```

Dlaczego autocomplete idzie przez `fetch`, a nie przez Inertię: Inertia zawsze przeładowuje
propsy strony i wpisuje do historii przeglądarki. Podpowiedzi przy każdym znaku nie mogą
tego robić. To dobre miejsce, żeby zrozumieć **granicę odpowiedzialności Inertii**:
nawigacja i stan strony — tak; drobne, częste zapytania pomocnicze — zwykły JSON.

**Search (Symfony):**
```
GET  /admin/status                 stan indeksów, lag kolejek, ostatni reindeks
POST /admin/reindex                start reindeksu (idempotentny)
GET  /admin/mappings/diff          różnica między mapowaniem w kodzie a w ES
POST /admin/synonyms/reload        przeładowanie synonimów bez reindeksu
GET  /health
```

Zwróć uwagę na `/products/{slug}` czytany z **Postgresa**, nie z ES. To celowe:
uczysz się, że ES służy do **wyszukiwania**, a nie do pobierania encji po ID.

---

## 9. Testowanie (nie pomijamy tego)

| Poziom | Co testujemy | Jak |
|---|---|---|
| jednostkowy | QueryBuilder produkuje poprawny DSL | asercje na tablicy, bez ES |
| integracyjny | mapowanie + analizator dają oczekiwane tokeny | prawdziwy ES, indeks per test, `_analyze` |
| integracyjny | handler + bulk + idempotencja | ES + Rabbit z compose |
| **jakości wyszukiwania** | czy „addidas buty" zwraca Adidasa na 1. miejscu | **Ranking Evaluation API** + zestaw ~50 zapytań z oczekiwaniami (nDCG, MRR, precision@k) |
| kontraktowy | koperta eventu zgodna z JSON Schema | walidacja po obu stronach |
| obciążeniowy | p95 latency wyszukiwania przy 100 rps | k6 |

**Ranking Evaluation API** to rzecz, o której 90 % programistów nie wie, że istnieje —
a to jedyny sposób, żeby tuning relevancji nie był zgadywanką. Będzie własny moduł.
