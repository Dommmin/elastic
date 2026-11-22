# ETAP 14 — Semantyka i ML (laboratorium) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Węzeł laboratoryjny `es-lab` (trial + ML) z próbką ~20 tys. produktów, na którym `search:eval:lab` liczy powtarzalną tabelę nDCG@10 dla 6 wariantów wyszukiwania × 3 grup zapytań, plus pomiary kosztu wektorów, demo filtra kNN, ELSER i wykrywanie anomalii.

**Architecture:** Jednowęzłowy ES w profilu Compose `ml` (ten sam obraz co produkcja, osobny wolumen, trial). Dane eksportowane raz z głównego klastra i bazy katalogu do NDJSON (`apps/catalog/storage/app/private/lab/`), ładowane przez `setup-lab.sh`; wektory liczy sam ES (inference endpoint E5 / ELSER). Ewaluacja w catalog: własny licznik nDCG (sprawdzany krzyżowo z `_rank_eval`), oceny z `RelevanceJudgments` przecięte z próbką.

**Tech Stack:** Elasticsearch 9.5.1 (trial na `es-lab`), `.multilingual-e5-small`, ELSER v2, ES ML anomaly detection, Laravel 13 / Pest (apps/catalog), bash + curl + jq, Docker Compose.

**Spec:** `docs/superpowers/specs/2026-11-22-etap-14-semantyka-ml-design.md` (§10 R1–R5 to wiążące rozstrzygnięcia).

## Global Constraints

- Repo `Dommmin/elastic` jest **publiczne**: zero haseł/tokenów/kluczy w commitach. `ES_LAB_PASSWORD` tylko w `.env`; w `.env.example` pusty placeholder `ES_LAB_PASSWORD=`.
- Główny klaster, `products-*`, aliasy, pipeline'y sklepu, `compose.prod.yaml`, `deploy/k8s` — **bez zmian**. Główny klaster tylko do odczytu (eksport).
- Nie zatrzymywać kontenerów innych projektów (igrit-*, starter-*, blog_*). Start `es-lab` odmawia, gdy zapas pamięci Dockera < 5 GB (wzór: `tools/seller360/fls-demo.sh`, sekcja „Kontrola pamięci”).
- `es-lab`: `profiles: [ml]`, obraz `marketplace/elasticsearch:${ES_VERSION}`, `discovery.type=single-node`, heap 1 GB, `mem_limit: 4g`, wolumen `es-lab-data`, port `127.0.0.1:${ES_LAB_PORT:-19201}:9200`, security włączone, TLS HTTP wyłączony.
- Inference endpointy: `lab-e5` (`.multilingual-e5-small`), `lab-elser` (ELSER v2). Indeks: `products-lab-v1`, 1 shard, 0 replik, `force_merge` do 1 segmentu po załadowaniu.
- Próbka: produkty z `id % 58 = 0` (≈20 tys. z 1,17 mln; M stałe, nie strojone pod zapytania — R2).
- Tekst dla modeli: `"{name}. {kategoria liścia}, {kategoria korzeń}"`, bez opisu.
- nDCG@10 z gain `2^g − 1`, zgodne z `_rank_eval` `dcg {k, normalize: true}` (używa go `SearchEvalCommand`); własny licznik = `_rank_eval` z dokładnością 1e-6.
- RRF: `rank_constant = 60`, `rank_window_size = 100`; kNN: `k = 10`, `num_candidates = 100`.
- Grupy zapytań: `lexical`, `descriptive`, `exact`. Warianty: `bm25`, `knn`, `hybrid-bool`, `rrf-client`, `rrf-native`, `elser` (14b) + diagnostyczny `knn-exact` (poza tabelą główną).
- Główna tabela używa pola `semantic_text` (E5); jawne `dense_vector` (`hnsw` float / `int8_hnsw` / `bbq_hnsw`) tylko w eksperymencie kosztu (R1).
- Dokumentacja po polsku; przewodniki krok po kroku w `docs/blog/` (definicja „etap skończony”); POMIARY §18; RUNBOOK od #152.
- Commity kończą się `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. Węzeł lab nie wstał / trial wygasł (po 30 dniach) — `search:eval:lab` i `setup-lab.sh` mają powiedzieć to jednym zdaniem (co zrobić: `make lab-up` / `make lab-reset`), nie rzucać stack trace'em. Test w Task 5.
2. Brak pliku eksportu albo plik z innego M niż w kodzie — `setup-lab.sh` / ewaluacja odmawia z jasnym komunikatem, zamiast ładować niepełną próbkę. Test w Task 3.
3. Zapytanie z pustą oceną po przecięciu z próbką — przerwanie z nazwą zapytania (nie ciche zaniżenie nDCG). Test w Task 4.
4. Inference endpoint jeszcze się ładuje (model pobierany przy pierwszym użyciu, 408/timeout) — setup czeka z limitem i komunikatem, nie zostawia indeksu pół-załadowanego. Krok w Task 2.
5. Ponowne `make lab-setup` na istniejącym wolumenie — idempotentne: nie duplikuje dokumentów (stałe `_id` = id produktu), nie tworzy endpointów drugi raz. Test w Task 3.

---

## Struktura plików

- `compose.yaml` — usługa `es-lab`, wolumen `es-lab-data`, zmienne lab dla `catalog-app`.
- `.env.example` — `ES_LAB_PASSWORD=`, `ES_LAB_PORT=19201`.
- `tools/lab/lab-up.sh` — kontrola pamięci + `docker compose --profile ml up -d es-lab` + czekanie na zdrowie.
- `tools/lab/lib.sh` — wspólne `lab_es()` (curl z hasłem przez `-K`, jak `fls-demo.sh`), `die`, `step`.
- `infra/elasticsearch/lab/setup-lab.sh` — trial, endpointy, indeks, pipeline, ładowanie, force_merge.
- `infra/elasticsearch/lab/products-lab-v1.json` — mapowanie (analiza z `products-v6.json`, bez `default_pipeline`).
- `infra/elasticsearch/lab/lab-embed-pipeline.json` — pipeline z procesorem `inference` do jawnych `dense_vector`.
- `apps/catalog/app/Console/Commands/LabExportCommand.php` — `search:lab:export`.
- `apps/catalog/app/Services/Search/Lab/{LabVariant, LabQueryBuilder, Ndcg, Rrf, LabJudgments, LabEvalRunner}.php`.
- `apps/catalog/app/Console/Commands/SearchEvalLabCommand.php` — `search:eval:lab`.
- `tests/relevance/semantic-queries.yaml` — zapytania z `group`.
- `tools/lab/costs.sh`, `tools/lab/filter-demo.sh` (14a); `tools/lab/export-events.sh`, `tools/lab/anomaly.sh`, `tools/lab/inject-outage.sh` (14b).
- `docs/blog/etap-14a-wektory-i-trafnosc.md`, `docs/blog/etap-14b-elser-i-anomalie.md`, `docs/POMIARY.md` §18, `docs/RUNBOOK.md` #152+, `docs/06-PLAN-WDROZENIA.md`.

---

## 14a

### Task 1: Węzeł `es-lab` w Compose

**Files:**
- Modify: `compose.yaml` (nowa usługa obok `es01..es03`; wolumen w sekcji `volumes`; w `catalog-app.environment`: `ELASTICSEARCH_LAB_HOST: http://es-lab:9200`, `ELASTICSEARCH_LAB_PASSWORD: ${ES_LAB_PASSWORD:-}`)
- Modify: `.env.example`, `.gitignore` (usuń linię `.lab-data/` — dane lab żyją w `apps/catalog/storage/app/private/lab/`, już ignorowanym; sprawdź `git check-ignore`)
- Create: `tools/lab/lib.sh`, `tools/lab/lab-up.sh`
- Modify: `Makefile` (cele `lab-up`, `lab-down` = `docker compose --profile ml stop es-lab`, `lab-reset` = stop + `rm` + usunięcie wolumenu `es-lab-data` z potwierdzeniem `CONFIRM=1`)
- Modify: `docs/superpowers/specs/2026-11-22-etap-14-semantyka-ml-design.md` R5: ścieżka danych = `apps/catalog/storage/app/private/lab/`

**Interfaces:**
- Produces: host `http://127.0.0.1:${ES_LAB_PORT}` (z hosta) i `http://es-lab:9200` (z sieci `marketplace`); `tools/lab/lib.sh` z `lab_es <curl-args…>` (user `elastic`, hasło z `ES_LAB_PASSWORD` wczytanego z `.env`), `step`, `die`, `need_free_mb <MB>`.

- [ ] **Step 1:** `es-lab` nie dziedziczy `x-es-common` (tamten ma `discovery.seed_hosts` i TLS transportowy wielowęzłowy). Własny blok: environment `discovery.type: single-node`, `ELASTIC_PASSWORD: ${ES_LAB_PASSWORD:?ES_LAB_PASSWORD musi być ustawione dla profilu ml}`, `ES_JAVA_OPTS: -Xms1g -Xmx1g`, `xpack.security.enabled: "true"`, `xpack.security.http.ssl.enabled: "false"`, `xpack.security.transport.ssl.enabled: "false"`, `xpack.ml.enabled: "true"`, `cluster.name: marketplace-lab`; healthcheck jak w `x-es-common` z `${ES_LAB_PASSWORD}`; `mem_limit: 4g`; `restart: "no"`.
- [ ] **Step 2:** Sprawdź, że profil domyślny się nie zmienił: `docker compose config --services | grep -c es-lab` → `0`; `docker compose --profile ml config --services | grep es-lab` → `es-lab`. Bez `ES_LAB_PASSWORD` `docker compose --profile ml config` kończy się komunikatem z `:?`.
- [ ] **Step 3:** `lab-up.sh`: `need_free_mb 5120` (ten sam sposób liczenia co `fls-demo.sh`), `docker compose --profile ml up -d es-lab`, czekanie do 180 s na `_cluster/health` yellow/green; na końcu wypisz licencję (`_license.type`).
- [ ] **Step 4:** `shellcheck tools/lab/*.sh` czysto. Ustaw lokalnie `ES_LAB_PASSWORD` w `.env` (wygeneruj `openssl rand -hex 16`, nie wypisuj w czacie), `make lab-up` → health green, `_license.type = basic`.
- [ ] **Step 5:** Commit `feat(lab): węzeł es-lab w profilu ml`.

### Task 2: Trial, inference E5, indeks laboratoryjny

**Files:**
- Create: `infra/elasticsearch/lab/setup-lab.sh`, `infra/elasticsearch/lab/products-lab-v1.json`, `infra/elasticsearch/lab/lab-embed-pipeline.json`
- Modify: `Makefile` (`lab-setup: bash infra/elasticsearch/lab/setup-lab.sh`)

**Interfaces:**
- Consumes: `tools/lab/lib.sh` (Task 1).
- Produces: endpoint `lab-e5`; indeks `products-lab-v1` z polami: `name` (jak v6: `pl_index`/`pl_search`, `.raw`, `.ac`), `description`, `brand` (keyword), `category.{id,path}` (keyword), `category_leaf`, `category_root` (keyword), `in_stock` (boolean), `price_min` (scaled_float 100), `lab_text` (text, `copy_to: [semantic_e5]`), `semantic_e5` (`semantic_text`, `inference_id: lab-e5`), `vec_float` / `vec_int8` / `vec_bbq` (`dense_vector`, dims 384, cosine, `index_options.type` odpowiednio `hnsw` / `int8_hnsw` / `bbq_hnsw`); pipeline `lab-embed` wypełniający trzy `vec_*` z `lab_text` przez endpoint `lab-e5` (jeden procesor inference, potem `set`/`script` kopiujący wektor do pozostałych dwóch pól).

- [ ] **Step 1:** Mapowanie: `dynamic: strict`, `settings`: `number_of_shards: 1`, `number_of_replicas: 0`, `index.default_pipeline: lab-embed`, sekcja `analysis` skopiowana 1:1 z `products-v6.json`.
- [ ] **Step 2:** `setup-lab.sh` faza „schema” (idempotentna, każdy krok najpierw sprawdza stan): `start_trial?acknowledge=true` tylko gdy `_license.type=basic`; `PUT _inference/text_embedding/lab-e5` (`service: elasticsearch`, `model_id: .multilingual-e5-small`, `num_allocations: 1`, `num_threads: 1`) tylko gdy GET zwraca 404; czekanie na gotowość wdrożenia (`_ml/trained_models/.multilingual-e5-small/_stats` → `deployment_stats.state = started`) z limitem 15 min i komunikatem „model jeszcze się pobiera/ładuje”; pipeline (PUT zawsze — idempotentne); indeks tylko gdy nie istnieje.
- [ ] **Step 3:** Sprawdź w dokumentacji 9.5 i zanotuj w komentarzu pipeline'u, czy endpoint E5 sam dokleja prefiksy `passage: ` / `query: ` dla procesora inference i dla `query_vector_builder`. Jeśli nie — pipeline dokleja `passage: ` do wejścia, a zapytania na `vec_*` (Task 6) dostają `query: `. `semantic_text` obsługuje to sam (R1).
- [ ] **Step 4:** Weryfikacja na żywo: `make lab-setup` dwa razy pod rząd — drugi przebieg bez błędów i bez zmian; `GET products-lab-v1/_mapping` pokazuje `semantic_e5`; `POST _inference/text_embedding/lab-e5 {"input":"kurtka zimowa"}` zwraca 384 liczby; zanotuj, jakie `index_options` ES nadał polu `semantic_e5` (do przewodnika).
- [ ] **Step 5:** Commit `feat(lab): trial, endpoint E5 i indeks products-lab-v1`.

### Task 3: Eksport próbki i ładowanie

**Files:**
- Create: `apps/catalog/app/Console/Commands/LabExportCommand.php`
- Test: `apps/catalog/tests/Feature/Search/LabExportCommandTest.php`
- Modify: `infra/elasticsearch/lab/setup-lab.sh` (faza „load”), `Makefile` (`lab-export: $(DC) exec catalog-app php artisan search:lab:export`)

**Interfaces:**
- Produces: `search:lab:export {--modulo=58} {--out=lab/products.ndjson}` (ścieżka względem dysku `local` = `storage/app/private`); plik NDJSON, linia = `{"id": "...", "name", "description", "brand", "category": {"id","path"}, "category_leaf", "category_root", "in_stock", "price_min", "lab_text"}`; pierwsza linia nagłówek `{"_lab_export": {"modulo": 58, "count": N, "exported_at": "..."}}`.
- Stała `LabExportCommand::MODULO = 58` — jedyne źródło M; ewaluacja (Task 4) czyta je stąd.

- [ ] **Step 1: Testy (Pest, sztuczny transport ES jak w `tests/Feature/SearchEvalCommandTest.php`, baza z fabryk):**
  - `exports only products with id divisible by modulo` — 6 produktów o id 58, 59, 116, 117, 174, 175 → w pliku dokładnie 58, 116, 174.
  - `builds lab_text from name and category names` — produkt „Kurtka zimowa Alpha” w kategorii Kurtki (korzeń Odzież) → `lab_text = "Kurtka zimowa Alpha. Kurtki, Odzież"`.
  - `takes in_stock and price_min from the search index, not the database` — sztuczny `_mget` zwraca `in_stock:false` → w pliku `false`.
  - `fails loudly when a sampled product is missing from the index` — `_mget` z `found:false` → exit 1, komunikat z id.
  - `writes the header line with modulo and count`.
- [ ] **Step 2:** `php artisan test --filter=LabExportCommandTest` → FAIL (brak komendy).
- [ ] **Step 3:** Implementacja: id z bazy (`id % M = 0`, `orderBy id`, porcje po 1000), `_mget` na `products-search` klientem `Client::class` (rola `catalog` ma odczyt), nazwy kategorii z bazy (liść + korzeń przez `parent_id`), zapis strumieniowy.
- [ ] **Step 4:** Testy → PASS.
- [ ] **Step 5:** `setup-lab.sh` faza „load”: odmowa, gdy brak pliku `apps/catalog/storage/app/private/lab/products.ndjson` albo nagłówek ma `modulo` ≠ 58 (komunikat: uruchom `make lab-export`); `_bulk` z `_id = id` w porcjach po 500, `?refresh=false`, z mierzeniem czasu całkowitego (wypisz `load_seconds`); potem `_refresh`, `_forcemerge?max_num_segments=1`, kontrola `_count == count z nagłówka`.
- [ ] **Step 6:** Na żywo: `make lab-export` (zanotuj czas i liczbę), `make lab-setup` → `_count` = nagłówek; drugi `make lab-setup` → ta sama liczba (stałe `_id`); `GET products-lab-v1/_segments` → 1 segment. Zanotuj, ile dokumentów próbki ma `in_stock=false` (potrzebne w Task 6).
- [ ] **Step 7:** Commit `feat(lab): eksport próbki i ładowanie do products-lab-v1`.

### Task 4: Oceny laboratoryjne i licznik nDCG

**Files:**
- Create: `apps/catalog/app/Services/Search/Lab/Ndcg.php`, `apps/catalog/app/Services/Search/Lab/LabJudgments.php`, `tests/relevance/semantic-queries.yaml`
- Test: `apps/catalog/tests/Unit/Search/Lab/NdcgTest.php`, `apps/catalog/tests/Feature/Search/LabJudgmentsTest.php`

**Interfaces:**
- Consumes: `RelevanceJudgments::resolve(array $rules): array<string,int>`, `LabExportCommand::MODULO`.
- Produces: `Ndcg::atK(array $rankedIds, array $ratings, int $k): float` (`$ratings` = `id => grade`); `LabJudgments::forQuery(array $rules): array<string,int>` (reguły → oceny przecięte z próbką `id % MODULO = 0`; pusty wynik → `InvalidArgumentException` z treścią zapytania); `LabJudgments::load(string $path): array<int, array{query:string, group:string, ratings:array<string,int>}>`.

- [ ] **Step 1: Testy `Ndcg`:**
  - idealna kolejność → `1.0`;
  - `rankedIds=['a','b']`, `ratings=['b'=>3,'a'=>0]`, k=10 → `(7/log2(3)) / 7` ≈ `0.6309297536`;
  - dokument bez oceny liczy się jak 0;
  - oceniony dokument spoza wyników obniża wynik (ideał liczony ze **wszystkich** ocen) — to założenie sprawdza krzyżowo Task 5 na żywym `_rank_eval`; jeśli tam wyjdzie różnica, poprawić tu i w teście;
  - brak ocen > 0 → `0.0`, nie dzielenie przez zero.
- [ ] **Step 2: Testy `LabJudgments`:** reguła `category: Kurtki` na bazie z produktami 58 (Kurtki), 59 (Kurtki), 116 (Laptopy) → `['58' => g]`; zapytanie, którego oceny po przecięciu są puste → wyjątek z treścią zapytania; plik z nieznaną grupą → wyjątek; każda pozycja pliku ma `group` ∈ {lexical, descriptive, exact}.
- [ ] **Step 3:** FAIL → implementacja → PASS (`php artisan test --filter='NdcgTest|LabJudgmentsTest'`).
- [ ] **Step 4:** `semantic-queries.yaml` (nagłówek-komentarz jak w `queries.yaml`: skala 0–3, zasada remisów, „ten plik jest dla próbki M=58”):
  - `lexical`: wszystkie 13 zapytań z `queries.yaml` z ich regułami, poza tymi, których oceny po przecięciu są puste (wtedy zamień na zapytanie tego samego typu — nazwę/markę z próbki).
  - `descriptive` (12): „coś ciepłego na zimę” (name_contains `Kurtka zimowa` 3, category `Kurtki` 1), „oświetlenie do ogrodu bez prądu” (`Lampa ogrodowa solarna` 3, `Oświetlenie` 1), „ćwiczenia w domu” (`Fitness` 2), „jazda po górach” (`Rower górski` 3, `Rowery` 1), „muzyka w podróży” (`Słuchawki` 2), „prezent dla biegacza” (`Buty do biegania` 2, `Koszulka termoaktywna` 1), „ochrona przed deszczem” (`Kurtka przeciwdeszczowa` 3, `Kurtki` 1), „do pracy w podróży” (`Laptopy` 2), „naprawy w domu” (`Narzędzia` 2), „odpoczynek na tarasie” (`Leżak ogrodowy` 3, `Meble ogrodowe` 1), „dojazd do pracy bez wysiłku” (`Rower elektryczny` 3, `Rowery` 1), „telefon z dobrym aparatem” (category `Smartfony` 1 — w danych nie ma informacji o aparacie; celowo trudny przypadek dla obu podejść).
  - `exact` (8): po wyeksportowaniu próbki wybierz z niej: 3 pełne nazwy z marką i modelem (`name` 3), 2 same marki (`brand` 1), 2 „model + wariant” (np. `Nova 256GB` → `name_contains` 3), 1 nazwa z literówką w marce (oceny jak dla poprawnej nazwy).
  - Każda grupa remisu w top-10 BM25 ma jedną ocenę (zasada z `queries.yaml`); sprawdzi to `TieGroupGuard` w Task 5.
- [ ] **Step 5:** Commit `feat(lab): oceny laboratoryjne, licznik nDCG i zapytania semantyczne`.

### Task 5: Warianty i `search:eval:lab`

**Files:**
- Create: `apps/catalog/app/Services/Search/Lab/{LabVariant.php, LabQueryBuilder.php, Rrf.php, LabEvalRunner.php}`, `apps/catalog/app/Console/Commands/SearchEvalLabCommand.php`
- Modify: `apps/catalog/config/services.php` (`elasticsearch_lab` => host `env('ELASTICSEARCH_LAB_HOST','http://es-lab:9200')`, user `elastic`, password `env('ELASTICSEARCH_LAB_PASSWORD')`), `apps/catalog/app/Providers/AppServiceProvider.php` (singleton `es.lab`, wzór `es.seller_view`; wstrzykiwany tylko do `LabEvalRunner`), `Makefile` (`lab-eval: $(DC) exec catalog-app php artisan search:eval:lab $(args)`)
- Test: `apps/catalog/tests/Unit/Search/Lab/{RrfTest.php, LabQueryBuilderTest.php}`, `apps/catalog/tests/Feature/Search/SearchEvalLabCommandTest.php`

**Interfaces:**
- Consumes: `Ndcg`, `LabJudgments` (Task 4), `ProductSearchService::buildTextQuery(string): array`, `TieGroupGuard`.
- Produces:
  - `enum LabVariant: string { Bm25='bm25'; Knn='knn'; HybridBool='hybrid-bool'; RrfClient='rrf-client'; RrfNative='rrf-native'; Elser='elser'; KnnExact='knn-exact'; }` z `isQueryExpressible(): bool` (true dla bm25, knn, hybrid-bool, elser) i `requiresTrial(): bool`.
  - `LabQueryBuilder::body(LabVariant $v, string $q, int $size = 10): array` — pełne ciało `_search` (`query` albo `retriever`). `bm25`: `buildTextQuery($q)`; `knn`: `{"knn": {"field":"semantic_e5", ...}}` — dla `semantic_text` użyj zapytania `semantic` / `knn` z `query_vector_builder` zgodnie z dokumentacją 9.5 (`k=10`, `num_candidates=100`); `hybrid-bool`: `bool.should` [bm25, knn]; `rrf-native`: retriever `rrf` [standard(bm25), knn] z `rank_constant 60`, `rank_window_size 100`; `knn-exact`: `script_score` z `cosineSimilarity` po `vec_float` i wektorze zapytania liczonym przez `_inference/text_embedding/lab-e5`.
  - `Rrf::fuse(array $rankings, int $rankConstant = 60, int $window = 100): array` — `$rankings` = lista list id w kolejności; wynik: id posortowane malejąco po Σ `1/(rankConstant + rank)` (rank od 1), remis → mniejsze id jako string, potem kolejność pierwszego wystąpienia.
  - `LabEvalRunner::run(array $variants, array $queries, int $k): array` — `['variant' => ['group' => avgNdcg], 'per_query' => [...]]`.
  - `search:eval:lab {--variant=*} {--group=*} {--k=10} {--json=} {--check-rank-eval} {--latency=0} {--recall}`.

- [ ] **Step 1: Testy `Rrf`:** dwie listy `[a,b,c]`, `[c,a,d]` → kolejność `a, c, b, d` (a: 1/61+1/62, c: 1/63+1/61, b: 1/62, d: 1/63); dokument poza oknem `window` się nie liczy; pusta lista → pusty wynik.
- [ ] **Step 2: Testy `LabQueryBuilder`:** `bm25` zawiera dokładnie `buildTextQuery`; `knn` ma `k=10`, `num_candidates=100`, pole `semantic_e5`; `rrf-native` ma `rank_constant=60`, `rank_window_size=100` i dwa retrievery; `hybrid-bool` ma oba człony w `should`.
- [ ] **Step 3: Testy komendy (sztuczny transport):**
  - `prints a variant by group table` — dwa warianty, dwie grupy → 4 liczby z 3 miejscami po przecinku.
  - `rrf-client asks ES for bm25 and knn and fuses in PHP` — log żądań: dwa `_search`, wynik zgodny z `Rrf::fuse`.
  - `refuses rrf-native on a basic license with one sentence` — ES zwraca 403 `license.expired.feature` → exit 1, komunikat zawiera `make lab-setup` (trial) i nie zawiera stack trace'u.
  - `says the lab node is down` — wyjątek połączenia → exit 1, komunikat zawiera `make lab-up`.
  - `--check-rank-eval fails when PHP and _rank_eval differ by more than 1e-6` — sztuczny `_rank_eval` zwraca 0.5, PHP liczy 0.6 → exit 1 z nazwą wariantu i zapytania.
  - `--json writes the same numbers as the table`.
- [ ] **Step 4:** FAIL → implementacja → PASS (`php artisan test --filter='Rrf|LabQueryBuilder|SearchEvalLab'`).
- [ ] **Step 5: Na żywo (es-lab po Task 3):**
  - `make lab-eval args="--variant=bm25 --variant=knn --variant=hybrid-bool --variant=rrf-client --variant=rrf-native --check-rank-eval"` → tabela 5×3, kontrola `_rank_eval` zielona (gdy czerwona na założeniu ideału z Task 4 — popraw `Ndcg` i jego test).
  - Dwa przebiegi pod rząd → identyczne liczby (anty-cel: powtarzalność co do cyfry).
  - `rrf-client` vs `rrf-native`: dodaj do komendy kontrolę (`--check-rrf`) porównującą top-10 obu; różnica dopuszczalna tylko wewnątrz grup remisu (ten sam wynik RRF) — zielona.
  - `--recall`: recall@10 `knn` (HNSW na `vec_float`) względem `knn-exact` na wszystkich zapytaniach — wypisz średnią.
  - `--latency=50`: p50/p99 `took` i czasu całkowitego po stronie PHP dla każdego wariantu (po 5 rozgrzewkowych).
  - `TieGroupGuard` na `bm25` (jak w `search:eval`) — zero grup remisu z różnymi ocenami; inaczej popraw reguły w `semantic-queries.yaml`.
- [ ] **Step 6:** Zapisz wynik `--json=lab/eval-14a.json`. Commit `feat(lab): search:eval:lab — pięć wariantów, kontrola _rank_eval i RRF`.

### Task 6: Koszt wektorów i demo filtra

**Files:**
- Create: `tools/lab/costs.sh`, `tools/lab/filter-demo.sh`
- Modify: `Makefile` (`lab-costs`, `lab-filter-demo`), `apps/catalog/app/Services/Search/Lab/LabQueryBuilder.php` + test (wariant `knn` z parametrem pola: `knnOn(string $field, string $q): array` dla `vec_float|vec_int8|vec_bbq`)

**Interfaces:**
- Consumes: `products-lab-v1`, `lab-embed`, `search:eval:lab`.
- Produces: `search:eval:lab --vector-field=vec_int8` (wariant `knn` na wskazanym polu jawnym); wyniki skryptów w formacie `klucz: wartość`.

- [ ] **Step 1:** `costs.sh`: `POST products-lab-v1/_disk_usage?run_expensive_tasks=true` → rozmiar na pole (`semantic_e5`, `vec_float`, `vec_int8`, `vec_bbq`, `name`, cały indeks); `_nodes/stats/indices/segments` → `dense_vector`/knn pamięć, jeśli 9.5 ją raportuje (zanotuj, co raportuje); czas ładowania: utwórz tymczasowy `products-lab-novec` (to samo mapowanie bez pól wektorowych, bez pipeline'u), załaduj ten sam plik, porównaj `load_seconds` z Task 3; potem usuń tymczasowy indeks. Skrypt kończy się tabelą i ekstrapolacją liniową na 1 171 554 dokumentów (rachunek, oznaczony jako rachunek).
- [ ] **Step 2:** `make lab-eval args="--variant=knn --vector-field=vec_float"`, to samo dla `vec_int8`, `vec_bbq` → nDCG per grupa (do POMIARÓW razem z rozmiarami).
- [ ] **Step 3:** `filter-demo.sh` (wymaga ≥ 1 dokumentu `in_stock=false` w próbce — jeśli Task 3 pokazał 0, skrypt oznacza deterministyczny podzbiór `id % 3 = 0` jako `in_stock=false` przez `_update_by_query` **tylko w `products-lab-v1`** i mówi o tym w wyjściu): dla „coś ciepłego na zimę” trzy zapytania — (a) knn bez filtra, (b) knn z `filter: {term: {in_stock: true}}` wewnątrz `knn`, (c) knn + `post_filter` in_stock — wypisz dla każdego: liczba wyników (z `size=10`), ile niedostępnych w wynikach, nDCG@10 (przez `search:eval:lab`-owy `Ndcg` albo jq). Oczekiwane: (a) zawiera niedostępne, (c) zwraca < 10 wyników, (b) 10 dostępnych.
- [ ] **Step 4:** `shellcheck` czysto; na żywo oba skrypty kończą się 0. Commit `feat(lab): pomiar kosztu wektorów i demo filtrowanego kNN`.

### Task 7: Dokumentacja 14a

**Files:**
- Create: `docs/blog/etap-14a-wektory-i-trafnosc.md`
- Modify: `docs/POMIARY.md` (§18.1 węzeł i próbka, §18.2 tabela 5×3 + per zapytanie najciekawsze, §18.3 koszt i kwantyzacja, §18.4 filtr, §18.5 latencja i recall HNSW), `docs/RUNBOOK.md` (#152 trial wygasł → `make lab-reset`; #153 model się nie wdraża / brak pamięci ML; #154 `_rank_eval` ≠ PHP — co sprawdzić; #155 rozjazd RRF client/native), tabela indeksu RUNBOOK, `docs/06-PLAN-WDROZENIA.md` (ETAP 14: status 14a + link do planu)

- [ ] **Step 1:** Przewodnik krok po kroku dla czytelnika, który nie widział rozmowy: po co laboratorium, dlaczego E5 a nie ELSER, `semantic_text` vs `dense_vector`, HNSW i `num_candidates`, kwantyzacja, RRF (z ręcznym przeliczeniem jednego przykładu), dlaczego `_rank_eval` nie przyjmuje retrieverów i jak to obeszliśmy, co działa na basic, a co wymaga trialu (z kodem 403), wyniki z POMIARÓW i **odpowiedź „kiedy wektory szkodzą” oparta na grupie `exact`**. Komendy do powtórzenia od zera (`lab-export`, `lab-up`, `lab-setup`, `lab-eval`, `lab-costs`, `lab-filter-demo`).
- [ ] **Step 2:** Bez danych dostępowych i bez wewnętrznych odniesień do planu/rozmowy w przewodniku. Commit `docs(lab): przewodnik 14a, POMIARY §18, RUNBOOK #152–#155`.

## 14b

### Task 8: ELSER

**Files:**
- Modify: `infra/elasticsearch/lab/setup-lab.sh` (faza „elser”, uruchamiana `setup-lab.sh elser` i z `make lab-setup-elser`), `infra/elasticsearch/lab/products-lab-v1.json` (pole `semantic_elser`: `semantic_text`, `inference_id: lab-elser`; `lab_text.copy_to` += `semantic_elser`), `LabQueryBuilder` + test (wariant `elser`: zapytanie `semantic` na `semantic_elser`)

- [ ] **Step 1:** Faza „elser” idempotentna: endpoint `lab-elser` (`service: elasticsearch`, model ELSER v2 — identyfikator zgodny z 9.5 i architekturą obrazu; sprawdź w dokumentacji i zanotuj), czekanie na wdrożenie jak w Task 2, `PUT products-lab-v1/_mapping` dodające `semantic_elser` i rozszerzone `copy_to` (jeśli `copy_to` istniejącego pola nie da się zmienić — przebuduj do `products-lab-v2` przez `_reindex` i zanotuj to w RUNBOOK), potem `_update_by_query?wait_for_completion=false` + śledzenie zadania; `force_merge` do 1 segmentu.
- [ ] **Step 2:** Test `LabQueryBuilderTest`: `elser` celuje w `semantic_elser`. FAIL → PASS.
- [ ] **Step 3:** Pamięć: przed wdrożeniem ELSER sprawdź zapas (`need_free_mb` z lib); jeśli dwa modele nie mieszczą się w 4 GB, faza zatrzymuje wdrożenie E5 na czas indeksacji ELSER i przywraca je po (zanotuj).
- [ ] **Step 4:** Na żywo: `make lab-eval args="--variant=bm25 --variant=knn --variant=hybrid-bool --variant=rrf-client --variant=rrf-native --variant=elser --check-rank-eval --json=lab/eval-14b.json"` → tabela 6×3, dwa przebiegi identyczne. Commit `feat(lab): ELSER jako szósty wariant`.

### Task 9: Wykrywanie anomalii

**Files:**
- Create: `tools/lab/export-events.sh`, `tools/lab/anomaly.sh`, `tools/lab/inject-outage.sh`, `infra/elasticsearch/lab/anomaly-job.json`, `infra/elasticsearch/lab/anomaly-datafeed.json`
- Modify: `Makefile` (`lab-export-events`, `lab-anomaly`)

**Interfaces:**
- Consumes: `events-user-default` na głównym klastrze (odczyt jako `elastic` z `ELASTIC_PASSWORD` z `.env`, tylko `_pit` + `search_after`), `tools/lab/lib.sh`.
- Produces: indeks `lab-events-user` na `es-lab` (zwykły indeks, mapowanie: `@timestamp` date, `event.type` keyword, `user.id` keyword, `search.query` keyword — tylko pola potrzebne jobowi); job `lab-user-activity`; plik `apps/catalog/storage/app/private/lab/events.ndjson`.

- [ ] **Step 1:** `export-events.sh`: PIT + `search_after` po `@timestamp`,`_shard_doc`, porcje 5000, tylko `_source` z polami jak wyżej, wynik do NDJSON z nagłówkiem `{"_lab_events": {"count": N, "from": ..., "to": ...}}`; zamyka PIT także po błędzie (`trap`).
- [ ] **Step 2:** `inject-outage.sh <od> <godziny>` (domyślnie okno 6 h zaczynające się 2026-11-10T09:00:00Z): na **kopii w `lab-events-user`** kasuje `user.clicked_result` w oknie (`_delete_by_query`), wypisuje, ile skasował; idempotentny (drugi przebieg kasuje 0). Nigdy nie dotyka głównego klastra — skrypt odmawia, gdy host nie jest hostem lab.
- [ ] **Step 3:** Job: `bucket_span: 1h`, detektor `low_count` z `by_field_name: event.type` (albo `partition_field_name`, decyzja implementatora z uzasadnieniem w przewodniku), datafeed na `lab-events-user` od początku do końca danych. `anomaly.sh`: ładuje NDJSON do `lab-events-user` (stałe `_id` z hasha linii, idempotentnie), tworzy/odtwarza job i datafeed, uruchamia datafeed na całym zakresie, czeka na `datafeed_state: stopped`, potem `GET _ml/anomaly_detectors/lab-user-activity/results/records?record_score=50` i **asercja**: istnieje rekord z `timestamp` w oknie awarii dla `user.clicked_result`. Wyjście: tabela top-5 rekordów (czas, typ, `record_score`, actual vs typical).
- [ ] **Step 4:** Porównanie z regułą progową ETAPU 12: znajdź w `infra/kibana/rules/` regułę ES|QL o kliknięciach/wyszukiwaniach, uruchom jej zapytanie ES|QL (z progiem z reguły) na `lab-events-user` po awarii i wypisz, czy złapała okno; zanotuj różnicę (próg widzi tylko bezwzględną liczbę, ML widzi odstępstwo od typowego wzorca godziny/dnia).
- [ ] **Step 5:** Na żywo: `make lab-export-events`, `make lab-anomaly` przed awarią (zanotuj top rekordy — fałszywe alarmy), `bash tools/lab/inject-outage.sh`, `make lab-anomaly` → asercja zielona. `shellcheck` czysto. Commit `feat(lab): wykrywanie anomalii na zdarzeniach user.* z wstrzykniętą awarią`.

### Task 10: Dokumentacja 14b i domknięcie

**Files:**
- Create: `docs/blog/etap-14b-elser-i-anomalie.md`
- Modify: `docs/POMIARY.md` (§18.6 ELSER w tabeli 6×3, §18.7 anomalie: rekordy przed/po awarii, porównanie z progiem), `docs/RUNBOOK.md` (#156 ELSER nie mieści się w pamięci ML; #157 job anomalii bez wyników / datafeed zatrzymany), `docs/06-PLAN-WDROZENIA.md` (ETAP 14 zakończony + linki), `README.md` (jedna linia o profilu `ml`, jeśli README wymienia profile)

- [ ] **Step 1:** Przewodnik 14b krok po kroku: ELSER (rzadkie wektory, dlaczego angielski przegrywa na polskim — z liczbami), anomaly detection (bucket, detektor, model typowości, `record_score`), porównanie z regułą progową, kiedy ML ma sens w operacjach.
- [ ] **Step 2:** Pełne testy catalog: `docker compose exec catalog-app php artisan test` → zielone (poza znanymi, jeśli są — wypisać).
- [ ] **Step 3:** Commit `docs(lab): przewodnik 14b, POMIARY §18.6–18.7, RUNBOOK #156–#157`.
