# 03 — Ścieżka nauki: Elasticsearch od zera do diagnozowania produkcji

Format każdego modułu:
- **Cel** — co będziesz umiał po module
- **Teoria** — co to jest i *skąd się bierze* (mechanizm, nie zaklęcie)
- **W projekcie** — co konkretnie budujemy
- **Ćwiczenia** — w tym zawsze co najmniej jedno **„zepsuj i napraw"**
- **Sprawdź się** — pytania, na które musisz umieć odpowiedzieć bez zaglądania

Moduły 1–5 to fundament — bez nich reszta to kopiowanie snippetów.
Realny czas: 1 moduł = 1–3 wieczory. Całość ≈ 2–3 miesiące spokojnej nauki.

> **Skala danych w ćwiczeniach.** Docelowa maszyna ma 32 GB RAM, więc od modułu 4 pracujemy
> na **5 mln produktów / ~15 mln ofert**, a nie na zabawkowym zbiorze. To nie jest kaprys:
> przy 10 tys. dokumentów *wszystko* jest szybkie, `from=9000` działa, złe mapowanie nie boli,
> a `force_merge` nic nie zmienia. Różnicę między dobrą a złą decyzją widać dopiero w skali.
> Od modułu 2 pracujemy też na **klastrze 3-nodowym z replikami**, bo połowa realnych
> problemów ES (rebalans, score per shard, alokacja) w single-node nie istnieje.

---

## MODUŁ 1 — Jak Elasticsearch działa w środku

**Cel:** rozumiesz, dlaczego ES jest szybki i jakie są tego konsekwencje.

**Teoria**

1. **Lucene** — ES to rozproszona nakładka na bibliotekę Lucene. Cała „magia"
   wyszukiwania dzieje się w Lucene; ES dodaje klaster, REST, JSON, agregacje.
2. **Odwrócony indeks (inverted index)** — zamiast „dokument → słowa" trzymamy
   „słowo → lista dokumentów". Dlatego szukanie słowa jest O(1)-ish, a nie skanem.
   Narysuj to sobie na kartce dla 3 zdań — serio, to 10 minut, które ustawia wszystko.
3. **Segment** — niemutowalny fragment indeksu na dysku. Nowe dokumenty trafiają do
   nowego segmentu. **Nic nigdy nie jest nadpisywane** — update = oznaczenie starego
   dokumentu jako usuniętego (tombstone) + zapis nowego.
   → stąd bierze się „ES puchnie", dopóki merge nie posprząta.
4. **Refresh** (domyślnie 1 s) — bufor w pamięci staje się segmentem *widocznym dla
   wyszukiwania*. To jest źródło „near real-time". Nie ma nic wspólnego z trwałością.
5. **Translog + flush** — trwałość. Każdy zapis idzie do translogu (fsync domyślnie co
   request). Flush = segmenty na dysk + wyczyszczenie translogu.
6. **Merge** — segmenty są łączone w tle; wtedy fizycznie znikają usunięte dokumenty.
   `force_merge` robi to na żądanie (tylko dla indeksów, do których już nie piszesz!).
7. **Shard** = jeden indeks Lucene. **Primary** przyjmuje zapis, **replica** go kopiuje
   i obsługuje odczyty. Liczba primary shardów jest **niezmienna po utworzeniu indeksu**
   (stąd `split`/`shrink`/reindex).
8. **doc_values** — kolumnowa struktura na dysku dla sortowania i agregacji.
   `fielddata` (dla `text`) to jej pamięciożerny odpowiednik w heapie — źródło klasycznych
   OOM-ów. Domyślnie wyłączone i zostaw to tak.
9. **Node roles**: master (stan klastra), data (dane), ingest (pipeline'y),
   coordinating (rozsyła zapytania i scala wyniki), ml. W małym klastrze jeden node robi
   wszystko — w dużym rozdziela się je świadomie.

**W projekcie:** faza I-2 i I-9 z `01-INFRASTRUKTURA.md`.

**Ćwiczenia**
1. Zaindeksuj 1 dokument, natychmiast go wyszukaj → brak wyniku. Dodaj `?refresh=true` →
   jest. Wyjaśnij różnicę własnymi słowami.
2. `GET _cat/segments/products?v` — zaindeksuj 1000 dokumentów, obserwuj liczbę segmentów.
   Zrób `POST products/_forcemerge?max_num_segments=1` i porównaj rozmiar.
3. Zaktualizuj 500 dokumentów, sprawdź `_cat/indices?v&h=index,docs.count,docs.deleted`.
   Wyjaśnij `docs.deleted`.
4. **Zepsuj i napraw:** ustaw `refresh_interval: -1`, zaindeksuj dane, dziw się, że
   wyszukiwanie nic nie zwraca. Znajdź przyczynę bez podpowiedzi.

**Sprawdź się**
- Dlaczego indeks „waży" 3× więcej niż dane w Postgresie?
- Co się stanie z zapisem, jeśli node padnie 0,5 s po `200 OK`?
- Dlaczego nie można zmienić liczby primary shardów?

---

## MODUŁ 2 — Klaster, shardy, zdrowie

**Cel:** czytasz stan klastra jak lekarz EKG i wiesz, co zrobić przy yellow/red.

**Teoria**
- `green` / `yellow` / `red` — kolor mówi wyłącznie o **przypisaniu shardów**:
  yellow = wszystkie primary OK, brakuje replik; red = brakuje przynajmniej jednej primary
  (część danych **niedostępna**).
- Allocation: decydery (disk watermarks, awareness, filtering, same-shard rule).
- **Disk watermarks**: `low 85%` (nie przydzielaj nowych), `high 90%` (przenieś stąd),
  `flood_stage 95%` → **indeksy przechodzą w read-only**. To jest przyczyna nr 1 nocnych
  telefonów: „nie da się zapisać do ES".
- Elekcja mastera i quorum (`voting_configuration`) — dlaczego 2 node'y to najgorsza
  możliwa liczba, a 3 to minimum dla HA.

**Narzędzia (naucz się ich na pamięć):**
```
GET _cluster/health?pretty
GET _cluster/health?level=indices        # który indeks psuje kolor
GET _cat/nodes?v&h=name,node.role,heap.percent,ram.percent,cpu,load_1m,disk.used_percent
GET _cat/indices?v&s=store.size:desc&h=health,index,pri,rep,docs.count,store.size
GET _cat/shards?v&s=state                # UNASSIGNED na górze
GET _cluster/allocation/explain          # ← DLACZEGO shard nie jest przypisany
GET _cat/allocation?v
GET _cat/pending_tasks?v
GET _cluster/settings?include_defaults=true&flat_settings=true
```

**Ćwiczenia**
1. Postaw profil `cluster`, zobacz `green`. `docker stop es02` → obserwuj przejście
   yellow → rebalans → yellow/green. Czytaj `_cat/shards` co 5 s.
2. Stwórz indeks z `number_of_replicas: 5` na 3-nodowym klastrze. Klaster: yellow.
   Uruchom `_cluster/allocation/explain` i **przeczytaj ze zrozumieniem** komunikat.
3. **Zepsuj i napraw:** wypełnij dysk VM (`fallocate` w kontenerze) powyżej 95 %,
   spróbuj zaindeksować → `cluster_block_exception ... read-only-allow-delete`.
   Napraw: zwolnij miejsce + `PUT _all/_settings {"index.blocks.read_only_allow_delete": null}`.
4. Wyłącz 2 z 3 node'ów → klaster traci quorum. Zobacz, jak wyglądają logi mastera.

**Sprawdź się**
- Klaster jest red o 3 w nocy. Wymień 5 komend w kolejności.
- Yellow na produkcji z 1 nodem — problem czy nie?
- Kiedy `_cluster/reroute?retry_failed=true` jest właściwą odpowiedzią?

---

## MODUŁ 3 — Analiza tekstu (tu wygrywa się wyszukiwarkę)

**Cel:** rozumiesz, dlaczego zapytanie nie znajduje dokumentu, który „przecież tam jest".

**Teoria**
- Łańcuch: **character filters → tokenizer → token filters**.
- `text` vs `keyword` — **najważniejsze rozróżnienie w całym ES**:
  `text` jest analizowany (dzielony na tokeny, do wyszukiwania pełnotekstowego),
  `keyword` nie jest (jeden token, do filtrów, agregacji, sortowania).
  Multi-field (`name` + `name.raw`) daje jedno i drugie.
- Analizator **indeksowania** ≠ analizator **wyszukiwania**. Synonimy zwykle tylko przy
  wyszukiwaniu (bo inaczej przy zmianie synonimów trzeba reindeksować).
- Polski: `analysis-stempel` (`polish_stem`, `polish_analyzer`), `asciifolding`
  (ą→a, ł→l), `_polish_` stopwords, `analysis-icu` do sortowania i normalizacji.
- ngram vs edge_ngram vs `search_as_you_type` vs completion suggester — cztery różne
  narzędzia do „podpowiedzi", z różnym kosztem indeksu.
- `normalizer` — jak analyzer, ale dla `keyword` (np. case-insensitive filtry).

**Narzędzie diagnostyczne nr 1:**
```
POST _analyze
{ "analyzer": "pl_index", "text": "Butów do biegania Adidasa" }

POST products/_analyze
{ "field": "name", "text": "Butów" }        # analizator faktycznie użyty przez pole

GET products/_termvectors/1?fields=name     # co REALNIE leży w indeksie dla dokumentu
```

**W projekcie:** budujemy `pl_index` / `pl_search` / `pl_autocomplete` z `02-APLIKACJE.md`.

**Ćwiczenia**
1. Porównaj `standard` i `polish_analyzer` na „Kupiłem najlepsze buty do biegania".
2. Dodaj `asciifolding` i sprawdź, czy „lodz" znajduje „Łódź". Potem usuń i zobacz porażkę.
3. Zbuduj synonimy `laptop, notebook, komputer przenośny` jako `synonym_graph`.
   Sprawdź, dlaczego wielowyrazowe synonimy wymagają `synonym_graph`, a nie `synonym`.
4. **Zepsuj i napraw:** zaindeksuj pole jako `text` i spróbuj po nim sortować →
   `Fielddata is disabled on text fields by default`. Wyjaśnij komunikat i napraw
   multi-fieldem, nie włączaniem fielddata.
5. Porównaj rozmiar indeksu z `edge_ngram(2,20)` i bez. Wyciągnij wniosek o koszcie.

**Sprawdź się**
- Kiedy `keyword`, a kiedy `text`? Podaj po 3 przykłady pól z naszego modelu.
- Dlaczego zmiana `search_analyzer` nie wymaga reindeksu, a `analyzer` — tak?
- Czym różni się `match_phrase` przy analizatorze z ngramami (pułapka!)?

---

## MODUŁ 4 — Mapowania i modelowanie dokumentu

**Cel:** projektujesz mapowanie świadomie i wiesz, co można zmienić bez reindeksu.

**Teoria**
- Dynamic mapping: `true` / `runtime` / `false` / `strict`. Dlaczego `strict` w produkcji.
- **Mapping explosion** — 10 tys. pól z dynamicznych kluczy JSON → stan klastra rośnie,
  master pada. Ratunek: `flattened`, limity `index.mapping.total_fields.limit`.
- Typy: `keyword`, `text`, liczby (`integer` vs `scaled_float` vs `half_float` —
  precyzja vs rozmiar), `date` (formaty!), `boolean`, `ip`, `geo_point`, `dense_vector`,
  `rank_feature`, `flattened`, `object` vs `nested` vs `join`.
- **`object` vs `nested`** — najczęstszy błąd. Tablica obiektów spłaszcza się do tablic
  wartości i `{"seller":"Jan","price":100}` przestaje być powiązane. `nested` tworzy
  ukryte dokumenty (koszt!), `join` (parent/child) — jeszcze większy koszt zapytań,
  ale tańsza aktualizacja dziecka.
- Co **można** zmienić bez reindeksu: dodać nowe pole, dodać multi-field, zmienić
  `search_analyzer`, `ignore_above`, ustawienia dynamiczne. Czego **nie można**: typu pola,
  `analyzer`, liczby shardów. → dlatego aliasy od dnia 1.
- **Runtime fields** — pola liczone przy zapytaniu (schema on read). Ratunek, gdy
  potrzebujesz nowego pola *natychmiast*, bez reindeksu; koszt płacisz przy każdym query.
- Index templates + component templates — jak zarządzać mapowaniami dla `logs-*`.

**Ćwiczenia**
1. Zaindeksuj produkt z tablicą ofert jako `object`. Zapytaj o „oferta sprzedawcy Jan
   w cenie 100" → dostajesz fałszywe trafienia. Zamień na `nested` i porównaj.
2. Zmierz różnicę w rozmiarze i czasie zapytania `object` vs `nested` vs `collapse`
   na 100 tys. dokumentów.
3. **Zepsuj i napraw:** wyślij dokument z polem `price: "1200 PLN"` do pola `scaled_float`
   → `mapper_parsing_exception`. Napraw dwiema drogami: ingest pipeline (`convert`)
   i poprawka po stronie producenta. Uzasadnij, która jest lepsza.
4. Dodaj runtime field `price_with_vat` i porównaj czas zapytania z polem indeksowanym.
5. Ustaw `total_fields.limit: 20` i wyślij dokument z 50 dynamicznymi atrybutami →
   zobacz `illegal_argument_exception`. Rozwiąż przez `flattened`.

**Sprawdź się**
- Masz 5 mln produktów, każdy z 30 ofertami. `nested` czy osobny indeks ofert? Uzasadnij.
- Klient chce dodać pole `promo_until` i filtrować po nim **dziś**. Co robisz?

---

## MODUŁ 5 — Indeksowanie danych

**Cel:** indeksujesz szybko, bezpiecznie i idempotentnie.

**Teoria**
- `PUT /index/_doc/{id}` vs `POST /index/_doc` vs `_create` vs `_update` vs `_update` ze
  skryptem vs upsert. Kiedy co.
- **Optimistic concurrency**: `_seq_no` + `_primary_term`, `if_seq_no`/`if_primary_term`.
- **External versioning** (`version_type=external`) — nasz mechanizm na out-of-order
  eventy z RabbitMQ. `409 version_conflict_engine_exception` to **sukces**, nie błąd.
- **Bulk API** — format NDJSON, rozmiar batcha (celuj w 5–15 MB / 1–5 tys. dokumentów),
  **odpowiedź częściowo błędna** (`"errors": true` przy HTTP 200! — najczęściej ignorowany
  fakt, przez który dane cicho giną).
- `refresh` param: `false` / `true` / `wait_for` — i dlaczego `refresh=true` w pętli
  to zabójstwo klastra.
- **Ingest pipelines**: `set`, `rename`, `convert`, `grok`, `dissect`, `script`,
  `enrich`, `on_failure`. Kiedy transformować w ingest node, a kiedy w aplikacji.
- Backpressure: `429 es_rejected_execution_exception` = przepełniona kolejka write.
  Reakcja: **zwolnij i ponów z backoffem**, nie zwiększaj wątków.

**W projekcie:** handler bulkowy w Symfony + backfill **5 mln** produktów. To już jest
skala, na której czas indeksacji liczy się w dziesiątkach minut i każde ustawienie widać
w liczbach — dokładnie o to chodzi.

**Ćwiczenia**
1. Zaindeksuj 1 mln dokumentów: (a) pojedynczo, (b) bulk 1000. Zmierz czas.
   Powtórz (b) z `refresh_interval: -1` i `replicas: 0`. Zapisz trzy liczby w runbooku.
2. Napisz świadomie błędny bulk (jeden dokument z błędem typu) i sprawdź, że HTTP to 200.
   Dopisz w kodzie obsługę per-item errors.
3. Wyślij event ze starszym `sequence` po nowszym → zobacz 409 i zweryfikuj, że dokument
   **nie** został cofnięty.
4. **Zepsuj i napraw:** odpal 8 konsumentów z prefetch 500 → dostaniesz 429.
   Zdiagnozuj przez `GET _nodes/stats/thread_pool?filter_path=**.write` i napraw.
5. Zbuduj ingest pipeline, który normalizuje `brand` i wylicza `indexed_at`; dodaj
   `on_failure` odkładający wadliwe dokumenty do `products-failed`.

**Sprawdź się**
- Bulk zwrócił 200. Skąd wiesz, że wszystko się zapisało?
- Jak zaindeksować 50 mln dokumentów najszybciej? Wymień 6 ustawień.

---

## MODUŁ 6 — Query DSL: wyszukiwanie

**Cel:** budujesz dowolne zapytanie i wiesz, dlaczego zwraca to, co zwraca.

**Teoria**
- **Query context vs filter context** — pierwszy liczy `_score` (drożej), drugi tylko
  tak/nie i **jest cache'owany**. Wszystkie filtry (marka, cena, dostępność) → `filter`.
  To najprostsza optymalizacja, jaką można zrobić, i większość ludzi jej nie robi.
- `bool`: `must` / `filter` / `should` / `must_not`, `minimum_should_match`.
- Zapytania pełnotekstowe: `match`, `match_phrase`, `match_phrase_prefix`,
  `multi_match` (`best_fields`, `most_fields`, `cross_fields`, `phrase`, `bool_prefix`),
  `combined_fields`, `query_string` (niebezpieczne dla inputu użytkownika!),
  `simple_query_string`.
- Zapytania term-level: `term`, `terms`, `terms_lookup`, `range`, `exists`, `prefix`,
  `wildcard`, `regexp`, `fuzzy`, `ids`. Dlaczego `wildcard` z gwiazdką na początku to
  katastrofa i czym go zastąpić (`index_prefixes`, ngramy, reverse token filter).
- Fuzziness: odległość Levenshteina, `AUTO`, `prefix_length`, koszt.
- `dis_max` i `tie_breaker`, `boosting`, `constant_score`, `function_score`,
  `script_score`, `rank_feature`, `distance_feature`.
- `nested` query + `inner_hits`, `has_child`/`has_parent`.
- `_source` filtering, `fields`, `docvalue_fields`, `stored_fields`, highlighting
  (`unified` vs `fvh` vs `plain`).

**Ćwiczenia**
1. To samo zapytanie w `must` i w `filter`. Porównaj `took` po 100 powtórzeniach
   (`GET _nodes/stats/indices/query_cache`). Wyjaśnij różnicę.
2. Porównaj `best_fields` vs `most_fields` vs `cross_fields` dla „adidas buty biegowe"
   na polach `name`, `brand`, `description`. Wypisz, kiedy który wygrywa.
3. Zbuduj wyszukiwarkę odporną na literówki, ale nie zwracającą śmieci: `fuzziness: AUTO`
   + `prefix_length: 1` + `minimum_should_match`. Znajdź próg, przy którym zaczynają
   się fałszywe trafienia.
4. **Zepsuj i napraw:** wpuść `query_string` z inputem użytkownika i wyślij
   `name:*` OR `1:1` → zobacz błąd/koszt. Przepisz na `simple_query_string` z whitelistą.
5. Zaimplementuj `collapse` po `product_id` z `inner_hits` = najtańsza oferta.
   Sprawdź, co się dzieje z `total` przy collapse (pułapka paginacji!).

**Sprawdź się**
- Klient: „szukam «iphone 15 pro» i dostaję iPhone 14 wyżej". Twoje 4 hipotezy?
- Kiedy `should` bez `must` zachowuje się jak OR, a kiedy jak boost?

---

## MODUŁ 7 — Relevancja: dlaczego ten wynik jest pierwszy

**Cel:** przestajesz zgadywać i zaczynasz mierzyć.

**Teoria**
- **BM25**: term frequency (z saturacją!), inverse document frequency, normalizacja
  długości pola (`b`, `k1`). Dlaczego BM25 zastąpiło TF-IDF.
- Skąd biorą się dziwne wyniki: statystyki są **per shard** (`dfs_query_then_fetch`
  jako narzędzie diagnostyczne), krótkie pola dostają boost przez normalizację długości,
  `norms` można wyłączyć.
- Boosting: index-time (odradzane) vs query-time; boost na polu (`name^3`) vs na zapytaniu.
- Sygnały biznesowe: popularność (`rank_feature`), świeżość (`distance_feature`),
  marża, stan magazynowy — jak je łączyć **nie psując** trafności (`function_score`
  z `boost_mode`/`score_mode`, albo lepiej: `should` z `rank_feature`).
- **Learning to Rank** — wspomnimy jako poziom wyżej.
- Narzędzia: `_explain`, `"explain": true`, `_search?profile=true`, Search Profiler
  w Kibanie, `_validate/query?explain=true`, **Ranking Evaluation API** (`_rank_eval`).

**W projekcie:** zestaw ~50 par (zapytanie → oczekiwane produkty) w repo + `make eval`,
który liczy nDCG@10 i pilnuje, żeby zmiana boostów nie pogorszyła wyników.
To jest dokładnie to, co robią zespoły search w dużych firmach.

**Ćwiczenia**
1. `GET products/_explain/{id}` dla zapytania — rozpisz na kartce, skąd wziął się wynik.
2. Zaindeksuj 2 dokumenty różniące się tylko długością opisu → zobacz wpływ normalizacji.
3. Ustaw `index.similarity.default.b = 0` i porównaj ranking. Wyjaśnij, co się stało.
4. Dodaj `rank_feature` na popularność. Zmierz nDCG przed i po. Dobierz `boost`
   metodą pomiaru, nie intuicji.
5. **Zepsuj i napraw:** rozbij indeks na 5 shardów i zobacz, jak ten sam dokument
   dostaje inny score. Zdiagnozuj przez `search_type=dfs_query_then_fetch`.

**Sprawdź się**
- Czym różni się `boost` w `match` od `boost_mode` w `function_score`?
- Jak udowodnić szefowi, że nowa wersja rankingu jest lepsza?

---

## MODUŁ 8 — Agregacje i facety

**Cel:** budujesz filtry boczne z licznikami i dashboardy analityczne.

**Teoria**
- Metric: `avg`, `sum`, `min`, `max`, `stats`, `extended_stats`, `percentiles` (TDigest —
  **przybliżone!**), `cardinality` (HyperLogLog++ — **przybliżone!**, `precision_threshold`).
- Bucket: `terms` (i jego `doc_count_error_upper_bound` — dlaczego liczniki bywają
  niedokładne przy wielu shardach!), `range`, `date_histogram` (+ strefy czasowe!),
  `histogram`, `filters`, `nested`/`reverse_nested`, `significant_terms`,
  **`composite`** (jedyny sposób na stronicowanie agregacji).
- Pipeline: `bucket_selector`, `bucket_sort`, `derivative`, `moving_fn`,
  `cumulative_sum`, `stats_bucket`.
- **Faceting w praktyce**: `post_filter` + `global` agg — jak pokazać liczniki dla marek
  *po* zastosowaniu filtra ceny, ale *bez* zastosowania filtra marki. To jest realny
  problem każdego sklepu i mało kto go rozwiązuje poprawnie.
- Koszt: agregacje jedzą heap; `search.max_buckets`; `terms` o `size: 10000` to proszenie
  się o `circuit_breaking_exception`.
- **Transforms** — materializowanie agregacji do osobnego indeksu (np. dzienne statystyki
  wyszukiwań). Alternatywa dla liczenia w locie.

**Ćwiczenia**
1. Zbuduj pełny panel facetów: marki, kategorie (hierarchicznie!), przedziały cen,
   dostępność — jednym zapytaniem razem z wynikami.
2. Zaimplementuj poprawną semantykę `post_filter` i udowodnij testem, że liczniki
   są prawidłowe przy 2 aktywnych filtrach.
3. Porównaj `cardinality` z dokładnym `COUNT(DISTINCT)` z Postgresa. Zmierz błąd.
   Zmień `precision_threshold` i zobacz wpływ na pamięć.
4. **Zepsuj i napraw:** zrób `terms` na polu `description` (`text`) → błąd fielddata.
   Potem zrób `terms` z `size: 100000` → circuit breaker. Napraw przez `composite`.
5. Zbuduj transform: dzienna liczba wyszukiwań per fraza → indeks `search-stats-*`,
   a z niego zasil `popularity` produktów. Zamknij pętlę: **kliknięcia poprawiają ranking**.

**Sprawdź się**
- Dlaczego licznik przy marce pokazuje 47, a po kliknięciu wychodzi 45 wyników?
- Kiedy `composite` zamiast `terms`?

---

## MODUŁ 9 — Paginacja, sortowanie, duże wyniki

**Cel:** nie wywalasz klastra „stroną 5000".

**Teoria**
- `from`/`size` — koszt rośnie liniowo, limit `index.max_result_window` (10 000).
  **Dlaczego**: każdy shard musi zwrócić `from+size` wyników koordynatorowi.
- **`search_after` + PIT (Point In Time)** — właściwy sposób na głębokie przewijanie
  i eksport. Wymaga stabilnego sortowania (dodaj `_shard_doc` lub unikalne pole).
- `scroll` — stary mechanizm, dziś tylko do jednorazowych eksportów, trzyma zasoby.
- `track_total_hits` — domyślnie ES przestaje liczyć po 10 000. „Ponad 10 000 wyników"
  na stronach sklepów bierze się dokładnie stąd.
- Sortowanie po polach, `missing`, `unmapped_type`, sortowanie po `nested` (`nested.filter`).

**Ćwiczenia**
1. `from=9990&size=10` vs `search_after` — zmierz `took` i zużycie heapu.
2. Wyeksportuj 500 tys. dokumentów przez PIT + `search_after` w komendzie Symfony.
3. **Zepsuj i napraw:** `from=10000` → `Result window is too large`. Rozwiąż na 2 sposoby
   i uzasadnij, dlaczego podniesienie `max_result_window` to **zła** odpowiedź.

---

## MODUŁ 10 — Wydajność, tuning i skalowanie

**Cel:** wiesz, co ustawić, zanim ktoś zapyta „czemu wolno".

**Teoria**
- **Sharding**: ile shardów? Reguły kciuka: 10–50 GB na shard, ≤ 20 shardów na 1 GB heapu,
  liczba shardów ≥ liczba node'ów (dla równomierności), ale nie za dużo (over-sharding =
  narzut na każde zapytanie + ciężki cluster state).
- `number_of_replicas` — replika zwiększa przepustowość odczytu i HA, kosztem zapisu i miejsca.
- **Routing** — kierowanie dokumentów tego samego klienta na jeden shard (zapytanie
  dotyka 1 shardu zamiast 20). Pułapka: hot shard.
- Cache: **query cache** (filtry, per segment), **request cache** (`size:0`, agregacje!),
  **fielddata cache**. `GET _nodes/stats/indices/{query_cache,request_cache,fielddata}`.
- `index.codec: best_compression`, `index sorting` (przyspiesza wczesne przerwanie),
  `_source` disabling (prawie nigdy — zabija reindeks!), `store.preload`.
- Thread pools i kolejki (`search`, `write`, `get`), rejections, `search.max_concurrent_shard_requests`.
- **Circuit breakers**: parent, fielddata, request, in_flight_requests, accounting.
- Slowlogi: `index.search.slowlog.threshold.query.warn` itd. — jak je włączyć i czytać.
- `_nodes/hot_threads` — co robi CPU *w tej chwili*.
- Adaptive replica selection, `preference` w zapytaniach.

**Ćwiczenia**
1. Zaindeksuj ten sam zbiór do indeksów z 1, 3 i 12 shardami. Zmierz k6-em p95 dla
   3 typów zapytań. **Zapisz wnioski** — to Twoja własna tabela decyzyjna.
2. Włącz request cache dla zapytania agregacyjnego i zmierz różnicę (`size: 0`!).
3. Włącz slowlog z progiem 5 ms, wygeneruj ruch, znajdź najwolniejsze zapytanie w logach.
4. `?profile=true` na złożonym zapytaniu — zidentyfikuj, która klauzula zjada 80 % czasu.
5. **Zepsuj i napraw:** zrób agregację `terms` z dużym `size` na polu o wysokiej
   kardynalności (np. `offer_id` przy 15 mln ofert) → `circuit_breaking_exception`.
   Odczytaj z komunikatu, ile pamięci było potrzebne i ile było dostępne.
   Bonus: tymczasowo zejdź z heapem do 1 GB (`ES_JAVA_OPTS`) i zobacz, jak dużo łatwiej
   jest wtedy wywalić klaster — to samo zapytanie, inna granica.
6. Symuluj hot shard przez routing i zobacz nierówne obciążenie w `_cat/nodes`.

**Sprawdź się**
- 200 GB danych, 3 node'y po 32 GB RAM. Ile shardów i dlaczego?
- Zapytania nagle zwolniły 10×, dane się nie zmieniły. 6 hipotez i jak je odrzucić.

---

## MODUŁ 11 — Operacje na indeksach w czasie życia systemu

**Cel:** zmieniasz schemat i utrzymujesz dane bez downtime.

**Teoria**
- **Aliasy** — obowiązkowe od dnia 1. Atomowa zamiana, aliasy z filtrem, `is_write_index`.
- `_reindex` (także remote), `_update_by_query`, `_delete_by_query`, `conflicts=proceed`,
  `requests_per_second` (throttling), `slices` (paralelizacja), **Tasks API**
  (`GET _tasks?actions=*reindex*&detailed`, `POST _tasks/{id}/_cancel`).
- `_shrink`, `_split`, `_clone`, `_rollover`.
- **Data streams** — dla danych czasowych (logi, zdarzenia). Backing indices, `@timestamp`,
  automatyczny rollover.
- **ILM**: hot → warm → cold → frozen → delete; akcje `rollover`, `forcemerge`, `shrink`,
  `searchable_snapshot`, `delete`. Kiedy który tier ma sens.
- **Snapshots**: repozytoria (`fs`, `s3`), inkrementalność, `_snapshot`, restore z
  `rename_pattern`, **SLM** (harmonogram + retencja). Snapshot ≠ backup, jeśli nigdy
  nie przetestowałeś restore.
- Rolling upgrade i kompatybilność wersji.

**Ćwiczenia**
1. Pełny reindeks `products-v1` → `v2` ze zmianą typu pola, z aliasem i zerowym downtime.
   Zmierz, ile trwa i ile zajmuje dodatkowego miejsca.
2. `_update_by_query` zmieniający nazwę sprzedawcy w 100 tys. dokumentów.
   Steruj `requests_per_second`, obserwuj w Tasks API, anuluj w połowie, wznów.
3. Skonfiguruj data stream `events-user` + ILM: rollover po 1 dniu/1 GB, delete po 7 dniach.
   Przyspiesz ILM (`indices.lifecycle.poll_interval: 10s`) i zobacz przejścia na żywo.
4. Zrób snapshot do repozytorium `fs`, **usuń indeks**, przywróć. Potem to samo na MinIO.
5. **Zepsuj i napraw:** przełącz alias na indeks, który jeszcze się nie doindeksował →
   użytkownicy widzą 30 % wyników. Zaprojektuj bramkę jakości, która to blokuje.

---

## MODUŁ 12 — Bezpieczeństwo i dostęp

**Cel:** konfigurujesz ES tak, jak wygląda produkcja.

**Teoria**
- TLS transport (obowiązkowy między node'ami) vs TLS HTTP; `elasticsearch-certutil`.
- Wbudowani użytkownicy (`elastic`, `kibana_system`), `elasticsearch-reset-password`,
  keystore na sekrety.
- **RBAC**: role, indices privileges, cluster privileges, **document-level security**
  (widzisz tylko swoje oferty) i **field-level security** (nie widzisz marży).
- **API keys** — właściwy sposób uwierzytelniania aplikacji (nie login `elastic`!).
  Klucze z ograniczonym zakresem i wygaśnięciem.
- Audit log, IP filtering, anonimowy dostęp (i dlaczego nie).

**Ćwiczenia**
1. Włącz pełne TLS w profilu `secure`, wygeneruj certy, popraw konfiguracje klientów PHP.
2. Utwórz rolę `app_catalog` z prawami tylko `read` na `products-search` i API key dla
   Laravela. Sprawdź, że Laravel **nie może** skasować indeksu.
3. Skonfiguruj DLS tak, że sprzedawca widzi w API tylko swoje oferty.
4. **Zepsuj i napraw:** rotacja klucza API bez restartu aplikacji.

---

## MODUŁ 13 — Wyszukiwanie wektorowe i semantyczne

**Cel:** wiesz, kiedy to ma sens, a kiedy jest modą.

**Teoria**
- Embeddingi, `dense_vector`, HNSW, `similarity` (cosine/dot/l2), `num_candidates` vs `k`,
  koszt pamięciowy (wektory muszą się zmieścić — to nie jest darmowe!).
- `sparse_vector` / ELSER (model Elastica) — semantyka bez własnych embeddingów.
  ⚠️ wymaga węzła ML → **licencja płatna**; użyjemy 30-dniowego trialu i to oznaczymy.
- `semantic_text` i inference endpoints — nowe, wygodne API.
- **Wyszukiwanie hybrydowe**: BM25 + kNN połączone przez **RRF** (Reciprocal Rank Fusion).
  W praktyce prawie zawsze bije samo kNN.
- Kiedy **nie**: wyszukiwanie po numerze katalogowym, EAN, filtry — tam wektory szkodzą.

**Ćwiczenia**
1. Wygeneruj embeddingi dla 10 tys. produktów (mały model lokalnie / usługa) i zaindeksuj.
2. Porównaj wyniki dla „coś ciepłego na zimę" — BM25 vs kNN vs hybryda z RRF.
3. Zmierz wzrost zużycia RAM i czasu indeksacji po dodaniu `dense_vector`.
4. **Zepsuj i napraw:** kNN bez filtra na `in_stock` zwraca niedostępne produkty.
   Zastosuj filtered kNN i porównaj z post-filtrowaniem (i wytłumacz, czemu post-filtr
   psuje `k`).

---

## MODUŁ 14 — Diagnostyka: Twój runbook

**Cel:** masz własny, sprawdzony dokument „co robić, gdy…". Piszesz go **w trakcie**
wszystkich poprzednich modułów, po każdym ćwiczeniu „zepsuj i napraw".

Szkielet `docs/RUNBOOK.md`, który wypełnisz:

| Objaw | Pierwsze 3 komendy | Typowe przyczyny |
|---|---|---|
| Klaster **red** | `_cluster/health?level=indices`, `_cat/shards?s=state`, `_cluster/allocation/explain` | padł node z primary, uszkodzony shard, brak miejsca |
| Klaster **yellow** | jw. | 1 node + repliki, awareness, watermark |
| **Nie da się zapisać** | `_cat/allocation?v`, `_cluster/settings`, logi | flood_stage read-only, 429, mapping error |
| **429 rejected** | `_nodes/stats/thread_pool`, `_cat/thread_pool?v` | za dużo równoległych bulków, za mały batch |
| **Wolne zapytania** | slowlog, `?profile=true`, `_nodes/hot_threads` | brak filter context, wildcardy, głęboka paginacja, zimny cache, merge w tle |
| **Wolne indeksowanie** | `_cat/thread_pool`, `_nodes/stats/indices/merges` | refresh 1s, repliki, za małe batche, GC |
| **OOM / circuit breaker** | komunikat wyjątku, `_nodes/stats/breakers` | fielddata na text, ogromne agregacje, za mały heap |
| **Brak wyników mimo danych** | `_analyze`, `_termvectors`, `_validate/query?explain` | zły analizator, `text` vs `keyword`, refresh, alias na złym indeksie |
| **Dziwna kolejność wyników** | `_explain`, `dfs_query_then_fetch` | statystyki per shard, normalizacja długości, boosty |
| **Rozjazd ES ↔ Postgres** | głębokość kolejek, DLQ, `indexed_at` | zgubiony event, konsument w pętli błędu, konflikt wersji |
| **Kolejka rośnie** | RabbitMQ UI, `_cat/thread_pool` | wolny konsument, brak batchowania, ES pod presją |
| **Wiadomości w DLQ** | podgląd DLQ, logi konsumenta | zmiana kontraktu, poison message, mapping error |

Dodatkowo: **czym się różni „ES jest wolny" od „moja aplikacja wolno odpytuje ES"** —
zmierz `took` (czas w ES) vs czas end-to-end w PHP. Różnica to sieć, serializacja,
`_source` za duży, brak keep-alive, DNS, retry.

---

## MODUŁ 15 (równoległy) — RabbitMQ na poważnie

Realizowany razem z modułami 5–8, bo tam pojawia się ruch.

**Teoria**
- AMQP 0-9-1: connection → channel → exchange → binding → queue → consumer.
- Typy exchange: `direct`, `topic` (nasz), `fanout`, `headers`. Wzorce routing keys.
- Trwałość na trzech poziomach: durable queue + persistent message + **publisher confirms**.
  Brak któregokolwiek = możliwa utrata.
- `ack` / `nack` / `reject`, `requeue`, **prefetch (QoS)** i dlaczego domyślne
  „bez limitu" jest pułapką.
- **DLX + TTL** jako retry z backoffem; alternatywa: delayed message exchange.
  Wzorzec: `queue → (błąd) → retry.5s → retry.30s → retry.5m → dlq`.
- **Quorum queues** (Raft) vs classic mirrored — co wybrać dziś.
- Idempotencja konsumenta, deduplikacja, `correlation_id`, `message_id`.
- Backpressure i flow control, `memory_high_watermark`, lazy queues.
- Monitoring: głębokość kolejki, `messages_unacknowledged`, consumer utilisation,
  publish/ack rate — i co który wskaźnik naprawdę mówi.

**Ćwiczenia**
1. Wyłącz publisher confirms, zabij brokera w trakcie publikacji → policz zgubione
   wiadomości. Włącz confirms i powtórz.
2. Ustaw prefetch 1 vs 1000 przy 4 konsumentach → porównaj rozkład pracy i czas.
3. Zbuduj pełny łańcuch retry z DLX i przetestuj poison message.
4. **Zepsuj i napraw:** zmień kontrakt eventu (dodaj wymagane pole) bez wersjonowania →
   zobacz masowe lądowanie w DLQ. Zaprojektuj wersjonowanie i migrację.
5. `--scale search-consumer-sync=6` przy 500 tys. eventów: znajdź wąskie gardło
   (Rabbit? konsument? ES?) używając metryk, nie zgadywania.

---

## Jak się uczyć, żeby to zostało

1. **Kibana Dev Tools zamiast curl** na start — autouzupełnianie uczy DSL szybciej niż dokumentacja.
2. **Po każdym module dopisz 3 wpisy do własnego runbooka.** Wiedza nieutrwalona = brak wiedzy.
3. **Zawsze rób ćwiczenie „zepsuj i napraw".** Umiejętność diagnozowania bierze się
   wyłącznie z widzenia błędów na własne oczy. Zapamiętasz `circuit_breaking_exception`
   dopiero wtedy, gdy sam go wywołasz.
4. **Mierz, nie wierz.** Każda opinia o wydajności bez liczby jest bezwartościowa.
5. **Czytaj oficjalną dokumentację ES** — jest naprawdę dobra. Blogi są często
   nieaktualne o 3 wersje major.
6. Notuj pytania, na które nie umiesz odpowiedzieć — to jest Twoja lista tematów.

---

## Kontrolne pytania „czy już umiem" (poziom mid/senior)

1. Wyjaśnij, co się dzieje od `POST /_bulk` do momentu, gdy dokument jest wyszukiwalny.
2. Dlaczego ES nie może być jedynym miejscem przechowywania danych?
3. Masz 1 mld dokumentów. Jak zaprojektujesz indeksy i shardy?
4. Czym różni się `text` od `keyword` i jakie są konsekwencje pomyłki?
5. Jak zmienisz typ pola na produkcji bez downtime?
6. Skąd biorą się niedokładne liczniki w agregacji `terms`?
7. Jak zdiagnozujesz zapytanie, które trwa 4 s?
8. Jak zapewnisz, że ES nie rozjedzie się z bazą źródłową?
9. Kiedy `nested`, kiedy `join`, a kiedy denormalizacja na płasko?
10. Co robisz, gdy klaster jest red?
11. Dlaczego heap 64 GB to zły pomysł?
12. Jak zmierzysz, czy nowy ranking jest lepszy od starego?
