# 00 — Przegląd projektu: „Marketplace Search Platform"

> Cel nadrzędny: **nauczyć się Elasticsearcha na poziomie, na którym potrafisz zaprojektować,
> uruchomić, wytuningować i zdiagnozować wyszukiwarkę produkcyjną** — a przy okazji poznać
> RabbitMQ, FrankenPHP/Caddy i wzorzec CQRS/read-model stosowany w firmach enterprise.

---

## 1. Dlaczego w ogóle Elasticsearch? (najważniejszy rozdział)

Zanim napiszemy linijkę kodu, musisz umieć odpowiedzieć na pytanie rekrutera/architekta:
**„po co wam ES, skoro macie Postgresa?"**. Jeśli nie umiesz — użyjesz ES źle.

### Co ES robi lepiej niż baza relacyjna

| Problem | Postgres | Elasticsearch |
|---|---|---|
| `WHERE name LIKE '%buty%'` na 5 mln rekordów | full scan, brak indeksu | odwrócony indeks, ~ms |
| „buty do biegania" ma znaleźć „but biegowy" | trzeba pisać własny stemming | analizatory + stemmer + synonimy |
| Ranking trafności (najlepszy wynik na górze) | brak (albo `ts_rank`, prymitywne) | BM25 + boosty + function_score |
| Faceting („Nike (23), Adidas (11)") razem z wynikami | osobne `GROUP BY`, N zapytań | jedno zapytanie + agregacje |
| Literówka „addidas" | brak | fuzziness / suggester |
| Autouzupełnianie od 2. znaku | drogie | edge_ngram / completion suggester |
| Agregacje po 100 mln logów w kilka sekund | ciężko | doc_values + agregacje kolumnowe |
| Wyszukiwanie semantyczne („coś na deszcz") | brak | dense_vector / kNN / ELSER |

### Czego ES **nie** robi i gdzie ludzie robią błąd

To jest wiedza, która odróżnia seniora od juniora:

1. **ES nie jest bazą źródłową (source of truth).** Nie ma transakcji ACID między dokumentami,
   nie ma joinów, nie ma constraintów, nie ma FK. Jeśli stracisz ES — musisz umieć odbudować
   indeks z Postgresa. Nasz projekt będzie to wymuszał: pełny reindex z bazy w każdej chwili.
2. **ES jest near-real-time, nie real-time.** Domyślnie dokument jest widoczny w wyszukiwaniu
   po ~1 s (`refresh_interval`). To nie bug — to fundament wydajności. Zrozumienie
   refresh/flush/merge to punkt 1 diagnostyki.
3. **ES jest eventually consistent względem Twojej bazy.** Zapisujesz do Postgresa, event leci
   do RabbitMQ, konsument indeksuje. Między tymi krokami dane się różnią. Trzeba to zaprojektować,
   nie „naprawić".
4. **Brak joinów.** Dokumenty muszą być zdenormalizowane. To zmienia całe modelowanie danych i
   jest najczęstszym źródłem katastrof („zrobimy `nested` na 10 tys. elementów").
5. **ES nie skaluje się magicznie.** Zła liczba shardów = albo over-sharding (klaster pada od
   metadanych), albo za duże shardy (nie da się rebalansować).

### Kiedy firmy enterprise sięgają po ES — realne zastosowania

| Zastosowanie | Przykład | Co z tego zrobimy w projekcie |
|---|---|---|
| Product search / site search | Allegro, Zalando, Booking | ✅ rdzeń projektu |
| Log & observability (ELK) | każda większa firma | ✅ moduł: logi obu apek → ES |
| Analytics / BI na zdarzeniach | dashboardy biznesowe | ✅ zdarzenia użytkownika + Kibana |
| Security / SIEM | Elastic Security | ⏩ tylko omówimy |
| Alerting / saved searches | „powiadom, gdy pojawi się X" | ✅ percolator |
| Vector / RAG / semantic search | AI-assistants | ✅ moduł hybrydowy (kNN + BM25 + RRF) |

---

## 2. Problem biznesowy, który rozwiązujemy

**Domena: marketplace z ofertami sprzedawców** (jak Allegro/Amazon Marketplace w miniaturze).

Dlaczego akurat to? Bo ta domena naturalnie wymusza **wszystkie** ważne mechanizmy ES —
nie musimy niczego sztucznie doklejać:

| Wymaganie biznesowe | Mechanizm ES, którego się przez nie nauczysz |
|---|---|
| Szukanie po nazwie, opisie, marce, kategorii | `multi_match`, `best_fields` vs `cross_fields`, analizatory |
| Polskie odmiany („buty" ↔ „butów") | analyzer Stempel, ASCII folding, normalizery |
| Synonimy („laptop" = „notebook") | `synonym_graph`, Synonyms API, reload bez reindeksu |
| Filtry boczne z licznikami | agregacje `terms`, `range`, `post_filter`, `global` |
| Sortowanie po cenie / trafności / dacie | `sort`, `_score`, `track_total_hits` |
| Jeden produkt = wiele ofert; pokazać najtańszą | `collapse` + `inner_hits`, albo `nested` |
| „Sklepy w promieniu 10 km" | `geo_point`, `geo_distance`, agregacje geo |
| Nowości wyżej, ale nie kosztem trafności | `function_score`, `distance_feature` |
| Popularne produkty wyżej | `rank_feature` zasilany z analityki kliknięć |
| Autouzupełnianie w search barze | `search_as_you_type`, completion suggester |
| „Czy chodziło Ci o…" | term/phrase suggester |
| Nieskończone przewijanie | `search_after` + PIT |
| „Powiadom mnie, gdy pojawi się iPhone < 3000 zł" | **percolator** |
| Historia wyszukiwań i kliknięć, dashboardy | data streams + ILM + Kibana |
| Zmiana mapowania bez downtime | aliasy + `_reindex` + dual-write |
| Wyszukiwanie znaczeniowe („prezent dla biegacza") | `dense_vector`, kNN, hybryda + RRF |

**Historyjka użytkownika, którą finalnie zobaczysz działającą:**

> Sprzedawca dodaje ofertę w panelu (Laravel → Postgres). W ciągu sekundy oferta jest
> wyszukiwalna w wyszukiwarce, ma poprawnie policzone facety, trafia do najtańszej oferty
> danego produktu, a użytkownik, który miał zapisany alert „iPhone 15 poniżej 3000 zł",
> dostaje powiadomienie — wszystko przez kolejkę, bez ani jednego zapytania synchronicznego
> między serwisami.

---

## 3. Architektura w jednym obrazku

```
                      ┌──────────────────────────────────────────┐
   przeglądarka  ───► │  catalog-app  (Laravel 13 + FrankenPHP)   │
                      │  • panel sprzedawcy (CRUD ofert)          │
                      │  • REST API wyszukiwarki (czyta z ES)     │
                      │  • source of truth: PostgreSQL            │
                      │  • outbox → publikacja eventów            │
                      └───────┬──────────────────────┬────────────┘
                              │ AMQP                 │ HTTP (read)
                              ▼                      ▼
                      ┌───────────────┐        ┌───────────────────┐
                      │   RabbitMQ    │        │  Elasticsearch    │
                      │ topic exchange│        │  (1 lub 3 node'y) │
                      │ + DLX + retry │        │  + Kibana         │
                      └───────┬───────┘        └─────▲──────┬──────┘
                              │ AMQP                 │      │
                              ▼                      │ bulk │ percolate
                      ┌──────────────────────────────┴──────┴──────┐
                      │  search-service  (Symfony 8 + Messenger)   │
                      │  • konsumenci eventów → dokumenty ES       │
                      │  • zarządzanie mapowaniami i reindeksem    │
                      │  • alerty (percolator) → event zwrotny     │
                      │  • własna baza: stan indeksacji            │
                      └────────────────────────────────────────────┘
```

**Kluczowa myśl architektoniczna (to jest właśnie „jak robią to enterprise"):**
to jest **CQRS z osobnym read modelem**. Strona zapisu (Laravel/Postgres) nic nie wie
o Elasticsearchu poza tym, że z niego czyta. Budowaniem read modelu zajmuje się osobny
serwis, asynchronicznie. Dzięki temu:

- reindeks nie obciąża aplikacji użytkownika,
- awaria ES nie blokuje sprzedaży (degradacja, nie awaria),
- zmiana schematu indeksu to deploy jednego małego serwisu,
- można przepiąć wyszukiwarkę na inny silnik bez dotykania monolitu.

---

## 4. Dlaczego dwa frameworki, a nie jeden

Celowo: **Laravel 13** dla części „produktowej" (szybki CRUD, Eloquent, panel) i
**Symfony 8** dla części „infrastrukturalnej" (Messenger to najlepszy w PHP abstrakcja
nad kolejkami: retry strategies, DLQ, middleware, `#[AsMessageHandler]`, konsumenci jako
long-running procesy). To realistyczne — w dużych firmach różne zespoły mają różne stacki,
a kontraktem jest **schemat wiadomości**, nie wspólny kod.

Uczysz się przy okazji rzeczy, której nie nauczy Cię jeden framework:
**wersjonowania kontraktu zdarzeń między serwisami**.

---

## 5. Co dostaniesz na końcu (definition of done całego kursu)

- [ ] `docker compose up` → działający lokalnie stack: 2 aplikacje, RabbitMQ, Postgres, ES + Kibana
- [ ] Klaster ES 3-nodowy jako domyślne środowisko pracy (green, z replikami), z rozumieniem
      shardów, replik, alokacji i rebalansu
- [ ] Realny wolumen: 5 mln produktów / ~15 mln ofert — decyzje projektowe podejmowane
      na podstawie zmierzonych liczb, nie intuicji
- [ ] Wyszukiwarka z facetami, autouzupełnianiem, literówkami, polskim stemmingiem
- [ ] Pełny reindeks z zerowym downtime (aliasy) uruchamiany jedną komendą
- [ ] Odporny pipeline eventowy: outbox, idempotencja, DLQ, retry z backoffem
- [ ] Dashboardy w Kibanie z realnych zdarzeń użytkownika + ILM
- [ ] Alerty percolatorowe
- [ ] Wyszukiwanie hybrydowe (BM25 + wektory + RRF)
- [ ] **Runbook diagnostyczny** — własny dokument „co robić, gdy klaster jest żółty/czerwony,
      gdy zapytania są wolne, gdy indeksacja się zapycha" — pisany przez Ciebie w trakcie nauki
- [ ] Świadome odpowiedzi na pytania rekrutacyjne z sekcji `03-SCIEZKA-NAUKI.md`

---

## 6. Mapa dokumentów

| Plik | Zawartość |
|---|---|
| `00-PRZEGLAD.md` | ten dokument — po co, co, dlaczego |
| `01-INFRASTRUKTURA.md` | wszystkie kontenery, sieci, wolumeny, TLS, pamięć, Makefile, kolejność budowy |
| `02-APLIKACJE.md` | model domenowy, kontrakty zdarzeń, endpointy, mapowania ES, przepływy |
| `03-SCIEZKA-NAUKI.md` | 15 modułów nauki ES + RabbitMQ, krok po kroku, z ćwiczeniami i diagnostyką |
| `04-ES-JAKO-PLATFORMA.md` | **czym ES jest poza wyszukiwarką** — 5 archetypów zastosowań, kiedy go NIE używać, i 3 dodatkowe tory projektu (observability, analityka, agregat 360) |
| `05-SPOJNOSC-DANYCH.md` | **jak nie rozjechać danych między aplikacjami** — 5 trybów awarii i obrona przed każdym, outbox, idempotencja, wersjonowanie, reconciliation, testy chaosu |
| `06-PLAN-WDROZENIA.md` | **mapa drogowa** — rejestr decyzji, 15 etapów z definition of done, szacunek czasu, granice zakresu |
| `07-WERSJE.md` | przypięte wersje wszystkich komponentów, zweryfikowane w rejestrach 2026-08-12, z uzasadnieniem i zasadami utrzymania |

> Kolejność czytania: `00` → `04` (po co to komu) → `06` (co robimy i kiedy) →
> reszta w miarę potrzeb. Jeśli masz przeczytać tylko jeden dokument poza tym —
> przeczytaj `04`. Odpowiada na pytanie „po co firmy naprawdę używają Elasticsearcha",
> które jest ważniejsze niż składnia Query DSL.
>
> `05` przeczytaj przed pisaniem pierwszej linijki kodu komunikacji między serwisami.
