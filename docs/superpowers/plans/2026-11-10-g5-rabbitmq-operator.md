# G5 — RabbitMQ przez operator na k3d: plan implementacji

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Klaster RabbitMQ 3 węzłów z operatora, topologia jako CR-y, migracja ze starego `rabbitmq-0` bez utraty wiadomości, Stream i pomiary — wszystko na lokalnym k3d.

**Architecture:** Lokalny k3d `elastic-g5` (1 server + 2 agenty). Overlaye kustomize na bazie `deploy/k8s`: `g5-local` (ścieżka komunikatów sklepu + stary RabbitMQ + nowy klaster obok) i `g5-local-ha` (stan po migracji). RabbitMQ w osobnym katalogu `deploy/k8s/rabbitmq-cluster/`, którego baza nie włącza. Narzędzia w `tools/k3d/`, każde przez wspólny `lib.sh` z jawnym kontekstem.

**Tech Stack:** k3d, k3s `v1.36.5+k3s1`, kustomize (`kubectl kustomize`), RabbitMQ Cluster Operator, Messaging Topology Operator, cert-manager `v1.21.2`, ECK 3.5.0 (już w `deploy/k8s/vendor/eck-3.5.0`), `pivotalrabbitmq/perf-test`, bash 3.2+, Ruby (asercje renderu).

**Spec:** `docs/superpowers/specs/2026-11-10-g5-rabbitmq-operator-design.md`

## Global Constraints

- Kontekst kubeconfig zawsze `k3d-elastic-g5`; namespace aplikacji `marketplace`. Żaden skrypt poza `tools/k3d/lib.sh` nie woła gołego `kubectl`.
- Baza `deploy/k8s` i jej `kustomization.yaml` bez zmian (przyszła produkcja); wyjątek „root” rabbitmq w `tools/k3s/ckad-check.rb` zostaje.
- Każda wersja przypięta (k3d, operatory, perf-test, obraz RabbitMQ) i wpisana do `docs/07-WERSJE.md`; manifesty operatorów w `deploy/k8s/vendor/<nazwa>-<wersja>/`.
- Budżet pamięci k3d: razem ≤ ~5.7 GB; RabbitMQ 3 × 512 Mi; ES 1 węzeł, heap 512m, limit 1.5 Gi.
- Lokalny stack Compose `marketplace` zatrzymany na czas pracy (`make down`); cudzych kontenerów nie ruszamy.
- Nazwy: klaster `rabbitmq-ha` (Service `rabbitmq-ha`), stream `search.analytics.stream` (`x-max-age: 7D`, klucz `user.#`), kolejki i exchange jak w `infra/rabbitmq/definitions.template.json`.
- Klient, który łączy się zaraz po starcie poda (Job, demo, perf-test), czeka na trasę z limitem 60 s (RUNBOOK #097).
- Każdy błąd po drodze = wpis w `docs/RUNBOOK.md` (numer następny wolny po najwyższym na `main`).
- Język kodu, komentarzy i dokumentów: polski, jak w reszcie repo. Commit kończy się linią `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.

## Review Focus

1. Za mało wolnej pamięci w Docker VM → `up.sh` odmawia startu z komunikatem i kodem ≠ 0, zamiast stawiać klaster, który zabije OOM (test w Task 1).
2. Skrypt w `tools/k3d/` z gołym `kubectl` trafia w obcy klaster → test grep w `g5-check.sh` (Task 2).
3. CR-y topologii rozjeżdżają się z `definitions.template.json` (Compose dalej go używa) → test zgodności (Task 4).
4. Messenger deklaruje dynamiczne kolejki `delay_*` → bez uprawnienia `configure` na `delay_.*` pierwszy retry kończy się `ACCESS_REFUSED` → asercja na `Permission` i próba retry na klastrze (Task 4).
5. Okno przełączenia wydawcy i konsumenta daje duplikaty albo zgubione zdarzenia → dowód liczbami na obu końcach i brak duplikatów w ES (Task 5).

---

### Task 1: Środowisko k3d

**Files:**
- Create: `tools/k3d/lib.sh`, `tools/k3d/up.sh`, `tools/k3d/down.sh`, `tools/k3d/images.sh`
- Modify: `Makefile` (sekcja nowa `G5`), `docs/07-WERSJE.md`

**Interfaces:**
- Produces (`lib.sh`, sourcowany przez każdy skrypt w `tools/k3d/`):
  - `CLUSTER=elastic-g5`, `CTX=k3d-elastic-g5`, `NS=marketplace`, `REPO_ROOT`
  - `kk <args…>` = `kubectl --context "$CTX" -n "$NS" "$@"`; `kc <args…>` = `kubectl --context "$CTX" "$@"` (bez namespace)
  - `wait_for <limit_s> <cmd…>` — ponawia co 5 s, kod 0 przy sukcesie, 1 po limicie
  - `docker_free_mb` — wolna pamięć Docker VM w MB (z `free -m` w VM, kolumna `available`)
  - `log`, `die` (komunikat na stderr, exit 1)
- Produces (Makefile): `g5-up`, `g5-down`, `g5-images`

- [ ] **Step 1: Test odmowy przy braku pamięci**

`G5_MIN_FREE_MB=999999 bash tools/k3d/up.sh` → kod ≠ 0, stderr zawiera `za mało pamięci w Docker VM` i liczbę dostępnych MB; żaden klaster nie powstaje (`k3d cluster list` bez `elastic-g5`).

- [ ] **Step 2: Uruchom test i potwierdź, że pada** (skrypt nie istnieje)

- [ ] **Step 3: Zaimplementuj `lib.sh`, `up.sh`, `down.sh`, `images.sh`**

`up.sh`: domyślny próg `G5_MIN_FREE_MB=6000`; `k3d cluster create elastic-g5 --servers 1 --agents 2 --image rancher/k3s:v1.36.5-k3s1 --no-lb --k3s-arg "--disable=traefik@server:0"`; czeka, aż 3 węzły `Ready`; idempotentny (istniejący klaster = tylko sprawdzenie). `down.sh`: `k3d cluster delete elastic-g5`. `images.sh`: buduje obrazy catalog, search, postgres z Dockerfile'i repo (tagi jak `images:` w bazie nadpisane w overlayu na `g5-local`) i `k3d image import -c elastic-g5`. k3d instalowany przez Homebrew w wersji przypiętej; wersja do `docs/07-WERSJE.md`.

- [ ] **Step 4: Testy przechodzą**

Krok 1 → odmowa. `make g5-up` → `kc get nodes` pokazuje 3 węzły `Ready`. Drugie `make g5-up` → bez zmian, kod 0. `make g5-images` → `docker exec k3d-elastic-g5-agent-0 crictl images` zawiera `elastic-catalog:g5-local`.

- [ ] **Step 5: Commit** `G5: środowisko k3d (elastic-g5, 3 węzły, import obrazów)`

---

### Task 2: Overlay `g5-local` — ścieżka komunikatów sklepu na starym RabbitMQ

**Files:**
- Create: `deploy/k8s/overlays/g5-local/kustomization.yaml` (+ pliki łatek w tym katalogu), `tools/k3d/secrets.sh`, `tools/k3d/seed.sh`, `tools/k3d/g5-check.rb`, `tools/k3d/g5-check.sh`, `tools/k3d/deploy.sh`
- Modify: `.github/workflows/prod-compose.yml` (krok `bash tools/k3d/g5-check.sh`), `Makefile` (`g5-check`, `g5-deploy`)

**Interfaces:**
- Consumes: `lib.sh` (Task 1)
- Produces:
  - Secret `marketplace-secrets` (te same klucze co `tools/k3s/secrets.sh`) plus `MESSENGER_TRANSPORT_DSN_HA` (host `rabbitmq-ha`) i Secret `rabbitmq-ha-marketplace-user` (klucze `username`, `password`) oraz Secret `rabbitmq-shovel-uris` (klucze `srcUri` = stary `rabbitmq`, `destUri` = `rabbitmq-ha`, format `amqp://user:hasło@host:5672/%2f`) — hasła losowe przy pierwszym uruchomieniu, potem nieruszane
  - `tools/k3d/seed.sh [--n=N] [--fresh]` — Job z obrazem catalog uruchamiający `php artisan marketplace:seed`, czeka na zakończenie
  - `tools/k3d/deploy.sh <overlay>` — `kc apply -k`, czeka na Job `migrate` i rollout Deploymentów
  - `g5-check.rb <render.yaml> <overlay>` — asercje renderu; `g5-check.sh` renderuje oba overlaye i woła `.rb`

- [ ] **Step 1: Asercje renderu (napisz najpierw)** w `g5-check.rb` dla `g5-local`:
  - zbiór obciążeń = dokładnie: StatefulSet `postgres`, StatefulSet `rabbitmq`, Deployment `outbox-publisher`, Deployment `search-consumer`, Job `migrate`, Elasticsearch `es` z jednym nodeSetem `count: 1` i heapem `-Xms512m -Xmx512m`, bez Kibany, bez `catalog-app`/`catalog-worker`/`catalog-scheduler`/`search-metrics`/`redis`/glitchtip/HPA/CronJob `pg-backup`;
  - limity pamięci kontenerów: suma ≤ 4 Gi (k3d i operatory poza renderem);
  - obrazy aplikacji z tagiem `g5-local`;
  - NetworkPolicy z bazy obecne (`default-deny`, `allow-dns`, `allow-egress-in-namespace`).
  - `g5-check.sh`: `grep -n 'kubectl ' tools/k3d/*.sh | grep -v '^tools/k3d/lib.sh:'` musi być puste (Review Focus 2).
- [ ] **Step 2: `bash tools/k3d/g5-check.sh` pada** (brak overlayu)
- [ ] **Step 3: Overlay**: `resources: [../..]`, łatki `$patch: delete` dla zbędnych obiektów, łatka ES (`count: 1`, heap, limit 1.5 Gi), łatki zasobów, `images:` z `newTag: g5-local`. `secrets.sh` i `deploy.sh` jak w Interfaces.
- [ ] **Step 4: Render przechodzi**, potem na k3d: `make g5-deploy o=g5-local` → pody `Running`; Job `marketplace:seed --n=200` (catalog image, przez `tools/k3d/seed.sh --n=200`) → `products-search/_count` = 200 w ES k3d.
- [ ] **Step 5: Commit** `G5: overlay g5-local — ścieżka komunikatów na k3d, asercje renderu w CI`

---

### Task 3: Operatory i `RabbitmqCluster` `rabbitmq-ha`

**Files:**
- Create: `deploy/k8s/vendor/rabbitmq-cluster-operator-<wersja>/cluster-operator.yml`, `deploy/k8s/vendor/messaging-topology-operator-<wersja>/messaging-topology-operator-with-certmanager.yaml`, `tools/k3d/install-operators.sh`, `deploy/k8s/rabbitmq-cluster/kustomization.yaml`, `deploy/k8s/rabbitmq-cluster/rabbitmq-ha.yaml`, `deploy/k8s/rabbitmq-cluster/networkpolicies.yaml`
- Modify: `deploy/k8s/overlays/g5-local/kustomization.yaml` (resources += `../../rabbitmq-cluster`), `tools/k3d/g5-check.rb`, `docs/07-WERSJE.md`

**Interfaces:**
- Consumes: `deploy.sh`, `g5-check.rb` (Task 2)
- Produces: Service `rabbitmq-ha` (5672, 15672, 15692, 5552); pody z etykietą `app.kubernetes.io/name: rabbitmq-ha`; NetworkPolicy `rabbitmq-ha-ingress` i `rabbitmq-from-ha` (pody `rabbitmq-ha` → stary `rabbitmq:5672`, dla Shovela)

- [ ] **Step 1: Asercje (napisz najpierw)**: `RabbitmqCluster` `rabbitmq-ha` ma `replicas: 3`, `podAntiAffinity.requiredDuringScheduling…` z `topologyKey: kubernetes.io/hostname`, limity 512Mi, wtyczki `rabbitmq_prometheus rabbitmq_shovel rabbitmq_shovel_management rabbitmq_stream`, `additionalConfig` zawiera `default_queue_type = quorum`; `rabbitmq-ha-ingress` dopuszcza: `outbox-publisher`, `search-consumer` i pody perf-test (etykieta `app: perf-test`) na 5672 i 5552; pody `rabbitmq-ha` między sobą na 4369, 25672, 5672, 5552; namespace operatorów na 15672; namespace `marketplace` nic więcej.
- [ ] **Step 2: Render pada**
- [ ] **Step 3: Implementacja**: pobierz manifesty wydań do `vendor/` (wersje przypięte, sumy sha256 w komentarzu nagłówka); `install-operators.sh`: cert-manager `v1.21.2` (helm, chart przypięty), potem oba manifesty, czeka na `Available`. Securitycontext podów RabbitMQ: uid 999 (domyślny operatora), drop ALL, seccomp RuntimeDefault, `allowPrivilegeEscalation: false`.
- [ ] **Step 4: Na k3d**: `bash tools/k3d/install-operators.sh` → oba operatory `Available`; `make g5-deploy o=g5-local` → `kk get rabbitmqcluster rabbitmq-ha -o jsonpath='{.status.conditions[?(@.type=="AllReplicasReady")].status}'` = `True`; trzy pody na trzech różnych węzłach (`kk get pods -l app.kubernetes.io/name=rabbitmq-ha -o wide`); `rabbitmq-diagnostics cluster_status` pokazuje 3 węzły; uid w podzie = 999.
- [ ] **Step 5: Commit** `G5: operatory RabbitMQ i klaster rabbitmq-ha (3 repliki, anti-affinity, uid 999)`

---

### Task 4: Topologia jako CR-y + Stream

**Files:**
- Create: `deploy/k8s/rabbitmq-cluster/topology.yaml`, `deploy/k8s/rabbitmq-cluster/stream.yaml`, `tools/k3d/topology-parity.rb`
- Modify: `deploy/k8s/rabbitmq-cluster/kustomization.yaml`, `tools/k3d/g5-check.sh` (woła parity), `tools/k3d/g5-check.rb`

**Interfaces:**
- Consumes: `rabbitmq-ha` (Task 3), Secret `rabbitmq-ha-marketplace-user` (Task 2)
- Produces: CR-y `Exchange`, `Queue`, `Binding`, `User` (`marketplace`, `importCredentialsSecret`), `Permission`, z `rabbitmqClusterReference: {name: rabbitmq-ha}`; kolejka `search.analytics.stream`

- [ ] **Step 1: Test zgodności (napisz najpierw)** `topology-parity.rb <render.yaml> infra/rabbitmq/definitions.template.json`: dla każdej kolejki z pliku istnieje `Queue` o tej nazwie z tymi samymi `arguments` (typ, `x-dead-letter-exchange`, `x-delivery-limit`); każdy exchange ma `Exchange` o tym samym typie; zbiór bindingów `(source, destination, routing_key)` identyczny; jedyna dozwolona różnica to `search.analytics.stream` + jej binding `user.#`. Asercja `Permission`: `configure` pasuje do `delay_.*` i nazw kolejek sklepu (Review Focus 4).
- [ ] **Step 2: Test pada**
- [ ] **Step 3: CR-y** w `topology.yaml` i `stream.yaml` (`x-queue-type: stream`, `x-max-age: 7D`, durable).
- [ ] **Step 4: Na k3d**: wszystkie CR-y `Ready=True`; `rabbitmqctl list_queues name type` na `rabbitmq-ha` pokazuje 6 kolejek `quorum` + `search.analytics.stream` `stream`; `rabbitmqctl list_permissions` pokazuje `marketplace`. Próba retry: wiadomość z niepoprawnym payloadem na `search.product.sync` nowego klastra (gdy konsument już na nim — wykonać w Task 5 po kroku 4 migracji) → kolejka `delay_*` powstaje, brak `ACCESS_REFUSED` w logach `search-consumer`.
- [ ] **Step 5: Commit** `G5: topologia RabbitMQ jako CR-y, zgodność z definitions.template.json, stream analityki`

---

### Task 5: Migracja przez Shovel i overlay końcowy

**Files:**
- Create: `deploy/k8s/overlays/g5-local-ha/kustomization.yaml` (+ łatki), `deploy/k8s/rabbitmq-cluster/shovels.yaml` (tylko w `g5-local`, nie w `g5-local-ha`), `tools/k3d/migrate.sh`, `tools/k3d/counts.sh`
- Modify: `deploy/k8s/overlays/g5-local/kustomization.yaml`, `tools/k3d/g5-check.rb`

**Interfaces:**
- Consumes: overlay `g5-local`, CR-y topologii, `seed.sh`
- Produces:
  - `counts.sh` → wypisuje `outbox_total outbox_unpublished processed_events es_count old_queues_total dlq_total` (jedna linia, liczby oddzielone spacją) — używany przez `migrate.sh`, Task 6 i przewodnik
  - `migrate.sh` — kroki 2–5 ze specu sekcji 5 z pauzą i wydrukiem `counts.sh` po każdym; kod ≠ 0, gdy końcowe liczby się nie zgadzają
  - overlay `g5-local-ha`: `RABBITMQ_HOST=rabbitmq-ha` w ConfigMap, `search-consumer` z `MESSENGER_TRANSPORT_DSN` z klucza `MESSENGER_TRANSPORT_DSN_HA`, bez StatefulSetu i Service `rabbitmq`, bez `shovels.yaml`

- [ ] **Step 1: Asercje renderu (napisz najpierw)**: `g5-local-ha` nie ma obiektu z `app: rabbitmq`, ConfigMap ma `RABBITMQ_HOST=rabbitmq-ha`, env `search-consumer` wskazuje klucz `_HA`, żaden pod RabbitMQ nie ma `runAsUser: 0`; `Shovel` w `g5-local` dla każdej kolejki starego węzła z `ackMode: on-confirm`, `srcDeleteAfter: queue-length`, `uriSecret: {name: rabbitmq-shovel-uris}`, cel `marketplace.events` z zachowaniem routing key.
- [ ] **Step 2: Render pada**
- [ ] **Step 3: Overlay, Shovel, `migrate.sh`, `counts.sh`**. Kroki 2 i 4 w `migrate.sh` to `kk set env` + `kk rollout status` (żywe przełączenie), na końcu `make g5-deploy o=g5-local-ha` nie zmienia już nic poza usunięciem starego RabbitMQ.
- [ ] **Step 4: Na k3d pod ruchem**: `seed.sh` w pętli (Job `--n=1500`) startuje przed krokiem 2 i kończy po kroku 4; `migrate.sh` → końcowa linia: `outbox_unpublished=0`, `processed_events = outbox_total`, `es_count` = liczba produktów w bazie katalogu, `old_queues_total=0`, `dlq_total=0`, kod 0. Duplikaty: `es` agregacja terms po `_id` z `min_doc_count: 2` = pusta (Review Focus 5). Potem próba retry z Task 4 Step 4.
- [ ] **Step 5: Commit** `G5: migracja na rabbitmq-ha przez Shovel, dowód liczbami, overlay końcowy`

---

### Task 6: Weryfikacja `g5` — kryteria 1–5

**Files:**
- Create: `tools/k3d/verify.sh`, `tools/k3d/perf-test.sh`, `tools/k3d/stream-demo.sh`
- Modify: `Makefile` (`g5-verify`)

**Interfaces:**
- Consumes: `lib.sh`, `counts.sh`, overlay `g5-local-ha`
- Produces:
  - `perf-test.sh <nazwa> <args perf-test…>` — Job `perf-test-<nazwa>` (obraz `pivotalrabbitmq/perf-test:<wersja przypięta>`, etykieta `app: perf-test`, czekanie na trasę 60 s), po zakończeniu wypisuje logi; kod = kod Joba
  - `verify.sh` — styl `check "<opis>" "<oczekiwane>" "<faktyczne>"` jak `tools/k3s/verify.sh`, podsumowanie `PASS=n FAIL=m`, kod ≠ 0 przy FAIL

- [ ] **Step 1: Sprawdzenia (napisz najpierw w `verify.sh`)**:
  1. 3 pody `rabbitmq-ha` `Ready` na 3 różnych węzłach, uid 999;
  2. wszystkie CR-y topologii `Ready=True`, 6 kolejek quorum + 1 stream;
  3. `counts.sh`: brak zaległości i zgodność liczb (jak Task 5 Step 4);
  4. failover: perf-test z `--confirm 100` i licznikiem potwierdzeń na `search.product.sync`-podobnej kolejce testowej quorum `g5.failover` (deklarowana przez perf-test, usuwana po teście); w trakcie `kk delete pod` lidera (z `rabbitmq-queues quorum_status`); po teście liczba wiadomości w kolejce ≥ liczba potwierdzonych → „utracone potwierdzone = 0”; czas do nowego lidera wypisany;
  5. stream: `stream-demo.sh` publikuje N=1000 zdarzeń `user.searched` przez `marketplace.events`; dwóch konsumentów (`x-stream-offset: first`) dostaje po 1000; trzeci z offsetem = znacznik czasu sprzed publikacji też 1000, a z offsetem `next` 0.
- [ ] **Step 2: `make g5-verify` przed implementacją skryptów pomocniczych pada**
- [ ] **Step 3: `perf-test.sh`, `stream-demo.sh`**
- [ ] **Step 4: `make g5-verify` → `PASS=… FAIL=0`**
- [ ] **Step 5: Commit** `G5: verify g5 na k3d — klaster, topologia, migracja, failover, stream`

---

### Task 7: Pomiary

**Files:**
- Create: `tools/k3d/measure.sh`
- Modify: `docs/POMIARY.md` (nowa sekcja „G5 — RabbitMQ”), `Makefile` (`g5-measure m=queues|failover|prefetch`)

**Interfaces:**
- Consumes: `perf-test.sh`, `counts.sh`, `seed.sh`

- [ ] **Step 1: `measure.sh queues`**: perf-test 3 × dla classic i quorum (wiadomość 1 KB, 2 producentów, 2 konsumentów, `--confirm`, 60 s); wypisuje medianę przepustowości (msg/s) i opóźnienia p50/p99 oraz load average.
- [ ] **Step 2: `measure.sh failover`**: 3 × zabicie lidera i 1 × `docker stop k3d-elastic-g5-agent-1` (potem `docker start`); utracone potwierdzone i czas do nowego lidera.
- [ ] **Step 3: `measure.sh prefetch`**: `search-consumer` z prefetch 1/10/50; najpierw ustal, jak Messenger ustawia prefetch dla AMQP (opcja transportu w DSN albo `messenger.yaml`) i potwierdź kolumną `prefetch_count` w `rabbitmqctl list_consumers`; dla każdej wartości reindeks 1500 zdarzeń (`seed.sh --n=1500 --fresh`), czas od publikacji do pustej kolejki, 3 ×.
- [ ] **Step 4: Uruchom wszystkie trzy, wpisz tabele do POMIARÓW** (data, konfiguracja, mediana, rozrzut, load average, uwaga o współdzielonej maszynie)
- [ ] **Step 5: Commit** `G5: pomiary — quorum vs classic, failover, prefetch`

---

### Task 8: Dokumentacja i domknięcie

**Files:**
- Create: `docs/blog/etap-g5-rabbitmq.md`
- Modify: `docs/RUNBOOK.md` (wpisy z drogi + indeks), `docs/13-PLAN-ETAP-G-K8S.md` (stan G5 i odstępstwa: k3d zamiast VPS, brak kube-prometheus-stack), `README.md` (wiersz G), `docs/07-WERSJE.md`

- [ ] **Step 1: Przewodnik krok po kroku** od `make g5-up` do `make g5-verify` i `make g5-down`, w stylu `docs/blog/etap-g4-chaos.md`: dlaczego operator, topologia jako CR-y, migracja z liczbami z `counts.sh`, failover, stream, pomiary, odstępstwa od planu, błędy z RUNBOOK.
- [ ] **Step 2: RUNBOOK, plan 13, README, WERSJE**
- [ ] **Step 3: Pełny przebieg od zera**: `make g5-down && make g5-up && make g5-images && bash tools/k3d/install-operators.sh && make g5-deploy o=g5-local && bash tools/k3d/migrate.sh && make g5-verify` → `FAIL=0`; `bash tools/k3d/g5-check.sh` → OK. Wynik wpisany do przewodnika.
- [ ] **Step 4: Commit** `G5: przewodnik, RUNBOOK, stan planu`
