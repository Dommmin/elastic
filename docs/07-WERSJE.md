# 07 — Wersje: co przypinamy i dlaczego

**Data weryfikacji: 2026-08-12.** Wszystkie wersje sprawdzone w realnych rejestrach
(nodejs.org/dist, packagist.org, registry.npmjs.org, hub.docker.com, GitHub Releases),
nie z pamięci.

Zasada: **zawsze najnowsza stabilna, nigdy `:latest` w pliku.** To nie jest sprzeczność —
bierzemy najnowszą wersję, ale zapisujemy ją jawnie, żeby build był powtarzalny.
`:latest` oznacza „nie wiem, co dostanę jutro" i uniemożliwia diagnozowanie.

---

## 1. Tabela wersji

### Rdzeń

| Komponent | Wersja | Tag / constraint | Uwagi |
|---|---|---|---|
| **Elasticsearch** | **9.5.1** | `docker.elastic.co/elasticsearch/elasticsearch:9.5.1` | wydane 2026-08-11 |
| **Kibana** | **9.5.1** | `docker.elastic.co/kibana/kibana:9.5.1` | **musi być identyczna z ES** |
| **PHP** | **8.5.9** | w obrazie FrankenPHP | najnowsza linia stabilna |
| **FrankenPHP** | **1.12.7** | `dunglas/frankenphp:1.12.7-php8.5` | zawiera Caddy |
| **Node.js** | **24.19.0 LTS** („Krypton") | `node:24.19.0-alpine` | patrz sekcja 3 |
| **PostgreSQL** | **18.4** | `postgres:18.4-alpine` | |
| **RabbitMQ** | **4.3.4** | `rabbitmq:4.3.4-management-alpine` | |
| **Redis** | **8.8.1** | `redis:8.8.1-alpine` | patrz sekcja 4 |

### PHP — zależności

| Paczka | Wersja | Wymaga PHP | Uwaga |
|---|---|---|---|
| `laravel/framework` | **13.25.0** | `^8.3` | |
| `symfony/framework-bundle` | **8.1.4** | `>=8.4.1` | ← to dyktuje minimum PHP |
| `elasticsearch/elasticsearch` (klient) | **9.5.0** | | dopasować major.minor do serwera |
| `laravel/octane` | **2.19.0** | `^8.1` | driver FrankenPHP |
| `php-amqplib/php-amqplib` | **3.7.4** | `^7.2\|\|^8.0` | publikacja z Laravela |

> **Wniosek o PHP:** Symfony 8.1 wymaga ≥ 8.4.1, Laravel 13 akceptuje ^8.3.
> **PHP 8.5.9 spełnia oba** → jedna wersja PHP dla obu aplikacji, jeden obraz bazowy.
> To upraszcza infrastrukturę i eliminuje klasę problemów „u mnie działa".

### Frontend

| Paczka | Wersja |
|---|---|
| `vue` | **3.5.41** |
| `@inertiajs/vue3` | **3.6.1** |
| `vite` | **8.2.1** |

### Pluginy Elasticsearcha

| Plugin | Wersja |
|---|---|
| `analysis-stempel` (polski stemmer) | **9.5.1** |
| `analysis-icu` | **9.5.1** |

⚠️ **Wersja pluginu musi zgadzać się z wersją ES co do znaku.** `elasticsearch-plugin install`
odmówi instalacji przy niezgodności. To najczęstsza przyczyna nieudanego upgrade'u ES —
i dobrze, że odmawia, bo plugin z inną wersją Lucene uszkodziłby indeksy.

---

## 2. Elasticsearch 9.5.1 — co to znaczy w praktyce

Wydany **2026-08-11**, czyli dzień przed powstaniem tego planu. Linia 9.x jest aktualna;
8.19.x to gałąź podtrzymywana dla tych, którzy nie zmigrowali.

Konsekwencje dla nauki:
- Wszystko z `03-SCIEZKA-NAUKI.md` dotyczy 9.x. **Uwaga na blogi i StackOverflow** —
  ogromna część treści w sieci opisuje 7.x, gdzie były jeszcze `_type` w dokumentach,
  inne domyślne ustawienia bezpieczeństwa i brak ES|QL. Jeśli tutorial używa `_doc/_type`
  albo `xpack.security.enabled=false` jako „normalnego" ustawienia — jest przestarzały.
- Klient PHP przypinamy do **9.5.x**, żeby major.minor zgadzał się z serwerem.
  Klient starszy o major nie będzie znał nowych endpointów; nowszy może wysyłać nagłówki,
  których serwer nie akceptuje.
- **Ćwiczenie w etapie 10:** rolling upgrade 9.5.0 → 9.5.1 na żywym klastrze 3-nodowym.
  Skoro obie wersje istnieją, zrobimy to realnie: wyłączanie alokacji shardów,
  restart node po node, weryfikacja. To jedna z tych operacji, których nikt nie ćwiczy
  przed pierwszym razem na produkcji.

---

## 3. Node: LTS 24, nie „najnowszy" 26

Stan na dziś:
- **Node 26.7.0** — linia *Current*, nie LTS
- **Node 24.19.0 „Krypton"** — **aktualne LTS** ← wybieramy to
- Node 22.23.2 „Jod" — LTS w trybie maintenance

Tu robimy jeden świadomy wyjątek od reguły „najnowsze": **bierzemy najnowsze LTS, a nie
najnowsze w ogóle.** Powód jest ten sam, dla którego robią tak firmy: linia Current dostaje
zmiany łamiące API i kończy wsparcie po pół roku. Ekosystem (Vite, pluginy) testuje
przede wszystkim pod LTS.

Node używamy **wyłącznie do budowania assetów** — nie ma go w obrazie runtime. Więc nawet
gdyby coś było nie tak, wpływ jest ograniczony do build stage'u.

> Linia 26 zostanie LTS w październiku 2026. Jeśli projekt dożyje — podbijemy wtedy
> i będzie to dobre ćwiczenie z aktualizacji zależności.

---

## 4. Redis 8.8 czy Valkey?

Sprawdziłem oba: Redis **8.8.1**, Valkey **9.1.1**.

**Wybieramy Redis 8.8.1**, ale warto znać kontekst, bo to pytanie pada na rozmowach:
Valkey to fork Redisa stworzony pod skrzydłami Linux Foundation po tym, jak Redis zmienił
licencję na niekompatybilną z open source. Wiele chmur i dystrybucji przeszło na Valkey.
Redis później częściowo się z tego wycofał.

Dla nas różnica techniczna jest pomijalna (Valkey pozostaje kompatybilny protokołowo),
a Redis ma bogatsze wsparcie w ekosystemie Laravela. **Zamiana to jedna linia w compose**,
więc jeśli zechcesz porównać — zrobimy to jako 15-minutowe ćwiczenie.

Lekcja szersza: **licencje bywają decyzją architektoniczną.** To samo dotyczy Elasticsearcha
(zmiana licencji w 2021, fork OpenSearch przez AWS) — o tym w module 1.

---

## 5. PostgreSQL 18

Wersja 18.4. Wcześniej w planie miałem 17 — podbijam do 18, zgodnie z Twoją zasadą.

Do sprawdzenia w praktyce (dobre ćwiczenie w etapie 1): Postgres 18 wprowadził
asynchroniczne I/O, co realnie zmienia charakterystykę wydajności przy sekwencyjnym
skanowaniu. Przy backfillu 5 mln ofert (`SELECT ... ORDER BY id` strumieniowo) możemy
to zauważyć. Zmierzymy i zapiszemy w `POMIARY.md`.

---

## 6. Jak to utrzymywać — bo wersje się starzeją

1. **Jedno miejsce prawdy:** wszystkie wersje jako zmienne w `.env` / `compose.yaml`:
   ```
   ES_VERSION=9.5.1
   PHP_VERSION=8.5
   NODE_VERSION=24.19.0
   POSTGRES_VERSION=18.4
   RABBITMQ_VERSION=4.3.4
   REDIS_VERSION=8.8.1
   ```
   Podbicie wersji = zmiana jednej linii, nie polowanie po plikach.
2. **`composer.lock` i `package-lock.json` commitowane.** Zawsze.
3. **Podbijanie wersji to osobny commit**, nigdy przy okazji zmiany funkcjonalnej —
   inaczej nie wiadomo, co zepsuło build.
4. **Kolejność podbijania przy ES:** najpierw klient PHP, potem serwer? Nie — odwrotnie:
   ES obsługuje klienty starsze o jeden major, więc **najpierw serwer, potem klient**.
5. **Przed podbiciem ES sprawdź breaking changes** w oficjalnych release notes.
   Migracje mapowań potrafią być wymagane między majorami.

---

## 7. Skrypt weryfikacyjny

W etapie 0 powstanie `make versions-check`, który odpyta rejestry i pokaże, co się
zdezaktualizowało względem `.env`. Nie po to, żeby aktualizować automatycznie —
po to, żeby wiedzieć. Automatyczna aktualizacja wersji infrastruktury to proszenie się
o niespodziankę w piątek po południu.

---

## 8. Weryfikacja przed startem

Ten dokument opisuje stan z **2026-08-12**. Jeśli zaczynasz później niż kilka tygodni od
tej daty, uruchom `make versions-check` (albo poproś mnie) — Elasticsearch wydaje patche
co 2–3 tygodnie i najprawdopodobniej będzie już 9.5.2+.

Wersje **major/minor** z tego dokumentu powinny być aktualne znacznie dłużej i to one
determinują treść nauki.
