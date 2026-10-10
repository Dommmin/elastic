# Wdrożenie na VPS: od „działa u mnie" do obrazu w rejestrze i prywatnego serwera

*ETAP D projektu Marketplace Search Platform. Cały stack (klaster
Elasticsearch, Kibana, Postgres, Redis, RabbitMQ, Laravel, Symfony) na
własnym serwerze, dostępny tylko dla Ciebie. Bez `git pull` na serwerze:
kod jedzie w obrazach przez rejestr, a serwer tylko je uruchamia. Razem
z każdym błędem, który wyszedł po drodze, i z tym, jak go znaleźć.*

> **Stan przewodnika:** Faza 0 (wszystko, co da się zrobić przed zakupem
> serwera) — gotowa i sprawdzona. Faza 1 (serwer) — dopisywana w trakcie
> wdrożenia. Plan: [`docs/08-PLAN-ETAP-D-VPS.md`](../08-PLAN-ETAP-D-VPS.md).

---

## Spis treści

1. [Kod czy obraz — dlaczego serwer nie robi `git pull`](#1-kod-czy-obraz--dlaczego-serwer-nie-robi-git-pull)
2. [Mapa: co gdzie leży](#2-mapa-co-gdzie-leży)
3. [Krok 1 — Obraz, który da się wypchnąć do publicznego rejestru](#krok-1--obraz-który-da-się-wypchnąć-do-publicznego-rejestru)
4. [Krok 2 — Nakładka `compose.prod.yaml`](#krok-2--nakładka-composeprodyaml)
5. [Krok 3 — Sekrety, które powstają tylko na serwerze](#krok-3--sekrety-które-powstają-tylko-na-serwerze)
6. [Krok 4 — Próba generalna bez serwera](#krok-4--próba-generalna-bez-serwera)
7. [Krok 5 — CI: GitHub Actions buduje, testuje, publikuje](#krok-5--ci-github-actions-buduje-testuje-publikuje)
8. [Krok 6 — Klucz SSH i weryfikacja z Maca](#krok-6--klucz-ssh-i-weryfikacja-z-maca)
9. [Czego nie widać w testach: trzy odkrycia](#czego-nie-widać-w-testach-trzy-odkrycia)
10. [Faza 1 — serwer](#faza-1--serwer) *(w trakcie)*

---

## 1. Kod czy obraz — dlaczego serwer nie robi `git pull`

Najprostszy pomysł na wdrożenie wygląda tak: na serwerze `git clone`,
potem `docker compose up --build`. Działa. I jest dokładnie tym, czego
kontenery miały nas oduczyć.

```
ŹLE:   git push ──► serwer: git pull → composer install → npm build → up
DOBRZE: git push ──► CI: build obrazu → test → rejestr ──► serwer: pull → up
```

Dlaczego to ma znaczenie:

| | `git pull` + build na serwerze | Obraz z rejestru |
|---|---|---|
| Co działa na produkcji | to, co akurat zbudowało się na serwerze | **dokładnie** ten obraz, który przeszedł testy |
| Rollback | `git checkout` + przebudowa (minuty, może się nie udać) | uruchom poprzedni tag (sekundy) |
| Serwer potrzebuje | git, composer, node, dostęp do repo, CPU na build | tylko Dockera |
| Ten sam obraz na 2 serwerach | nie — dwa buildy, dwa wyniki | tak — ten sam skrót (digest) |

Docker od początku (2013) zakłada model „zbuduj raz, uruchamiaj wszędzie".
Kubernetes (2014–2015) w ogóle nie umie budować — węzeł klastra dostaje
`image: rejestr/nazwa:tag` i go pobiera. Git pojawia się w Kubernetesie
dopiero w **GitOps** (Argo CD, Flux, od ~2017): agent w klastrze pobiera
z gita *manifesty* (opis, co ma działać), ale obrazy dalej idą z rejestru.

U nas:

```
git push na main
   │
   ▼
GitHub Actions: build 5 obrazów (linux/amd64)
   │  → prod-image-check (bramka: zero sekretów, konfiguracja w runtime)
   │  → push: ghcr.io/dommmin/elastic-{elasticsearch,postgres,rabbitmq,catalog,search}:<SHA>
   ▼
Mac: make prod-deploy tag=<SHA>
   │  scp: compose.yaml, compose.prod.yaml (+ szablon .env przy pierwszym razie)
   ▼
VPS /opt/marketplace: docker compose pull → up -d --wait → migracje
```

Na serwerze **nie ma kodu ani gita**. To jest sprawdzane, nie deklarowane
(`verify.sh stack`, Task 8).

---

## 2. Mapa: co gdzie leży

| Plik | Rola |
|---|---|
| `.dockerignore` | co NIE trafia do obrazu (sekrety, `vendor/`, `node_modules/`) |
| `infra/elasticsearch/Dockerfile` | ES + pluginy + `analysis/` + skrypty init (wbudowane) |
| `infra/postgres/Dockerfile` | Postgres + skrypt tworzący bazy |
| `infra/rabbitmq/Dockerfile`, `entrypoint.sh` | topologia w obrazie, hash hasła liczony przy starcie |
| `infra/php/catalog.Dockerfile`, `catalog-entrypoint.sh` | Laravel: `config:cache` przy starcie |
| `infra/php/search.Dockerfile`, `search.build.env` | Symfony: atrapy env tylko na czas `cache:warmup` |
| `compose.prod.yaml` | nakładka: obrazy z GHCR, zero bind-mountów |
| `.env.prod.example`, `tools/vps/gen-env.sh` | szablon i generator sekretów |
| `.github/workflows/images.yml` | build → test → push |
| `tools/vps/prod-image-check.sh` | 22 sprawdzenia obrazów (lokalnie i w CI) |
| `tools/vps/compose-prod-check.sh` | 14 sprawdzeń nakładki |
| `tools/vps/verify.sh` | weryfikacja serwera z Maca, faza po fazie |
| `tools/vps/bootstrap.sh`, `install-docker.sh`, `deploy.sh` | skrypty serwerowe |

---

## Krok 1 — Obraz, który da się wypchnąć do publicznego rejestru

Repo jest publiczne, więc obrazy w GHCR też będą publiczne (serwer
pobierze je bez tokenu). To podnosi poprzeczkę: **każda warstwa obrazu
jest do wyciągnięcia przez każdego**. `RUN rm .env` w późniejszej warstwie
nie pomaga — plik dalej leży w warstwie wcześniejszej.

Zaczęliśmy od testu, nie od poprawek: `tools/vps/prod-image-check.sh`
klonuje repo do katalogu tymczasowego (czyli widzi to samo co CI — bez
`.env`, `vendor/` i innych ignorowanych plików), buduje 5 obrazów i sprawdza
22 rzeczy. Pierwszy przebieg: **PASS=1 FAIL=21**, a dwa obrazy w ogóle się
nie zbudowały. Oto co wyszło, w kolejności, w jakiej to naprawialiśmy.

### 1.1. Brak `.dockerignore` — sekrety w obrazie

`COPY apps/catalog/ ./` kopiuje *wszystko* z katalogu, łącznie z lokalnym
`.env` (hasła), `vendor/` i `node_modules/` zbudowanymi pod macOS.
`.gitignore` nie ma tu nic do rzeczy — Docker go nie czyta.

```dockerignore
**/.env
**/.env.local
**/vendor
**/node_modules
apps/*/var
infra/rabbitmq/definitions.json
.git
```

Sprawdź (po buildzie):

```bash
docker run --rm --entrypoint sh <obraz> -c 'test -e /app/.env && echo JEST || echo brak'
```

### 1.2. `npm run build` bez PHP

Etap `assets` budował frontend w czystym `node:alpine`. Lokalnie nigdy
tego nie robiliśmy — Vite chodził w kontenerze z PHP. W czystym klonie:

```
RUN npm run build  →  exit code: 1
```

Przyczyna: plugin `@laravel/vite-plugin-wayfinder` przy *buildzie* (nie
tylko przy `npm run dev`) woła `php artisan wayfinder:generate`, żeby
wygenerować typowane trasy w TS. Potrzebuje PHP, `vendor/` i kodu
aplikacji. Naprawa — kolejność etapów:

```
base ──► vendor  (composer install --no-dev + kod + package:discover)
  │         │
  └► vite ──┴─► assets  (vite = base + Node; COPY --from=vendor; npm run build)
                  │
base ──► prod ◄───┘  (vendor + public/build)
```

Ciekawostka po drodze: `composer install --no-scripts` wyłącza też
`package:discover`, więc Laravel nie widzi providerów z paczek — w tym
komendy `wayfinder:generate`. Trzeba ją zawołać ręcznie.

### 1.3. `config:cache` w buildzie — cicha pułapka

To najgroźniejszy z błędów, bo **nic by nie krzyczało**:

```dockerfile
# było w Dockerfile (etap prod):
RUN php artisan config:cache
```

`config:cache` zapisuje wynik wszystkich `env()` do
`bootstrap/cache/config.php`. W czasie buildu nie ma zmiennych z compose,
więc zapisują się wartości domyślne — m.in. `DB_CONNECTION=sqlite`. A gdy
cache istnieje, Laravel **w ogóle nie czyta** `env()`. Zmienne z compose
byłyby po cichu ignorowane, aplikacja pisałaby do pliku SQLite w kontenerze
— i znikałaby z każdym wdrożeniem.

Naprawa: cache konfiguracji powstaje przy **starcie** kontenera
(`infra/php/catalog-entrypoint.sh`), gdy zmienne już są. `route:cache`
i `view:cache` mogą zostać w buildzie — nie zależą od środowiska.

Test, który to pilnuje:

```bash
docker run --rm -e DB_CONNECTION=pgsql -e APP_KEY=... <catalog> \
  sh -c 'php artisan config:cache && php -r "echo (require \"bootstrap/cache/config.php\")[\"database\"][\"default\"];"'
# oczekiwane: pgsql
```

### 1.4. Symfony: `cache:warmup` bez zmiennych

Symfony zachowuje się odwrotnie niż Laravel: `%env(DATABASE_URL)%` jest
rozwiązywane **w runtime**, nie zamrażane. Ale kompilacja kontenera DI
(`cache:warmup`) sprawdza, czy zmienne *istnieją*:

```
Environment variable not found: "DATABASE_URL".
Environment variable not found: "DEFAULT_URI".
```

Naprawa bez śmiecenia w obrazie — plik z atrapami montowany tylko na czas
jednej komendy:

```dockerfile
RUN --mount=type=bind,source=infra/php/search.build.env,target=/tmp/build.env \
    touch .env \
 && (set -a && . /tmp/build.env && set +a && php bin/console cache:warmup)
```

`ENV DATABASE_URL=...` byłoby błędem: zostałoby w obrazie na zawsze.
`touch .env` jest potrzebne, bo `Dotenv::bootEnv()` rzuca wyjątkiem, gdy
pliku nie ma (a `apps/search/.env` jest w `.gitignore`).

### 1.5. Konfiguracja z bind-mountów → do obrazów

Lokalnie compose montuje 13 plików z repo (`./infra/...`, `./tests/relevance`).
Na serwerze repo nie ma. Każdy z tych plików trafił do obrazu **pod tą samą
ścieżką**, pod którą jest montowany — dzięki temu lokalnie bind-mount
przykrywa zawartość obrazu (edycja synonimów działa bez rebuildu), a na
serwerze używana jest wersja z obrazu. Zero warunków, zero `if prod`.

### 1.6. RabbitMQ: hash hasła nie może być w obrazie

`definitions.json` (topologia + użytkownik) zawiera **hash hasła**. Hash to
też sekret — słabe hasło da się z niego odzyskać offline. W obrazie jest
więc tylko *szablon*, a `infra/rabbitmq/entrypoint.sh` liczy hash przy
każdym starcie z `RABBITMQ_USER`/`RABBITMQ_PASSWORD`, tym samym algorytmem
co `tools/render-rabbitmq-definitions.py`:

```sh
head -c 4 /dev/urandom > salt
{ cat salt; printf '%s' "$RABBITMQ_PASSWORD"; } | openssl dgst -sha256 -binary > digest
HASH=$(cat salt digest | base64)          # base64( sól + sha256(sól + hasło) )
```

Po drodze: test RabbitMQ najpierw padał, choć broker działał. Pętla
`rabbitmq-diagnostics check_running` uruchamiana *w trakcie bootu* node'a
kładła kontener. Czekamy więc na linię `Server startup complete` w logach.

### 1.7. Zależności deweloperskie w kodzie produkcyjnym

Obraz prod instaluje `composer install --no-dev`. Dwie komendy, których
będziemy używać na serwerze, korzystały z paczek tylko z `require-dev`:
`marketplace:seed` (Faker) i `search:eval` (`symfony/yaml`). Pierwszą
złapał test, drugą — dopiero próba generalna (Krok 4). Potem przeskanowaliśmy
kod pod kątem wszystkich przestrzeni nazw z `require-dev` — więcej nie ma.

Wynik po wszystkich poprawkach: **PASS=22 FAIL=0**, a skan obrazów pod kątem
wartości haseł z lokalnego `.env`: 0 trafień w każdym z 5 obrazów.

---

## Krok 2 — Nakładka `compose.prod.yaml`

Nie piszemy drugiego `compose.yaml`. Dwa pliki o tej samej treści
rozjeżdżają się po tygodniu. Zamiast tego **nakładka**: compose scala
`compose.yaml` z `compose.prod.yaml`, a nakładka zmienia tylko to, co
w prod ma być inne.

```bash
docker compose -f compose.yaml -f compose.prod.yaml --profile cluster --profile apps config
```

`config` pokazuje wynik scalenia, czyli to, co compose naprawdę uruchomi.
Na nim, a nie na plikach, działa test `tools/vps/compose-prod-check.sh`.

### Pułapka: scalanie list

Pierwsza wersja nakładki miała:

```yaml
catalog-vite:
  profiles: [dev-only]     # "niech Vite nigdy nie startuje na serwerze"
```

Test pokazał, że Vite **dalej startuje**. Listy w nakładce się *scalają*:
`[apps]` + `[dev-only]` = `[apps, dev-only]`, więc profil `apps` nadal go
włącza. To samo dotyczy `volumes`: dopisanie listy dokłada pozycje, a nie
zastępuje bind-mountów. Na to są tagi (Compose ≥ 2.24):

```yaml
catalog-vite:
  profiles: !override [dev-only]   # zastąp całą wartość
catalog-app:
  build: !reset null               # usuń, jakby nigdy nie istniało
  volumes: !override
    - caddy-data:/data             # tylko nazwane wolumeny, zero ./repo
```

Wynik: zero `build:`, zero bind-mountów, każdy port na `127.0.0.1`,
klucze Kibany z `.env` zamiast stałych z repo (**14/14**).

### `:80` zamiast HTTPS

`SERVER_NAME: ":80"` sprawia, że Caddy (we FrankenPHP) słucha zwykłego HTTP
dla każdej nazwy hosta. Bez domeny nie ma komu wystawić certyfikatu, a cały
ruch i tak idzie szyfrowanym tunelem SSH. Healthcheck z obrazu (który pyta
po HTTPS) trzeba wtedy nadpisać na `curl http://localhost/up`.

---

## Krok 3 — Sekrety, które powstają tylko na serwerze

`.env.prod.example` ma wszystkie sekrety ustawione na `__GENERATE__`.
`tools/vps/gen-env.sh` zamienia każde wystąpienie na osobną losową wartość:

| Nazwa | Format | Dlaczego |
|---|---|---|
| `*_APP_KEY` | `base64:` + 32 bajty | format klucza Laravela |
| `*_KEY` | 64 znaki hex | Kibana wymaga min. 32 znaków |
| reszta | 48 znaków hex | hex jest bezpieczny w DSN (`postgresql://u:HASŁO@...`) |

Dwie zasady wbudowane w skrypt:

- **Nigdy nie nadpisuje istniejącego `.env`** (exit 1). Utrata `.env` na
  serwerze to utrata haseł do baz, w których są dane.
- **Niczego nie wypisuje.** Sekrety nie trafiają do terminala, logu CI ani czatu.

W `.env` serwera są też `COMPOSE_FILE=compose.yaml:compose.prod.yaml`
i `COMPOSE_PROFILES=cluster,apps`. Compose czyta je sam, więc na serwerze
wystarczy `docker compose ps`, bez powtarzania `-f ... --profile ...`.

---

## Krok 4 — Próba generalna bez serwera

Zanim cokolwiek kupimy: stack produkcyjny uruchomiony lokalnie, w osobnym
katalogu, z **tylko tymi plikami, które trafią na serwer** (2 pliki compose
i wygenerowany `.env`) oraz obrazami prod oznaczonymi tak jak w GHCR. Ze
względu na pamięć: 1 węzeł ES zamiast 3.

```bash
docker compose -p mkt-prodtest -f compose.yaml -f compose.prod.yaml --profile apps up -d --wait
```

Co wyszło (żadna z tych rzeczy nie była widoczna w testach obrazów):

1. **`search:eval` padał** na `Class "Symfony\Component\Yaml\Yaml" not found`,
   bo `symfony/yaml` było tylko w `require-dev` (Krok 1.7).
2. **Nikt nie publikował outboxu na bieżąco** (szczegóły niżej).
3. **Wynik nDCG nie jest powtarzalny** (szczegóły niżej).

Wynik końcowy próby: wszystkie usługi `healthy`, smoke **25/25**, 1500
produktów przeszło cały pipeline (outbox → RabbitMQ → consumer → ES),
zmiana ceny widoczna w ES po **1 s**. Zużycie RAM: ES 1,7 GB (limit 2 GB),
Kibana 1,2 GB, reszta razem < 0,7 GB.

---

## Krok 5 — CI: GitHub Actions buduje, testuje, publikuje

`.github/workflows/images.yml`, jeden job, w tej kolejności:

```
build 5 obrazów (load: true, bez pushu) → prod-image-check → push <SHA> + main
```

**Bramka przed rejestrem.** Obrazy są publiczne, więc test „zero sekretów"
musi przejść, zanim cokolwiek trafi do GHCR. Czerwony test oznacza, że nic
nie zostaje wypchnięte.

**Bezpieczeństwo samego workflowu:**

- Wyzwalacze to tylko `push` na `main` i ręczny start. Nie ma
  `pull_request_target` ani wstawiania `${{ github.event.* }}` do `run:`,
  więc nikt z zewnątrz nie przemyci komendy w tytule PR-a.
- `permissions: {}` na górze, a job dostaje tylko `contents: read`
  i `packages: write`. Token nie może zrobić nic więcej.
- Akcje są przypięte do pełnego SHA (`actions/checkout@3d3c42e…  # v7.0.1`).
  Tag `v7` można przepisać, commit nie.
- `persist-credentials: false`, czyli token nie zostaje na dysku runnera.

Tag obrazu to **pełny SHA commita**: jednoznacznie mówi, jaki kod jest
w środku. `main` to ruchoma etykieta „ostatni build", wygodna, ale do
wdrożeń używamy SHA.

---

## Krok 6 — Klucz SSH i weryfikacja z Maca

Osobny klucz tylko do tego serwera:

```bash
ssh-keygen -t ed25519 -f ~/.ssh/elastic_vps_ed25519 -C "elastic-vps"
```

Osobny, bo da się go cofnąć (usunąć z serwera albo z panelu dostawcy) bez
ruszania kluczy do GitHuba i innych maszyn. Klucz nie ma hasła, bo
automatyzacja z sesji Claude nie ma jak go podać. Jeśli chcesz hasło: dodaj
je (`ssh-keygen -p -f ~/.ssh/elastic_vps_ed25519`) i trzymaj klucz
w `ssh-agent` (`ssh-add --apple-use-keychain ...`).

Każdy krok wdrożenia ma fazę w `tools/vps/verify.sh`, uruchamianą z Maca.
Fazę piszemy przed wykonaniem kroku: najpierw musi być czerwona, potem
zielona. Tak samo jak test przed kodem:

```bash
make vps-verify faza=images    # obrazy w GHCR: publiczne, linux/amd64
make vps-verify faza=access    # serwer: SSH, CPU, RAM, dysk, x86_64, Ubuntu
```

`images` sprawdza obrazy z **pustą konfiguracją Dockera**
(`DOCKER_CONFIG=$(mktemp -d)`), czyli bez logowania do rejestru. Skoro da
się je pobrać tak, serwer też je pobierze bez tokenu.

---

## Czego nie widać w testach: trzy odkrycia

### Outbox bez przekaźnika

Wzorzec outbox: catalog zapisuje zmianę i zdarzenie w **jednej transakcji**
(tabela `outbox`), a osobny proces publikuje zdarzenia do RabbitMQ.
Komenda `outbox:publish --loop` istniała, ale **nic jej nie uruchamiało**.
Zdarzenia publikował tylko `marketplace:seed` na końcu seedowania.
Lokalnie nikt tego nie zauważył, bo dane zawsze szły przez seed.

Na serwerze zmiana ceny leżałaby w outboksie wiecznie, a w wyszukiwarce
byłaby stara cena. Teraz działa usługa `outbox-publisher`: ten sam obraz
co catalog, inny proces (wzorzec „jeden obraz, wiele ról").

```
UPDATE offers + INSERT outbox (1 transakcja)
        │
outbox-publisher: SELECT … FOR UPDATE SKIP LOCKED → publish → confirm → published_at
        │
RabbitMQ → search-consumer → ES          (zmierzone: 1 s)
```

`SKIP LOCKED` pozwala bezpiecznie uruchomić kilka publisherów naraz.
Sprawdziliśmy to, bo seed i usługa publikują równolegle: seed opublikował
165 zdarzeń, resztę zabrał publisher.

### Wynik nDCG, który nie jest liczbą

Plan zakładał test przyjęcia „nDCG@10 = 0.967, jak lokalnie". Na świeżym
seedzie wyszło 0.851. Potem 0.856, 0.842, 0.889, za każdym razem na tych
samych danych.

Diagnoza:

```bash
curl -u elastic:… "localhost:$ES_PORT/products-search/_search?q=name:telefon&size=12&_source=name"
# 158 2.583 Bailey Ltd Smartfon Nova 128GB
# 117 2.583 Fritsch-Schuppe Smartfon Zenit Pro
# 195 2.583 ...                           ← wszystkie smartfony: IDENTYCZNY _score
```

Przy remisie `_score` o kolejności decyduje wewnętrzny numer dokumentu
w Lucene, a on zależy od tego, kiedy ES odświeżył i scalił segmenty.
`queries.yaml` ocenia tylko 10 z kilkudziesięciu remisujących smartfonów,
więc wynik losuje się razem z kolejnością. A 0.967 zmierzono na lokalnej
bazie z 6019 produktami (seed puszczony kilka razy), której nie da się
odtworzyć.

Wniosek: **liczba, której nie da się powtórzyć, nie może być testem.**
Kryterium dla ETAPU D to „eval OK": 1500 dokumentów, zapytania o konkretny
produkt = 1.000, średnia ≥ 0.80. Po restarcie i po odtworzeniu snapshotu
wynik ma być *identyczny jak przed*, bo indeks jest ten sam. Naprawa
harnessu to osobne zadanie.

### Smoke test, który kłamał przy działających aplikacjach

`make smoke` sprawdzał routing RabbitMQ tak: opublikuj wiadomość, policz
głębokość kolejki, oczekuj 1. Przy działającym `search-consumer` konsument
zabierał wiadomość, zanim test zdążył policzyć, i wychodziło 0. To był
wyścig, a nie błąd brokera. Teraz test na chwilę zatrzymuje konsumenta.
Używa `stop`, a nie `pause`: niepotwierdzone wiadomości wracają do kolejki
i znikają w `purge`, zamiast trafić do handlera jako śmieci.

---

## Faza 1 — serwer

*Dopisywane w trakcie wdrożenia: zakup, zabezpieczenie systemu, Docker,
pierwsze wdrożenie, tunel SSH, rollback, backupy, restart.*
