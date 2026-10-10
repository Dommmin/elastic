# 09 — ETAP D2: ten sam stack w Kubernetesie (k3s)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Cel:** stack z D1 (te same obrazy z GHCR) działa w Kubernetesie na tym samym VPS-ie,
przełączony z Compose bez utraty danych, z drogą powrotu. Do tego przewodnik:
co Kubernetes daje, a co zabiera w porównaniu z Compose — na znanym systemie.

**Architektura:**

```
                       VPS 185.238.74.58 (24 GB)
 ┌──────────────────────────────────────────────────────────────────┐
 │  Docker Compose (D1, "blue")      │  k3s (D2, "green")            │
 │  działa do przełączenia (Task 9)  │  namespace: marketplace       │
 │                                   │  ECK: Elasticsearch ×3, Kibana│
 │                                   │  StatefulSet: postgres,       │
 │                                   │    rabbitmq, redis            │
 │                                   │  Deployment: catalog-app,     │
 │                                   │    outbox-publisher,          │
 │                                   │    search-consumer            │
 │                                   │  Job: migracje, indeks        │
 │                                   │  CronJob: pg-backup           │
 └──────────────────────────────────────────────────────────────────┘
        ▲ SSH (22) — jedyny otwarty port
 Mac: tunel 26443 → API k3s (127.0.0.1:6443) → kubectl, k9s, port-forward do UI
```

**Tech stack:** k3s v1.36.5+k3s1 (kanał stable), ECK 3.5.0 (operator Elastica), Kustomize
(wbudowany w `kubectl`), local-path-provisioner (wbudowany w k3s). Bez Helma.

**Spec:** zarys D2 w `docs/08-PLAN-ETAP-D-VPS.md` + decyzje z rozmowy (k3s, nie kubeadm;
blue-green obok Compose).

## Decyzje (z uzasadnieniem)

| # | Decyzja | Dlaczego | Odrzucone |
|---|---|---|---|
| K-1 | **k3s**, nie kubeadm | to samo API i manifesty; ~0,5–1 GB zamiast ~2 GB na sam klaster; cel = workloady, nie budowa klastra | kubeadm (ewentualnie osobny etap D3 pod CKA) |
| K-2 | **Blue-green obok Compose** | D1 działa dalej (Twoje ćwiczenia), przełączenie po weryfikacji, powrót = `docker compose start` | wyłączenie Compose na starcie (brak drogi powrotu) |
| K-3 | **ECK** dla ES + Kibany | operator robi to, co w D1 robiły `es-init` + `es-setup` + healthchecki: certy, hasła, rolling restart | ES jako ręczny StatefulSet (dużo kodu, mało nauki o tym, jak się to robi naprawdę) |
| K-4 | Postgres, RabbitMQ, Redis jako **zwykłe StatefulSety** z obrazami z D1 | żeby zobaczyć „gołe" prymitywy: StatefulSet, PVC, headless Service, probe'y; operator już mamy w ES | CloudNativePG / RabbitMQ Cluster Operator — świetne, ale drugi i trzeci operator nie uczą niczego nowego |
| K-5 | **Kustomize**, bez Helma | `kubectl apply -k`, tag obrazów w jednym miejscu (`images:`); widać czysty YAML | Helm — warstwa szablonów zasłania to, czego się uczysz |
| K-6 | k3s **bez Traefika i ServiceLB** (`--disable traefik,servicelb`), API na `127.0.0.1` | nic nie słucha publicznie; dostęp tak jak w D1 — przez SSH | Ingress + publiczny port (D1 uzasadniło, dlaczego nie) |
| K-7 | Sekrety: **`Secret` tworzony na serwerze z istniejącego `/opt/marketplace/.env`** | te same hasła w obu stackach = migracja danych bez zmiany haseł; nic w gicie | Sealed Secrets / SOPS — osobny temat |
| K-8 | Migracje i indeks jako **`Job`**, pg-backup jako **`CronJob`** | k8s-owe odpowiedniki „kroku w deploy.sh" i timera systemd | init containers (migracja przy każdym starcie poda) |
| K-9 | Na czas blue-green ES w k3s: `heap 1g / limit 2g`; po przełączeniu `1500m / 3g` | oba stacki naraz ≈ 18–19 GB z 24 | — |

## Global Constraints

- Te same obrazy co D1: `ghcr.io/dommmin/elastic-{elasticsearch,postgres,rabbitmq,catalog,search}:<SHA>`.
- Namespace `marketplace`. Nazwy usług muszą pasować do konfiguracji obrazów: **Service `catalog-app`** (Caddyfile ma blok `http://catalog-app` dla wewnętrznego API), `postgres`, `rabbitmq`, `redis`.
- ES dla aplikacji: `http://marketplace-es-http:9200` (Service tworzony przez ECK), HTTP TLS wyłączony — parytet z D1.
- Z internetu otwarty wyłącznie `22/tcp` — także po k3s (skan z Maca obejmuje dodatkowo 6443, 10250, 30000–32767).
- Żaden sekret w gicie. `kubectl` z Maca tylko przez tunel SSH.
- Test przyjęcia jak w D1: ES green (3 nody), 1500 dokumentów, eval OK (zapytania o produkt = 1.000, średnia ≥ 0.80), E2E zmiana ceny ≤ 10 s, restart serwera → wszystko samo, eval identyczny.
- DoD: commity + przewodnik `docs/blog/etap-d2-k3s.md`.

## Review Focus

1. **k3s a ufw.** k3s pisze własne reguły iptables (jak Docker). NodePort (30000–32767) albo `hostPort` w manifeście = port w internecie mimo ufw. → `verify.sh exposure` rozszerzony, powtarzany po każdym tasku.
2. **Pod nie dosięga Service'u** przy ufw `default deny` — k3s wymaga zezwolenia na ruch z sieci podów (10.42.0.0/16) i usług (10.43.0.0/16). → test DNS + połączenia z poda w Task 1.
3. **Pamięć przy dwóch stackach naraz** — OOM killer zabije losowy kontener (w tym z Compose). → limity na każdym kontenerze w k8s, próg 85% w `verify.sh`, redukcja heapu (K-9).
4. **Job migracji przy zmianie tagu** — `Job` jest niezmienny: `kubectl apply` z nowym obrazem się nie uda. → deploy usuwa i tworzy Job od nowa, czeka na `Complete`.
5. **Kolizja nazw w ES** podczas restore'u ze snapshotu D1 (alias `products-search`, indeksy systemowe Kibany). → restore tylko `products-v1` z `include_aliases`.

---

## Mapa plików

| Plik | Odpowiedzialność |
|---|---|
| `deploy/k8s/kustomization.yaml` | lista zasobów, namespace, **tag obrazów** (`images:`), ConfigMap z `config.env` |
| `deploy/k8s/config.env` | niesekretna konfiguracja (hosty, porty, nazwy baz) — odpowiednik `environment:` z compose |
| `deploy/k8s/elasticsearch.yaml` | ECK: `Elasticsearch` (3 nody, obraz z pluginami, PVC, snapshoty), `Kibana`, role i użytkownicy aplikacji (file realm) |
| `deploy/k8s/postgres.yaml`, `rabbitmq.yaml`, `redis.yaml` | StatefulSet + headless Service + PVC |
| `deploy/k8s/catalog.yaml` | Deployment `catalog-app` + Service; Deployment `outbox-publisher` |
| `deploy/k8s/search-consumer.yaml` | Deployment |
| `deploy/k8s/jobs/migrate.yaml` | Job: migracje catalog + search, indeks (gdy brak) |
| `deploy/k8s/backup.yaml` | CronJob pg-backup + PVC/hostPath na dumpy |
| `tools/k3s/install-k3s.sh` | k3s z flagami K-6, reguły ufw dla sieci podów |
| `tools/k3s/install-eck.sh` | CRD + operator ECK 3.5.0 (przypięta wersja) |
| `tools/k3s/secrets.sh` | `Secret marketplace-secrets` z `/opt/marketplace/.env` (+ użytkownicy ES) |
| `tools/k3s/deploy.sh` | `kubectl apply -k` z tagiem, Job migracji, czekanie na rollout |
| `tools/k3s/verify.sh` | fazy weryfikacji D2 (z Maca, przez tunel) |
| `Makefile` | `k3s-*` |
| `~/.ssh/config`, `~/.kube/elastic-vps.yaml` | tunel do API, kubeconfig (poza repo) |

**Interfejs `tools/k3s/verify.sh <faza>`:** fazy `cluster | exposure | eck | data | apps | stack | backup | reboot | all`,
wynik `✓/✗`, `PASS=n FAIL=m`, `exit 1` gdy `FAIL>0` — ten sam styl co `tools/vps/verify.sh`.
Każdy task najpierw dopisuje fazę (czerwona), potem ją zazielenia.

---
## Taski

### Task 1: k3s na serwerze + dostęp z Maca

- [ ] **Faza `cluster`** (czerwona): node `Ready`; wersja `v1.36.5+k3s1`; Traefik i ServiceLB nieobecne; API słucha tylko na `127.0.0.1:6443`; `kubectl` z Maca przez tunel działa; pod testowy rozwiązuje DNS `kubernetes.default` i łączy się z Service'em; StorageClass `local-path` domyślna.
- [ ] **Faza `exposure`**: jak w D1 + `6443 10250 30000 32767` zamknięte z zewnątrz.
- [ ] `tools/k3s/install-k3s.sh`: `INSTALL_K3S_VERSION=v1.36.5+k3s1`, `--disable traefik --disable servicelb`, `--bind-address 127.0.0.1`, `--write-kubeconfig-mode 600`, kubeconfig dla `deploy`; ufw: `allow from 10.42.0.0/16`, `allow from 10.43.0.0/16` (ruch wewnątrz klastra, nie z internetu).
- [ ] Mac: `Host elastic-vps-k8s` z `LocalForward 26443 127.0.0.1:6443`; kubeconfig `~/.kube/elastic-vps.yaml` z `server: https://127.0.0.1:26443`; `kubectl` ≥ 1.35 (skew ±1 do serwera 1.36 — obecny 1.33 za stary: **Twoja zgoda na `brew install kubernetes-cli`**).
- [ ] `cluster` + `exposure` → ✓, Compose (D1) dalej `verify.sh stack` ✓. **Commit** `ETAP D2 [1/9]`.

### Task 2: operator ECK

- [ ] **Faza `eck`**: CRD `elasticsearches.elasticsearch.k8s.elastic.co` istnieje; operator `elastic-operator-0` Running; wersja 3.5.0.
- [ ] `tools/k3s/install-eck.sh`: `kubectl create -f crds.yaml` + `kubectl apply -f operator.yaml` z `download.elastic.co/downloads/eck/3.5.0/` (wersja przypięta, pliki zapisane w `deploy/k8s/vendor/eck-3.5.0/` — repo jest źródłem prawdy, nie internet). **Commit** `[2/9]`.

### Task 3: sekrety i konfiguracja

- [ ] `tools/k3s/secrets.sh` (na serwerze): `Secret marketplace-secrets` z `/opt/marketplace/.env` (tylko klucze sekretne), `Secret es-app-users` (file realm: `catalog`, `searchsvc`, hasła z `.env`), `Secret es-app-roles` (role `catalog_app`, `search_service` — **przeniesione 1:1 z `infra/elasticsearch/setup-security.sh`**), `Secret marketplace-es-elastic-user` (hasło `elastic` = to samo co w D1).
- [ ] `deploy/k8s/config.env` + `kustomization.yaml` (ConfigMap). Test: `kubectl get secret … -o jsonpath` → klucze obecne, wartości niewyświetlane; `kubectl kustomize deploy/k8s` → poprawny YAML. **Commit** `[3/9]`.

### Task 4: Elasticsearch + Kibana przez ECK

- [ ] **Faza `es`**: `Elasticsearch marketplace` → `HEALTH green`, `NODES 3`, wersja 9.5.1, obraz `ghcr.io/dommmin/elastic-elasticsearch:<tag>`; pluginy `analysis-stempel`, `analysis-icu`; `_analyze` z analizatorem polskim działa; użytkownik `catalog` loguje się i **nie** może czytać `.security`; Kibana `green`.
- [ ] `elasticsearch.yaml`: `version: 9.5.1`, `image:` z GHCR, `nodeSets: [{name: default, count: 3}]`, heap przez `ES_JAVA_OPTS` (K-9), limity pamięci, `volumeClaimTemplates` (local-path, 10 Gi), PVC `es-snapshots` montowany we wszystkich podach + `path.repo`, `http.tls.selfSignedCertificate.disabled: true`, `auth.fileRealm` / `auth.roles` z Task 3. `Kibana`: `count: 1`, `elasticsearchRef`. **Commit** `[4/9]`.

### Task 5: Postgres, RabbitMQ, Redis

- [ ] **Faza `data`**: 3 StatefulSety `Ready 1/1`; PVC `Bound`; `pg_isready`; bazy `catalog` i `searchsvc` istnieją (skrypt init z obrazu); RabbitMQ: logowanie hasłem z `Secret`, kolejka `search.product.sync`; Redis `PING` z hasłem; **restart poda** (`kubectl delete pod postgres-0`) → dane zostają.
- [ ] Manifesty z probe'ami przepisanymi z healthchecków `compose.yaml`; `hostname`/nazwa node'a RabbitMQ stała (StatefulSet daje to za darmo — porównanie z komentarzem w `compose.yaml`). **Commit** `[5/9]`.

### Task 6: aplikacje, Job migracji, deploy

- [ ] **Faza `apps`**: Deploymenty `catalog-app`, `outbox-publisher`, `search-consumer` → `Available`; Job `migrate-<tag>` → `Complete`; `GET /up` przez `port-forward` → 200 i `X-App-Version = <tag>`; indeks + alias istnieją.
- [ ] `tools/k3s/deploy.sh <tag>`: `kustomize edit set image` (w kopii tymczasowej) → `kubectl apply -k` → usuń stary Job → utwórz `migrate` z nowym tagiem → `kubectl wait --for=condition=complete` → `kubectl rollout status` dla 3 Deploymentów. Probe'y: `readiness`/`liveness` `httpGet /up` (catalog), `exec` jak healthchecki z compose (publisher, consumer). **Commit** `[6/9]`.

### Task 7: migracja danych z Compose (blue → green)

- [ ] **Faza `stack`** (parytet z D1): 1500 dokumentów, eval OK, smoke-odpowiednik (DNS, kolejki, routing), E2E zmiana ceny ≤ 10 s.
- [ ] Postgres: świeży dump z Compose (`pg-backup.sh`) → `kubectl cp` → `pg_restore` do `postgres-0` → liczby wierszy = Compose.
- [ ] ES: snapshot z Compose → kopia plików repozytorium do PVC `es-snapshots` → rejestracja repo w ECK → restore `products-v1` z aliasem → eval = wartość z Compose (ten sam indeks).
- [ ] Kolejność z RUNBOOK #031/D1: konsumenci i publisher **zatrzymani** w k8s na czas restore'u (`kubectl scale --replicas=0`). **Commit** `[7/9]`.

### Task 8: backupy w Kubernetesie

- [ ] **Faza `backup`**: repozytorium `fs-backup` + SLM `nightly` w ECK; CronJob `pg-backup` (03:30) — ręczne `kubectl create job --from=cronjob/pg-backup` → dumpy obu baz w `/var/backups/marketplace-k8s`; `make vps-backup-pull` obejmuje też te pliki. **Commit** `[8/9]`.

### Task 9: przełączenie, restart, porównanie, przewodnik

- [ ] Przełączenie: `docker compose stop` (D1 zatrzymany, **wolumeny zostają** = droga powrotu), heap ES w k8s → `1500m / 3g` (rolling restart przez ECK — obserwujemy, że klaster cały czas `yellow/green`, nigdy `red`).
- [ ] **Faza `reboot`**: restart serwera → k3s i wszystko w namespace samo, ES green 3/3, eval identyczny.
- [ ] Ćwiczenie powrotu: `kubectl scale … --replicas=0` + `docker compose start` → D1 `verify.sh stack` ✓ → z powrotem na k8s.
- [ ] POMIARY: RAM k3s+ECK vs Compose, czas wdrożenia nowego tagu (ECK rolling restart ES vs restart klastra w D1), czas po restarcie serwera.
- [ ] `docs/blog/etap-d2-k3s.md`: każdy obiekt k8s z odpowiednikiem w `compose.yaml` (tabela „Compose → Kubernetes"), co dał operator, co zabrał Kubernetes, kiedy warto. RUNBOOK dla każdego realnego problemu. README. **Commit** `[9/9]`.

**DoD D2:** `tools/k3s/verify.sh all` zielony + `reboot` ✓ + przewodnik + Ty sam: `kubectl` z Maca, `k9s`, wdrożenie nowego tagu przez `make k3s-deploy`, rollback (`kubectl rollout undo` vs ponowny deploy starego tagu — różnica opisana w przewodniku).
