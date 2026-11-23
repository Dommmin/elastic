# ETAP 14 (opcjonalny) — Semantyka i ML: projekt

Data: 2026-11-22. Gałąź: `etap-14-semantyka` (odbita od `etap-13-seller-360`, bo PR #23 jeszcze nie scalony;
po scaleniu rebase na `main`). Moduł 13 ścieżki nauki (`docs/03-SCIEZKA-NAUKI.md`), ETAP 14 w
`docs/06-PLAN-WDROZENIA.md`.

## 1. Po co

Cel modułu 13: **wiedzieć, kiedy wektory mają sens, a kiedy są modą** — i umieć to pokazać liczbą, nie
opinią. Etap kończy się tabelą nDCG@10 dla kilku sposobów wyszukiwania na tych samych zapytaniach i tych
samych ocenach, rozbitą na typy zapytań, oraz przewodnikiem, który z tej tabeli wyciąga wnioski.

To jest **laboratorium**, nie funkcja sklepu (decyzja użytkownika, 2026-11-22). Sklep, `products-search`,
główny klaster i k3s zostają nietknięte. Ewentualny tryb mieszany w sklepie to osobny, późniejszy krok,
podejmowany dopiero na podstawie wyników.

## 2. Fakty, które ustawiają projekt (rozpoznanie 2026-11-22)

- Główny klaster: ES 9.5.1, licencja **basic**, 3 węzły po ~1,8/2 GB zajętej pamięci. `products-v6`:
  1,17 mln dokumentów, ale tylko **~7,9 tys. różnych nazw** i **11 kategorii liści** (taksonomia w
  `SeedMarketplaceCommand::TAXONOMY`, np. „Kurtka zimowa {model}”, „Lampa ogrodowa solarna {model}”).
- **Opisy produktów to łacińska zapychajka Fakera** — semantycznie bezwartościowe. Znaczenie niosą nazwa,
  marka i kategoria (nazwy kategorii są w bazie katalogu; w indeksie tylko `category.id`/`category.path`).
- Na basic działa kNN (`knn` jako zapytanie), ale **RRF i retriever `linear` zwracają 403** (licencja).
  `_rank_eval` przyjmuje tylko `query`, nie retrievery.
- **ELSER jest wyłącznie angielski.** Do polskiego nadaje się wielojęzyczny E5 (`.multilingual-e5-small`,
  384 wymiary), dostępny w ES jako model wbudowany — wymaga węzła ML, czyli trialu.
- Istniejąca ocena trafności: `search:eval` (catalog) + `RelevanceJudgments` (reguły → oceny z bazy
  katalogu), `tests/relevance/queries.yaml` (13 zapytań, głównie słownikowych).
- Maszyna współdzielona z innymi projektami — nie zatrzymujemy cudzych kontenerów, pilnujemy zapasu pamięci
  (wzór: kontrola pamięci w `tools/seller360/fls-demo.sh`).

## 3. Cele, nie-cele, anty-cele

**Cele**
- G1. Trwały węzeł laboratoryjny `es-lab` z trialem i ML, włączany profilem Compose `ml`.
- G2. Indeks laboratoryjny z deterministyczną próbką ~20 tys. produktów, z polem semantycznym (E5) i polem
  ELSER, plus jawne `dense_vector` do nauki HNSW/kwantyzacji.
- G3. Porównanie nDCG@10 sześciu wariantów (§6) na trzech grupach zapytań (§5), z własnym liczeniem nDCG
  sprawdzonym krzyżowo z `_rank_eval`.
- G4. Pomiary kosztu: rozmiar indeksu i pamięć z/bez wektorów, czas indeksacji, latencja p50/p99 wariantów,
  wpływ kwantyzacji (float / int8 / bbq) na rozmiar i nDCG.
- G5. „Zepsuj i napraw”: kNN bez filtra `in_stock` vs filtered kNN vs post-filtr (ile wyników ginie z `k`).
- G6 (14b). ELSER zmierzony na polskich zapytaniach (oczekiwana porażka, ale zmierzona).
- G7 (14b). Wykrywanie anomalii (ES ML) na zdarzeniach `user.*` z ETAPU 12, z wstrzykniętą awarią,
  porównane z progową regułą ES|QL z ETAPU 12.
- G8. Przewodniki `docs/blog/etap-14a-…`, `etap-14b-…`, POMIARY §18, wpisy RUNBOOK (definicja „etap
  skończony”).

**Nie-cele**: zmiany w wyszukiwarce sklepu, frontend, k3s/VPS, wektory dla całego 1,17 mln, własny
kontener z modelem (Python), fine-tuning modeli, `semantic_text` na głównym klastrze.

**Anty-cele** (porażka, nawet jeśli „działa”):
- Wynik zależny od losu (niedeterministyczna próbka, remisy rozstrzygane wewnętrznym doc id) — pomiar
  musi się powtarzać co do cyfry na tych samych danych.
- Ocena „pod wektory”: dopisanie wyłącznie zapytań, na których wektory wygrywają. Każda grupa ma zapytania,
  na których powinny przegrać.
- Porównanie jabłek z gruszkami: różne zbiory dokumentów albo różne oceny dla różnych wariantów.
- Wyczerpanie pamięci maszyny i padnięcie głównego klastra albo cudzych projektów.

## 4. Węzeł laboratoryjny (podejście A, wybrane przez użytkownika)

- Usługa `es-lab` w `compose.yaml`, `profiles: [ml]`, ten sam obraz `marketplace/elasticsearch:${ES_VERSION}`
  (te same analizatory polskie i synonimy co produkcja), `discovery.type=single-node`, role domyślne (w tym
  `ml`), wolumen `es-lab-data`, port tylko na `127.0.0.1:${ES_LAB_PORT:-19201}`.
- Pamięć: heap 1,5 GB, `mem_limit` 4 GB, `xpack.ml.max_machine_memory_percent: 60` (przy 1 GB / 3 GB import E5 kończył się OOM-kill).
  Główny klaster i `es-lab` nie mieszczą się naraz: tryb lab (`make lab-mode-on/off`) zatrzymuje i przywraca własne es01–es03, Kibanę i procesy zależne od ES.
  Start chroniony kontrolą zapasu pamięci (jak `fls-demo.sh`): odmowa startu, gdy zapas Dockera < 3,5 GB
  (korekta 2026-11-22: Docker VM ma 15,6 GB i ~4 GB wolne; podpowiedź: zatrzymać własną Kibanę).
- Hasło `elastic` węzła: zmienna `ES_LAB_PASSWORD` w `.env` (`.env.example`: pusty placeholder; repo
  publiczne — żadnych haseł w commitach). Bez TLS (jak reszta lokalnego stosu).
- `infra/elasticsearch/lab/setup-lab.sh` (idempotentny): trial (`start_trial`, gdy jeszcze basic), inference
  endpointy `lab-e5` (`.multilingual-e5-small`) i `lab-elser` (ELSER v2), indeks laboratoryjny, ładowanie
  próbki, zdarzenia do 14b. Modele pobiera sam ES z serwerów Elastica przy tworzeniu endpointu (jedyny
  zewnętrzny download etapu; przewodnik to opisuje).
- Trial trwa 30 dni od pierwszego startu wolumenu. Po wygaśnięciu: `make lab-reset` (kasuje wolumen, trial
  od nowa) — opisane w RUNBOOK.
- Cele `make`: `lab-export`, `lab-up`, `lab-setup`, `lab-eval`, `lab-down`, `lab-reset`.

## 5. Dane i oceny

**Próbka.** Deterministyczna, ~20 tys. produktów z głównego klastra (`products-search`), wybór po
własności id (np. `id mod M = 0`, M dobrane do ~20 tys.; moja decyzja — prosta, powtarzalna, bez losu).
Kopiowana na `es-lab` (reindex z remote albo eksport przez catalog — wybór budowniczego). Indeks
laboratoryjny dostaje dodatkowo nazwę kategorii liścia i korzenia (z bazy katalogu), bo bez niej wektor
widzi tylko nazwę.

**Tekst dla modeli.** `"{name}. {kategoria liścia}, {kategoria korzeń}"` — bez opisu (łacina). Ten sam
tekst dla E5, ELSER i jawnego `dense_vector`. E5 wymaga prefiksów `query: ` / `passage: ` — inference
endpoint ES robi to sam; przy jawnym `dense_vector` budowniczy musi zadbać o te same prefiksy (sprawdzić w
dokumentacji 9.5 i zapisać w przewodniku).

**Oceny.** `RelevanceJudgments` bez zmian semantyki: reguły rozwijane na całym katalogu, potem **przecięte
z id próbki** (ocena dokumentu spoza indeksu zaniżałaby ideał nDCG). Zapytanie, którego oceny po przecięciu
są puste, przerywa ewaluację (jak reguła, która nic nie łapie).

**Nowy plik** `tests/relevance/semantic-queries.yaml`, każde zapytanie z polem `group`:
- `lexical` — istniejące zapytania słownikowe (`smartfon`, `wiertarka`, `kurtka zimowa`…).
- `descriptive` — opis potrzeby bez słów z nazwy, ~12 zapytań, np. „coś ciepłego na zimę” (Kurtka zimowa 3,
  Kurtki 1), „oświetlenie do ogrodu bez prądu” (Lampa ogrodowa solarna 3, Oświetlenie 1), „ćwiczenia w domu”
  (Fitness), „jazda po górach” (Rower górski), „muzyka w podróży” (Słuchawki), „prezent dla biegacza”.
- `exact` — gdzie wektory powinny szkodzić: pełna nazwa z marką i modelem (`Wyman-Howell Laptop Ultra 14"`),
  sama marka (`Orn PLC`), model + pojemność (`Nova 256GB`), ~8 zapytań.

Oceny w skali 0–3 jak dotąd. Zasada remisów z `queries.yaml` obowiązuje (strażnik `TieGroupGuard`).

## 6. Warianty i pomiar

| Wariant | Jak | Licencja |
|---|---|---|
| `bm25` | obecne zapytanie tekstowe (`buildTextQuery`, bez popularności), na indeksie lab | basic |
| `knn` | zapytanie `knn` na polu E5, `k=10`, `num_candidates` stałe (np. 100) | basic* |
| `hybrid-bool` | `bool.should` [tekst, `knn`] — suma wyników (na basic jedyna „mieszanka” w ES) | basic* |
| `rrf-client` | dwa zapytania (bm25, knn), RRF policzone w PHP (`k=60`, rank window 100) | basic* |
| `rrf-native` | retriever `rrf` [standard, knn], te same parametry | trial |
| `elser` | `semantic_text` na ELSER (14b) | trial |

\* samo zapytanie działa na basic; wektor zapytania liczy jednak inference ES (trial). Na basic trzeba by go
liczyć poza ES — przewodnik mówi to wprost.

- Nowe polecenie w catalog, np. `search:eval:lab {--variant=*} {--group=*}`, drugi klient ES do `es-lab`.
  **nDCG@10 liczone w PHP** dla wszystkich wariantów (ta sama funkcja, te same oceny, gain `2^g − 1` jak w
  `_rank_eval`). Dla wariantów wyrażalnych jako `query` (bm25, knn, hybrid-bool) wynik PHP musi zgadzać się
  z `_rank_eval` z dokładnością 1e-6 — test, który pilnuje, że własny licznik liczy to samo.
- `rrf-client` i `rrf-native` muszą dać tę samą kolejność top-10 (z dokładnością do remisów) — test
  zgodności; to dowód, że RRF to prosty algorytm, a nie magia licencji.
- Wyjście: tabela wariant × grupa (średnie nDCG@10) + per zapytanie, zapisywana też jako JSON do POMIARÓW.
- Latencja: p50/p99 z N powtórzeń na wariant (rozgrzane), osobno czas inference zapytania.

**Koszt wektorów (G4).** `_disk_usage` na polu wektorowym i rozmiar indeksu z/bez wektorów; czas ładowania
próbki z inference vs bez; trzy kopie jawnego `dense_vector` (`hnsw` float, `int8_hnsw`, `bbq_hnsw`) — rozmiar
i nDCG wariantu `knn` na każdej. Ekstrapolacja na 1,17 mln w przewodniku (rachunek, nie pomiar).

**Filtr (G5).** Zapytanie opisowe z filtrem `in_stock=true`: kNN z filtrem wewnątrz `knn` vs `knn` + `post_filter`
— liczba wyników < `k` i trafienia niedostępnych. Część próbki trzeba mieć z `in_stock=false` (sprawdzić, czy
próbka je zawiera; jeśli nie, oznaczyć deterministyczny podzbiór w indeksie lab, nie w sklepie).

## 7. 14b — ELSER i anomalie

- ELSER: wariant `elser` w tej samej tabeli. Oczekiwanie: wyraźnie gorszy od E5 na `descriptive`; jeśli
  wyjdzie inaczej, przewodnik to tłumaczy, a nie ukrywa.
- Anomalie: zdarzenia `events-user-default` (ok. 158 tys., 2026-09-26 → 2026-11-20) kopiowane na `es-lab`.
  Job ML (np. `count` kliknięć / wyszukiwań w kubełku 1 h, ewentualnie z podziałem) + **wstrzyknięta awaria**:
  deterministyczny skrypt kasuje/dokłada zdarzenia w oknie kilku godzin na kopii lab. Dowód: job oznacza okno
  awarii (wynik anomalii powyżej progu), a reguła progowa z ETAPU 12 zastosowana do tych samych danych —
  co łapie, czego nie. Bez alertowania do Kibany głównej (lab nie ma Kibany; wyniki przez API).

## 8. Bezpieczeństwo i ograniczenia

- Repo publiczne: żadnych haseł, tokenów ani kluczy w commitach; `ES_LAB_PASSWORD` tylko w `.env`.
- Nie dotykamy głównego klastra poza odczytem (próbka, zdarzenia). Żadnych zmian w `products-*`, aliasach ani
  pipeline'ach sklepu.
- Nie zatrzymujemy kontenerów innych projektów; kontrola pamięci przed startem `es-lab`.
- Węzeł lab nie trafia do `compose.prod.yaml`, k8s ani na VPS.

## 9. Podział i Definition of Done

**14a** (G1–G5, G8 część a): węzeł, próbka, E5, jawne `dense_vector`, warianty bm25/knn/hybrid-bool/rrf-client/
rrf-native, koszty, filtr. **14b** (G6, G7, G8 część b): ELSER, anomalie.

**DoD etapu**: `make lab-eval` daje tabelę nDCG@10 6 wariantów × 3 grupy, powtarzalną co do cyfry; testy
zgodności (PHP vs `_rank_eval`, client vs native RRF) zielone; pomiary kosztu i filtra w POMIARY §18; job
anomalii oznacza wstrzykniętą awarię; dwa przewodniki krok po kroku w `docs/blog/` z odpowiedzią „kiedy
wektory szkodzą” opartą na liczbach z grupy `exact`; testy catalog zielone.

## 10. Rozstrzygnięcia po przeglądzie budowniczego (moje decyzje, 2026-11-22)

- R1. **Główna tabela używa pola `semantic_text` (E5)** dla `knn`, `hybrid-bool` i `rrf-*`. Trzy jawne kopie
  `dense_vector` (float/int8/bbq) służą tylko eksperymentowi kosztu (§6) — jeden indeks, kilka pól.
- R2. **Zapytania `exact` dobierane po wylosowaniu próbki** z produktów, które w niej są (M jest stałe, nie
  stroimy go pod zapytania). Reguły `name` / `brand` / `name_contains` z `RelevanceJudgments` wystarczają.
  Pusta ocena po przecięciu nadal przerywa ewaluację — to strażnik nieaktualnego pliku, nie przeszkoda.
- R3. **Powtarzalność:** indeks lab ma 1 shard, 0 replik, po załadowaniu `force_merge` do 1 segmentu. Remisy
  (~148 produktów na nazwę) neutralizują reguły ocen jak dotąd. Dodatkowo wariant diagnostyczny `knn-exact`
  (brute force, `script_score` z podobieństwem cosinusowym) — mierzy, ile HNSW gubi względem dokładnego kNN
  (recall@10), nie trafia do tabeli głównej.
- R4. **14a kończy się tabelą 5 wariantów × 3 grupy**; 14b dokłada kolumnę `elser`. DoD etapu (§9) = 6.
- R5. **Próbka i zdarzenia eksportowane raz** z głównego klastra do pliku w ścieżce ignorowanej przez git
  (`apps/catalog/storage/app/private/lab/`, już ignorowana); `lab-setup` ładuje z pliku. Główny klaster potrzebny tylko do eksportu, `lab-eval`
  wymaga tylko `es-lab` i bazy katalogu (oceny). Eksport jest jawnym krokiem (`make lab-export`).

## 11. Zostawione budowniczemu

Sposób kopiowania próbki (reindex z remote vs eksport przez catalog), dokładne M, nazwy klas i plików,
`num_candidates` (z uzasadnieniem w przewodniku), liczba powtórzeń do latencji, konfiguracja jobu anomalii
(detektor, kubełek), forma skryptu awarii, numery wpisów RUNBOOK.
