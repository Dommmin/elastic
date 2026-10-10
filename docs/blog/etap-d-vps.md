# Wdrożenie na VPS: od „działa u mnie" do obrazu w rejestrze i prywatnego serwera

*ETAP D projektu Marketplace Search Platform. Cały stack (klaster
Elasticsearch, Kibana, Postgres, Redis, RabbitMQ, Laravel, Symfony) na
własnym serwerze, dostępny tylko dla Ciebie. Bez `git pull` na serwerze:
kod jedzie w obrazach przez rejestr, a serwer tylko je uruchamia. Razem
z każdym błędem, który wyszedł po drodze, i z tym, jak go znaleźć.*

> **Stan:** Faza 0 (przed zakupem) i Faza 1 (serwer) zrobione i sprawdzone
> na prawdziwym VPS: `make vps-verify faza=all` → **63/63**, restart serwera →
> wszystko samo w 187 s. Plan: [`docs/08-PLAN-ETAP-D-VPS.md`](../08-PLAN-ETAP-D-VPS.md).
> Faza D2 (k3s) — osobny plan.

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
10. [Krok 7 — Pierwsze wejście i zabezpieczenie systemu](#krok-7--pierwsze-wejście-i-zabezpieczenie-systemu)
11. [Krok 8 — Docker](#krok-8--docker)
12. [Krok 9 — Pierwsze wdrożenie](#krok-9--pierwsze-wdrożenie)
13. [Krok 10 — Tunel SSH: aplikacja tylko dla Ciebie](#krok-10--tunel-ssh-aplikacja-tylko-dla-ciebie)
14. [Krok 11 — Nowa wersja i rollback](#krok-11--nowa-wersja-i-rollback)
15. [Krok 12 — Backupy i odtwarzanie](#krok-12--backupy-i-odtwarzanie)
16. [Krok 13 — Restart serwera](#krok-13--restart-serwera)
17. [Ściąga: codzienna obsługa](#ściąga-codzienna-obsługa)
18. [Twoja kolej](#twoja-kolej)

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

Pierwszy przebieg: **10,5 min** (bez cache), wszystkie kroki zielone.
Paczki w GHCR od razu wyszły **publiczne**: etykieta
`org.opencontainers.image.source=https://github.com/Dommmin/elastic` łączy
je z publicznym repo, a GHCR przejmuje jego widoczność.

**Uwaga na tagi:** obrazy powstają tylko dla commitów, które zmieniają
`apps/`, `infra/` itd. (filtr `paths`). Commit z samą dokumentacją nie ma
obrazów, więc „HEAD z main" nie jest poprawnym tagiem do wdrożenia.
`tools/vps/latest-image-tag.sh` pyta GitHuba o SHA ostatniego *udanego*
buildu i z niego korzystają `make prod-deploy` oraz `verify.sh`.

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

## Krok 7 — Pierwsze wejście i zabezpieczenie systemu

Serwer: Ubuntu 24.04.4, 4 vCPU, 24 GB RAM, 99 GB dysku. Klucz publiczny
dodany do `/root/.ssh/authorized_keys` (`ssh-copy-id`). Alias w `~/.ssh/config`:

```
Host elastic-vps
    HostName <IP>
    User deploy                 # na początku: root
    IdentityFile ~/.ssh/elastic_vps_ed25519
    IdentitiesOnly yes          # nie próbuj innych kluczy (każda próba = "nieudane logowanie")
```

```bash
make vps-verify faza=access      # SSH, CPU, RAM, dysk, x86_64, Ubuntu → 6/6
```

Dysk 99 GB zamiast planowanych 150: przy 1500 produktach dane to megabajty,
obrazy ~7 GB. Próg w weryfikacji obniżony do 80 GB wolnego — świadomie, z zapisem w planie.

### Bootstrap: 8 kroków

`tools/vps/bootstrap.sh` (idempotentny): aktualizacje → użytkownik `deploy`
z kluczem i `sudo` → `sshd` tylko z kluczy, bez roota → `ufw` (tylko 22/tcp)
→ `fail2ban` → automatyczne łatki bezpieczeństwa (bez automatycznego
restartu) → sysctl pod ES → swap → strefa czasowa.

**Uruchamiaj w tle na serwerze, z logiem do pliku**:

```bash
scp tools/vps/bootstrap.sh elastic-vps:/tmp/
ssh elastic-vps 'sudo -n sh -c "nohup bash /tmp/bootstrap.sh > /var/log/bootstrap.log 2>&1 < /dev/null &"'
```

Dlaczego tak, a nie `ssh … 'bash -s' < bootstrap.sh` — bo tak właśnie zrobiłem
za pierwszym razem i **zbanowałem sam siebie** (RUNBOOK #034):

1. Przed bootstrapem uruchomiłem czerwoną fazę `verify.sh hardening`. ~13
   sprawdzeń logowało się jako `deploy`, którego jeszcze nie było → 13
   nieudanych logowań w dzienniku.
2. Krok 1 instaluje `fail2ban`. Pakiet startuje usługę od razu, czyta dziennik
   z ostatnich 10 minut: >5 porażek z jednego IP → **ban**. Ban zrywa też
   nawiązane połączenie.
3. Skrypt żył chwilę dalej, ale pierwsze `echo` do martwego terminala = SIGPIPE.
   Doszedł do kroku 3: root wyłączony, firewall jeszcze nie.

Objaw: `ssh: connect to host … port 22: Connection refused`. Diagnoza po 4
minutach (domyślny ban Ubuntu to 10 min, wpadliśmy w końcówkę):

```bash
ssh -o User=deploy elastic-vps 'sudo zgrep -h " Ban \| Unban " /var/log/fail2ban.log*'
# NOTICE [sshd] Ban   <IP>    17:44:26
# NOTICE [sshd] Unban <IP>    17:48:51
```

Lekcje, które weszły do skryptów: weryfikacja przerywa po pierwszym nieudanym
logowaniu, jail `sshd` jest zapisany *przed* instalacją fail2ban, długie
operacje idą przez `nohup`. I ogólna: **test, który „tylko sprawdza", też ma
skutki uboczne** — dla fail2ban nieudane logowanie to atak.

Dwa drobiazgi z tej samej rundy:
- Ubuntu 24.04 ma domyślnie `vm.max_map_count = 1048576`. „Ustaw 262144" by go
  *obniżyło* — skrypt podnosi tylko, gdy jest mniej.
- Po `full-upgrade` (201 pakietów, w tym jądro) — `/var/run/reboot-required`.
  Restart od razu, póki nic nie działa: SSH wrócił po 27 s, zabezpieczenia bez zmian.

```bash
make vps-verify faza=hardening   # deploy+klucz, root odrzucony, sshd, ufw, fail2ban, sysctl → 14/14
make vps-verify faza=exposure    # skan Z ZEWNĄTRZ: otwarty tylko 22 → 15/15
```

`exposure` skanuje porty **z Maca**, a nie przez `ss -tlnp` na serwerze. Docker
publikuje porty własnymi regułami iptables, z pominięciem ufw — lista reguł
ufw może być idealna, a port i tak otwarty. Liczy się tylko to, co widać z sieci.
Dlatego ta faza jest powtarzana po każdym kolejnym kroku.

---

## Krok 8 — Docker

```bash
scp tools/vps/install-docker.sh elastic-vps:/tmp/ && ssh elastic-vps 'sudo -n bash /tmp/install-docker.sh'
make vps-verify faza=docker      # → 7/7
```

Z oficjalnego repo `download.docker.com` (Engine 29.9, Compose 5.6), nie
`apt install docker.io` — nakładka potrzebuje Compose ≥ 2.24. W `daemon.json`:

- **rotacja logów** (`max-size: 10m`, `max-file: 3`) — domyślnie logi kontenerów
  rosną bez limitu i potrafią zapchać dysk;
- **`live-restore`** — restart samego demona Dockera (np. przy aktualizacji)
  nie zabija kontenerów, więc klaster ES nie przechodzi restartu.

---

## Krok 9 — Pierwsze wdrożenie

```bash
make prod-deploy                 # tag = ostatni udany build CI
```

Co robi `tools/vps/deploy.sh`: `scp` plików compose (+ szablon `.env`, skrypty
backupu) → na serwerze: `.env` z `gen-env.sh` (tylko pierwszy raz) i
`IMAGE_TAG=<sha>` → `docker compose pull` → `up --wait` **bez** outbox-publishera
→ migracje → `up --wait` całości → indeks i alias (tylko gdy ich nie ma) →
konfiguracja backupów → `docker image prune`.

Kolejność „schemat przed kodem": publisher czyta tabelę `outbox`, więc nowa
wersja może ruszyć dopiero po migracjach. Przy pierwszym wdrożeniu tabeli nie
ma wcale — publisher by padał, a `up --wait` przerwałby skrypt przed migracjami
(to wyszło w próbie generalnej, więc było naprawione przed serwerem).

### Dwa błędy w samym skrypcie wdrożenia (RUNBOOK #035)

Skrypt zdalny idzie heredokiem: `ssh host bash -s <<REMOTE … REMOTE`.

**Błąd 1 — wdrożenie kończy się po cichu po pierwszej migracji.** Skrypt
trafia do zdalnego basha *przez stdin*. `docker compose exec -T` też czyta
stdin — więc połknął resztę skryptu. Bash nie miał czego czytać i zakończył
się kodem 0. Bez błędu, bez es02 i es03, bez outbox-publishera. Naprawa:
`</dev/null` przy każdym `exec`.

**Błąd 2 — wdrożenie wisi 10 minut, na serwerze nic się nie dzieje.**
```
bash tools/vps/deploy.sh …
 └ bash tools/vps/deploy.sh …
    └ bash -s            ← NA MACU
```
Heredoc był niecytowany (`<<REMOTE`), więc **lokalny** bash rozwijał w nim
backticki — także w *komentarzach*. Komentarz „`` `bash -s` `` przez stdin"
uruchomił `bash -s` na Macu, który czekał na wejście. Naprawa: `<<'REMOTE'`
(nic nie jest rozwijane lokalnie) i zmienne przekazane jawnie:

```bash
ssh "${HOST}" "TAG='${TAG}' DIR='${DIR}' bash -s" <<'REMOTE'
```

Zasada: **heredoc do zdalnej powłoki zawsze cytuj.** Ten sam problem dotyczył
`smoke-test.sh` puszczanego przez stdin — teraz jest kopiowany i uruchamiany z pliku.

Wynik:

```bash
make vps-verify faza=stack
#  ✓ brak kodu na serwerze (.git, apps/)
#  ✓ obrazy własne = ghcr.io/dommmin/elastic-*:fc6e16d…
#  ✓ klaster ES: green, 3 nody
#  ✓ smoke-test.sh: wszystkie testy (25)
#  ✓ products-search: 1500 dokumentów
#  ✓ eval: zapytania o konkretny produkt = 1.000, średnia 0.841
#  ✓ E2E: zmiana ceny w ES w <= 10 s (3 s)
#  PASS=11 FAIL=0
```

RAM: 9,9 z 24 GB (ES 2,2–2,6 GB na node, Kibana 1,1 GB, reszta razem < 0,4 GB).

---

## Krok 10 — Tunel SSH: aplikacja tylko dla Ciebie

Na serwerze wszystkie usługi słuchają na `127.0.0.1` — z internetu ich nie ma.
Tunel SSH przenosi port z serwera na Twój Mac, szyfrowany tym samym kanałem co SSH:

```
Mac localhost:28080 ══ SSH (port 22) ══▶ serwer 127.0.0.1:8080 (catalog)
Mac localhost:25601 ══════════════════▶ serwer 127.0.0.1:5601 (Kibana)
Mac localhost:26672 ══════════════════▶ serwer 127.0.0.1:15672 (RabbitMQ UI)
```

```bash
make vps-tunnel                       # Ctrl+C zamyka tunel
make vps-secret k=ELASTIC_PASSWORD    # hasło do Kibany (użytkownik: elastic)
make vps-secret k=RABBITMQ_PASSWORD   # hasło do RabbitMQ UI (użytkownik: marketplace)
```

Porty lokalne to 2xxxx, bo 18080 zajmuje lokalny stack dev, a 25672 — RabbitMQ
innego projektu. `ExitOnForwardFailure yes` w `~/.ssh/config`: jeśli port jest
zajęty, tunel nie wstaje po cichu bez tego portu, tylko kończy się błędem.

Dlaczego tunel, a nie publiczny port z hasłem: każdy publiczny port to
powierzchnia ataku (skanery znajdą Kibanę w ciągu godzin). Tunel nie wystawia
niczego nowego — korzysta z SSH, które i tak jest otwarte i chronione kluczem.

**Pułapka w panelu RabbitMQ:** kolejka `search.product.sync` pokazuje
**0 konsumentów**, choć search-consumer działa i przetwarza zdarzenia. Symfony
Messenger czyta kolejkę przez `basic.get` w pętli, a nie `basic.consume` — więc
RabbitMQ nie widzi zarejestrowanego konsumenta. To nie awaria; sprawdzaj
`make prod-logs s=search-consumer`.

---

## Krok 11 — Nowa wersja i rollback

Do ćwiczenia potrzebna była widoczna zmiana — i od razu coś użytecznego:
nagłówek `X-App-Version` z SHA commita, z którego zbudowano obraz (CI:
`--build-arg APP_VERSION=${{ github.sha }}`, Caddy: `header X-App-Version {$APP_VERSION}`).

```bash
git push                                     # CI: 4,3 min z cache
make prod-deploy                             # nowa wersja
curl -sI localhost:28080/up | grep -i x-app-version
# X-App-Version: e0589c621c46…

make prod-deploy tag=fc6e16d485ebcd76f692dc8faf1f6d3278615a25   # ROLLBACK
# (brak nagłówka — stary obraz go nie miał)

make prod-deploy                             # z powrotem na nową
```

Każde przejście trwało ~3,5 minuty. Wąskie gardło: tag obejmuje **wszystkie 5
obrazów**, więc zmiana tylko w catalog podmienia też obraz ES → restart całego
klastra. Kierunek usprawnienia: osobne wersjonowanie obrazów infrastruktury
(ES, Postgres, RabbitMQ zmieniają się rzadko) i aplikacji (catalog, search).

---

## Krok 12 — Backupy i odtwarzanie

Backup, którego nie odtworzyłeś, jest hipotezą. Oba przećwiczone.

| | Elasticsearch | Postgres |
|---|---|---|
| Mechanizm | snapshot do repozytorium `fs-backup` (`/snapshots`) | `pg_dump -Fc` obu baz |
| Harmonogram | polityka SLM `nightly`, 01:00 UTC | timer systemd, 03:30 |
| Retencja | 7 dni (min. 1, maks. 7) | 7 dni (`find -mtime +7 -delete`) |
| Gdzie skonfigurowane | `deploy.sh` (PUT idempotentne) | `infra/systemd/*`, instalowane przez `deploy.sh` |
| Kopia poza serwerem | `make vps-backup-pull` → `~/backups/elastic-vps/` | to samo |

Repozytorium `fs` działa, bo 3 nody są na jednym serwerze i dzielą wolumen
`/snapshots`. Przy nodach na różnych maszynach potrzebny byłby NFS albo S3 —
każdy node zapisuje swoje shardy do tego samego repozytorium.

### Ćwiczenie: usunięty indeks

```
1. eval przed: 0.841
2. zatrzymaj search-consumer i outbox-publisher        ← ważne, patrz niżej
3. DELETE products-v1          → wyszukiwarka: HTTP 500
4. POST _snapshot/fs-backup/<snapshot>/_restore
   {"indices":"products-v1","include_aliases":true,"include_global_state":false}
5. 1500 dokumentów, alias products-search → products-v1
6. start konsumentów; eval po: 0.841 (identyczny), wyszukiwarka: HTTP 200
```

Krok 2 nie jest kosmetyką: gdyby w trakcie przyszło zdarzenie, zapis do
nieistniejącego aliasu `products-search` **utworzyłby nowy indeks o tej nazwie**
z automatycznym mapowaniem — i odtworzenie aliasu by się z nim zderzyło.

Druga obserwacja: bez indeksu wyszukiwarka zwraca 500. Do rozważenia
(ETAP 9): łagodna degradacja — komunikat „wyszukiwanie chwilowo niedostępne".

### Ćwiczenie: dump Postgresa

`pg_restore` najnowszego dumpu do osobnej bazy `catalog_restore_test`, porównanie
liczby wierszy z produkcją: `products` 1500/1500, `offers` 4517/4517, `brands`,
`sellers`, `outbox` — wszystkie zgodne. Baza testowa usunięta.

```bash
make vps-verify faza=backup      # repozytorium, SLM, snapshot < 26 h, timer, dumpy < 26 h → 5/5
```

---

## Krok 13 — Restart serwera

Najważniejszy test odporności: prąd zgasł o 3 w nocy. Po starcie nikt nie
wpisze `docker compose up` — wszystko musi wstać samo (`restart: unless-stopped`),
a klaster złożyć się z 3 nodów, mimo że żaden nie jest „pierwszy" (RUNBOOK #021).

```bash
make vps-verify faza=reboot      # UWAGA: naprawdę restartuje serwer
#  eval przed restartem: 0.841
#  ✓ po restarcie: ES green, 3 nody, wszystko healthy w <= 5 min (187 s)
#  ✓ uptime serwera < 10 min (restart naprawdę był)
#  ✓ eval identyczny jak przed restartem (0.841 -> 0.841)
#  ✓ smoke po restarcie
```

„Eval identyczny" jest tu mocnym testem, bo ten sam indeks = te same segmenty =
ta sama kolejność remisów. Na świeżym seedzie ta liczba skacze (RUNBOOK #032),
po restarcie — nie ma prawa.

Na koniec:

```bash
make vps-verify faza=all         # images, access, hardening, exposure, docker, stack, backup
#  PASS=63 FAIL=0
```

---

## Ściąga: codzienna obsługa

| Chcę… | Komenda |
|---|---|
| zobaczyć aplikację / Kibanę / RabbitMQ | `make vps-tunnel` → localhost:28080 / 25601 / 26672 |
| hasło z serwera | `make vps-secret k=ELASTIC_PASSWORD` |
| wdrożyć to, co jest na main | `git push` → poczekaj na CI → `make prod-deploy` |
| cofnąć wersję | `make prod-deploy tag=<starszy SHA>` (lista: GitHub → Actions → images) |
| stan kontenerów / logi | `make prod-ps`, `make prod-logs s=catalog-app` |
| sprawdzić, czy wszystko gra | `make vps-verify` |
| ściągnąć backupy na Maca | `make vps-backup-pull` |
| wejść na serwer | `ssh elastic-vps` (tylko `deploy`, tylko kluczem) |
| przed pushem zmian w Dockerfile'ach | `make prod-check` (obrazy z czystego klonu + nakładka) |

---

## Twoja kolej

Etap jest skończony, gdy **sam** zrobisz z tego przewodnika:

1. `make vps-tunnel`, zaloguj się do Kibany (`make vps-secret k=ELASTIC_PASSWORD`)
   i znajdź w *Stack Management → Snapshot and Restore* politykę `nightly`
   i snapshot z dzisiaj.
2. Rollback i powrót: `make prod-deploy tag=fc6e16d485ebcd76f692dc8faf1f6d3278615a25`,
   sprawdź `curl -sI localhost:28080/up` (nagłówka nie ma), potem `make prod-deploy`.
3. `make vps-verify` — powinno być 63/63.

Pytania kontrolne (odpowiedz własnymi słowami):

- Dlaczego na serwerze nie ma `git pull`? Co dokładnie by się zepsuło przy rollbacku?
- Dlaczego `config:cache` w Dockerfile to błąd, a `route:cache` nie?
- Port 9200 jest na liście reguł ufw jako zablokowany. Czy to wystarczy, żeby
  był niedostępny z internetu? Jak to sprawdzić?
- Co się stanie, jeśli podczas odtwarzania indeksu konsument dostanie zdarzenie?
- Dlaczego `verify.sh` po nieudanym logowaniu przerywa, zamiast sprawdzać dalej?
