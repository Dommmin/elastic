# POMIARY — Twoje własne liczby

> Zasada projektu: **mierz, nie wierz.** Zdanie o wydajności bez liczby nie istnieje.
> Ta tabela ma być argumentem w dyskusji — Twoim, opartym na Twoim sprzęcie,
> a nie cytatem z bloga sprzed trzech wersji major.

**Sprzęt referencyjny:** _(uzupełnij przy pierwszym pomiarze)_
- CPU / RAM hosta:
- Pamięć przydzielona Dockerowi:
- `ES_HEAP`, liczba node'ów:

Każdy pomiar rób **3 razy** i zapisuj medianę. Pierwszy przebieg zawsze jest wolniejszy
(zimny cache stron, JIT, puste cache ES) — to nie jest wynik, to rozgrzewka.

---

## 1. Indeksowanie (ETAP 5–6, moduł 5)

| Metoda | Dokumentów | Czas | dok./s | Uwagi |
|---|---|---|---|---|
| pojedynczo (`_doc` per dokument) | 100 000 | | | |
| bulk 500 | 1 000 000 | | | |
| bulk 1000 | 1 000 000 | | | |
| bulk 1000 + `refresh_interval: -1` | 1 000 000 | | | |
| bulk 1000 + `-1` + `replicas: 0` | 1 000 000 | | | |
| pełny backfill (5 mln produktów) | 5 000 000 | | | |

**Wniosek:**

---

## 2. Sharding (ETAP 8, moduł 10)

Ten sam zbiór 5 mln dokumentów, różna liczba primary shardów.

| Shardy | Rozmiar indeksu | Czas indeksacji | p50 zapytania | p95 | p99 |
|---|---|---|---|---|---|
| 1 | | | | | |
| 3 | | | | | |
| 6 | | | | | |
| 12 | | | | | |

Typy zapytań mierzone: (a) `term` po marce, (b) `multi_match` pełnotekstowe,
(c) agregacja `terms` + `histogram` (facety).

**Moja tabela decyzyjna „ile shardów":**

---

## 3. Cache (moduł 10)

| Scenariusz | 1. wywołanie | Kolejne | Zysk |
|---|---|---|---|
| zapytanie w `query` context | | | |
| to samo w `filter` context | | | |
| agregacja bez `size: 0` | | | |
| agregacja z `size: 0` (request cache) | | | |

**Wniosek:**

---

## 4. FrankenPHP: worker mode vs klasyczny (ETAP 4, decyzja D-07b)

Pomiar k6, 30 s, 20 wirtualnych użytkowników.

| Endpoint | `OCTANE_ENABLED=false` | `=true` | Zysk |
|---|---|---|---|
| `/` (statyczna strona Inertii) | | | |
| `/search?q=laptop` (partial reload) | | | |
| `/api/suggest?q=lap` | | | |
| `/seller/dashboard` (ciężkie agregacje) | | | |

Hipoteza z planu do zweryfikowania: **im lżejsze żądanie, tym większy zysk**
(bootstrap to koszt stały). Czy się potwierdza?

**Wniosek i decyzja:**

---

## 5. Model danych: `nested` vs `collapse` (moduł 4)

| Model | Rozmiar indeksu | Czas indeksacji | p95 wyszukiwania | Koszt zmiany ceny 1 oferty |
|---|---|---|---|---|
| produkt + `nested` oferty | | | | |
| oferta jako osobny dokument + `collapse` | | | | |

**Wniosek:**

---

## 5a. Relewancja — baseline ETAP 7 (`search:eval`, 13 zapytań)

Świadomie MNIEJSZY harness niż docelowy z sekcji 6 (tam: ~50 zapytań,
ETAP 12) — fundament na ~1500-produktowym syntetycznym seedzie
(`php artisan marketplace:seed`, `tests/relevance/queries.yaml`), nie
docelowy zestaw na prawdziwym wolumenie. Patrz `docs/RUNBOOK.md` #020
(pułapka: `_rank_eval` `ratings` po aliasie zamiast fizycznego indeksu).

| Data | Konfiguracja | nDCG@10 (średnia, 13 zapytań) | Uwagi |
|---|---|---|---|
| 2026-08-17 | `multi_match best_fields` (`name^3`, `name.ac`, `brand^2`, `description`), bez fuzziness, + 2 zapytania testujące `synonyms.txt` | **0.967** | Jedno zapytanie (`Orn PLC`, samo dopasowanie marki jako wolny tekst) ma nDCG 0.571 — oczekiwane: równe `_score` dla wszystkich trafień daje arbitralną kolejność remisów, `ratings` obejmuje tylko część z >15 pasujących dokumentów. Nie jest to regresja do pilnowania, tylko właściwość zapytania tego typu (do rozważenia: filtr `term` na `brand`, nie `multi_match`, gdyby to był realny przypadek użycia, nie test synonimów/kategorii). |

**Uruchomienie:** `make eval` (albo `docker compose exec catalog-app php artisan search:eval`).

---

## 5b. Ile zapytań do ES kosztuje każda akcja na /search (ETAP 7, DoD)

`make search-proof` — slowlog z progiem 0ms, żądania z nagłówkami Inertii
dokładnie takimi jak z przeglądarki. 3 node'y, heap 1g, ~1500 produktów,
maszyna obciążona innymi projektami (czasy orientacyjne, liczby zapytań — nie).

| Akcja użytkownika | Zapytań do ES — PRZED poprawką | PO poprawce (2026-10-06) | Co liczyło | took |
|---|---|---|---|---|
| Pierwsze wejście (`/search?q=laptop`) | 1 | **1** | wyniki + facety | 54 ms |
| Auto-request po `priceHistogram` (deferred) | **2** (search + histogram) | **1** | tylko histogram | 26 ms |
| Klik w facet marki | 1 | **1** | wyniki + facety, bez histogramu | 14 ms |
| Przewinięcie (kolejna strona) | 1 (z agregacjami facetów) | **1** | wyniki, **bez agregacji** | 16 ms |

"Przed" = wersja z pierwszej sesji ETAPU 7 (`results`/`facets` jako zwykłe
tablice liczone zawsze, facety także na stronie 2+). Poprawka: leniwe closures
z memoizacją w `SearchController` + agregacje tylko dla pierwszej strony
w `ProductSearchService::search()`. Dowód automatyczny bez ES:
`tests/Feature/SearchPropsLazinessTest.php` (Mockery liczy wywołania serwisu).

---

## 6. Relewancja (moduł 7, ETAP 12)

Mierzone przez `_rank_eval` na zestawie ~50 zapytań kontrolnych.

| Konfiguracja | nDCG@10 | MRR | precision@5 |
|---|---|---|---|
| baseline (`multi_match`, bez boostów) | | | |
| + boost na `name^3` | | | |
| + synonimy | | | |
| + `rank_feature` (popularność z kliknięć) | | | |
| + `distance_feature` (świeżość) | | | |
| hybryda BM25 + kNN z RRF | | | |

**Wniosek — co realnie poprawiło wyniki, a co było tylko intuicją:**

---

## 7. Wektory (moduł 13)

| Metryka | Bez `dense_vector` | Z `dense_vector` (384 dims) |
|---|---|---|
| rozmiar indeksu | | |
| czas indeksacji 1 mln dok. | | |
| zużycie heapu | | |
| p95 wyszukiwania | | |

**Wniosek — czy semantyka była warta swojej ceny:**

---

## 8. Spójność (ETAP 9)

| Metryka | Wartość | SLO z `05-SPOJNOSC-DANYCH.md` |
|---|---|---|
| lag p50 (`indexed_at` − `occurred_at`) | | |
| lag p95 | | |
| lag p99 | | < 2 s (cena, stan) |
| przepustowość konsumenta (1 replika) | | |
| przepustowość (4 repliki) | | |
| czas pełnego reconciliation (5 mln) | | |
| czas pełnego reindeksu | | |

**Wniosek:**

---

## 9. ETAP D — VPS (2026-10-10)

Serwer: Ubuntu 24.04, 4 vCPU, 24 GB RAM, 99 GB NVMe. Stack: 3 nody ES
(heap 1500m, limit 3g), Kibana, Postgres, Redis, RabbitMQ, catalog,
outbox-publisher, search-consumer. Obrazy z GHCR, tag = SHA commita.

| Pomiar | Wartość | Uwagi |
|---|---|---|
| CI: build 5 obrazów + test + push (zimny cache) | 10,5 min | pierwszy przebieg |
| CI: to samo z cache GHA (zmiana w catalog) | 4,3 min | |
| pierwsze wdrożenie (pull ~7 GB + start + migracje) | ~4,5 min | |
| wdrożenie / rollback innego tagu | ~3,5 min | nowy tag = też nowy obraz ES → restart całego klastra; do usprawnienia (wersjonować osobno obrazy infrastruktury i aplikacji) |
| restart serwera → ES green 3/3 + wszystko healthy | 187 s | `verify.sh reboot` |
| zmiana ceny w catalog → widoczna w ES | 1–3 s | outbox-publisher → RabbitMQ → search-consumer |
| `/search?q=…` na serwerze (50 zapytań, 5 fraz) | p50 106 ms, p95 191 ms, max 637 ms | cała odpowiedź Inertii, bez sieci/tunelu |
| snapshot SLM (52 indeksy) | 1,6 s | |
| `pg_dump -Fc` catalog / searchsvc | 377 KB / 31 KB | |
| RAM zajęty (cały stack) | 9,9 / 24 GB (41%) | ES 2,2–2,6 GB/node, Kibana 1,1 GB, reszta < 0,4 GB |
| dysk | 12 / 99 GB | obrazy 7 GB |
| `search:eval` (świeży seed) | 0.841 | identyczny przed/po restarcie i po restore; patrz RUNBOOK #032 |
