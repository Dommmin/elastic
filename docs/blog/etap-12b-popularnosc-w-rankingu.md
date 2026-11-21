# Popularność w rankingu, krok po kroku: enrich, IPS, tasowanie remisów i dowód na `_rank_eval`

*ETAP 12b projektu Marketplace Search Platform. Część 12a zbierała zdarzenia z sesji (wyszukania, wyświetlenia,
kliknięcia) i liczyła z nich lejek. Ta część zamyka pętlę: kliknięcia wracają do rankingu jako popularność produktu
(`rank_feature`), z korektą biasu pozycji (IPS). Potem trzeba **udowodnić**, że to coś poprawia, i sprawdzić, że
dowód nie jest artefaktem. Na koniec dwie reguły anomalii w Kibanie: skok zapytań bez wyników i spadek CTR.*

> **Stan:** gałąź `etap-12-analityka` (niewypchnięta), lokalny stos Docker (3 węzły ES 9.5.1, Kibana 9.5.1),
> sprawdzone 2026-11-20. **Na żywo:** aliasy produktów wskazują `products-v6` z pipeline'em enrich (0 odrzuconych
> zapisów), popularność ma 7457 produktów, popularność podniosła nDCG@10 na zestawie dowodowym z ~0,15 do 0,21–0,26
> i nie ruszyła go w kontroli (Δ +0,002), reguły anomalii odpaliły po wstrzyknięciu i milczały przy normalnym ruchu.
> Lokalnie domyślne `SEARCH_POPULARITY_BOOST=2`. **Nie wdrożone na k3s** (brak serwera), a k3s celowo zostaje przy
> B = 0, dopóki nie będzie tam `products-v6` i refreshu popularności.
> Część 12a: [`etap-12a-zdarzenia-i-lejek.md`](etap-12a-zdarzenia-i-lejek.md).
> Projekt: [specyfikacja](../superpowers/specs/2026-11-20-etap-12-analityka-operacyjna-design.md).
> Pomiary: [`POMIARY.md` §16.1–16.6](../POMIARY.md). Błędy z drogi: RUNBOOK [#133–#138](../RUNBOOK.md#133).

---

## Spis treści

1. [Problem: zamknąć pętlę i tego dowieść](#1-problem-zamknąć-pętlę-i-tego-dowieść)
2. [Decyzje](#2-decyzje)
3. [Mapa plików](#3-mapa-plików)
4. [Krok 1 — jedna ścieżka punktująca](#krok-1--jedna-ścieżka-punktująca)
5. [Krok 2 — `products-v6` i pipeline enrich, który nigdy nie odrzuca](#krok-2--products-v6-i-pipeline-enrich-który-nigdy-nie-odrzuca)
6. [Krok 3 — `product-popularity`: p(k) z danych, IPS i prior w dobrej skali](#krok-3--product-popularity-pk-z-danych-ips-i-prior-w-dobrej-skali)
7. [Krok 4 — przyrząd pomiarowy: oceny z ukrytej prawdy symulatora](#krok-4--przyrząd-pomiarowy-oceny-z-ukrytej-prawdy-symulatora)
8. [Krok 5 — tasowanie remisów: drugi klucz sortowania, nie `random_score`](#krok-5--tasowanie-remisów-drugi-klucz-sortowania-nie-random_score)
9. [Krok 6 — pętla zamknięta jednym skryptem](#krok-6--pętla-zamknięta-jednym-skryptem)
10. [Krok 7 — dowód: wyniki, kontrola, plateau B](#krok-7--dowód-wyniki-kontrola-plateau-b)
11. [Krok 8 — reguły anomalii w ES|QL](#krok-8--reguły-anomalii-w-esql)
12. [Całość od zera](#całość-od-zera)
13. [Ograniczenia](#ograniczenia)
14. [Co wybuchło po drodze](#co-wybuchło-po-drodze)
15. [Zadania domowe](#zadania-domowe)
16. [Ściąga](#ściąga)

---

## 1. Problem: zamknąć pętlę i tego dowieść

Wyszukiwarka do tej pory układała wyniki tylko po tekście (BM25). Fraza typu „lampa led nova” daje setki produktów
z **identycznym** `_score`: nazwa pasuje tak samo, więc ES nie ma czym ich rozróżnić i zostawia kolejność
wewnętrzną Lucene. Klienci klikają jednak jedne produkty chętniej niż inne. Ten sygnał już jest w data streamach
z 12a, więc pytania są takie:

- Jak dokleić popularność do dokumentu produktu, skoro dokument jest przepisywany przy każdej zmianie oferty
  (strumień z ETAPU 9), przy reindeksie i reprojekcji, i nie wolno przez to zgubić ani jednego zapisu?
- Jak liczyć popularność, żeby nie nagradzać produktu za to, że **już** stał wysoko (bias pozycji)?
- Jak zmierzyć poprawę, skoro bez popularności kolejność w remisie jest przypadkowa, a „poprawę” trzeba mierzyć
  względem czegoś, co się nie rusza?
- Jak sprawdzić, że dowód nie jest wadliwy, czyli że przy kliknięciach bez sygnału popularność niczego nie
  „poprawia”?

Wszystko poniżej działa na symulatorze z 12a (`analytics:simulate`): klient klika pozycję `k` z prawdopodobieństwem
`a / k^η`, gdzie `a` to **ukryta atrakcyjność** produktu, której wyszukiwarka nie zna. Dzięki temu jest prawda,
z którą można porównać wynik.

## 2. Decyzje

| Pytanie | Wybór | Dlaczego |
|---|---|---|
| Gdzie popularność wchodzi do wyniku | `bool.should: rank_feature {field: popularity, saturation: {}, boost: B}` w **jednej** metodzie `buildScoringQuery()` | `search()`, histogram cen i `_rank_eval` muszą punktować tak samo, inaczej eval mierzy coś innego niż widzi klient |
| Jak popularność trafia do dokumentu | `index.default_pipeline` w `products-v6` z procesorem `enrich` po `_id` | każdy zapis (strumień, `_reindex`, reprojekcja, UBQ) dostaje aktualną wartość, kod zapisu się nie zmienia |
| Co, gdy enrich zawiedzie | `on_failure` kasuje pola popularności i **przepuszcza** dokument | zgubiony zapis produktu jest gorszy niż produkt bez popularności |
| Źródło prawdy dla popularności | indeks enrich, nie `_source`: pierwszy procesor kasuje stare pola | inaczej `_reindex` przenosiłby popularność produktu, który wypadł z okna |
| Formuła | wygładzony **współczynnik** `(kliknięcia + α·c̄) / (wyświetlenia + α)`, nie suma | suma rośnie z ekspozycją: wygrywałyby częste frazy i to, co już stoi wysoko |
| Korekta pozycji | IPS: kliknięcie na pozycji `k` liczy się jako `1/p(k)`, `p(k)` z danych | `p(k)` z parametrów symulatora oznaczałoby, że korekta zna odpowiedź |
| Prior dla IPS | `c̄_ips = Σ 1/p(k) / wyświetlenia` (skala IPS), dla surowej `c̄ = kliknięcia / wyświetlenia` | prior w złej skali znowu wiąże popularność z ekspozycją (błąd znaleziony w recenzji, krok 3) |
| Liczenie | Transform **wsadowy** odtwarzany komendą `search:popularity:refresh` | okno przesuwne 30 dni: ciągły Transform nie zapomina zdarzeń wychodzących z okna |
| Zestaw ocen do dowodu | 10 fraz z dużymi grupami remisu, oceny z `a` symulatora | istniejące `queries.yaml` oceniają grupy remisu jednakowo, więc z definicji nie zobaczą poprawy |
| Stabilna linia bazowa | `--tie-shuffles=N`: drugi klucz sortowania (seedowany hash), średnia i min–max z N przebiegów | `random_score` z wagą 1e-6 ginie w float32 (krok 5) |
| Wybór B | 2, środek plateau 0,5–4 | na tym zestawie B od 0,5 do 4 daje identyczną kolejność; 2 ma zapas w obie strony |
| Anomalie | reguły `.es-query` w trybie ES\|QL (licencja basic), reguły jako kod | jedno zapytanie liczy okno bieżące i bazę 7 dni naraz |

## 3. Mapa plików

```
apps/catalog/
  app/Services/ProductSearchService.php          buildScoringQuery(): tekst + rank_feature (gdy q != '' i B > 0)
  config/services.php                            search.popularity_boost <- SEARCH_POPULARITY_BOOST (domyślnie 0)
  app/Console/Commands/SearchEvalCommand.php     --queries=, --tie-shuffles=N (drugi klucz sortowania)
  app/Console/Commands/AnalyticsJudgmentsCommand.php   analytics:judgments: oceny z prawdy symulatora
  app/Services/Search/RelevanceJudgments.php     selektor ids: {id: ocena}
apps/search/
  src/Analytics/PositionPropensity.php           p(k) z CTR wg pozycji: PAV, normalizacja do poz. 1, podłoga 0,01
  src/Analytics/PopularityFormula.php            (licznik + α·prior) / (wyświetlenia + α), PHP i bucket_script
  src/Analytics/PopularityRefresher.php          p(k) -> Transform -> _execute enrich -> _update_by_query
  src/Command/PopularityRefreshCommand.php       search:popularity:refresh [--variant --as-of --window-days --alpha --rps --reset]
infra/elasticsearch/
  mappings/products-v6.json                      v5 + popularity (rank_feature), popularity_raw/ips (float), default_pipeline
  analytics/index-product-popularity.json        indeks źródłowy enrich (dynamic: false)
  analytics/enrich-policy-product-popularity.json   match po product_id
  analytics/pipeline-products-enrich-popularity.json
  analytics/index-alerts-analytics.json          indeks dla connectora Index (dynamic: strict)
  setup-analytics.sh                             sekcja 3 (popularność, przed Kibaną), sekcja 5 (anomalie)
infra/kibana/rules/                              zero-results-spike.json, ctr-drop.json, 2 connectory
tests/relevance/popularity-queries.yaml          wygenerowany zestaw ocen (seed 1, 10 fraz, ~7 tys. linii)
tools/analytics/closed-loop.sh                   pętla zamknięta: dzień -> ingest -> refresh -> eval -> CSV
docs/data/etap-12/closed-loop-*.csv              trajektorie z POMIARÓW §16.4
```

Testy: `apps/catalog/tests/Unit/Search/ScoringQueryTest.php`, `tests/Feature/Search/SearchEvalTieShufflesTest.php`,
`tests/Feature/Analytics/JudgmentsCommandTest.php` (catalog); `tests/Integration/PopularityPipelineTest.php`,
`tests/Integration/PopularityRefreshTest.php`, `tests/Unit/Analytics/*`, `tests/Unit/MappingV6Test.php` (search).

## Krok 1 — jedna ścieżka punktująca

Przed tym etapem `search()` i `priceHistogram()` składały własny `bool`, a `buildSearchQuery()` (używane przez
`search:eval`) miało osobną kopię. Klauzula popularności dopisana tylko w jednym miejscu zmieniłaby `_rank_eval`, ale
nie to, co widzi klient i symulator. Albo na odwrót. Dlatego najpierw refaktor: wszystkie trzy wołają

```php
public function buildScoringQuery(string $q): array
{
    $text = $this->buildTextQuery($q);
    $boost = (float) config('services.search.popularity_boost', 0);

    if ($q === '' || $boost <= 0) {
        return $text;                       // B = 0: zapytanie bit w bit jak przed etapem
    }

    return ['bool' => [
        'must'   => [$text],
        'should' => [['rank_feature' => ['field' => 'popularity', 'saturation' => new \stdClass, 'boost' => $boost]]],
    ]];
}
```

- **Tylko przy frazie.** Dla `match_all` (przeglądanie kategorii) każdy produkt ma ten sam wynik tekstowy, a
  popularność by go zdominowała. To świadome: popularność rozstrzyga remisy tekstowe, a nie zastępuje tekstu.
- **Konfig czytany przy każdym wywołaniu.** Catalog działa na Octane (aplikacja żyje między żądaniami), a eval
  i symulator przestawiają B przez `docker compose exec -e SEARCH_POPULARITY_BOOST=…`.
- **`rank_feature` na brakującym polu nic nie dodaje.** Klauzula jest bezpieczna także na `products-v5` i na
  produktach bez popularności.
- **Facety zostają przy samym tekście** (tekst jako filtr, wynik tam nie ma znaczenia).

Weryfikacja:

```bash
docker compose exec catalog-app php artisan test --compact tests/Unit/Search/ScoringQueryTest.php
# snapshot B=0 (zapytanie bez zmian), rank_feature przy B=2, brak przy q='', parytet search()/eval, facety
make eval     # przed i po refaktorze: 1.000 na 13 zapytaniach, identycznie
```

## Krok 2 — `products-v6` i pipeline enrich, który nigdy nie odrzuca

### Mapowanie

`products-v6.json` to `products-v5.json` tekst w tekst plus trzy pola i jedno ustawienie (pilnuje tego
`MappingV6Test`):

```json
"settings": { "index": { "default_pipeline": "products-enrich-popularity", ... } },
"mappings": { "properties": {
  "popularity":     { "type": "rank_feature" },
  "popularity_raw": { "type": "float" },
  "popularity_ips": { "type": "float" }
}}
```

`popularity` służy do punktowania (wariant wybrany w refreshu), `popularity_raw` i `popularity_ips` są do wglądu
i porównań.

### Pipeline

Mapowanie produktów jest `dynamic: strict` i nie ma pola `product_id`, więc enrich dopasowuje po `_id` dokumentu.
Sprawdziłem eksperymentem na ES 9.5.1, że ingest widzi `_id` zarówno przy zwykłym zapisie (z wersją zewnętrzną),
jak i w `_update_by_query`. `infra/elasticsearch/analytics/pipeline-products-enrich-popularity.json`:

```json
"processors": [
  { "remove": { "tag": "pop-reset", "field": ["popularity", "popularity_raw", "popularity_ips"], "ignore_missing": true } },
  { "enrich": { "tag": "pop-enrich", "policy_name": "product-popularity-policy", "field": "_id",
                "target_field": "_pop", "max_matches": 1, "ignore_missing": true } },
  { "set": { "tag": "pop-rank-feature", "if": "ctx._pop?.popularity != null && ctx._pop.popularity > 0",
             "field": "popularity", "copy_from": "_pop.popularity" } },
  { "set": { "tag": "pop-raw", "if": "ctx._pop?.popularity_raw != null", "field": "popularity_raw", "copy_from": "_pop.popularity_raw" } },
  { "set": { "tag": "pop-ips", "if": "ctx._pop?.popularity_ips != null", "field": "popularity_ips", "copy_from": "_pop.popularity_ips" } },
  { "remove": { "tag": "pop-cleanup", "field": "_pop", "ignore_missing": true } }
],
"on_failure": [
  { "remove": { "field": ["_pop", "popularity", "popularity_raw", "popularity_ips"], "ignore_missing": true } }
]
```

Trzy rzeczy, każda pilnowana testem mutacyjnym (osłabiony pipeline → konkretny test pada):

1. **Pipeline nigdy nie odrzuca zapisu produktu.** Strumień z ETAPU 9 nie może gubić zmian cen i ofert przez to,
   że analityka ma awarię. `on_failure` na poziomie pipeline'u łapie wyjątek dowolnego procesora, kasuje pola
   popularności i przepuszcza dokument. Jedyny procesor w `on_failure` to `remove` z `ignore_missing`, który sam
   nie ma jak się wywrócić. Bez `on_failure` pada `test_awaria_w_pipeline_nie_odrzuca_zapisu`.
2. **Najpierw kasuj (strip-first).** Bez pierwszego `remove` `_reindex` i `_update_by_query` przenosiłyby starą
   popularność produktu, który wypadł z `product-popularity` (enrich z `ignore_missing` niczego wtedy nie nadpisze).
   Źródłem prawdy jest indeks enrich, nie `_source`. Pilnuje tego `test_stara_popularnosc_z_source_nie_przezywa_bez_wpisu`.
3. **Warunek `> 0` jest obowiązkowy.** `rank_feature` odrzuca 0 i wartości ujemne, ale to **błąd mapowania**
   przy indeksowaniu, który dzieje się już po pipeline. `on_failure` go nie złapie, więc dokument z `popularity: 0`
   zostałby odrzucony. Formuła z kroku 3 daje zawsze > 0, a warunek to druga linia obrony
   ([RUNBOOK #136](../RUNBOOK.md#136)).

### Kolejność zakładania: wymusza ją ES

Eksperymenty z budowy (szczegóły w [RUNBOOK #135](../RUNBOOK.md#135)):

| Próba | Wynik |
|---|---|
| `PUT _ingest/pipeline/…` z enrich na politykę niewykonaną albo nieistniejącą | **500** `no enrich index exists for policy` |
| `DELETE _enrich/policy/…`, gdy odwołuje się do niej pipeline | **409** `pipeline is referencing it` |
| `DELETE _ingest/pipeline/…`, gdy jest `default_pipeline` jakiegoś indeksu | **400** |
| zapis do indeksu, którego `default_pipeline` nie istnieje | **400 dla każdego zapisu** |
| odczyt `.enrich-*` (nawet jako `elastic`) | **403** (indeks zastrzeżony) |

Stąd kolejność w sekcji popularności `setup-analytics.sh` (przy budowie sekcja 4, od końcowej fali sekcja 3): pusty
indeks `product-popularity` → polityka → `_execute` → pipeline. I dopiero potem migracja na `products-v6`. Stany „polityka usunięta” i „brak indeksu enrich” nie dają się
wywołać zwykłym API, gdy pipeline już istnieje, więc test integracyjny sprawdza tor `on_failure` prawdziwą awarią
procesora (zepsuty wpis enrich: napis zamiast liczby, Painless rzuca wyjątek przy `> 0`).

```bash
make analytics-setup
# [analytics] [4] Indeks product-popularity już istnieje
# [analytics] [4] Polityka product-popularity-policy już istnieje (polityk nie da się aktualizować w miejscu)
# [analytics] [4] _execute product-popularity-policy (indeks enrich z bieżącego product-popularity)
# [analytics] [4] Pipeline products-enrich-popularity (on_failure: zapis produktu nigdy nie jest odrzucany)
# [analytics] [4] OK
```

Przy pierwszym uruchomieniu zamiast „już istnieje” są linie `Tworzę pusty indeks…` i `Tworzę politykę enrich…`.
Polityki enrich nie da się zmienić w miejscu; procedura przebudowy jest w nagłówku `setup-analytics.sh` (pusty
pipeline → `DELETE` polityki → `make analytics-setup` → refresh).

> **Uwaga na kolejność sekcji.** Przy budowie sekcja popularności (wtedy 4) stała po imporcie do Kibany (3): gdy Kibana
> nie odpowiadała, skrypt kończył się przed pipeline'em. W końcowej fali ETAPU 12 kolejność odwrócona: popularność
> to sekcja 3 (linie `[3]`), Kibana 4. Powyższy wydruk pochodzi sprzed zmiany. Na świeżym klastrze i tak najpierw
> `make analytics-setup`, potem migracja na v6 — a gdyby ktoś to pomylił, `search:index:create` i
> `search:index:migrate prepare` odmówią utworzenia indeksu z `default_pipeline`, którego nie ma w klastrze.

### Migracja v4 → v5 → v6 w dwóch przebiegach

`make reindex` (narzędzia ETAPU 10) zawsze bierze `vN → vN+1` z celu aliasu i nie ma opcji „skocz do v6”. Aliasy
wskazywały `products-v4`. Plik `products-v5.json` istniał (przebudowa bez zmian mapowania z ETAPU 10), ale indeksu
`products-v5` fizycznie nie było. Zamiast dopisywać `--to=` poszły dwa przebiegi: v4 → v5 jako czysta przebudowa,
potem v5 → v6 ([RUNBOOK #137](../RUNBOOK.md#137)).

```
reindeks products-v4 → products-v5   reindex 29 s | finalize 26 s | razem 69 s
Bramka products-v5: zdrowie=green, dokumenty=297777, catalog=297777 → GATE=PASS
nDCG@10: products-v4=1.000000 products-v5=1.000000

reindeks products-v5 → products-v6   reindex 41 s | finalize 24 s | razem 79 s
Bramka products-v6: zdrowie=green, dokumenty=297777, catalog=297777 → GATE=PASS
nDCG@10: products-v5=1.000000 products-v6=1.000000
```

`_reindex` przez pipeline jest ~40% wolniejszy (29 s → 41 s). Koszt drugiego przebiegu: ~70 s.

Weryfikacja na żywo (`elastic:…` = hasło z `ELASTIC_PASSWORD` w `.env`):

```bash
curl -s -u elastic:… 'http://localhost:19200/_cat/aliases/products-*?v'
# products-search products-v6 ...
# products-write  products-v6 ... true
curl -s -u elastic:… 'http://localhost:19200/products-v6/_settings/index.default_pipeline?flat_settings=true'
# {"products-v6":{"settings":{"index.default_pipeline":"products-enrich-popularity"}}}
curl -s -u elastic:… 'http://localhost:19200/_nodes/stats/ingest?filter_path=nodes.*.ingest.pipelines.products-enrich-popularity'
# "count": 893101, "failed": 0      <- reindeks, strumień i UBQ refreshy, ani jednej awarii
```

Po switchu: `make churn rate=10 duration=10` → `updated=92 deleted=8 failed=0`, `search:verify-convergence` →
`checked=97 mismatches=0`. Zapisy strumienia jako `searchsvc` przechodzą przez pipeline i zachowują wersję
zewnętrzną (`projected_at_us`).

## Krok 3 — `product-popularity`: p(k) z danych, IPS i prior w dobrej skali

### Dlaczego współczynnik, a nie suma

Pierwszy odruch to „suma kliknięć”. Suma rośnie z liczbą wyświetleń. Frazy mają rozkład Zipfa, więc produkty
z częstych fraz wygrałyby tylko dlatego, że częściej je pokazujemy. A to, co już stoi wysoko, dostaje więcej
wyświetleń i kliknięć, więc suma utrwala obecny ranking („bogaci się bogacą”). W kontroli bez sygnału popularność
z sumy byłaby po prostu miarą ekspozycji.

Współczynnik kliknięcia na wyświetlenie tego nie ma, ale przy 2 wyświetleniach i 1 kliknięciu daje 0,5, czyli
szum. Stąd wygładzenie (prior bayesowski): `α` pseudo-wyświetleń o średnim CTR `c̄`.

```
surowa: (kliknięcia + α·c̄) / (wyświetlenia + α)
IPS:    (Σ po kliknięciach 1/p(k) + α·c̄_ips) / (wyświetlenia + α)       α = 20
```

Produkt bez danych dostaje dokładnie prior, a im więcej wyświetleń, tym bliżej własnego CTR. Prior i α są > 0, więc
wynik zawsze > 0 (wymóg `rank_feature`). `PopularityFormula` to jedno źródło wzoru: ten sam napis idzie do
`bucket_script` w Transformie, a wersja PHP służy testom.

### p(k): skłonność obejrzenia pozycji, szacowana z danych

Kliknięcie na pozycji 1 mówi mniej niż kliknięcie na pozycji 15, bo pozycję 1 ogląda prawie każdy. IPS (inverse
propensity scoring) waży każde kliknięcie odwrotnością prawdopodobieństwa obejrzenia: `1/p(k)`.

`p(k)` nie bierzemy z symulatora (`1/k^η`), bo wtedy korekta „znałaby odpowiedź”. `PositionPropensity` liczy je
z tych samych danych co popularność:

1. CTR na pozycji `k` = kliknięcia(k) / wyświetlenia(k), dla wszystkich zapytań razem.
2. Regresja izotoniczna (PAV, wagi = wyświetlenia): `p(k)` nie może rosnąć z pozycją. Sąsiednie pozycje
   z odwróconym CTR są łączone w pulę.
3. Normalizacja do wygładzonego CTR pozycji 1, podłoga 0,01 (pojedyncze kliknięcie waży najwyżej 100).
4. Odmowa **przed jakimkolwiek zapisem**, gdy w oknie nie ma wyświetleń, kliknięć na 1–20 albo CTR na pozycji 1.

Tabela z żywego przebiegu (`make popularity-refresh variant=ips as_of=2026-11-20`, dane z symulacji 12a):

```
Okno [2026-10-21T00:00:00Z, 2026-11-20T00:00:00Z), wariant ips, α=20
  k   wyświetlenia  kliknięcia  CTR     p(k)
  1   17047         4168        0.2445  1.0000
  2   17047         3097        0.1817  0.7430
  3   17047         1212        0.0711  0.2908
  4   17047          976        0.0573  0.2378
  5   17047         1006        0.0590  0.2378   <- PAV połączył 4 i 5
  ...
 19   17047          226        0.0133  0.0579
 20   17047          257        0.0151  0.0579   <- PAV połączył 19 i 20
c̄ (prior surowy)  0.05038
c̄_ips (prior IPS) 0.24450
produktów 6797 | UBQ updated=6797 version_conflicts=0 total=6797
czasy: p(k) 0.0 s, transform 1.3 s, enrich _execute 0.6 s, update_by_query 2.0 s
```

Oszacowane `p(2) = 0,743`, a symulator ma `1/2 = 0,5`. To oczekiwane: CTR wg pozycji miesza pozycję z tym, co na niej
stoi, a 5% sesji klika losowo. Korekta nie jest skalibrowana do symulatora i nie powinna być.

### Prior w skali IPS: błąd znaleziony w recenzji

Pierwsza wersja miała jeden prior `c̄ = kliknięcia / wyświetlenia = 0,050` dla obu wariantów. W recenzji wyszło,
że to psuje IPS. Licznik IPS (`Σ 1/p(k)`) jest w innej skali niż surowe kliknięcia: średnio
`Σ 1/p(k) / wyświetlenia = 0,2445`, prawie 5× więcej. Skutek:

- produkt z małą liczbą wyświetleń jest ściągany do 0,05 (prior), a produkt dobrze wyeksponowany zbliża się do swojej
  stopy IPS ~0,24;
- im więcej wyświetleń, tym **wyżej** średnio ląduje popularność IPS, niezależnie od atrakcyjności;
- czyli IPS znowu rankuje po ekspozycji, a w kontroli bez sygnału (`--flat-attractiveness`) przestałaby być
  ≈ stała i mogłaby „poprawiać” ranking. Dowód byłby wadliwy.

Poprawka (`22003d0`): każdy prior w skali swojego licznika. Surowa: `c̄ = kliknięcia(1..20) / wyświetlenia`. IPS:
`c̄_ips = Σ_kliknięcia 1/p(k) / wyświetlenia`. Wtedy produkt bez danych stoi na poziomie przeciętnego produktu
**w tej samej skali**, a w kontroli wszystkie produkty dążą do tej samej wartości. Test integracyjny ma to wprost:
produkty 1 i 2 mają ten sam CTR po korekcie pozycji, więc dostają tę samą popularność IPS (8/30), a surowa daje im
różne wartości (5/30 vs 4/30).

Ciekawostka z żywych danych: `c̄_ips = 0,2445 = ctr(1)` dokładnie. PAV zachowuje sumy w pulach, więc
`Σ kliknięcia_k / p_k = ctr(1) · Σ wyświetlenia`. Po poprawce minimum `popularity_ips` wzrosło z 0,0025 do 0,0121:
produkty z małą ekspozycją są ściągane do ~ctr(1), a nie do 0,05.

### Komenda `search:popularity:refresh`

Cztery kroki (`PopularityRefresher`):

1. **p(k), c̄, c̄_ips**: jedno zapytanie `size: 0` na `events-impression-*,events-user-*` w oknie
   `[as_of − 30 dni, as_of)`.
2. **Transform `product-popularity`** (wsadowy): usunięcie starego, `_delete_by_query` na `product-popularity`
   (wynik sprawdzany: `deleted < total` przerywa), utworzenie, start i czekanie na `stopped` z checkpointem ≥ 1.
   Źródło: oba streamy. Wagi `1/p(k)` idą jako `params.weights` do `runtime_mappings.click_weight` (Painless, pozycje
   > 20 z wagą p(20)). Pivot po `product.id`: `impressions`, `clicks`, `clicks_ips`, `popularity_raw`,
   `popularity_ips` i `popularity` (wybrany wariant) jako `bucket_script`.
3. **`_execute` polityki enrich**: nowy `.enrich-*` z aktualnego `product-popularity`. Do tej chwili żywy pipeline
   czyta starą kopię, więc chwilowo pusty `product-popularity` nikomu nie szkodzi. Jeśli przebieg padnie w kroku 2,
   pada przed publikacją i na żywo zostaje stan poprzedni.
4. **`_update_by_query`** na `products-write` z `pipeline=products-enrich-popularity`, w tle, `conflicts=proceed`,
   z `--rps`. **Zawężone**: `ids` produktów z nowego wyniku LUB `exists popularity_raw` LUB `exists popularity_ips`
   (żeby wyczyścić tych, którzy wypadli). Każdy inny dokument przeszedłby przez pipeline bez zmian. 6797 aktualizacji
   zamiast ~298 tys. Powyżej 50 tys. ID zawężenie przechodzi w `match_all` (limit `terms`).

**Wersje:** UBQ zapisuje V+1 wewnętrznie, a wersja zewnętrzna to `projected_at_us` (~1,8e15). Ten sam argument co przy
zmianie nazwy sprzedawcy w ETAPIE 10: następna zmiana ze strumienia ma większą wersję, kolejność zostaje.

**Dlaczego Transform wsadowy:** okno przesuwne. Ciągły Transform tylko dopisuje i aktualizuje kubełki, nie
„zapomina” zdarzeń wychodzących z okna ([RUNBOOK #130](../RUNBOOK.md#130)). Przeliczenie całości trwa ~1,3 s.
Definicja (okno, α, oba priory, wariant) ląduje w opisie Transformu, widocznym w Kibanie:

```bash
curl -s -u elastic:… 'http://localhost:19200/_transform/product-popularity?filter_path=transforms.description'
# "ETAP 12: popularność produktów, okno [2026-10-21T00:00:00Z, 2026-11-20T00:00:00Z), α=20, c̄=0.05416,
#  c̄_ips=0.29835, popularity=ips. Wsadowy — odtwarzany przez search:popularity:refresh."
```

### Na żywym produkcie: co robi IPS

Stan po ostatnim przebiegu dowodu (popularność z 30 dni symulacji pętli otwartej, wariant ips):

```bash
curl -s -u elastic:… 'http://localhost:19200/products-search/_doc/224507?_source_includes=name,popularity*'
# "name": "Franecki-Carroll Wiertarka Prime",
# "popularity_raw": 0.04169, "popularity_ips": 0.54064, "popularity": 0.54064
```

W `product-popularity`: 3436 wyświetleń, 143 kliknięcia, `Σ 1/p = 1862,5`. Ręcznie:
surowa `(143 + 20·0,05416) / (3436 + 20) = 0,0417`, IPS `(1862,5 + 20·0,29835) / 3456 = 0,5406`. Produkt jest
wyświetlany często, ale nisko: ma mało kliknięć, za to na dalekich pozycjach, gdzie mało kto patrzy. Surowa uznaje go
za słaby, IPS za jeden z najlepszych. To dokładnie przypadek, dla którego IPS istnieje.

Weryfikacja kodu:

```bash
docker compose exec search-consumer php bin/phpunit tests/Unit/Analytics tests/Integration/PopularityRefreshTest.php
# p(2)=0,5, c̄=0,15, c̄_ips=0,3; ips 8/30 vs raw 5/30; granice okna [from, to); wiersz 999 znika;
# UBQ updated=4, _version V+1; pusty zakres = odmowa bez zapisu
```

## Krok 4 — przyrząd pomiarowy: oceny z ukrytej prawdy symulatora

`tests/relevance/queries.yaml` (13 zapytań z ETAPU 7) ocenia całe grupy remisu jednakowo („każdy smartfon = 2”), więc
z definicji nie zauważy, że wewnątrz grupy lepszy smartfon wszedł wyżej. Do dowodu potrzebny jest osobny zestaw:

```bash
make popularity-judgments          # = analytics:judgments --seed=1 --queries=10, kopiuje plik do tests/relevance/
```

Jak `analytics:judgments` wybiera frazy i oceny:

- Ranking z `buildSearchQuery` przy **wymuszonym B = 0** i stałej `preference` (kopie shardów mają minimalnie różne
  statystyki BM25: ta sama grupa dała 8,748777 albo 8,752492).
- Kandydat: fraza z ≥ 1 trafieniem i grupą identycznego `_score` ≥ 20 w top 50 (pula 404 fraz z symulatora).
- Oceniany zbiór = **wszystkie** produkty z `_score` ≥ wyniku 50. trafienia, czyli top 50 plus cała grupa remisu na
  granicy. Sam top 50 nie wystarczy: tasowanie wynosiłoby do top 10 produkty bez oceny, bo grupy mają ~700 członków.
  Frazy ze zbiorem > 1000 (kategorie, całe słowa: grupy 5–17 tys.) odpadają.
- Ocena = `min(3, ⌊a·4⌋)` z ukrytej atrakcyjności. Zapisane są wszystkie oceny, także 0, przez selektor
  `ids: {id: ocena}` (dodany do `RelevanceJudgments`; nieistniejące ID przerywają eval głośno).
- Plik jest deterministyczny (drugie uruchomienie: `diff` pusty). Wybrane frazy: „Romaguera Inc”, „smartfon extreme
  pro”, „hantle kompakt zestaw”, „lampa led nova” … po ~690–730 ocen na frazę.

**Co to mierzy, a czego nie** (to samo zdanie stoi w nagłówku pliku): czy pętla odzyskuje ukrytą prawdę
z zaszumionych, przesuniętych przez pozycję kliknięć. **Nie** mierzy, czy ludzie uznaliby wyniki za trafniejsze:
atrakcyjność to Beta z hasha ID, a oceny to jej zaokrąglenie. Wynik pozytywny znaczy „mechanizm działa zgodnie
z modelem klienta”, a nie „klienci są zadowoleni”.

Dwie uwagi do pliku: frazy dowodu nie są frazami najczęstszymi (zestaw wybiera duże grupy remisu, a symulator losuje
frazy z rozkładu Zipfa, więc rzadsza fraza dostaje mniej kliknięć), a po przebudowie katalogu plik trzeba
wygenerować od nowa.

## Krok 5 — tasowanie remisów: drugi klucz sortowania, nie `random_score`

Bez popularności kolejność w remisie to wewnętrzny doc id Lucene. Zmienia się przy każdej przebudowie (a migracja na
v6 to przebudowa) i zależy od kopii shardu. Pojedynczy przebieg evalu jest więc losowy, a `search:eval` przy N = 0
słusznie kończy się ostrzeżeniem NIEDETERMINISTYCZNY (kod 2, [RUNBOOK #096](../RUNBOOK.md#096)). Potrzebna jest
średnia z wielu losowych ułożeń remisów, przy czym remisy mają się tasować, a reszta kolejności nie może drgnąć.

### Plan z projektu: `random_score` z wagą 1e-6. Nie działa

Pomysł był taki: `function_score { random_score {seed: i}, weight: 1e-6, boost_mode: sum }`. Dodatek < 1e-6 miał
rozstrzygać tylko remisy. Sprawdzone na żywym klastrze: **`_score` to float32**.

- Przy `_score` ≈ 27 odstęp między sąsiednimi floatami (ulp) to 2^-19 ≈ 1,9e-6.
- Dodatek `r·1e-6` mniejszy od połowy ulp (~0,95e-6) zaokrągla się do zera. To ~95% dokumentów. Reszta dostaje
  +1 ulp.
- Na żywo top 50 po owinięciu dalej ma **jeden** `_score` („smartfony”, waga 1e-6). Zamiast losowej kolejności
  powstają dwa poziomy, a w każdym dalej rządzi doc id Lucene. Ziarno wybiera losowy podzbiór (~5%) w kolejności
  Lucene. Grupa < ~200 dokumentów prawie się nie tasuje.
- Większa waga (1e-4) przy B > 0 zaczęłaby przestawiać dokumenty o **różnych** wynikach, bo z `rank_feature` wyniki
  są prawie ciągłe. Nie ma wagi, która jest jednocześnie widoczna i nieszkodliwa.

Szczegóły i jak to zdiagnozować: [RUNBOOK #133](../RUNBOOK.md#133).

### Co jest zamiast: `sort: [_score desc, hash asc]`

Przebieg `i` dokłada do żądania `_rank_eval` sortowanie, a `query` zostawia bez zmian:

```json
"sort": [
  { "_score": "desc" },
  { "_script": { "type": "number", "order": "asc",
      "script": { "source": "<SplitMix64(_seq_no, created_at, seed)>", "params": { "seed": i } } } }
]
```

- `_score` liczony jest bit w bit tak samo, zmienia się tylko kolejność dokumentów o **identycznym** wyniku. Gwarancja
  wynika z konstrukcji, nie z progu.
- `_seq_no` jest wspólny dla primary i repliki, więc kolejność nie zależy od tego, którą kopię trafi zapytanie.
  `created_at` rozdziela kolizje `_seq_no` między shardami.
- Domyślnie `--tie-shuffles=0`: jeden przebieg i zachowanie jak dotąd (strażnik remisów, kod 2). Przy N > 0 strażnik
  jest zbędny (remisy z różnymi ocenami są właśnie tym, co uśredniamy), kod wyjścia 0, a tabela ma średnią, min, max
  i maks. liczbę nieocenionych w top-k.

**Dowody:**
- `SearchEvalTieShufflesTest` (fałszywy ES): `query` w każdym przebiegu === `query` bez tasowania, `sort[0]` to
  `_score desc`, ziarna 1..N.
- Na żywo: 10 fraz × 20 ziaren × B ∈ {0, 2} = 400 przebiegów. Sekwencja `_score` w top 50 identyczna jak bez
  tasowania, zbiory ID każdej grupy też. **0 naruszeń.** Kolejność ID zmieniła się we wszystkich 400.
- `_rank_eval` naprawdę respektuje `sort`: ten sam `request` przez `_rank_eval` i `_search` daje te same top 10
  (POMIARY §16.1). Bez `sort` w jednej z dwóch prób wyszło inaczej, bo zapytania trafiły w różne kopie shardu
  ([RUNBOOK #134](../RUNBOOK.md#134)).

```bash
docker compose exec -T -e SEARCH_POPULARITY_BOOST=0 catalog-app php artisan search:eval \
  --queries=/tests/relevance/popularity-queries.yaml --tie-shuffles=20
# Średnie nDCG@10 z 20 przebiegów: 0.151 (min 0.101, max 0.189) na 10 zapytaniach
# NDCG=0.15074650298124
# NDCG_MIN=0.10096105370592
# NDCG_MAX=0.18943863455149
```

Linie `NDCG=`, `NDCG_MIN=`, `NDCG_MAX=` są dla skryptów. min i max łączne to skrajne średnie pojedynczych przebiegów.
Eval jest deterministyczny przy niezmienionym indeksie: to samo polecenie kilka godzin po pomiarze dało co do cyfry
to samo co w POMIARACH.

## Krok 6 — pętla zamknięta jednym skryptem

Pętla otwarta (dowód główny) zbiera kliknięcia na **stałym** rankingu (B = 0) i dopiero potem włącza popularność.
Pętla zamknięta to lekcja o sprzężeniu zwrotnym: popularność policzona z dni 1..d−1 wpływa na kliknięcia dnia d.

```bash
make closed-loop variant=ips days=30 boost=2          # pyta "tak"; yes=1 pomija pytanie; flat=1 = kontrola
```

`tools/analytics/closed-loop.sh` dla każdego dnia d:

1. `analytics:simulate --day=d --as-of=… ` z `-e SEARCH_POPULARITY_BOOST=B` (ranking z bieżącą popularnością);
2. czekanie, aż kolejka `search.analytics.ingest` będzie pusta w dwóch kolejnych odczytach, potem `_refresh` streamów;
3. `search:popularity:refresh --as-of=<północ po dniu d>` (okno liczone względem symulowanego „dziś”, nie zegara);
4. `search:eval --tie-shuffles=10` z tym samym B, wiersz CSV do `docs/data/etap-12/closed-loop-<wariant>-<czas>.csv`.

> **Uwaga:** na starcie skrypt **kasuje wszystkie dokumenty** w `events-user-default` i `events-impression-default`
> (także demo z 12a) i zeruje popularność (`search:popularity:refresh --reset`). Ctrl-C przerywa (kod 130),
> stan ES zostaje taki, jak w chwili przerwania.

Pierwszy przebieg raw zawisł w dniu 17 na 24 min: `rabbitmqctl list_queues` w kontenerze RabbitMQ nie odpowiadał,
a limit `DRAIN_TIMEOUT` był sprawdzany dopiero po jego powrocie. Teraz odczyt idzie przez `with_timeout` (perl, własna
grupa procesów, domyślnie 20 s, `RMQCTL_TIMEOUT`), a zawieszony odczyt liczy się jako „kolejka zajęta”
([RUNBOOK #138](../RUNBOOK.md#138)). Przebieg 30 dni trwa ~10 min na wariant.

## Krok 7 — dowód: wyniki, kontrola, plateau B

Wszystkie liczby z [POMIARÓW §16.1–16.6](../POMIARY.md) (2026-11-20, 16:20–17:35 UTC): `products-v6`, symulator
seed 1, 300 sesji/dzień, η = 1, szum 5%, okno 30 dni, α = 20, eval 20 tasowań.

### Protokół pętli otwartej

```bash
# 1. pusta kolejka, czyste streamy i zerowa popularność
curl -s -u elastic:… -X POST -H 'Content-Type: application/json' \
  'http://localhost:19200/events-user-default,events-impression-default/_delete_by_query?refresh=true&conflicts=proceed' \
  -d '{"query":{"match_all":{}}}'
docker compose exec search-consumer php bin/console search:popularity:refresh --reset
# 2. 30 dni przy stałym rankingu (B = 0)
docker compose exec -e SEARCH_POPULARITY_BOOST=0 catalog-app php artisan analytics:simulate --days=30 --as-of=2026-11-20
# 3. czekać, aż analytics-consumer opróżni kolejkę (~80 s), potem dla każdego wariantu:
make popularity-refresh variant=raw as_of=2026-11-20
for b in 0 0.5 1 2 4 8 16; do
  docker compose exec -T -e SEARCH_POPULARITY_BOOST=$b catalog-app php artisan search:eval \
    --queries=/tests/relevance/popularity-queries.yaml --tie-shuffles=20 | grep '^NDCG'
done
make popularity-refresh variant=ips as_of=2026-11-20      # i ta sama pętla evalu
```

Czasy: symulacja ~21 s, ingest 78–85 s (~38 tys. zdarzeń + 341 tys. wyświetleń), refresh ~4 s, eval 20 przebiegów ~1 s.

### Pętla otwarta: popularność pomaga

| wariant | B | nDCG@10 (1. przebieg) | min–max | nDCG@10 (powtórka) |
|---|---|---|---|---|
| — | 0 | 0,156 / 0,158 | 0,107–0,205 | 0,151 / 0,151 |
| raw | 0,5 / 1 / 2 | **0,2453** | 0,240–0,252 | **0,2131** |
| ips | 0,5 / 1 / 2 / 4 | **0,2593** | 0,255–0,264 | **0,2214** |
| raw | 4 | 0,2452 (1 nieoceniony w top 10) | | 0,2131 |
| ips | 8 / 16 | 0,2581 / 0,2485 (1 / 4 nieocenione) | | 0,2208 / 0,2101 |

Co z tego wynika:

- **Popularność podnosi nDCG@10 z ~0,15 do 0,21–0,26** w obu przebiegach (+0,06…+0,10, czyli +40…+65%).
- **IPS > surowa w obu przebiegach** (+0,014 i +0,008). Raw i ips liczone są z tych samych zdarzeń, więc różnica jest
  mniej zaszumiona niż poziomy. Przewaga ma stały znak, ale jest mała, a w powtórce przedziały min–max się nakładają.
  Bez potwierdzenia statystycznego.
- **Rozrzut min–max maleje z ~0,09 do ~0,01.** Produkty z popularnością przestają remisować, tasują się już tylko te
  bez kliknięć.
- **Bezwzględne nDCG jest niskie (~0,26)**, bo ideał to cała oceniona grupa (~700 produktów) ułożona po `a`, a sygnał
  dostają tylko produkty, które ranking przy B = 0 w ogóle pokazał w top 20.

### Plateau: B od 0,5 do 4 daje tę samą kolejność

To najciekawszy wynik do zrozumienia `rank_feature`. Z `saturation` klauzula dodaje do wyniku `B · s(p)`, gdzie
`s(p) = p / (p + pivot)` leży w (0, 1). W grupie remisu wynik tekstowy jest identyczny, więc o kolejności decyduje
tylko `B · s(p)`. Funkcja jest rosnąca, więc **każde B > 0 układa grupę tak samo**: po popularności. B zaczyna mieć
znaczenie dopiero wtedy, gdy `B · s` przeskakuje różnicę wyniku tekstowego **między** grupami. Wtedy do top 10
wchodzą produkty z gorzej pasującej grupy, spoza ocenianego zbioru (kolumna „nieocenione”), a nDCG spada. Tu
pierwsze przeskoki są przy B = 4 (raw) i 8 (ips).

Wniosek praktyczny: na tym zestawie B jest przełącznikiem „popularność rozstrzyga remisy: tak/nie”, a nie pokrętłem.
Pokrętłem staje się dopiero przy B tak dużym, że popularność zaczyna wygrywać z tekstem, a tego nie chcemy.

### Kontrola: popularność nie „poprawia” bez sygnału

Kryterium zapisane **przed** uruchomieniem kontroli (16:30 UTC): w `--flat-attractiveness` (stała atrakcyjność 0,3,
kliknięcia zależą tylko od pozycji) dla IPS przy każdym B ∈ {0,5, 1, 2, 4}
`|nDCG(B) − nDCG(0)| < (max − min)` linii bazowej kontroli. Przekroczenie = dowód wadliwy.

| wariant | B | nDCG@10 | Δ vs B = 0 | nieocenione |
|---|---|---|---|---|
| ips | 0 | 0,1540 (0,107–0,197) | — (**próg 0,0903**) | 0 |
| ips | 0,5–4 | 0,1557 | **+0,0017** | 0 |
| ips | 16 | 0,1401 | −0,0139 | 6 |
| raw | 0 | 0,1570 | — | 0 |
| raw | 0,5–2 | 0,1558 | −0,0012 | 0 |
| raw | 8 / 16 | 0,1399 / 0,1339 | −0,017 / −0,023 | 8 / 10 |

**Werdykt: kryterium spełnione, dowód nie został sfalsyfikowany.** Δ = 0,002 przy efekcie 0,10 w pętli z sygnałem,
czyli ~60× mniej. Surowa w kontroli też stoi: utrwala kolejność z B = 0, ale ta kolejność jest przypadkowa względem
prawdy. Spadki przy dużym B (obie wersje) to przeskoki grup tekstowych.

Uczciwie o progu: próg (rozrzut tasowań ~0,09) jest tego samego rzędu co efekt, więc kryterium jest łagodne. Do tego
porównuje średnią z 20 tasowań z rozrzutem pojedynczych tasowań, a szum średniej to mniej więcej rozrzut/√20 ≈ 0,02.
Mocniejszym argumentem jest sama wielkość Δ.

### Pętla zamknięta (B = 2)

| dzień | raw | ips |
|---|---|---|
| 1 | 0,161 | 0,175 |
| 10 | 0,193 | 0,217 |
| 17 | 0,203 | **0,254** (maksimum ips) |
| 30 | **0,228** | **0,230** |
| odniesienie: pętla otwarta, B = 2 | 0,213–0,245 | 0,221–0,259 |

CSV: `docs/data/etap-12/closed-loop-raw-20261120T171920Z.csv` i `closed-loop-ips-20261120T170658Z.csv`.

- Obie trajektorie rosną od ~0,16–0,17 do ~0,23. Klasycznego „bogaci się bogacą” (surowa zjeżdża w dół) **nie widać**.
- IPS jest wyżej przez większość miesiąca (dni 11–17 o ~0,05), ale w dniu 30 obie są równe, a przerwany pierwszy
  przebieg raw był we wczesnych dniach wyżej niż IPS. Na jednym przebiegu na wariant różnica jest na granicy szumu.
- IPS ma maksimum w dniu 17, potem spada w stronę surowej. Hipoteza (niesprawdzona): przy włączonym boost lepsze
  produkty stoją wyżej, więc CTR wg pozycji miesza pozycję z atrakcyjnością, oszacowane `p(k)` jest za strome i IPS
  przestaje poprawnie zdejmować bias. To jest znane ograniczenie szacowania `p(k)` z danych obserwacyjnych.
- Pętla zamknięta kończy w zakresie pętli otwartej. Przebiegi są niesparowane, a szum ~±0,03, więc **mierzalnego
  kosztu sprzężenia zwrotnego tu nie widać**. Utrata eksploracji przy dłuższym horyzoncie to hipoteza.

### Niedeterminizm: ±0,03 i ±0,05

Powtórka pętli otwartej z tym samym ziarnem dała inną liczbę kliknięć (18 465 vs 17 861) i niższe poziomy
(0,213/0,221 zamiast 0,245/0,259). Przyczyna: symulator bierze **prawdziwy** ranking z `search()`, a tam przy B = 0
remisy rozstrzyga kopia shardu. Symulator jest deterministyczny co do rozkładu, ale nie co do sztuki. Liczby
z jednego przebiegu traktować z dokładnością ~±0,03 (pętla otwarta) i ~±0,05 (pętla zamknięta, wczesne dni: tyle
różniły się przerwany i powtórzony przebieg raw).

### Strażnik i wybór B

`make eval` (13 zapytań z `queries.yaml`) z popularnością IPS:

| B | 0 | 0,5 | 1 | 2 | 4 | 8 | 16 |
|---|---|---|---|---|---|---|---|
| nDCG@10 | 1,000 | 1,000 | 1,000 | 1,000 | 1,000 | 1,000 | **0,914** |

**Wybór: `SEARCH_POPULARITY_BOOST=2`** (`compose.yaml` `${SEARCH_POPULARITY_BOOST:-2}`, `.env.example`). Pełny zysk
(plateau), 0 nieocenionych w top 10 we wszystkich pomiarach przy B ≤ 2, strażnik czysty do B = 8. To wybór
asekuracyjny, nie optimum: zestaw nie rozróżnia B wewnątrz 0,5–4, a 2 ma zapas w dół (inne frazy, inne skale wyniku)
i w górę (do pierwszych przeskoków). Po ustawieniu: `make eval` = 1,000, `popularity-queries` = 0,221.

k3s zostaje przy B = 0: `deploy/k8s` nie ustawia `SEARCH_POPULARITY_BOOST`, a `config/services.php` ma domyślnie 0.

### Zastrzeżenia, zebrane

- **Prawda jest syntetyczna** (krok 4). Mierzymy odzyskiwanie modelu symulatora.
- **IPS > surowa** w 2 z 2 sparowanych przebiegów, ale o 0,008–0,014 i bez testu statystycznego.
- **Nieocenione dokumenty działają przeciw boostowi.** Produkt spoza ocenianego zbioru liczy się jako 0, nawet jeśli
  jest atrakcyjny. Przy B ≤ 2 było ich 0, więc tam zysk jest zmierzony wprost; wyniki przy B ≥ 4 są dolnym oszacowaniem.
- **Jeden przebieg pętli zamkniętej na wariant** (raw powtórzony z konieczności). Brak wykresu PNG (na maszynie nie ma
  matplotlib ani gnuplot).

## Krok 8 — reguły anomalii w ES|QL

### Typ reguły

`GET /api/alerting/rule_types` pokazał, że `.es-query` jest w licencji basic, a jej tryb `searchType: esqlQuery`
działa. ES|QL wygrał, bo jedno zapytanie liczy oba okna (ostatnie 15/60 min i bazę z 7 dni) i porównuje je w `WHERE`.
W Query DSL to byłyby dwie agregacje plus porównanie poza zapytaniem.

Pułapka: Kibana nakłada na zapytanie ES|QL filtr czasu `timeWindowSize` po `@timestamp`. Dlatego okno reguły to
**7 dni** (baza), a okno „ostatnie 15 min” siedzi w samym zapytaniu (`NOW() - 15 minutes`). Reguła odpala, gdy
zapytanie zwróci wiersz (`threshold: [0]`, `>`).

`infra/kibana/rules/zero-results-spike.json` (zapytanie):

```
FROM events-user-*
| WHERE event.type == "user.searched"
| EVAL is_recent = CASE(@timestamp >= NOW() - 15 minutes, 1, 0), is_zero = CASE(search.total_hits == 0, 1, 0)
| STATS recent_searches = SUM(is_recent), recent_zero_results = SUM(is_recent * is_zero),
        baseline_searches = SUM(1 - is_recent), baseline_zero = SUM((1 - is_recent) * is_zero)
| EVAL recent_ratio = TO_DOUBLE(recent_zero_results) / recent_searches, baseline_ratio = TO_DOUBLE(baseline_zero) / baseline_searches
| WHERE recent_searches >= 20 AND recent_zero_results >= 5 AND recent_ratio > 3 * baseline_ratio
| KEEP recent_searches, recent_zero_results, recent_ratio, baseline_searches, baseline_ratio
```

Sztuczka: `CASE` zamienia warunek na 0/1, a `SUM(is_recent * is_zero)` to „licz wiersze spełniające oba warunki”.
Jeden `STATS` daje cztery liczniki naraz.

`ctr-drop.json` jest zbudowane tak samo: CTR (kliknięcia/wyszukania) ostatnich 60 min < 0,5 × CTR reszty 7 dni,
minimum 30 wyszukań w oknie.

| | Spec | Zbudowane | Dlaczego |
|---|---|---|---|
| baza zero results | mediana dzienna z 7 dni | udział zbiorczy z 7 dni (bez ostatnich 15 min) | jeden `STATS`; mediana wymagałaby dwóch poziomów agregacji; przy równym ruchu dziennym obie bazy są bliskie |
| baza CTR | ta sama godzina doby średnio z 7 dni | cały poprzedni 7-dniowy okres | symulator nie ma zależności CTR od godziny |
| minimalny wolumen | — | ≥ 20 / ≥ 5 (zero results), ≥ 30 (CTR) w oknie | bez tego 1 z 2 wyszukań bez wyników to „50%” |

Harmonogram co 1 min, `notify_when: onActionGroupChange` (powiadomienie przy przejściu w stan aktywny, nie co
minutę). Akcje: connector Index `analytics-alerts-index` → indeks `alerts-analytics` (`dynamic: strict`; nazwa
reguły, czas, komunikat, zmierzone wartości z `{{#context.hits}}{{_source.pole}}{{/context.hits}}`) i connector
Server log (`warn` w logu Kibany).

Sekcja 5 `setup-analytics.sh`: indeks, connectory po stałym ID, reguły po **nazwie** (`_find`, dokładne
dopasowanie → `PUT`, brak → `POST`). Plik jest źródłem prawdy: ręczne zmiany w Kibanie zostaną nadpisane.

```
[analytics] [5] Indeks alerts-analytics już istnieje
[analytics] [5] Connector analytics-alerts-index: aktualizuję
[analytics] [5] Connector analytics-server-log: aktualizuję
[analytics] [5] Reguła „Analityka: skok zero results”: aktualizuję (b3a8a7bc-…)
[analytics] [5] Reguła „Analityka: spadek CTR”: aktualizuję (b01d63d4-…)
[analytics] [5] OK — reguły: http://localhost:5601/app/management/insightsAndAlerting/triggersActions/rules
```

### Weryfikacja: wstrzyknięcie, cisza, jeden alert na epizod

```bash
docker compose exec catalog-app php artisan analytics:simulate --live --inject=zero-results --sessions-per-day=60
```

`--live` publikuje paczkę sesji w ostatnich 15 minutach (~115 wyszukań, więc minima reguł są spełnione).

1. **Zero results** (17:40:57 UTC): dokumenty w `alerts-analytics` po ~25 s, zero results 83/116 = 0,716 vs baza
   0,052. Trzy sekundy później odpaliła też reguła CTR (0,33 vs 1,02). To **korelacja, nie błąd**: wyszukania bez
   wyników nie mają kliknięć, więc skok zero results zbija CTR.
2. **Spadek CTR** (`--inject=ctr-drop`, 17:43:24): alert CTR po ~60 s (113 wyszukań, CTR 0,248 vs 1,016). Reguła zero
   results milczała.
3. **Normalny ruch:** 3 paczki bez wstrzyknięcia przez ~9 min, żaden nowy alert.
4. **Trwała anomalia:** 5 paczek z `--inject=zero-results` co ~57 s (za każdym razem inne liczby). Przybył **jeden
   dokument na regułę** w pierwszej minucie, potem nic przez ~6 min trwania anomalii. Alert ES|QL bez `groupBy` ma
   stałe ID niezależnie od wartości wiersza, a `onActionGroupChange` nie powtarza. Po recovery i ponownym skoku
   powstanie nowy dokument.

```bash
curl -s -u elastic:… -H 'Content-Type: application/json' 'http://localhost:19200/_query?format=txt' \
  -d '{"query":"FROM alerts-analytics | KEEP @timestamp, rule.name, recent_searches, recent_ratio, baseline_ratio | SORT @timestamp"}'
```

```
       @timestamp       |         rule.name          |recent_searches|   recent_ratio   |  baseline_ratio
------------------------+----------------------------+---------------+------------------+-------------------
2026-11-20T17:41:19.097Z|Analityka: skok zero results|116            |0.7155172413793104|0.05161969976297077
2026-11-20T17:41:22.121Z|Analityka: spadek CTR       |116            |null              |null
2026-11-20T17:44:22.166Z|Analityka: spadek CTR       |113            |null              |null
2026-11-20T17:59:20.376Z|Analityka: skok zero results|79             |0.7974683544303798|0.05136351601800371
2026-11-20T17:59:23.400Z|Analityka: spadek CTR       |79             |null              |null
```

(Dokumenty reguły CTR mają własne pola `recent_ctr` / `baseline_ctr`, stąd `null` w kolumnach zero results.)

Po każdej fazie zdarzenia testowe usunąłem (`_delete_by_query` z `@timestamp >= 2026-11-20`), a z
`search-stats-daily` kubełki dnia 2026-11-20 osobno, bo Transform nie cofa usunięć ([RUNBOOK #130](../RUNBOOK.md#130)).

Ograniczenia reguł: baza zawiera starsze fragmenty tej samej anomalii (przy 7 dniach pomijalne); baza nie ma
minimalnego wolumenu, więc przy prawie pustej historii jest niewiarygodna; przy braku ruchu reguły milczą
(`recent_searches = 0` odpada na minimum).

## Całość od zera

Zakładam stos z poprzednich etapów (`make up-apps`, klaster 3 węzłów, produkty w indeksie z aliasami
`products-search`/`products-write` z ETAPU 10) i dane 12a albo pusty tor zdarzeń. Kolejność ma znaczenie:

```bash
# 1. tor analityki: sekcje 1–5 (pipeline enrich w sekcji 3, przed Kibaną w sekcji 4)
make analytics-setup
docker compose up -d analytics-consumer

# 2. migracja na products-v6 (dopiero gdy pipeline products-enrich-popularity istnieje!)
make reindex          # vN → vN+1; powtarzać, aż aliasy wskażą products-v6 (lokalnie: v4 → v5, v5 → v6)
make eval             # 1.000

# 3. dane: 30 dni przy B = 0 (na pustej popularności B nie ma znaczenia, ale ustaw jawnie)
docker compose exec -e SEARCH_POPULARITY_BOOST=0 catalog-app php artisan analytics:simulate --days=30 --as-of=2026-11-20
docker compose exec rabbitmq rabbitmqctl list_queues -q name messages_ready messages_unacknowledged  # czekać na 0 0

# 4. popularność i pomiar
make popularity-refresh variant=ips as_of=2026-11-20
make popularity-judgments                       # tylko gdy zmienił się katalog; plik jest w repo
docker compose exec -T -e SEARCH_POPULARITY_BOOST=0 catalog-app php artisan search:eval \
  --queries=/tests/relevance/popularity-queries.yaml --tie-shuffles=20
docker compose exec -T catalog-app php artisan search:eval \
  --queries=/tests/relevance/popularity-queries.yaml --tie-shuffles=20      # B = 2 z compose
make eval                                       # strażnik: 1.000

# 5. (opcjonalnie, KASUJE streamy) pętla zamknięta i kontrola
make closed-loop variant=ips days=30 boost=2
make closed-loop variant=ips days=30 boost=2 flat=1

# 6. anomalie
docker compose exec catalog-app php artisan analytics:simulate --live --inject=zero-results --sessions-per-day=60
# po ~1 min: FROM alerts-analytics | SORT @timestamp DESC | LIMIT 5
```

Na zupełnie świeżym klastrze `search:index:create` zakłada `products-v2` (`CURRENT_VERSION = 2`), więc do v6 dochodzi
się czterema `make reindex`. Sekcja 3 (popularność) musi przejść przed ostatnim z nich — inaczej `prepare` odmówi.

## Ograniczenia

- **Świeży klaster i k3s/VPS: kolejność bootstrapu (poprawione w końcowej fali ETAPU 12).** Indeks z
  `default_pipeline` wskazującym nieistniejący pipeline odrzuca każdy zapis (400), a ES tego przy tworzeniu nie
  sprawdza. Teraz sprawdza kod: `IndexMigration::prepare` i `search:index:create` czytają `index.default_pipeline`
  z pliku mapowania (zagnieżdżone i płaskie), pytają `GET _ingest/pipeline/<id>` i przy 404 odmawiają z podpowiedzią
  `make analytics-setup` (create — jeszcze przed `--force`). Sekcja popularności skryptu stoi teraz przed Kibaną, więc
  niedziałająca Kibana nie blokuje pipeline'u ([RUNBOOK #135](../RUNBOOK.md#135)).
- **Refresh popularności na indeksie bez `popularity`** (np. alias zapisu jeszcze na v5) odmawia `PopularityRefused`
  przed transformem, enrich i UBQ — sprawdza, że cel zapisu ma `popularity` typu `rank_feature`.
- **k3s zostaje przy B = 0** i nie ma tam `products-v6`, `analytics-setup` ani refreshu popularności. Skrypt setupu
  działa tylko na Compose. Przygotowane w końcowej fali: rola `search_service` w `deploy/k8s/es-roles.yml` ma już
  `manage_transform`, `manage_enrich` i `product-popularity*` (1:1 z `setup-security.sh`), a kolejka
  `search.analytics.ingest` (i jej `.dlq`) ma politykę RabbitMQ `analytics-ingest-cap` (100 000 wiadomości,
  `drop-head`) — w overlayu G5 nie ma analytics-consumera, a catalog dalej publikuje `user.*`
  ([RUNBOOK #139](../RUNBOOK.md#139)).
- **Przerwany refresh zostawia częściowy `product-popularity`.** Na żywo nic się nie dzieje (enrich czyta starą
  kopię), dopóki ktoś nie wykona polityki od nowa. `make analytics-setup` robi `_execute` zawsze, więc uruchomiony
  między padniętym a powtórzonym refreshem opublikuje częściowy indeks. Bezpiecznie: najpierw powtórzyć refresh.
- **Zmiana polityki enrich** wymaga ręcznej procedury (nagłówek `setup-analytics.sh`); w tym czasie zapisy produktów
  idą bez popularności.
- **`rank_feature` dokładany także przy sortowaniu po cenie/dacie** i w histogramie cen, gdzie wynik nie ma znaczenia
  (niegroźne, mały koszt).
- **Prawda syntetyczna, jeden przebieg pętli zamkniętej na wariant, szum ±0,03/±0,05** (krok 7).
- **`p(k)` z danych obserwacyjnych** jest dobre, gdy ranking przy zbieraniu jest niezależny od atrakcyjności (pętla
  otwarta, remisy). W pętli zamkniętej się psuje (hipoteza z kroku 7).
- **Reguły anomalii:** bazy uproszczone (zbiorczy udział zamiast mediany, cały okres zamiast tej samej godziny), jeden
  alert na regułę (bez grup per fraza), skok zero results wyzwala też regułę CTR.
- **`make closed-loop` kasuje streamy analityczne** w całości (także dane demo 12a).
- **Błąd z 12a poprawiony w końcowej fali:** `CartController::recordCheckedOut` jest w całości w `try/catch` +
  `report()`, więc błąd analityki po złożeniu zamówień nie daje 500. Kliknięcia z UI liczą się tylko z kontekstem
  wyszukania zawierającym produkt (pozycja z kontekstu), więc sfałszowane `?sid=&pos=` nie podbije IPS (przewodnik
  12a, ograniczenia). `analytics:simulate` na produkcji wymaga `--force`.

## Co wybuchło po drodze

| RUNBOOK | Objaw |
|---|---|
| [#133](../RUNBOOK.md#133) | `random_score` z wagą 1e-6 nie tasuje remisów: `_score` to float32 |
| [#134](../RUNBOOK.md#134) | Ten sam `_rank_eval` daje inne top 10 przy kolejnym wywołaniu: remisy rozstrzyga kopia shardu |
| [#135](../RUNBOOK.md#135) | Enrich i `default_pipeline`: 500 przy tworzeniu pipeline'u, 409 przy usuwaniu polityki, 400 na każdym zapisie |
| [#136](../RUNBOOK.md#136) | `rank_feature` odrzuca 0, a `on_failure` pipeline'u tego nie łapie |
| [#137](../RUNBOOK.md#137) | `make reindex` nie skacze do v6: zawsze vN → vN+1 |
| [#138](../RUNBOOK.md#138) | `closed-loop.sh` wisi 24 min: zawieszony `rabbitmqctl` w kontenerze |

Do tego błąd projektowy znaleziony w recenzji, nie w działaniu: prior IPS w skali surowej (krok 3).

## Zadania domowe

1. **Determinizm symulatora.** Dodaj do `search()` drugi klucz sortowania (np. `_seq_no`-hash jak w evalu albo
   `created_at`) i powtórz pętlę otwartą dwa razy z tym samym ziarnem. Czy liczby wyjdą identyczne co do cyfry?
   Ile kosztuje to na `/search`?
2. **Przedziały ufności dla IPS vs surowa.** Puść pętlę otwartą 5 razy z różnymi `--seed` i policz średnią i odchylenie
   różnicy ips − raw. Czy przewaga 0,008–0,014 przeżywa?
3. **`p(k)` z interwencji zamiast z obserwacji.** W symulatorze zamieniaj losowo miejscami pozycje k i k+1 w części
   sesji (RandPair) i licz `p(k+1)/p(k)` ze stosunku CTR tego samego produktu na obu pozycjach. Czy pętla zamknięta
   przestaje spadać po dniu 17?
4. **Mediana dzienna w regule zero results.** Zbuduj bazę z `search-stats-daily` (udział per dzień, potem mediana)
   i porównaj liczbę fałszywych alarmów przy `--live` bez wstrzyknięcia.
5. **Alert per fraza.** Dodaj `BY search.query` do reguły zero results. Co się dzieje z `onActionGroupChange`, gdy ID
   alertu zależy od grupy?
6. **Wdrożenie na k3s (na papierze).** Wypisz kolejność: rola `transform` węzłów (ECK ma domyślne role), sekcja 3 przed
   migracją, `SEARCH_POPULARITY_BOOST` na końcu listy env w `deploy/k8s` (overlay G5 łata po indeksie), CronJob
   refreshu.

## Ściąga

```bash
# popularność: przelicz i doklej (wariant ips|raw, okno do as_of)
make popularity-refresh variant=ips as_of=2026-11-20
docker compose exec search-consumer php bin/console search:popularity:refresh --reset     # wyzeruj

# eval na zestawie dowodowym przy zadanym B (20 tasowań remisów)
docker compose exec -T -e SEARCH_POPULARITY_BOOST=2 catalog-app php artisan search:eval \
  --queries=/tests/relevance/popularity-queries.yaml --tie-shuffles=20
make eval                                                     # strażnik, 13 zapytań

# popularność produktu i stan enrich
curl -s -u elastic:… 'http://localhost:19200/products-search/_doc/224507?_source_includes=name,popularity*'
curl -s -u elastic:… 'http://localhost:19200/product-popularity/_count'
curl -s -u elastic:… 'http://localhost:19200/_transform/product-popularity?filter_path=transforms.description'
curl -s -u elastic:… 'http://localhost:19200/_nodes/stats/ingest?filter_path=nodes.*.ingest.pipelines.products-enrich-popularity'

# symulacja bez popularności (pętla otwarta) i pętla zamknięta (KASUJE streamy)
docker compose exec -e SEARCH_POPULARITY_BOOST=0 catalog-app php artisan analytics:simulate --days=30 --as-of=2026-11-20
make closed-loop variant=ips days=30 boost=2 [flat=1] [yes=1]

# nowy zestaw ocen (po przebudowie katalogu)
make popularity-judgments

# anomalie: wstrzyknij, potem zobacz alerty
docker compose exec catalog-app php artisan analytics:simulate --live --inject=ctr-drop --sessions-per-day=60
curl -s -u elastic:… -H 'Content-Type: application/json' 'http://localhost:19200/_query?format=txt' \
  -d '{"query":"FROM alerts-analytics | SORT @timestamp DESC | LIMIT 5"}'
open "http://localhost:${KIBANA_PORT:-5601}/app/management/insightsAndAlerting/triggersActions/rules"

# setup (idempotentny; sekcja 3 = enrich, sekcja 4 = Kibana, sekcja 5 = reguły)
make analytics-setup
```

`elastic:…` = hasło z `ELASTIC_PASSWORD` w `.env`. `KIBANA_PORT` też jest w `.env` (domyślnie 5601).
