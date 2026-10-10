# Marketplace Search Platform

Projekt do nauki **Elasticsearcha** na poziomie produkcyjnym — od wyszukiwarki,
przez obserwowalność i analitykę, po agregat danych z wielu źródeł.
Przy okazji: RabbitMQ, FrankenPHP/Caddy, CQRS z osobnym read modelem.

```
catalog (Laravel 13 + Inertia/Vue)  ──outbox──►  RabbitMQ  ──►  search (Symfony 8)
        │ źródło prawdy: PostgreSQL                                    │
        └──────────── czyta ────────────►  Elasticsearch  ◄────pisze───┘
```

---

## Start

```bash
cp .env.example .env
make init        # generuje definicje RabbitMQ, buduje obrazy
make up          # start (1 node ES) — najpierw sprawdza, czy sprzęt udźwignie
make smoke       # 25 testów: czy stack FAKTYCZNIE działa
make urls        # adresy i hasła
```

Klaster 3-nodowy (tryb docelowy, wymaga ~20 GB dla Dockera):

```bash
make up-cluster
```

Wszystkie komendy: `make help`

---

## Wymagania

- Docker Desktop z **min. 8 GB** pamięci (1 node ES) lub **20 GB** (klaster 3-nodowy)
- Python 3 (generowanie definicji RabbitMQ)
- `make doctor` sprawdzi, czy środowisko wystarczy — **uruchom go, zanim coś pójdzie nie tak**

---

## Dokumentacja

| Dokument | Zawartość |
|---|---|
| [00-PRZEGLAD](docs/00-PRZEGLAD.md) | po co, co i dlaczego |
| [01-INFRASTRUKTURA](docs/01-INFRASTRUKTURA.md) | kontenery, pamięć, fazy budowy |
| [02-APLIKACJE](docs/02-APLIKACJE.md) | model domenowy, kontrakty zdarzeń, mapowania |
| [03-SCIEZKA-NAUKI](docs/03-SCIEZKA-NAUKI.md) | 15 modułów z ćwiczeniami |
| [04-ES-JAKO-PLATFORMA](docs/04-ES-JAKO-PLATFORMA.md) | **ES to nie tylko wyszukiwarka** |
| [05-SPOJNOSC-DANYCH](docs/05-SPOJNOSC-DANYCH.md) | jak nie rozjechać danych między serwisami |
| [06-PLAN-WDROZENIA](docs/06-PLAN-WDROZENIA.md) | mapa drogowa, 15 etapów, rejestr decyzji |
| [07-WERSJE](docs/07-WERSJE.md) | przypięte wersje i zasady utrzymania |
| [RUNBOOK](docs/RUNBOOK.md) | **przewodnik diagnostyczny — uzupełniaj po każdym błędzie** |
| [POMIARY](docs/POMIARY.md) | tabela pomiarów — Twoje liczby, nie cudze blogi |
| [blog/](docs/blog/) | **przewodniki krok po kroku po każdym etapie** — co, jak, dlaczego i co wybuchło |

---

## Stan realizacji

| Etap | Zakres | Status |
|---|---|---|
| 0 | Repozytorium, compose, Makefile | ✅ |
| 1 | Postgres (2 bazy) + Redis | ✅ |
| 2 | Elasticsearch + Kibana + TLS + RBAC + pluginy PL | ✅ |
| 3 | RabbitMQ: exchange'e, kolejki quorum, DLQ | ✅ |
| 4 | Laravel 13 + Inertia/Vue na FrankenPHP | ✅ |
| 5 | Symfony 8 + Messenger | ✅ |
| 6 | Model domenowy + Transactional Outbox + konsument + mapowanie ES | ✅ zweryfikowane end-to-end na żywym stacku |
| 7 | Wyszukiwarka: `ProductSearchService`, facety, autocomplete, UI Vue/Inertia | ✅ zweryfikowane end-to-end na żywym stacku |
| D | Wdrożenie na VPS: obrazy z GHCR (CI), Compose, tunel SSH, backupy, rollback | ✅ zweryfikowane na serwerze (`make vps-verify` 63/63 + restart) — [przewodnik](docs/blog/etap-d-vps.md) |
| 8+ | patrz [06-PLAN-WDROZENIA](docs/06-PLAN-WDROZENIA.md) | — |

> **ETAP 6 zweryfikowany end-to-end** na klastrze 3-nodowym + aplikacjach:
> `Product::createWithOutbox()` → outbox → `outbox:publish` (potwierdzenia
> brokera) → RabbitMQ → `search-consumer` → `GET /api/internal/.../projection`
> → `ElasticsearchIndexer` (external versioning) → dokument w
> `products-search`. Po drodze znalezione i naprawione 6 błędów, których
> SQLite-w-pamięci nie mogło złapać (wymagały żywej infrastruktury) —
> pełne opisy w [RUNBOOK #013–018](docs/RUNBOOK.md#013), w tym jeden
> poważny (czyszczenie prawdziwej bazy przez źle skonfigurowaną izolację
> testów) i jeden świadomie odłożony (kolizja `sequence` między różnymi
> agregatami piszącymi do tego samego dokumentu — opisana, nie naprawiona).

> **ETAP 7 zweryfikowany end-to-end** — pełny opis krok po kroku, z kodem,
> błędami i ich diagnozą: **[Wyszukiwarka dla ludzi](docs/blog/etap-07-wyszukiwarka.md)**.
> `ProductSearchService` (Query DSL, facety jako filtered aggregations
> w `global`, `nested inner_hits` dla najtańszej oferty, PIT + `search_after`
> odporne na wygaśnięcie PIT) → `SearchController` (leniwe propsy,
> `Inertia::scroll()` z kursorem, `Inertia::defer()` dla histogramu) + JSON API
> → `Search.vue` (partial reloads, `<InfiniteScroll>`, autocomplete z obroną
> przed wyścigiem żądań). 1500 produktów / ~4500 ofert zaseedowanych
> prawdziwym pipeline'em outboxu.
>
> **Dowody, nie deklaracje:** `make search-proof` (slowlog, próg 0 ms)
> pokazuje, że każda akcja na stronie to dokładnie jedno zapytanie do ES —
> klik w facet nie liczy histogramu, przewinięcie nie liczy facetów
> ([POMIARY 5b](docs/POMIARY.md)); `make eval` daje **nDCG@10 = 0.967**
> na 13 zapytaniach kontrolnych ([POMIARY 5a](docs/POMIARY.md)).
> Błędy złapane po drodze: [RUNBOOK #019–025](docs/RUNBOOK.md#019).
>
> Do zrobienia ręcznie: lista kontrolna w przeglądarce (sekcja "Twoja
> kolej" w przewodniku). Świadomie poza zakresem: ~50 zapytań kontrolnych
> zamiast 13, SSR (ETAP 7b) — oba jako zadania domowe w przewodniku.

> **ETAP D zweryfikowany na prawdziwym serwerze** (Ubuntu 24.04, 4 vCPU,
> 24 GB): na serwerze nie ma kodu ani gita — tylko obrazy
> `ghcr.io/dommmin/elastic-*:<SHA>` budowane przez GitHub Actions, 2 pliki
> compose i `.env` z sekretami wygenerowanymi na miejscu. Z internetu otwarty
> wyłącznie port 22; UI przez `make vps-tunnel`. Wdrożenie i rollback:
> `make prod-deploy [tag=<SHA>]`. Backupy ES (SLM) i PG (systemd) z
> przećwiczonym odtwarzaniem; restart serwera: wszystko wstaje samo w ~3 min.
> Plan: [08-PLAN-ETAP-D-VPS](docs/08-PLAN-ETAP-D-VPS.md), błędy:
> [RUNBOOK #028–035](docs/RUNBOOK.md#028).

---

## Najczęstsze komendy

```bash
make es-health      # zdrowie klastra, node'y, indeksy
make es-shards      # rozmieszczenie shardów (UNASSIGNED na górze)
make es-explain     # DLACZEGO shard nie jest przypisany
make mq-status      # głębokość kolejek, konsumenci
make up-apps         # doctor (pamięć, porty) -> cały stack z aplikacjami
make seed n=1500     # dane pod wyszukiwarkę, prawdziwym pipeline'em outboxu
make eval            # nDCG@10 na zapytaniach kontrolnych (ETAP 7)
make search-proof    # ile zapytań do ES kosztuje każda akcja na /search
make psql db=catalog
make logs s=es01
```
