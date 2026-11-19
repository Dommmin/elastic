# ETAP 10 — Operacje na indeksach: plan implementacji

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `make reindex` podmienia `products-v1` na `products-v2` pod żywym strumieniem zdarzeń bez chwili przerwy i bez utraty/wskrzeszenia dokumentów, z bramką jakości; do tego `_update_by_query` z Tasks API i przećwiczony restore ze snapshotu (fs + MinIO).

**Architecture:** Dwa aliasy (`products-search` odczyt, `products-write` zapis). Kroki migracji to komendy Symfony w `apps/search` (search-service posiada indeks), orkiestracja w `tools/es/reindex.sh`, bramka łączy komendę Symfony (liczby) z `search:eval` catalogu (nDCG). Droga B wypełnia nowy indeks z catalogu po ID z nowego endpointu `internal`.

**Tech Stack:** Symfony 8 + elasticsearch-php (search), Laravel 13 + Pest (catalog), ES 9 (3 nody, lokalny Compose), bash, MinIO.

**Spec:** `docs/superpowers/specs/2026-11-19-etap-10-operacje-na-indeksach-design.md` (§8 = rozstrzygnięcia po recenzji, obowiązują).

## Global Constraints

- Gałąź `etap-10-indeksy`. Kontener catalog/search montuje **główny checkout** — pracuj w głównym checkoucie na tej gałęzi albo weryfikuj zgodnie z pamięcią „kontener montuje główny checkout”.
- ES lokalnie startuje z `ES_HEAP=512m ES_MEM_LIMIT=1536m` (maszyna współdzielona). Nie zatrzymuj kontenerów `igrit-*`, `starter-*`, `blog_*`; nie używaj cudzego MinIO.
- Zbiór danych: **300 000 produktów**; zejście do 150 000 tylko gdy seed/reindeks nie da się ukończyć — zapisać w POMIARACH.
- Mapowanie `dynamic: strict` zostaje; pola `_source` = kształt projekcji catalogu.
- Wersja dokumentu = `projected_at_us` (wersjonowanie zewnętrzne) — żadna nowa ścieżka zapisu go nie omija (wyjątek opisany: `_update_by_query`, Task 10).
- Aliasy: odczyt `products-search`, zapis `products-write` (`is_write_index: true`). Catalog czyta tylko `products-search`.
- Cele niszczące odmawiają działania na indeksie, który jest celem `products-search` lub `products-write`. Brak `--force` omijającego bramkę.
- Repo publiczne: hasła/klucze MinIO tylko w `.env`, w `.env.example` zastępcze.
- Testy: catalog `docker compose exec catalog-app php artisan test --compact <plik>`, search `docker compose exec search-consumer php bin/phpunit <plik>`; testy na prawdziwym ES w `apps/search/tests/Integration` z `#[Group('integration')]` i pomijaniem bez `ELASTICSEARCH_HOST` (wzór: `ElasticsearchIndexerVersioningTest`), na **jednorazowych** indeksach/aliasach z `uniqid()`, sprzątane w `tearDown`.
- PHP: po zmianach `vendor/bin/pint --dirty --format agent` (catalog); styl Symfony jak sąsiednie pliki (search).
- Komentarze i komunikaty po polsku, jak w reszcie repo. Commit po każdym tasku, stopka `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Ruling (plan vs spec §5.5/§8.3): „produkty `listed()`” w specu to w rzeczywistości **wszystkie istniejące produkty** — `ProductProjectionController` zwraca projekcję dla każdego istniejącego produktu (oferty filtruje `listedOffers`), 404 tylko dla nieistniejącego. Bramka i przegląd po reindeksie porównują z liczbą/ID wiersza `products`.

## Review Focus

1. Usunięcie produktu w oknie migracji (krok 3–7) — produkt nie może wrócić w nowym indeksie (Task 5 test, Task 12 weryfikacja z churn).
2. Ctrl-C w trakcie `_reindex` i ponowne `make reindex` — wznowienie bez dubli, bez ruszenia aliasu odczytu (Task 9).
3. `make reindex` uruchomione drugi raz po udanej migracji (v2 → v3) — numer wersji liczony z aliasu, nie zaszyty na sztywno (Task 7 test).
4. Przerwanie migracji między krokiem 3 a 7 (`reindex-abort`) — zapisy z okna nie giną po powrocie na `vN` (Task 7 test + Task 12).
5. Bramka na częściowym indeksie — odmowa z komunikatem, co się nie zgadza (Task 7 test, Task 9 `reindex-break`).

---

### Task 1: Seed z dużym sprzedawcą i generator ruchu (catalog)

**Files:**
- Modify: `apps/catalog/app/Console/Commands/SeedMarketplaceCommand.php`
- Create: `apps/catalog/app/Console/Commands/ChurnMarketplaceCommand.php`
- Modify: `Makefile` (`seed` przekazuje `big=`; nowy `churn`)
- Test: `apps/catalog/tests/Feature/SeedMarketplaceBigSellerTest.php`, `apps/catalog/tests/Feature/ChurnMarketplaceCommandTest.php`

**Interfaces:**
- Produces: `marketplace:seed --n=N --big-seller=M` — jeden sprzedawca (nazwa stała, np. „Megasklep”) ma ofertę w `M` produktach (`M ≤ N`); `make seed n=300000 big=100000`.
- Produces: `marketplace:churn {--rate=20 : zmian/s} {--duration=60 : s} {--delete-ratio=0.05}` — w pętli zmienia cenę/stan losowych ofert i usuwa część produktów **przez istniejące ścieżki domenowe z outboxem** (`Product::deleteWithOutbox('product.deleted')`, zmiany ofert tak jak panel), kończy się podsumowaniem `updated=… deleted=… since=<ISO>`; `make churn rate= duration=`.

- [ ] **Step 1: Testy**
  - `big seller owns exactly M products`: `marketplace:seed --n=50 --big-seller=20 --no-publish` → sprzedawca „Megasklep” ma oferty w dokładnie 20 różnych produktach; każdy produkt ma ≥1 ofertę.
  - `big seller larger than n fails`: `--n=10 --big-seller=11` → kod ≠ 0, komunikat.
  - `churn writes outbox events`: po `marketplace:churn --rate=50 --duration=1 --delete-ratio=0.5` w `outbox` są zdarzenia ofert i `product.deleted`, a usunięte produkty nie istnieją.
- [ ] **Step 2: Uruchom — FAIL**
- [ ] **Step 3: Zaimplementuj** (seed: duży sprzedawca dostaje ofertę w pierwszych `M` produktach zamiast losowania; reszta bez zmian)
- [ ] **Step 4: Uruchom — PASS; pint**
- [ ] **Step 5: Commit** `feat(catalog): seed z dużym sprzedawcą i marketplace:churn`

### Task 2: Seed 300 tys. i pomiary wstępne (operacyjny, bez kodu produkcyjnego)

**Files:**
- Modify: `docs/POMIARY.md` (szkic sekcji ETAP 10), ewentualnie `docs/RUNBOOK.md`

- [ ] **Step 1:** Stos lokalny z obniżonym ES (`ES_HEAP=512m ES_MEM_LIMIT=1536m make up …`), `make seed n=300000 big=100000`. Zmierz czas i przepustowość. Jeśli prognoza > 4 h — zatrzymaj, wróć z rulingiem o szybszej ścieżce seeda (dane w ES nadal z projekcji catalogu, spec §5.9).
- [ ] **Step 2:** Pokaż różnicę `half_float`: na kopii `v1` i jednorazowym indeksie z `rating_avg: scaled_float(100)` (te same dokumenty przez `_reindex`) porównaj `range rating_avg gte 4.7` (count) i/lub sort. Zapisz liczby. Jeśli różnicy nie da się pokazać — ruling: inna zmiana typu niewykonalna w miejscu, nie zmieniająca zapytań catalogu; dopisz powód do specu §5.2.
- [ ] **Step 3:** `make eval` dwa razy na 300 tys. → identyczny wynik i czas < kilka minut. Jeśli nie — popraw sędziów/remisy (`RelevanceJudgments`, `SearchEvalCommand::TIE_CHECK_MAX_HITS`) z testem, nie luzuj progu (spec §8.2).
- [ ] **Step 4:** Zanotuj rozmiar `products-v1` (`_cat/indices`), wolne miejsce.
- [ ] **Step 5: Commit** `docs: pomiary wstępne ETAPU 10 (seed 300 tys., half_float, eval)`

### Task 3: Dwa aliasy, wersjonowane mapowania, rozdzielony reproject (search)

**Files:**
- Modify: `apps/search/src/Service/ElasticsearchIndexer.php`, `apps/search/src/Service/IndexOutcome.php`
- Modify: `apps/search/src/Command/CreateSearchIndexCommand.php`, `apps/search/src/Command/ReprojectCommand.php`
- Create: `apps/search/src/Service/IndexAliases.php`
- Create: `infra/elasticsearch/mappings/products-v2.json`
- Test: `apps/search/tests/Integration/Command/CreateSearchIndexCommandTest.php`, `apps/search/tests/Integration/ElasticsearchIndexerVersioningTest.php` (rozszerzyć), `apps/search/tests/Unit/MappingV2Test.php`, `apps/search/tests/Integration/Command/ReprojectCommandTest.php` (dostosować)

**Interfaces:**
- Produces: `final class IndexAliases { const READ = 'products-search'; const WRITE = 'products-write'; public function __construct(Client $client, string $read = self::READ, string $write = self::WRITE); public function readTarget(): ?string; public function writeTarget(): ?string; public function swap(string $alias, string $from, string $to): void /* atomowe _aliases, is_write_index dla WRITE */; public static function versionOf(string $index): int /* 'products-v7' → 7 */ }`. Testy podają własne nazwy aliasów.
- Produces: `ElasticsearchIndexer` domyślnie `alias: IndexAliases::WRITE`; `deleteProduct` zwraca `IndexOutcome::NotFound` dla 404, `Stale` dla 409 (nowy case enuma; `ProductSyncHandler`/`ReprojectCommand` liczą go osobno).
- Produces: `search:index:create {--index-version=N}` (domyślnie stała `CURRENT_VERSION = 1`) — tworzy `products-vN` z `products-vN.json`, a jeśli aliasów brak: podpina oba do `products-vN`; jeśli `products-search` istnieje, a `products-write` nie — dopina `products-write` do tego samego indeksu (migracja istniejącej instalacji). Nigdy nie przepina istniejących aliasów (to robi Task 7).
- Produces: `search:reproject {--ids-from=products-search} {--write-to=products-write}` (zamiast jednego `--alias`).
- Produces: `products-v2.json` = `v1` + `rating_avg: {type: scaled_float, scaling_factor: 100}` (albo zmiana z rulingu Task 2).

- [ ] **Step 1: Testy**
  - `MappingV2Test`: dekodowane `v1` i `v2` (bez `_meta`) różnią się dokładnie jedną ścieżką `mappings.properties.rating_avg`, a w `v2` ma ona `scaled_float`/100.
  - `CreateSearchIndexCommandTest` (aliasy jednorazowe): świeży klaster → oba aliasy na `…-v1`, `products-write` z `is_write_index: true`; drugi przebieg idempotentny; stan „jest tylko alias odczytu” → dopięty alias zapisu na ten sam indeks.
  - `ElasticsearchIndexerVersioningTest`: usunięcie nieistniejącego → `NotFound`; usunięcie starszą wersją → `Stale`.
  - `ReprojectCommandTest`: ID czytane z `--ids-from`, zapis do `--write-to`.
- [ ] **Step 2: FAIL** · **Step 3: Implementacja** · **Step 4: PASS** (cały `bin/phpunit`)
- [ ] **Step 5:** Na żywym stosie: `search:index:create` dopina `products-write` do `products-v1`, restart `search-consumer`, `make smoke` zielone, zmiana oferty w panelu widoczna w `/search`.
- [ ] **Step 6: Commit** `feat(search): alias zapisu products-write i mapowanie products-v2`

### Task 4: Endpointy catalogu dla bramki i drogi B; `search:eval --index`

**Files:**
- Modify: `apps/catalog/routes/api.php`
- Create: `apps/catalog/app/Http/Controllers/Internal/ProductIdsController.php`
- Modify: `apps/catalog/app/Console/Commands/SearchEvalCommand.php`
- Test: `apps/catalog/tests/Feature/Internal/ProductIdsControllerTest.php`, `apps/catalog/tests/Feature/SearchEvalCommandTest.php` (istniejący lub nowy)

**Interfaces:**
- Produces: `GET /api/internal/products/count` → `{"count": int}` (wszystkie wiersze `products`).
- Produces: `GET /api/internal/products/ids?after=<int>&limit=<1..5000, domyślnie 1000>` → `{"ids": [int…], "next_after": int|null}` (keyset po `id` rosnąco; `null` = koniec).
- Produces: `search:eval {--k=10} {--index= : indeks fizyczny zamiast aliasu}` — z `--index` zapytania i `_rank_eval` idą do tego indeksu, `resolveAliasTarget()` pomijane; wypisuje nDCG jak dziś + linię maszynową `NDCG=<float>` do parsowania przez skrypt.

- [ ] **Step 1: Testy:** count = liczba produktów; `ids` stronicuje bez dubli i luk (3 strony przy `limit=2` z 5 produktami), `limit` poza zakresem → 422; eval z `--index` celuje w wskazany indeks (fake klient ES / spy jak w istniejących testach eval) i drukuje `NDCG=`.
- [ ] **Step 2–4:** FAIL → implementacja → PASS; pint
- [ ] **Step 5: Commit** `feat(catalog): internal products count/ids i search:eval --index`

### Task 5: Wyścig usunięć — test tombstone i ochrona

**Files:**
- Test: `apps/search/tests/Integration/DeleteDuringReindexTest.php`
- Ewentualnie Create: `apps/search/src/Command/SweepOrphansCommand.php` (tylko jeśli test wykaże brak tombstone)

**Interfaces:**
- Consumes: `IndexAliases`, `ElasticsearchIndexer` (Task 3); dla sweepu endpoint `/products/ids` (Task 4) — jeśli sweep jest potrzebny, metody `CatalogProjectionClient::productIds()`/`countProducts()` z Task 7 powstają tutaj, a Task 7 ich używa.
- Produces (warunkowo): `search:index:sweep {--index=}` — ID z indeksu, których catalog nie ma (`/products/ids`), usuwa z wersją z 404-projekcji; raport `checked=… removed=…`.

- [ ] **Step 1: Testy** (jednorazowe `old`/`new` i aliasy):
  - `delete of doc present in old only, then reindex, does not resurrect`: dokument w `old` z wersją V; alias zapisu → `new` z `index.gc_deletes: 1h`; `deleteProduct(id, V+1000)` przez alias zapisu (→ `NotFound`); `_reindex old→new` z `version_type: external`, `conflicts: proceed`; `_refresh`; dokumentu **nie ma** w `new`.
  - Ten sam scenariusz z `gc_deletes: 0s` + odczekanie — udokumentowanie zachowania domyślnego (oczekiwane wskrzeszenie; asercja na faktyczny wynik, z komentarzem dlaczego).
  - `delete version exceeds last projection`: dwie projekcje fałszywego catalogu (ten sam produkt, potem 404) — wersja usunięcia > wersja ostatniej projekcji.
- [ ] **Step 2:** Uruchom. Jeśli pierwszy test przechodzi — ochroną jest `gc_deletes` (Task 7 go ustawia), sweep niepotrzebny; zapisz w RUNBOOK. Jeśli nie — zaimplementuj `search:index:sweep` z testem i włącz go w Task 7 przed bramką.
- [ ] **Step 3: Commit** `test(search): wyścig usunięć podczas reindeksu (gc_deletes)`

### Task 6: Weryfikacja zbieżności po migracji (catalog)

**Files:**
- Create: `apps/catalog/app/Console/Commands/SearchVerifyConvergenceCommand.php`
- Test: `apps/catalog/tests/Feature/SearchVerifyConvergenceCommandTest.php`

**Interfaces:**
- Produces: `search:verify-convergence {--since= : ISO 8601}` — dla każdego `aggregate_id` produktu z `outbox` od `--since`: produkt istnieje → dokument w `products-search` istnieje i ma te same `price_min`, `price_max`, `in_stock`, zbiór `offers.offer_id` co świeża projekcja; nie istnieje → dokumentu brak. Wynik `checked=… mismatches=…`, lista do 20 rozjazdów, kod ≠ 0 przy rozjazdach. Uruchamiać po opróżnieniu kolejki.

- [ ] **Step 1: Testy** (fake klient ES): zgodny → 0; inna cena → mismatch z ID; usunięty produkt nadal w ES → mismatch „zombie”.
- [ ] **Step 2–4:** FAIL → implementacja → PASS; pint
- [ ] **Step 5: Commit** `feat(catalog): search:verify-convergence`

### Task 7: Komendy migracji i bramka (search)

**Files:**
- Create: `apps/search/src/Command/IndexMigrateCommand.php`, `apps/search/src/Command/IndexGateCommand.php`, `apps/search/src/Service/IndexMigration.php`
- Test: `apps/search/tests/Integration/Command/IndexMigrateCommandTest.php`, `apps/search/tests/Integration/Command/IndexGateCommandTest.php`

**Interfaces:**
- Consumes: `IndexAliases` (Task 3), `products-vN.json`, catalog `/products/count` (Task 4) przez `CatalogProjectionClient` (dodać metody `countProducts(): int`, `productIds(?int $after, int $limit): array{ids: list<int>, next_after: ?int}`).
- Produces: `search:index:migrate <step>` z opcjami `--read-alias`, `--write-alias` (testy), `--slices=auto`, `--rps=-1`, `--gc-deletes=…`:
  - `prepare` — preflight (oba aliasy na tym samym `vN`, klaster nie czerwony, `vN+1` nie istnieje lub `--resume`), tworzy `vN+1` z repliki 0, `refresh_interval: -1`, `gc_deletes` z opcji; wypisuje `TARGET=products-vN+1`.
  - `switch-write` — `products-write` → `vN+1`.
  - `reindex` — startuje `_reindex vN→vN+1` (`wait_for_completion=false`, `dest.version_type: external`, `conflicts: proceed`), wypisuje `TASK=<id>`; `--wait` śledzi `_tasks/<id>` i drukuje postęp (`created/updated/version_conflicts/total`), przy SIGINT woła `_cancel` i kończy kodem 130.
  - `finalize` — przywraca repliki i `refresh_interval` z pliku mapowania, `_refresh`, czeka na zielony.
  - `switch-read` — `products-search` → `vN+1`, potem `gc_deletes` domyślne. Wywołuje bramkę i odmawia, jeśli nie przeszła (bramka liczy, `switch-read` nie ma obejścia).
  - `abort` — oba aliasy z powrotem na `vN`, `vN+1` zostaje do diagnostyki; wypisuje `REPROJECT_NEEDED_SINCE=<ISO czasu switch-write>` (zapisany w `_meta` indeksu `vN+1` w `prepare`/`switch-write`).
  - `cleanup` — usuwa indeksy `products-v*` niebędące celem żadnego aliasu, po potwierdzeniu `--yes`.
- Produces: `search:index:gate --index=<name> [--tolerance=0.001]` — zielony, po `_refresh`; `|count(index) − catalog.count| / catalog.count ≤ tolerance`; wypisuje `GATE=PASS|FAIL` i każdą niespełnioną regułę. nDCG porównuje skrypt (Task 9), bo `search:eval` żyje w catalogu.

- [ ] **Step 1: Testy** (jednorazowe indeksy z prefiksem i aliasy; catalog fake przez `MockHttpClient`):
  - pełny przebieg `prepare → switch-write → reindex --wait → finalize → switch-read` na 2000 dokumentów: alias odczytu przez cały czas wskazuje indeks z 2000 dokumentami (sprawdzane między krokami), na końcu oba aliasy na `vN+1`, repliki przywrócone.
  - zapis przez alias zapisu po `switch-write` trafia do `vN+1` i nie jest nadpisany przez `_reindex` starszą wersją.
  - drugi przebieg z `vN+1` liczy `vN+2` (Review Focus 3).
  - `prepare` odmawia, gdy aliasy wskazują różne indeksy.
  - `reindex` przerwany (`_cancel`) i powtórzony → ta sama liczba dokumentów, brak dubli (Review Focus 2).
  - `abort` przywraca oba aliasy na `vN` i drukuje `REPROJECT_NEEDED_SINCE` (Review Focus 4).
  - gate: indeks z 30 % dokumentów → `GATE=FAIL` z regułą liczby; pełny → `PASS`; `switch-read` przy FAIL → kod ≠ 0, alias odczytu nietknięty (Review Focus 5).
- [ ] **Step 2–4:** FAIL → implementacja → PASS (cały `bin/phpunit`)
- [ ] **Step 5: Commit** `feat(search): search:index:migrate i bramka jakości`

### Task 8: Droga B — wypełnienie z catalogu (search)

**Files:**
- Create: `apps/search/src/Command/IndexBackfillCommand.php`
- Test: `apps/search/tests/Integration/Command/IndexBackfillCommandTest.php`

**Interfaces:**
- Consumes: `CatalogProjectionClient::productIds()` (Task 7), `ProductSynchronizer`, `ElasticsearchIndexer`.
- Produces: `search:index:backfill --index=<name> [--batch=1000]` — strony ID z catalogu → `sync()` każdego do wskazanego indeksu; raport `indexed/stale(409)/not_found/errors`, kod ≠ 0 przy błędach; postęp co stronę.

- [ ] **Step 1: Testy** (fake catalog, jednorazowy indeks): 3 strony ID → wszystkie dokumenty w indeksie z wersją `projected_at_us`; dokument z wyższą wersją już w indeksie → liczony jako `stale`, nie nadpisany; 404 projekcji → `not_found`, brak dokumentu.
- [ ] **Step 2–4:** FAIL → implementacja → PASS
- [ ] **Step 5: Commit** `feat(search): search:index:backfill (droga B reindeksu)`

### Task 9: `tools/es/reindex.sh` i cele Make

**Files:**
- Create: `tools/es/reindex.sh`, `tools/es/lib.sh`, `tools/es/test.sh`
- Modify: `Makefile`, `.github/workflows/prod-compose.yml` (jeśli `tools/es/test.sh` ma iść do CI tym samym mechanizmem co `tools/chaos/test.sh`)

**Interfaces:**
- Consumes: komendy Task 7/8, `search:eval --index` (Task 4).
- Produces:
  - `make reindex [source=es|pg] [slices=auto] [rps=-1]` → `prepare` → `switch-write` → (`reindex --wait` | `backfill`) → `finalize` → [`sweep`, jeśli Task 5 go dodał] → `gate` → nDCG: `search:eval --index=<stary>` i `--index=<nowy>`, FAIL jeśli nowy < stary − 0.01 → `switch-read`. Każdy krok z czasem; na końcu podsumowanie do wklejenia w POMIARY (czas każdego kroku, rozmiar obu indeksów).
  - `make reindex-abort` → `abort` + `search:reproject --ids-from=<stary> --write-to=products-write` (nadrabia okno; spec §8.1).
  - `make reindex-cleanup` → `cleanup --yes` po pytaniu w terminalu.
  - `make reindex-break` → `_reindex` z `max_docs` ≈ 30 % do `products-break-<ts>` (bez aliasów), `gate` na nim — oczekiwany `FAIL`; cel kończy się sukcesem **tylko** gdy bramka odmówiła; usuwa indeks.
  - Każdy cel niszczący: preflight odmawia, jeśli cel jest wskazywany przez któryś alias.
- Skrypt fail-closed (`set -euo pipefail`), parsuje `TARGET=`, `TASK=`, `GATE=`, `NDCG=`.

- [ ] **Step 1:** `tools/es/test.sh` (bez klastra): `bash -n`, `shellcheck` (jeśli używany w repo), parsowanie przykładowych wyjść komend (fixture) → poprawne wartości; preflight odrzuca indeks pod aliasem (funkcja testowana na stubie).
- [ ] **Step 2–4:** FAIL → implementacja → PASS
- [ ] **Step 5: Commit** `feat(tools): make reindex, reindex-abort, reindex-break, reindex-cleanup`

### Task 10: `_update_by_query` — zmiana nazwy sprzedawcy

**Files:**
- Create: `apps/catalog/app/Console/Commands/SellerRenameCommand.php` (zmiana w Postgresie)
- Create: `apps/search/src/Command/SellerRenameIndexCommand.php`
- Modify: `Makefile`
- Test: `apps/catalog/tests/Feature/SellerRenameCommandTest.php`, `apps/search/tests/Integration/Command/SellerRenameIndexCommandTest.php`

**Interfaces:**
- Produces: `php artisan marketplace:seller-rename <seller_id> <nowa_nazwa>` — tylko baza, **bez** zdarzeń w outboxie (projekcja i tak czyta nazwę na żywo).
- Produces: `search:seller:rename <seller_id> <nowa_nazwa> [--rps=500] [--no-wait]` — `_update_by_query` na `products-write`, `query: nested(offers, term offers.seller_id)` + warunek, że któraś oferta tego sprzedawcy ma inną nazwę (idempotencja/wznowienie), skrypt Painless podmienia `seller` w ofertach tego sprzedawcy, `conflicts: proceed`, `wait_for_completion=false`; wypisuje `TASK=`; bez `--no-wait` śledzi postęp.
- Produces: `make seller-rename id= name= rps=`, `make es-rethrottle task= rps=`, `make es-cancel task=`.

- [ ] **Step 1: Testy:**
  - catalog: nazwa zmieniona, `outbox` bez nowych wierszy.
  - search: 300 dokumentów, 120 z ofertą sprzedawcy → po komendzie 120 z nową nazwą, pozostałe nietknięte; ponowne uruchomienie → 0 zaktualizowanych.
  - wersja po `_update_by_query`: dokument z wersją V → po aktualizacji V+1; potem `indexProduct` z wersją V+1000 (późniejsze zdarzenie) → `Indexed`, nie `Stale`.
- [ ] **Step 2–4:** FAIL → implementacja → PASS; pint
- [ ] **Step 5: Commit** `feat: zmiana nazwy sprzedawcy przez _update_by_query`

### Task 11: Snapshoty — MinIO, repo S3, SLM, drille restore

**Files:**
- Modify: `compose.yaml` (serwis `minio` + jednorazowy `minio-init` tworzący bucket, profil z §7 specu, wolny port), `.env.example`
- Modify: `infra/elasticsearch/setup-security.sh` lub nowy `infra/elasticsearch/setup-snapshots.sh` (keystore `s3.client.default.access_key/secret_key`, `_nodes/reload_secure_settings`, repo `minio-s3` z `endpoint`, `protocol: http`, `path_style_access: true`; lokalna polityka SLM `local-nightly` z retencją)
- Create: `tools/es/restore-drill.sh`
- Modify: `Makefile`, `docs/07-WERSJE.md` (wersja obrazu MinIO, przypięta)

**Interfaces:**
- Produces: `make es-snapshots-setup`, `make es-restore-drill repo=local-fs|minio-s3` (snapshot indeksu spod `products-search` → restore z `rename_pattern` do `products-restored-<ts>` → porównanie liczby i próbki 100 `_source` → usunięcie kopii), `make es-restore-drill-destructive repo=…` (kopia przez `_clone`/`_reindex` do `products-drill-<ts>` → snapshot → **usunięcie** tej kopii → restore pod tą samą nazwą → porównanie; preflight odmawia, gdy nazwa jest celem aliasu), `make es-slm-run` (`_execute` + czekanie na wynik).
- Hasła/klucze tylko z `.env`.

- [ ] **Step 1:** Dodaj test bez klastra do `tools/es/test.sh`: `docker compose config` z profilem przechodzi; drill odmawia indeksu pod aliasem (stub).
- [ ] **Step 2:** Implementacja; `docker compose --profile <profil> up -d minio minio-init` nie koliduje z portami igrit.
- [ ] **Step 3:** Na żywo: oba drille dla obu repo, `make es-slm-run` → snapshot `SUCCESS`; zanotuj czasy.
- [ ] **Step 4: Commit** `feat: MinIO, repo S3, SLM lokalnie i drille restore`

### Task 12: Weryfikacja na żywym stacku (operacyjny)

- [ ] **Step 1:** `make churn rate=20 duration=<dłużej niż reindeks>` w tle; zanotuj `since`. `make reindex` (droga A, v1→v2). W trakcie: `curl` na `/search` w pętli co 1 s — **żadnej** odpowiedzi pustej/błędnej (logować liczbę trafień).
- [ ] **Step 2:** Po churn i opróżnieniu kolejki: `search:verify-convergence --since=…` → `mismatches=0`. Porównanie `range rating_avg gte 4.7` v1 vs v2 (dowód sensu zmiany).
- [ ] **Step 3:** `make reindex source=pg` (v2→v3) z churn → to samo; czas B vs A.
- [ ] **Step 4:** Przerwanie: `make reindex` → Ctrl-C w `_reindex` → ponowne uruchomienie → sukces. Druga próba: po `switch-write` → `make reindex-abort` → churn → `verify-convergence` = 0.
- [ ] **Step 5:** `make reindex-break` → bramka odmawia, alias nietknięty.
- [ ] **Step 6:** `make seller-rename` dla „Megasklep” z `rps=200`: obserwacja w `make es-tasks`, `es-rethrottle` w trakcie, `es-cancel` w połowie, wznowienie → 0 dokumentów ze starą nazwą.
- [ ] **Step 7:** Wpisy RUNBOOK dla każdej awarii po drodze (429, circuit breaker, timeouty, cokolwiek) — w trakcie, nie na końcu.

### Task 13: Dokumentacja

**Files:**
- Create: `docs/blog/etap-10-operacje-na-indeksach.md`
- Modify: `docs/RUNBOOK.md` (od pierwszego wolnego numeru, z indeksem), `docs/POMIARY.md` (sekcja ETAP 10: seed, czasy A/B, rozmiar dodatkowy, `slices`/`rps`, update_by_query, restore), `README.md` (wiersz etapu 10 + `make reindex` w skrótach), `docs/06-PLAN-WDROZENIA.md` (status), `docs/01-INFRASTRUKTURA.md` jeśli opisuje `make reindex` inaczej

- [ ] **Step 1:** Przewodnik krok po kroku (co, jak, dlaczego, co wybuchło): dwa aliasy i kolejność wdrożenia, przebieg migracji z liczbami, pułapka `gc_deletes`, bramka i `reindex-break`, droga powrotu (spec §8.1), A vs B, `_update_by_query` i wersje, snapshoty fs/MinIO, SLM.
- [ ] **Step 2:** Sprawdź, że każda liczba w przewodniku i README ma źródło w POMIARACH i że hipotezy nie są podane jako fakty.
- [ ] **Step 3: Commit** `docs: przewodnik ETAPU 10, RUNBOOK, POMIARY`
