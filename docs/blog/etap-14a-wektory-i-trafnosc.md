# Wektory i trafność, krok po kroku: BM25, kNN, hybryda i RRF na polskich zapytaniach

*ETAP 14a projektu Marketplace Search Platform. Pytanie etapu brzmi: **kiedy wektory mają sens, a kiedy są modą?**
Odpowiedź ma być liczbą, nie opinią. Budujemy osobny węzeł laboratoryjny z modelem E5, ładujemy deterministyczną
próbkę 19 824 produktów i mierzymy nDCG@10 pięciu sposobów wyszukiwania (BM25, kNN, suma w `bool`, RRF liczone
w PHP, RRF w ES) na tych samych 33 zapytaniach i tych samych ocenach, rozbitych na trzy grupy: słownikowe, opisowe
i dokładne. Do tego koszt wektorów (dysk, czas ładowania, kwantyzacja), filtrowany kNN i recall grafu HNSW. Część
14b (ELSER i wykrywanie anomalii) będzie w osobnym przewodniku.*

> **Stan:** gałąź `etap-14-semantyka` (niewypchnięta), lokalny stos Docker, węzeł `es-lab` (ES 9.5.1, jeden węzeł,
> licencja trial), sprawdzone 2026-11-22. **Na żywo:** tabela 5 × 3 powtarza się co do cyfry dla tej samej budowy
> indeksu (przeliczona ponownie przy pisaniu tego tekstu), własne nDCG zgodne z `_rank_eval` na 33/33 zapytaniach,
> RRF z PHP zgodny z RRF z ES na 33/33 (z dokładnością do remisów), recall@10 HNSW 0,952 na pierwszej budowie grafu
> i 0,997 na obecnej.
> Projekt: [specyfikacja](../superpowers/specs/2026-11-22-etap-14-semantyka-ml-design.md).
> Pomiary: [`POMIARY.md` §18](../POMIARY.md). Błędy z drogi: RUNBOOK [#152–#155](../RUNBOOK.md#152).
> Laboratorium nie trafia do sklepu, na k3s ani na VPS: `products-search` i główny klaster zostają nietknięte.

---

## Spis treści

1. [Problem: wektory jako hipoteza do sprawdzenia](#1-problem-wektory-jako-hipoteza-do-sprawdzenia)
2. [Decyzje](#2-decyzje)
3. [Mapa plików](#3-mapa-plików)
4. [Cały tor 14a od zera](#4-cały-tor-14a-od-zera)
5. [Krok 1 — węzeł laboratoryjny i tryb lab](#krok-1--węzeł-laboratoryjny-i-tryb-lab)
6. [Krok 2 — dlaczego E5, a nie ELSER](#krok-2--dlaczego-e5-a-nie-elser)
7. [Krok 3 — próbka `id % 15 = 0` i tekst dla modelu](#krok-3--próbka-id--15--0-i-tekst-dla-modelu)
8. [Krok 4 — `semantic_text` kontra `dense_vector` i prefiksy E5](#krok-4--semantic_text-kontra-dense_vector-i-prefiksy-e5)
9. [Krok 5 — trzy grupy zapytań i oceny](#krok-5--trzy-grupy-zapytań-i-oceny)
10. [Krok 6 — pięć wariantów i formy zapytań, które ES przyjmuje](#krok-6--pięć-wariantów-i-formy-zapytań-które-es-przyjmuje)
11. [Krok 7 — basic kontra trial: co dostaje 403](#krok-7--basic-kontra-trial-co-dostaje-403)
12. [Krok 8 — RRF policzone ręcznie](#krok-8--rrf-policzone-ręcznie)
13. [Krok 9 — własne nDCG, bo `_rank_eval` nie przyjmuje retrieverów](#krok-9--własne-ndcg-bo-_rank_eval-nie-przyjmuje-retrieverów)
14. [Krok 10 — tabela wyników](#krok-10--tabela-wyników)
15. [Krok 11 — HNSW, `num_candidates` i dlaczego 0,801 było szczęściem](#krok-11--hnsw-num_candidates-i-dlaczego-0801-było-szczęściem)
16. [Krok 12 — kwantyzacja: dysk, pamięć, jakość](#krok-12--kwantyzacja-dysk-pamięć-jakość)
17. [Krok 13 — filtrowany kNN: filtr w `knn` kontra `post_filter`](#krok-13--filtrowany-knn-filtr-w-knn-kontra-post_filter)
18. [Krok 14 — latencja i cache inferencji](#krok-14--latencja-i-cache-inferencji)
19. [Kiedy wektory szkodzą, a kiedy pomagają](#kiedy-wektory-szkodzą-a-kiedy-pomagają)
20. [Liczby z laboratorium](#liczby-z-laboratorium)
21. [Ograniczenia](#ograniczenia)
22. [Co wybuchło po drodze](#co-wybuchło-po-drodze)
23. [Ściąga](#ściąga)

---

## 1. Problem: wektory jako hipoteza do sprawdzenia

Wyszukiwarka sklepu (ETAPY 3–12) to BM25 z polskim stemmerem, synonimami i popularnością. Działa dobrze, gdy klient
pisze słowa, które są w nazwie produktu: `kurtka zimowa`, `wiertarka`, `Wyman-Howell Laptop Ultra 14"`. Nie działa,
gdy klient opisuje potrzebę: „coś ciepłego na zimę” nie ma ani jednego słowa wspólnego z „Kurtka zimowa Alpine”.
Wektory (embeddingi) obiecują to naprawić, bo porównują znaczenie, a nie słowa.

Obietnica ma cenę: model ML w klastrze, wolniejsze indeksowanie, więcej dysku i pamięci, wyniki przybliżone. Do tego
dochodzi ryzyko, że wektory **pogorszą** zapytania, które BM25 obsługuje idealnie (dokładne nazwy, marki, modele).
Etap ma więc odpowiedzieć liczbą na dwa pytania: ile wektory dają na zapytaniach opisowych i ile zabierają na
słownikowych i dokładnych.

Żeby porównanie było uczciwe, wszystkie warianty dostają **ten sam zbiór dokumentów, te same zapytania i te same
oceny**, a wynik musi się powtarzać co do cyfry. Dlatego laboratorium, a nie eksperyment na sklepie.

## 2. Decyzje

| Decyzja | Dlaczego |
|---|---|
| Osobny węzeł `es-lab` (profil Compose `ml`), nie główny klaster | Model E5 wymaga węzła ML, czyli licencji trial. Główny klaster jest na basic i ma zostać nietknięty |
| Tryb lab (`make lab-mode-on/off`) | Docker VM ma 15,6 GB, maszyna jest współdzielona; `es-lab` (4 GB) i główny klaster (3 węzły) nie mieszczą się naraz |
| Model `.multilingual-e5-small` (384 wymiary) | ELSER jest tylko angielski; E5 jest wielojęzyczny i wbudowany w ES |
| Próbka `id % 15 = 0` (19 824 produkty) | Deterministyczna, bez losowania; ~20 tys. ładuje się w kwadrans, a 1,17 mln dokumentów zajęłoby ~15 h |
| Tekst dla modelu: `"{nazwa}. {kategoria liścia}, {kategoria korzeń}"` | Opisy produktów to łacina Fakera, znaczenie niosą nazwa i kategoria |
| Główna tabela na polu `semantic_text`, trzy jawne `dense_vector` tylko do pomiaru kwantyzacji | Jeden indeks, kilka pól, te same dokumenty |
| 1 shard, 0 replik, `force_merge` do 1 segmentu | Powtarzalność: remisy i graf HNSW nie zależą od kopii shardu ani od podziału na segmenty |
| nDCG@10 liczone w PHP dla wszystkich wariantów, sprawdzane z `_rank_eval` | `_rank_eval` nie przyjmuje retrieverów (RRF), a porównujemy też RRF |
| `num_candidates = 100` | 10 × k; pomiar z `--num-candidates` (krok 11) pokazał, że więcej nie poprawia nDCG |

## 3. Mapa plików

```
compose.yaml                                                usługa es-lab (profil ml), wolumen es-lab-data
.env.example                                                ES_LAB_PASSWORD (pusty), ES_LAB_PORT=19201
tools/lab/lib.sh                                            step/die, lab_es (curl -K, hasło nie trafia do argumentów), need_free_mb
tools/lab/lab-mode.sh                                       make lab-mode-on/off (zapisuje, co działało, i przywraca dokładnie to)
tools/lab/lab-up.sh                                         make lab-up (kontrola zapasu pamięci, czekanie na zdrowie)
tools/lab/costs.sh                                          make lab-costs
tools/lab/filter-demo.sh                                    make lab-filter-demo (własny jednorazowy indeks)
infra/elasticsearch/lab/setup-lab.sh                        make lab-setup: trial, endpoint lab-e5, pipeline, indeks, ładowanie
infra/elasticsearch/lab/products-lab-v1.json                mapowanie: lab_text → semantic_e5, vec_float / vec_int8 / vec_bbq
infra/elasticsearch/lab/lab-embed-pipeline.json             pipeline: inference E5 → vec_float, kopie do vec_int8 i vec_bbq
apps/catalog/app/Console/Commands/LabExportCommand.php      search:lab:export (make lab-export)
apps/catalog/app/Console/Commands/SearchEvalLabCommand.php  search:eval:lab (make lab-eval)
apps/catalog/app/Services/Search/Lab/
  LabVariant.php                                            bm25, knn, hybrid-bool, rrf-client, rrf-native (+ elser w 14b)
  LabQueryBuilder.php                                       ciała zapytań wszystkich wariantów
  LabEvalRunner.php                                         przebieg, kontrole _rank_eval i RRF, recall, latencja
  LabJudgments.php                                          oceny z reguł, przecięte z próbką
  Ndcg.php                                                  nDCG@k jak w _rank_eval
  Rrf.php                                                   RRF w PHP
tests/relevance/semantic-queries.yaml                       33 zapytania w trzech grupach
apps/catalog/storage/app/private/lab/                       (ignorowane przez git) próbka, wyniki JSON, plik stanu trybu lab
```

Próbka (`products.ndjson`) i wyniki (`eval-14a.json`, `eval-14a-pre-rebuild.json`, `latency-14a.json`) leżą w
katalogu ignorowanym przez git. Nie ma ich w repozytorium; odtwarza się je komendami z następnej sekcji.

## 4. Cały tor 14a od zera

Wymagania: działający stos (`make up`), baza katalogu z produktami, `jq`. Jednorazowo hasło węzła lab w `.env`
(repozytorium jest publiczne, hasło nie trafia do commitów):

```bash
grep -q '^ES_LAB_PASSWORD=.' .env || echo "ES_LAB_PASSWORD=$(openssl rand -hex 16)" >> .env
```

Potem kolejno:

```bash
# 1. eksport próbki: tryb NORMALNY (główny ES + baza katalogu), ~20 s
make lab-export                       # → storage/app/private/lab/products.ndjson, 19 824 linie

# 2. przełączenie na laboratorium
make lab-mode-on                      # zatrzymuje własne es01–es03, Kibanę i procesy zależne od ES
make lab-up                           # es-lab: kontrola zapasu pamięci (4,5 GB), start, health green
docker compose up -d --no-deps catalog-app   # tylko raz, jeśli catalog-app powstał przed dodaniem ELASTICSEARCH_LAB_*

# 3. trial, model, indeks, ładowanie (~15 min; pierwszy raz pobiera model z serwerów Elastica)
make lab-setup

# 4. pomiary
make lab-eval args="--check-rank-eval --check-rrf --json=lab/eval-14a.json"   # tabela 5×3 + kontrole
make lab-eval args="--variant=knn --recall"                                   # recall@10 HNSW
make lab-eval args="--latency=50 --json=lab/latency-14a.json"                 # p50/p99
make lab-eval args="--variant=knn --num-candidates=500"                       # wpływ num_candidates
make lab-eval args="--variant=knn --vector-field=vec_int8"                    # pola z kwantyzacją
make lab-costs                                                                # dysk, pamięć, ładowanie bez wektorów
make lab-filter-demo                                                          # filtr w knn kontra post_filter

# 5. powrót do normalnej pracy
make lab-mode-off                     # zatrzymuje es-lab, przywraca dokładnie to, co działało przed lab-mode-on
```

`make lab-setup` jest idempotentne: drugi przebieg nic nie psuje (trial i endpoint już są, ładowanie nadpisuje te
same `_id` w ~25 s dzięki cache inferencji). Dane węzła zostają w wolumenie `es-lab-data` między `lab-down` a
`lab-up`; kasuje je dopiero `make lab-reset CONFIRM=1` (RUNBOOK [#152](../RUNBOOK.md#152)).

## Krok 1 — węzeł laboratoryjny i tryb lab

`es-lab` to ten sam obraz co produkcja (`marketplace/elasticsearch:9.5.1`, te same polskie analizatory i synonimy),
`discovery.type=single-node`, role domyślne (w tym `ml`), port tylko na `127.0.0.1:19201`, hasło `elastic` z
`ES_LAB_PASSWORD` w `.env`. Profil `ml` sprawia, że zwykłe `docker compose up` go nie uruchamia.

**Pamięć — pierwsza wersja padła.** Węzeł z heapem 1 GB i limitem kontenera 3 GB został zabity przez OOM-killer
(`exit 137`) w trakcie importu modelu E5. Model ma 470 MB, ale jego wdrożenie potrzebuje ~1,5 GB pamięci natywnej
(poza heapem JVM; `required_native_memory_bytes` w `_ml/trained_models/_stats`). Działa: heap 1,5 GB, limit 4 GB,
`xpack.ml.max_machine_memory_percent: 60`. Kontener po wdrożeniu modelu zajmuje ~3 GB. Szczegóły i sprzątanie po
przerwanym imporcie: RUNBOOK [#153](../RUNBOOK.md#153).

**Tryb lab.** Na maszynie z ~4 GB wolnej pamięci 4-gigabajtowy węzeł obok trzech węzłów głównego klastra się nie
zmieści. `make lab-mode-on`:

1. sprawdza, które z własnych usług (`es01–es03`, `kibana`, konsumenci i procesy zależne od ES) działają,
2. zapisuje ich listę do `storage/app/private/lab/lab-mode.state`,
3. zatrzymuje tylko je. Postgres i `catalog-app` zostają, bo `lab-eval` czyta oceny z bazy katalogu.

`make lab-mode-off` zatrzymuje `es-lab`, startuje najpierw węzły ES, czeka na zdrowie klastra, potem resztę z listy.
Cudzych kontenerów (inne projekty na tej samej maszynie) skrypt nie dotyka. Po `lab-mode-on` zapas pamięci wynosił
~9,8 GB.

Uwaga: jeśli działa profil `obs` (filebeat, metricbeat, prometheus), jego procesy w trybie lab co chwilę próbują
połączyć się z zatrzymanym głównym ES i piszą błędy do logów. To nieszkodliwe; wracają do normy po `lab-mode-off`.

`make lab-up` przed startem liczy zapas pamięci Dockera (pamięć VM minus suma użycia kontenerów) i odmawia poniżej
4,5 GB z podpowiedzią `make lab-mode-on`.

## Krok 2 — dlaczego E5, a nie ELSER

ES ma dwa wbudowane modele do wyszukiwania semantycznego:

| | ELSER v2 | `.multilingual-e5-small` |
|---|---|---|
| Typ | rzadki (sparse): rozwija tekst w ważone tokeny słownika | gęsty (dense): wektor 384 liczb |
| Język | **tylko angielski** (słownik WordPiece z angielskiego BERT) | ~100 języków, w tym polski |
| Pole | `sparse_vector` / `semantic_text` | `dense_vector` / `semantic_text` |
| Wyszukiwanie | odwrócony indeks (jak BM25) | graf HNSW |

Polskie „kurtka” w ELSER rozpada się na angielskie kawałki słów bez znaczenia. Do polskich zapytań nadaje się E5.
ELSER zmierzymy w 14b i oczekujemy porażki, ale porażki zmierzonej.

Oba modele wymagają węzła ML, a ML (wdrożenie modelu i inference) wymaga licencji **trial** (albo płatnej).
`make lab-setup` włącza trial tylko na `es-lab`:

```bash
POST _license/start_trial?acknowledge=true      # tylko gdy GET _license zwraca "basic"
```

Trial trwa 30 dni od pierwszego startu wolumenu. Licencja jest związana z UUID klastra, więc drugi trial na tym samym
wolumenie nie ruszy; po wygaśnięciu kasujemy wolumen (`make lab-reset CONFIRM=1`) i stawiamy węzeł od nowa,
RUNBOOK [#152](../RUNBOOK.md#152).

Endpoint inference (jedyny zewnętrzny download etapu: ES sam pobiera model z serwerów Elastica):

```bash
PUT _inference/text_embedding/lab-e5
{"service":"elasticsearch","service_settings":{"model_id":".multilingual-e5-small","num_allocations":1,"num_threads":1}}
```

## Krok 3 — próbka `id % 15 = 0` i tekst dla modelu

**Ile produktów.** Indeks sklepu ma 1,17 mln dokumentów, ale to dokumenty z zagnieżdżeniami; baza katalogu ma
**297 360 produktów**. Pierwsza wersja eksportu liczyła modulo pod 1,17 mln (M = 58) i dała 5122 produkty zamiast
~20 tys. Poprawka: **M = 15**, czyli **19 824 produkty**. Wybór po własności `id` nie wymaga losowania, więc każdy,
kto ma tę samą bazę, dostanie tę samą próbkę.

**Eksport jest jawnym krokiem** (`make lab-export`, ~20 s): `search:lab:export` czyta nazwę, opis, markę i kategorie
z bazy katalogu, a `in_stock` i `price_min` z `_mget` na głównym `products-search`. Zapisuje NDJSON do
`storage/app/private/lab/products.ndjson`. Główny klaster jest potrzebny tylko do tego kroku; potem laboratorium
działa bez niego.

**Tekst dla modelu.** Opisy produktów to łacińska zapychajka Fakera, więc wektor z opisu byłby szumem. Pole
`lab_text` ma postać `"{nazwa}. {kategoria liścia}, {kategoria korzeń}"`, np. „Kurtka zimowa Alpine. Kurtki,
Odzież”. Nazwa kategorii w indeksie sklepu nie istnieje (jest tylko `category.id`), dlatego eksport bierze ją z bazy.

**Ładowanie** (`make lab-setup`, faza `load`): `_bulk` po 500 dokumentów przez pipeline `lab-embed`, potem
`_refresh` i `_forcemerge?max_num_segments=1`. **896 s** dla 19 824 dokumentów. Dla porównania ta sama próbka bez
wektorów ładuje się w **~4 s** (pomiar `make lab-costs`, rozdzielczość 1 s). Prawie cały czas to inference E5 na
jednym wątku CPU; każdy dokument jest liczony dwa razy (pipeline dla `vec_*` i `semantic_text` dla `semantic_e5`).

Strażnik: przed `_bulk` skrypt zapisuje w `_meta.lab_modulo` indeksu modulo próbki. Ponowne ładowanie z tym samym
modulo wznawia przerwane (stałe `_id`), a z innym odmawia z podpowiedzią „usuń indeks albo `make lab-reset`”.

## Krok 4 — `semantic_text` kontra `dense_vector` i prefiksy E5

Indeks `products-lab-v1` ma cztery pola wektorowe z tego samego tekstu:

```json
"lab_text":    { "type": "text", "analyzer": "pl_index", "copy_to": ["semantic_e5"] },
"semantic_e5": { "type": "semantic_text", "inference_id": "lab-e5" },
"vec_float":   { "type": "dense_vector", "dims": 384, "similarity": "cosine", "index_options": { "type": "hnsw" } },
"vec_int8":    { "type": "dense_vector", "dims": 384, "similarity": "cosine", "index_options": { "type": "int8_hnsw" } },
"vec_bbq":     { "type": "dense_vector", "dims": 384, "similarity": "cosine", "index_options": { "type": "bbq_hnsw" } }
```

**`semantic_text`** robi wszystko sam: przy zapisie woła endpoint inference, dzieli długi tekst na fragmenty
(chunki), zapisuje wektory w ukrytym polu `semantic_e5.inference.chunks.embeddings`, a przy wyszukiwaniu sam liczy
wektor zapytania. Wygoda kosztem kontroli: typ indeksu i kwantyzację wybiera ES (krok 12).

**`dense_vector`** to surowe pole. Wektor trzeba dostarczyć: tu robi to pipeline z procesorem `inference`, który
zapisuje wynik E5 do `vec_float`, a skrypt kopiuje go do `vec_int8` i `vec_bbq` (kwantyzację robi już sam indeks,
według `index_options`). Przy wyszukiwaniu wektor zapytania liczy `query_vector_builder` albo my sami.

**Prefiksy E5.** Model był trenowany z prefiksami: dokumenty jako `passage: …`, zapytania jako `query: …`. Bez nich
wektory są inne i gorzej do siebie pasują. Dokumentacja 9.x tego nie opisuje, więc sprawdziliśmy empirycznie,
porównując wektory:

| Kto liczy wektor | Prefiks | Co trzeba zrobić |
|---|---|---|
| procesor `inference` w pipeline | dokleja `passage: ` sam (wektor = endpoint z `input_type: ingest`) | **nic**; dopisanie prefiksu w pipeline daje podwójny prefiks i psuje wektor |
| `semantic_text` przy zapisie | jak wyżej | nic |
| `query_vector_builder` w zapytaniu `knn` | dokleja `query: ` sam | nic |
| ręcznie: `POST _inference/text_embedding/lab-e5` | **bez `input_type` nie dokleja niczego** | podać `"input_type": "search"` |

Dowód z laboratorium: wynik `knn` z `query_vector_builder` (0,93084884) był równy wynikowi z ręcznym wektorem
liczonym z `input_type: search`, a wektor bez `input_type` dał inny wynik (0,94201446).

Drobna pułapka: procesor `inference` dopisuje do dokumentu pole `model_id`, a mapowanie ma `dynamic: strict`, więc
zapis by padł. Pipeline ma dlatego procesor `remove` dla `model_id`.

## Krok 5 — trzy grupy zapytań i oceny

`tests/relevance/semantic-queries.yaml` ma 33 zapytania. Każde ma pole `group`:

| Grupa | Liczba | Przykłady | Kto powinien wygrać |
|---|---|---|---|
| `lexical` | 13 | `smartfon`, `kurtka zimowa`, `komórka` (synonim), `Orn PLC` | BM25 albo remis |
| `descriptive` | 12 | „coś ciepłego na zimę”, „oświetlenie do ogrodu bez prądu”, „dojazd do pracy bez wysiłku” | wektory |
| `exact` | 8 | `Jones-Gerhold Smartfon Vento 256GB 5G`, `Wolff Inc`, `Nova 256GB`, literówka `Romagera Inc …` | BM25 |

Każda grupa ma zapytania, na których faworyt powinien przegrać, żeby zestaw nie był ułożony „pod wektory”.

**Oceny** to reguły (`name`, `name_contains`, `brand`, `category`) w skali 0–3, rozwijane z bazy katalogu przez
`RelevanceJudgments` i **przecinane z id próbki**. Ocena dokumentu, którego nie ma w indeksie, zaniżałaby ideał nDCG
każdemu wariantowi. Przykład: „coś ciepłego na zimę” → `name_contains: 'Kurtka zimowa'` = 3, kategoria `Kurtki` = 1.

**Zasada remisów.** Gdy BM25 daje kilku dokumentom w top-10 identyczny `_score`, ich kolejność rozstrzyga wewnętrzny
numer dokumentu, czyli przypadek. Jeśli takie dokumenty mają różne oceny, nDCG zależy od losu. Dlatego każda grupa
remisu w top-10 BM25 musi mieć jedną ocenę; pilnuje tego strażnik w `search:eval:lab` (kod wyjścia 2).

**Zmiana oceny po zobaczeniu wyników — trzeba to powiedzieć wprost.** Pierwszy przebieg strażnika złapał remis
w zapytaniu z literówką `Romagera Inc Rower górski Pro X 29"`. BM25 nie dopasowuje błędnej marki, więc „Romaguera Inc
Rower górski Pro X 29"” (ocena 3) i „Wolff Inc Rower górski Pro X 29"” (ocena 0) mają ten sam `_score` 50,5575
(wspólne „Inc” i model). Żadna jedna ocena dla tej pary nie zachowuje „3 tylko dla Romaguera”. Zmieniliśmy regułę na
`name_contains: 'Rower górski Pro X 29"'` z oceną 2: cała rodzina modelu jest „dokładnie tym typem produktu”.

To decyzja podjęta **po** zobaczeniu wyników i zmieniła kierunek wniosku na tym zapytaniu: grupa `exact` dla bm25
wzrosła z 0,954 do 1,000, a dla knn spadła z 1,000 do 0,972. Zapytanie mierzy teraz „czy wariant znajduje model mimo
literówki w marce”, a nie „czy znajduje dokładnie ten produkt”. Wracamy do tego w sekcji
[Kiedy wektory szkodzą](#kiedy-wektory-szkodzą-a-kiedy-pomagają).

## Krok 6 — pięć wariantów i formy zapytań, które ES przyjmuje

| Wariant | Jak | Ciało (skrót) |
|---|---|---|
| `bm25` | zapytanie sklepu (`buildTextQuery`: `multi_match` best_fields, `name^3, name.ac, brand^2, description`, tie_breaker 0,3), bez popularności | `{"query": <bm25>}` |
| `knn` | kNN na `semantic_e5`, k = 10, `num_candidates` = 100 | `{"query": {"knn": …}}` |
| `hybrid-bool` | `bool.should` [bm25, knn]: suma wyników | `{"query": {"bool": {"should": [<bm25>, <knn>]}}}` |
| `rrf-client` | dwa `_search` (bm25 `size` 100, knn k = 10), RRF w PHP, `rank_constant` 60, okno 100 | `Rrf::fuse()` |
| `rrf-native` | retriever `rrf` z dwoma retrieverami `standard` | `{"retriever": {"rrf": …}}` |

**Która forma kNN działa na `semantic_text`.** Sprawdzone na ES 9.5.1:

| Forma | Na `semantic_text` |
|---|---|
| sekcja `knn` na najwyższym poziomie `_search` | **400** `[knn] queries are only supported on [dense_vector] fields` |
| retriever `knn` | **400**, ten sam komunikat |
| zapytanie `knn` w `query` z `query_vector_builder` | **działa** |

Działająca forma:

```json
{
  "query": {
    "knn": {
      "field": "semantic_e5",
      "k": 10,
      "num_candidates": 100,
      "query_vector_builder": { "text_embedding": { "model_id": "lab-e5", "model_text": "coś ciepłego na zimę" } }
    }
  }
}
```

Dlatego `rrf-native` używa dwóch retrieverów `standard` (jeden z zapytaniem BM25, drugi z tym zapytaniem `knn`),
a nie retrievera `knn`:

```json
{ "retriever": { "rrf": {
    "retrievers": [ { "standard": { "query": <bm25> } }, { "standard": { "query": <knn jak wyżej> } } ],
    "rank_constant": 60, "rank_window_size": 100 } } }
```

`model_id` w `query_vector_builder` można pominąć (ES weźmie model z pola), ale jawny jest czytelniejszy.

## Krok 7 — basic kontra trial: co dostaje 403

| Funkcja | basic | trial |
|---|---|---|
| zapytanie `knn` z gotowym `query_vector` | działa | działa |
| `bool` z `knn` (hybrid-bool) | działa | działa |
| wdrożenie modelu, `_inference`, `query_vector_builder`, `semantic_text` | **nie** (ML) | działa |
| retriever `rrf` | **403** | działa |
| retriever `linear` | **403** | działa |
| `_rank_eval` | działa (tylko `query`) | działa (tylko `query`) |

Na basic odpowiedź na retriever `rrf` (i `linear`) to HTTP **403** z błędem licencji: ES zgłasza, że bieżąca
licencja nie obejmuje tej funkcji. Kod 403 sprawdziliśmy na głównym klastrze (basic) przy rozpoznaniu; dokładnej
treści komunikatu tu nie cytujemy, bo laboratorium działa na trialu, a w trybie lab główny klaster jest zatrzymany.
Sprawdzisz sam po `make lab-mode-off`, wysyłając do `products-search` retriever `rrf` z dwoma retrieverami
`standard` (np. dwa zapytania `match` na `name`) — to zapytanie tylko czyta. `search:eval:lab` łapie 403 z „license” w treści i odpowiada jednym zdaniem: „Licencja węzła lab nie
obejmuje wariantów … — włącz trial: make lab-setup”.

Wniosek praktyczny: na basic `knn`, `hybrid-bool` i `rrf-client` są **wykonalne**, ale wektor zapytania trzeba
policzyć poza ES (własny serwis z modelem), bo inference w ES wymaga trialu. Laboratorium liczy go w ES, więc
wszystkie warianty poza `bm25` potrzebują tu trialu. **RRF nie jest magią licencji**: `rrf-client` to ~30 linii PHP
(krok 8) i daje te same sumy co ES.

## Krok 8 — RRF policzone ręcznie

Reciprocal Rank Fusion ignoruje wartości `_score` i patrzy tylko na pozycje. Wynik dokumentu:

```
RRF(d) = Σ po listach  1 / (rank_constant + pozycja d w liście)        pozycje od 1, rank_constant = 60
```

Dokument nieobecny w liście nie dostaje z niej nic. Przykład z prawdziwego przebiegu, zapytanie
`Wyman-Howell Laptop Ultra 14"` (pozycje z `eval-14a.json`, gdzie `per_query[].top` ma top-10 id każdego
wariantu):

| Dokument | pozycja BM25 | pozycja kNN | RRF | miejsce `rrf-client` |
|---|---|---|---|---|
| 272625 | 1 | 1 | 1/61 + 1/61 = **0,032787** | 1 |
| 254550 | 2 | 2 | 1/62 + 1/62 = **0,032258** | 2 |
| 199620 | 3 | 3 | 1/63 + 1/63 = **0,031746** | 3 |
| 17625 | 7 | 9 | 1/67 + 1/69 = 0,014925 + 0,014493 = **0,029418** | 4 |
| 108240 | 9 | 10 | 1/69 + 1/70 = 0,014493 + 0,014286 = **0,028778** | 5 |
| 108075 | 4 | — | 1/64 = **0,015625** | 7 |
| 268725 | — | 4 | 1/64 = **0,015625** | 8 |

Trzy lekcje z tej tabeli:

1. **Zgoda wygrywa z siłą.** Dokument 17625 jest na 7. i 9. miejscu, a w fuzji wyprzedza 108075 z 4. miejsca BM25,
   bo jest w obu listach. Druga lista prawie podwaja wynik.
2. **Skala `_score` nie ma znaczenia.** BM25 daje tu wyniki rzędu dziesiątek, kNN poniżej 1. RRF tego nie widzi, i
   właśnie dlatego nie ma problemu `hybrid-bool` (krok 10).
3. **Remisy są wbudowane.** Pozycja 4 tylko w liście A (108075) daje dokładnie tę samą sumę co pozycja 4 tylko
   w liście B (268725). `rrf-client` rozstrzyga remis mniejszym id jako tekst („108075” < „268725”), więc 108075 jest
   7., a 268725 8. ES rozstrzyga remis wewnętrznym numerem dokumentu Lucene i w `rrf-native` kolejność jest odwrotna.

Kontrola `--check-rrf` porównuje na każdej pozycji top-10 sumę z ES (float32) z sumą z PHP (|Δ| ≤ 1e-6): **zielona
na 33/33 zapytaniach**. Kolejność różni się tylko wewnątrz remisów. Ale te remisy mają w 5 zapytaniach różne oceny
(`komórka`, „coś ciepłego na zimę”, „oświetlenie do ogrodu bez prądu”, „do pracy w podróży”, „odpoczynek na
tarasie”), stąd `rrf-client` ≠ `rrf-native` w tabeli (np. lexical 0,958 vs 0,966). Oba są deterministyczne
(1 shard, 1 segment); różnią się tylko regułą rozstrzygania remisu. RUNBOOK [#155](../RUNBOOK.md#155).

Uwaga o oknie: lista kNN ma tylko k = 10 pozycji (zapytanie `knn` zwraca k trafień), lista BM25 ma 100. Dokument
z dalekiej pozycji BM25 dostaje mało (1/160 = 0,00625 za pozycję 100), ale dostaje.

## Krok 9 — własne nDCG, bo `_rank_eval` nie przyjmuje retrieverów

`_rank_eval` (ETAP 7) liczy nDCG po stronie ES, ale każde żądanie w nim przyjmuje tylko `query`. Retriever `rrf` się
tam nie zmieści, a `rrf-client` w ogóle nie jest jednym zapytaniem ES. Żeby porównać wszystkie warianty tym samym
miernikiem, nDCG@10 liczy PHP (`Ndcg::atK`) dla każdego wariantu, z tym samym wzorem co `_rank_eval` metryki `dcg`
z `normalize: true`:

```
DCG@k  = Σ_{i=1..k} (2^ocena_i − 1) / log2(i + 1)
nDCG@k = DCG@k / DCG ideału
```

Własny licznik to ryzyko, więc pilnuje go kontrola: dla trzech wariantów wyrażalnych jako `query` (bm25, knn,
hybrid-bool) `--check-rank-eval` wysyła te same zapytania do `_rank_eval` i porównuje wynik z PHP z tolerancją
1e-6. **Zgodne na 33/33 zapytaniach** (`_rank_eval` przyjmuje zapytanie `knn` z `query_vector_builder` w
`request.query`).

**Znalezione na żywo: ES obcina ideał.** Na prawdziwych zapytaniach obie wersje licznika dawały te same liczby, bo
każdy wariant zwraca pełne 10 trafień. Różnicę złapała dopiero sonda: zapytanie `ids: [67230]` (jedno trafienie)
z ocenami {67230: 1, 12555: 3}. `_rank_eval` zwrócił **0,142857 = 1/7**, czyli ideał = sam najlepszy dokument
(2³ − 1 = 7), a nie oba oceniane (7 + 1/log2 3 ≈ 7,63, co dałoby ~0,131). ES liczy ideał z najlepszych ocen, ale
tylko do **min(k, liczba zwróconych trafień)**. `Ndcg` został poprawiony, żeby robić to samo (test „ideał obcięty do
liczby zwróconych wyników, jak w _rank_eval”).

Konsekwencja, o której trzeba pamiętać: **wariant, który zwraca mniej niż k trafień, nie jest karany za puste
pozycje**, tylko za kolejność i dobór tego, co wrócił. Zobaczymy to w demo `post_filter` (krok 13): 7 trafień
i nDCG 1,000. Co sprawdzić, gdy PHP i `_rank_eval` się rozjadą: RUNBOOK [#154](../RUNBOOK.md#154).

## Krok 10 — tabela wyników

`make lab-eval` (nDCG@10, średnia w grupie; 13 lexical, 12 descriptive, 8 exact). Stan kanoniczny
(`eval-14a.json`), przeliczony ponownie przy pisaniu tego przewodnika z identycznym wynikiem:

| Wariant | lexical | descriptive | exact |
|---|---|---|---|
| bm25 | **1,000** | 0,167 | **1,000** |
| knn | 0,950 | **0,693** | 0,972 |
| hybrid-bool | **1,000** | 0,515 | **1,000** |
| rrf-client | 0,958 | 0,612 | 0,975 |
| rrf-native | 0,966 | 0,594 | 0,975 |

Zapytania, które coś pokazują:

| Zapytanie (grupa) | bm25 | knn | hybrid-bool | rrf-client | rrf-native |
|---|---|---|---|---|---|
| `komórka` (lexical) | 1,000 | **0,351** | 1,000 | 0,452 | 0,555 |
| coś ciepłego na zimę (descriptive) | 0,000 | **1,000** | **0,000** | 0,555 | 0,445 |
| oświetlenie do ogrodu bez prądu | 0,000 | **1,000** | **0,000** | 0,544 | 0,445 |
| ćwiczenia w domu | 0,000 | 1,000 | 1,000 | 1,000 | 1,000 |
| ochrona przed deszczem | 0,000 | 1,000 | 1,000 | 1,000 | 1,000 |
| naprawy w domu | 0,000 | 1,000 | 1,000 | 1,000 | 1,000 |
| muzyka w podróży | 0,000 | 0,469 | 0,469 | 0,469 | 0,469 |
| prezent dla biegacza | 0,000 | 0,713 | 0,713 | 0,713 | 0,713 |
| odpoczynek na tarasie | 0,000 | 0,129 | 0,000 | 0,068 | 0,054 |
| do pracy w podróży | 0,000 | 0,000 | 0,000 | 0,000 | 0,000 |
| dojazd do pracy bez wysiłku | 0,000 | 0,000 | 0,000 | 0,000 | 0,000 |
| `Romagera Inc Rower górski Pro X 29"` (exact) | 1,000 | **0,779** | 1,000 | 0,801 | 0,801 |

Pozostałe zapytania (11 lexical, 2 descriptive, 7 exact) mają 1,000 we wszystkich wariantach.

**Dlaczego `hybrid-bool` zabija kNN.** `bool.should` sumuje wyniki. BM25 daje dziesiątki punktów, a zapytanie `knn`
z podobieństwem cosinusowym daje wynik poniżej 1 (w przestrzeni E5 typowo ~0,9). Gdy BM25 znajdzie cokolwiek, choćby
nietrafnie (pojedyncze słowo zapytania w nazwie innego produktu), jego wyniki przykrywają kNN całkowicie: „coś ciepłego
na zimę” i „oświetlenie do ogrodu bez prądu” spadają z 1,000 (knn) do 0,000, a „odpoczynek na tarasie” z 0,129
do 0,000 (na pierwszej budowie grafu do zera spadały cztery zapytania z 1,000). Suma ma sens tylko po wyrównaniu skal (wagi, normalizacja);
na basic ES innej mieszanki w jednym zapytaniu nie da. RRF patrzy na pozycje, więc ratuje połowę (0,44–0,56).

**Dlaczego tyle jedynek.** Reguły kategorii obejmują setki dokumentów próbki (np. ~1800 smartfonów z oceną 1),
więc ideał DCG@10 jest nasycony, a nDCG@10 = 1,000 znaczy „całe top-10 jest z właściwego typu produktu”. Tabela mierzy
więc trafienie w typ, a nie subtelny porządek wewnątrz typu.

**Zapytania nie do zrobienia dla żadnego wariantu.** „dojazd do pracy bez wysiłku” (oczekiwany rower elektryczny)
i „do pracy w podróży” (laptop) wymagają wiedzy o świecie, której nie ma ani w słowach, ani w krótkim tekście
„nazwa + kategoria”. Wektor E5 small też jej nie wnosi.

## Krok 11 — HNSW, `num_candidates` i dlaczego 0,801 było szczęściem

**HNSW w dwóch zdaniach.** Dokładny kNN porównuje wektor zapytania z każdym dokumentem (19 824 porównań; przy
1,17 mln za drogo). HNSW buduje graf „bliskich sąsiadów” warstwami i przy wyszukiwaniu idzie po nim zachłannie od
punktu wejścia; `num_candidates` to liczba kandydatów, których szuka na najniższej warstwie, zanim wybierze k
najlepszych. Więcej kandydatów to wolniej, ale bliżej dokładnego wyniku.

**Recall@10** (`make lab-eval args="--variant=knn --recall"`) porównuje top-10 HNSW na `vec_float` (k = 10,
`num_candidates` = 100) z dokładnym brute force (`script_score` z `cosineSimilarity` na `vec_float`, ten sam wektor
zapytania z `input_type: search`):

| Budowa grafu | recall@10 po id | z remisami na granicy | najgorsze zapytanie |
|---|---|---|---|
| pierwsza (po ładowaniu) | **0,952** | 0,955 | `komórka` **0,0** |
| obecna (po przywróceniu indeksu i merge) | **0,997** | 1,000 | — |

Przy `komórka` na pierwszej budowie HNSW utknął w lokalnym regionie grafu: najlepszy znaleziony wynik 0,9058 wobec
dokładnego 0,9074, zero wspólnych dokumentów z prawdziwym top-10. Przy `num_candidates` = 2000 zwracał dokładne
top-10. Przestrzeń E5 jest bardzo gęsta (prawie wszystkie podobieństwa ~0,90), więc drobne różnice w budowie grafu
przestawiają sąsiadów.

**Powtarzalność: per budowa indeksu, nie między budowami.** Dwa przebiegi na tym samym indeksie są identyczne co do
bitu. Ale demo filtra w pierwszej wersji oznaczało dokumenty jako niedostępne bezpośrednio w `products-lab-v1`
(`_update_by_query` + `force_merge`). Każda taka zmiana przebudowuje graf HNSW. Po przywróceniu indeksu do stanu
kanonicznego wiersze oparte na kNN przesunęły się, a bm25 nie drgnął:

| Wiersz (lexical / descriptive / exact) | pierwsza budowa (`eval-14a-pre-rebuild.json`) | obecna (`eval-14a.json`) |
|---|---|---|
| bm25 | 1,000 / 0,167 / 1,000 | bez zmian |
| knn | 0,950 / **0,801** / 0,972 | 0,950 / **0,693** / 0,972 |
| hybrid-bool | 1,000 / 0,539 / 1,000 | 1,000 / 0,515 / 1,000 |
| rrf-client | 0,958 / 0,677 / 0,975 | 0,958 / 0,612 / 0,975 |
| rrf-native | 0,966 / 0,656 / 0,975 | 0,966 / 0,594 / 0,975 |

Na pierwszej budowie „do pracy w podróży” i „prezent dla biegacza” miały w knn 1,000, na obecnej 0,000 i 0,713.
Od tej pory demo filtra pracuje na własnej kopii indeksu (krok 13), a kanoniczny jest stan obecny.

**Które 0,693 jest prawdziwe?** Pomiar z `--num-candidates` (obecna budowa, wariant knn, descriptive):

| Pole | 100 | 500 | 2000 |
|---|---|---|---|
| `semantic_e5` | 0,693 | 0,652 | 0,651 |
| `vec_bbq` | 0,689 | 0,651 | 0,651 |
| `vec_int8` | 0,714 | 0,709 | 0,709 |
| `vec_float` | 0,709 | 0,709 | 0,709 |

`vec_float` nie zależy od `num_candidates`: HNSW przy 100 kandydatach już znajduje to, co dokładne przeszukanie.
`semantic_e5` przy większej liczbie kandydatów **spada** i ustala się na 0,651. Więcej kandydatów nie poprawia
trafności, tylko zbliża wynik do dokładnego przeszukania tego pola. Wniosek: 0,801 z pierwszej budowy (i 0,693 przy
100 na obecnej) to rozrzut przybliżenia, który akurat trafił w dokumenty dobrze ocenione. Recall i nDCG to różne
rzeczy: przybliżony wynik może przypadkiem być trafniejszy niż dokładny. Stabilna jest liczba ~0,65 dla
`semantic_e5` i ~0,71 dla `vec_float`. Skąd ta różnica, wyjaśnia krok 12.

## Krok 12 — kwantyzacja: dysk, pamięć, jakość

**Co to jest.** Wektor float32 o 384 wymiarach to 1536 bajtów. Kwantyzacja trzyma obok niego wersję zgrubną do
nawigowania po grafie: `int8_hnsw` 1 bajt na wymiar (384 B), `bbq_hnsw` (Better Binary Quantization) ~1 bit na
wymiar (48 B) plus poprawki. Zgrubna wersja ma się zmieścić w pamięci (page cache), a pełny wektor służy do
przeliczenia (rescore) najlepszych kandydatów. `bbq_hnsw` w 9.5 domyślnie ma `rescore_vector.oversample: 3.0`: bierze
k × 3 kandydatów po wyniku zgrubnym i przelicza je na pełnych wektorach.

**Co wybiera `semantic_text`.** W mapowaniu indeksu (`GET products-lab-v1/_mapping`) `semantic_e5` pokazuje tylko
`model_settings` (384, cosine, float). Prawda jest w polu wewnętrznym, widocznym przez `include_defaults`:

```bash
GET products-lab-v1/_mapping/field/semantic_e5.*?include_defaults=true
```
```json
"semantic_e5.inference.chunks.embeddings": {
  "type": "dense_vector", "element_type": "bfloat16", "similarity": "cosine",
  "index_options": { "type": "bbq_hnsw", "m": 16, "ef_construction": 100, "rescore_vector": { "oversample": 3.0 } }
}
```

Czyli w ES 9.5.1 `semantic_text` na modelu gęstym domyślnie **przechowuje wektory jako bfloat16 (2 bajty na wymiar)
i indeksuje je jako `bbq_hnsw`**. To wyjaśnia wszystkie trzy obserwacje: `semantic_e5` przy dużym `num_candidates`
ustala się dokładnie na tym samym wyniku co `vec_bbq` (0,923 / 0,651 / 0,972), jest tak samo wrażliwy na przebudowę
grafu jak `vec_bbq` (descriptive 0,801 → 0,774 → 0,693 przez trzy budowy, `vec_bbq` 0,793 → 0,772 → 0,689),
a `vec_float` i `vec_int8` są stabilne. W gęstej przestrzeni E5 1-bitowe przybliżenie myli kolejność na tyle, że
top-30 do przeliczenia nie zawsze zawiera właściwe dokumenty: na zapytaniach opisowych bbq traci ~0,06 nDCG względem
float. Gdyby to miało znaczenie, `semantic_text` przyjmuje własne `index_options` (np. `int8_hnsw`).

**Dysk** (`make lab-costs`, `_disk_usage?run_expensive_tasks=true`, 19 824 dokumenty, 1 segment):

| Pole | MB | Co jest na dysku |
|---|---|---|
| `vec_float` | 29,5 | float32 + graf |
| `vec_int8` | **37,0** | float32 + kopia int8 + graf |
| `vec_bbq` | 30,8 | float32 + bity + graf |
| `semantic_e5` | 16,3 | bfloat16 + bity + graf (~862 B na wektor) |
| `name` (z podpolami) | 1,2 | |
| cały indeks | 119,7 | ten sam indeks bez pól wektorowych: 5,6 MB |

Kwantyzacja **nie oszczędza dysku**: pełny wektor zostaje (do rescore i do ponownej kwantyzacji przy merge), więc
`int8` jest największe. Oszczędza RAM potrzebny do szybkiego przeszukania grafu. Oszczędność dysku daje dopiero
mniejszy typ przechowywania, jak bfloat16 w `semantic_text`.

**Pamięć.** `_stats` z sekcją `dense_vector` w ES 9.5 raportuje pamięć poza stertą tylko sumarycznie (119 MB: surowe
wektory 106,6 MB, int8 7,9 MB, bity bbq 2,5 MB, graf 2,2 MB), bez podziału na pola. `_nodes/stats/indices/segments`
pokazuje `memory_in_bytes: 0`: wektorów nie liczy w ogóle. Nie wierz temu zeru przy planowaniu pamięci.

**Ekstrapolacja na 1 171 554 dokumenty** (mnożnik 59,1). To **rachunek, nie pomiar**, przy założeniu liniowości:
`vec_float` ~1,70 GB, `int8` ~2,14 GB, `bbq` ~1,78 GB, `semantic_e5` ~0,94 GB, cały indeks z czterema polami
~6,9 GB; ładowanie z wektorami na tym sprzęcie (jeden wątek inferencji) ~14,7 h.

## Krok 13 — filtrowany kNN: filtr w `knn` kontra `post_filter`

Klient szuka „coś ciepłego na zimę” i chce tylko dostępne produkty. Są dwa miejsca na filtr:

```json
// b) filtr WEWNĄTRZ knn: ES szuka k najbliższych WŚRÓD pasujących do filtra
{"query": {"knn": {"field": "vec_float", "k": 10, "num_candidates": 100, "query_vector_builder": {…},
                   "filter": {"term": {"in_stock": true}}}}}

// c) post_filter: ES wybiera k najbliższych spośród WSZYSTKICH, potem wyrzuca niedostępne
{"query": {"knn": {…bez filtra…}}, "post_filter": {"term": {"in_stock": true}}}
```

W próbce jest tylko 1 niedostępny produkt, więc `make lab-filter-demo` buduje **własny, jednorazowy indeks**
`products-lab-filter`: `_reindex` z `products-lab-v1` z `pipeline: _none` (wektory jadą z `_source`, bez ponownej
inferencji), oznacza co trzeci dokument próbki (`(id / 15) % 3 = 0`, razem 6620) jako `in_stock: false`, robi
`force_merge` i na koniec indeks kasuje. `products-lab-v1` zostaje nietknięty, żeby nie przebudować jego grafu
(krok 11). Kopia nie ma `semantic_text`, więc demo używa pola `vec_float`.

Pierwsza próba oznaczała `id % 3 = 0`. Id próbki to wielokrotności 15, czyli wszystkie są podzielne przez 3: cała
próbka stała się niedostępna. Stąd `(id / M) % 3`.

| Wariant | trafień | niedostępnych | nDCG@10 |
|---|---|---|---|
| a) knn bez filtra | 10 | 3 | 1,000 |
| b) filtr w `knn` | **10** | 0 | 1,000 |
| c) `post_filter` | **7** | 0 | 1,000 |

`post_filter` działa po wyborze k sąsiadów: z 10 najbliższych 3 były niedostępne, więc zostało 7. Klient dostaje
niepełną stronę, a przy bardziej selektywnym filtrze (np. jedna marka) mógłby dostać zero, mimo że pasujące produkty
istnieją. Filtr w `knn` jest stosowany w trakcie przeszukiwania grafu i zawsze daje k pasujących (o ile tyle ich
jest). Zasada: **filtr kNN zawsze w `knn.filter`**; `post_filter` zostaw do facetów, gdzie właśnie chcesz, żeby
agregacje widziały wyniki sprzed filtra.

nDCG 1,000 we wszystkich trzech nie jest pomyłką. Oceny nie zależą od dostępności, każdy z wyników jest kurtką
zimową, a nDCG z obciętym ideałem (krok 9) nie karze za brakujące 3 pozycje. To dobry przykład, że jedna metryka
nie wystarcza: tu różnicuje liczba trafień, nie nDCG.

## Krok 14 — latencja i cache inferencji

`make lab-eval args="--latency=50"`: 5 żądań rozgrzewkowych, potem 50 przebiegów × 33 zapytania = 1650 próbek na
wariant (pierwsza budowa grafu; ms):

| Wariant | `took` p50 | `took` p99 | PHP p50 | PHP p99 |
|---|---|---|---|---|
| bm25 | 0 | 1 | 1,3 | 2,8 |
| knn | 1 | 2 | 1,7 | 4,8 |
| hybrid-bool | 1 | 3 | 2,2 | 4,8 |
| rrf-client | 2 | 4 | 3,8 | 7,2 |
| rrf-native | 2 | 4 | 3,0 | 5,4 |
| inference (z cache) | – | – | 0,8 | 1,1 |
| **inference bez cache** | – | – | **78,4** | **117,7** |

(`took` dla `rrf-client` to suma dwóch żądań.) Pułapka pomiaru: wdrożenie modelu ma **cache inferencji** (448 MB;
w statystykach 121 140 trafień w cache na 128 447 wywołań). Powtarzane zapytanie dostaje gotowy wektor, więc wiersze
wariantów pokazują koszt kNN **z wektorem z cache**. Wiersz „bez cache” dokleja do tekstu unikalny znacznik, żeby
wymusić liczenie od nowa. Prawdziwy koszt nowego zapytania kNN to mniej więcej `knn` + ~78 ms (p50) na jednym wątku
CPU. Samo przeszukanie grafu jest tanie; drogi jest model.

`num_candidates` 100 → 2000 podnosi `took` p99 z 1–2 do 5–8 ms (krok 11), bez zysku w trafności.

## Kiedy wektory szkodzą, a kiedy pomagają

**Grupa `exact` (8 zapytań).** bm25 1,000, hybrid-bool 1,000, knn 0,972, RRF 0,975. Siedem z ośmiu zapytań
(pełne nazwy z marką, same marki `Wolff Inc` / `Bosco PLC`, model z pojemnością `Nova 256GB` / `Zenit 128GB`) ma
1,000 we **wszystkich** wariantach. Wektory niczego tu nie dodają, ale też nie psują: tekst dla modelu to krótka nazwa
z kategorią, więc pełna nazwa w zapytaniu jest bardzo blisko wektora właściwych produktów.

Całą stratę knn w tej grupie robi jedno zapytanie, literówka `Romagera Inc Rower górski Pro X 29"`: knn 0,779,
RRF 0,801. Top-10 knn wygląda tak: 1. „Romaguera Inc Rower górski Pro X 29"” (dokładnie ten produkt, mimo literówki),
2–5. „Romaguera-Rippin Rower górski Pro X 29"”, 6–8. „Romaguera Inc Rower górski **Ultra** 29"”, 9–10. „Jones-Gerhold
Rower górski Pro X 29"”. Wektor poprawił literówkę i trzymał się marki, BM25 trzymał się modelu. Przy pierwotnej
ocenie (3 tylko dla Romaguera Inc Pro X) knn miał na tym zapytaniu 1,000, a bm25 ~0,63. Po zmianie oceny (krok 5,
decyzja podjęta po zobaczeniu wyników) knn ma 0,779, bo „Ultra” na pozycjach 6–8 to inny model. **Dowód „wektory
szkodzą na dokładnych zapytaniach” opiera się więc na jednym zapytaniu, którego ocena zmieniła się po fakcie.**
Uczciwa odpowiedź z grupy `exact`: na tych danych wektory nie dają nic i tracą niewiele (0,028), a na literówce w
marce mogą być wręcz lepsze. Kodów SKU, EAN i numerów części, gdzie szkoda byłaby największa, ten katalog nie ma
i ich nie mierzyliśmy.

**Gdzie wektory szkodzą naprawdę (z liczbami):**

1. **Słowa, które zna słownik synonimów, a nie zna model.** `komórka` (lexical): bm25 1,000 dzięki synonimowi
   „telefon, smartfon, komórka”, knn **0,351**. Top-10 knn to pół na pół laptopy „Ratke, Mohr and Thiel Ultrabook
   Nova 13"” i smartfony tej samej marki. Jedno krótkie słowo daje wektor, który ląduje w przypadkowym rejonie
   przestrzeni; na pierwszej budowie grafu HNSW zgubił tu nawet dokładnych sąsiadów (recall 0). RRF ciągnie to
   zapytanie w dół do 0,45–0,56, choć BM25 było idealne.
2. **Naiwna mieszanka.** `hybrid-bool` daje w descriptive **0,515**, mniej niż sam knn (0,693): dwa zapytania
   opisowe spadają z 1,000 do 0,000, a „odpoczynek na tarasie” z 0,129 do 0,000, bo skala BM25 przykrywa kNN
   (krok 10). Hybryda nie jest automatycznie
   „najlepsza z obu światów”.
3. **Niestabilność.** Ten sam indeks po przebudowie grafu: knn descriptive 0,801 → 0,693, a przy 500+ kandydatach
   0,651 (domyślne bbq w `semantic_text`). BM25 przez wszystkie budowy: 0,167, co do bitu.
4. **Koszt.** Ładowanie 896 s zamiast ~4 s (~200 razy dłużej), indeks 120 MB zamiast 5,6 MB, model ~1,5 GB pamięci
   natywnej, trial albo własny serwis z modelem, ~78 ms na nowe zapytanie. Na 1,17 mln dokumentów: ~6,9 GB
   i ~15 h ładowania (rachunek).

**Gdzie wektory pomagają (grupa `descriptive`, 12 zapytań).** bm25 **0,167** (trafia tylko 2 z 12: „jazda po górach”
i „telefon z dobrym aparatem”, gdzie słowo zapytania pasuje do nazwy lub synonimu), knn **0,69–0,71** zależnie od pola
i budowy grafu (stabilnie ~0,65 na `semantic_e5`, ~0,71 na `vec_float`). Na 7 z 12 zapytań opisowych knn ma 1,000
(„coś ciepłego na zimę”, „oświetlenie do ogrodu bez prądu”, „naprawy w domu”, „ochrona przed deszczem”…), z czego
5 to zapytania, na których bm25 ma 0,000. To jest
różnica czterokrotna i to jedyny powód, żeby płacić koszt z punktu 4.

**Wniosek dla sklepu.** Wektory są narzędziem do zapytań opisowych, nie zamiennikiem BM25. Rozsądny tryb mieszany to
fuzja po pozycjach (RRF) albo przełącznik: BM25, gdy zapytanie trafia słownikiem (dokładna nazwa, marka, synonim),
kNN z filtrem w `knn`, gdy BM25 nie znajduje nic sensownego. Ta decyzja jest poza zakresem laboratorium i wymaga
osobnego pomiaru na prawdziwym ruchu.

## Liczby z laboratorium

| Co | Wartość |
|---|---|
| Próbka | 19 824 produkty (`id % 15 = 0` z 297 360 w bazie katalogu), 1 shard, 1 segment |
| Ładowanie z wektorami / bez | 896 s / ~4 s |
| Węzeł | `es-lab`, heap 1,5 GB, limit 4 GB, model 470 MB (~1,5 GB pamięci natywnej) |
| nDCG@10 descriptive: bm25 / knn / hybrid-bool / rrf-c / rrf-n | 0,167 / 0,693 / 0,515 / 0,612 / 0,594 |
| nDCG@10 exact: bm25 / knn / rrf | 1,000 / 0,972 / 0,975 |
| nDCG@10 `komórka`: bm25 / knn | 1,000 / 0,351 |
| PHP vs `_rank_eval` | 33/33 zgodne (1e-6) |
| `rrf-client` vs `rrf-native` | sumy zgodne 33/33, kolejność różna tylko w remisach |
| recall@10 HNSW (`vec_float`, 100 kandydatów) | 0,952 (pierwsza budowa), 0,997 (obecna) |
| Dysk: float / int8 / bbq / `semantic_e5` | 29,5 / 37,0 / 30,8 / 16,3 MB |
| Filtr: bez / w `knn` / `post_filter` | 10 (3 niedostępne) / 10 / 7 trafień |
| Inference bez cache | p50 78 ms, p99 118 ms |

Więcej: [`POMIARY.md` §18](../POMIARY.md).

## Ograniczenia

- **Wyniki kNN są powtarzalne tylko w obrębie jednej budowy indeksu.** Każde `_update_by_query` + merge albo
  ponowne ładowanie przebudowuje graf i może przesunąć descriptive o ~0,1. BM25 jest stabilny.
- **Oceny są zgrubne.** Reguły kategorii obejmują setki dokumentów, więc nDCG@10 nasyca się na „właściwym typie
  produktu” i nie widzi porządku wewnątrz typu.
- **Obcięty ideał nie karze za mniej niż k wyników** (jak `_rank_eval`). Demo `post_filter` ma przez to nDCG 1,000
  przy 7 trafieniach.
- **Zapytanie z literówką zostało przeocenione po zobaczeniu wyników** (krok 5). Grupa `exact` jest przez to nieco
  łatwiejsza dla BM25, a jedyna strata wektorów w tej grupie pochodzi z tego zapytania.
- **Remisy RRF** rozstrzygane są inaczej w PHP (mniejsze id) i w ES (numer dokumentu Lucene); w 5 zapytaniach zmienia
  to nDCG.
- **Latencja kNN w tabeli jest z wektorem z cache inferencji.** Koszt nowego zapytania to wiersz „bez cache”.
  Latencję mierzono na pierwszej budowie grafu.
- **Mała próbka, syntetyczne dane.** 19 824 dokumenty, ~11 kategorii liści, nazwy z Fakera. Ekstrapolacja na
  1,17 mln to rachunek.
- **Treść komunikatu 403 na basic nie jest zacytowana**: laboratorium działa na trialu, a główny klaster w trybie lab
  jest zatrzymany.

## Co wybuchło po drodze

| RUNBOOK | Objaw |
|---|---|
| [#152](../RUNBOOK.md#152) | trial wygasł po 30 dniach: inference i `rrf` dostają 403, `start_trial` odmawia |
| [#153](../RUNBOOK.md#153) | model E5 się nie wdraża: OOM-kill przy imporcie, niekompletny model, brak pamięci ML |
| [#154](../RUNBOOK.md#154) | nDCG z PHP ≠ `_rank_eval` |
| [#155](../RUNBOOK.md#155) | `rrf-client` ≠ `rrf-native` w tabeli albo `--check-rrf` czerwone |

## Ściąga

```bash
# jednorazowo
grep -q '^ES_LAB_PASSWORD=.' .env || echo "ES_LAB_PASSWORD=$(openssl rand -hex 16)" >> .env

# od zera
make lab-export                      # tryb normalny
make lab-mode-on && make lab-up
make lab-setup                       # trial, lab-e5, pipeline, indeks, ładowanie (~15 min)

# pomiary
make lab-eval                                                     # tabela 5×3 + per zapytanie
make lab-eval args="--check-rank-eval --check-rrf --json=lab/eval-14a.json"
make lab-eval args="--group=descriptive --variant=knn --variant=bm25"
make lab-eval args="--variant=knn --recall"
make lab-eval args="--variant=knn --num-candidates=2000"
make lab-eval args="--variant=knn --vector-field=vec_bbq"         # vec_float | vec_int8 | vec_bbq
make lab-eval args="--latency=50 --json=lab/latency-14a.json"
make lab-costs
make lab-filter-demo

# zapytania ręcznie (hasło z .env przez curl -K, nie pojawia się na ekranie)
source tools/lab/lib.sh
lab_es "${LAB_URL}/_license" | jq .license.type
lab_es "${LAB_URL}/products-lab-v1/_count"
lab_es "${LAB_URL}/products-lab-v1/_mapping/field/semantic_e5.*?include_defaults=true" | jq
lab_es -H 'Content-Type: application/json' "${LAB_URL}/_inference/text_embedding/lab-e5" \
  -d '{"input":"coś ciepłego na zimę","input_type":"search"}' | jq '.text_embedding[0].embedding | length'
lab_es "${LAB_URL}/_ml/trained_models/_stats" | jq '.trained_model_stats[] | select(.deployment_stats) | .model_size_stats'

# koniec
make lab-mode-off
make lab-down                        # sam es-lab stop, dane zostają w wolumenie
make lab-reset CONFIRM=1             # kasuje wolumen (po wygaśnięciu trialu)
```
