# 08 — ETAP D: wdrożenie na VPS (Compose → k3s)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Cel:** cały stack (klaster ES 3-nodowy, Kibana, Postgres, Redis, RabbitMQ, catalog, search-consumer)
działa na prywatnym VPS-ie, dostępny **tylko dla właściciela**, z weryfikacją każdego kroku i przewodnikiem
„jak to zrobiłem i jak to działa".

**Architektura:** ten sam `compose.yaml` co lokalnie + nakładka `compose.prod.yaml` (obrazy `prod`,
bez bind-mountów kodu, bez Vite). **Żaden port poza SSH nie jest wystawiony do internetu** — wszystkie
porty usług są już dziś bindowane na `127.0.0.1`, a do UI dostajesz się tunelem SSH
(`ssh -L`). Bez domeny, bez publicznego HTTPS, bez reverse proxy na zewnątrz.

**Fazy:**
- **D1 (ten plan, szczegółowo)** — VPS + Docker Compose. Szybko do działającego stanu i punkt odniesienia.
- **D2 (zarys na końcu, osobny plan po DoD D1)** — migracja tego samego stacku na k3s.

**Stack:** Ubuntu LTS, Docker Engine + Compose v2, ufw, fail2ban, unattended-upgrades, systemd timers.

**Zastępuje:** punkt „❌ deploy produkcyjny" z `06-PLAN-WDROZENIA.md` CZĘŚĆ E — po DoD D1 aktualizujemy tamten wpis.

---

## Kto co robi

| Ty (tylko Ty możesz) | Claude (przez SSH z tej sesji) |
|---|---|
| Kupujesz serwer, zakładasz konto, płacisz | Przygotowuje repo przed zakupem (Faza 0) |
| Wklejasz **publiczny** klucz SSH w panelu dostawcy | Zabezpiecza system, instaluje Dockera, wdraża stack |
| Podajesz IP serwera | Uruchamia weryfikację po każdym kroku i pokazuje wynik |
| Zatwierdzasz każdą komendę (tryb uprawnień) | Pisze przewodnik, RUNBOOK, POMIARY |
| Zgoda na `git push` przed Taskiem 6 | — |

Hasła nigdy nie przechodzą przez czat: sekrety generuje skrypt **na serwerze**, a dostęp jest wyłącznie
po kluczu.

## Wymagania sprzętowe (do zakupu)

| Parametr | Wartość | Dlaczego |
|---|---|---|
| RAM | **24 GB** (minimum 16 GB, wtedy 1 node ES) | suma `mem_limit` niżej |
| vCPU | 6–8 | build obrazów PHP + 3 JVM |
| Dysk | ≥ 150 GB NVMe | obrazy, 3× dane ES, snapshoty, backupy PG |
| Architektura | **x86_64** | wszystkie obrazy mają amd64; ARM = dodatkowa niewiadoma |
| System | **Ubuntu 24.04 LTS** (lub 26.04 LTS, jeśli dostawca ma i Docker oficjalnie wspiera) | repo Dockera, długie wsparcie |

**Profil ES w zależności od RAM** (wartości do `.env` na serwerze):

| RAM VPS | Tryb | `ES_HEAP` | `ES_MEM_LIMIT` | Suma limitów stacku |
|---|---|---|---|---|
| 16 GB | 1 node (bez `--profile cluster`) | 2g | 4g | ~11,5 GB |
| **24 GB** | **3 nody** | **1500m** | **3g** | **~17 GB** |
| 32 GB | 3 nody (jak `01-INFRASTRUKTURA.md`) | 3g | 6g | ~26 GB |

---

## Global Constraints

- Wszystkie porty usług bindowane wyłącznie na `127.0.0.1` (jak w `compose.yaml`); z internetu otwarty tylko `22/tcp`.
- Logowanie SSH wyłącznie kluczem; `PermitRootLogin no`, `PasswordAuthentication no`.
- Repo `Dommmin/elastic` jest **PUBLICZNE** → żaden plik z sekretem nie trafia do gita ani do obrazu Dockera.
- Wersje usług bez zmian względem `.env.example` (ES/Kibana 9.5.1, Postgres 18.4, RabbitMQ 4.3.4, Redis 8.8.1, FrankenPHP 1.12.7, PHP 8.5, Node 24.19.0).
- Polecenia na serwerze: `docker compose -f compose.yaml -f compose.prod.yaml --profile cluster --profile apps …` — opakowane w `make prod-*`.
- Stan docelowy po restarcie serwera: wszystko wstaje samo (`restart: unless-stopped`), klaster ES `green`.
- Weryfikacja przyjęcia: `nDCG@10 = 0.967` z `make eval` (seed deterministyczny, `fake()->seed(42)`) — ta sama liczba co lokalnie w `POMIARY.md`.
- DoD etapu (pamięć projektu): commity w repo + przewodnik krok po kroku w `docs/blog/etap-d-vps.md`.

## Review Focus

Rzeczy, których zwykłe testy nie złapią, a które najpewniej ugryzą:

1. **Docker omija ufw.** Port opublikowany jako `0.0.0.0:X` jest dostępny z internetu mimo `ufw deny`. → `verify.sh exposure` skanuje porty z Maca (Task 4, 7).
2. **Restart serwera = deadlock klastra** (RUNBOOK #021) albo usługi, które nie wstają. → test `reboot` (Task 8).
3. **Zapchany dysk** przez logi kontenerów i snapshoty. → rotacja logów w `daemon.json` + próg `df` w `verify.sh docker` (Task 5).
4. **Odcięcie się od serwera** przy zmianie konfiguracji `sshd`. → nowa sesja testowana *przed* zamknięciem starej, `sshd -t` przed reloadem (Task 4).
5. **Sekret w obrazie lub w gicie** (brak `.dockerignore`, `COPY apps/catalog/` kopiuje `.env`). → test „w obrazie nie ma `.env`" (Task 1).

---

## Mapa plików

| Plik | Odpowiedzialność |
|---|---|
| `.dockerignore` (nowy) | Nie wpuszcza `.env`, `vendor/`, `node_modules/`, `public/build`, `.git` do kontekstu buildu |
| `infra/php/catalog.Dockerfile` (zmiana) | `config/route/view:cache` przeniesione z buildu do startu kontenera |
| `infra/php/catalog-entrypoint.sh` (nowy) | Cache Laravela przy starcie, potem `exec frankenphp run` |
| `compose.prod.yaml` (nowy) | Nakładka: target `prod`, `!override` wolumenów, Vite wyłączony, klucze Kibany z env |
| `.env.prod.example` (nowy) | Szablon `.env` na serwer: profil 24 GB, `APP_ENV=production`, sekrety jako `__GENERATE__` |
| `tools/vps/gen-env.sh` (nowy) | Na serwerze: `.env.prod.example` → `.env` z losowymi sekretami (`openssl rand`) |
| `tools/vps/verify.sh` (nowy) | Z Maca: `verify.sh <faza>`, wynik ✓/✗ jak w `smoke-test.sh`, kod wyjścia ≠ 0 przy błędzie |
| `tools/vps/bootstrap.sh` (nowy) | Na serwerze, jako root: użytkownik, sshd, ufw, fail2ban, sysctl, aktualizacje |
| `tools/vps/install-docker.sh` (nowy) | Na serwerze: Docker Engine z oficjalnego repo + `daemon.json` |
| `infra/systemd/marketplace-pg-backup.{service,timer}` (nowe) | Codzienny `pg_dump`, retencja 7 dni |
| `Makefile` (zmiana) | `prod-up`, `prod-down`, `prod-logs`, `prod-ps`, `vps-tunnel`, `vps-backup-pull` |
| `docs/blog/etap-d-vps.md` (nowy) | Przewodnik krok po kroku (DoD) |
| `docs/RUNBOOK.md`, `docs/POMIARY.md`, `README.md`, `docs/06-PLAN-WDROZENIA.md` (zmiany) | Wpisy, liczby, status, granice zakresu |

**Interfejs `tools/vps/verify.sh`:** `verify.sh <faza> [--host elastic-vps]`, gdzie faza ∈
`access | hardening | exposure | docker | stack | backup | reboot | all`. Łączy się przez alias SSH
`elastic-vps` (z `~/.ssh/config`). Wypisuje `✓/✗ opis`, na końcu `PASS=n FAIL=m`, `exit 1` gdy `FAIL>0`.
Każdy task najpierw dopisuje swoją fazę do `verify.sh` (czerwona), potem ją zazielenia.

---

## FAZA 0 — przygotowanie lokalnie (przed zakupem, 0 zł)

### Task 1: Obrazy `prod` budują się z czystego klonu i nie zawierają sekretów

Dziś obrazy `prod` nigdy nie były uruchamiane. Analiza Dockerfile'ów pokazała cztery problemy, które
wywróciłyby wdrożenie:

1. **Brak `.dockerignore`** — `COPY apps/catalog/ ./` wkleja do obrazu lokalny `.env` (sekrety), `vendor/` i `node_modules/` z macOS.
2. **`php artisan config:cache` w czasie buildu** — w buildzie nie ma zmiennych z compose, więc cache zapamięta wartości domyślne (`DB_CONNECTION=sqlite`!). Runtime env byłby ignorowany — ten sam błąd, co opisany przy `DB_CONNECTION` w `compose.yaml`, tylko ukryty w cache.
3. **`marketplace:seed` używa `fake()`**, a obraz `prod` instaluje `composer --no-dev` → brak Fakera, seed nie zadziała na serwerze.
4. **Symfony bez `apps/search/.env`** (plik gitignorowany) — sprawdzić, czy `bin/console` startuje w czystym klonie z samymi zmiennymi środowiskowymi.

**Files:**
- Create: `.dockerignore`, `infra/php/catalog-entrypoint.sh`, `compose.prod.yaml`, `.env.prod.example`, `tools/vps/gen-env.sh`
- Modify: `infra/php/catalog.Dockerfile:129-145`, `apps/catalog/composer.json` (faker → `require`), ewentualnie `infra/php/search.Dockerfile:60-75`
- Test: `tools/vps/prod-image-check.sh` (lokalny, z czystego klonu w scratchpadzie)

**Interfaces:**
- Produces: obrazy `marketplace/catalog:prod`, `marketplace/search:prod`; `compose.prod.yaml` (używany przez `make prod-*` w Tasku 6); `gen-env.sh <szablon> <cel>` — nie nadpisuje istniejącego celu, kod wyjścia 1 gdy cel istnieje.

- [ ] **Krok 1: Napisz `tools/vps/prod-image-check.sh` (test, ma się wysypać)**

Robi `git clone` repo do katalogu tymczasowego (= dokładnie to, co zobaczy serwer: zero plików ignorowanych), buduje oba targety `prod` i sprawdza:

```bash
check "w obrazie catalog nie ma .env"        "0"      "$(docker run --rm --entrypoint sh $IMG_C -c 'ls -a /app | grep -c "^\.env$"')"
check "w obrazie nie ma node_modules"        "0"      "$(docker run --rm --entrypoint sh $IMG_C -c 'test -d /app/node_modules && echo 1 || echo 0')"
check "config czyta env w runtime"           "pgsql"  "$(docker run --rm -e DB_CONNECTION=pgsql -e APP_KEY=base64:$(openssl rand -base64 32) $IMG_C php artisan tinker --execute='echo config(\"database.default\");')"
check "Faker dostępny w prod (seed)"         "ok"     "$(docker run --rm --entrypoint php $IMG_C -r 'require "vendor/autoload.php"; echo class_exists("Faker\\Factory") ? "ok" : "brak";')"
check "search: bin/console startuje bez .env" "0"     "$(docker run --rm -e APP_ENV=prod -e APP_SECRET=x -e DATABASE_URL=... $IMG_S php bin/console about >/dev/null 2>&1; echo $?)"
```

- [ ] **Krok 2: Uruchom — oczekiwane FAIL** na `.env` w obrazie, `config czyta env` (zwróci `sqlite`) i Fakerze.

- [ ] **Krok 3: Napraw**
  - `.dockerignore`: `**/.env`, `**/.env.local`, `**/vendor`, `**/node_modules`, `apps/*/public/build`, `apps/*/public/hot`, `apps/*/var`, `apps/*/storage/logs/*`, `.git`, `.remember`, `.claude`, `docs`. **Wyjątki** `!apps/search/.env.dev`/`.env.test` tylko jeśli Krok 1 pokaże, że Symfony ich potrzebuje.
  - `catalog-entrypoint.sh`: `php artisan config:cache && route:cache && view:cache && exec frankenphp run --config /etc/frankenphp/Caddyfile`; w Dockerfile `ENTRYPOINT` zamiast `RUN … config:cache`.
  - `fakerphp/faker` przenieść do `require` z komentarzem w `README`, dlaczego (seed to w tym projekcie operacja „produkcyjna").
  - Symfony: jeśli `bootEnv` wymaga pliku — w obrazie `prod` utwórz pusty `.env` (`RUN touch .env`) — wartości przyjdą ze zmiennych środowiskowych.

- [ ] **Krok 4: Napisz `compose.prod.yaml`**
  - `catalog-app`: `build.target: prod`, `volumes: !override [caddy-data:/data, caddy-config:/config, ./tests/relevance:/tests/relevance:ro]`, `SERVER_NAME: ":80"`, `APP_KEY`, `APP_URL: http://localhost:${CATALOG_HTTP_PORT}`, `VITE_DEV_SERVER` usunięty tagiem `!reset` (dokładną składnię dla pojedynczego klucza `environment` potwierdza `docker compose config` w Kroku 6).
  - `search-consumer`: `build.target: prod`, `volumes: !override [./infra/elasticsearch/mappings:/infra/elasticsearch/mappings:ro]`, `APP_ENV: prod`, `APP_SECRET`.
  - `catalog-vite`: `profiles: [dev-only]` (nigdy nie startuje na serwerze).
  - `kibana`: trzy `XPACK_*_ENCRYPTIONKEY` z `${KIBANA_*_KEY}` zamiast stałych.
  - Wymaga Compose ≥ 2.24 (tagi `!override`/`!reset`) — sprawdzane w `verify.sh docker`.

- [ ] **Krok 5: `.env.prod.example` + `gen-env.sh`.** Szablon = `.env.example` + profil 24 GB z tabeli wyżej + `APP_ENV=production`, `APP_DEBUG=false`; każdy sekret ma wartość `__GENERATE__`. `gen-env.sh` zamienia każde `__GENERATE__` na `openssl rand -hex 24` (klucze Kibany: 32+ znaki; `APP_KEY`: `base64:` + 32 bajty), `chmod 600`.

- [ ] **Krok 6: Uruchom `prod-image-check.sh` — oczekiwane: wszystkie ✓.** Dodatkowo `docker compose -f compose.yaml -f compose.prod.yaml --profile cluster --profile apps config >/dev/null` → kod 0. (Pełnego stacku prod lokalnie nie uruchamiamy — maszyna jest współdzielona z innymi projektami.)

- [ ] **Krok 7: Lokalny dev nadal działa:** `make up-apps && make smoke && make eval` → smoke zielony, `nDCG@10 = 0.967`.

- [ ] **Krok 8: Commit** `ETAP D [1/9]: obrazy prod z czystego klonu — .dockerignore, cache w runtime, compose.prod`

### Task 2: Klucz SSH, alias i szkielet `verify.sh`

**Files:** Create: `tools/vps/verify.sh`; Modify: `Makefile` (`vps-verify`, `vps-tunnel`); poza repo: `~/.ssh/elastic_vps_ed25519`, wpis w `~/.ssh/config`.

- [ ] **Krok 1:** `ssh-keygen -t ed25519 -f ~/.ssh/elastic_vps_ed25519 -C "elastic-vps $(date +%F)"` — osobny klucz, do cofnięcia bez ruszania Twoich innych kluczy. Publiczną część (`.pub`) pokazuję Ci do wklejenia w panelu dostawcy.
- [ ] **Krok 2:** `~/.ssh/config`: `Host elastic-vps` → `HostName <IP>`, `User root` (do Tasku 4, potem `deploy`), `IdentityFile ~/.ssh/elastic_vps_ed25519`, `IdentitiesOnly yes`, `ServerAliveInterval 30`.
- [ ] **Krok 3:** `verify.sh` ze wspólnymi `check`/`contains` (skopiowane z `tools/smoke-test.sh`) i fazą `access`: `ssh elastic-vps true` → 0; `nproc` ≥ 6; `MemTotal` ≥ 23 GB; `/` ≥ 140 GB wolnego; `uname -m` = `x86_64`; `/etc/os-release` zawiera `Ubuntu`.
- [ ] **Krok 4:** `make vps-verify faza=access` → **FAIL** (serwera jeszcze nie ma) — to jest test czerwony.
- [ ] **Krok 5: Commit** `ETAP D [2/9]: verify.sh + alias SSH`

---

## FAZA 1 — serwer

### Task 3 (Ty): zakup

- [ ] Kupujesz VPS wg tabeli wymagań; przy tworzeniu wklejasz `elastic_vps_ed25519.pub`; **nie** ustawiasz hasła roota (albo ustawiasz i zachowujesz dla siebie — do konsoli ratunkowej dostawcy).
- [ ] Podajesz mi IP. Uzupełniam `HostName`.
- [ ] `make vps-verify faza=access` → **wszystkie ✓**. Jeśli RAM < 23 GB, przełączamy profil ES wg tabeli (16 GB → 1 node).

### Task 4: Zabezpieczenie systemu

**Files:** Create: `tools/vps/bootstrap.sh`; Modify: `tools/vps/verify.sh` (fazy `hardening`, `exposure`).

- [ ] **Krok 1:** dopisz fazy do `verify.sh`:
  - `hardening`: logowanie jako `deploy` działa; jako `root` — odrzucone; `sshd -T` zawiera `passwordauthentication no` i `permitrootlogin no`; `ufw status` = `active`, jedyna reguła `22/tcp`; `fail2ban-client status sshd` działa; `unattended-upgrades` włączone; `sysctl vm.max_map_count` = `262144`; `sysctl vm.swappiness` = `1`; strefa `Europe/Warsaw`.
  - `exposure`: z **Maca** `nc -z -w2 <IP> <port>` dla `9200 9201 9202 5601 5432 6379 5672 15672 8080 8443 80 443` → wszystkie zamknięte; `22` otwarty.
- [ ] **Krok 2:** `verify.sh hardening` → FAIL.
- [ ] **Krok 3:** `bootstrap.sh` (idempotentny, uruchamiany jako root): `apt full-upgrade`; użytkownik `deploy` z kluczem skopiowanym z `/root/.ssh/authorized_keys`, `sudo` bez hasła (świadomy kompromis: automatyzacja z tej sesji; zapisane w przewodniku); `sshd_config.d/10-hardening.conf`; **`sshd -t` przed `systemctl reload ssh`**; `ufw default deny incoming`, `allow 22/tcp`, `enable`; `fail2ban` (jail `sshd`); `unattended-upgrades` (tylko security, bez automatycznego restartu); `/etc/sysctl.d/99-elasticsearch.conf` (`vm.max_map_count=262144`, `vm.swappiness=1`); swap 2 GB jako bezpiecznik; `timedatectl set-timezone Europe/Warsaw`; pakiety `git make python3 jq`.
- [ ] **Krok 4 (bezpiecznik przed odcięciem):** sesja roota zostaje otwarta → w **drugiej** sesji `ssh deploy@elastic-vps sudo true` → dopiero gdy działa, alias przełączam na `User deploy`, a sesję roota zamykam.
- [ ] **Krok 5:** `verify.sh hardening` i `verify.sh exposure` → wszystkie ✓.
- [ ] **Krok 6: Commit** `ETAP D [3/9]: hardening serwera — deploy, sshd, ufw, sysctl pod ES`

### Task 5: Docker

**Files:** Create: `tools/vps/install-docker.sh`, `infra/docker/daemon.json`; Modify: `verify.sh` (faza `docker`).

- [ ] **Krok 1:** faza `docker`: `docker version` jako `deploy` (bez sudo); `docker compose version` ≥ `2.24`; `docker info` → `Logging Driver: json-file` + `max-size=10m`, `max-file=3`; `docker run --rm hello-world` → 0; `df /` użycie < 80%.
- [ ] **Krok 2:** → FAIL.
- [ ] **Krok 3:** `install-docker.sh`: oficjalne repo `download.docker.com` (nie paczka `docker.io` z Ubuntu), `docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin`, `daemon.json` z rotacją logów, `usermod -aG docker deploy`.
- [ ] **Krok 4:** → wszystkie ✓, a `verify.sh exposure` dalej zielony (Docker nie otworzył niczego na świat).
- [ ] **Krok 5: Commit** `ETAP D [4/9]: Docker Engine z rotacją logów`

### Task 6: Stack na serwerze

**Files:** Modify: `Makefile` (`prod-up`, `prod-down`, `prod-ps`, `prod-logs`, `prod-seed`, `prod-eval`), `verify.sh` (faza `stack`).

- [ ] **Krok 0 (Ty):** zgoda na `git push origin main` — serwer pobiera kod z publicznego GitHuba.
- [ ] **Krok 1:** faza `stack` (komendy przez `ssh elastic-vps`):
  - `_cat/nodes` → 3 nody; `_cluster/health` → `green`;
  - `make smoke` na serwerze → `FAIL=0`;
  - `make prod-eval` → `nDCG@10` = `0.967`;
  - end-to-end: zmiana ceny produktu w catalog → po ≤ 10 s nowa cena w `GET products/_doc/<id>` (outbox → RabbitMQ → search-consumer → ES);
  - `docker compose ps` → zero kontenerów `unhealthy`/`restarting`; suma `MemUsage` < 80% RAM.
- [ ] **Krok 2:** → FAIL.
- [ ] **Krok 3:** na serwerze: `git clone https://github.com/Dommmin/elastic.git /opt/marketplace` → `tools/vps/gen-env.sh .env.prod.example .env` (sekrety powstają **tylko tam**; nie wyświetlam ich w czacie) → `python3 tools/render-rabbitmq-definitions.py` → `make prod-up` (build + `up -d`) → migracje catalog i search → `search:index:create` → `make prod-seed n=1500`.
- [ ] **Krok 4:** `verify.sh stack` → wszystkie ✓. Czasy buildu i startu zapisuję do POMIARÓW.
- [ ] **Krok 5: Commit** `ETAP D [5/9]: stack na VPS — make prod-*, weryfikacja stack`

### Task 7: Dostęp tylko dla Ciebie — tunel SSH

**Files:** Modify: `Makefile` (`vps-tunnel`), `~/.ssh/config` (`LocalForward`).

- [ ] **Krok 1:** `Host elastic-vps-tunnel` z `LocalForward` dla: `18080 → 127.0.0.1:8080` (catalog), `15601 → 5601` (Kibana), `25672 → 15672` (RabbitMQ UI); porty lokalne przesunięte, żeby nie kolidowały z lokalnym stackiem ani projektem igrit.
- [ ] **Krok 2:** `make vps-tunnel` → w przeglądarce: `http://localhost:18080/search?q=…` pokazuje wyniki i facety; Kibana loguje użytkownikiem `elastic`; RabbitMQ UI pokazuje kolejkę `product_sync` z konsumentem. Sprawdzam to w przeglądarce w aplikacji i robię zrzuty do przewodnika.
- [ ] **Krok 3:** `verify.sh exposure` → dalej ✓ (tunel niczego nie wystawia).
- [ ] **Krok 4: Commit** `ETAP D [6/9]: tunel SSH do UI`

### Task 8: Backup, odtwarzanie, restart („zepsuj i napraw")

**Files:** Create: `infra/systemd/marketplace-pg-backup.service`, `.timer`; Modify: `Makefile` (`vps-backup-pull`), `verify.sh` (fazy `backup`, `reboot`).

- [ ] **Krok 1:** fazy:
  - `backup`: repozytorium snapshotów `fs` zarejestrowane (`/snapshots`); polityka SLM `nightly` istnieje, ostatni snapshot `SUCCESS`; `systemctl list-timers` zawiera `marketplace-pg-backup`; w `/var/backups/marketplace/` jest dump < 26 h.
  - `reboot`: `sudo reboot` → czekaj na SSH → po ≤ 5 min `_cluster/health` = `green`, 3 nody, `smoke` zielony, `eval` = `0.967`.
- [ ] **Krok 2:** → FAIL.
- [ ] **Krok 3:** SLM (codziennie 03:00, retencja 7), timer `pg_dump -Fc` (03:30, retencja 7 dni), `make vps-backup-pull` (rsync dumpów i snapshotów na Maca — kopia poza serwerem).
- [ ] **Krok 4: Ćwiczenie odtwarzania:** usuwam indeks `products-v1` → odtwarzam ze snapshotu → `eval` = `0.967`. Usuwam bazę `catalog` w kontenerze testowym → `pg_restore` z dumpu → liczba produktów zgodna.
- [ ] **Krok 5: Ćwiczenie restartu:** `verify.sh reboot` → ✓. Jeśli wróci deadlock z RUNBOOK #021 albo coś innego nie wstanie — diagnoza i wpis do RUNBOOK.
- [ ] **Krok 6: Commit** `ETAP D [7/9]: backupy ES/PG, odtwarzanie, test restartu`

### Task 9: Pomiary i dokumentacja (DoD)

- [ ] **POMIARY.md:** latencja wyszukiwania (p50/p95 z `tools/bench`) VPS vs Mac; czas buildu; zużycie RAM per kontener (`docker stats`).
- [ ] **RUNBOOK.md:** wpisy dla każdego realnego problemu z Tasków 1–8 (co najmniej 3, wg zasady G-4).
- [ ] **`docs/blog/etap-d-vps.md`** — przewodnik w stylu `etap-07-wyszukiwarka.md`: każdy krok = *co robimy → dlaczego tak, a nie inaczej → komendy → jak sprawdzić*. Obowiązkowe sekcje: „Docker a ufw", „Dlaczego tunel SSH, a nie publiczny port", „config:cache w buildzie — cicha pułapka", „Co się dzieje przy restarcie klastra".
- [ ] **README** (tabela statusu), **`06-PLAN-WDROZENIA.md`** CZĘŚĆ E (deploy przestaje być poza zakresem → odsyłacz tutaj).
- [ ] `make vps-verify faza=all` → `FAIL=0`; wynik wklejony do przewodnika.
- [ ] **Commit** `ETAP D [8/9]: przewodnik, RUNBOOK, POMIARY`; **[9/9]** zarezerwowany na poprawki po Twojej lekturze przewodnika.

**DoD D1:** `verify.sh all` zielony + przewodnik przeczytany przez Ciebie + Ty sam wykonujesz z przewodnika
jedną operację (np. restart i odtworzenie snapshotu) bez mojej pomocy.

---

## FAZA D2 — k3s (zarys; szczegółowy plan po DoD D1)

Ten sam stack, ale w Kubernetesie, na tym samym VPS-ie (Compose zatrzymany, dane przeniesione). Cel:
zobaczyć, co Kubernetes daje, a co zabiera, na **znanym** systemie — z D1 jako punktem odniesienia.

| Krok | Treść | Czego uczy |
|---|---|---|
| D2.1 | k3s single-node, `kubectl` z Maca przez tunel SSH (API 6443 też tylko na localhost) | control plane, kubeconfig |
| D2.2 | Obrazy `prod` → rejestr (GHCR prywatny albo `k3s ctr images import`) | skąd klaster bierze obrazy |
| D2.3 | **ECK** (operator Elastica): `Elasticsearch` 3 nody + `Kibana` jako zasoby CRD | operator vs ręczny compose: certy, hasła, rolling upgrade |
| D2.4 | Postgres (CloudNativePG) i RabbitMQ (Cluster Operator) — albo prosty `StatefulSet` dla porównania | StatefulSet, PVC, operatorzy |
| D2.5 | catalog i search-consumer jako `Deployment`, sekrety w `Secret`, config w `ConfigMap`, probe'y z healthchecków | liveness/readiness vs healthcheck Compose |
| D2.6 | Migracja danych: snapshot ES → restore w ECK, `pg_dump` → restore | migracja bez utraty danych |
| D2.7 | Ta sama weryfikacja: `verify.sh` z wariantem `kubectl` (`nDCG@10 = 0.967`, reboot, exposure) | parytet między platformami |
| D2.8 | Porównanie w POMIARACH: RAM narzutu k3s, czas wdrożenia zmiany, zachowanie po restarcie | kiedy K8s ma sens, a kiedy nie |

Ryzyko: k3s + ECK zjada ~1–1,5 GB więcej → przy 24 GB ES może zejść do `ES_HEAP=1g`. Decyzja w planie D2.

---

## Szacunek czasu

| Część | Czas |
|---|---|
| Faza 0 (Task 1–2) | 0,5–1 dnia — można zrobić **przed** zakupem |
| Task 3 (Ty) | 15 min |
| Task 4–7 | ~0,5 dnia |
| Task 8–9 | 0,5–1 dnia |
| D2 | 2–4 dni |
