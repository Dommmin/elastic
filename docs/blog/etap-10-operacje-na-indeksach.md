# Operacje na indeksach, krok po kroku: zmiana typu pola pod ruchem, wycofanie i restore

*ETAP 10 projektu Marketplace Search Platform. Od ETAPU 6 wyszukiwarka czyta przez alias, a każdy dokument ma
wersję zewnętrzną (`projected_at_us`, mikrosekundy z chwili odczytu projekcji z catalogu). Ten etap sprawdza,
czy te dwie decyzje naprawdę pozwalają podmienić indeks pod żywym ruchem: zmienia typ pola `rating_avg`
na 300 tys. produktów, w trakcie ciągłych zmian i usunięć, bez jednej pustej odpowiedzi z `/search`. Do tego
bramka, która nie przepuści niedoindeksowanego indeksu, wycofanie w połowie migracji, `_update_by_query`
na 98 tys. dokumentów z dławieniem i anulowaniem, snapshoty na dysk i na MinIO, odtworzenie po „skasowałem
indeks”.*

> **Stan:** gałąź `etap-10-indeksy` (niewypchnięta), wszystko na lokalnym stosie Docker (3 węzły ES 9.5.1).
> **Na żywo (2026-11-19/20):** droga A v1→v2 w 70 s, droga B v3→v4 w 151 s, przerwanie Ctrl-C i wznowienie,
> wycofanie w 5,65 s, bramka odmawia niepełnemu indeksowi; po każdym przebiegu `verify-convergence … mismatches=0`.
> Sonda `/search` co sekundę w oknach pomiarów Task 12 i Task 12c: 3904 + 922 żądania, **każde HTTP 200, żadne puste**.
> Projekt: [specyfikacja](../superpowers/specs/2026-11-19-etap-10-operacje-na-indeksach-design.md),
> plan: [plan implementacji](../superpowers/plans/2026-11-19-etap-10-operacje-na-indeksach.md).
> Pomiary: [`POMIARY.md` §15](../POMIARY.md). Błędy z drogi: RUNBOOK [#110–#126](../RUNBOOK.md#110).
> Nie wdrożone na k3s/VPS (brak serwera, patrz [otwarte sprawy](#otwarte-sprawy)).

---

## Spis treści

1. [Problem: indeks ma typy, których nie da się zmienić w miejscu](#1-problem)
2. [Mapa plików](#2-mapa-plików)
3. [Krok 1 — dane: 300 tys. produktów prawdziwym pipeline'em](#krok-1--dane-300-tys-produktów-prawdziwym-pipelineem)
4. [Krok 2 — po co zmieniać typ: `half_float` gubi 4,70](#krok-2--po-co-zmieniać-typ-half_float-gubi-470)
5. [Krok 3 — dwa aliasy i kolejność wdrożenia](#krok-3--dwa-aliasy-i-kolejność-wdrożenia)
6. [Krok 4 — migracja: osiem kroków i stan na klastrze](#krok-4--migracja-osiem-kroków-i-stan-na-klastrze)
7. [Krok 5 — usunięcia w trakcie: `gc_deletes` i tombstone](#krok-5--usunięcia-w-trakcie-gc_deletes-i-tombstone)
8. [Krok 6 — bramka i `make reindex-break` (ćwiczenie 5)](#krok-6--bramka-i-make-reindex-break-ćwiczenie-5)
9. [Krok 7 — droga A kontra droga B](#krok-7--droga-a-kontra-droga-b)
10. [Krok 8 — Ctrl-C i wznowienie](#krok-8--ctrl-c-i-wznowienie)
11. [Krok 9 — wycofanie: pierwszy projekt był zły](#krok-9--wycofanie-pierwszy-projekt-był-zły)
12. [Krok 10 — `_update_by_query`: Tasks API, dławienie, anulowanie, wersje](#krok-10--_update_by_query-tasks-api-dławienie-anulowanie-wersje)
13. [Krok 11 — snapshoty: `fs`, MinIO, SLM i drille restore](#krok-11--snapshoty-fs-minio-slm-i-drille-restore)
14. [Ukryte pułapki](#ukryte-pułapki)
15. [Odstępstwa od planu](#odstępstwa-od-planu)
16. [Co wybuchło po drodze](#co-wybuchło-po-drodze)
17. [Otwarte sprawy](#otwarte-sprawy)
18. [Ściąga](#ściąga)
19. [Co dalej / zadania domowe](#co-dalej--zadania-domowe)

---

## 1. Problem

W Elasticsearchu typ pola jest na zawsze. `PUT _mapping` doda nowe pole, ale nie zamieni `half_float` na
`scaled_float`: Lucene zapisało już wartości w starym formacie. Jedyna droga to nowy indeks z nowym
mapowaniem i przeniesienie danych. Pytanie brzmi: jak to zrobić, kiedy:

- wyszukiwarka cały czas czyta (żadnej chwili z pustym albo częściowym wynikiem),
- konsument cały czas pisze (zmiany cen, stanów, usunięcia produktów),
- usunięty produkt nie może wrócić (zombie), a żaden zapis ze strumienia nie może zginąć.

Projekt ma do tego dwie gotowe decyzje z wcześniejszych etapów: catalog czyta przez alias `products-search`
(D-11), a każdy zapis do ES idzie z `version_type: external` i wersją `projected_at_us`. Wersje zewnętrzne
oznaczają, że ES odrzuca (409) każdy zapis starszy niż to, co ma. To pozwala kopiować stare dane i przyjmować
nowe zdarzenia **jednocześnie**, bez koordynacji: kto ma nowszą wersję, ten wygrywa.

Ten etap dokłada brakujące elementy: drugi alias (do zapisu), bramkę jakości, wycofanie i przećwiczony restore.

## 2. Mapa plików

```
infra/elasticsearch/mappings/
  products-v2.json        v1 + rating_avg: scaled_float (scaling_factor 100)
  products-v3..v5.json    kopie v2 różniące się tylko _meta: przebudowy bez zmiany mapowania
apps/search/src/
  Service/IndexAliases.php        products-search (odczyt), products-write (zapis, is_write_index)
  Service/IndexMigration.php      kroki migracji, warunki wstępne, stan w _meta.migration indeksu
  Service/IndexGate.php           bramka: zielony po _refresh, liczba vs catalog (tolerancja 0.001)
  Service/ElasticsearchIndexer.php  bulkSync(): _bulk z wersjami zewnętrznymi (droga B, nadrabianie)
  Service/SellerRenamer.php, TaskWaiter.php   _update_by_query + Tasks API
  Command/IndexMigrateCommand.php    search:index:migrate prepare|switch-write|reindex|finalize|switch-read|abort|cleanup
  Command/IndexGateCommand.php       search:index:gate --index= [--tolerance=0.001]
  Command/IndexBackfillCommand.php   search:index:backfill --index= [--batch=500] [--since=ISO]
  Command/SellerRenameIndexCommand.php  search:seller:rename <id> <nazwa> [--rps] [--check-only]
apps/catalog/
  Http/Controllers/Internal/ProductIdsController.php   /api/internal/products/count|ids|changed
  Http/Controllers/Internal/ProductProjectionController.php  /projections?ids=… (do 500 naraz)
  Services/Search/TouchedProductIds.php    ID produktów dotkniętych od chwili T (z outboxu)
  Services/Search/TieGroupGuard.php        strażnik remisów search:eval (PIT + search_after)
  Console/Commands/  marketplace:churn, marketplace:seed-reviews, search:verify-convergence,
                     marketplace:seller-rename, search:eval --index=
tools/es/
  reindex.sh              run | abort | cleanup | break  (make reindex*)
  restore-drill.sh        normal | destructive | slm-run  (make es-restore-drill*)
  lib.sh, test.sh         wspólne funkcje i testy offline (bez klastra)
infra/elasticsearch/setup-snapshots.sh   keystore, repo minio-s3, SLM local-nightly
compose.yaml              profil snapshots: minio + minio-init (obrazy przypięte digestem)
```

---

## Krok 1 — dane: 300 tys. produktów prawdziwym pipeline'em

Test wyścigów ma sens tylko wtedy, gdy wersje w ES są prawdziwe, czyli dokumenty powstały z projekcji catalogu,
a nie z `_bulk` wygenerowanego skryptem. Dlatego seed idzie tą samą drogą co produkcja: wiersz w `products` +
zdarzenie w `outbox` → `outbox-publisher` → kolejka quorum → `search-consumer` → projekcja z catalogu po HTTP → ES.

```bash
docker compose exec catalog-app php artisan marketplace:seed --n=300000 --big-seller=100000 --fresh
make seed-reviews products=50000     # opinie, żeby rating_avg w ogóle miał wartości
make churn rate=20 duration=300      # ruch w tle podczas migracji: zmiany ofert + 5 % usunięć
```

`--big-seller=100000` daje sprzedawcy „Megasklep” ofertę w 100 tys. produktów, pod ćwiczenie z `_update_by_query`.
`make seed` nie przekazuje `--fresh`, stąd pełne polecenie.

Liczby ([POMIARY §15](../POMIARY.md)): generowanie w Postgresie ok. 21 min, ale jeden konsument robi 25–30 dok./s,
co dla 300 tys. dałoby 2,8–3,3 h. Cztery konsumenty (`--scale search-consumer=4`) dały 70–100 dok./s, czyli ok. 3×,
nie 4×: wąskim gardłem był catalog (projekcja po HTTP) i Postgres, nie konsumenty (hipoteza z `docker stats`).
Od startu seeda do pustej kolejki: ok. 73 min. Zrównoleglenie konsumentów jest bezpieczne właśnie dzięki
wersjom zewnętrznym: kolejność przetwarzania nie ma znaczenia.

**Pierwsza pułapka już tutaj.** Kolejka była pusta, a w ES było 299 999 dokumentów. Jedna wiadomość wylądowała
w transporcie `failed` (Doctrine) z `RejectRedeliveredMessageException`. Pusta kolejka nie oznacza kompletu:
zawsze porównuj liczbę dokumentów z `count(*)` w bazie i sprawdzaj `messenger:failed:show` ([#111](../RUNBOOK.md#111)).

Seed nie tworzył opinii, więc `rating_avg` (pole, którego typ ma się zmienić) był pusty we wszystkich
dokumentach. Stąd `marketplace:seed-reviews`: 50 tys. produktów dostało 364 351 opinii zwykłą ścieżką domenową
(`Review::createWithOutbox`, jedno zdarzenie na produkt), z celowo dużą liczbą średnich równych dokładnie
4,70 (10 004 produkty) i 3,30 (10 187).

## Krok 2 — po co zmieniać typ: `half_float` gubi 4,70

`half_float` ma 11 bitów mantysy. W przedziale [4, 8) krok to 1/256, więc 4,70 zapisuje się jako ≈ 4,6992.
Spec wymagał, żeby najpierw pokazać, że różnica jest widoczna na danych, a dopiero potem migrować. Wynik na
`products-v1` po `seed-reviews`, porównany z Postgresem (`round(avg(rating), 2)`):

| Zapytanie | ES (`half_float`) | Postgres |
|---|---|---|
| `rating_avg >= 4.7` | **1 742** | **11 746** |
| `4.7 <= rating_avg < 4.71` | 0 | 10 004 |
| `4.69 <= rating_avg < 4.7` | 10 004 | 0 |
| `3.3 <= rating_avg <= 3.3` | 0 | 10 187 |
| `term rating_avg: 4.7` | 10 004 | 10 004 |

Filtr „od 4,7 gwiazdki” gubi 85 % wyniku. `term 4.7` się zgadza, bo wartość zapytania też jest zaokrąglana do
`half_float`; błąd widać dopiero na granicach przedziałów. Po migracji na `products-v2`
(`scaled_float`, `scaling_factor: 100`) pod churnem: **v1 1 741, v2 11 721, baza 11 721** (baza przeliczona po
churnie, który usunął część produktów). Hipoteza ze szkicu pomiarów potwierdzona.

Rozmiar indeksu się nie zmienił w sposób dający się zmierzyć: w próbie na 300 tys. syntetycznych ocen oba
warianty miały po 179 MB primary; różnice między kolejnymi indeksami v1–v4 (182–200 MB primary) wynikają
z merge'y i usuniętych dokumentów, nie z typu pola ([POMIARY §15](../POMIARY.md)).

## Krok 3 — dwa aliasy i kolejność wdrożenia

Jeden alias nie wystarcza. Gdyby konsument pisał przez `products-search`, to w chwili przepięcia aliasu
na nowy indeks wszystkie zapisy z czasu kopiowania trafiłyby do starego. Dlatego:

- `products-search` — **odczyt**. Catalog nic nie wie o migracji.
- `products-write` — **zapis**, `is_write_index: true`. `ElasticsearchIndexer` domyślnie pisze i usuwa przez niego.

**Kolejność wdrożenia ma znaczenie.** Najpierw alias, potem kod:

1. `search:index:create` na istniejącym klastrze dopina `products-write` do bieżącego celu `products-search`
   (idempotentnie, nigdy nie przesuwa istniejących aliasów).
2. Dopiero potem restart konsumenta z nową nazwą aliasu.

Deploy robi krok 1 **przy każdym wdrożeniu** (`tools/vps/deploy.sh`, job `deploy/k8s/jobs/migrate.yaml`), przed
podmianą konsumenta. Wcześniej oba skrypty wołały `search:index:create` tylko wtedy, gdy nie było `products-search`,
czyli istniejący klaster nigdy nie dostałby `products-write` (przegląd końcowy, I2). Żeby bezwarunkowe wywołanie było
bezpieczne, komenda bez jawnego `--index-version` (i bez `--force`) przy istniejącym aliasie **nie tworzy indeksu**,
tylko dopina brakujący alias. Inaczej na klastrze z `products-v1` zostawiłaby osierocony `products-v2`, a następne
`prepare` (v1 → v2) odmówiłoby na „obcym” celu o tej nazwie.

Odwrotnie konsument przez chwilę pisałby do aliasu, którego nie ma. Hipoteza (nie sprawdzałem tego na żywo):
ES przy `index` na nieistniejącą nazwę utworzyłby **indeks** `products-write` z dynamicznym mapowaniem, a nie alias
(klaster nie ma `action.auto_create_index: false`, a rola `search_service` ma `all` na `products-*`), i kolejne
`search:index:create` miałoby kolizję nazw. Na żywym stosie: `search:index:create` → „Dopięto products-write -> products-v1”,
restart konsumenta, `make smoke` 25/25, `make churn rate=5 duration=5` → 23 zapisy wylądowały w `products-v1`
przez nowy alias.

`search:reproject` dostał rozdzielone opcje: `--ids-from=products-search --write-to=products-write`.

## Krok 4 — migracja: osiem kroków i stan na klastrze

```bash
make reindex                  # droga A: _reindex (domyślnie source=es slices=auto rps=-1)
make reindex source=pg        # droga B: odbudowa z catalogu
```

`tools/es/reindex.sh` jest cienki: każdy krok to komenda Symfony `search:index:migrate <krok>`, a logika siedzi
w `IndexMigration`. Kroki (vN = obecny indeks, vN+1 = nowy):

| # | Krok | Co robi |
|---|---|---|
| 0 | `ndcg vN` | `search:eval --index=vN` — punkt odniesienia dla bramki, **przed** zamrożeniem vN ([#113](../RUNBOOK.md#113)) |
| 1 | `prepare` | oba aliasy na tym samym indeksie, klaster nie czerwony, plik `products-vN+1.json` istnieje, na vN nie trwa `_update_by_query`; tworzy vN+1 z `number_of_replicas: 0`, `refresh_interval: -1`, `gc_deletes: 1h` |
| 2 | `switch-write` | zapisuje `switch_write_at` w `_meta`, potem atomowo przepina `products-write` na vN+1. Od teraz strumień pisze tylko do nowego indeksu |
| 3 | `reindex --wait` / `backfill` | droga A: `_reindex` z `dest.version_type: external`, `conflicts: proceed`, jako zadanie śledzone w Tasks API. Droga B: `search:index:backfill` |
| 4 | `finalize` | sprawdza, że wypełnienie się udało (zadanie `completed`, bez `failures`, nie anulowane; albo backfill z 0 błędów), przywraca repliki i `refresh_interval`, czeka na zielony |
| 5 | `gate` | bramka (krok 6 niżej) |
| 6 | `ndcg vN+1` | nDCG nowego nie może spaść o więcej niż 0,01 względem vN |
| 7 | `switch-read` | jeszcze raz bramka **w procesie**, potem atomowo `products-search` → vN+1, `gc_deletes` z powrotem domyślne |

vN **zostaje** do `make reindex-cleanup`, który pokazuje listę indeksów bez aliasu i pyta o „tak”.

**Stan jest na klastrze, nie w procesie skryptu.** Każdy krok zapisuje się w `mappings._meta.migration` indeksu
vN+1: `source`, `prepared_at`, `switch_write_at`, `reindex_task`, `fill_method`, `switch_read_started_at`,
`switched_read_at`. Dzięki temu skrypt można zabić w dowolnej chwili, a kolejne wywołanie wie, gdzie jest
(wznowienie, krok 8), i każdy krok sam sprawdza warunki wstępne: `switch-read` bez `finalize` odmawia,
`finalize` po anulowanym `_reindex` odmawia, `abort` po rozpoczętym `switch-read` odmawia. Nie ma flagi
`--force` omijającej którykolwiek z tych warunków.

**Okno nieświeżego odczytu.** Od kroku 2 do kroku 7 odczyt idzie z vN, który nie dostaje już zapisów. To nie utrata
(zapisy są w vN+1), tylko opóźnienie świeżości: zmierzone ~62 s w drodze A i ~144 s w drodze B. Sonda `/search`
w tym czasie nie widziała żadnej luki, a trafienia zapytania kontrolnego zmieniały się wyłącznie przez prawdziwe
usunięcia z churnu (1203 → 1201).

Przebieg A v1→v2 pod churnem (`make churn rate=20 duration=300`):

| krok | s |
|---|---|
| ndcg products-v1 | 6 |
| prepare | 1 |
| switch-write | 1 |
| reindex (299 536 dok., `version_conflicts` 252) | 26 |
| finalize | 27 |
| gate (299 494 vs 299 492, PASS) | 1 |
| ndcg products-v2 | 8 |
| switch-read | 0 |
| **razem** | **70** |

`version_conflicts: 252` to dokumenty, które strumień zdążył zapisać do v2 nowszą wersją, zanim dotarła do nich
kopia. ES odrzucił starszą kopię i o to chodzi. Po churnie: kolejka pusta, `failed` bez nowych wpisów,
`search:verify-convergence --since=… → checked=5697 mismatches=0`.

`verify-convergence` to dowód niezależny od ES-owych liczników: dla każdego produktu dotkniętego w oknie (z outboxu)
buduje świeżą projekcję z bazy i porównuje z dokumentem w `products-search` (ceny, stan, zbiór ofert, a usunięty
w bazie i obecny w ES = zombie). Jest wolny (O(N) zapytań do bazy, ponad 2 min dla 50 tys. produktów), ale
odpowiada dokładnie na pytanie z anty-celów.

## Krok 5 — usunięcia w trakcie: `gc_deletes` i tombstone

To jest najciekawsza pułapka etapu. Scenariusz: produkt jest w v1, alias zapisu już na v2, `_reindex` jeszcze
do niego nie doszedł. Churn usuwa produkt. Konsument wysyła wersjonowane usunięcie przez `products-write` do v2,
gdzie dokumentu **jeszcze nie ma** (404). Chwilę później `_reindex` kopiuje starą wersję z v1. Czy produkt wraca?

Odpowiedź zależy od tego, czy ES pamięta usunięcie dokumentu, którego nie miał. Test na prawdziwym ES
(`apps/search/tests/Integration/DeleteDuringReindexTest.php`) pokazał:

- ES **zostawia tombstone** także dla wersjonowanego usunięcia nieistniejącego dokumentu: `deleteProduct`
  zwraca `NotFound` (404), ale wersja usunięcia jest zapamiętana.
- Z `gc_deletes=1h` na v2: `_reindex` (external, `conflicts=proceed`) dostaje `version_conflicts=1, created=0`.
  Produkt **nie wraca**.
- Z `gc_deletes=0s`, po 2 s i refreshu: `created=1`. Produkt **wraca** (zombie). To test dokumentacyjny:
  dowodzi, że ochroną jest właśnie `gc_deletes`, a nie coś innego.

Tombstone żyje tylko `index.gc_deletes` (domyślnie 60 s). Migracja dłuższa niż ta wartość otwiera okno wyścigu
z powrotem. Dlatego `prepare` ustawia `gc_deletes: 1h` (opcja `--gc-deletes`), a `switch-read` przywraca
domyślne. Jeśli przewidujesz migrację dłuższą niż godzina, podnieś tę wartość. Spec przewidywał drugą ochronę
(przegląd po reindeksie), jeśli tombstone by nie istniał; nie była potrzebna. Bramka (liczba vs catalog)
i `verify-convergence` i tak wyłapałyby zombie przed i po przełączeniu ([#110](../RUNBOOK.md#110)).

Do tego `deleteProduct` rozróżnia teraz 404 (`NotFound`) i 409 (`Stale`); wcześniej oba były `Stale`
i nie było widać, co się dzieje.

## Krok 6 — bramka i `make reindex-break` (ćwiczenie 5)

Bramka (`search:index:gate`, wołana też wewnątrz `switch-read`) przepuszcza tylko wtedy, gdy:

1. vN+1 jest zielony (po `_refresh`, bo indeks miał `refresh_interval: -1`),
2. `|liczba dokumentów − liczba produktów w catalogu| / liczba w catalogu ≤ 0,001`,
3. catalog odpowiedział (niedostępny catalog = FAIL, nie „pomijam”).

Liczba z catalogu to **wszystkie istniejące produkty** (`/api/internal/products/count`), bo projekcja istnieje dla
każdego produktu, a filtr `listed()` dotyczy ofert w jego wnętrzu. Tolerancja 0,001 (300 dok. przy 300 tys.)
pokrywa zdarzenia w locie: pod churnem liczba w ES bywała o 2 większa niż w catalogu (bramki: 299 494 vs 299 492, 299 939 vs 299 937;
usunięcia jeszcze w kolejce), po opróżnieniu kolejki równa.

Do tego krok nDCG w skrypcie: nowy indeks nie może dać nDCG@10 niższego o więcej niż 0,01 niż stary. Bramka jest
**względna**: liczy się różnica między vN a vN+1 na tych samych danych, nie wartość bezwzględna.

### Ćwiczenie 5: zepsuj i napraw

```bash
make reindex-break
```

Cel buduje kopię `products-break-<ts>` z `_reindex` i `max_docs` ≈ 30 %, **bez ruszania aliasów**, uruchamia na niej
bramkę i usuwa kopię (pułapka EXIT). Na żywo:

```
products-v3: 298682 dok.; kopiuję max_docs=89604 (~30 %) do products-break-1795135552
NIESPEŁNIONE: liczba dokumentów … 89604, catalog 298682 (różnica 209078 = 0.7000 > tolerancja 0.001)
GATE=FAIL
✓ bramka odmówiła
```

Całość 12 s. Cel kończy się sukcesem **tylko** wtedy, gdy bramka zwróci `GATE=FAIL`, kod ≠ 0 i linię z regułą
liczby dokumentów. Bramka, która nagle przepuści niepełny indeks, czerwieni ten cel. Cele niszczące mają też
preflight: odmawiają, jeśli wskazany indeks jest celem `products-search` lub `products-write`, i to fail-closed
(błąd ES przy sprawdzaniu aliasów = odmowa, nie „chyba nie”).

### nDCG jako sygnał bramki jest słaby i trzeba to powiedzieć wprost

Na 300 tys. produktów wszystkie 13 zapytań kontrolnych daje nDCG@10 = **1,000**, na każdym indeksie. Wskaźnik jest
nasycony: niedoindeksowany indeks z tymi samymi top-10 też dostanie 1,000. Prawdziwą robotę w bramce robi
porównanie liczby z catalogiem. nDCG wyłapie tylko zmianę mapowania, która psuje trafność (np. inny analizator),
a i to tylko wtedy, gdy zmieni top-10 któregoś z 13 zapytań.

Po drodze okazało się, że **strażnik remisów w `search:eval` był ślepy**. nDCG jest deterministyczne tylko wtedy,
gdy każda grupa remisu `_score` dotykająca top-10 ma jedną ocenę ([#096](../RUNBOOK.md#096)). Stary strażnik czytał
najwyżej 1000 trafień, a na 300 tys. grupy remisu k-tego wyniku mają do 11 583 dokumentów (10 z 13 zapytań ponad
1000, 5 ponad okno 10 000). Nowy `TieGroupGuard` czyta top-k normalnie, a całą grupę k-tego wyniku przez PIT +
`search_after` (strony po 5000, `min_score`), bez limitu. Na żywym indeksie nic nie znalazł (3 przebiegi
identyczne, 4,6–6,0 s), czyli nDCG = 1,000 jest teraz dowiedzione, nie tylko zaobserwowane ([#116](../RUNBOOK.md#116)).
Remis z różnymi ocenami daje teraz kod **2** (`TIE_UNSTABLE`), a błąd `_rank_eval` kod 1, żeby skrypt mógł je odróżnić.

## Krok 7 — droga A kontra droga B

| | Droga A (`source=es`) | Droga B (`source=pg`) |
|---|---|---|
| Skąd dane | `_reindex` vN → vN+1 w ES | wszystkie ID z catalogu → projekcje → `_bulk` do vN+1 |
| Wymaganie | nowe mapowanie da się wyliczyć z `_source` | żadne: działa też dla pól spoza `_source` |
| Zasada | szybka | ES odbudowalny z bazy (`05-SPOJNOSC` §7) |

Pomiar pod churnem (A: v1→v2, B: v3→v4; v3–v5 to przebudowy bez zmiany mapowania, bo plik
`products-vN+1.json` musi istnieć dla każdej wersji):

| krok | droga B | droga A |
|---|---|---|
| ndcg stary | 6 s | 6 s |
| prepare | 1 s | 1 s |
| switch-write | 0 s | 1 s |
| **wypełnienie** | **120 s** (298 609 indexed, 3 stale, 0 errors, 2 513 dok./s) | **26 s** |
| finalize | 15 s | 27 s |
| gate | 1 s | 1 s |
| ndcg nowy | 7 s | 8 s |
| switch-read | 1 s | 0 s |
| **razem** | **151 s** | **70 s** |

Droga B jest ~4,6× wolniejsza na samym wypełnieniu i ~2,2× w całości. Dwie minuty to jednak wynik **po
przeprojektowaniu**. Pierwsza wersja backfillu robiła dla każdego produktu osobne `GET …/{id}/projection` (29 ms
na żądanie) i osobny zapis do ES: **37 dok./s**, czyli ~2 h 15 min dla 300 tys. I nie dało się jej nawet
dokończyć, bo krok ginął po godzinie ([#112](../RUNBOOK.md#112)). Poprawka:

- catalog: `GET /api/internal/products/projections?ids=1,2,3` (do 500 ID, jedno zapytanie Eloquent na partię,
  jeden `projected_at_us` dla całej partii, ten sam builder co pojedyncza projekcja, więc kształt nie może się rozjechać),
- search: `ElasticsearchIndexer::bulkSync()` — jeden `_bulk` na partię, `index` dla znalezionych i `delete` dla
  brakujących, wszystko z `version_type: external`; 409 = stale, 404 przy delete = not_found.

Pełny backfill 298 682 produktów do jednorazowego indeksu: **112 s, 2 667 dok./s, ~72× szybciej**.

**Pieczęć backfillu** (`fill_method: backfill`, `backfill_completed_at`, liczniki w `_meta.migration`) dostaje tylko
cel migracji **w toku**: cel `products-write`, gdy `products-search` wskazuje jeszcze jego `_meta.migration.source`.
Pierwsza wersja pieczętowała każdy indeks z `_meta.migration`, a ten ma też żywy vN+1 po udanej migracji. Od drugiej
migracji pełny backfill do `products-write` w spoczynku nadpisywał więc pieczęć zakończonej migracji (przegląd
końcowy). Backfill do przygotowanego celu przed `switch-write` też nie pieczętuje: zapisy strumienia sprzed przepięcia
by go ominęły, a `finalize` i tak wymaga `switch-write`.

`slices` nie zmierzyłem sensownie: indeks ma jeden shard, a `slices=auto` dzieli według liczby shardów, więc daje
jeden wątek. To zostaje na ETAP 8 (więcej shardów).

**Dodatkowe miejsce na dysku:** w czasie kopiowania vN+1 nie ma repliki, więc dochodzi ok. jeden primary
(~190 MB). Po `finalize` repliki wracają i tuż po switch-read v2 miał 502,8 MB (przed merge), obok v1 418 MB.
Po scaleniu segmentów v2 spadł do 382 MB. Zaplanuj miejsce na **drugi pełny indeks z repliką, przed merge**.

## Krok 8 — Ctrl-C i wznowienie

Ctrl-C w kroku `reindex --wait` nie może zostawić osieroconego zadania w ES. Dwie warstwy:

- komenda PHP ma handler `SIGINT` (`pcntl_async_signals`), który woła `_tasks/<id>/_cancel`, czeka na zatrzymanie
  i kończy się kodem 130,
- `reindex.sh` ma pułapkę `INT`/`TERM`, która anuluje każdy trwający `_reindex` do celu (pas bezpieczeństwa,
  gdyby sygnał nie dotarł do kontenera).

Na żywo (v2→v3 pod churnem): SIGINT przy `created=21999` z 298 957 → „anuluję zadanie … / _reindex do products-v3
zatrzymany”, kod 130, w `_cat/tasks` pusto. Aliasy: odczyt v2, zapis v3. Po przeniesieniu długich kroków do
`docker compose run` ([#112](../RUNBOOK.md#112)) to samo jeszcze raz: zadanie anulowała już komenda PHP w kontenerze
`run` (`_tasks/<id>` → `canceled: "by user request"`, 32 994/298 396), żaden kontener `search-consumer-run-*` nie został.

**Wznowienie.** Ponowne `make reindex` początkowo **odmawiało** („aliasy muszą wskazywać ten sam indeks…”) i jako
jedyną drogę zostawiało wycofanie. Backend wznowienie wspierał (`switch-write` jest idempotentny, a powtórny `_reindex`
z wersjami zewnętrznymi niczego nie nadpisze starszą wersją), brakowało go w skrypcie ([#114](../RUNBOOK.md#114)). Teraz
`resume_decision` w `lib.sh` decyduje:

- oba aliasy na tym samym indeksie → `fresh` (zwykły przebieg),
- `_meta.migration.source` celu zapisu = indeks odczytu → `resume` (pomija prepare i switch-write),
- oba aliasy na vN+1, ale `switch_read_started_at` bez `switched_read_at` (proces padł po przepięciu odczytu,
  przed krokami po nim) → odmowa z podpowiedzią „uruchom `search:index:migrate switch-read`, żeby dokończyć”.
  Wcześniej skrypt brał to za spoczynek i startował vN+2 (przegląd końcowy, [#126](../RUNBOOK.md#126)),
- wszystko inne (obce źródło, brak `_meta`, błąd ES) → odmowa z powodem.

Wznowienie trwało 62 s: `reindex created=268209 version_conflicts=30748 total=298957`. Zmierzone są tylko sumy
(SIGINT przy `created=21999`, potem `version_conflicts=30748`). Interpretacja, nie rozbita pomiarem: 30 748 konfliktów to
prawdopodobnie ~22 tys. dokumentów skopiowanych przed przerwaniem plus zapisy strumienia, które wygrały z kopią. Nic nie zostało nadpisane
starszą wersją: `verify-convergence … checked=11215 mismatches=0`, bramka 298 682 = 298 682.

Przy wznowieniu stary indeks jest już zamrożony, więc jego nDCG ma fałszywe remisy ([#113](../RUNBOOK.md#113)).
Skrypt traktuje go orientacyjnie i toleruje wyłącznie kod 2 (remis); każdy inny kod błędu kończy przebieg.

## Krok 9 — wycofanie: pierwszy projekt był zły

`make reindex-abort` cofa migrację między `switch-write` a `switch-read`: oba aliasy z powrotem na vN. Problem: od
`switch-write` vN nie dostawał zapisów. Samo przepięcie aliasów przywraca indeks bez zmian z całego okna, czyli
nieaktualne ceny i, co gorsza, usunięte produkty, które wróciły (zombie). Wycofanie musi te zmiany nadrobić.

### Wersja 1: O(N) — i dlaczego padła na żywo

Plan (ruling A): po przepięciu aliasów pełny backfill wszystkich produktów z catalogu do `products-write` plus
`search:reproject` wszystkich ID z porzuconego vN+1 (żeby objąć usunięcia). Logicznie poprawne. Na żywo, pod
churnem, przy 37 dok./s:

- pełny backfill doszedł do 87 000 z ~299 600 i **zginął po 40 min 55 s z kodem 137** (restart konsumenta, [#112](../RUNBOOK.md#112)),
- `verify-convergence` na przywróconym v1: **`checked=9004 mismatches=1643`**, w tym zombie,
- szacunek całego wycofania: ~4,5 h.

To był błąd projektu, nie wykonania: koszt wycofania rósł z wielkością katalogu, a powinien z ruchem w oknie.
Naprawiłem stan ręcznie: ID produktów dotkniętych od początku okna z outboxu (9004), jednorazowy indeks
`products-catchup-<ts>` z samymi `_id`, `search:reproject --ids-from=products-catchup-<ts> --write-to=products-write`
(244 s: 8 561 zaindeksowanych, 274 × 404, 169 usuniętych), potem `mismatches=0`. Ta ręczna procedura stała się
projektem wersji 2.

### Wersja 2: tylko okno, z outboxu

```bash
make reindex-abort [margin=300] [from=products-vN+1]
```

1. `switch_write_at` z `_meta.migration` celu zapisu, odczytany **przed** czymkolwiek (bez niego odmowa, aliasy nietknięte),
2. anulowanie trwającego `_reindex`,
3. `search:index:migrate abort` → oba aliasy na vN,
4. `search:index:backfill --index=products-write --since=<switch_write_at − margin>`.

`--since` bierze ID z nowego endpointu `GET /api/internal/products/changed?since=…` (outbox: `product` →
`aggregate_id`, `offer`/`review` → `payload.product_id`, ta sama reguła co `verify-convergence`, wspólna klasa
`TouchedProductIds`). Usunięte produkty też są na liście, a projekcja ich nie znajdzie → wersjonowane `delete`.
Nadrabianie nigdy nie dotyka pieczęci w `_meta`.

Na żywo, pod churnem, po Ctrl-C z poprzedniego kroku: **5,65 s całość**, nadrabianie
`indexed 4887, stale(409) 3, not_found 222, deleted 26, errors 0` w 4 s. `verify-convergence … checked=7542 mismatches=0`,
bramka 298 003 = 298 003, `rating_avg >= 4.7` 11 671 = baza. Wariant z `_reindex` jeszcze w toku: 6,25 s, równoległe
`make reindex` skończyło się fail-closed i niczego nie przełączyło, `checked=3821 mismatches=0`.

**Założenia, które trzeba znać:**

- **Margines.** Zdarzenie sprzed `switch-write`, które czekało w kolejce, zapisało się już tylko do vN+1. Margines
  (domyślnie 300 s) musi być dłuższy niż opóźnienie kolejki w chwili `switch-write`. Abort wypisuje głębokość kolejki
  (na żywo 7 i 26 wiadomości). To założenie, nie sprawdzenie: przy wielominutowym zatorze podnieś `margin=`.
- **Zapisy bez outboxu.** Nadrabiane jest tylko to, co jest w outboxie. Ręczny `_update_by_query` albo ręczny reproject
  do `products-write` w oknie migracji nie wrócą. Dlatego `search:seller:rename` w trakcie migracji **odmawia**
  (krok 10), a `make seller-rename` sprawdza to (`--check-only`), zanim zmieni bazę. Działa to też w drugą stronę:
  `prepare`, `switch-write` i `reindex` odmawiają, gdy na vN (albo na którymś z aliasów) trwa `_update_by_query`.
  Inaczej zapisy zmiany nazwy po `switch-write` trafiałyby tylko do zamrożonego vN, a bramka i `verify-convergence`
  by tego nie zobaczyły ([#125](../RUNBOOK.md#125)). Między `--check-only` a zmianą w ES wciąż jest okno: jeśli migracja
  zacznie się akurat wtedy, ES odmówi, a baza zostanie już zmieniona. Naprawa: po `switch-read` powtórz
  `make seller-rename` z tą samą nazwą (zapytanie dopasowuje tylko dokumenty ze starą nazwą).

### Powrót po zakończonej migracji

Po `switch-read` `make reindex-abort` odmawia (żeby nie cofać dawnych, zakończonych migracji). Powrót na vN (dopóki
nie usunął go `reindex-cleanup`) to procedura z RUNBOOK [#115](../RUNBOOK.md#115): ręczne atomowe przepięcie obu aliasów
na vN, potem `make reindex-abort from=products-vN+1` (samo nadrabianie, okno z `_meta` porzuconego indeksu). Tej
procedury nie przećwiczyłem na żywo po `switch-read`; `--from` sprawdzałem tylko na stubach w `test.sh`.

## Krok 10 — `_update_by_query`: Tasks API, dławienie, anulowanie, wersje

Scenariusz: sprzedawca zmienia nazwę. Projekcja czyta nazwę sprzedawcy na żywo z bazy, więc zamiast 98 tys. zdarzeń
w outboxie wystarczy zmiana w Postgresie i `_update_by_query` w ES.

```bash
make seller-rename id=61 name="Megasklep Plus" rps=200
make es-tasks                                  # postęp w Tasks API
make es-rethrottle task=<node:id> rps=400      # zmiana tempa w locie
make es-cancel task=<node:id>                  # anulowanie
```

`search:seller:rename` uruchamia na `products-write` zapytanie `nested` z warunkiem „ta sama oferta: `seller_id` = 61
**i** nazwa ≠ nowa” oraz skrypt Painless podmieniający `offers[].seller`, z `conflicts: proceed`,
`wait_for_completion=false`, `requests_per_second`. Warunek „nazwa ≠ nowa” czyni wznowienie idempotentnym: ponowne
uruchomienie dotyka tylko dokumentów jeszcze ze starą nazwą.

Na żywo (Megasklep, 98 102 dokumenty):

| Faza | Wynik |
|---|---|
| start `rps=200` | ~350 dok./s w pierwszych 18 s (partie po 1000 i czekanie, czyli dławienie jest skokowe) |
| `_rethrottle` → 400 po ~18 s | status pokazał `requests_per_second: 400` |
| `es-cancel` po ~137 s | komenda kod 130, „anulowano: updated=48000”; 48 000 nowych / 50 102 starych |
| wznowienie `rps=1000` | `updated=50102` w ~61 s → 98 102 nowych, 0 starych |
| powrót bez dławienia `rps=-1` | 98 102 w 15 s (~6 500 dok./s) |

Liczby czytane tuż po końcu zadania potrafią się spóźnić o ~1 s (`refresh_interval`); czytaj drugi raz.

**Konflikty wersji.** Przy `conflicts: proceed` dokument zmieniony między odczytem scrolla a zapisem (np. przez
strumień) jest pomijany i zostaje ze starą nazwą. Komenda kończy się wtedy kodem 0 (nic nie jest zepsute), ale
wypisuje ostrzeżenie „… dokumentów pominiętych przez konflikt wersji — uruchom ponownie to samo polecenie”. Test:
dokument zmieniony w przerwie między paczkami daje `updated=1499 version_conflicts=1`, a powtórka `updated=1`.

**Wersje.** `_update_by_query` podbija wersję dokumentu **wewnętrznie** o 1. Przy wersjach zewnętrznych w mikrosekundach
to nie psuje kolejności: test (`SellerRenameIndexCommandTest`) pokazuje wersję V → V+1, potem zdarzenie strumienia
z wersją V jest odrzucane jako stale, a z wersją V+1000 wchodzi. Kolejne prawdziwe zdarzenie ma `projected_at_us`
większe o sekundy, nie o 1 µs. To jest jedyna ścieżka zapisu, która omija `projected_at_us`, i dlatego właśnie nie ma
jej w outboxie, co ma konsekwencje dla wycofania (krok 9).

## Krok 11 — snapshoty: `fs`, MinIO, SLM i drille restore

```bash
make es-snapshots-setup                         # MinIO + keystore + repo minio-s3 + SLM (raz, i po odtworzeniu węzłów ES)
make es-restore-drill repo=local-fs             # snapshot → restore pod inną nazwą → porównanie
make es-restore-drill repo=minio-s3
make es-restore-drill-destructive repo=local-fs # „skasowałem indeks” na własnej kopii
make es-slm-run                                 # polityka local-nightly teraz, z czekaniem na wynik
```

- **Repo `local-fs`** (`/snapshots`) było już od ETAPU 2.
- **MinIO** to nowy serwis w profilu `snapshots` (porty `127.0.0.1:19000/19001`, własny wolumen; nie używamy MinIO
  innych projektów z tej maszyny). `minio-init` tworzy bucket i osobnego użytkownika dla ES z polityką tylko na ten
  bucket. Węzły ES mają `s3.client.default.endpoint=http://minio:9000` i `path_style_access=true`; klucze idą do
  keystore każdego węzła (`--stdin`, nigdy w argumentach) i `_nodes/reload_secure_settings`. `protocol` celowo nie
  jest ustawione: w 9.5 jest przestarzałe, schemat niesie endpoint.
- **Drill nieniszczący** robi snapshot indeksu spod aliasu odczytu, restore z `rename_pattern` do `products-restored-*`,
  porównuje liczbę (liczoną przed snapshotem) i próbkę 100 dokumentów (`_source` + `_version`), sprząta. Aliasów nie dotyka.
- **Drill niszczący** robi kopię przez `_reindex` (wersje zewnętrzne zachowane), snapshot kopii, **usuwa kopię**,
  odtwarza i porównuje. Nigdy nie usuwa indeksu pod aliasem: nazwa musi pasować do `products-(drill|restored)-<cyfry>`,
  a preflight sprawdza aliasy fail-closed.
- **SLM `local-nightly`**: repo `local-fs`, `["products-*","-products-drill-*","-products-restored-*"]`, 01:00 UTC,
  retencja 7 dni / min. 1 / maks. 7.

Wyniki (`products-v1`, 299 999 produktów):

| Drill | repo | snapshot | restore | rozmiar |
|---|---|---|---|---|
| nieniszczący | local-fs | 6,0 s | 6,2 s | 214,8 MB |
| nieniszczący | minio-s3 | 5,9 s | 6,0 s | 214,8 MB |
| niszczący (kopia 22,3 s) | local-fs | 5,0 s | 5,2 s | 183,9 MB |
| niszczący | minio-s3 | 5,8 s | 5,0 s | 184,2 MB |

Wszystkie: liczba zgodna, próbka zgodna. `make es-slm-run`: SUCCESS w 6,8 s. Przy jednym shardzie i lokalnym dysku
MinIO nie jest wolniejszy od `fs`.

Dwie pułapki z tej części: **obrazy MinIO nie są już serwowane z tagami `RELEASE`**, więc pin to digest
([#118](../RUNBOOK.md#118)), a **keystore ES leży w kontenerze, nie na wolumenie**: po odtworzeniu węzłów repo `minio-s3`
przestaje działać, dopóki nie uruchomisz ponownie `make es-snapshots-setup` ([#119](../RUNBOOK.md#119)).

---

## Ukryte pułapki

Rzeczy, które wyglądały na zdrowe, a nie były:

1. **Pusta kolejka ≠ komplet.** Wiadomość w transporcie `failed` nie podnosi głębokości kolejki ani DLQ w RabbitMQ.
   Porównuj liczby, nie stan kolejki ([#111](../RUNBOOK.md#111)).
2. **`--time-limit` konsumenta zabija `docker compose exec`.** `search-consumer` kończy się co 3600 s (`--time-limit=3600`,
   `restart: unless-stopped`), a razem z nim każdy `exec` w tym kontenerze, z kodem 137. Wycofanie zginęło tak po
   40 min 55 s. Długie kroki idą teraz przez `docker compose run --rm --no-deps` ([#112](../RUNBOOK.md#112)).
3. **nDCG zamrożonego indeksu kłamie.** Po `switch-write` stary indeks nie dostaje usunięć, a oceny `search:eval` liczy
   z bazy. Usunięte produkty zostają w indeksie bez oceny i tworzą remisy z mieszanymi ocenami: eval starego
   indeksu padał po bramce, która przeszła. nDCG starego mierzy się **przed** `switch-write` ([#113](../RUNBOOK.md#113)).
4. **Obraz MinIO z tagiem działał tylko lokalnie.** Tag został nadany ręcznie `docker tag` na obrazie, który już był na
   maszynie; z rejestru się go nie pobierze. Pin digestem z `RepoDigests` ([#118](../RUNBOOK.md#118)).
5. **`echo tak | make reindex-cleanup` nic nie usuwał**, bo `docker compose exec -T` w podglądzie zjadał stdin
   z odpowiedzią ([#122](../RUNBOOK.md#122)).
6. **128 MB `memory_limit` przy 300 tys.**: `search:eval`, `pluck('id')` w `seed-reviews` i pierwsza wersja `/changed`
   (DISTINCT w SQL, filtr w PHP) padały na pamięci, bo przy 1500 produktach nikt tego nie widział ([#123](../RUNBOOK.md#123)).
7. **Zabicie `docker compose exec` (bez TTY) po stronie hosta nie zatrzymuje procesu w kontenerze.** Przerwany `make churn` dalej
   produkował zdarzenia do końca `--duration`; interaktywnego Ctrl-C z TTY nie sprawdzałem ([#124](../RUNBOOK.md#124)).

## Odstępstwa od planu

| Plan / spec | Co zrobiłem | Dlaczego |
|---|---|---|
| ES z `ES_HEAP=512m ES_MEM_LIMIT=1536m` | **heap 1g, limit 2g** (wartości z `.env`) | stabilnie przy 300 tys., bez OOM; odtwarzanie węzłów kosztuje czas i ryzyko; liczby są przy większym heapie |
| abort = pełny backfill + reproject (ruling A, spec §8.1) | **oba aliasy wstecz + nadrabianie z outboxu od `switch_write_at − margin`** | wersja O(N) padła na żywo po 40 min (~4,5 h szacunku); nowa trwa 5,65 s |
| backfill: projekcja po jednej | **projekcje partiami po 500 + `_bulk`** | 37 → ~2 667 dok./s; droga B w ogóle wykonalna |
| długie kroki przez `docker compose exec` | **`docker compose run --rm --no-deps`** | `exec` ginie przy restarcie konsumenta co godzinę |
| bramka: produkty `listed()` | **wszystkie istniejące produkty** | projekcja istnieje dla każdego produktu; `listed()` filtruje oferty |
| przegląd po reindeksie (druga ochrona usunięć) | **nie dodany** | test pokazał, że ES zostawia tombstone; wystarcza `gc_deletes: 1h` |
| backfill odmawia celu odczytu | **dozwolony, bez pieczęci** | potrzebny do nadrabiania po abort; wersje zewnętrzne czynią go bezpiecznym |
| `search:eval`: remis = kod 1 | **remis = kod 2 (`TIE_UNSTABLE`)** | wznowienie musi odróżnić remis na zamrożonym indeksie od awarii |
| jedno mapowanie v2 | **v3–v5 jako przebudowy bez zmiany mapowania** | `prepare` wymaga pliku dla każdej wersji, a przebiegi 2–5 potrzebowały kolejnych |
| preflight „dość miejsca” (spec §5.3) | **nie zrobiony** (tylko „klaster nie czerwony”) | poza zakresem planu; patrz zadania domowe |
| `seller-rename` zawsze | **odmawia w trakcie migracji** | zmiana bez outboxu zginęłaby przy abort |
| `seed-reviews` (nie było w planie) | **dodany** (Task 2b) | bez opinii zmiana typu `rating_avg` byłaby niewidoczna na danych |

Specyfikacja ma przy §8.1 notę „Zmiana w trakcie realizacji” z tą listą.

## Co wybuchło po drodze

| Błąd | Wpis |
|---|---|
| `gc_deletes`: usunięty produkt wraca po `_reindex` (test dowodowy) | [#110](../RUNBOOK.md#110) |
| pusta kolejka, a w ES brakuje produktu: wiadomość w `failed` | [#111](../RUNBOOK.md#111) |
| `Error 137` po ~40 min: `--time-limit` konsumenta zabija `exec` | [#112](../RUNBOOK.md#112) |
| `make reindex` pada na nDCG starego indeksu po bramce PASS | [#113](../RUNBOOK.md#113) |
| ponowne `make reindex` po Ctrl-C odmawia | [#114](../RUNBOOK.md#114) |
| **wycofanie O(N): 40 min, 1643 rozjazdy, zombie** | [#115](../RUNBOOK.md#115) |
| strażnik remisów ślepy przy 300 tys. | [#116](../RUNBOOK.md#116) |
| `half_float`: filtr „od 4,7” gubi 85 % wyniku | [#117](../RUNBOOK.md#117) |
| obraz MinIO z tagiem nie do pobrania | [#118](../RUNBOOK.md#118) |
| repo `minio-s3` przestaje działać po odtworzeniu węzłów ES | [#119](../RUNBOOK.md#119) |
| 403 na `_tasks/<id>/_cancel`: brak `cancel_task` w roli | [#120](../RUNBOOK.md#120) |
| `curl -X HEAD` wisi | [#121](../RUNBOOK.md#121) |
| `echo tak \| make reindex-cleanup` nie usuwa | [#122](../RUNBOOK.md#122) |
| `Allowed memory size of 134217728 bytes exhausted` przy 300 tys. | [#123](../RUNBOOK.md#123) |
| przerwany `make churn` dalej pisze | [#124](../RUNBOOK.md#124) |
| (przegląd końcowy) migracja startuje w trakcie `_update_by_query` zmiany nazwy | [#125](../RUNBOOK.md#125) |
| (przegląd końcowy) padnięty `switch-read`: `abort` odmawia, `run` brał to za spoczynek | [#126](../RUNBOOK.md#126) |

Wspólny wzór: prawie każdy z tych błędów był niewidoczny przy 1500 produktach i krótkich krokach. Ujawniła je dopiero
skala (300 tys.), czas (krok dłuższy niż godzina życia konsumenta) i ruch w tle (churn). Najdroższy był #115: projekt,
który na papierze był poprawny, ale miał zły rząd wielkości kosztu. Wniosek na przyszłość: przy każdej procedurze
awaryjnej policz, z czym rośnie jej koszt, zanim ją napiszesz.

## Otwarte sprawy

- ~~**`/api/internal/*` nie ma uwierzytelnienia.**~~ Zamknięte osobnym PR: wspólny sekret `X-Internal-Token` i 404
  w publicznym bloku Caddy, opis w [Uwierzytelnienie `/api/internal`](etap-10b-uwierzytelnienie-api-wewnetrznego.md).
- **k3s/VPS nie są zaktualizowane** (brak serwera). Przy następnym wdrożeniu: `cancel_task` w roli `search_service`
  (`deploy/k8s/es-roles.yml`, [#120](../RUNBOOK.md#120)) i MinIO/SLM według potrzeb produkcji. Dopięcie
  `products-write` przed nowym konsumentem (krok 3) deploy robi już sam (`search:index:create` przy każdym wdrożeniu),
  ale nie było to uruchomione na serwerze.
- **Margines abortu to założenie.** Skrypt pokazuje głębokość kolejki, ale nie mierzy opóźnienia najstarszej wiadomości.
- **Kursor PIT z `/api/search`** jest przypięty do fizycznego indeksu: otwarty przed `switch-read` działa dalej na
  starym, dopóki ten istnieje. Po `reindex-cleanup` stare kursory przestaną działać. Nie sprawdzone na żywo.
- **Pierwsze uruchomienie `shellcheck`** na nowych skryptach będzie w CI (lokalnie brak binarki).
- Lokalnie zostały dwa ręcznie nadane tagi obrazów MinIO (`quay.io/minio/minio:RELEASE.2025-09-07T16-13-09Z`,
  `quay.io/minio/mc:RELEASE.2025-08-13T08-35-41Z`); nic ich nie używa, można je usunąć `docker rmi` (usuwa tylko tag).

## Ściąga

```bash
make reindex [source=es|pg] [slices=auto] [rps=-1]   # migracja vN → vN+1, wznawia przerwaną po switch-write
make reindex-abort [margin=300] [from=products-vN+1] # wycofanie po switch-write + nadrabianie okna z outboxu
make reindex-cleanup                                 # usuń indeksy bez aliasu (podgląd + „tak”)
make reindex-break                                   # dowód bramki: 30 % MUSI nie przejść
make churn rate=20 duration=300                      # ruch w tle
docker compose exec catalog-app php artisan search:verify-convergence --since=<ISO>   # zbieżność ES z bazą
docker compose exec search-consumer php bin/console search:index:gate --index=products-v2
make seller-rename id=61 name="Nowa" rps=500         # baza + _update_by_query (odmawia w trakcie migracji)
make es-tasks | es-rethrottle task= rps= | es-cancel task=
make es-snapshots-setup                              # po każdym odtworzeniu węzłów ES
make es-restore-drill repo=local-fs|minio-s3
make es-restore-drill-destructive repo=local-fs|minio-s3
make es-slm-run
bash tools/es/test.sh                                # testy skryptów bez klastra
```

Gdy coś poszło nie tak: [RUNBOOK #110–#126](../RUNBOOK.md#110).

## Co dalej / zadania domowe

1. **Zepsuj `gc_deletes`.** Uruchom `search:index:migrate prepare --gc-deletes=0s`, przepnij zapis, usuń kilka produktów
   churnem, odczekaj minutę i zrób `_reindex`. Znajdź zombie `verify-convergence` i bramką. Która z nich zauważyła
   pierwsza i dlaczego bramka może ich nie zauważyć (podpowiedź: tolerancja 0,001)?
2. **Zmień margines na 0** (`make reindex-abort margin=0`) przy zatkanej kolejce (zatrzymaj konsumenta na minutę przed
   `switch-write`). Ile rozjazdów pokaże `verify-convergence` i skąd się wzięły?
3. **Zrób nDCG użytecznym sygnałem.** Dodaj zapytania kontrolne, na których obecny indeks nie ma 1,000 (np. literówki,
   frazy wielowyrazowe), a potem zrób mapowanie v6 ze zmienionym analizatorem i sprawdź, czy bramka nDCG je zatrzyma.
4. **Preflight miejsca na dysku** (spec §5.3, niezrobiony): `_cat/allocation` przed `prepare`, odmowa, gdy wolne miejsce
   jest mniejsze niż 2 × rozmiar vN z repliką (pamiętaj o rozmiarze przed merge).
5. **`slices` na kilku shardach.** Zrób mapowanie z 3 shardami primary i zmierz `slices=1` vs `slices=auto` oraz wpływ `rps`
   na sondę `/search` (`took_ms`). To pomiar, którego przy jednym shardzie nie dało się zrobić.
6. **Powrót po `switch-read`** ([#115](../RUNBOOK.md#115)): przećwicz ręczne przepięcie obu aliasów na vN i
   `make reindex-abort from=products-vN+1`, z `verify-convergence` na końcu. Zapisz wynik w POMIARACH.
7. ~~**Uwierzytelnij `/api/internal/*`**~~: zrobione, patrz
   [Uwierzytelnienie `/api/internal`](etap-10b-uwierzytelnienie-api-wewnetrznego.md). Zadanie domowe w zamian: rotacja
   tokenu bez przestoju (dwa ważne tokeny naraz w catalogu, potem przepięcie search, potem usunięcie starego).
8. **Restore do innego klastra**: zarejestruj to samo repo `minio-s3` jako `readonly` w osobnym, jednowęzłowym ES i odtwórz
   tam snapshot. Co się stanie, gdy dwa klastry piszą do jednego repo?
