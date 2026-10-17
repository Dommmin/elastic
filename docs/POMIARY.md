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
| 2026-10-15 | j.w., po Portalu sprzedawcy: w projekcji tylko oferty `listed()` (`published` i stan > 0); po `make reproject` | **0.786** | Porównywalny punkt odniesienia to **0.779** zmierzone tego samego dnia na tym samym stacku przed migracjami portalu. Starsze 0.967 pochodzi z 2026-08-17 z innych danych — nie porównywać. Szczegóły: sekcja poniżej. |

**Uruchomienie:** `make eval` (albo `docker compose exec catalog-app php artisan search:eval`).

### Portal sprzedawcy: wpływ `listed()` na wyszukiwanie (2026-10-15)

Zmiana: projekcja produktu zawiera tylko oferty `listed()` (status `published` **i** stan > 0); wcześniej wszystkie `active`, także ze stanem 0. Pomiar na tym samym stacku: „przed" = przed migracjami portalu, „po" = po migracjach i `make reproject` (przeliczenie wszystkich 1500 dokumentów; samo przetworzenie zdarzeń odświeża tylko ruszone produkty, więc indeks byłby niespójny).

| Zapytanie | Przed | Po | Nieocenione w top-10 przed | Nieocenione w top-10 po |
|---|---|---|---|---|
| Wyman-Howell Laptop Ultra 14" | 1.000 | 1.000 | 9 | 9 |
| Bailey Ltd Smartfon Nova 128GB | 1.000 | 1.000 | 9 | 9 |
| Orn PLC | 0.505 | 0.571 | 5 | 4 |
| smartfon | 0.927 | 0.852 | 1 | 2 |
| buty do biegania | 0.780 | 0.780 | 1 | 1 |
| kurtka zimowa | 0.931 | 0.931 | 1 | 1 |
| meble ogrodowe | 0.770 | 0.739 | 3 | 3 |
| wiertarka | 0.731 | 0.860 | 3 | 2 |
| rower górski | 0.905 | 0.718 | 1 | 3 |
| notebook | 0.934 | 0.870 | 1 | 2 |
| komórka | 0.589 | 0.703 | 5 | 4 |
| telefon | 0.589 | 0.703 | 5 | 4 |
| słuchawki bezprzewodowe | 0.471 | 0.486 | 5 | 5 |
| **Średnia** | **0.779** | **0.786** | | |

Oferty: 4518 w sumie, wszystkie `published`; **89 (ok. 2%) ma stan 0** i `listed()` je odfiltrowuje.

Testy i smoke (ta sama zmiana): `tests/Unit/SellerPortal`: 130 testów, 44 ms (kryterium < 1 s); `make smoke`: 25/25.

Interpretacja (hipotezy, nie zweryfikowane): średnia prawie bez zmian (+0.007), ale pojedyncze zapytania ruszyły się w obie strony (`rower górski` 0.905 -> 0.718, `wiertarka` 0.731 -> 0.860). Najprawdopodobniej przyczyną jest skład indeksu, nie ranking: produkty, które straciły wyprzedane oferty, mogą zmienić dostępność/cenę minimalną lub zniknąć z wyników, więc zmienia się skład top-10. Plik `ratings` obejmuje tylko część dokumentów, więc zmiana liczby nieocenionych trafień w top-10 wpływa na nDCG: `rower górski` ma ich 1 -> 3 (nDCG spada), `wiertarka` 3 -> 2 (rośnie), `komórka`/`telefon` 5 -> 4 (rosną), `smartfon` 1 -> 2 (spada), `notebook` 1 -> 2 (spada).  Zgodność kierunku z liczbą nieocenionych jest sugestią, nie dowodem (`notebook` i `smartfon` pasują; `meble ogrodowe` spada przy tej samej liczbie 3). Nie zmieniało się zapytanie ES. Żeby to zweryfikować: porównać listy top-10 per zapytanie przed i po (które dokumenty wypadły/weszły i czy to produkty z ofertami o stanie 0) oraz policzyć, ile z nowych dokumentów nie ma ocen.

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

---

## 10. ETAP D2 — k3s na tym samym VPS (2026-10-10/11)

Ten sam serwer, te same obrazy (`cc56c33`), te same dane (migracja z D1).
k3s v1.36.5, ECK 3.5.0, ES 3 nody heap 1500m / limit 3Gi.

| Pomiar | D1 (Compose) | D2 (k3s) | Uwagi |
|---|---|---|---|
| RAM całego stacku | 9,9 GB (41%) | 9,5 GB (39%) | k3s + containerd ~1,1 GB; ES 2,2 GB/node, Kibana 1,0 GB |
| Restart serwera → całość gotowa | 187 s | 178 s | k8s: redis/pg 33–37 s, aplikacje 56–75 s, ES 3/3 141 s, Kibana 178 s |
| Pierwsze wdrożenie aplikacji | ~4,5 min (pull) | 2:11 | k8s: obrazy w containerd k3s osobno od Dockera |
| Rolling restart ES (nowy obraz / heap) | restart całego klastra naraz | 5–6,5 min, węzeł po węźle | RED w trakcie przy `number_of_replicas: 0` (RUNBOOK #038) |
| Zmiana ceny → ES | 1–3 s | 3 s | |
| Restart poda Postgresa → aplikacje same zdrowe | — | 16–74 s | zależnie od fazy back-offu; headless Service bez DNS w trakcie |
| Migracja danych D1 → D2 | — | pg_restore + snapshot/restore | liczby wierszy =, eval 0.841 = |
| Powrót do D1 (killall + start + zdrowie) | — | ~14 min łącznie | kontenery D1 healthy po ~2 min; reszta do rozdzielenia (k3s-killall / compose --wait) |
| Burza startowa ES+Kibana obok działającego D1 | — | SSH/API niedostępne ~25 min | bez OOM; 4 vCPU (RUNBOOK #037) |
| Dysk | 12 GB | 20 GB | obrazy w dwóch runtime'ach (Docker + containerd k3s) |

---

<a id="zamowienia-2026-10-17"></a>
## 11. Zamówienia: współbieżność i E2E (2026-10-17)

Lokalny stack (`main` @ `c7a956b`: Postgres, `catalog-worker`,
`catalog-scheduler`), maszyna współdzielona z innymi projektami, VM Dockera
15,6 GB. Surowe wyjścia race-testu:
`.superpowers/sdd/2026-10-16-zamowienia-ddd/measurements/race-*.out` (lokalnie, katalog poza gitem).

### `orders:race-test` — 50 kupujących, 10 sztuk, 4 workery, oba style

Uruchomione w jednorazowym kontenerze z `--memory 3g`, bo w `catalog-app`
(512m) 50 procesów PHP się nie mieści (RUNBOOK [#049](RUNBOOK.md#049)):

```bash
env_file=$(mktemp)
docker inspect marketplace-catalog-app-1 --format '{{range .Config.Env}}{{println .}}{{end}}' > "$env_file"
docker run --rm --memory 3g --network marketplace_marketplace --env-file "$env_file" \
  -v <checkout mierzonej gałęzi>/apps/catalog:/app -w /app marketplace-catalog-app \
  php artisan orders:race-test --buyers=50 --stock=10 --workers=4
rm "$env_file"
```

| Przebieg | Opłacone | Odrzucone (saga) | Odmowy przy składaniu | Overselling | Konflikty wersji oferta / zamówienie | p50 / p95 złożenia (ms) |
|---|---|---|---|---|---|---|
| saga (DDD) 1 | 10 | 40 | 0 | 0 | 61 / 0 | 613,6 / 776,8 |
| saga (DDD) 2 | 10 | 40 | 0 | 0 | 24 / 0 | 2451,0 / 2747,2 |
| saga (DDD) 3 | 10 | 40 | 0 | 0 | 12 / 0 | 6968,8 / 7435,5 |
| jedna transakcja (lite) 1 | 10 | 0 | `offer_no_longer_available`: 40 | 0 | 0 / 0 | 2992,7 / 3652,1 |
| jedna transakcja (lite) 2 | 10 | 0 | `offer_no_longer_available`: 40 | 0 | 0 / 0 | 4450,4 / 13411,2 |
| jedna transakcja (lite) 3 | 10 | 0 | `offer_no_longer_available`: 40 | 0 | 0 / 0 | 7950,7 / 11523,2 |

We wszystkich sześciu: końcowy `offer.stock` 0, aktywne rezerwacje 0,
`failed_jobs` od startu 0, kod wyjścia 0.

**Wniosek o poprawności:** oba style 6/6 bez oversellingu, dokładnie 10
opłaconych. Saga wchłania wyścig jako 40 asynchronicznych odrzuceń
z konfliktami wersji oferty (ponawianymi); jedna transakcja jako 40
natychmiastowych odmów bez ani jednego konfliktu (kupujący czekają na
blokadzie wiersza).

**Wniosek o latencji: brak.** Czasy rosły z przebiegu na przebieg w obu
stylach, bo w trakcie węzły ES restartowały się pod presją pamięci VM
(es03: 20 restartów) obok cudzych kontenerów. p50/p95 z tej tabeli nie
nadają się do porównania stylów ani przebiegów.

Dlaczego lite odmawia kodem `offer_no_longer_available`, a nie
`out_of_stock`: po rezerwacji ostatniej sztuki oferta przestaje być
`listed` (dostępne = 0), więc snapshot oferty odmawia, zanim
składanie dojdzie do rezerwacji, gdzie padłoby `OutOfStock`.

### Wyszukiwarka: ostatnia sztuka znika i wraca

| Pomiar | Wynik |
|---|---|
| klik „Złóż zamówienia” → oferta znika z dokumentu ES | 1,3 s |
| zdarzenie `stock_reserved` w katalogu → zaindeksowane (`doc_version`) | 0,53 s (16:03:22,151 → 22,685) |
| zdarzenie `reservation_released` po anulowaniu → zaindeksowane | 0,57 s (16:03:25,202 → 25,770) |

Jeden wcześniejszy pomiar powrotu (26,7 s) był artefaktem: okno `confirm`
w przeglądarce blokowało klik. Zamówienie bez płatności wygasło przez
sweep po 15 + 5 min i oferta wróciła do indeksu.

### Saga po awarii workera

`docker compose stop catalog-worker`, złożone zamówienie: po 10 s dalej
`placed`, jeden job w `jobs`. `docker compose start catalog-worker` →
`awaiting_payment` po **28,1 s**, wliczając start kontenera. Normalne
przejście z działającym workerem: ~1 s (historia zamówienia: 17:10:21 →
17:10:22). Awarii w połowie żądania dla jednej transakcji **nie mierzyłem**
(wymaga ubicia procesu PHP w trakcie transakcji); jej atomowość pokrywa
`OrderTransactionsTest` na gałęzi `zamowienia-ddd-lite`.

### E2E w przeglądarce (kryteria 1–6 ze specu)

| Scenariusz | Wynik |
|---|---|
| koszyk u 2 sprzedawców → 2 zamówienia → płatność → wysyłka | „Sprawdzamy dostępność” → po ~1 s „Czeka na płatność” → bramka → „Czekamy na potwierdzenie płatności” → po webhooku „Opłacone”; numer przesyłki ze spacji odrzucony, `INPOST-123456789` → „Wysłane”; stany ofert 3→1 i 5→4 |
| brak towaru w trakcie (sprzedawca zmniejsza stan) | „Odrzucone: Zabrakło towaru: …”, rezerwacja drugiej pozycji `released`, jej stan bez zmian |
| zmiana ceny po dodaniu do koszyka | „Cena zmieniona z 19,99 zł na 21,99 zł”, składanie zablokowane (zamówień 5 → 5); po akceptacji złożone za 21,99 zł |
| wygaśnięcie + wpłata po terminie | sweep ~30 s po cofniętym terminie: `Expired`, rezerwacja `released`; „Zapłać mimo wygaśnięcia” → zwrot `late_payment` 21,99 zł `completed` |
| zdublowany webhook | job `DONE`, dalej 1 zwrot i 7 zdarzeń |
| sprzedawca schodzi poniżej zarezerwowanych | „Nie możesz zejść poniżej liczby zarezerwowanych sztuk”, stan bez zmian |

### Znalezione na stacku

- `catalog-scheduler` z `mem_limit: 128m`: każdy przebieg sweepu ginął z OOM
  (137), zamówienia nie wygasały. Poprawka: 256m (RUNBOOK [#048](RUNBOOK.md#048)).
- Race-test w `catalog-app` ginie po ~2 s (RUNBOOK [#049](RUNBOOK.md#049)).
- search-consumer po restartach ES: „No alive nodes” przy klastrze `green`,
  76 wiadomości w failure transport; `make reproject` przeliczył 1500
  dokumentów w 76 s. Problem istniejący wcześniej w search (RUNBOOK [#050](RUNBOOK.md#050)).
