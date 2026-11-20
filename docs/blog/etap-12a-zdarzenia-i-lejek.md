# Analityka operacyjna, krok po kroku: zdarzenia z sesji, data streamy, Transform i lejek w Kibanie

*ETAP 12a projektu Marketplace Search Platform. Wyszukiwarka działa, ale do tej pory nie było wiadomo, co klient
z nią robi: czego szuka, ile fraz kończy się pustą listą, w co klika, ile sesji z wyszukania dochodzi do koszyka
i zakupu. Ten etap dokłada pełny tor danych: catalog zapisuje zdarzenia z sesji, search-service wpisuje je do data
streamów, Transform liczy dzienne statystyki per fraza, a Kibana i ES|QL pokazują lejek, CTR i frazy bez wyników.
Część 12b ([`etap-12b-popularnosc-w-rankingu.md`](etap-12b-popularnosc-w-rankingu.md)) używa tych danych do
rankingu (popularność z korektą biasu pozycji).*

> **Stan:** gałąź `etap-12-analityka` (niewypchnięta), lokalny stos Docker (3 węzły ES 9.5.1, Kibana 9.5.1),
> sprawdzone 2026-11-20. **Na żywo:** symulator opublikował 30 dni historii (9000 sesji, 37 580 zdarzeń w 19,3 s),
> Transform policzył 4941 kubełków dzień × fraza w pierwszym checkpoincie (~1,3 s), spóźnione zdarzenie sprzed
> 3 dni trafiło do swojego dnia po 103 s, dashboard zaimportował się (9 obiektów) także do pustej przestrzeni Kibany.
> Projekt: [specyfikacja](../superpowers/specs/2026-11-20-etap-12-analityka-operacyjna-design.md).
> Pomiary: [`POMIARY.md` §16](../POMIARY.md). Błędy z drogi: RUNBOOK [#128–#132](../RUNBOOK.md#128).
> Nie wdrożone na k3s (brak serwera).

---

## Spis treści

1. [Problem](#1-problem)
2. [Decyzje](#2-decyzje)
3. [Mapa plików](#3-mapa-plików)
4. [Cały tor 12a od zera](#4-cały-tor-12a-od-zera)
5. [Krok 1 — sesja `mp_sid` i zdarzenia w catalogu](#krok-1--sesja-mp_sid-i-zdarzenia-w-catalogu)
6. [Krok 2 — data streamy, ILM i `event.ingested`](#krok-2--data-streamy-ilm-i-eventingested)
7. [Krok 3 — `analytics-consumer`: paczki i 409 jako deduplikacja](#krok-3--analytics-consumer-paczki-i-409-jako-deduplikacja)
8. [Krok 4 — symulator klientów](#krok-4--symulator-klientów)
9. [Krok 5 — Transform `search-stats-daily`](#krok-5--transform-search-stats-daily)
10. [Krok 6 — spóźnione zdarzenia: dlaczego sync po `event.ingested`](#krok-6--spóźnione-zdarzenia-dlaczego-sync-po-eventingested)
11. [Krok 7 — dashboard „Wyszukiwarka — analityka”](#krok-7--dashboard-wyszukiwarka--analityka)
12. [Krok 8 — ES|QL: lejek, zero results, CTR wg pozycji](#krok-8--esql-lejek-zero-results-ctr-wg-pozycji)
13. [Krok 9 — przejście przez UI z logowaniem (ręcznie)](#krok-9--przejście-przez-ui-z-logowaniem-ręcznie)
14. [Liczby z żywego stosu](#liczby-z-żywego-stosu)
15. [Ograniczenia](#ograniczenia)
16. [Co wybuchło po drodze](#co-wybuchło-po-drodze)
17. [Ściąga](#ściąga)

---

## 1. Problem

Pytania, na które do tej pory nie było odpowiedzi:

- Ile sesji, które coś wyszukały, kliknęło wynik? Ile z nich doszło do koszyka, a ile do zakupu?
- Które frazy kończą się zerem trafień (literówki, brakujący asortyment)?
- Jak bardzo klient klika to, co jest wysoko, tylko dlatego, że jest wysoko (bias pozycji)?
- Jak zmienia się CTR dzień po dniu?

Żeby odpowiedzieć, trzeba zbierać **zdarzenia** (nie stan): każde wyszukanie, kliknięcie, dodanie do koszyka
i zakup, przypięte do sesji. Do tego jedno ograniczenie z całego projektu (D-03): **catalog nigdy nie pisze do ES**.
Pisze tylko search-service.

## 2. Decyzje

| Pytanie | Wybór | Dlaczego |
|---|---|---|
| Czym jest sesja | cookie `mp_sid` (UUID, 30 min bezczynności, przedłużane każdym żądaniem) | sesja Laravela zmienia ID przy logowaniu (ochrona przed session fixation) i rozcięłaby lejek na „przed” i „po”. `mp_sid` przeżywa logowanie |
| Jak zdarzenie wychodzi z catalogu | wiersz w `outbox`, wysyła `outbox-publisher` | ta sama droga co zdarzenia domenowe: request nie dotyka RabbitMQ, `trace_id` za darmo |
| Kto rejestruje kliknięcie | serwer, przy wejściu na stronę produktu z `?sid=&pos=` | bez beacona JS: testowalne w Pest, działa bez JS |
| Skąd kliknięcie zna frazę | kontekst wyszukania w Redisie (`analytics:search:<id>`, 30 min) | Transform pivot nie umie łączyć dokumentów po `search_id`, więc każde zdarzenie niesie frazę samo |
| Odświeżenie strony produktu | `_id` kliknięcia = `click:<search_id>:<product_id>`, drugi zapis dostaje 409 | deduplikacja w jednym miejscu (handler), Transformy jej nie potrzebują |
| Gdzie trzymać zdarzenia | data streamy `events-user-default` i `events-impression-default` | dane tylko dopisywane, z czasem; rollover i kasowanie po 90 dniach robi ILM |
| Wyświetlenia (impressions) | osobny dokument na każdą pozycję 1–20 wyniku | CTR wg pozycji wymaga par (produkt, pozycja); pivot po polu wielowartościowym gubi pozycję |
| Synchronizacja Transformu | `sync` po `event.ingested` (czas przyjęcia przez ES), kubełki po `@timestamp` | sync po `@timestamp` po cichu gubiłby zdarzenia spóźnione (backfill, retry, przestój kolejki) |
| Dashboard w repo | NDJSON saved objects, import przez `_import?overwrite=true` | odtwarzalny na świeżej Kibanie jedną komendą |
| Koniec lejka | `user.checked_out` z requestu kupującego, nie zdarzenie Ordering | Ordering celowo nie wypuszcza zdarzeń zamówienia; płatność przychodzi webhookiem bez cookie sesji |

## 3. Mapa plików

```
apps/catalog/
  app/Http/Middleware/AnalyticsSession.php      cookie mp_sid, AnalyticsSession::currentId()
  app/Analytics/AnalyticsRecorder.php           zdarzenie -> wiersz outboxu (błąd = report(), nigdy 500)
  app/Analytics/SearchContextStore.php          Redis analytics:search:<id> -> {query, results}, TTL 1800 s
  app/Analytics/SearchedEventEmitter.php        user.searched (web i API, tylko pierwsza strona)
  app/Analytics/Simulation/*                    symulator: atrakcyjność, model kliknięć, pula fraz, publisher
  app/Console/Commands/AnalyticsSimulateCommand.php   php artisan analytics:simulate
apps/search/
  src/Analytics/AnalyticsDocumentMapper.php     koperta -> dokument(y) ES (+ impressions dla user.searched)
  src/Analytics/AnalyticsBulkWriter.php         _bulk create, 409 = duplikat
  src/MessageHandler/AnalyticsEventHandler.php  BatchHandlerInterface, paczki po 500
infra/elasticsearch/
  analytics/ilm-events-user.json                hot: rollover 1d/1gb, delete po 90 dniach
  analytics/pipeline-events-ingested.json       set event.ingested = _ingest.timestamp
  analytics/template-events-user.json           dynamic: strict, pola ECS-podobne
  analytics/template-events-impression.json
  analytics/index-search-stats-daily.json       indeks docelowy Transformu, jawne mapowanie
  analytics/transform-search-stats-daily.json   Transform ciągły (dzień × fraza)
  setup-analytics.sh                            sekcje 1–3, idempotentny (make analytics-setup)
infra/kibana/analytics-dashboard.ndjson         3 data views, 5 wizualizacji Lens, dashboard
compose.yaml                                    analytics-consumer; węzły ES z rolą transform
```

## 4. Cały tor 12a od zera

Stos (`make up`) musi już działać. Potem:

```bash
make analytics-setup                     # ILM, pipeline, szablony, data streamy, Transform, dashboard
docker compose up -d analytics-consumer  # konsument kolejki search.analytics.ingest
make simulate days=30                    # 30 dni historii (seed 1, 300 sesji/dzień)
# po ~1 min (ingest) + ~1–2 min (checkpoint Transformu):
open "http://localhost:${KIBANA_PORT:-5601}/app/dashboards#/view/search-analytics"
```

Wynik `make analytics-setup` na działającym stosie (drugie i kolejne uruchomienie, nic się nie zmienia):

```
[analytics] Węzłów: 3 → replik: 1
[analytics] [1] ILM events-user (hot: rollover 1d/1gb, delete po 90d)
[analytics] [1] Pipeline events-ingested (event.ingested)
[analytics] [1] Szablon events-user (dynamic: strict)
[analytics] [1] Data stream events-user-default już istnieje
[analytics] [1] Szablon events-impression (dynamic: strict)
[analytics] [1] Data stream events-impression-default już istnieje
[analytics] [1] OK
[analytics] [2] Indeks search-stats-daily już istnieje
[analytics] [2] Transform search-stats-daily już istnieje (definicji nie nadpisuję)
[analytics] [2] Transform search-stats-daily w stanie: started
[analytics] [2] OK
[analytics] [3] Czekam na Kibanę (http://kibana:5601)
[analytics] [3] Import analytics-dashboard.ndjson (overwrite=true)
[analytics] [3] Zaimportowano obiektów: 9
[analytics] [3] OK — dashboard: http://localhost:5601/app/dashboards#/view/search-analytics
[analytics] Gotowe.
```

Przy pierwszym uruchomieniu w sekcji 2 zamiast „już istnieje” są linie `Tworzę indeks…`, `Tworzę transform…`,
`Startuję transform…`.
Adres dashboardu w ostatniej linii sekcji 3 składa się z `KIBANA_PORT` z `.env` (tu 5601; na maszynie współdzielonej
z innymi projektami port może być przesunięty). Od części 12b skrypt ma jeszcze sekcje 4 (popularność, enrich)
i 5 (reguły anomalii), opisane w [przewodniku 12b](etap-12b-popularnosc-w-rankingu.md).

> **Węzły ES muszą mieć rolę `transform`.** W `compose.yaml` role węzłów są wypisane jawnie, a do ETAPU 12 nie było
> wśród nich `transform`. Bez niej każde wywołanie `_transform` kończy się błędem
> `Transform requires the transform node role for at least 1 node, found no transform nodes`
> ([RUNBOOK #128](../RUNBOOK.md#128)). Po zmianie `compose.yaml` węzły trzeba odtworzyć, po jednym.

Skrypt woła ES i Kibanę z wnętrza kontenera `es01` (hasło `elastic` jest tam w zmiennej środowiskowej), więc nic nie
wypisuje i nie potrzebuje haseł na hoście. Kibanę widzi po sieci compose pod `http://kibana:5601` (zmienna `KIBANA_URL`).

## Krok 1 — sesja `mp_sid` i zdarzenia w catalogu

Middleware `AnalyticsSession` siedzi w grupach `web` i `api`. Każda odpowiedź ustawia cookie na nowo (30 min od
ostatniego żądania):

```bash
curl -sk --resolve catalog.localhost:8443:127.0.0.1 -D - -o /dev/null https://catalog.localhost:8443/ | grep -i mp_sid
# set-cookie: mp_sid=<uuid>; expires=Fri, 20 Nov 2026 15:56:14 GMT; Max-Age=1800; path=/; httponly; samesite=lax
```

Zdarzenia (routing key = typ):

| Typ | Kiedy | Co niesie |
|---|---|---|
| `user.searched` | `SearchController::index` (web i API), tylko pierwsza strona | `search_id`, `query` (trim + lowercase), `query_raw`, `filters`, `total_hits`, `results` (top 20 ID), `sort` |
| `user.clicked_result` | wejście na `/products/{id}?sid=&pos=` | `search_id`, `query` (z Redisa), `product_id`, `position`, `dedup_key` |
| `user.added_to_cart` | `CartController::store` (wymaga logowania) | `product_id`, `offer_id`, `search_id` i `query`, jeśli produkt otwarto z wyników |
| `user.checked_out` | `CartController::checkout` | `order_ids`, `product_ids`, `amount_cents` |

`user_id` dochodzi, gdy użytkownik jest zalogowany. Koperta jest ta sama co dla zdarzeń domenowych, z
`aggregate = {type: "session", id: <mp_sid>}`.

**Pusta fraza też jest zdarzeniem.** Wejście na `/search` bez `q` (przeglądanie z filtrami) emituje `user.searched`
z `query = ""`. Mapper w search-service pomija puste napisy, więc w ES takie wyszukanie **nie ma pola
`search.query`**. Sprawdzone na żywo:

```bash
curl -sk --resolve catalog.localhost:8443:127.0.0.1 -c /tmp/cj 'https://catalog.localhost:8443/search'
curl -sk --resolve catalog.localhost:8443:127.0.0.1 -b /tmp/cj -c /tmp/cj 'https://catalog.localhost:8443/search?q='
```

```
FROM events-user-* | WHERE session.id == "dbfb5f72-e95f-41c2-a501-1bdeed33ead2"
| KEEP @timestamp, event.type, search.query, search.total_hits, user.id | SORT @timestamp

       @timestamp       |  event.type   | search.query  |search.total_hits|    user.id
------------------------+---------------+---------------+-----------------+---------------
2026-11-20T15:14:33.000Z|user.searched  |null           |10000            |null
2026-11-20T15:14:34.000Z|user.searched  |null           |10000            |null
```

Dwa żądania, jedna sesja (drugie przyszło z cookie z pierwszego). Do Transformu wrócimy w kroku 5.

## Krok 2 — data streamy, ILM i `event.ingested`

Sekcja 1 skryptu zakłada politykę ILM `events-user`, pipeline `events-ingested`, dwa szablony (oba
`dynamic: strict`) i dwa data streamy:

```bash
curl -s -u elastic:… 'http://localhost:19200/_data_stream/events-*?filter_path=data_streams.name,data_streams.status,data_streams.ilm_policy,data_streams.template'
```

```json
{"data_streams":[
  {"name":"events-impression-default","status":"GREEN","template":"events-impression","ilm_policy":"events-user"},
  {"name":"events-user-default","status":"GREEN","template":"events-user","ilm_policy":"events-user"}]}
```

Pipeline to jeden procesor: `set event.ingested = {{_ingest.timestamp}}`. Jest `index.default_pipeline` w obu
szablonach, więc nikt nie musi o nim pamiętać przy zapisie. Na dokumencie widać oba czasy:

```
FROM events-user-* METADATA _id | WHERE _id == "late-event-test-1" | KEEP @timestamp, event.ingested, search.query

       @timestamp       |     event.ingested     |   search.query
------------------------+------------------------+-------------------
2026-11-17T12:00:00.000Z|2026-11-20T15:22:23.729Z|__late_event_test__
```

`@timestamp` = kiedy klient coś zrobił (`occurred_at` z koperty), `event.ingested` = kiedy ES to przyjął. Przy
backfillu historii różnią się o dni. Ten dokument to test z kroku 6.

## Krok 3 — `analytics-consumer`: paczki i 409 jako deduplikacja

Osobny worker Messengera na transporcie `analytics` (kolejka `search.analytics.ingest`). Handler implementuje
`BatchHandlerInterface`: zbiera do 500 wiadomości i wysyła jeden `_bulk` z `op_type: create`. Paczka spływa, gdy jest
pełna albo gdy kolejka jest pusta. Wynik każdej pozycji:

| Odpowiedź ES | Co robi handler |
|---|---|
| 201 | ack |
| 409 (`_id` już jest) | ack: to duplikat z at-least-once albo odświeżone kliknięcie |
| 429 / 5xx | nack, retry Messengera (3 razy) |
| inne 4xx (np. `mapper_parsing_exception`) | `UnrecoverableMessageHandlingException`, od razu `failed` |

Log z dwóch wyszukań z kroku 1 (każde = 1 dokument `user.searched` + 20 impressions):

```
[app] Zapisano paczkę zdarzeń analityki. ["messages" => 2,"created" => 42,"duplicates" => 0,"rejected" => 0,"retryable" => 0]
```

`_id` dokumentu to `id` koperty, a dla kliknięcia `data.dedup_key`. Dlatego drugie wejście na tę samą stronę
produktu z tego samego wyszukania nie jest drugim kliknięciem: ES odpowiada 409, handler liczy je jako `duplicates`.

## Krok 4 — symulator klientów

Na prawdziwym ruchu z jednego laptopa żaden wykres nie ma sensu, więc `analytics:simulate` generuje sesje: frazy
z katalogu z rozkładem Zipfa (~5% spoza katalogu, czyli zero wyników), **prawdziwy ranking** z wyszukiwarki,
kliknięcia z prawdopodobieństwem `a / k^η` (ukryta atrakcyjność produktu `a`, pozycja `k`), koszyk, zakup.
Publikuje prosto do `marketplace.events` tą samą kopertą; od RabbitMQ w górę droga jest identyczna jak z UI.

```bash
make simulate days=30     # = php artisan analytics:simulate --days=30 --sessions-per-day=300 --seed=1
```

Przebieg z 2026-11-20 (as-of 2026-11-20, czyli dni 2026-10-21 … 2026-11-19):

| sesje | wyszukania | zero wyników | kliknięcia | koszyk | zakupy | opublikowane | czas komendy |
|---|---|---|---|---|---|---|---|
| 9000 | 17 972 | 925 (5,1%) | 17 175 | 1937 | 496 | 37 580 | 19,3 s |

Ingest przez `analytics-consumer` (wraz z 340 940 impressions): około 1 min. To samo ziarno daje te same zdarzenia
z tymi samymi ID, więc powtórka jest idempotentna (409). Szczegóły i pułapka z niedeterministycznymi remisami:
[RUNBOOK #132](../RUNBOOK.md#132).

## Krok 5 — Transform `search-stats-daily`

Liczenie CTR per fraza z surowych zdarzeń przy każdym otwarciu dashboardu to agregacja po kilkudziesięciu tysiącach
dokumentów. Transform liczy to raz i trzyma wynik w zwykłym indeksie: jeden dokument na parę (dzień, fraza).

`infra/elasticsearch/analytics/transform-search-stats-daily.json`, najważniejsze fragmenty:

```json
"source": {
  "index": ["events-user-*"],
  "query": { "terms": { "event.type": ["user.searched", "user.clicked_result"] } }
},
"dest": { "index": "search-stats-daily" },
"frequency": "1m",
"sync": { "time": { "field": "event.ingested", "delay": "60s" } },
"pivot": {
  "group_by": {
    "day":   { "date_histogram": { "field": "@timestamp", "calendar_interval": "1d" } },
    "query": { "terms": { "field": "search.query", "missing_bucket": true } }
  },
  "aggregations": {
    "searches":     { "filter": { "term": { "event.type": "user.searched" } } },
    "zero_results": { "filter": { "bool": { "filter": [
                        { "term": { "event.type": "user.searched" } },
                        { "term": { "search.total_hits": 0 } } ] } } },
    "sessions":     { "cardinality": { "field": "session.id" } },
    "clicks":       { "filter": { "term": { "event.type": "user.clicked_result" } } },
    "avg_click_position": { "avg": { "field": "click.position" } },
    "ctr": { "bucket_script": {
      "buckets_path": { "clicks": "clicks._count", "searches": "searches._count" },
      "script": "params.searches > 0 ? (double) params.clicks / params.searches : null" } }
  }
}
```

Decyzje w tym pliku:

- **`source.query` tylko wyszukania i kliknięcia.** Koszyk i zakup nie wchodzą do żadnej metryki, a zakup nie ma
  frazy, więc bez filtra wpadałby do kubełka „brak frazy” i zawyżał `sessions`.
- **`missing_bucket: true`.** Bez tego wyszukania bez frazy (krok 1) w ogóle nie trafiłyby do indeksu. Z nim
  powstaje kubełek `query = null`, czyli **przeglądanie bez frazy**. W nim lądują też kliknięcia, których kontekst
  w Redisie wygasł (brak `query`).
- **`avg` na `click.position` bez filtra.** Tylko kliknięcia mają to pole, więc średnia i tak liczy się z kliknięć.
- **`ctr` to kliknięcia na wyszukanie**, nie „odsetek wyszukań z kliknięciem”. Jedno wyszukanie może dać kilka kliknięć,
  więc wartość potrafi przekroczyć 1 (np. `koszulka termoaktywna extreme`: 1,456).
- **Indeks docelowy zakłada skrypt** (`index-search-stats-daily.json`, `dynamic: strict`), a nie Transform. Mapowanie
  wydedukowane przez Transform (podgląd `_transform/_preview`) nie miało pola `ctr`: wynik `bucket_script` dostałby typ
  z dynamicznego mapowania.

Weryfikacja po pierwszym checkpoincie:

```bash
curl -s -u elastic:… 'http://localhost:19200/_transform/search-stats-daily/_stats'
```

```
"state": "started", "health": {"status": "green"},
"documents_processed": 35148, "documents_indexed": 4940, "index_failures": 0, "search_failures": 0,
"exponential_avg_checkpoint_duration_ms": 1308.0,
"checkpointing": {"last": {"checkpoint": 1, ...}}
```

35 148 = 17 973 wyszukania + 17 175 kliknięć (koszyk i zakupy odfiltrowane). Sumy z indeksu docelowego zgadzają się
ze źródłem co do sztuki:

```
FROM search-stats-daily | STATS searches = SUM(searches), zero = SUM(zero_results), clicks = SUM(clicks), rows = COUNT(*)

   searches    |     zero      |    clicks     |     rows
---------------+---------------+---------------+---------------
17975          |925            |17175          |4941
```

(17 975 = 17 972 z symulatora + 3 wyszukania z UI z tego dnia.) Kubełek przeglądania bez frazy:

```
FROM search-stats-daily | WHERE query IS NULL | KEEP day, query, searches, clicks, sessions

          day           |     query     |   searches    |    clicks     |   sessions
------------------------+---------------+---------------+---------------+---------------
2026-11-20T00:00:00.000Z|null           |2              |0              |1
```

Symulator nie generuje pustych fraz, więc ten kubełek pochodzi tylko z dwóch żądań `curl` z kroku 1.

## Krok 6 — spóźnione zdarzenia: dlaczego sync po `event.ingested`

Transform ciągły w każdym checkpoincie pyta: „które dokumenty doszły od ostatniego razu?”. Odpowiada na to pole
`sync.time.field`. Gdyby było to `@timestamp`, zdarzenie z `@timestamp` sprzed 3 dni, przyjęte dziś (backfill,
retry z kolejki, przestój consumera), nie spełniłoby warunku „nowsze niż ostatni checkpoint” i **przepadłoby po
cichu**. Z `event.ingested` każde zdarzenie jest „nowe” w chwili przyjęcia, a kubełkowanie i tak idzie po
`@timestamp`, więc trafia do swojego dnia. `delay: 60s` daje zapas na odświeżenie indeksu (`refresh_interval`)
i rozjazd zegarów.

Test na żywo: jedno wyszukanie z `@timestamp` 2026-11-17 (3 dni temu), zapisane zwykłą drogą zapisu (rola
`search_service`, `_create` do data streamu, pipeline ustawia `event.ingested`), z frazą, której nie ma nigdzie indziej:

```bash
docker compose exec -T search-consumer sh -c \
  'curl -sS -u "$ELASTICSEARCH_USER:$ELASTICSEARCH_PASSWORD" -H "Content-Type: application/json" \
     -X PUT "$ELASTICSEARCH_HOST/events-user-default/_create/late-event-test-1" --data-binary @-' <<'EOF'
{"@timestamp":"2026-11-17T12:00:00.000Z","event":{"id":"late-event-test-1","type":"user.searched"},
 "session":{"id":"00000000-0000-4000-8000-00000000late"},
 "search":{"id":"late-event-test-1","query":"__late_event_test__","query_raw":"__late_event_test__",
           "total_hits":0,"results":[],"sort":"relevance"}}
EOF
# {"_index":".ds-events-user-default-2026.11.20-000001","_id":"late-event-test-1","result":"created",...}
```

Przed zapisem (15:22:23 UTC) zapytanie o `__late_event_test__` w `search-stats-daily` nie zwracało nic. Pętla co 10 s:

```
po 103 s:
          day           |       query       |   searches    | zero_results  |   sessions
------------------------+-------------------+---------------+---------------+---------------
2026-11-17T00:00:00.000Z|__late_event_test__|1              |1              |1
```

Górna granica to `frequency` (do 60 s czekania na następny checkpoint) plus `delay` (60 s), czyli około 2 min.

**Transform niczego nie cofa.** Po usunięciu dokumentu źródłowego kubełek został:

```bash
curl -s -u elastic:… -X DELETE 'http://localhost:19200/.ds-events-user-default-2026.11.20-000001/_doc/late-event-test-1'
# "result":"deleted"
```

```
FROM events-user-* METADATA _id | WHERE _id == "late-event-test-1" | STATS n = COUNT(*)     -> 0
FROM search-stats-daily | WHERE query == "__late_event_test__" | KEEP day, query, searches  -> 2026-11-17 | 1
```

Checkpoint wykrywa zmiany po nowych wartościach `event.ingested`. Usunięcie nie dodaje żadnego dokumentu, więc
kubełek nie jest przeliczany. Testowy kubełek usunąłem z indeksu docelowego ręcznie
(`_delete_by_query` po `query: __late_event_test__`, `deleted: 1`). Bezpieczne, bo w źródle nie ma już nic, co by go
odtworzyło. Szerzej: [RUNBOOK #130](../RUNBOOK.md#130).

## Krok 7 — dashboard „Wyszukiwarka — analityka”

`infra/kibana/analytics-dashboard.ndjson` (9 obiektów, ID czytelne, więc import z `overwrite=true` jest idempotentny):

| Obiekt | ID | Źródło | Co pokazuje |
|---|---|---|---|
| data view | `events-user` | `events-user-*` (`@timestamp`) | Discover na zdarzeniach |
| data view | `events-impression` | `events-impression-*` (`@timestamp`) | Discover na wyświetleniach |
| data view | `search-stats-daily` | `search-stats-daily` (czas: `day`) | wykresy z Transformu |
| Lens (ES\|QL) | `search-analytics-funnel` | `events-user-*` | lejek: sesje na 4 etapach |
| Lens (ES\|QL) | `search-analytics-ctr-by-position` | `events-user-*, events-impression-*` | CTR wg pozycji 1–20 |
| Lens | `search-analytics-ctr-over-time` | `search-stats-daily` | formuła `sum(clicks) / sum(searches)` per dzień |
| Lens | `search-analytics-zero-results` | `search-stats-daily` | top 15 fraz wg `sum(zero_results)` |
| Lens | `search-analytics-top-queries` | `search-stats-daily` | tabela top 20 fraz: wyszukania, zero results, kliknięcia, CTR |
| dashboard | `search-analytics` | wszystkie powyżej + opis (Markdown) | domyślny zakres `now-31d/d` → `now` |

Dwa panele są w ES|QL, bo zwykły Lens (jeden data view na warstwę) ich nie policzy: lejek wymaga dwóch poziomów
agregacji (najpierw per sesja, potem po sesjach), a CTR wg pozycji łączy dwa data streamy.

**Jak powstał NDJSON** (żeby dało się go zmienić):

1. Data views: `POST /api/data_views/data_view` ze stałymi `id`.
2. Panele na data view: publiczne API Lens jako kod, `POST /api/visualizations` (Kibana 9.5, nagłówek
   `Elastic-Api-Version: 2023-10-31`). Kibana waliduje konfigurację i sama buduje stan Lensa. Przykład (top zero results):

   ```json
   {"type":"xy","title":"Top frazy bez wyników (zero results)",
    "layers":[{"type":"bar_horizontal",
      "data_source":{"type":"data_view_reference","ref_id":"search-stats-daily"},
      "x":{"operation":"terms","fields":["query"],"limit":15,
           "rank_by":{"type":"metric","metric_index":0,"direction":"desc"}},
      "y":[{"operation":"sum","field":"zero_results","label":"Wyszukania bez wyników"}]}]}
   ```

3. Panele ES|QL: to samo API **odrzuca** `data_source.type: "esql"` (opis endpointu: „ES|QL visualizations cannot be
   created through this endpoint”). Stan Lensa zbudowałem tym samym kodem, którego używa Kibana (`LensConfigBuilder`
   z `@kbn/lens-embeddable-utils`, uruchomiony node'em z kontenera Kibany), i zapisałem jako saved object `lens`.
   Szczegóły i pułapki: [RUNBOOK #129](../RUNBOOK.md#129).
4. Dashboard: `PUT /api/dashboards/search-analytics` z panelami `{"type":"vis","config":{"ref_id":…}}`.
5. `POST /api/saved_objects/_export` z `includeReferencesDeep: true`, potem zamiana losowych UUID wizualizacji na
   czytelne ID i usunięcie pól `version`/`updated_at`.

**Weryfikacja importu:** obiekty usunięte z Kibany, potem `make analytics-setup` → `Zaimportowano obiektów: 9`,
a `GET /api/dashboards/search-analytics` zwraca 6 paneli z poprawnymi `ref_id`. Drugi test na „świeżej” Kibanie:
pusta przestrzeń `import-test`, import tego samego pliku → `success: true, successCount: 9, errors: None`, dashboard
czytelny przez API (w nowej przestrzeni Kibana nadaje obiektom nowe ID i spójnie przepina referencje; przestrzeń
potem usunięta). Wygląd paneli oceń sam w przeglądarce: tego nie sprawdzałem.

## Krok 8 — ES|QL: lejek, zero results, CTR wg pozycji

Każde zapytanie poniżej uruchomione na żywych danych (`POST /_query?format=txt`, albo Discover w trybie ES|QL).

### Lejek per sesja

Definicja: sesje z ≥1 `user.searched` → z nich te z ≥1 `user.clicked_result` → z nich te z ≥1 `user.added_to_cart`
→ z nich te z ≥1 `user.checked_out`. **Krok koszyka wymaga logowania**: anonimowa sesja odpada na nim albo idzie dalej
po zalogowaniu (to samo `mp_sid`).

Najpierw jak wygląda poziom sesji:

```
FROM events-user-*
| STATS s = COUNT(*) WHERE event.type == "user.searched",
        c = COUNT(*) WHERE event.type == "user.clicked_result",
        a = COUNT(*) WHERE event.type == "user.added_to_cart",
        o = COUNT(*) WHERE event.type == "user.checked_out"
  BY session.id
| SORT o DESC, a DESC, session.id
| LIMIT 5

       s       |       c       |       a       |       o       |             session.id
---------------+---------------+---------------+---------------+------------------------------------
3              |5              |3              |1              |5c15bcd0-76a3-46dd-9e5f-14751a31b438
1              |4              |3              |1              |8f19de22-4b13-4c07-8019-5ae6806b5460
3              |7              |3              |1              |e61ae6f1-1981-46d6-9751-63134616e89b
3              |5              |2              |1              |03f6a810-7235-4f8c-821d-fe40426dc04d
3              |5              |2              |1              |06dcf441-757c-4fc0-9a4c-5535eb2f2c02
```

Drugi `STATS` liczy sesje spełniające warunki kolejnych etapów (to samo zapytanie jest w panelu lejka):

```
FROM events-user-*
| STATS s = COUNT(*) WHERE event.type == "user.searched",
        c = COUNT(*) WHERE event.type == "user.clicked_result",
        a = COUNT(*) WHERE event.type == "user.added_to_cart",
        o = COUNT(*) WHERE event.type == "user.checked_out"
  BY session.id
| STATS `1. wyszukanie` = COUNT(*) WHERE s > 0,
        `2. kliknięcie` = COUNT(*) WHERE s > 0 AND c > 0,
        `3. koszyk`     = COUNT(*) WHERE s > 0 AND c > 0 AND a > 0,
        `4. checkout`   = COUNT(*) WHERE s > 0 AND c > 0 AND a > 0 AND o > 0

 1. wyszukanie | 2. kliknięcie |   3. koszyk   |  4. checkout
---------------+---------------+---------------+---------------
9002           |7349           |1734           |496
```

81,6% sesji kliknęło wynik, 23,6% klikających dodało coś do koszyka, 28,6% z nich kupiło. 9002 = 9000 sesji
symulatora + 2 sesje z UI.

Jedna sesja od środka (widać, że `user.id` pojawia się dopiero przy koszyku, czyli po zalogowaniu):

```
FROM events-user-* | WHERE session.id == "5c15bcd0-76a3-46dd-9e5f-14751a31b438"
| EVAL q = search.query | KEEP @timestamp, event.type, q, click.position, product.id, user.id | SORT @timestamp

       @timestamp       |    event.type     |              q              |click.position |       product.id       |    user.id
------------------------+-------------------+-----------------------------+---------------+------------------------+---------------
2026-11-08T19:55:27.000Z|user.searched      |słuchawki bezprzewodowe ultra|null           |null                    |null
2026-11-08T19:59:46.000Z|user.searched      |prime                        |null           |null                    |null
2026-11-08T20:00:33.000Z|user.clicked_result|prime                        |2              |151666                  |null
2026-11-08T20:00:46.000Z|user.added_to_cart |prime                        |null           |151666                  |900000367
...
2026-11-08T20:09:30.000Z|user.checked_out   |null                         |null           |[148936, 151666, 154510]|900000367
```

### Zero results: top frazy

```
FROM events-user-*
| WHERE event.type == "user.searched" AND search.total_hits == 0
| STATS searches = COUNT(*), sessions = COUNT_DISTINCT(session.id) BY search.query
| SORT searches DESC, search.query
| LIMIT 10

   searches    |   sessions    |    search.query
---------------+---------------+--------------------
81             |81             |lodówka side by side
78             |78             |słuchwki
76             |76             |pralka
71             |71             |telewizor oled
70             |70             |ekspres ciśnieniowy
70             |69             |laptpo
68             |68             |karma dla kota
66             |65             |mikrofalówka
66             |66             |suszarka bębnowa
65             |65             |odkurzacz pionowy
```

Dwa rodzaje: literówki (`słuchwki`, `laptpo`: zadanie dla synonimów albo `fuzziness`) i brakujący asortyment
(AGD, karma: zadanie dla działu zakupów).

### CTR wg pozycji: wyświetlenia kontra kliknięcia

```
FROM events-user-*, events-impression-*
| WHERE impression.position IS NOT NULL OR event.type == "user.clicked_result"
| EVAL position = COALESCE(click.position, impression.position)
| STATS impressions = COUNT(impression.position), clicks = COUNT(click.position) BY position
| EVAL ctr = ROUND(TO_DOUBLE(clicks) / impressions, 4)
| SORT position

  impressions  |    clicks     |   position    |      ctr
---------------+---------------+---------------+---------------
17050          |4168           |1              |0.2445
17050          |3097           |2              |0.1816
17050          |1212           |3              |0.0711
17050          |976            |4              |0.0572
17050          |1006           |5              |0.059
17050          |812            |6              |0.0476
17050          |704            |7              |0.0413
17050          |603            |8              |0.0354
17050          |567            |9              |0.0333
17050          |620            |10             |0.0364
...
17050          |226            |19             |0.0133
17050          |257            |20             |0.0151
```

Sztuczki: `FROM` z dwoma wzorcami daje jedną tabelę, w której pola z drugiego data streamu są `null`. `COALESCE`
skleja dwie kolumny pozycji w jedną. `COUNT(pole)` liczy tylko wiersze, w których pole jest. `TO_DOUBLE` jest potrzebne,
bo `long / long` w ES|QL to dzielenie całkowite. 17 050 wyświetleń na każdej pozycji = 17 050 wyszukań z ≥20 wynikami
(wszystkie poza 925 zerowymi). Spadek z 24% na pozycji 1 do 1,5% na pozycji 20 to bias pozycji z modelu symulatora
(plus różna atrakcyjność produktów). 12b zrobi z tego korektę IPS.

### Top frazy i CTR dzień po dniu (z `search-stats-daily`)

```
FROM search-stats-daily
| STATS searches = SUM(searches), zero = SUM(zero_results), clicks = SUM(clicks) BY query
| EVAL ctr = ROUND(TO_DOUBLE(clicks) / searches, 3)
| SORT searches DESC
| LIMIT 10

   searches    |     zero      |    clicks     |            query            |      ctr
---------------+---------------+---------------+-----------------------------+---------------
3424           |0              |3386           |prime                        |0.989
1572           |0              |1603           |ultrabook extreme 13"        |1.02
988            |0              |889            |hantle                       |0.9
713            |0              |749            |kurtka zimowa pro x          |1.05
583            |0              |511            |neo                          |0.877
465            |0              |677            |koszulka termoaktywna extreme|1.456
385            |0              |554            |koszulka termoaktywna zenit  |1.439
369            |0              |242            |smartfon nova 256gb 5g       |0.656
294            |0              |272            |smartfon nova pro            |0.925
245            |0              |238            |laptop pro x 15.6" gamingowy |0.971
```

```
FROM search-stats-daily
| WHERE day >= "2026-11-13"
| STATS searches = SUM(searches), clicks = SUM(clicks), zero = SUM(zero_results) BY day
| EVAL ctr = ROUND(TO_DOUBLE(clicks) / searches, 3), zero_rate = ROUND(TO_DOUBLE(zero) / searches, 3)
| SORT day

   searches    |    clicks     |     zero      |          day           |      ctr      |   zero_rate
---------------+---------------+---------------+------------------------+---------------+---------------
603            |565            |23             |2026-11-13T00:00:00.000Z|0.937          |0.038
620            |580            |37             |2026-11-14T00:00:00.000Z|0.935          |0.06
608            |570            |31             |2026-11-15T00:00:00.000Z|0.938          |0.051
609            |589            |28             |2026-11-16T00:00:00.000Z|0.967          |0.046
583            |590            |23             |2026-11-17T00:00:00.000Z|1.012          |0.039
576            |500            |36             |2026-11-18T00:00:00.000Z|0.868          |0.063
584            |556            |29             |2026-11-19T00:00:00.000Z|0.952          |0.05
3              |0              |0              |2026-11-20T00:00:00.000Z|0.0            |0.0
```

Uwaga na `SUM(sessions)`: `sessions` to `cardinality` w obrębie dnia i frazy, więc suma po dniach albo frazach liczy
tę samą sesję kilka razy. Unikalne sesje w dłuższym okresie licz ze źródła (`COUNT_DISTINCT(session.id)`).

### ES|QL kontra SQL

Elasticsearch ma też starsze `_sql`. Proste grupowanie działa tak samo:

```bash
curl -s -u elastic:… -H 'Content-Type: application/json' 'http://localhost:19200/_sql?format=txt' \
  -d '{"query":"SELECT \"search.query\" AS q, COUNT(*) AS searches FROM \"events-user-*\" WHERE \"event.type\" = '\''user.searched'\'' AND \"search.total_hits\" = 0 GROUP BY q ORDER BY searches DESC LIMIT 5"}'
```

```
         q          |   searches
--------------------+---------------
lodówka side by side|81
słuchwki            |78
pralka              |76
telewizor oled      |71
ekspres ciśnieniowy |70
```

`POST /_sql/translate` pokazuje, że pod spodem to zwykłe zapytanie z agregacją `composite` (`terms` na `search.query`).
Lejek już nie przechodzi:

```
SELECT COUNT(*) FROM (SELECT "session.id" AS sid, COUNT(*) AS n FROM "events-user-*" GROUP BY sid) t WHERE n > 0
-> verification_exception: Nested aggregations in sub-selects are not supported.
```

| | ES\|QL | ES `_sql` | SQL (np. PostgreSQL) |
|---|---|---|---|
| Model | potok `\|`, każdy krok dostaje tabelę z poprzedniego | tłumaczenie na Query DSL + agregacje | relacyjny, zapytanie deklaratywne |
| Agregacja po agregacji (lejek) | tak: drugi `STATS` po pierwszym | nie (`Nested aggregations in sub-selects are not supported`) | tak: podzapytanie albo CTE |
| Agregat warunkowy | `COUNT(*) WHERE warunek` | brak | `COUNT(*) FILTER (WHERE …)` |
| Kilka indeksów naraz | `FROM a-*, b-*` (brakujące pola = `null`) | jeden wzorzec | `UNION ALL` / `JOIN` |
| Wykonanie | własny silnik obliczeniowy w ES | Query DSL | planer bazy |

Ten sam lejek w PostgreSQL (dla porównania składni; zdarzenia nie leżą w Postgresie, więc tego nie uruchamiałem):

```sql
WITH per_session AS (
  SELECT session_id,
         COUNT(*) FILTER (WHERE type = 'user.searched')       AS s,
         COUNT(*) FILTER (WHERE type = 'user.clicked_result') AS c,
         COUNT(*) FILTER (WHERE type = 'user.added_to_cart')  AS a,
         COUNT(*) FILTER (WHERE type = 'user.checked_out')    AS o
  FROM events GROUP BY session_id)
SELECT COUNT(*) FILTER (WHERE s > 0)                               AS wyszukanie,
       COUNT(*) FILTER (WHERE s > 0 AND c > 0)                     AS klikniecie,
       COUNT(*) FILTER (WHERE s > 0 AND c > 0 AND a > 0)           AS koszyk,
       COUNT(*) FILTER (WHERE s > 0 AND c > 0 AND a > 0 AND o > 0) AS checkout
FROM per_session;
```

ES|QL jest tu bliżej SQL-a niż `_sql`, mimo że tylko `_sql` nazywa się SQL.

## Krok 9 — przejście przez UI z logowaniem (ręcznie)

Tego kroku nie robiłem (wymaga zalogowania). Koszyk i checkout są pokryte testami feature
(`apps/catalog/tests/Feature/Analytics/FunnelEventsTest.php`), a kliknięcie z dedupem sprawdzone na żywo przez `curl`.
Żeby zobaczyć cały lejek z jednej własnej sesji:

1. Otwórz `https://catalog.localhost:8443/search?q=buty` (bez logowania).
2. W DevTools → Application → Cookies odczytaj `mp_sid`.
3. Kliknij wynik: URL produktu ma `?sid=<search_id>&pos=<n>`. Odśwież stronę (drugie kliknięcie ma być odrzucone 409).
4. Zaloguj się kontem kupującego z seedera i dodaj produkt do koszyka (`mp_sid` się nie zmienia).
5. Złóż zamówienie (checkout).
6. Po kilku sekundach:

   ```
   FROM events-user-* | WHERE session.id == "<mp_sid>"
   | KEEP @timestamp, event.type, search.query, click.position, product.id, user.id | SORT @timestamp
   ```

   Oczekiwane: `user.searched` → jedno `user.clicked_result` (mimo odświeżenia) → `user.added_to_cart` (już z `user.id`)
   → `user.checked_out`. Lejek na dashboardzie urośnie o jedną sesję na każdym etapie.

## Liczby z żywego stosu

| Co | Wartość |
|---|---|
| Symulacja | 9000 sesji, 37 580 zdarzeń, 19,3 s komendy, ingest ~1 min |
| `events-user-default` | 37 583 dok., 12,1 MB primary (339 B/dok.), 32,6 MB z repliką |
| `events-impression-default` | 341 000 dok., 25,3 MB primary (78 B/dok.), 67,9 MB z repliką |
| `search-stats-daily` | 4941 dok. (dzień × fraza), 543 KB primary |
| Pierwszy checkpoint Transformu | 35 148 dok. źródłowych → 4940 kubełków, ~1,3 s |
| Spóźnione zdarzenie (3 dni) | widoczne w kubełku swojego dnia po 103 s |
| Lejek | 9002 → 7349 → 1734 → 496 sesji |
| CTR wg pozycji | 24,5% (poz. 1), 18,2% (poz. 2), 7,1% (poz. 3) … 1,5% (poz. 20) |

Więcej: [`POMIARY.md` §16](../POMIARY.md).

## Ograniczenia

- **Transform nie cofa usunięć ani nie „zapomina” danych.** Usunięcie zdarzeń ze źródła (RODO, sprzątanie testów,
  ILM kasujące indeksy po 90 dniach) zostawia kubełki w `search-stats-daily`. Pełne przeliczenie: zatrzymać Transform,
  usunąć go i indeks, `make analytics-setup` (ściąga niżej).
- **Zmiana definicji Transformu nie wchodzi sama.** Skrypt tworzy go tylko, gdy go brak (pivotu nie da się zmienić
  przez `_update`). Po zmianie JSON-a trzeba przebudować jak wyżej.
- **Deduplikacja przez 409 działa w obrębie jednego backing index.** Duplikat po rolloverze (np. ręczne
  `messenger:failed:retry` po dniu) przejdzie.
- **CTR to kliknięcia na wyszukanie**, nie odsetek wyszukań z kliknięciem. Do tego drugiego trzeba by liczyć
  unikalne `search.id` z kliknięciem.
- **`sessions` w `search-stats-daily` nie sumuje się** po dniach ani frazach (cardinality, krok 8).
- **Dashboard sprawdzony przez API, nie wzrokowo.** Import, referencje i odczyt dashboardu przez
  `/api/dashboards` są potwierdzone, renderu paneli w przeglądarce nie oglądałem.
- **Kontekst w Redisie żyje 30 min.** Kliknięcie po dłuższej przerwie trafia do kubełka „brak frazy”.
- **Znany błąd do poprawki w końcowej fali ETAPU 12:** w `CartController::checkout` odczyt ofert do zdarzenia
  `user.checked_out` jest poza `try/catch`, więc wyjątek w tym miejscu dałby 500 już po złożeniu zamówień (wbrew
  zasadzie „analityka nigdy nie psuje requestu”).

## Co wybuchło po drodze

| RUNBOOK | Objaw |
|---|---|
| [#128](../RUNBOOK.md#128) | `Transform requires the transform node role for at least 1 node, found no transform nodes` |
| [#129](../RUNBOOK.md#129) | `/api/visualizations` odrzuca ES\|QL, `/internal/lens/visualizations` daje 500; budowanie stanu Lensa poza Kibaną |
| [#130](../RUNBOOK.md#130) | Usunięte zdarzenie dalej liczone w `search-stats-daily` |
| [#131](../RUNBOOK.md#131) | Wyjątek mappera w `AnalyticsEventHandler` zostawiał wiadomości paczki bez ack/nack |
| [#132](../RUNBOOK.md#132) | Powtórka dnia symulatora dawała inne ID i duplikaty: remisy w rankingu ES |

## Ściąga

```bash
# cały tor od zera
make analytics-setup && docker compose up -d analytics-consumer && make simulate days=30

# stan Transformu
curl -s -u elastic:… 'http://localhost:19200/_transform/search-stats-daily/_stats?filter_path=transforms.state,transforms.checkpointing.last,transforms.stats.documents_processed'

# pełne przeliczenie search-stats-daily (po zmianie definicji albo po usunięciu danych ze źródła)
curl -s -u elastic:… -X POST   'http://localhost:19200/_transform/search-stats-daily/_stop?wait_for_completion=true'
curl -s -u elastic:… -X DELETE 'http://localhost:19200/_transform/search-stats-daily'
curl -s -u elastic:… -X DELETE 'http://localhost:19200/search-stats-daily'
make analytics-setup

# rozmiar data streamów
curl -s -u elastic:… 'http://localhost:19200/_cat/indices/.ds-events-*,search-stats-daily?v&h=index,docs.count,store.size,pri.store.size'

# ES|QL z terminala
curl -s -u elastic:… -H 'Content-Type: application/json' 'http://localhost:19200/_query?format=txt' \
  -d '{"query":"FROM search-stats-daily | STATS s = SUM(searches), c = SUM(clicks) BY day | SORT day DESC | LIMIT 3"}'

# dashboard: tylko import (sekcja 3 skryptu jest idempotentna)
make analytics-setup
open "http://localhost:${KIBANA_PORT:-5601}/app/dashboards#/view/search-analytics"
```

`elastic:…` = hasło z `ELASTIC_PASSWORD` w `.env`. `KIBANA_PORT` też jest w `.env` (domyślnie 5601).
