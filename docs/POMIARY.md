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

## 12. Observability (ETAP 11, 2026-10-18)

Stack z ETAPU 11 na żywo: kontenery aplikacji z kodem z worktree (logi JSON,
`/prometheus`, `search-metrics`), profil `obs` z worktree, ES 3 węzły
(`ES_HEAP=1g`, `mem_limit` 2g), maszyna współdzielona z dwoma innymi
projektami (VM Dockera: 15,6 GiB, 10 CPU).

### Pamięć kontenerów `obs` (`docker stats --no-stream`)

„Przed” = profil `obs` już uruchomiony, aplikacje jeszcze z głównego
checkoutu (bez ruchu do metryk i logów). „Po” = po godzinie weryfikacji
(alerty, zamówienie, ślad, ILM, Stack Monitoring otwarty w Kibanie).

| Kontener | Limit | Przed | Po |
|---|---|---|---|
| grafana | 512m | 351,7 MiB | 180,1 MiB |
| prometheus | 512m | 66,8 MiB | 61,3 MiB |
| alertmanager | 64m | 49,6 MiB | 20,4 MiB |
| mailpit | 256m | 17,6 MiB | 11,3 MiB |
| filebeat | 256m | 48,6 MiB | 56,0 MiB |
| metricbeat | 256m | 51,1 MiB | 51,0 MiB |
| search-metrics | 128m | — (nie działał) | 63,3 MiB |
| **razem `obs`** | **1984 MiB** | **~585 MiB** | **~443 MiB** |

Grafana po starcie trzyma ~350 MiB i schodzi do ~180 MiB; przy 256m nie
wstawała (stąd 512m). search-metrics: 49 % limitu 128m — zapas jest, ale
nie duży.

### Alerty: od awarii do maila

Prometheus: `scrape_interval` i `evaluation_interval` 15 s; Alertmanager
`group_wait` 10 s.

| Alert | Akcja | `pending` | `firing` | mail FIRING | naprawa | stan zamknięty | mail RESOLVED |
|---|---|---|---|---|---|---|---|
| SearchConsumerDown | `docker stop` search-consumer 09:03:35 | +1:00 | **+2:01** | +2:08 | `docker start` 09:06:00 | +0:21 | +0:43 |
| OrdersStuckPlaced | `docker stop` catalog-worker 09:25:59, zamówienie 09:26:16 | +1:22 od zamówienia | **+3:26** | +3:27 | `docker start` 09:29:57 | +0:22 | +0:53 |
| SearchFailedMessages | (sam) restart es01 09:00:21 → „No alive nodes” | — | 09:01:47 | 09:02:57 | `messenger:failed:retry` | — | — |

SearchConsumerDown: okno `rate(...[1m])` + `for: 1m` = ~2 min, zgodnie
z D10. OrdersStuckPlaced: 60 s progu w gauge + `for: 2m` + scrape.

### Logi w ES

- `logs-app-*` po godzinie ruchu (od 08:50): catalog 599 dok. / 645 KB
  (primary), search 473 dok. / 505 KB. Najwięcej: access log Caddy (452,
  głównie healthcheck `/up`) i `search-metrics` (403 — log „Matched route”
  przy każdym scrape i healthchecku, co 15 s). Po pomiarze kanał `request`
  wyłączony z `stderr_json` w search (commit `4f999f3`), więc ten szum
  znika; błędy z tego kanału zostają.
- Ślad jednej zmiany ceny (`trace.id`): 9 dokumentów w 1,1 s — od logu
  kontrolera do „Zaindeksowano produkt.”.

### Stack Monitoring (Metricbeat)

- `period: 10s`: `.monitoring-es-8-mb` rosło o **~68 tys. dok. / ~66 MB na
  godzinę** (60 % to metricset `shard`); polityka `.monitoring-8-ilm-policy`
  trzyma dane do 6 dni → kilka GB.
- `period: 30s`: +4 685 dok. w 35 min (z przerwą ~8 min bez Metricbeata
  i przestojami ES) — rząd wielkości ~10 tys./h.

### ILM demo (`logs-ilmdemo`, poll 10 s)

20 dokumentów 09:24:10 → indeks `-000001` hot 09:24:16 → rollover 09:26:20
(`max_age: 2m`) → warm 09:29:19 (3 min od rolloveru) → delete planowo
~09:34, faktycznie **09:46:17** — klaster był w pętli restartów węzłów
(niżej), ILM dokończył po powrocie do `green`. `-000002` (pusty) nie robi
rolloveru: ES nie przewija pustego indeksu.

### Znalezione: ES na granicy `mem_limit`

W czasie weryfikacji węzły ES restartowały się co 5–15 min
(`RestartCount` es01 31 → 37, es02 27 → 30, es03 22 → 26 w ~1 h).
`memory.events` cgroupy es01 pokazało `oom_kill 1` przed dwoma z tych
restartów, a `memory.stat`: `anon` 1,5–1,7 GB przy limicie 2 GB (sterta 1g
+ ~260 MB non-heap + pamięć natywna), cache plików ~0. Do tego cała VM
Dockera była pełna (~15,4 z 15,6 GiB, w tym ~6,7 GiB innych projektów):
w tej samej godzinie Prometheus miał ~14 min luk w scrape **wszystkich**
celów, łącznie z samym sobą (`scrape_duration` do 141 s). Skutki widoczne
w alertach: TargetDown, OrderingSweepStale i migający DeadLetterNotEmpty
(`for: 0m` + luka w serii = RESOLVED/FIRING co kilka minut; potem
`for: 5m`, commit `10ab6c1`). Diagnoza i opcje: RUNBOOK
[#058](RUNBOOK.md#058); decyzja o pamięci ES jeszcze nie zapadła.

## 13. Śledzenie błędów (ETAP 11b, 2026-10-26)

Stack na żywo: GlitchTip 6.2.6 all-in-one z profilu `errors`, kontenery aplikacji
z kodem z gałęzi `glitchtip`, ES 3 węzły (`ES_HEAP=1g`, `mem_limit` 2g), maszyna
współdzielona z innym projektem (VM Dockera: 15,6 GiB, 10 CPU; inne projekty
~2,1 GiB). Profil `obs` nie działał.

### Pamięć i baza GlitchTipa (`docker stats --no-stream`)

| Moment | Pamięć kontenera `glitchtip` (limit 512 MiB) |
|---|---|
| po pierwszym starcie i bootstrapie | 182,7 MiB |
| po kilku przebiegach `make errors-smoke` i `errors-tunnel-check` | 196,8 MiB |
| największa zaobserwowana w sesji | 228,7 MiB |

CPU w spoczynku ~0,6 %. Baza `glitchtip` w Postgresie: **22 MB** po tych
przebiegach. Przy limicie 512m zapas jest > 2x, więc OOM wskazywałby na coś
nietypowego (RUNBOOK [#070](RUNBOOK.md#070)).

### Koszt żądania z błędem przy awarii GlitchTipa

`GET /_debug/errors/http` przez Caddy, `catalog-app` (dev, `APP_DEBUG=true`),
ciepła ścieżka (po dwóch żądaniach rozgrzewających), `curl -w %{time_total}`.
SDK: `http_connect_timeout` 0,3 s, `http_timeout` 1 s.

| Stan GlitchTipa | Żądanie z błędem |
|---|---|
| działa | 0,22–0,40 s |
| **zawieszony** (`docker pause`) | 1,26–1,34 s (+ ok. `http_timeout`) |
| zatrzymany (`docker stop`) | 0,24–0,29 s |
| (żądanie bez błędu, dla porównania) `/up` | 0,02–0,05 s |

Zatrzymany jest tani, bo nazwa przestaje się rozwiązywać (`getaddrinfo`
w kontenerze: 0,02 s), a wysyłka SDK padła w 4–39 ms. Najgorszy jest
zawieszony: połączenie przechodzi, odpowiedzi brak. `make errors-smoke` pilnuje
narzutu < 1,5 s względem bazy.

**Znalezione: pierwszy błąd po bezczynności jest wolny sam z siebie.** Pierwsze
żądanie z błędem po kilkunastu sekundach ciszy trwało 1,6–3,2 s przy
działającym GlitchTipie, a zatrzymanie *niepowiązanego* kontenera (mailpit)
przy działającym GlitchTipie dało 3,2 s. To zimna ścieżka strony błędu
(`APP_DEBUG`), niezwiązana z GlitchTipem; dlatego smoke mierzy narzut względem
ciepłej bazy, a nie bezwzględny czas. Wstępnie planowany próg 3 s dla
„zatrzymanego” GlitchTipa mierzyłby to środowisko, nie narzędzie.

### Source mapy

| | |
|---|---|
| mapy wygenerowane przez `vite build` (`sourcemap: 'hidden'`) | 98 |
| paczki z Debug ID w GlitchTipie po `make errors-sourcemaps` | 98 (`sourcecode_debugsymbolbundle`) |
| komentarz `sourceMappingURL` w bundlach | 0 |
| obraz `prod`: pliki `.map` / `.js` | **0 / 98** |
| `app-*.js` (z SDK `@sentry/vue`) | 212 kB, gzip 63,9 kB |

### Droga zdarzenia

Zdarzenie (smoke: HTTP, kolejka, search, przeglądarka) jest issue we właściwym
projekcie w mniej niż kilkanaście sekund; mail o nowym issue dochodzi do
mailpita w ramach okna pollingu smoke (60 prób co 3 s). Dokładnych czasów
przejścia nie mierzyłem.

---

## 14. G5 — RabbitMQ (operator, 3 węzły, lokalny k3d, 2026-11-10)

Wszystko przez `tools/k3d/measure.sh` (`make g5-measure m=queues|failover|prefetch`),
generator obciążenia `pivotalrabbitmq/perf-test` 2.25.0 jako Job na k3d, każdy pomiar
**3 razy**, podana mediana i rozrzut (min–max), load average z `uptime` po przebiegu.

**Konfiguracja:** k3d `elastic-g5` (server + 2 agenty, kontenery Dockera w jednej VM:
10 CPU, 15,6 GiB), RabbitMQ 4.3.4 (`rabbitmq:4.3.4-management`), 3 pody
`rabbitmq-ha-server-0..2` (po jednym na węźle, 512 Mi), perf-test z użytkownikiem
`g5-perftest` (tylko `g5.*`), wiadomości trwałe (`-f persistent`), 1 KB.

> **Uwaga o wiarygodności.** Maszyna jest współdzielona z innymi projektami, a w czasie
> pomiarów load average hosta wynosił 6–17 (1 min; 10 CPU). Liczby są **orientacyjne**
> i mają rząd wielkości, nie dokładność. Rozrzut między powtórzeniami jest duży
> (najgorszy: classic, przebieg 3, patrz niżej) i pokazuje właśnie tę niepewność.
> Jeden przebieg każdego pomiaru to 60 s (kolejki) albo kilkadziesiąt sekund (reszta).

### Quorum vs classic

`perf-test -x 2 -y 2 -s 1024 -c 200 -q 100 -z 60 -f persistent`: 2 producentów,
2 konsumentów, publisher confirms (maks. 200 niepotwierdzonych), prefetch konsumenta 100,
jedna kolejka, 60 s. Przebiegi na przemian (classic, quorum, classic, …), żeby szum
maszyny nie obciążył jednego typu. Classic to jedna kopia na jednym węźle (w RabbitMQ 4
nie ma już klasycznych kolejek zwierciadlanych); quorum to 3 repliki Raft (wg dokumentacji RabbitMQ
wiadomość jest potwierdzana po zapisie na większości replik; tu tego nie mierzyłem). „Odbiór” to `receiving rate avg` (producenci i konsumenci szły w tym
samym tempie, `sending rate avg` różnił się o < 2 %), więc kolejka nie rosła w sposób, który tłumaczyłby
opóźnienia (opieram to wyłącznie na zgodności tempa wysyłania i odbioru).

| Typ kolejki | Odbiór, msg/s (mediana, min–max) | Publikacja→odbiór p50 / p99, ms (mediany) | Publikacja→potwierdzenie p50 / p99, ms (mediany) | Load avg (1 min) |
|---|---|---|---|---|
| classic | 25 893 (1 675–27 713) | 6,5 / 26,3 | 11,4 / 39,6 | 8,71 (8,66–9,22) |
| quorum | 2 205 (2 033–2 807) | 357 / 6 018 | 21,3 / 7 030 | 8,47 (6,09–16,67) |

Surowe przebiegi (odbiór msg/s; p50/p99 publikacja→odbiór w ms; p50/p99 do potwierdzenia w ms; load 1 min):

| Przebieg | classic | quorum |
|---|---|---|
| 1 | 25 893; 6,5 / 26,3; 11,1 / 32,5; 9,22 | 2 807; 374,5 / 6 017; 16,7 / 7 030; 8,47 |
| 2 | 27 713; 6,2 / 23,5; 11,4 / 39,6; 8,71 | 2 205; 357,4 / 6 018; 23,2 / 10 011; 16,67 |
| 3 | **1 675**; 7,1 / **11 011**; 16,7 / **14 021**; 8,66 | 2 033; 265,7 / 7 039; 21,3 / 6 065; 6,09 |

Wnioski (z zastrzeżeniem o szumie):

- Quorum było tu **ok. 10–12 × wolniejsze** od classic w przepustowości (mediana 2,2 tys. vs 25,9 tys.
  msg/s). Wielosekundowe p99 (publikacja→odbiór ok. 6 s, do potwierdzenia p99 6–10 s) przy p50 do
  potwierdzenia rzędu 20 ms może wskazywać na okresowe przestoje, a nie równomierną powolność (to **hipoteza**, nie wynik).
  **Przyczyny nie zmierzyłem.** Hipotezy: fsync/WAL na współdzielonym dysku VM Dockera (3 repliki
  piszą na ten sam dysk) albo alarmy pamięci/flow control na podach 512 Mi. Potwierdziłyby to:
  `rabbitmq-diagnostics alarms` i metryki per węzeł (dysk, fsync, `rabbitmq_prometheus` na 15692)
  zbierane w trakcie przebiegu. Czy na osobnych dyskach różnica byłaby mniejsza, też nie wiem.
- Classic jest szybkie, ale ma jedną kopię: utrata węzła = utrata dostępu do kolejki (do powrotu
  węzła) i, przy awarii dysku, wiadomości. To cena za przepustowość; kolejki sklepu
  (`search.product.sync` itd.) są quorum właśnie dlatego, że zdarzenia nie mogą zniknąć.
- Przebieg 3 classic (1 675 msg/s, p99 11 s) odbiega od przebiegów 1–2 (~26–28 tys.) **z niezbadanej
  przyczyny**; load hosta (8,66) był podobny jak w tamtych (9,22 i 8,71). Mediana go odsiewa,
  rozrzut go pokazuje, ale nie wiem, czy to szum maszyny, czy cecha classic.
- Opóźnienie do potwierdzenia p50 jest zbliżone (11 vs 21 ms), rozjeżdża się dopiero na ogonie.

### Failover pod obciążeniem

Producent z confirmami (`-c 100`, 300 msg/s, 9 000 wiadomości, kolejka quorum `g5.failover`,
bez konsumentów), w trakcie zabijamy lidera. **Potwierdzone** to dolne ograniczenie z metryk
perf-test (`PERF-COUNTERS`), więc „w kolejce ≥ potwierdzone” jest uczciwym dowodem
braku utraty: gdyby potwierdzona wiadomość zniknęła, w kolejce byłoby mniej niż potwierdzonych.
Czas do nowego lidera to czas od `kubectl delete pod` / `docker stop` do pierwszego odczytu
`rabbitmqctl list_queues leader` z innego poda, który pokazuje innego lidera (odczyt co ok. 0,3 s plus exec,
rozdzielczość ~0,5 s).

| Przebieg | Zdarzenie | Lider → nowy | Czas do nowego lidera, ms | Potwierdzone (dolne ogr.) | W kolejce po teście | Utracone potwierdzone | Load avg (1 min) |
|---|---|---|---|---|---|---|---|
| 1 | kasowanie poda lidera | server-1 → server-0 | 2 981 | 9 000 | 9 000 | 0 | 7,91 |
| 2 | kasowanie poda lidera | server-2 → server-0 | 2 797 | 9 000 | 9 000 | 0 | 5,09 |
| 3 | kasowanie poda lidera | server-2 → server-1 | 2 872 | 8 997 | 8 997 | 0 | 7,33 |
| 4 | `docker stop k3d-elastic-g5-agent-1` (lider na jego podzie) | server-0 → server-2 | 1 091 | 8 994 | 8 994 | 0 | 5,92 |
| 5 | to samo | server-0 → server-2 | 1 186 | 8 997 | 9 000 | 0 | 7,33 |
| 6 | to samo | server-0 → server-1 | 1 017 | 9 000 | 9 000 | 0 | 7,6 |

Uwaga: „utracone” liczę jako max(0, potwierdzone − w kolejce), a „w kolejce” to statystyka
`messages` z `rabbitmqctl list_queues`, która potrafi się spóźniać o kilka sekund (w pomiarze
prefetch pokazała 28 800 zamiast 30 000). W przebiegach 3 i 4 w kolejce było mniej niż 9 000
(8 997 i 8 994, równo z potwierdzonymi), w przebiegu 5 w kolejce 9 000 przy potwierdzonych 8 997, więc „0” opiera się na statystyce z opóźnieniem i nie jest dowodem absolutnym.

- **Kasowanie poda lidera** (3 ×): mediana **2 872 ms**, min–max 2 797–2 981 ms.
- **Zatrzymanie węzła** (3 ×; zadanie wymagało jednego, powtórzyłem dwa razy, bo tanie): mediana
  **1 091 ms**, min–max 1 017–1 186 ms. Szybciej niż po skasowaniu poda; przyczyny nie badałem
  (hipoteza: `docker stop` zrywa połączenia od razu, a kasowany pod zamyka się łagodnie).
- **Utracone potwierdzone: 0 we wszystkich 6 przebiegach**; żaden `nacked`; producent kończył się sukcesem
  (kod 0). Wartości poniżej 9 000 (8 994–8 997) to koniec przebiegu: ostatnich kilka wiadomości nie zdążyło
  być potwierdzonych przy ostatnim odczycie metryk (co 0,25 s) i nie ma ich w kolejce; niepotwierdzona
  wiadomość nie wchodzi do zobowiązania brokera, więc to nie jest utrata.
- Węzeł `agent-1` po `docker start` wraca sam; kubelet i pod rabbitmq-ha-server-0 wstają w ciągu
  kilkudziesięciu sekund, `AllReplicasReady=True` po ~1–2 min (RUNBOOK: po `docker start` adresy IP
  węzłów k3d potrafią się przetasować i `kubectl exec` chwilę pada na certyfikacie).
- Pierwsza próba zatrzymania węzła była nieudana, ale pouczająca: generator obciążenia wylądował
  na tym samym węźle i zginął razem z nim (Job bez logów, bez liczników). Teraz `PERF_AVOID_NODE`
  pinuje Job poza zatrzymywanym węzłem.

### Prefetch

**Messenger nie ma opcji prefetch dla AMQP, więc `search-consumer` nie da się tak ustawić.**
Sprawdzone trzema niezależnymi drogami przed pomiarem:

1. `symfony/amqp-messenger` (wersja z `apps/search/vendor`): `Connection::AVAILABLE_OPTIONS` nie zawiera
   `prefetch_count`, a nieznana opcja (w DSN albo w `options:`) kończy się `LogicException: Invalid option(s)`.
   `CHANGELOG.md` pakietu: parametr `prefetch_count` oznaczono jako przestarzały („bez efektu”), a potem **usunięto**
   (wpis „Remove option `prefetch_count`” w wydaniu 6.0).
2. Transport odbiera przez `basic.get` (`Connection::get()` → `$this->queue($name)->get()`, w pętli
   `messenger:consume`), nie przez `basic.consume`. `basic.get` nie ma pojęcia prefetch.
3. Na żywym klastrze przy działającym `search-consumer`: `rabbitmqctl list_consumers` jest **puste**,
   a `list_queues name consumers` pokazuje `consumers = 0` na każdej kolejce (też `search.product.sync`).

Zgodnie z poleceniem (potwierdzić kolumną `prefetch_count`) nie ma więc czego potwierdzać
dla `search-consumer`. Zamiast tego zmierzyłem **wpływ prefetch na konsumenta AMQP w ogóle**
generatorem perf-test: kolejka quorum `g5.prefetch` wstępnie napełniona 30 000 wiadomości
(1 KB, trwałe), potem jeden konsument z ack po każdej wiadomości, `-q` 1/10/50 (`-D 30000`).
Wartość faktycznie działa: przed pomiarem `rabbitmqctl list_consumers queue_name prefetch_count`
pokazało dokładnie 1, 10 i 50.

| Prefetch konsumenta | Odbiór, msg/s (`receiving rate avg` perf-test; mediana, min–max) | Czas opróżnienia 30 000 wiadomości, s (zawiera start Joba: scheduling, czekanie na trasę, JVM, narzut ok. 5–10 s, wyliczony: 30 000 / przepustowość vs czas) | Load avg (1 min) |
|---|---|---|---|
| 1 | 576 (509–581) | 61,8 (56,5–67,0) | 9,07 (8,83–9,28) |
| 10 | 2 487 (2 260–2 696) | 20,9 (20,9–21,0) | 9,05 (8,48–11,19) |
| 50 | 7 882 (6 404–10 653) | 10,7 (10,5–10,8) | 9,68 (7,42–9,76) |

Przy prefetch 1 konsument czeka na ack i kolejne dostarczenie (przypuszczalnie zapis do większości replik plus RTT; nie mierzyłem) po każdej
wiadomości, więc przepustowość to ok. 1 / (czas jednego cyklu) ≈ 580 msg/s. Wg przepustowości (mediany)
okno 10 daje ~4,3 ×, a 50 ~13,7 ×; kolumna czasu pokazuje mniejsze ilorazy (2,9 × i 5,8 ×), bo
każdy czas zawiera stały narzut startu Joba, który **wyliczyłem, nie zmierzyłem** (30 000 / mediana
przepustowości vs zmierzony czas: ok. 5–10 s). To zgodne z teorią, ale **nie dotyczy `search-consumer`**: ten pobiera
pojedynczo przez `basic.get`.

**Stan wyjściowy `search-consumer`** (jedyne, co da się o nim zmierzyć): reindeks 1 500 zdarzeń
(`seed.sh --n=1500 --fresh`: 1 500 zdarzeń w outboxie → `outbox-publisher` → kolejka quorum
`search.product.sync` → Messenger → ES), czas od końca seed do pustej kolejki; po każdym przebiegu
`counts.sh` zbieżne (`1500 0 1500 1500 0 0`).

| Pomiar | Czas, s (mediana, min–max) | Load avg (1 min) |
|---|---|---|
| reindeks 1 500 zdarzeń (3 ×: 40,7 / 42,0 / 45,5) | 42,0 (40,7–45,5) | 9,18 (7,55–10,53) |

Wcześniejsza seria tego samego pomiaru (40,9 / 41,6 / 42,0 s) potwierdza wynik.
≈ 36 zdarzeń/s. Czas obejmuje też cykl odpytywania outboxu przez `outbox-publisher`. Wąskim gardłem
prawdopodobnie nie jest broker, tylko pętla `basic.get` + pobranie projekcji z katalogu (HTTP) +
zapis do ES w transakcji, po jednej wiadomości naraz (nie sprawdzałem tego osobno). Gdyby ten czas trzeba było skrócić, dźwignią jest równoległość konsumentów
(`replicas` search-consumer) lub przejście na `basic.consume` z prefetch (inny transport),
nie opcja Messengera.
