# ETAP 10 — Operacje na indeksach: projekt

Data: 2026-11-19 · Moduł 11 z `docs/03-SCIEZKA-NAUKI.md` · etap 10 z `docs/06-PLAN-WDROZENIA.md`

## 1. Po co

Nauka: zmieniać schemat indeksu i chronić dane **bez przerwy w działaniu wyszukiwarki**.
Projekt od dnia 1 czyta i pisze przez alias (D-11) i ma wersjonowanie zewnętrzne
(`projected_at_us`) — ten etap pokazuje, że te decyzje faktycznie pozwalają podmienić
indeks pod żywym ruchem, i dokłada to, czego brakuje: drugi alias, bramkę jakości,
przećwiczony restore.

Etap jest skończony dopiero z commitami **i** przewodnikiem w `docs/blog/`.

## 2. Cele, nie-cele, anty-cele

**Cele**
1. `make reindex` — reindeks `products-v1` → `products-v2` ze zmianą typu pola, pod
   ciągłym strumieniem zdarzeń, z zerowym downtime. Dwie drogi wypełnienia `v2`:
   `source=es` (domyślna, `_reindex`) i `source=pg` (odbudowa z catalogu).
2. Bramka jakości przed przełączeniem aliasu odczytu: liczba dokumentów i nDCG.
   Przełączenie na niedoindeksowany indeks musi zostać **zablokowane** (ćwiczenie 5).
3. `_update_by_query` na ~100 tys. dokumentów (zmiana nazwy sprzedawcy) z
   `requests_per_second`, obserwacją w Tasks API, anulowaniem w połowie i wznowieniem.
4. Snapshot → restore przećwiczony na repozytorium `fs`, potem na MinIO (S3);
   lokalna polityka SLM z retencją.
5. Pomiary w `docs/POMIARY.md`: czas obu dróg reindeksu, dodatkowe miejsce na dysku,
   wpływ `slices`/`requests_per_second`, czas restore.

**Nie-cele**
- 5 mln dokumentów — skala to etap 8. Tu **~300 tys. produktów**.
- Data streamy i ILM — zrobione w etapie 11 (logi); ćwiczenie 3 modułu 11 pomijamy.
- `_shrink`/`_split`/zmiana liczby shardów — etap 8.
- Reindeks zdalny, rolling upgrade, wdrożenie na k3s/VPS (brak serwera).

**Anty-cele** (porażka, nawet jeśli „działa”)
- Jakakolwiek chwila, w której `/search` zwraca pusty lub częściowy wynik.
- Utracony zapis ze strumienia albo **wskrzeszony** usunięty produkt po migracji.
- Zatrzymanie lub zmiana cudzych kontenerów (igrit-*, starter-*, blog_*), w tym
  cudzego MinIO.
- Sekret w repo (repo publiczne).

## 3. Ograniczenia

- Maszyna współdzielona: ES lokalnie z `ES_HEAP=512m ES_MEM_LIMIT=1536m`, porty
  przesunięte w `.env`, ES z hosta na `19200`.
- 3 nody ES lokalnie (`es01..03`), repo snapshotów `local-fs` (`/snapshots`) już
  rejestruje `infra/elasticsearch/setup-security.sh`.
- MinIO nie ma w naszym `compose.yaml` — trzeba go dodać (własny kontener, własny
  port, dane dostępowe tylko w `.env`, w `.env.example` zastępcze).
- Kontrakt projekcji: pola `_source` = kształt `GET /api/internal/products/{id}/projection`.
  `dynamic: strict` zostaje.

## 4. Podejścia (rozważone)

| | Jak `v2` dostaje dane | Za | Przeciw |
|---|---|---|---|
| **A** `_reindex` + alias zapisu | ES kopiuje `v1`→`v2` (`version_type: external`, `conflicts: proceed`); nowe zdarzenia od początku idą do `v2` | szybkie, uczy Tasks API, `slices`, throttlingu; jedna zmiana w kodzie | działa tylko, gdy nowe mapowanie da się wyliczyć z `_source` |
| **B** odbudowa z Postgresa | `search:reproject`-podobne przejście po ID z catalogu, zapis do `v2` | realizuje zasadę z `05-SPOJNOSC` §7 (ES odbudowalny z bazy); działa też dla pól spoza `_source` | wolniejsze (HTTP na produkt), mniej uczy ES |
| C podwójny zapis w konsumencie | konsument pisze do `v1` i `v2` | — | więcej kodu, nowe tryby awarii (zapis częściowo udany); A daje to samo prościej |

**Wybrane przez użytkownika: A jako domyślna ścieżka, B jako wariant `make reindex source=pg`;**
obie mierzone i porównane w POMIARACH. C odrzucone.

## 5. Projekt

### 5.1 Dwa aliasy

- `products-search` — **odczyt**. Catalog (`ProductSearchService`, `IndexedProductReader`,
  `SearchEvalCommand`) bez zmian.
- `products-write` — **zapis**, z `is_write_index: true`. `ElasticsearchIndexer` w
  konsumencie domyślnie pisze i usuwa przez `products-write`.
- `search:index:create` tworzy oba aliasy na świeżym klastrze; na istniejącym `v1`
  dopina `products-write` (idempotentnie). Wymaga to krótkiej kolejności wdrożenia:
  najpierw alias, potem konsument z nową nazwą (opisane w przewodniku).
- `search:reproject` bierze ID z `products-search`, a pisze przez `products-write`
  (rozdzielone opcje źródła ID i celu zapisu; dziś obie to jedno `--alias`).

### 5.2 Mapowanie v2

`infra/elasticsearch/mappings/products-v2.json` = `v1` z jedną zmianą typu:
**`rating_avg`: `half_float` → `scaled_float` (`scaling_factor: 100`)**.
Powód merytoryczny: `half_float` ma 11 bitów mantysy — w przedziale [4, 8) krok to
1/256, więc 4.7 zapisuje się jako ≈4.699, co psuje porównania przy progu oceny
i remisy w sortowaniu. Zmiany typu istniejącego pola nie da się zrobić
`PUT _mapping` — wymaga reindeksu, o to chodzi.

**Pierwszy krok implementacji to sprawdzenie, że różnica jest widoczna** (np. zapytanie
`range rating_avg gte 4.7` albo sort daje inne wyniki na `v1` i `v2` dla tych samych
danych). Jeśli nie da się jej pokazać, wykonawca wybiera inną zmianę typu, której nie
da się zrobić w miejscu i która nie zmienia zapytań catalogu, i zapisuje powód w specu.

`search:index:create` przyjmuje `--version=N` (plik `products-vN.json`); domyślnie
bieżąca wersja zapisana w jednym miejscu.

### 5.3 `make reindex` — przebieg (droga A)

Orkiestracja: `tools/es/reindex.sh` (styl `tools/k3d/*`, fail-closed), kroki jako
komendy Symfony w `apps/search` (to search-service posiada indeks) plus `search:eval`
z catalogu dla bramki.

1. **Preflight:** alias odczytu i zapisu wskazują ten sam indeks `vN`; `vN+1` nie
   istnieje (albo `--resume`); zdrowie klastra zielone/żółte; dość miejsca.
2. **Utwórz `vN+1`** z `number_of_replicas: 0`, `refresh_interval: -1` oraz
   `index.gc_deletes` dłuższym niż przewidywana migracja (patrz 5.4).
3. **Przepnij `products-write`** atomowo na `vN+1`. Od tej chwili strumień pisze tylko
   do nowego indeksu; odczyt dalej z `vN` — nadal pełny.
4. **`_reindex vN → vN+1`** jako zadanie (`wait_for_completion=false`), `dest.version_type:
   external`, `conflicts: proceed`, `slices` i `requests_per_second` z parametrów
   (`make reindex slices=auto rps=-1`). Skrypt śledzi zadanie przez Tasks API, pokazuje
   postęp, obsługuje anulowanie (Ctrl-C → `_tasks/{id}/_cancel`, indeks zostaje, można
   wznowić od kroku 4 — ponowny `_reindex` jest idempotentny dzięki wersjom).
5. **Przywróć** repliki i `refresh_interval`, `_refresh`, poczekaj na zielony.
6. **Bramka** (5.5). Odmowa = koniec z kodem ≠ 0, alias odczytu nietknięty.
7. **Przepnij `products-search`** atomowo na `vN+1`.
8. Przywróć domyślne `gc_deletes`. **`vN` zostaje** — usuwa go dopiero
   `make reindex-cleanup` (droga powrotu = jedno przepięcie aliasu odczytu, opisane w RUNBOOK).

Odczyt przez cały czas trafia w kompletny indeks: `vN` do kroku 7, potem zweryfikowany
`vN+1`. Między krokami 3 a 7 odczyt w `vN` nie widzi świeżych zmian ze strumienia —
to świadome opóźnienie świeżości (rzędu czasu reindeksu), nie utrata; zmierzyć i opisać.

### 5.4 Usunięcia w trakcie migracji (wskrzeszanie)

Pułapka: produkt usunięty po starcie `_reindex` dostaje usunięcie w `vN+1`, a potem
`_reindex` kopiuje jego starą wersję z `vN` → produkt wraca. Wersjonowanie zewnętrzne
chroni przed tym tylko dopóki ES pamięta tombstone usunięcia, czyli
`index.gc_deletes` (domyślnie 60 s).

Projekt: `gc_deletes` na `vN+1` podniesione na czas migracji (krok 2) i przywrócone
w kroku 8. **Wymagany test** (Symfony, na prawdziwym ES), który odtwarza wyścig:
dokument w `vN`, alias zapisu na `vN+1`, wersjonowane usunięcie przez `products-write`
(także gdy w `vN+1` jeszcze go nie ma), potem `_reindex` — dokument **nie** może się
pojawić w `vN+1`. Jeśli ES nie zostawia tombstone dla usunięcia nieistniejącego
dokumentu, wykonawca dokłada drugą ochronę (np. konsument w trakcie migracji usuwa
też z indeksu odczytu albo krok porządkowy po reindeksie) i opisuje to w RUNBOOK.

Bramka dodatkowo sprawdza zbieżność z catalogiem (5.5), więc ewentualne zombie
wyjdą przed przełączeniem.

### 5.5 Bramka jakości (`search:index:gate`)

Przełączenie odczytu tylko gdy **wszystkie**:
1. `vN+1` zielony, po `_refresh`.
2. Liczba dokumentów `vN+1` zgodna z liczbą produktów `listed()` w catalogu
   (źródło prawdy), z tolerancją na zdarzenia w locie — tolerancja jako parametr,
   domyślnie mała i opisana; wymaga lekkiego endpointu/komendy z liczbą w catalogu.
3. nDCG@10 z `search:eval --index=products-vN+1` ≥ wynik dla `vN` − 0.01
   (`search:eval` dostaje opcję wskazania indeksu fizycznego; `resolveAliasTarget()`
   już istnieje — przy `--index` po prostu go pomija).

Ćwiczenie 5 („zepsuj i napraw”): `make reindex-break` przerywa `_reindex` w ~30 % i próbuje
przełączyć — bramka musi odmówić z czytelnym komunikatem, co się nie zgadza. Brak
flagi `--force` omijającej bramkę.

### 5.6 Droga B — `make reindex source=pg`

Kroki 1–3 i 5–8 jak w A. Krok 4: zamiast `_reindex` — przejście po **wszystkich ID
produktów z catalogu** (nie z ES — `vN+1` jest pusty) i zapis projekcji do `vN+1`
przez `ElasticsearchIndexer` z wersją `projected_at_us`. Catalog potrzebuje
stronicowanego źródła ID (endpoint `internal` albo równoważne) — wykonawca dobiera
najprostsze zgodne z istniejącymi trasami `internal`. 409 w trakcie = strumień
wyprzedził backfill, liczone i raportowane, nie błąd. Pomiar: czas B vs A na tych
samych 300 tys.

### 5.7 `_update_by_query` — zmiana nazwy sprzedawcy

Scenariusz: sprzedawca zmienia nazwę w catalogu. Projekcja czyta nazwę na żywo z bazy
(`ProductProjectionController`: `$offer->seller->name`), więc baza + `_update_by_query`
w ES dają spójny stan bez 100 tys. zdarzeń w outboxie.

- Komenda (catalog lub search — wykonawca wybiera; ES-owa część w search-service)
  zmienia nazwę w Postgresie i uruchamia `_update_by_query` na `products-write`
  z zapytaniem `nested` po `offers.seller_id` i skryptem Painless podmieniającym
  `offers[].seller`, `wait_for_completion=false`, `requests_per_second` z parametru,
  `conflicts: proceed`.
- Obserwacja w Tasks API (`make es-tasks` już jest), `_rethrottle` na żywo, anulowanie
  w połowie, wznowienie = ponowne uruchomienie (zapytanie dopasowuje tylko dokumenty
  ze starą nazwą, więc jest idempotentne).
- Seed musi zapewnić sprzedawcę z ~100 tys. dokumentów (opcja seeda albo osobny krok).
- Do opisania w przewodniku: `_update_by_query` podbija wersję **wewnętrznie** o 1,
  co przy wersjach zewnętrznych (`projected_at_us`, mikrosekundy) nie psuje kolejności —
  sprawdzić testem, że późniejsze zdarzenie strumienia nadal wygrywa.

### 5.8 Snapshoty, restore, SLM

- `make es-restore-drill repo=local-fs` — snapshot indeksu z aliasu odczytu, restore
  z `rename_pattern` do `products-restored-*`, porównanie liczby dokumentów i próbki
  `_source`, sprzątanie. Nie dotyka aliasów — bez downtime.
- `make es-restore-drill-destructive` — wariant z ćwiczenia 4: usunięcie prawdziwego
  indeksu i restore. Żeby nie złamać anty-celu, robi to **na indeksie, na który nie
  wskazuje alias odczytu** (np. stary `vN` po migracji albo kopia), i to jest jawnie
  opisane.
- MinIO: nowy serwis w `compose.yaml` (profil `snapshots` lub `tools`), bucket tworzony
  automatycznie, repo `minio-s3` (`type: s3`, `endpoint`, `path_style_access`), klucze
  w keystore ES (`s3.client.default.*`) + `_nodes/reload_secure_settings`. Ten sam drill.
- SLM lokalnie: polityka z harmonogramem i retencją, `_slm/policy/<id>/_execute` w drillu.

### 5.9 Seed 300 tys.

`make seed n=300000` przez prawdziwy pipeline outboxu. Pierwszy krok implementacji
mierzy przepustowość; jeśli seed trwałby wiele godzin, wykonawca może dodać szybszą
ścieżkę seeda, **o ile dane w ES nadal powstają z projekcji catalogu** (wersje
`projected_at_us` muszą być prawdziwe, inaczej test wyścigów jest fikcją).

## 6. Dokumentacja i dowody

- `docs/blog/etap-10-operacje-na-indeksach.md` — przewodnik krok po kroku (obowiązkowy).
- `docs/RUNBOOK.md` — nowe wpisy od pierwszego wolnego numeru (wszystko, co wybuchło;
  w tym droga powrotu po złym reindeksie i pułapka `gc_deletes`).
- `docs/POMIARY.md` — sekcja ETAP 10 z liczbami z §2 cel 5.
- README (wiersz etapu 10), `docs/06-PLAN-WDROZENIA.md` (status), `docs/07-WERSJE.md`
  (MinIO).
- Testy: Symfony (aliasy, wyścig usunięć, wersja po `_update_by_query`, bramka),
  catalog (`search:eval --index`, licznik `listed()`), skrypty z trybem bez klastra
  w CI tam, gdzie to tanie (składnia, `shellcheck` jeśli już używany).

## 7. Zostawione wykonawcy

- Podział na komendy Symfony vs bash i ich nazwy (poza `make reindex`, `source=`,
  `slices=`, `rps=`, `reindex-cleanup`, `reindex-break`, `es-restore-drill*`).
- Dokładna tolerancja liczby dokumentów w bramce i jej uzasadnienie.
- Wartość `gc_deletes` na czas migracji.
- Port MinIO (wolny, nie kolidujący z igrit) i nazwa profilu.
- Kształt endpointu ID/licznika w catalogu, zgodny z istniejącymi trasami `internal`.

## 8. Rozstrzygnięcia po recenzji wykonawcy (decyzje projektowe, do sprawdzenia przez użytkownika)

1. **Droga powrotu nie jest bezstratna z samego przepięcia.** Po kroku 3 `vN` zamarza.
   Powrót = przepięcie **obu** aliasów na `vN` + nadrobienie w `vN` zmian z okna z outboxu
   catalogu: `search:index:backfill --index=products-write --since=<switch_write_at − margines>`
   (domyślnie 300 s; produkty dotknięte od tej chwili, projekcje partiami, `_bulk` z wersjami
   zewnętrznymi, usunięte → wersjonowane `delete`). Bez podwójnego zapisu (C zostaje odrzucone).
   RUNBOOK opisuje to jako procedurę, nie jedną linię (#115). Ta sama procedura obsługuje
   przerwanie migracji między krokami 3 a 7 (`make reindex-abort`); po kroku 7 —
   ręczne przepięcie aliasów + `make reindex-abort from=products-vN+1`.

   > **Zmiana w trakcie realizacji (Task 12/12b).** Pierwotnie powrót = `search:reproject`
   > (pełna droga B) do `vN`, a w implementacji (ruling A) pełny backfill + reproject wszystkich
   > ID z porzuconego `vN+1`. Na żywo przy 300 tys. to O(N): ~37 dok./s, krok zginął po 40 min
   > (restart konsumenta), szacunek ~4,5 h, `mismatches=1643` na przywróconym indeksie. Zastąpione
   > nadrabianiem tylko okna (5,65 s na żywo). Założenia: margines dłuższy niż opóźnienie kolejki
   > w chwili `switch-write`; zapisy bez outboxu (`_update_by_query`) nie są nadrabiane, więc
   > `search:seller:rename` w trakcie migracji odmawia.
   >
   > Pozostałe odstępstwa od tego dokumentu:
   > - ES lokalnie z heap 1g / limit 2g zamiast 512m / 1536m (§3) — stabilnie przy 300 tys.;
   > - bramka porównuje z liczbą **wszystkich istniejących** produktów, nie `listed()` (§5.5);
   > - backfill (§5.6) pobiera projekcje partiami po 500 (`/api/internal/products/projections`)
   >   i pisze jednym `_bulk` na partię — wersja „projekcja po jednej” dawała 37 dok./s;
   > - długie kroki `reindex.sh` przez `docker compose run --rm --no-deps`, nie `exec`
   >   (konsument restartuje się co 3600 s);
   > - przegląd po reindeksie (pkt 3) niepotrzebny: ES zostawia tombstone, wystarcza `gc_deletes: 1h`;
   > - backfill do celu odczytu jest dozwolony (bez pieczęci), bo używa go nadrabianie;
   > - `search:eval`: remis z różnymi ocenami = kod 2 (`TIE_UNSTABLE`), strażnik czyta całą grupę
   >   remisu przez PIT; nDCG nasycone na 1,000 — słaby sygnał bramki (opisane w przewodniku);
   > - `make reindex` wznawia migrację przerwaną po `switch-write`; nDCG starego indeksu mierzone
   >   przed `switch-write`;
   > - mapowania `products-v3..v5` = v2 (przebudowy bez zmiany mapowania pod kolejne przebiegi);
   > - `marketplace:seed-reviews` dodany (seed nie tworzył opinii, `rating_avg` był pusty);
   > - preflight „dość miejsca” (§5.3) nie zrobiony;
   > - `search:index:create` ma `--index-version=N` (nie `--version`, zajęte przez Symfony).
2. **Bramka nDCG jest względna.** Porównuje `vN` i `vN+1` na tych samych danych, więc
   liczy się różnica, nie wartość bezwzględna. Przed bramką krok pomiarowy: dwa przebiegi
   `search:eval` na tym samym indeksie 300 tys. muszą dać identyczny wynik
   (deterministyczność z RUNBOOK #096). Jeśli nie dają albo trwają dłużej niż kilka minut —
   wykonawca naprawia sędziów/remisy, a nie luzuje progu; wynik zapisuje w POMIARACH.
3. **Wskrzeszanie: zostajemy w modelu dwóch aliasów.** Najpierw test (§5.4). Jeśli ES nie
   zostawia tombstone dla usunięcia nieistniejącego dokumentu, ochroną jest **przegląd po
   reindeksie**: ID w `vN+1`, których catalog nie zwraca jako `listed()`, są usuwane z
   wersją z projekcji — bez zmiany semantyki konsumenta. `deleteProduct` ma rozróżniać
   404 i 409 w liczniku (dziś oba to `Stale`), żeby było widać, co się działo. Test
   sprawdza też, że wersja usunięcia > `projected_at_us` ostatniej projekcji.
4. **Duży sprzedawca:** opcja seeda (np. `--big-seller=100000`), która daje jednemu
   sprzedawcy ofertę w 100 tys. produktów. Sędziowie nDCG opierają się na nazwach
   i kategoriach, nie na sprzedawcach, więc skos ich nie rusza.
5. **Cele niszczące nigdy nie dotykają indeksów pod aliasem odczytu.** `make reindex-break`
   buduje częściową kopię `products-break-*` (`_reindex` z `max_docs` ≈ 30 %), **bez
   ruszania aliasów**, uruchamia na niej bramkę (musi odmówić) i ją usuwa. Drill
   niszczący działa na kopii zrobionej przez sam cel. Wszystkie cele niszczące mają
   preflight, który odmawia, jeśli wskazany indeks jest celem `products-search`
   lub `products-write`.
6. **Testy na prawdziwym ES** — w `apps/search/tests/Integration`, tak jak istniejący
   `ElasticsearchIndexerVersioningTest`, uruchamiane lokalnie przez `make`; do CI tylko
   to, co już tam trafia tym samym mechanizmem.
7. **Zasoby:** strojenie `slices`/`rps` pod budżet 512m heap jest częścią pomiaru
   (circuit breaker czy 429 to wynik do RUNBOOK, nie porażka). Zbiór zmniejszamy
   (do 150 tys.) tylko, jeśli seed albo reindeks nie da się ukończyć — i zapisujemy to.
