# ELSER i anomalie, krok po kroku: rzadkie wektory na polskich zapytaniach i ML kontra reguła progowa

*ETAP 14b projektu Marketplace Search Platform. Ciąg dalszy [części 14a](etap-14a-wektory-i-trafnosc.md), na tym samym
węźle laboratoryjnym `es-lab`. Dwa pytania, oba z odpowiedzią w liczbach. **Pierwsze:** czy ELSER, wbudowany model
semantyczny Elastica, pomaga na polskich zapytaniach? Dokładamy go jako szósty wariant do tabeli z 14a.
**Drugie:** czy wykrywanie anomalii (ML) znajdzie awarię w zdarzeniach sklepu, jeśli nikt mu nie powie, czego szukać?
Kopiujemy 158 710 prawdziwych zdarzeń `user.*` z głównego klastra, wstrzykujemy sześciogodzinną awarię kliknięć
i porównujemy ML z regułą progową z ETAPU 12.*

> **Stan:** gałąź `etap-14-semantyka` (scalona, PR #24, 2026-11-23), lokalny stos Docker, węzeł `es-lab` (ES 9.5.1, jeden węzeł,
> licencja trial), sprawdzone 2026-11-22. **Na żywo:** tabela 6 × 3 w dwóch przebiegach identyczna, pięć wierszy
> z 14a co do bitu równe `eval-14a.json`, `_rank_eval` zgodny z PHP także dla ELSER; job anomalii z kalendarzem
> wykrywa awarię (asercja `make lab-anomaly` zielona), test negatywny przed awarią czerwony, tak jak powinien.
> Część 14a (E5, kNN, hybryda, RRF, koszt wektorów): [przewodnik 14a](etap-14a-wektory-i-trafnosc.md).
> Projekt: [specyfikacja](../superpowers/specs/2026-11-22-etap-14-semantyka-ml-design.md).
> Pomiary: [`POMIARY.md` §18.6–18.7](../POMIARY.md). Błędy z drogi: RUNBOOK [#156–#157](../RUNBOOK.md#156).
> Laboratorium nie trafia do sklepu, na k3s ani na VPS. Główny klaster był tylko czytany (eksport zdarzeń).

---

## Spis treści

1. [Problem: dwa modele, dwa pytania](#1-problem-dwa-modele-dwa-pytania)
2. [Decyzje](#2-decyzje)
3. [Mapa plików](#3-mapa-plików)
4. [Cały tor 14b od zera](#4-cały-tor-14b-od-zera)
5. [Część I — ELSER](#część-i--elser)
6. [Krok 1 — rzadkie wektory: co ELSER robi z tekstem](#krok-1--rzadkie-wektory-co-elser-robi-z-tekstem)
7. [Krok 2 — osobny indeks `products-lab-elser`](#krok-2--osobny-indeks-products-lab-elser)
8. [Krok 3 — pamięć ML: 429 i bramka 90%](#krok-3--pamięć-ml-429-i-bramka-90)
9. [Krok 4 — indeksacja: alokacje, nie wątki](#krok-4--indeksacja-alokacje-nie-wątki)
10. [Krok 5 — zapytanie `semantic` i tabela 6 × 3](#krok-5--zapytanie-semantic-i-tabela-6--3)
11. [Krok 6 — dlaczego ELSER przegrywa na polskim](#krok-6--dlaczego-elser-przegrywa-na-polskim)
12. [Część II — wykrywanie anomalii](#część-ii--wykrywanie-anomalii)
13. [Krok 7 — eksport zdarzeń z głównego klastra](#krok-7--eksport-zdarzeń-z-głównego-klastra)
14. [Krok 8 — jak ML myśli: kubełek, detektor, model typowości, `record_score`](#krok-8--jak-ml-myśli-kubełek-detektor-model-typowości-record_score)
15. [Krok 9 — job i datafeed](#krok-9--job-i-datafeed)
16. [Krok 10 — prawdziwa luka w danych i kalendarz](#krok-10--prawdziwa-luka-w-danych-i-kalendarz)
17. [Krok 11 — przed awarią: jeden fałszywy alarm](#krok-11--przed-awarią-jeden-fałszywy-alarm)
18. [Krok 12 — awaria: 189 kliknięć mniej](#krok-12--awaria-189-kliknięć-mniej)
19. [Krok 13 — reguła progowa `ctr-drop` na tych samych danych](#krok-13--reguła-progowa-ctr-drop-na-tych-samych-danych)
20. [Kiedy ML w operacjach ma sens](#kiedy-ml-w-operacjach-ma-sens)
21. [Liczby z laboratorium](#liczby-z-laboratorium)
22. [Ograniczenia](#ograniczenia)
23. [Co wybuchło po drodze](#co-wybuchło-po-drodze)
24. [Powrót do normalnej pracy](#powrót-do-normalnej-pracy)
25. [Ściąga](#ściąga)

---

## 1. Problem: dwa modele, dwa pytania

W 14a wielojęzyczny model gęsty E5 dał na zapytaniach opisowych nDCG@10 **0,693** wobec **0,167** BM25. Elastic ma
jednak drugi, „flagowy” model semantyczny: **ELSER** (Elastic Learned Sparse EncodeR). Elastic przedstawia go jako gotowy
model do wyszukiwania semantycznego bez strojenia pod własne dane. Jest tylko angielski. Czy na polskim katalogu
coś z niego zostaje? Hipoteza z 14a brzmiała „porażka”, ale porażkę trzeba zmierzyć, a nie założyć.

Drugie pytanie jest operacyjne. W ETAPIE 12 napisaliśmy regułę Kibany `ctr-drop`: alarm, gdy w ostatniej godzinie CTR
(kliknięcia / wyszukiwania) spadnie poniżej połowy średniej z 7 dni. Taka reguła wymaga wiedzy, **co** mierzyć
i **jaki** próg jest zły. Wykrywanie anomalii obiecuje coś innego: dostaje surowe liczby zdarzeń, sam uczy się, jak
wygląda normalny dzień i tydzień, i zgłasza odstępstwa. Sprawdzamy to na kopii prawdziwych zdarzeń ze sztucznie
wywołaną awarią, której moment znamy co do godziny.

## 2. Decyzje

| Decyzja | Dlaczego |
|---|---|
| ELSER we **własnym indeksie** `products-lab-elser`, z `_reindex` z `products-lab-v1` | Zmiana mapowania `products-lab-v1` przeliczyłaby E5 (~15 min) i przebudowała graf HNSW, a to przesuwa wiersze kNN w tabeli (14a, krok 11). Dowód, że się udało: pięć pozostałych wierszy `eval-14b.json` jest identycznych z `eval-14a.json` |
| Model `.elser_model_2` (wariant niezależny od platformy) | Wariant `_linux-x86_64` jest tylko dla x86, a VM Dockera na Macu to arm64 |
| Persistent `xpack.ml.max_machine_memory_percent=90`, **tylko na `es-lab`** | Przy 60% ES odmawia wdrożenia drugiego modelu (HTTP 429). Zapytania knn i elser muszą działać w jednym przebiegu tabeli |
| Na czas `_reindex` 6 alokacji ELSER × 1 wątek, potem 1 alokacja | Wątki jednej alokacji nie przyspieszyły inferencji; alokacje tak (z godzin do 16,5 min) |
| Zdarzenia **kopiowane** do `lab-events-user` na `es-lab` | ML wymaga trialu, główny klaster jest na basic i ma zostać nietknięty; awarię wstrzykujemy w kopię |
| Detektor `low_count`, `partition_field_name: event.type`, kubełek 1 h | Szukamy ciszy (brak kliknięć), nie skoku; każdy typ zdarzenia ma inny wolumen i rytm, więc osobny model |
| Kalendarz ML `lab-known-gaps` dla prawdziwej luki 2026-10-21 → 2026-10-26 | Bez niego luka w historii „zjada” skalę `record_score` i awaria dostaje ~40 zamiast ~90 |
| Awaria: skasowanie `user.clicked_result` w oknie 6 h od 2026-11-10T09:00Z | Odtwarza typową awarię wyszukiwarki: ludzie szukają, ale nie mają w co kliknąć |
| Porównanie z regułą `ctr-drop` z ETAPU 12 na tych samych danych | Odpowiedź „czy ML jest potrzebny” wymaga konkurenta, a nie tylko wyniku ML |

## 3. Mapa plików

```
infra/elasticsearch/lab/setup-lab.sh             faza elser: bramka pamięci ML, endpoint lab-elser, indeks, _reindex (make lab-setup-elser)
apps/catalog/app/Services/Search/Lab/
  LabVariant.php                                 elser jako szósty wariant (poza domyślną tabelą 14a)
  LabQueryBuilder.php                            ciało elser: zapytanie semantic na semantic_elser; indexFor() → products-lab-elser
  LabEvalRunner.php                              indeks per wariant (także dla _rank_eval)
tools/lab/export-events.sh                       make lab-export-events: PIT + search_after z events-user-default (tylko odczyt)
tools/lab/anomaly.sh                             make lab-anomaly: ładowanie, job, kalendarz, datafeed, top-5, asercja, reguła ctr-drop
tools/lab/inject-outage.sh                       awaria: kasuje user.clicked_result z okna (tylko lab-events-user na es-lab)
infra/elasticsearch/lab/anomaly-job.json         job lab-user-activity: low_count / partition event.type / 1h
infra/elasticsearch/lab/anomaly-datafeed.json    datafeed: lab-events-user, match_all
infra/kibana/rules/ctr-drop.json                 (ETAP 12) reguła progowa, z której anomaly.sh bierze zapytanie ES|QL
apps/catalog/storage/app/private/lab/            (ignorowane przez git) events.ndjson, eval-14b.json
```

## 4. Cały tor 14b od zera

Wymagania: zrobiony tor 14a (węzeł `es-lab`, trial, `products-lab-v1` załadowany, [przewodnik 14a, sekcja
4](etap-14a-wektory-i-trafnosc.md#4-cały-tor-14a-od-zera)), `jq`, `python3`. Zdarzenia `user.*` w głównym klastrze
(data stream `events-user-default` z ETAPU 12).

```bash
# 1. eksport zdarzeń: tryb NORMALNY (główny ES działa), ~16 s, tylko odczyt
make lab-mode-off                     # jeśli tryb lab jest włączony
make lab-export-events                # → storage/app/private/lab/events.ndjson, 158 710 zdarzeń

# 2. laboratorium
make lab-mode-on && make lab-up

# 3. ELSER (~17 min; pierwszy raz pobiera model z serwerów Elastica)
make lab-setup-elser
make lab-eval args="--variant=bm25 --variant=knn --variant=hybrid-bool --variant=rrf-client --variant=rrf-native --variant=elser --check-rank-eval --json=lab/eval-14b.json"

# 4. anomalie: przebieg PRZED awarią (lista rekordów, bez asercji)
make lab-anomaly args="--no-assert"

# 5. awaria i przebieg PO awarii (asercja: kod wyjścia 1, jeśli ML jej nie wykryje)
bash tools/lab/inject-outage.sh       # kasuje 189 user.clicked_result z 2026-11-10T09:00Z + 6 h
make lab-anomaly

# 6. przywrócenie danych sprzed awarii (opcjonalne) i powrót do normalnej pracy
bash tools/lab/anomaly.sh --reload --no-assert
make lab-mode-off
```

**Daty są wpisane na stałe** i pasują do tego zbioru danych: okno awarii (`OUTAGE_FROM=2026-11-10T09:00:00Z`,
`OUTAGE_HOURS=6`, nadpisywalne zmiennymi środowiskowymi w obu skryptach) i zdarzenie kalendarza (2026-10-21 →
2026-10-26, w `anomaly.sh`). Jeśli Twoje zdarzenia pokrywają inne dni albo mają inne luki, ustaw okno na dzień
z normalnym ruchem i sprawdź historię przed pierwszym przebiegiem (krok 10).

`make lab-setup-elser` jest idempotentne (drugi przebieg ~4 s, wszystko „bez zmian”), a `_reindex` wznawialny:
dokumenty trafiają pod tymi samymi `_id` z `op_type: create`, więc przerwany przebieg dokończy resztę.

---

## Część I — ELSER

## Krok 1 — rzadkie wektory: co ELSER robi z tekstem

E5 z 14a zamienia tekst w **gęsty** wektor: 384 liczby, wszystkie niezerowe, bez interpretacji pojedynczych wymiarów.
Szuka się go grafem HNSW. ELSER robi coś innego. Zamienia tekst w **rzadki** wektor: listę par (token, waga) ze
słownika angielskiego BERT (~30 tys. kawałków słów, tzw. WordPiece). Niezerowych są dziesiątki do kilkuset
tokenów (sprawdzone na `lab-elser`: `winter jacket` 44, `komórka` 33, `Lampa ogrodowa solarna Zenit. Oświetlenie,
Dom i ogród` 171, ta sama nazwa z marką na początku 250–303), reszta słownika to zero. Taki wektor zapisuje się w polu `sparse_vector` w zwykłym indeksie odwróconym, jak
słowa dla BM25, a wynik dokumentu to suma iloczynów wag tokenów wspólnych dla zapytania i dokumentu.

Sztuczka nazywa się **rozwinięciem (term expansion)**. Model dokłada tokeny, których w tekście nie ma, ale które
„powinny” pasować. Zobacz sam (`lab_es` z `tools/lab/lib.sh`, top-10 tokenów po wadze, sprawdzone na żywo).
Uruchamiaj z katalogu głównego repo; `lib.sh` jest pisane pod bash, a jego `die` (np. brak `.env`) wczytane przez
`source` w interaktywnej powłoce zamknie ją, więc bezpieczniej opakować całość w `bash -c '…'`:

```bash
source tools/lab/lib.sh
lab_es -H 'Content-Type: application/json' "${LAB_URL}/_inference/sparse_embedding/lab-elser" \
  -d '{"input":"winter jacket"}' \
  | jq -c '.sparse_embedding[0].embedding | to_entries | sort_by(-.value) | .[:10] | map("\(.key)=\(.value*100|round/100)")'
```

| Tekst | Najcięższe tokeny |
|---|---|
| `winter jacket` | winter 2,61 · jacket 2,53 · christmas 1,53 · jackets 1,38 · **coat 1,15** · clothing 1,00 · **cold 0,98** · dress 0,93 · weather 0,82 · **warmth 0,74** |
| `kurtka zimowa` | kurt 2,37 · ##imo 2,22 · ##ka 1,80 · ##wa 1,76 · z 1,58 · she 1,07 · actress 0,70 · legend 0,69 · director 0,68 · her 0,56 |
| `coś ciepłego na zimę` | ##ime 1,88 · co 1,79 · z 1,70 · ##ep 1,50 · ##s 1,37 · **polish 1,14** · ##ego 1,06 · **poland 1,05** · ##ł 1,00 · ci 0,93 |
| `komórka` | ##rka 2,75 · ##mo 2,47 · ko 2,45 · ##rk 1,42 · ##nka 1,21 · ##ka 1,15 · sami 0,82 · ka 0,79 · mara 0,77 · russian 0,74 |

Po angielsku rozwinięcie jest piękne: kurtka zimowa „przyciąga” płaszcz, zimno i ciepło, czyli dokładnie to, czego
brakowało BM25 w zapytaniach opisowych. Po polsku tokenizer tnie słowa na kawałki (`kurt` + `##imo` + `##ka`), a model
rozwija je w to, co zna: „Kurt” to imię, więc dostajemy *she*, *actress*, *director*. Z „coś ciepłego na zimę” model
rozpoznaje tylko, że to po polsku (*polish*, *poland*). Znaczenia nie ma.

Plus wbrew pozorom: rzadki wektor można **przeczytać**. Przy E5 nie da się zapytać „dlaczego ten dokument”, przy
ELSER widać to w tokenach.

## Krok 2 — osobny indeks `products-lab-elser`

Najprostsza droga byłaby taka: dopisać do `products-lab-v1` pole `semantic_text` z ELSER i przeliczyć indeks
(`_update_by_query`). Odrzuciliśmy ją. Przepisanie każdego dokumentu to ponowna inferencja E5 (~15 min) i, co
gorsza, przebudowa grafu HNSW. W 14a (krok 11) widzieliśmy, że każda przebudowa przesuwa wiersze kNN w descriptive
nawet o 0,1. Tabela 6 × 3 straciłaby porównywalność z tabelą 5 × 3.

Dlatego faza `elser` w `setup-lab.sh`:

1. tworzy endpoint inference `lab-elser` (`PUT _inference/sparse_embedding/lab-elser`, `service: elasticsearch`,
   `model_id: .elser_model_2`),
2. tworzy indeks `products-lab-elser` z tą samą analizą (1 shard, 0 replik) i polami tekstowymi z
   `products-lab-v1`; mapowanie generuje `jq` z `products-lab-v1.json`, z `lab_text.copy_to: ["semantic_elser"]`
   i polem `semantic_elser: {type: semantic_text, inference_id: lab-elser}`. Bez pól `vec_*` i bez pipeline'u E5,
3. kopiuje dokumenty `_reindex` (asynchronicznie, `op_type: create`, `conflicts: proceed`, tylko pola tekstowe
   ze `_source`), robi `_refresh` i `force_merge` do 1 segmentu,
4. sprawdza `_count` = **19 824**.

`products-lab-v1` jest tylko czytany. **Dowód:** w `eval-14b.json` wiersze bm25, knn, hybrid-bool, rrf-client
i rrf-native są równe `eval-14a.json` co do bitu, łącznie z nDCG każdego zapytania i listami top-10.

Wariant `elser` w `search:eval:lab` sam celuje w swój indeks (`LabQueryBuilder::indexFor()`), także w kontroli
`_rank_eval`. Jeśli indeksu albo endpointu nie ma, komenda kończy się jednym zdaniem z podpowiedzią
`make lab-setup-elser`.

## Krok 3 — pamięć ML: 429 i bramka 90%

Węzeł `es-lab` ma limit 4 GB i `xpack.ml.max_machine_memory_percent: 60` (14a, krok 1), czyli ML może zająć
~2,4 GB. ES **szacuje** pamięć wdrożenia ostrożnie: ELSER ~2 GB, E5 ~1,3 GB. Razem się nie mieści, więc tworzenie
endpointu `lab-elser` przy działającym E5 kończy się **HTTP 429** (za mało pamięci ML do przydzielenia modelu).

Dwie rzeczy w fazie `elser`:

1. **E5 zatrzymane na czas wdrażania ELSER.** Skrypt wykonuje `POST _ml/trained_models/lab-e5/deployment/_stop?force=true`,
   wdraża ELSER i na końcu przywraca E5 (`deployment/_start`). Przywrócenie siedzi w `trap … EXIT`, więc wykonuje
   się także po błędzie.
2. **Bramka podniesiona do 90%.** Nawet po kolei oba modele naraz nie mieściły się w 60%, a tabela potrzebuje obu
   jednocześnie (knn pyta E5, elser pyta ELSER, oba przy każdym zapytaniu). Skrypt ustawia:

```bash
PUT _cluster/settings
{"persistent": {"xpack.ml.max_machine_memory_percent": 90}}
```

To ustawienie **trwałe i tylko na `es-lab`** (główny klaster bez zmian). Bramka to tylko szacunek: realnie proces
`pytorch_inference` ELSER zajmuje ~0,9 GB RSS, a kontener z oboma modelami 3,0–3,7 GiB z 4 GiB (w tym page cache).

**Ryzyko.** Przy 90% bramka przestaje chronić węzeł: heap 1,5 GB + do 3,6 GB dla ML to więcej niż 4 GB limitu
kontenera. Jeśli realne zużycie zbliży się do szacunku (więcej alokacji, duży cache inferencji, trzeci model), jądro
zabije kontener (`exit 137`), tak jak przy pierwszej konfiguracji z 14a. Dlatego po indeksacji ELSER wraca do 1
alokacji, a gdy nie jest potrzebny, warto go zatrzymać. Powrót do 60%: `{"persistent":
{"xpack.ml.max_machine_memory_percent": null}}` (ustawienie klastra znika, obowiązuje znów wartość z `compose.yaml`).
RUNBOOK [#156](../RUNBOOK.md#156).

## Krok 4 — indeksacja: alokacje, nie wątki

ELSER na CPU jest wolny: jedna alokacja liczy fragment tekstu w ~0,65 s, czyli ~92 fragmenty na minutę. Dla 19 824
dokumentów to kilka godzin. Pierwsza myśl: więcej wątków (`num_threads`). Nie pomaga: 8 wątków jednej alokacji
zajęło jeden rdzeń i przepustowość się nie zmieniła.

Pomogły **alokacje**. Alokacja to osobna kopia modelu obsługująca osobne żądania równolegle; wątki dzielą jedno
żądanie. `_reindex` wysyła dokumenty partiami, więc równoległość daje dopiero kilka alokacji. Faza tworzy endpoint
z `num_allocations: 6, num_threads: 1` (700–1000 dokumentów na minutę), a po `_reindex` zmniejsza do 1:

```bash
PUT _inference/sparse_embedding/lab-elser/_update
{"service_settings": {"num_allocations": 1}}
```

**Czas indeksacji: 992 s** (~16,5 min; całe `make lab-setup-elser` 17 min z pobraniem modelu). Indeks po
`force_merge`: 35 MB (`products-lab-v1` z czterema polami wektorowymi: 120 MB). `_cat/indices` pokazuje 39 648
dokumentów, bo liczy też ukryte dokumenty zagnieżdżone z fragmentami (`chunks`) pola `semantic_text`; `_count` mówi
19 824.

## Krok 5 — zapytanie `semantic` i tabela 6 × 3

Na polu `semantic_text` najprostsze jest zapytanie `semantic`. ES sam rozwija zapytanie modelem pola
(inferencja przy zapytaniu, więc wdrożenie ELSER musi działać) i liczy iloczyn rzadkich wektorów:

```json
{ "query": { "semantic": { "field": "semantic_elser", "query": "coś ciepłego na zimę" } } }
```

To zwykłe `query`, więc `_rank_eval` je przyjmuje: kontrola `--check-rank-eval` zielona dla bm25, knn, hybrid-bool
**i elser**. Wynik (`make lab-eval … --variant=elser --check-rank-eval --json=lab/eval-14b.json`, dwa przebiegi
identyczne):

| Wariant | lexical | descriptive | exact |
|---|---|---|---|
| bm25 | **1,000** | 0,167 | **1,000** |
| knn (E5) | 0,950 | **0,693** | 0,972 |
| hybrid-bool | **1,000** | 0,515 | **1,000** |
| rrf-client | 0,958 | 0,612 | 0,975 |
| rrf-native | 0,966 | 0,594 | 0,975 |
| **elser** | 0,923 | **0,167** | **0,831** |

ELSER na zapytaniach opisowych ma **dokładnie tyle co BM25** (0,167), czyli ponad cztery razy mniej niż E5. Na
dokładnych jest **najgorszy ze wszystkich** (0,831), a na słownikowych traci jedno zapytanie (`komórka`). Model
semantyczny, który nie rozumie języka, nie dodaje semantyki, za to psuje dopasowanie dokładne.

## Krok 6 — dlaczego ELSER przegrywa na polskim

Zapytania, na których ELSER różni się od reszty (nDCG@10, `eval-14b.json`):

| Zapytanie (grupa) | bm25 | knn | elser | Co ELSER zwrócił na górze |
|---|---|---|---|---|
| oświetlenie do ogrodu bez prądu (descriptive) | 0,000 | 1,000 | **1,000** | „Lampa ogrodowa solarna” |
| ochrona przed deszczem (descriptive) | 0,000 | 1,000 | **1,000** | „Kurtka przeciwdeszczowa” |
| coś ciepłego na zimę, ćwiczenia w domu, naprawy w domu, muzyka w podróży, prezent dla biegacza | 0,000 | 0,47–1,000 | **0,000** | |
| jazda po górach, telefon z dobrym aparatem (descriptive) | 1,000 | 1,000 | **0,000** | |
| `komórka` (lexical) | 1,000 | 0,351 | **0,000** | „Koszulka sportowa” |
| `Nova 256GB` (exact) | 1,000 | 1,000 | **0,000** | „Smartfon Nova **128GB**” |
| `Romagera Inc Rower górski Pro X 29"` (exact, literówka) | 1,000 | 0,779 | 0,649 | |

**Dwa „sukcesy” to zbieg kawałków słów, nie rozumienie.** „ogrodu” tnie się na `og` + `##rod`, a „ogrodowa”
w nazwie lampy zawiera te same kawałki. „deszczem” daje `##zcz`, które jest też w „przeciwdeszczowa”. To działa jak
bardzo luźny stemmer, a nie jak model znaczenia. Sprawdź rozwinięcia sam (kod z kroku 1, top-15 tokenów): w obu
zapytaniach poza kawałkami słów są tylko nazwy krajów i miast regionu (*polish*, *poland*, *warsaw*, *czech*,
*slovakia*, *krakow*, *prague*), żadnego tokenu o świetle, ogrodzie czy deszczu.

**Porażki mają ten sam mechanizm w drugą stronę.** `komórka` daje głównie kawałki `##rka`, `##mo`, `ko`, `##rk`, `##ka`; `ko` i `##ka`
są też w „Koszulka sportowa” (`ko`, `##sz`, `##ul`, `##ka`), więc top-10 to koszulki sportowe. BM25 trafia dzięki
synonimowi „telefon, smartfon, komórka” z analizatora `products-lab-v1`. ELSER go nie widzi: zapytanie `semantic`
oddaje surowy tekst endpointowi inference, a w rozwinięciu `komórka` (33 tokeny) nie ma ani *phone*, ani *telefon*,
ani *smartfon*. `Nova 256GB` to porażka rozwinięcia, nie tokenizacji: ELSER rozwija „256” m.in. w *memory* (1,23),
*32* (0,99), *storage* (0,96) i *128* (0,91), więc „Smartfon Nova 128GB” wychodzi na górę (wynik ~16,4, cała pierwsza
szóstka to wersje 128GB). Rozwinięcie pomaga przy luźnych zapytaniach i szkodzi tam, gdzie liczy się dokładna wartość
(pojemność, model, kod).

**Wniosek.** Dla polskiego katalogu wielojęzyczny model gęsty (E5) wygrywa z ELSER bez dyskusji. ELSER v2 ma sens
dla tekstów angielskich; na polskich daje wyniki BM25 bez jego zalet (synonimy, stemmer, precyzja na nazwach).
Innych modeli rzadkich nie mierzyliśmy.

---

## Część II — wykrywanie anomalii

## Krok 7 — eksport zdarzeń z głównego klastra

Zdarzenia `user.*` (ETAP 12: `user.searched`, `user.clicked_result`, `user.added_to_cart`, `user.checked_out`) leżą
w data streamie `events-user-default` na głównym klastrze. Wykrywanie anomalii to funkcja ML, więc trial, więc
`es-lab`. Kopiujemy dane, a nie przenosimy licencji.

`make lab-export-events` (`tools/lab/export-events.sh`) czyta je **tylko do odczytu** i bez limitu 10 000 wyników
zwykłego stronicowania:

1. `POST events-user-default/_pit?keep_alive=5m` otwiera **point in time**, czyli zamrożony widok indeksu. Nowe
   zdarzenia zapisywane w trakcie eksportu nie przesuną stron.
2. Kolejne `_search` z `pit`, `size: 5000`, `sort: [@timestamp asc, _shard_doc asc]` i `search_after` = wartości
   sortowania ostatniego trafienia poprzedniej strony. `_shard_doc` to tani, unikalny rozstrzygacz remisów
   w obrębie PIT.
3. `trap` zamyka PIT (`DELETE _pit`) także po błędzie; inaczej trzymałby segmenty do wygaśnięcia.

Wynik: **158 710** zdarzeń w **16 s**, od 2026-09-26T00:01:32Z do 2026-11-20T00:07:28Z, w tym 18 465
`user.clicked_result`. Pierwsza linia pliku to nagłówek z liczbą i zakresem. Hasło użytkownika `elastic` idzie przez
`curl -K <(…)`, nie przez argumenty.

Kolejność trybów ma znaczenie: eksport wymaga głównego klastra (`make lab-mode-off`), a ML wymaga `es-lab`
(`make lab-mode-on && make lab-up`). Oba naraz nie mieszczą się w pamięci (14a, krok 1).

`make lab-anomaly` ładuje plik do `lab-events-user` (1 shard, 0 replik, mapowanie: `@timestamp` date,
`event.type`, `event.id`, `user.id`, `search.query` jako keyword). Każdy dokument dostaje stałe `_id` = SHA-1 linii,
więc ponowne ładowanie nie tworzy duplikatów. `event.id` jest w eksporcie właśnie po to, żeby dwa zdarzenia tego
samego typu z tej samej sekundy miały różne linie, a więc różne `_id`.

## Krok 8 — jak ML myśli: kubełek, detektor, model typowości, `record_score`

Cztery pojęcia wystarczą, żeby czytać wyniki:

**Kubełek (`bucket_span`).** ML nie patrzy na pojedyncze zdarzenia, tylko na szereg czasowy: tu liczbę zdarzeń
w każdej godzinie. 55 dni danych to **1320 kubełków**. Krótszy kubełek widzi krótsze awarie, ale ma większy szum
(w spokojnej godzinie nocnej 3 kliknięcia zamiast 5 to nic, a przy kubełku 10 min wyglądałoby jak spadek o połowę).

**Detektor.** Funkcja plus pola. Nasz:

```json
{ "function": "low_count", "partition_field_name": "event.type" }
```

`low_count` liczy zdarzenia w kubełku i zgłasza **tylko** kubełki z liczbą niższą niż oczekiwana (`count` zgłaszałby
oba kierunki, `high_count` tylko skoki). Awaria wyszukiwarki to cisza, nie skok. `partition_field_name` rozbija
szereg na osobne modele dla każdego typu zdarzenia: koszyk ma ~78 zdarzeń na godzinę, kliknięcia ~40 w dzień,
zakupy ~19. Wspólny model dla wszystkich typów mieszałby te skale (alternatywa `by_field_name` dała w teście
praktycznie te same wyniki; partition jest czystszy, bo daje też osobną normalizację wyniku na typ).

**Model typowości.** Dla każdego szeregu ML uczy się online (kubełek po kubełku) rozkładu prawdopodobieństwa
wartości, łącznie z wykrytą sam okresowością dobową i tygodniową. W każdym rekordzie wyniku widać `typical` (czego
model się spodziewał) i `actual` (co przyszło). O 13:00 we wtorek model spodziewa się innej liczby kliknięć niż
o 3:00 w nocy.

**`probability` i `record_score`.** Dla każdego kubełka ML liczy prawdopodobieństwo tak skrajnej wartości przy
swoim modelu, np. 1,5 × 10⁻⁴. Potem **normalizuje** je do skali 0–100 względem wszystkich anomalii, które widział
w historii joba; to jest `record_score`. Skala jest więc **względna**: ta sama godzina dostaje inny wynik zależnie
od tego, co jeszcze było w danych, a stare rekordy są przeliczane (renormalizacja), gdy przychodzą nowe. W Kibanie
od 50 zaczyna się poziom „major”; przyjmujemy ten próg w asercji.

Influencer `event.type` mówi w wynikach, który typ zdarzenia „odpowiada” za anomalię kubełka. Przy jednym polu
partycji jest to oczywiste, ale bez niego wyniki na poziomie kubełka nie wskazywałyby winnego.

## Krok 9 — job i datafeed

Job to model i jego wyniki, datafeed to „pompa” danych z indeksu do joba. `anomaly.sh` odtwarza oba od zera przy
każdym przebiegu (stop i kasowanie starych, potem `PUT`):

```bash
PUT _ml/anomaly_detectors/lab-user-activity      # infra/elasticsearch/lab/anomaly-job.json
PUT _ml/datafeeds/datafeed-lab-user-activity     # indeks lab-events-user, match_all, scroll_size 1000
POST _ml/anomaly_detectors/lab-user-activity/_open
POST _ml/datafeeds/datafeed-lab-user-activity/_start   {"start": "<min @timestamp>", "end": "<max @timestamp + 1 h>"}
```

Job ma `model_memory_limit: 32mb` (faktycznie 25 MB, `memory_status: ok`) i `results_index_name:
lab-user-activity`, więc wyniki lądują we własnym indeksie `.ml-anomalies-custom-lab-user-activity-000001`.
Datafeed z `end` przechodzi historię chronologicznie (lookback) i sam się zatrzymuje; bez `end` przeszedłby potem
w tryb ciągły i co kubełek dociągał nowe dane. Skrypt czeka na stan `stopped` i zamyka job. Wyniki czyta:

```bash
GET _ml/anomaly_detectors/lab-user-activity/results/records
{"sort": "record_score", "desc": true, "record_score": 0, "page": {"from": 0, "size": 1000}}
```

**Pamięć.** Job zajmuje ~25 MB, więc otworzył się obok obu wdrożonych modeli (E5 i ELSER) bez zatrzymywania
któregokolwiek. Gdyby się nie otworzył, skrypt podpowiada zatrzymanie ELSER
(`POST _ml/trained_models/lab-elser/deployment/_stop?force=true`); `make lab-setup-elser` uruchomi go ponownie
(z 1 alokacją, bo indeks `products-lab-elser` jest kompletny). Sprawdzone na ES 9.5.1: identyfikatorem musi być
**deployment** (`lab-elser`), a nie model. `_stop` bez `force` odpowiada 409 („created by inference endpoint”),
a `.elser_model_2/deployment/_stop`, z `force` i bez, zwraca `{"stopped":true}`, ale niczego nie zatrzymuje.

## Krok 10 — prawdziwa luka w danych i kalendarz

**Pierwszy przebieg po awarii (bez kalendarza) awarii nie wykrył.** Zero kliknięć przez 6 godzin dostało
`record_score` **37–40**, mimo prawdopodobieństwa rzędu 10⁻⁵–10⁻⁶. Rekordów z wynikiem ≥ 50 było za to 45, wszystkie
w tym samym miejscu: **2026-10-21 ~10:00 → 2026-10-26**. W tych dniach `user.added_to_cart` miało ~70 zdarzeń na dobę
zamiast ~1900, `user.checked_out` podobnie. To prawdziwa luka w danych (inny ruch generatora zdarzeń w tamtym czasie),
nie nasza awaria.

Tu widać normalizację z kroku 8 w działaniu: pięć dni prawie zerowego koszyka to w historii joba najbardziej
skrajne odstępstwo, więc zajęło górę skali 90–99, a nasza awaria wypadła pod progiem. Próbowaliśmy innych ustawień,
zanim znaleźliśmy przyczynę: kubełek 30 min, 2 h i 3 h, `by_field_name` zamiast partycji, okno awarii 12 h. Żadne
nie przekroczyło ~44. Kubełek 6 h dał 86, ale tylko dlatego, że był dopasowany do długości okna awarii; to strojenie
pod znany wynik, więc odrzucone.

**Naprawa: kalendarz ML.** Kalendarz to lista zdarzeń zaplanowanych (święta, okna serwisowe, znane luki), których
job ma nie oceniać i z których ma się nie uczyć. `anomaly.sh` tworzy go przed otwarciem joba:

```bash
PUT _ml/calendars/lab-known-gaps          {"job_ids": ["lab-user-activity"], "description": "znane luki w danych (nie awarie)"}
POST _ml/calendars/lab-known-gaps/events  {"events": [{"description": "niski ruch generatora cart/checkout",
  "start_time": 1792540800000, "end_time": 1792972800000, "skip_result": true, "skip_model_update": true}]}
```

(Czasy w milisekundach epoki: 2026-10-21T00:00Z i 2026-10-26T00:00Z.) `skip_result` – nie twórz rekordów dla tych
kubełków; `skip_model_update` – nie ucz modelu z tych danych, żeby „pięć dni ciszy” nie poszerzyło pojęcia normy.
Po dodaniu kalendarza awaria dostaje **89–94**.

Lekcja: ML jest tak dobry, jak czysta jest jego historia. Znaną lukę oznacza się kalendarzem, a nie obniżeniem
progu asercji (obniżony próg złapałby też szum). Daty są wpisane na stałe i dotyczą tego zbioru; przy innych danych
trzeba najpierw obejrzeć historię (przebieg z `--no-assert` i top rekordów pokazuje luki od razu).

## Krok 11 — przed awarią: jeden fałszywy alarm

`make lab-anomaly args="--no-assert"` na nietkniętych danych, z kalendarzem. Top-5 rekordów:

| Kubełek (UTC) | Typ | `record_score` | actual | typical |
|---|---|---|---|---|
| 2026-11-03 18:00 | `user.searched` | **98,3** | 15 | 35,3 |
| 2026-10-02 01:00 | `user.checked_out` | 36,6 | 17 | 19,2 |
| 2026-11-10 20:00 | `user.clicked_result` | 31,2 | 16 | 51,7 |
| 2026-10-12 04:00 | `user.added_to_cart` | 30,5 | 51 | 77,1 |
| 2026-10-02 02:00 | `user.checked_out` | 28,7 | 20 | 19,2 |

Rekordów razem 26, z wynikiem ≥ 50 **jeden**: 15 wyszukiwań zamiast ~35 o 18:00 w dniu 03.11. Nie ma za nim żadnej
awarii (to syntetyczny ruch generatora), więc dla operatora to **fałszywy alarm**, choć statystycznie prawdziwe
odchylenie (prawdopodobieństwo 1,5 × 10⁻⁷, mniejsze niż w kubełkach awarii). Tak wygląda koszt ML: zgłasza to, co
nietypowe, a nie to, co szkodliwe.

Test negatywny: ten sam przebieg z asercją (bez `--no-assert`) kończy się kodem 1 i „BRAK takiego rekordu”. Asercja
nie jest więc zielona z założenia.

## Krok 12 — awaria: 189 kliknięć mniej

`bash tools/lab/inject-outage.sh` kasuje `user.clicked_result` z okna **2026-11-10T09:00Z + 6 h**
(`_delete_by_query` z filtrem typu i zakresu czasu): **189** dokumentów. Drugie wywołanie kasuje 0 (idempotentne).
Skrypt ma trzy bezpieczniki, bo kasowanie danych na złym klastrze byłoby nieodwracalne: odmawia, gdy docelowy adres
to nie `LAB_URL`, gdy port to 19200 (główny klaster), i gdy pod adresem istnieje `events-user-default`. Działa tylko
na `lab-events-user`.

`make lab-anomaly` (indeks ma znacznik `_meta.lab_loaded`, zapisywany dopiero po udanym ładowaniu, więc skrypt go nie
ładuje ponownie, żeby nie wskrzesić skasowanych kliknięć; przywrócenie danych to `--reload`. Indeks z dokumentami, ale
bez znacznika, to przerwane ładowanie: skrypt odmawia i podpowiada `--reload`). Kubełki okna awarii dla `user.clicked_result`:

| Kubełek (UTC) | actual | typical | `record_score` | ≥ 50 |
|---|---|---|---|---|
| 09:00 | 0 | – | brak rekordu | nie |
| 10:00 | 0 | 42,6 | **88,9** | **tak** |
| 11:00 | 0 | 31,1 | 5,1 | nie |
| 12:00 | 0 | 42,5 | 34,1 | nie |
| 13:00 | 0 | 37,3 | **93,7** | **tak** |
| 14:00 | 0 | 38,8 | 21,1 | nie |

Rekordów razem 31, z wynikiem ≥ 50 trzy (dwa z okna + fałszywy alarm z 03.11). Asercja zielona (kod 0):

```
OK: 2026-11-10T13:00:00Z  score 93.7  actual 0  typical 37.3
OK: 2026-11-10T10:00:00Z  score 88.9  actual 0  typical 42.6
```

**ML wykrył awarię, ale tylko w 2 z 6 godzin okna.** Sześć godzin zera nie jest dla modelu sześcioma równie
mocnymi sygnałami. Przy godzinowej zmienności kliknięć ~±30% pojedyncze zero ma prawdopodobieństwo ~10⁻³–10⁻⁴,
a model uczy się online, więc po pierwszych zerach jego pojęcie „normy” prawdopodobnie się rozszerza (to
wyjaśnienie, nie pomiar; wyniki godzin nie rosną ani nie maleją monotonicznie). Uczciwy opis: ML zauważył,
że coś jest nie tak, i wskazał właściwy szereg i dzień, ale nie zaznaczył całego okna.

## Krok 13 — reguła progowa `ctr-drop` na tych samych danych

Reguła z ETAPU 12 (`infra/kibana/rules/ctr-drop.json`) to zapytanie ES|QL: zdarzenia z ostatniej godziny („recent”)
kontra reszta 7 dni („baseline”); alarm, gdy

```
recent_searches >= 30 AND recent_ctr < 0.5 * baseline_ctr
```

`anomaly.sh` bierze to zapytanie z pliku reguły, podmienia `FROM events-user-*` na `lab-events-user`, `NOW()`
na kolejne pełne godziny okna, i ogranicza dane do `[teraz − 7 dni, teraz)`. Wynik po awarii:

| Reguła liczona o (UTC) | obejmuje godzinę | Werdykt | Dlaczego |
|---|---|---|---|
| 10:00 | 09:00–10:00 | cisza | 25 wyszukiwań < podłoga 30 |
| 11:00 | 10:00–11:00 | **ALARM** | CTR 0 |
| 12:00 | 11:00–12:00 | **ALARM** | CTR 0 |
| 13:00 | 12:00–13:00 | **ALARM** | CTR 0 |
| 14:00 | 13:00–14:00 | **ALARM** | CTR 0 |
| 15:00 | 14:00–15:00 | cisza | 28 wyszukiwań < podłoga 30 |

**Reguła: 4 z 6 godzin, ML: 2 z 6.** Dodatkowo przeliczyliśmy regułę godzina po godzinie dla 03.11–17.11 (336
godzin, jednorazowe przeliczenie poza repozytorium): **zero fałszywych alarmów**, cztery trafienia, wszystkie
w oknie awarii. ML w tym samym okresie miał jeden fałszywy alarm (03.11).

Dlaczego reguła wygrała? Bo awaria zeruje kliknięcia, a wyszukiwania zostają, więc stosunek klików do wyszukiwań
jest silnym, ręcznie zaprojektowanym sygnałem, a jego mianownik sam „normalizuje” porę dnia. ML dostał tylko surowe
liczby na typ i musiał się domyślić, ile kliknięć to normalnie o 11:00. Reguła ma za to dwie słabości widoczne
w tabeli: podłoga 30 wyszukiwań (bez niej w spokojnych godzinach strzelałaby z byle powodu) gasi alarm na skraju
okna, i reguła istnieje tylko dlatego, że **ktoś wiedział**, że CTR jest właściwą miarą.

## Kiedy ML w operacjach ma sens

Wniosek z tego laboratorium jest mniej efektowny niż reklama, ale uczciwy: **dla ostrego spadku znanej miary dobra
reguła progowa może być lepsza od ML** — prostsza, tańsza (bez trialu i modelu), łatwiejsza do wyjaśnienia
i tutaj trafniejsza (4/6 godzin, 0 fałszywych alarmów wobec 2/6 i 1).

ML jest wart swojej ceny, gdy:

- **nie znasz progu albo sygnał nie ma oczywistej miary względnej.** 15 wyszukiwań zamiast 35 we wtorek o 18:00 to
  coś, czego żadna z naszych reguł by nie zauważyła; ML zauważył (choć tu nie było za tym awarii);
- **sezonowość jest nieznana lub zmienna**: rytm dobowy, tygodniowy, święta. Próg statyczny albo strzela w nocy,
  albo milczy w dzień; ML uczy się rytmu sam;
- **szeregów jest dużo**: tysiąc sprzedawców, sto kategorii, dziesięć regionów. Reguły na każdy szereg nikt nie
  napisze, a `partition_field_name: seller.id` to jedna linia;
- **chcesz wiedzieć „co jest dziwne”, zanim wiesz, czego szukać**: ML jako narzędzie rozpoznania, a dopiero potem,
  dla sygnałów, które okazały się ważne, prosta reguła.

I dwa warunki konieczne: **czysta historia** (znane luki w kalendarzu, krok 10) oraz akceptacja, że wynik jest
względny i probabilistyczny: będą fałszywe alarmy i nie każda godzina awarii dostanie wysoki wynik.

## Liczby z laboratorium

| Co | Wartość |
|---|---|
| ELSER | `.elser_model_2`, endpoint `lab-elser`, indeks `products-lab-elser` (19 824 dokumenty, 35 MB) |
| Indeksacja ELSER | 992 s (6 alokacji × 1 wątek), 1 alokacja ~92 fragmenty/min |
| Pamięć ML | bramka 60% → 429 dla dwóch modeli; persistent 90% na `es-lab`; kontener 3,0–3,7 / 4 GiB |
| nDCG@10 elser: lexical / descriptive / exact | 0,923 / 0,167 / 0,831 (bm25: 1,000 / 0,167 / 1,000; knn: 0,950 / 0,693 / 0,972) |
| Pięć wierszy 14a w `eval-14b.json` | identyczne z `eval-14a.json` |
| Zdarzenia | 158 710 (eksport 16 s), 2026-09-26 → 2026-11-20, 1320 kubełków 1 h |
| Job | `low_count`, partycja `event.type`, model 25 MB |
| Bez kalendarza | awaria 37–40, luka 10.21–10.26: 45 rekordów ≥ 50 |
| Z kalendarzem, przed awarią | 26 rekordów, 1 ≥ 50 (fałszywy alarm, `user.searched` 03.11 18:00, 98,3) |
| Awaria | 189 kliknięć skasowanych, 2026-11-10T09:00Z + 6 h |
| ML po awarii | 2 z 6 godzin ≥ 50 (13:00 93,7; 10:00 88,9) |
| Reguła `ctr-drop` | 4 z 6 godzin; 0 fałszywych alarmów w 336 h |

Więcej: [`POMIARY.md` §18.6–18.7](../POMIARY.md).

## Ograniczenia

- **Jeden model rzadki, jeden język zapytań.** Zmierzyliśmy ELSER v2 (angielski) na polskich zapytaniach. Wniosek
  dotyczy tej pary, nie rzadkich wektorów w ogóle.
- **Tabela 6 × 3 ma ograniczenia z 14a** (oceny nasycone na typie produktu, literówka przeoceniona po wynikach,
  kNN powtarzalny tylko w obrębie budowy grafu); ELSER w osobnym indeksie bez HNSW jest pod tym względem stabilny.
- **Plik `eval-14b.json` zapisuje w polu `index` nazwę `products-lab-v1`**, choć wariant elser pyta
  `products-lab-elser`.
- **Dane zdarzeń są syntetyczne** (generator ruchu z ETAPU 12) i mają prawdziwą lukę z innego ruchu generatora.
  Daty luki w kalendarzu i okno awarii są wpisane na stałe pod ten zbiór.
- **Jedna awaria, jeden kształt**: twarde zero kliknięć przez 6 h. Powolny spadek (np. CTR −20% przez tydzień) mógłby
  dać odwrotny werdykt: reguła z progiem 50% by go nie zauważyła. Tego nie mierzyliśmy.
- **336 godzin reguły bez fałszywych alarmów to jednorazowe przeliczenie** (skrypt poza repozytorium); w repozytorium
  `anomaly.sh` liczy regułę tylko dla 6 godzin okna.
- **Asercja sprawdza „co najmniej jedna godzina okna ≥ 50”**, a nie całe okno.

## Co wybuchło po drodze

| RUNBOOK | Objaw |
|---|---|
| [#156](../RUNBOOK.md#156) | ELSER się nie wdraża: HTTP 429, za mało pamięci ML (obok E5) |
| [#157](../RUNBOOK.md#157) | Job anomalii bez wyników, datafeed zatrzymany albo awaria z wynikiem < 50 (luka w danych, kalendarz) |

Błędy z części 14a (trial po 30 dniach, OOM przy imporcie modelu, nDCG ≠ `_rank_eval`, RRF): RUNBOOK
[#152–#155](../RUNBOOK.md#152).

## Powrót do normalnej pracy

```bash
make lab-mode-off        # zatrzymuje es-lab, startuje es01–es03, czeka na zdrowie, przywraca resztę z listy
```

`make lab-mode-off` przywraca dokładnie te usługi, które działały przed `make lab-mode-on` (lista w
`storage/app/private/lab/lab-mode.state`). Dane laboratorium (oba indeksy produktów, `lab-events-user`, job
z wynikami, kalendarz, ustawienie 90%) zostają w wolumenie `es-lab-data` do następnego `make lab-up`. Kasuje je
dopiero `make lab-reset CONFIRM=1`. Główny klaster nie wymaga sprzątania: był tylko czytany.

## Ściąga

```bash
# ELSER
make lab-setup-elser                                       # endpoint lab-elser + products-lab-elser (~17 min)
make lab-eval args="--variant=elser --variant=bm25 --variant=knn"
make lab-eval args="--variant=bm25 --variant=knn --variant=hybrid-bool --variant=rrf-client --variant=rrf-native --variant=elser --check-rank-eval --json=lab/eval-14b.json"

# z katalogu głównego repo; `die` z lib.sh zamyka interaktywną powłokę, więc najlepiej w bash -c '…' (krok 1)
source tools/lab/lib.sh
lab_es -H 'Content-Type: application/json' "${LAB_URL}/_inference/sparse_embedding/lab-elser" \
  -d '{"input":"kurtka zimowa"}' | jq -c '.sparse_embedding[0].embedding | to_entries | sort_by(-.value) | .[:10]'
lab_es "${LAB_URL}/_ml/trained_models/_stats" | jq -c '.trained_model_stats[] | select(.deployment_stats) | {model_id, state: .deployment_stats.state, alloc: .deployment_stats.number_of_allocations}'
lab_es "${LAB_URL}/_cluster/settings" | jq .persistent       # xpack.ml.max_machine_memory_percent

# anomalie
make lab-mode-off && make lab-export-events && make lab-mode-on && make lab-up
make lab-anomaly args="--no-assert"                        # przed awarią
bash tools/lab/inject-outage.sh                            # awaria (tylko lab-events-user na es-lab)
make lab-anomaly                                           # po awarii, asercja
bash tools/lab/anomaly.sh --reload --no-assert             # dane sprzed awarii
lab_es "${LAB_URL}/_ml/anomaly_detectors/lab-user-activity/_stats" | jq '.jobs[0] | {state, data_counts, model_size_stats}'
lab_es "${LAB_URL}/_ml/datafeeds/datafeed-lab-user-activity/_stats" | jq '.datafeeds[0].state'
lab_es "${LAB_URL}/_ml/calendars/lab-known-gaps/events" | jq

# koniec
make lab-mode-off
```
