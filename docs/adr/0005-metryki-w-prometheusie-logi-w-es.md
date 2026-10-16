# ADR-0005: Metryki w Prometheusie, logi i ślady w Elasticsearchu

- **Status:** przyjęta (wdrożenie w ETAPIE 11)
- **Data:** 2026-10-16
- **Kontekst decyzji:** tor observability ([TOR 2 w `04-ES-JAKO-PLATFORMA.md`](../04-ES-JAKO-PLATFORMA.md), [ETAP 11 w `06-PLAN-WDROZENIA.md`](../06-PLAN-WDROZENIA.md), profil `obs` w [`01-INFRASTRUKTURA.md`](../01-INFRASTRUKTURA.md))

## Kontekst

Plan ETAPU 11 zakładał, że **wszystko** idzie do ES: logi przez Filebeat,
metryki przez Metricbeat. Ten sam projekt w tabeli „kiedy ES to zły wybór”
(`04`, sekcja 3) mówi jednak, że do metryk systemowych z alertami lepszy jest
Prometheus lub VictoriaMetrics. Dokumenty sobie przeczyły.

Fakty:
- Najważniejsze sygnały tego systemu to liczby w czasie, nie zdarzenia:
  zaległość outboxu i wiek najstarszego wpisu, głębokość kolejek i DLQ,
  opóźnienie synchronizacji z ES, błędy pozycji w bulku, p95 latencji.
  Do tego potrzebne są alerty, a nie przeglądanie dokumentów.
- RabbitMQ ma już włączony plugin `rabbitmq_prometheus` (port 15692).
- FrankenPHP (Caddy) wystawia metryki Prometheusa bez żadnego kodu w aplikacji.
- W stacku jest Redis, a w profilu `tools` mailpit.
- PHP nie współdzieli pamięci między żądaniami ani między workerami Octane
  i Messengera. Licznik trzymany w pamięci procesu nie ma sensu.
- Maszyna lokalna jest współdzielona z innymi projektami, a VPS jest mały.
  Każdy kontener musi mieć `mem_limit`.
- Celem toru jest nauka ES jako platformy observability (data streams, ILM,
  ECS, korelacja `trace_id`). Tego nie oddajemy.

## Decyzja

**Podział odpowiedzialności:**

| Sygnał | Gdzie | Narzędzie do oglądania |
|---|---|---|
| Logi aplikacji (ECS, `trace_id`) | ES, data streams `logs-app-*` + ILM | Kibana Discover |
| Ślady (APM, opcjonalnie) | ES | Kibana APM |
| Metryki samego klastra ES | ES, przez Metricbeat | Kibana Stack Monitoring |
| Pozostałe metryki i **wszystkie alerty** | Prometheus | Grafana, Alertmanager → mailpit |

**Źródła metryk w Prometheusie:**

| Źródło | Sposób | Najważniejsze metryki |
|---|---|---|
| RabbitMQ | plugin `rabbitmq_prometheus` (już włączony) | głębokość kolejek, DLQ, konsumenci, ack rate |
| Caddy / FrankenPHP | wbudowany endpoint metryk | latencja i kody odpowiedzi HTTP obu aplikacji |
| Postgres | `postgres_exporter` | połączenia, transakcje, rozmiar tabel |
| Elasticsearch | `elasticsearch_exporter` | stan klastra, odrzucenia w thread poolach, heap |
| Host (VPS) | `node_exporter` | CPU, RAM, dysk |
| Laravel (`catalog`) | `spatie/laravel-prometheus` | gauge liczone przy scrape: zaległość outboxu, wiek najstarszego wpisu, oferty czekające na moderację, sprzedawcy czekający na akceptację |
| Symfony (`search`) | `promphp/prometheus_client_php` z magazynem w Redisie | liczniki zaindeksowanych dokumentów i błędów pozycji bulka (label: klasa błędu), histogram czasu bulka, gauge opóźnienia synchronizacji |

**Zasady:**
1. **Spatie tylko do gauge liczonych przy scrape.** Pakiet odpowiada na pytanie
   „ile jest teraz”, licząc wartość zapytaniem w chwili scrape. Nie zliczamy
   nim zdarzeń w trakcie żądań. Latencję HTTP bierzemy z Caddy, nie z aplikacji.
2. **W Symfony `promphp` bezpośrednio, bez bundla.** Metryki produkują
   konsumenci Messengera, nie żądania HTTP. Listenery żądań z
   `artprima/prometheus-metrics-bundle` nic by tu nie dały, a bezpośrednie
   użycie biblioteki pokazuje, jak działa magazyn metryk w Redisie.
3. **Żadnych identyfikatorów w labelach** (ID oferty, sprzedawcy, produktu,
   fraza wyszukiwania). Labele mają mieć skończony, mały zbiór wartości.
   Szczegóły per obiekt są w logach w ES, po `trace_id`.
4. **Endpointy `/metrics` nie wychodzą na zewnątrz.** Są dostępne tylko w sieci
   `marketplace` (Compose) lub przez Service typu ClusterIP (k3s), a Caddy
   ich nie publikuje.
5. **Alerty definiujemy tylko w Prometheusie.** Kibana nie ma reguł alertów
   na tych samych sygnałach.
6. **Wszystko w profilu `obs`**, z `mem_limit` na każdym kontenerze.
   Retencja Prometheusa: 15 dni lokalnie, 7 dni na VPS.
7. **Na k3s bez `kube-prometheus-stack`.** Wystarczy zwykły Deployment
   Prometheusa z konfiguracją `scrape_configs`. Operator i jego CRD to za duży
   narzut na jeden węzeł.

## Rozważane opcje

| Opcja | Za | Przeciw |
|---|---|---|
| **A. Prometheus na metryki, ES na logi (wybrana)** | każde narzędzie robi to, do czego jest zbudowane; PromQL i Alertmanager to standard rynkowy; część eksporterów już działa | dwa systemy do utrzymania i dwa UI (Grafana i Kibana) |
| B. Wszystko w ES (Metricbeat, pierwotny plan) | jeden system, jedno UI, więcej praktyki z ES | metryki w ES są droższe w miejscu i pamięci niż w TSDB; na licencji basic Kibana nie wyśle maila z alertu (konektory poza logiem serwera i indeksem są płatne); uczyłoby to wzorca, który dokument `04` sam odradza |
| C. VictoriaMetrics zamiast Prometheusa | mniej RAM i dysku, zgodne API i PromQL | mniej popularne; do nauki wybieramy standard. Zostaje jako plan B na VPS, jeśli pomiar pokaże brak pamięci |
| D. Grafana Cloud / SaaS | zero utrzymania | dane poza naszą infrastrukturą, nic nie uczy o samym narzędziu |

## Konsekwencje

**Pozytywne**
- Metryki spójności z [`05-SPOJNOSC-DANYCH.md`](../05-SPOJNOSC-DANYCH.md)
  (zaległość outboxu, lag konsumentów, DLQ) dostają alerty z prawdziwego
  zdarzenia, a nie tylko dashboard.
- Dochodzi realne ćwiczenie porównawcze: Metricbeat ma moduł `prometheus`,
  który potrafi zescrapować te same endpointy i wysłać dane do ES. Na tych
  samych danych mierzymy rozmiar i czas zapytań w ES i w Prometheusie
  (wynik do [POMIARY](../POMIARY.md)).
- Endpointy `/metrics` aplikacji nie zależą od ES. Gdy klaster leży,
  nadal widać, co dzieje się z kolejkami i outboxem.

**Negatywne**
- Dwa UI. Korelacja „metryka skoczyła → które żądania” wymaga przejścia
  z Grafany do Kibany (link z dashboardu z zakresem czasu).
- Szacunkowo +400–600 MB RAM w profilu `obs` (Prometheus, Grafana,
  Alertmanager, dwa eksportery). Do zmierzenia w ETAPIE 11 na
  współdzielonej maszynie i na VPS.
- Symfony dostaje nową zależność od Redisa (magazyn metryk). Gdy Redis
  leży, metryki znikają, ale indeksowanie musi działać dalej: zapis metryki
  nie może rzucać wyjątku do handlera.
