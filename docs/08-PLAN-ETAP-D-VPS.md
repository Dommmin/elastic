# 08 — ETAP D: wdrożenie na VPS (obrazy z rejestru; Compose → k3s)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Cel:** cały stack (klaster ES 3-nodowy, Kibana, Postgres, Redis, RabbitMQ, catalog, search-consumer)
działa na prywatnym VPS-ie, dostępny **tylko dla właściciela**, z weryfikacją każdego kroku i przewodnikiem
„jak to zrobiłem i jak to działa".

**Architektura — model „zbuduj raz, uruchamiaj wszędzie":**

```
git push → GitHub Actions buduje obrazy (amd64) → GHCR (ghcr.io/dommmin/elastic-*:<sha>)
                                                        │ docker pull
Mac: make prod-deploy tag=<sha> ── scp 3 pliki ──▶ VPS: compose.yaml + compose.prod.yaml + .env
```

- **Na serwerze nie ma kodu ani gita.** Są tylko obrazy z rejestru i trzy pliki: `compose.yaml`,
  `compose.prod.yaml` i `.env` (sekrety, generowane na serwerze).
- Obrazy są **samowystarczalne**: konfiguracja, która lokalnie wchodzi przez bind-mount (synonimy ES,
  skrypty init, mapowania, konfiguracja RabbitMQ), jest wbudowana w obraz. Lokalny dev działa bez zmian,
  bo bind-mounty przykrywają to, co jest w obrazie.
- **Żaden port poza SSH nie jest wystawiony do internetu.** Porty usług są bindowane na `127.0.0.1`,
  a UI otwierasz tunelem SSH.
- Wdrożenie i rollback to jedna komenda z tagiem (hash commita).

**Fazy:**
- **D1 (ten plan)** — VPS + Docker Compose z obrazami z GHCR.
- **D2 (zarys na końcu, osobny plan po DoD D1)** — te same obrazy w k3s.

**Stack:** GitHub Actions, GHCR, Ubuntu LTS, Docker Engine + Compose v2, ufw, fail2ban, systemd timers.

**Zmienia granice zakresu z `06-PLAN-WDROZENIA.md` CZĘŚĆ E:** „❌ deploy produkcyjny" i „❌ CI/CD" —
CI ogranicza się do budowania i publikowania obrazów. Po DoD D1 aktualizujemy tamten dokument.

---

## Kto co robi

| Ty (tylko Ty możesz) | Claude |
|---|---|
| Zgoda na `git push` (Task 3) | Faza 0: obrazy prod, compose.prod, workflow CI — lokalnie, przed zakupem |
| Ustawienie paczek GHCR na **Public** (GitHub → Packages) | Zabezpieczenie systemu, Docker, wdrożenie (przez SSH) |
| Zakup serwera, wklejenie **publicznego** klucza SSH, podanie IP | Weryfikacja po każdym kroku (`verify.sh`) z wynikiem w czacie |
| Zatwierdzanie komend | Przewodnik, RUNBOOK, POMIARY |

Hasła nigdy nie przechodzą przez czat. Sekrety generuje skrypt na serwerze, a dostęp do serwera jest
wyłącznie po kluczu SSH.

## Wymagania sprzętowe

| Parametr | Wartość | Dlaczego |
|---|---|---|
| RAM | **24 GB** (minimum 16 GB, wtedy 1 node ES) | suma `mem_limit` niżej |
| vCPU | 4–8 | 3 JVM + PHP (build odbywa się w CI, nie na serwerze) |
| Dysk | ≥ 150 GB NVMe | obrazy, 3× dane ES, snapshoty, backupy PG |
| Architektura | **x86_64** | CI buduje `linux/amd64` |
| System | **Ubuntu 24.04 LTS** (26.04 LTS, jeśli dostawca ma i Docker oficjalnie wspiera) | repo Dockera, długie wsparcie |

| RAM VPS | Tryb ES | `ES_HEAP` | `ES_MEM_LIMIT` | Suma limitów stacku |
|---|---|---|---|---|
| 16 GB | 1 node | 2g | 4g | ~11,5 GB |
| **24 GB** | **3 nody** | **1500m** | **3g** | **~17 GB** |
| 32 GB | 3 nody (jak `01-INFRASTRUKTURA.md`) | 3g | 6g | ~26 GB |

---

## Global Constraints

- Nazwy obrazów: `ghcr.io/dommmin/elastic-{elasticsearch,postgres,rabbitmq,catalog,search}:<tag>`, tag = pełny SHA commita; dodatkowo `:main` dla ostatniego builda z `main`. Platforma `linux/amd64`.
- Wersje bazowe z `.env.example` (jedno źródło prawdy, czytane też przez CI): ES/Kibana 9.5.1, Postgres 18.4, RabbitMQ 4.3.4, Redis 8.8.1, FrankenPHP 1.12.7, PHP 8.5, Node 24.19.0.
- Repo i obrazy są **publiczne** → w obrazie i w gicie nie może być żadnego sekretu ani jego pochodnej (także hasha hasła RabbitMQ).
- Porty usług bindowane tylko na `127.0.0.1`; z internetu otwarty wyłącznie `22/tcp`.
- SSH wyłącznie kluczem; `PermitRootLogin no`, `PasswordAuthentication no`.
- Katalog na serwerze: `/opt/marketplace`, właściciel `deploy`. Brak `.git` i `apps/` na serwerze.
- Po restarcie serwera wszystko wstaje samo, klaster ES `green`.
- Test przyjęcia: `nDCG@10 = 0.967` (seed deterministyczny, `fake()->seed(42)`), ta sama liczba co w `POMIARY.md`.
- Lokalny dev (`make up-apps`, `make smoke`, `make eval`) działa jak przed etapem.
- DoD etapu: commity + przewodnik krok po kroku w `docs/blog/etap-d-vps.md`.

## Review Focus

1. **Docker omija ufw.** Port opublikowany na `0.0.0.0` jest widoczny z internetu mimo `ufw deny`. → `verify.sh exposure` skanuje porty z Maca (Task 6, 7, 9).
2. **Sekret w publicznym obrazie** (`.env` skopiowany przez `COPY apps/catalog/`, hash hasła w `definitions.json`). → `prod-image-check.sh` w CI blokuje push (Task 1, 3).
3. **Restart serwera = deadlock klastra** (RUNBOOK #021). → test `reboot` (Task 10).
4. **Zapchany dysk** przez logi i stare obrazy. → rotacja logów + `docker image prune` w deployu + próg `df` (Task 7, 8).
5. **Odcięcie się od serwera** przy zmianie `sshd`. → druga sesja testowana przed zamknięciem pierwszej, `sshd -t` przed reloadem (Task 6).

---

## Mapa plików

| Plik | Odpowiedzialność |
|---|---|
| `.dockerignore` (nowy) | Kontekst buildu bez `.env*`, `vendor/`, `node_modules/`, `public/build`, `var/`, `.git`, `docs` |
| `infra/elasticsearch/Dockerfile` (zmiana) | + `analysis/`, `init-storage.sh`, `setup-security.sh` wbudowane |
| `infra/postgres/Dockerfile` (nowy) | `postgres:18.4-alpine` + `init/` w `/docker-entrypoint-initdb.d` |
| `infra/rabbitmq/Dockerfile` + `entrypoint.sh` (nowe) | conf + plugins + szablon definicji; **hash hasła liczony przy starcie** z env |
| `infra/php/catalog.Dockerfile` + `catalog-entrypoint.sh` | `config/route/view:cache` przy starcie, nie w buildzie; `tests/relevance` w obrazie |
| `infra/php/search.Dockerfile` (zmiana) | mapowania ES w obrazie; start bez `apps/search/.env` |
| `apps/catalog/composer.json` (zmiana) | `fakerphp/faker` → `require` (seed działa w prod) |
| `tools/vps/prod-image-check.sh` (nowy) | Test obrazów: brak sekretów, config z env, seed, konsola Symfony |
| `compose.prod.yaml` (nowy) | `image: ghcr.io/…:${IMAGE_TAG}`, `build: !reset`, `volumes: !override`, bez Vite |
| `.env.prod.example`, `tools/vps/gen-env.sh` (nowe) | Szablon `.env` (profil 24 GB, `__GENERATE__`) i generator sekretów |
| `.github/workflows/images.yml` (nowy) | Build 5 obrazów amd64 → `prod-image-check` → push do GHCR |
| `tools/vps/verify.sh` (nowy) | `verify.sh <faza>` z Maca; ✓/✗; `exit 1` przy błędzie |
| `tools/vps/bootstrap.sh`, `install-docker.sh`, `infra/docker/daemon.json` (nowe) | Hardening i Docker na serwerze |
| `infra/systemd/marketplace-pg-backup.{service,timer}` (nowe) | Codzienny `pg_dump`, retencja 7 dni |
| `Makefile` (zmiana) | `prod-deploy`, `prod-ps`, `prod-logs`, `prod-seed`, `prod-eval`, `vps-verify`, `vps-tunnel`, `vps-backup-pull` |
| `docs/blog/etap-d-vps.md` + RUNBOOK, POMIARY, README, `06-PLAN` | Dokumentacja (DoD) |

**Interfejs `tools/vps/verify.sh`:** `verify.sh <faza>`, faza ∈ `images | access | hardening | exposure | docker | stack | backup | reboot | all`.
Łączy się aliasem `elastic-vps`. Wypisuje `✓/✗ opis`, na końcu `PASS=n FAIL=m`, `exit 1` gdy `FAIL>0`.
Każdy task najpierw dopisuje swoją fazę (czerwona), potem ją zazielenia.

**Interfejs `make prod-deploy tag=<sha>`:** `scp compose.yaml compose.prod.yaml` → serwer; jeśli brak `.env`
→ `gen-env.sh`; `IMAGE_TAG=<sha>` zapisany w `.env`; `docker compose pull && up -d --wait`;
`docker image prune -f`. Rollback = to samo ze starszym tagiem.

---
## FAZA 0 — lokalnie, przed zakupem (0 zł)

### Task 1: Samowystarczalne obrazy prod bez sekretów

Obrazy `prod` nigdy nie były uruchamiane. Analiza pokazała problemy, które wywróciłyby wdrożenie:

1. **Brak `.dockerignore`** — `COPY apps/catalog/ ./` wkleja do obrazu lokalny `.env` z sekretami, `vendor/` i `node_modules/` z macOS.
2. **`config:cache` w czasie buildu** — w buildzie nie ma zmiennych z compose, więc cache zapamięta `DB_CONNECTION=sqlite`, a runtime env będzie ignorowany.
3. **`marketplace:seed` używa `fake()`**, a `prod` instaluje `composer --no-dev` → brak Fakera.
4. **Symfony bez `apps/search/.env`** (gitignorowany) — do sprawdzenia, czy `bin/console` startuje z samymi zmiennymi.
5. **Konfiguracja przez bind-mount** (13 montowań `./infra/…`, `./tests/relevance`) — na serwerze bez repo tych plików nie ma.
6. **`definitions.json` RabbitMQ zawiera hash hasła** — nie może trafić do publicznego obrazu.

**Files:** jak w mapie plików (wiersze od `.dockerignore` do `prod-image-check.sh`).

**Interfaces — produkuje:** 5 obrazów `marketplace/{elasticsearch,postgres,rabbitmq,catalog,search}:prod` budowanych z kontekstu `.`; `tools/vps/prod-image-check.sh <tag>` (exit 0 = obrazy OK) — używany lokalnie i w CI (Task 3).

- [ ] **Krok 1: Napisz `prod-image-check.sh` (ma się wysypać).** Klonuje repo do katalogu tymczasowego (= zero plików ignorowanych), buduje 5 obrazów, sprawdza:
  - `catalog`, `search`: w `/app` brak `.env`, `node_modules`; `catalog` ma `/app/vendor/fakerphp`;
  - `catalog` z `-e DB_CONNECTION=pgsql -e APP_KEY=…` → `php artisan tinker --execute='echo config("database.default");'` = `pgsql`;
  - `catalog`: istnieje `/tests/relevance/queries.yaml`; `search`: istnieje `/infra/elasticsearch/mappings/products-v1.json`;
  - `search` z `APP_ENV=prod APP_SECRET=x DATABASE_URL=…` → `bin/console about` kod 0;
  - `elasticsearch`: istnieją `config/analysis/synonyms.txt`, `/usr/local/bin/init-storage.sh`, `setup-security.sh`;
  - `postgres`: istnieje `/docker-entrypoint-initdb.d/01-databases.sh`;
  - `rabbitmq`: w obrazie **brak** `/etc/rabbitmq/definitions.json`; po starcie z `RABBITMQ_USER/PASSWORD` → `rabbitmqctl authenticate_user $U $P` kod 0 i kolejka `search.product.sync` istnieje.
- [ ] **Krok 2:** uruchom → oczekiwane FAIL (sekrety, `sqlite`, brak plików).
- [ ] **Krok 3: Napraw:**
  - `.dockerignore` (lista w mapie plików);
  - `catalog-entrypoint.sh`: `config:cache && route:cache && view:cache && exec frankenphp run --config /etc/frankenphp/Caddyfile`; w Dockerfile `ENTRYPOINT` zamiast `RUN … config:cache`; `COPY tests/relevance /tests/relevance`;
  - `fakerphp/faker` → `require` (komentarz w README: seed jest tu operacją „produkcyjną");
  - `search`: `COPY infra/elasticsearch/mappings /infra/elasticsearch/mappings`; jeśli `bootEnv` wymaga pliku → `RUN touch .env`;
  - ES: `COPY` analysis + oba skrypty (ścieżki jak w `compose.yaml`, żeby bind-mount lokalnie je przykrywał);
  - Postgres: nowy Dockerfile z `COPY init/`;
  - RabbitMQ: `COPY` conf, `enabled_plugins`, `definitions.template.json`; `entrypoint.sh` liczy hash **tym samym algorytmem co `tools/render-rabbitmq-definitions.py`** (4 bajty soli + sha256, base64; `openssl` i `base64` są w obrazie — sprawdzone), zapisuje `/etc/rabbitmq/definitions.json`, potem `exec docker-entrypoint.sh rabbitmq-server`.
- [ ] **Krok 4:** `prod-image-check.sh` → wszystkie ✓.
- [ ] **Krok 5:** lokalny dev bez regresji: `make up-apps && make smoke && make eval` → smoke zielony, `0.967`.
- [ ] **Krok 6: Commit** `ETAP D [1/10]: samowystarczalne obrazy prod bez sekretów`

### Task 2: `compose.prod.yaml` i sekrety

**Interfaces — konsumuje:** obrazy z Task 1. **Produkuje:** `compose.prod.yaml`, `.env.prod.example`, `gen-env.sh <szablon> <cel>` (nie nadpisuje istniejącego celu → exit 1).

- [ ] **Krok 1: Test** `tools/vps/compose-prod-check.sh`: `docker compose -f compose.yaml -f compose.prod.yaml --profile cluster --profile apps config` → kod 0; w wyniku **zero** kluczy `build:`, **zero** źródeł wolumenów zaczynających się od `./`; każdy `image:` usług własnych zaczyna się od `ghcr.io/dommmin/elastic-`; brak usługi `catalog-vite`; `gen-env.sh` na kopii szablonu → zero `__GENERATE__`, plik `600`, drugie wywołanie → exit 1.
- [ ] **Krok 2:** → FAIL.
- [ ] **Krok 3:** `compose.prod.yaml`:
  - usługi własne: `image: ghcr.io/dommmin/elastic-<nazwa>:${IMAGE_TAG}`, `build: !reset null`, `volumes: !override` (tylko nazwane wolumeny);
  - `catalog-app`: `SERVER_NAME: ":80"`, `APP_ENV: production`, `APP_DEBUG: "false"`, `APP_KEY`, `APP_URL: http://localhost:18080`, `VITE_DEV_SERVER` usunięty (`!reset`; składnię potwierdza `config`);
  - `search-consumer`: `APP_ENV: prod`, `APP_SECRET`;
  - `catalog-vite`: `profiles: [dev-only]`;
  - Kibana: `XPACK_*_ENCRYPTIONKEY` z `${KIBANA_*_KEY}`.
- [ ] **Krok 4:** `.env.prod.example` = `.env.example` + profil 24 GB + `IMAGE_TAG=main` + sekrety `__GENERATE__`; `gen-env.sh`: `openssl rand -hex 24`, klucze Kibany 32+ znaków, `APP_KEY=base64:$(openssl rand -base64 32)`, `chmod 600`.
- [ ] **Krok 5:** test → ✓.
- [ ] **Krok 6: Commit** `ETAP D [2/10]: compose.prod + generator sekretów`

### Task 3: GitHub Actions → GHCR

- [ ] **Krok 1:** faza `images` w `verify.sh` (uruchamiana z Maca, **bez logowania do GHCR**: `DOCKER_CONFIG=$(mktemp -d)`): dla 5 obrazów `docker manifest inspect ghcr.io/dommmin/elastic-<n>:<sha>` → kod 0 i platforma `linux/amd64`.
- [ ] **Krok 2:** → FAIL (obrazów jeszcze nie ma).
- [ ] **Krok 3:** `.github/workflows/images.yml`: trigger `push` na `main` (ścieżki `apps/**`, `infra/**`, `tests/relevance/**`, `.dockerignore`, workflow) + `workflow_dispatch`; `permissions: contents: read, packages: write`; akcje przypięte do SHA (skill `github-actions-hardening`); wersje wczytane z `.env.example`; macierz 5 obrazów; `docker/build-push-action` z `platforms: linux/amd64`, cache `type=gha`, tagi `<sha>` i `main`; przed pushem `prod-image-check.sh`.
- [ ] **Krok 4 (Ty):** zgoda na `git push origin main`.
- [ ] **Krok 5 (Ty):** po pierwszym przebiegu: GitHub → Packages → każda z 5 paczek → *Change visibility* → **Public**.
- [ ] **Krok 6:** `verify.sh images` → ✓. Czas builda (zimny i z cache) → POMIARY.
- [ ] **Krok 7: Commit** `ETAP D [3/10]: CI buduje obrazy do GHCR`

### Task 4: Klucz SSH, alias i szkielet `verify.sh`

- [ ] **Krok 1:** `ssh-keygen -t ed25519 -f ~/.ssh/elastic_vps_ed25519 -C "elastic-vps"` — osobny klucz, do cofnięcia bez ruszania innych. Pokazuję Ci **tylko `.pub`**.
- [ ] **Krok 2:** `~/.ssh/config`: `Host elastic-vps` → `HostName <IP>`, `User root` (do Tasku 6), `IdentityFile`, `IdentitiesOnly yes`, `ServerAliveInterval 30`.
- [ ] **Krok 3:** faza `access`: `ssh elastic-vps true`; `nproc ≥ 4`; `MemTotal ≥ 23 GB`; wolne na `/` ≥ 140 GB; `uname -m = x86_64`; Ubuntu.
- [ ] **Krok 4:** → FAIL (serwera jeszcze nie ma).
- [ ] **Krok 5: Commit** `ETAP D [4/10]: verify.sh + alias SSH`

---
## FAZA 1 — serwer

### Task 5 (Ty): zakup

- [ ] Kupujesz VPS wg wymagań i przy tworzeniu wklejasz `elastic_vps_ed25519.pub`. Hasło roota (jeśli jest) zachowujesz dla siebie, do konsoli ratunkowej dostawcy.
- [ ] Podajesz IP → `verify.sh access` → ✓. Jeśli RAM < 23 GB, zmieniamy profil ES wg tabeli.

### Task 6: Zabezpieczenie systemu

- [ ] **Krok 1:** fazy `hardening` i `exposure`:
  - `hardening`: logowanie jako `deploy` działa, jako `root` jest odrzucane; `sshd -T` → `passwordauthentication no`, `permitrootlogin no`; `ufw` aktywny, jedyna reguła `22/tcp`; `fail2ban-client status sshd` OK; `unattended-upgrades` włączone; `vm.max_map_count = 262144`; `vm.swappiness = 1`; strefa `Europe/Warsaw`.
  - `exposure` (z Maca): `nc -z -w2 <IP>` dla `80 443 5432 5601 5672 6379 8080 8443 9200 9201 9202 15672` → wszystkie zamknięte, `22` otwarty.
- [ ] **Krok 2:** → FAIL.
- [ ] **Krok 3:** `bootstrap.sh` (idempotentny, jako root): `apt full-upgrade`; użytkownik `deploy` z kluczem z `/root/.ssh/authorized_keys`, `sudo` bez hasła (świadomy kompromis na potrzeby automatyzacji, opisany w przewodniku); `sshd_config.d/10-hardening.conf`; **`sshd -t` przed reloadem**; `ufw default deny incoming` + `allow 22/tcp`; `fail2ban`; `unattended-upgrades` (tylko security, bez automatycznego restartu); `/etc/sysctl.d/99-elasticsearch.conf`; swap 2 GB jako bezpiecznik; strefa czasowa; `/opt/marketplace` dla `deploy`.
- [ ] **Krok 4 (bezpiecznik):** sesja roota zostaje otwarta → w drugiej sesji `ssh deploy@… sudo true` → dopiero wtedy alias przechodzi na `User deploy`.
- [ ] **Krok 5:** `hardening` i `exposure` → ✓.
- [ ] **Krok 6: Commit** `ETAP D [5/10]: hardening serwera`

### Task 7: Docker

- [ ] **Krok 1:** faza `docker`: `docker version` jako `deploy` bez sudo; `docker compose version ≥ 2.24` (tagi `!reset`/`!override`); `json-file`, `max-size=10m`, `max-file=3`; `hello-world` OK; zajętość `/` < 80%.
- [ ] **Krok 2:** → FAIL.
- [ ] **Krok 3:** `install-docker.sh`: oficjalne repo `download.docker.com` (nie paczka `docker.io` z Ubuntu), `docker-ce`, `containerd.io`, `docker-compose-plugin`; `daemon.json`; `deploy` w grupie `docker`.
- [ ] **Krok 4:** `docker` ✓ i `exposure` dalej ✓.
- [ ] **Krok 5: Commit** `ETAP D [6/10]: Docker Engine z rotacją logów`

### Task 8: Pierwsze wdrożenie

- [ ] **Krok 1:** faza `stack` (przez SSH):
  - na serwerze nie ma `.git` ani `apps/` w `/opt/marketplace`;
  - `docker compose images` → wszystkie obrazy własne z `ghcr.io/dommmin/elastic-*:<IMAGE_TAG>`;
  - `_cat/nodes` → 3 nody, `_cluster/health` → `green`;
  - `tools/smoke-test.sh` przez `ssh elastic-vps 'cd /opt/marketplace && set -a && . ./.env && set +a && COMPOSE_FILE=compose.yaml:compose.prod.yaml bash -s' < tools/smoke-test.sh` → `FAIL=0`;
  - `make prod-eval` → `nDCG@10 = 0.967`;
  - end-to-end: zmiana ceny produktu w catalog → po ≤ 10 s nowa cena w ES (outbox → RabbitMQ → search-consumer → ES);
  - brak kontenerów `unhealthy`/`restarting`; suma `MemUsage` < 80% RAM.
- [ ] **Krok 2:** → FAIL.
- [ ] **Krok 3:** `make prod-deploy tag=<sha z Tasku 3>` → migracje catalog i search (`docker compose exec`) → `search:index:create` → `make prod-seed n=1500`. Sekrety powstają tylko na serwerze i nie wyświetlam ich w czacie.
- [ ] **Krok 4:** `stack` ✓; czas od `prod-deploy` do `green` → POMIARY.
- [ ] **Krok 5: Commit** `ETAP D [7/10]: pierwsze wdrożenie z GHCR`

### Task 9: Dostęp tylko dla Ciebie — tunel SSH

- [ ] **Krok 1:** `Host elastic-vps-tunnel` z `LocalForward`: `18080 → 127.0.0.1:8080` (catalog), `15601 → 5601` (Kibana), `25672 → 15672` (RabbitMQ UI). Porty lokalne są przesunięte, żeby nie kolidowały z lokalnym stackiem ani z igrit.
- [ ] **Krok 2:** `make vps-tunnel` → w przeglądarce: wyszukiwarka zwraca wyniki i facety; Kibana loguje użytkownikiem `elastic`; RabbitMQ UI pokazuje `search.product.sync` z konsumentem. Sprawdzam w przeglądarce aplikacji i robię zrzuty do przewodnika.
- [ ] **Krok 3:** `exposure` dalej ✓.
- [ ] **Krok 4: Commit** `ETAP D [8/10]: tunel SSH do UI`

### Task 10: Nowa wersja, rollback, backup, restart („zepsuj i napraw")

- [ ] **Krok 1:** fazy `backup` i `reboot`:
  - `backup`: repozytorium snapshotów `fs` (`/snapshots`), polityka SLM `nightly`, ostatni snapshot `SUCCESS`; timer `marketplace-pg-backup` aktywny; dump < 26 h w `/var/backups/marketplace/`.
  - `reboot`: `sudo reboot` → po ≤ 5 min `green`, 3 nody, smoke ✓, `eval = 0.967`.
- [ ] **Krok 2:** → FAIL.
- [ ] **Krok 3: Cykl wersji:** drobna widoczna zmiana (np. tekst w UI) → push → CI → `make prod-deploy tag=<nowy>` → zmiana widoczna przez tunel → `make prod-deploy tag=<stary>` (rollback) → zmiany nie ma → powrót na nowy tag.
- [ ] **Krok 4:** SLM (03:00, retencja 7), timer `pg_dump -Fc` (03:30, retencja 7 dni), `make vps-backup-pull` (rsync na Maca — kopia poza serwerem).
- [ ] **Krok 5: Odtwarzanie:** usuwam `products-v1` → restore ze snapshotu → `eval = 0.967`; `pg_restore` dumpu do bazy testowej → liczba produktów zgodna.
- [ ] **Krok 6: Restart:** `verify.sh reboot` → ✓. Każdy problem (np. powrót #021) → diagnoza + wpis w RUNBOOK.
- [ ] **Krok 7: Commit** `ETAP D [9/10]: rollback, backupy, test restartu`

### Task 11: Pomiary i dokumentacja (DoD)

- [ ] **POMIARY:** p50/p95 wyszukiwania VPS vs Mac (`tools/bench`), czasy CI i deployu, RAM per kontener.
- [ ] **RUNBOOK:** wpis dla każdego realnego problemu z Tasków 1–10 (min. 3, zasada G-4).
- [ ] **`docs/blog/etap-d-vps.md`** w stylu `etap-07-wyszukiwarka.md`: każdy krok to *co → dlaczego tak, a nie inaczej → komendy → jak sprawdzić*. Obowiązkowe sekcje: „Kod czy obraz — dlaczego serwer nie robi `git pull`", „Docker a ufw", „Tunel SSH zamiast publicznego portu", „`config:cache` w buildzie — cicha pułapka", „Sekret w publicznym obrazie", „Restart klastra".
- [ ] README (status), `06-PLAN-WDROZENIA.md` CZĘŚĆ E (odsyłacz tutaj).
- [ ] `verify.sh all` → `FAIL=0`, wynik w przewodniku.
- [ ] **Commit** `ETAP D [10/10]: przewodnik, RUNBOOK, POMIARY`

**DoD D1:** `verify.sh all` zielony + przeczytany przewodnik + **sam** wykonujesz z przewodnika wdrożenie
nowego tagu i rollback bez mojej pomocy.

---

## FAZA D2 — k3s (zarys; szczegółowy plan po DoD D1)

Te same obrazy z GHCR, ale uruchamiane przez Kubernetesa na tym samym VPS-ie (Compose zatrzymany, dane
przeniesione). D1 jest punktem odniesienia.

| Krok | Treść | Czego uczy |
|---|---|---|
| D2.1 | k3s single-node; API 6443 tylko na localhost, `kubectl` z Maca przez tunel | control plane, kubeconfig |
| D2.2 | **ECK**: `Elasticsearch` (3 nody, obraz `elastic-elasticsearch` z pluginami) + `Kibana` jako CRD | operator a ręczny compose: certy, hasła, rolling upgrade |
| D2.3 | Postgres (CloudNativePG) i RabbitMQ (Cluster Operator) albo `StatefulSet` dla porównania | StatefulSet, PVC, operatorzy |
| D2.4 | catalog i search-consumer jako `Deployment`, `Secret`/`ConfigMap`, probe'y z healthchecków | liveness/readiness vs healthcheck Compose |
| D2.5 | Migracja danych: snapshot ES → restore w ECK; `pg_dump` → restore | migracja bez utraty danych |
| D2.6 | `verify.sh` w wariancie `kubectl` (`0.967`, reboot, exposure) | parytet między platformami |
| D2.7 (opc.) | **GitOps**: Argo CD pobiera manifesty z gita, CI zmienia tylko tag obrazu | skąd git w Kubernetesie — manifesty, nie kod |
| D2.8 | Porównanie w POMIARACH: narzut RAM k3s, czas wdrożenia, zachowanie po restarcie | kiedy K8s ma sens |

Ryzyko: k3s + ECK zajmuje ~1–1,5 GB więcej → przy 24 GB ES może zejść do `ES_HEAP=1g`. Decyzja w planie D2.

---

## Szacunek czasu

| Część | Czas |
|---|---|
| Faza 0 (Task 1–4) | 1–1,5 dnia, **przed** zakupem |
| Task 5 (Ty) | 15 min + ustawienie paczek na Public |
| Task 6–9 | ~0,5 dnia |
| Task 10–11 | 0,5–1 dnia |
| D2 | 2–4 dni |
