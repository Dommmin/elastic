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
| 6 | Model domenowy + Transactional Outbox + konsument + mapowanie ES | ⏳ napisane i zlintowane, **nieuruchomione** — czeka na `make up-apps` |
| 7+ | patrz [06-PLAN-WDROZENIA](docs/06-PLAN-WDROZENIA.md) | — |

> **Stan ETAPU 6 wymaga wyjaśnienia.** Cały kod (migracje, modele, outbox,
> publikator AMQP, konsument Symfony, mapowanie ES) powstał przy
> **wyłączonym Dockerze** i jest zweryfikowany tak daleko, jak się dało bez
> niego: 51/51 testów Pest (SQLite in-memory), kompilacja kontenera DI
> Symfony, `debug:messenger` potwierdzający routing, `php -l` na każdym
> pliku. Nieprzetestowane pozostaje to, co wymaga żywej infrastruktury:
> realna publikacja do RabbitMQ, konsumpcja, zapis do ES, migracja
> `processed_events` (Postgres-specific SQL). **Pierwsza rzecz po
> `make up-apps`: `php artisan migrate`, `php bin/console
> doctrine:migrations:migrate`, `php bin/console search:index:create`,
> potem end-to-end: dodaj ofertę → sprawdź, czy jest w ES.**

---

## Najczęstsze komendy

```bash
make es-health      # zdrowie klastra, node'y, indeksy
make es-shards      # rozmieszczenie shardów (UNASSIGNED na górze)
make es-explain     # DLACZEGO shard nie jest przypisany
make mq-status      # głębokość kolejek, konsumenci
make psql db=catalog
make logs s=es01
```
