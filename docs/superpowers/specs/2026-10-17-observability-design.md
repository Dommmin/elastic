# ETAP 11: tor observability (logi w ES, metryki i alerty w Prometheusie)

Data: 2026-10-17 · Status: projekt do przeglądu, przed planem

## Cel

Wdrożyć podział z [ADR-0005](../../adr/0005-metryki-w-prometheusie-logi-w-es.md):
logi aplikacji w Elasticsearchu (ECS, data streams, ILM, korelacja po
`trace_id`), metryki klastra ES przez Metricbeat do Stack Monitoring,
pozostałe metryki i **wszystkie** alerty w Prometheusie, powiadomienia
przez Alertmanager do mailpita, dashboardy w Grafanie.

Cel nauki: ES jako platforma logów (ingest pipeline, szablony, data
streams, ILM na żywo, ECS), PromQL i reguły alertów z testami
jednostkowymi, oraz gauge liczone przy scrape jako sposób na metryki
z aplikacji PHP bez współdzielonej pamięci.

## Zakres

**W zakresie (ten PR):**
- profil Compose `obs`: `obs-setup` (jednorazowy), `filebeat`, `metricbeat`,
  `prometheus`, `alertmanager`, `grafana`, `search-metrics`; `mailpit`
  dostaje profile `[tools, obs]`;
- logi JSON z catalog (Laravel + access log Caddy) i search do data streams
  `logs-app-catalog` i `logs-app-search` przez ingest pipeline mapujący na ECS;
- ILM produkcyjna i demonstracyjna (minutowa);
- Metricbeat dla Stack Monitoring klastra ES;
- propagacja `trace_id`: HTTP catalog → outbox → RabbitMQ → search-consumer
  → wewnętrzne API catalog → zapis do ES;
- scrape: `rabbitmq_prometheus`, metryki Caddy/FrankenPHP, gauge catalog
  (`spatie/laravel-prometheus`), gauge failure transportu search;
- reguły alertów z testami `promtool test rules`, Alertmanager → mailpit;
- dwa dashboardy Grafany (provisioning z repo);
- pomiar narzutu RAM profilu `obs` w `POMIARY.md`, wpisy RUNBOOK,
  przewodnik `docs/blog/etap-11-observability.md`.

**Poza zakresem (kolejny PR, zapisane w planie ETAPU 11):**
- `promphp/prometheus_client_php` z Redisem w search (liczniki bulka,
  błędy pozycji, histogram czasu bulka, gauge opóźnienia synchronizacji);
  podepnie się pod endpoint `search-metrics` z tego PR;
- `postgres_exporter`, `elasticsearch_exporter`, `node_exporter`;
- ćwiczenie porównawcze Metricbeat (moduł `prometheus`) → ES vs Prometheus;
- manifesty k3s i VPS (retencja 7 dni) — osobno, po ustabilizowaniu lokalnie;
- APM/ślady.

## Decyzje

### D1. ECS powstaje w ingest pipeline, nie w PHP

Aplikacje piszą **standardowy JSON Monologa** (`Monolog\Formatter\JsonFormatter`)
na stderr. Pipeline `logs-app` w ES mapuje go na ECS.

Rozważane:
- `elastic/ecs-logging` w PHP — wersja 2.x to własny logger PSR, nie
  formatter Monologa; ryzyko kompatybilności z Laravel/Symfony i mniej
  praktyki z ES;
- mapowanie w Filebeacie (`decode_json_fields` + `rename`) — logika w YAML-u
  shippera zamiast w ES; nie da się jej przetestować `_simulate`.

Konsekwencja: pipeline zależy od kształtu JSON-a Monologa 3 (te same pola
w obu aplikacjach). Zmiana formatu = zmiana pipeline'u. Test: `_ingest/pipeline/logs-app/_simulate`
na przykładowych liniach z obu aplikacji i z Caddy (skrypt w `infra/obs/elasticsearch/`).

### D2. Filebeat bierze tylko linie JSON

Autodiscover dockera z warunkiem na etykietę
`com.docker.compose.project=marketplace` i listę serwisów (`catalog-app`,
`catalog-worker`, `catalog-scheduler`, `outbox-publisher`, `search-consumer`,
`search-metrics`). `include_lines: ['^\{']` — tekst dla człowieka (np. `-vv`
konsumenta, `info()` komend artisan) zostaje w `docker logs`, do ES idzie
tylko JSON. Kontenery innych projektów na tej maszynie (igrit, starter)
nie są czytane.

Pole `fields.app` (`catalog` / `search`) wybiera data stream:
`index: logs-app-%{[fields.app]}`. `setup.template` i `setup.ilm` w Filebeacie
wyłączone — szablon i ILM zakłada `obs-setup`, nie shipper.

### D3. Data streams, szablony, ILM

- Komponenty: `logs-app@mappings` (ECS: `@timestamp`, `log.level`,
  `log.logger`, `message` jako `match_only_text`, `trace.id`, `service.name`,
  `event.dataset`, `http.*`, `url.path`, `error.*`; `labels` i
  `app.context` jako `flattened`, żeby dowolny kontekst logu nie rozdmuchał
  mapowania), `logs-app@settings` (1 shard, 1 replika, `index.default_pipeline: logs-app`).
- Szablon `logs-app` dla `logs-app-*`, `data_stream: {}`, **priorytet 200**
  (wbudowany `logs` dla `logs-*-*` ma 100 i wygrałby, ustawiając swój ILM).
- ILM `logs-app`: hot (rollover `max_age: 1d` lub `max_primary_shard_size: 1gb`)
  → warm po 2 d (`forcemerge: 1`, `readonly`) → delete po 7 d.
  Lokalna maszyna jest współdzielona; 7 dni logów to dziesiątki MB.
- Demo: szablon `logs-ilmdemo` dla `logs-ilmdemo-*` z tymi samymi komponentami
  i polityką `logs-ilmdemo`: rollover `max_age: 2m` → warm po 3 m → delete po 8 m.
  Klaster ma już `indices.lifecycle.poll_interval: 10s`. Przewodnik wysyła
  dokumenty i ogląda `_ilm/explain`.

### D4. Pipeline `logs-app`

Trzy gałęzie po sparsowaniu `message` jako JSON (procesor `json`
z `add_to_root`; błąd parsowania → `event.original` zostaje, tag
`json_parse_failure`, dokument nie przepada):

1. **Monolog** (jest `level_name`): `datetime` → `@timestamp`,
   `level_name` → `log.level` (lowercase), `channel` → `log.logger`,
   `context.trace_id` / `extra.trace_id` → `trace.id`, `context.exception`
   → `error.message` / `error.type` / `error.stack_trace`, reszta `context`
   → `app.context`.
2. **Access log Caddy** (`logger` == `http.log.access*`): `ts` → `@timestamp`,
   `request.method` / `request.uri` / `status` / `duration` → `http.request.method`,
   `url.original`, `http.response.status_code`, `event.duration` (ns),
   `resp_headers.X-Trace-Id[0]` → `trace.id`, `event.dataset: catalog.access`.
3. **Pozostałe logi Caddy/FrankenPHP** (jest `logger`, nie access): `ts`, `level`, `msg`.

Wspólne: `service.name` z etykiety compose (Filebeat dodaje metadane
kontenera), `event.dataset` domyślnie `<app>.<service>`, usunięcie
surowych pól po mapowaniu.

### D5. Metricbeat tylko dla Stack Monitoring

Moduł `elasticsearch`, `xpack.enabled: true`, `scope: cluster`,
`hosts: [http://es01:9200]` — ta sama konfiguracja dla 1 i 3 węzłów.
Użytkownik wbudowany `remote_monitoring_user` (hasło ustawia `obs-setup`).
Wynik: Kibana → Stack Monitoring. **Żadnych** alertów w Kibanie (ADR, zasada 5).

### D6. `trace_id` od żądania do ES

Format: 32 znaki hex (trace-id W3C), żeby dało się później przejść na
OpenTelemetry bez zmiany pól.

1. **catalog, HTTP** — middleware `AssignTraceId` (globalny): bierze trace-id
   z nagłówka `traceparent`, jeśli poprawny; inaczej generuje
   (`bin2hex(random_bytes(16))`). Zapisuje w `Context::add('trace_id', …)`
   Laravela — Context trafia do każdego logu i jest **automatycznie
   przenoszony do jobów kolejki**, więc kroki sagi w `catalog-worker` logują
   ten sam ślad. Odpowiedź dostaje nagłówek `X-Trace-Id`.
2. **catalog, konsola** — klasa `TraceId` z `current()`: wartość z Context
   albo nowa (zapamiętana w Context). Sweep i seed dostają więc własny ślad
   na przebieg.
3. **outbox** — migracja: `outbox.trace_id` `char(32)` nullable. Obie ścieżki
   zapisu (`App\Concerns\EmitsOutboxEvents`,
   `SellerPortal\Infrastructure\Persistence\OfferIntegrationEvents`)
   wpisują `TraceId::current()`. Nullable, bo stare wiersze go nie mają.
4. **publisher** — nagłówek AMQP `application_headers.trace_id` (koperta
   zdarzenia z `docs/05` bez zmian — to metadane transportu, nie treść
   zdarzenia) i log `debug` per zdarzenie z `trace_id`, `event_id`, `type`.
   Publisher ustawia Context na czas jednej wiadomości, żeby log miał
   właściwy ślad.
5. **search, odbiór** — `ExternalJsonEnvelopeSerializer::decode()` czyta
   nagłówek do `TraceIdStamp`; `encode()` zapisuje go z powrotem
   (retry nie gubi śladu — ta sama lekcja co `RETRY_COUNT_HEADER`, RUNBOOK #027).
   Subscriber na `WorkerMessageReceivedEvent` ustawia serwis `TraceContext`,
   na `WorkerMessageHandledEvent` / `WorkerMessageFailedEvent` go czyści.
   Procesor Monologa dopisuje `extra.trace_id`.
6. **search → catalog** — `CatalogProjectionClient` wysyła
   `traceparent: 00-<trace_id>-<16 hex>-01`; middleware z punktu 1 przyjmuje
   go w wewnętrznym API, więc żądanie projekcji ma ten sam ślad.
7. **zapis do ES** — istniejący log `info` „Indexed” w `ProductSyncHandler`
   ma teraz `trace.id`.

Efekt w Kibanie: filtr `trace.id: <X-Trace-Id z odpowiedzi>` pokazuje
access log, log kontrolera, publikację, odbiór w search, żądanie projekcji
i wynik indeksowania.

Logi: catalog `LOG_STACK=single,stderr` + `LOG_STDERR_FORMATTER=Monolog\Formatter\JsonFormatter`
(ustawione w `compose.yaml`, testy i host bez zmian); search — handler
`stderr_json` (`php://stderr`, `monolog.formatter.json`, poziom `info`)
w `when@dev` i `when@prod`.

### D7. Źródła metryk w Prometheusie

Scrape co 15 s, evaluation co 15 s, retencja 15 d (`--storage.tsdb.retention.time`).

| Job | Cel | Uwagi |
|---|---|---|
| `rabbitmq` | `rabbitmq:15692/metrics` | metryki brokera (pamięć, alarmy, połączenia) |
| `rabbitmq-queues` | `rabbitmq:15692/metrics/detailed?family=queue_coarse_metrics&family=queue_consumer_count` | per kolejka: `rabbitmq_detailed_queue_messages_ready`, `rabbitmq_detailed_queue_consumers` |
| `caddy` | `catalog-app:2020/metrics` | globalna opcja `metrics { per_host }` w Caddyfile + blok `http://:2020 { metrics }`; admin API zostaje na localhost (zmienia konfigurację, nie wystawiamy go) |
| `catalog` | `catalog-app:80/prometheus` | `spatie/laravel-prometheus` ^1.7 (wspiera Laravel 13) |
| `search` | `search-metrics:80/metrics` | nowy kontener, patrz D9 |
| `prometheus`, `alertmanager` | self | |

**Endpointy metryk nie wychodzą na hosta** (ADR, zasada 4): publiczny blok
Caddy (`catalog.localhost`) odpowiada 404 na `/prometheus*`, a trasa jest
w bloku `http://catalog-app` (tylko sieć `marketplace`). Port 2020 nie jest
publikowany. Filtra `allowed_ips` ze spatie nie używamy: adresy w sieci
dockera są zmienne, a ochroną jest routing Caddy.

### D8. Gauge catalog (liczone przy scrape)

Przestrzeń nazw `catalog`. Wszystkie progi czasu liczone w PHP
(`now()->subSeconds(…)`) i porównywane w SQL, żeby te same zapytania
działały w Postgresie i w SQLite (testy). Wieki w sekundach;
pusta kolejka = 0.

| Metryka | Zapytanie |
|---|---|
| `catalog_outbox_pending` | `outbox` z `published_at IS NULL` |
| `catalog_outbox_oldest_pending_age_seconds` | `now - min(occurred_at)` z nieopublikowanych |
| `catalog_offers_pending_moderation` | `offers.pending_requested_at IS NOT NULL` |
| `catalog_offers_pending_moderation_oldest_age_seconds` | `now - min(pending_requested_at)` |
| `catalog_sellers_pending_approval` | `sellers.status = 'pending'` |
| `catalog_sellers_pending_approval_oldest_age_seconds` | `now - min(coalesce(status_changed_at, created_at))` |
| `catalog_orders_stuck_placed` | `orders.status = 'placed' AND placed_at < now - 60 s` |
| `catalog_orders_settled_with_active_reservation` | `offer_reservations.status = 'active'` złączone z `orders` po `reservation_key = 'order-' || id`, `orders.status IN ('paid','shipped')` |
| `catalog_ordering_sweep_last_run_age_seconds` | `now - Cache::get('ordering:sweep:last_run_at')`; **brak próbki**, gdy klucza nie ma |
| `catalog_failed_jobs` | `failed_jobs` |

Złączenie po `reservation_key` łączy dwa konteksty (Ordering i SellerPortal)
na poziomie SQL. To świadomy wyjątek: gauge jest diagnostyką niezmiennika
**między** kontekstami (saga), nie logiką domeny, więc mieszka
w `app/Metrics`, nie w żadnym `src/<Kontekst>`. Klucz składa operator `||`
(`'order-' || orders.id`), który działa tak samo w Postgresie i w SQLite.

Koszt: ~10 prostych zapytań co 15 s, wszystkie po indeksach
(`outbox.published_at`, `orders(status, …)`, `offer_reservations(offer_id, status)`)
lub po małych tabelach. Do sprawdzenia `EXPLAIN` w planie; brakujący
indeks częściowy na `outbox (occurred_at) WHERE published_at IS NULL`
dodajemy, jeśli plan pokaże seq scan.

Błąd zapytania (np. baza leży) nie może wywalić całej odpowiedzi
`/prometheus`: Prometheus straciłby wtedy wszystkie metryki naraz.
Każdy gauge łapie wyjątek, loguje `warning` i nie oddaje próbki;
dostępność bazy pokazuje osobny alert `TargetDown`/HTTP.

### D9. `search-metrics`: failure transport przez Messengera

search nie ma serwera HTTP (tylko konsument). Nowy kontener z obrazu
search (FrankenPHP), `php-server` serwujący `public/`, jedna trasa
`GET /metrics` w kontrolerze `MetricsController`. Wartość
`search_messenger_failed_messages` liczy `MessageCountAwareInterface::getMessageCount()`
transportu `failed` — przez abstrakcję Messengera, nie przez SQL na
`messenger_messages`. Format tekstowy Prometheusa składany ręcznie
(jedna linia `# HELP`, `# TYPE`, wartość) — biblioteka `promphp` wejdzie
w kolejnym PR razem z licznikami i od razu zastąpi ręczny render.

Rozważane i odrzucone: gauge w catalog czytający bazę searchsvc (łamie
granicę własności danych między usługami); `postgres_exporter` z własnym
zapytaniem (`--extend.query-path` jest oznaczone jako przestarzałe);
zmiana failure transportu na kolejkę RabbitMQ (`messenger:failed:show`
nie działa z AMQP).

`mem_limit: 128m`, profil `obs`, brak portu na hoście.

### D10. Reguły alertów

Plik `infra/obs/prometheus/rules/marketplace.yml`, każdy alert z
`labels.severity` i `annotations.summary`, `description`, `runbook`
(link do wpisu w `docs/RUNBOOK.md`).

| Alert | Wyrażenie | `for` | Waga | Uzasadnienie progu |
|---|---|---|---|---|
| `TargetDown` | `up == 0` | 1m | critical | standard; 4 nieudane scrape |
| `OutboxStale` | `catalog_outbox_oldest_pending_age_seconds > 60` | 1m | critical | publisher pyta co 1 s (`--sleep=1`), partia 500; seed 1500 zdarzeń schodzi w kilka s; minuta = publisher martwy albo RabbitMQ blokuje publikację (flow control, `disk_free_limit`) |
| `SearchConsumerDown` | `rabbitmq_detailed_queue_consumers{queue="search.product.sync"} == 0` | 1m | critical | **alert z DoD**; konsument restartuje się co godzinę (`--time-limit=3600`) w kilka sekund, minuta to przeczeka |
| `SearchSyncBacklog` | `rabbitmq_detailed_queue_messages_ready{queue="search.product.sync"} > 1000` | 5m | warning | seed 1500 zdarzeń konsument przerabia w niecałą minutę; 5 min powyżej 1000 = nie nadąża |
| `DeadLetterNotEmpty` | `rabbitmq_detailed_queue_messages{queue=~".+\\.dlq"} > 0` | 0m | warning | DLQ ma nie być pusta tylko przez awarię; każda wiadomość to ręczna decyzja |
| `SearchFailedMessages` | `search_messenger_failed_messages > 0` | 1m | critical | RUNBOOK #050: każda wiadomość w failure transport to zmiana oferty, której nie ma w `/search`; bez alertu indeks stoi po cichu |
| `CatalogHttpErrorRate` | 5xx / wszystkie odpowiedzi Caddy dla hosta `catalog.localhost` > 0.05 **i** ruch > 0.1 req/s | 5m | warning | bez progu ruchu jeden błąd przy pustym stacku daje 100 % |
| `OrdersStuckPlaced` | `catalog_orders_stuck_placed > 0` | 2m | warning | rezerwacja to krok workera (`--sleep=1`), zwykle kilka sekund; zamówienie `placed` ponad 60 s + 2 min = worker stoi albo krok wpada w retry (RUNBOOK #045) |
| `SettledOrderHoldsReservation` | `catalog_orders_settled_with_active_reservation > 0` | 5m | warning | po płatności rezerwacja powinna być potwierdzona przez krok sagi; 5 min przeczeka ponowienia joba; dłużej = towar zablokowany na zawsze (wyciek stanu) |
| `OrderingSweepStale` | `catalog_ordering_sweep_last_run_age_seconds > 180` | 0m | warning | sweep co minutę; 3 opuszczone przebiegi (RUNBOOK #044, #048) |
| `OrderingSweepNeverRan` | `absent(catalog_ordering_sweep_last_run_age_seconds) and on() up{job="catalog"} == 1` | 10m | warning | brak klucza w cache przy żywym catalog; 10 min na pierwszy przebieg po starcie |
| `CatalogFailedJobs` | `catalog_failed_jobs > 0` | 1m | warning | krok sagi w `failed_jobs` czeka na ręczne `queue:retry` (RUNBOOK #046) |
| `OfferModerationOverdue` | `catalog_offers_pending_moderation_oldest_age_seconds > 86400` | 0m | info | SLA biznesowe: zmiana ceny czeka na moderatora dłużej niż dobę |
| `SellerApprovalOverdue` | `catalog_sellers_pending_approval_oldest_age_seconds > 86400` | 0m | info | jw. dla sprzedawcy czekającego na akceptację |

Testy: `infra/obs/prometheus/rules/marketplace.test.yml` (`promtool test rules`)
— dla każdego alertu przypadek „odpala” i „nie odpala tuż pod progiem”.
Uruchamiane obrazem Prometheusa (`docker run --rm --entrypoint promtool`),
target `make obs-check`, który robi też `promtool check config` i
`amtool check-config`.

### D11. Alertmanager → mailpit

Jedna trasa, odbiorca e-mail `alerts@marketplace.local`, SMTP `mailpit:1025`
bez TLS i auth, `send_resolved: true` (DoD: alert się zamyka),
`group_by: [alertname]`, `group_wait: 10s`, `repeat_interval: 4h`.
`info` trafia do tego samego odbiorcy (jeden użytkownik, lokalnie) —
rozdzielenie tras ma sens dopiero na VPS.

### D12. Grafana

Provisioning z repo (`infra/obs/grafana/provisioning`): datasource
Prometheus (domyślny) i Alertmanager; dashboardy jako JSON.
- **Marketplace — przegląd**: lista alertów (panel „Alert list” z Alertmanagera),
  outbox (zaległość i wiek), kolejki RabbitMQ (ready, konsumenci, DLQ),
  HTTP catalog (req/s per kod, p95 z histogramu Caddy), failure transport.
- **Ordering**: zamówienia `placed` > 60 s, rozliczone z aktywną rezerwacją,
  wiek sweepu, `failed_jobs`, oferty i sprzedawcy czekający na decyzję.
Link w nagłówku dashboardów do Kibana Discover (`logs-app-*`) z bieżącym
zakresem czasu (`${__from}`, `${__to}`) — przejście „metryka skoczyła →
które żądania” (ADR, konsekwencje negatywne).
Logowanie: admin z hasłem z `.env` (`GRAFANA_ADMIN_PASSWORD`), bez anonimowego dostępu.

### D13. Kontenery i zasoby

| Kontener | Obraz | `mem_limit` | Port na hoście (127.0.0.1) |
|---|---|---|---|
| `obs-setup` | ten sam co `es-setup` | — (jednorazowy) | — |
| `filebeat` | `docker.elastic.co/beats/filebeat:${ES_VERSION}` | 256m | — |
| `metricbeat` | `docker.elastic.co/beats/metricbeat:${ES_VERSION}` | 256m | — |
| `prometheus` | `prom/prometheus:${PROMETHEUS_VERSION}` | 512m | `PROMETHEUS_PORT=19090` |
| `alertmanager` | `prom/alertmanager:${ALERTMANAGER_VERSION}` | 64m | `ALERTMANAGER_PORT=19093` |
| `grafana` | `grafana/grafana:${GRAFANA_VERSION}` | 256m | `GRAFANA_PORT=13000` (3000 zajęty przez inny projekt) |
| `search-metrics` | obraz search | 128m | — |
| `mailpit` | jak dziś, profile `[tools, obs]`, wersja przypięta | 256m | `MAILPIT_UI_PORT` |

Wersje przypięte w `.env.example` i `docs/07-WERSJE.md` (zasada repo).
Filebeat montuje `/var/lib/docker/containers:ro` i `/var/run/docker.sock:ro`
(Docker Desktop: ścieżki z VM). Suma limitów ~1,7 GB; na maszynie wolne
ok. 3,9 GB z 15,6 GB VM (pomiar 2026-10-17). Realne zużycie mierzymy
i zapisujemy w `POMIARY.md`.

`obs-setup` (idempotentny skrypt bash z curl, `PUT` nadpisuje):
komponenty i szablony, pipeline, polityki ILM, rola i użytkownik
`filebeat_writer` (`create_doc`, `auto_configure` na `logs-app-*`
i `logs-ilmdemo-*`), hasło `remote_monitoring_user`, data view
`logs-app-*` w Kibanie (API Kibany). `filebeat` i `metricbeat` startują
po `service_completed_successfully`.

## Testy

**catalog (Pest, host, SQLite in-memory):**
- `AssignTraceId`: generuje 32 hex; przyjmuje poprawny `traceparent`;
  odrzuca niepoprawny; nagłówek `X-Trace-Id` w odpowiedzi; Context ma wartość.
- Zapis outboxu obiema ścieżkami ma `trace_id` z Context; job kolejki widzi ten sam ślad.
- Publisher: wiadomość AMQP ma nagłówek `trace_id` (test na poziomie budowania wiadomości).
- Każdy gauge: wartość dla danych na granicy progu (59 s vs 61 s itd.), pusta tabela = 0,
  brak klucza sweepu = brak próbki.
- `/prometheus` przez host wewnętrzny zwraca format tekstowy z nazwami metryk;
  awaria jednego gauge nie psuje odpowiedzi.

**search (PHPUnit, host):**
- serializer: `decode` → `TraceIdStamp`; `encode` → nagłówek wraca; brak nagłówka nie psuje dekodowania.
- procesor Monologa dopisuje `trace_id` tylko gdy jest ustawiony.
- `CatalogProjectionClient` wysyła `traceparent`.
- `MetricsController` zwraca poprawny format z liczbą z transportu (transport w pamięci).

**infra:**
- `make obs-check`: `promtool check config`, `promtool test rules`, `amtool check-config`.
- `_simulate` pipeline'u na próbkach (Monolog catalog, Monolog search, access log Caddy, linia nie-JSON).

## Weryfikacja na żywym stacku (DoD)

Kontenery montują główny checkout, nie worktree — weryfikacja aplikacji
po merge do głównego checkoutu albo w jednorazowych kontenerach z kodem
z worktree (sposób z RUNBOOK #027). Infra `obs` montuje pliki z
`infra/obs`, więc ten sam problem: uruchamiamy `docker compose` z
katalogu worktree z `.env` z głównego checkoutu, tylko dla serwisów `obs`.

1. `make up-obs`: wszystkie kontenery `obs` healthy, `obs-setup` kończy się kodem 0.
2. Prometheus → Status → Targets: wszystkie joby `UP`; zapytania zwracają
   każdą metrykę z D7–D9.
3. **Alert celowo:** `docker stop marketplace-search-consumer-1` →
   `SearchConsumerDown` w Prometheusie `firing` po ~1 min → mail w mailpicie;
   `docker start` → mail „RESOLVED”. Czasy zapisane w przewodniku.
4. Drugi alert: zamówienie zostawione z zatrzymanym workerem →
   `OrdersStuckPlaced` (pokazuje sygnał z Orderingu).
5. Zmiana ceny oferty w portalu → `X-Trace-Id` z odpowiedzi → w Kibanie
   ten sam `trace.id` w `catalog-app` (access + aplikacja), `outbox-publisher`,
   `search-consumer` (odbiór + „Indexed”) i wewnętrznym żądaniu projekcji.
6. ILM demo: dokumenty do `logs-ilmdemo-test`, `_ilm/explain` pokazuje
   przejście hot → warm → delete w ~10 min.
7. Kibana → Stack Monitoring pokazuje klaster i węzły.
8. `docker stats` profilu `obs` przed i po → `POMIARY.md`.

## Ryzyka

- **Obrazy beats 9.5.1** — sprawdzone `docker manifest inspect` 2026-10-17: są.
- **Docker Desktop i Filebeat** — odczyt `/var/lib/docker/containers` działa
  w VM, ale uprawnienia do socketu mogą wymagać `user: root`. Akceptujemy
  w dev (kontener tylko czyta).
- **Zmiana formatu logów** w kontenerach catalog (stderr JSON obok pliku)
  zmienia wygląd `docker logs` / `make worker-logs` — tekst `info()` komend
  zostaje, dochodzą linie JSON. Opis w przewodniku.
- **Metryki Caddy z `per_host`** — przy wielu hostach rośnie liczba serii;
  u nas dwa hosty (`catalog.localhost`, `catalog-app`).
- **Pamięć** — jeśli pomiar pokaże brak miejsca, plan B z ADR:
  VictoriaMetrics zamiast Prometheusa.
