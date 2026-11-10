# G5 — RabbitMQ przez operator, na lokalnym k3d

> Spec, 2026-11-10. Plan nadrzędny: [`docs/13-PLAN-ETAP-G-K8S.md`](../../13-PLAN-ETAP-G-K8S.md), sekcja G5.
> Zmiana względem planu: VPS wygasł, więc G5 działa i jest weryfikowany **tylko na lokalnym
> k3d**. Manifesty nie mogą zależeć od lokalnego środowiska, żeby przyszły VPS przyjął je
> bez przepisywania. Wdrożenie produkcyjne nie wchodzi w zakres G5.

## 1. Cel i kryteria sukcesu

**Po co.** Dziś RabbitMQ to jeden pod `rabbitmq-0` (StatefulSet, root, topologia
wpieczona w obraz przez `definitions.json`). Wszystkie 6 kolejek jest `quorum`, ale na
jednym węźle quorum nic nie replikuje: utrata węzła = przestój i ryzyko utraty danych.
G5 uczy RabbitMQ „wyżej”: operator, prawdziwy klaster 3 węzłów, topologia jako obiekty
Kubernetesa, Streams i migracja bez utraty wiadomości, wszystko na zdarzeniach sklepu.

**Kryteria sukcesu (wszystkie mierzalne, wszystkie w `verify` fazie `g5`):**

1. `RabbitmqCluster` z 3 replikami, każda na innym węźle k3d, pody jako uid 999.
   W końcowym overlayu (`g5-local-ha`) nie ma już poda RabbitMQ działającego jako root.
   Baza `deploy/k8s` (przyszła produkcja) zachowuje stary StatefulSet i jego wyjątek
   w `tools/k3s/ckad-check.rb`, dopóki nie będzie wdrożenia na serwer (poza zakresem).
2. Cała topologia (exchange, kolejki, bindingi, użytkownik, uprawnienia, polityki)
   istnieje jako CR-y Messaging Topology Operatora. `definitions.json` nie jest już
   źródłem prawdy dla k8s (Compose dalej go używa).
3. Migracja ze starego `rabbitmq-0` na klaster pod żywym ruchem: liczba zdarzeń
   w outboksie = liczba przetworzonych zdarzeń w search = liczba dokumentów w ES;
   stare kolejki i wszystkie DLQ puste.
4. Failover: zabicie poda lidera kolejki `search.product.sync` pod obciążeniem
   z potwierdzeniami wydawcy = **0 utraconych potwierdzonych wiadomości**; zmierzony
   czas do nowego lidera.
5. Stream `search.analytics.stream`: dwóch niezależnych czytelników dostaje te same
   zdarzenia, a czytelnik z `x-stream-offset` (`first` i znacznik czasu) odtwarza historię.
6. Pomiary w POMIARACH: quorum vs classic (przepustowość, opóźnienie p50/p99),
   failover, prefetch 1/10/50 na reindeksie 1500 zdarzeń.
7. Przewodnik `docs/blog/etap-g5-rabbitmq.md` krok po kroku i wpisy RUNBOOK.
   Bez przewodnika podetap nie jest skończony.

## 2. Środowisko: k3d

- Klaster `elastic-g5`: **1 server + 2 agenty** (3 węzły k8s), k3s w wersji przypiętej
  w `tools/k3s/install-k3s.sh` (`v1.36.5+k3s1`), k3d przypięty w `docs/07-WERSJE.md`.
  Trzy węzły są po to, żeby anti-affinity rozłożyła repliki RabbitMQ, a „awaria węzła”
  (`docker stop` agenta) była prawdziwym failoverem, nie restartem poda.
- Obrazy własne (catalog, search, postgres) budowane lokalnie i ładowane przez
  `k3d image import` — bez pulla z GHCR i bez rejestru.
- Bez Ingressu i bez otwierania portów: management UI i metryki przez `kubectl port-forward`.
- Kontekst kubeconfig `k3d-elastic-g5`. Narzędzia nigdy nie używają gołego `kubectl`
  bez `--context` (lekcja z G4: goły `kubectl` na Macu celuje w obcy klaster).
- Skrypty: `tools/k3d/up.sh`, `tools/k3d/down.sh`, cele `make g5-up`, `make g5-down`.
  `up.sh` sprawdza wolną pamięć Docker VM przed startem i odmawia poniżej budżetu (sekcja 8).
- Na czas G5 lokalny stack Compose `marketplace` jest zatrzymany (`make down`). Cudzych
  kontenerów nie ruszamy.

## 3. Manifesty

- Overlay `deploy/k8s/overlays/g5-local` (kustomize): z bazy bierze tylko ścieżkę
  komunikatów: namespace, ServiceAccounts, NetworkPolicy, config, Postgres, Job `migrate`,
  `outbox-publisher`, `search-consumer` i ES przez ECK z **1 węzłem** (łatka `count: 1`,
  heap 512m). Zasoby zmniejszone łatkami. Baza `deploy/k8s` nie dostaje żadnej zmiany
  pod k3d.
- Nowe pliki RabbitMQ w osobnym katalogu `deploy/k8s/rabbitmq-cluster/` (własny
  `kustomization.yaml`): `RabbitmqCluster` + CR-y topologii + ich NetworkPolicy. To
  docelowa architektura dla każdego środowiska, ale bazowy `kustomization.yaml` ich nie
  włącza (bez operatora w klastrze CR-y byłyby „nieznanym typem”). Overlaye `g5-local`
  i `g5-local-ha` dokładają je przez `resources`.
- NetworkPolicy z G2 obowiązują także na k3d (k3d ma ten sam kube-router). Nowe trasy:
  operator → pody RabbitMQ (management API), pody RabbitMQ między sobą (4369, 25672,
  5672 dla Shovela), klienci → 5672. Trasa każdego klienta jest w tabeli `ALLOWED`
  w `ckad-check.rb`, jak w G2.
- Klienci łączący się zaraz po starcie (Job `migrate`, demo Streamu, `perf-test`)
  czekają na trasę z limitem (wyścig kube-router, RUNBOOK #097).

## 4. Operatory

- **Cluster Operator** i **Messaging Topology Operator**: wersje przypięte, manifesty
  wydania zapisane w `deploy/k8s/vendor/` (jak `eck-3.5.0`), zainstalowane skryptem
  `tools/k3d/install-operators.sh`. Topology Operator wymaga cert-managera (webhooki):
  ta sama wersja co w G2, chart przypięty.
- `RabbitmqCluster` `rabbitmq-ha`:
  - `replicas: 3`, `affinity.podAntiAffinity` (required, `topologyKey: kubernetes.io/hostname`);
  - `persistence` z PVC (local-path k3d), rozmiar w budżecie;
  - `rabbitmq.additionalConfig`: `default_queue_type = quorum` (jak dziś w `rabbitmq.conf`),
    `vm_memory_high_watermark` dopasowany do limitu;
  - wtyczki: `rabbitmq_prometheus`, `rabbitmq_shovel`, `rabbitmq_shovel_management`,
    `rabbitmq_stream`;
  - securityContext z profilu restricted (uid 999, drop ALL, seccomp).
- **Topologia jako CR-y** (odpowiedniki `infra/rabbitmq/definitions.template.json`):
  exchange `marketplace.events`, `marketplace.dlx` (topic), `marketplace.delays` (direct);
  kolejki quorum z `x-dead-letter-exchange` i `x-delivery-limit: 5` plus ich DLQ;
  wszystkie bindingi; `User` z hasłem importowanym z `marketplace-secrets`; `Permission`.
  Messenger dalej deklaruje sam dynamiczne kolejki opóźnień (`delay_*`, classic,
  `x-expires`), więc użytkownik ma uprawnienie `configure` na `delay_.*`.
  Test bez klastra porównuje CR-y z `definitions.template.json` (te same nazwy, typy,
  argumenty, bindingi), żeby oba źródła się nie rozjechały.

## 5. Migracja bez utraty (Shovel)

Stan wyjściowy na k3d: overlay z obecnym StatefulSetem `rabbitmq` (1 węzeł), sklep
pod ruchem (Job `marketplace:seed` w pętli generuje zdarzenia przez outbox).

Kolejność (każdy krok to osobna komenda w przewodniku i sprawdzenie w `verify`):

1. Postaw `rabbitmq-ha` z topologią obok starego (oba działają).
2. **Wydawca na nowy klaster**: `outbox-publisher` dostaje host `rabbitmq-ha`
   (ConfigMap), rollout. Od tej chwili nowe zdarzenia trafiają tylko do nowego klastra.
3. **Shovel** (CR `Shovel`, tryb `on-confirm`, `src-delete-after: queue-length`) dla
   każdej kolejki starego węzła: przenosi zaległe wiadomości do `marketplace.events`
   na nowym klastrze z zachowaniem routing key.
4. **Konsument na nowy klaster**: `search-consumer` dostaje nowy host, rollout.
   Idempotencja przez `ProcessedEvent` chroni przed duplikatami z okna przełączenia.
5. Sprawdzenie: stare kolejki = 0, DLQ = 0 (stare i nowe), outbox opublikowany w całości,
   `ProcessedEvent` = liczba zdarzeń, `_count` w ES zgodny z bazą katalogu.
6. Stan końcowy jako kod: overlay `g5-local-ha` (aplikacje na `rabbitmq-ha`, bez
   StatefulSetu `rabbitmq` i jego Service). Kroki 2 i 4 to operacje na żywo w czasie
   migracji, a `kubectl apply` overlayu końcowego niczego już nie zmienia (repo = prawda).

Dowód „bez utraty” to liczenie na obu końcach, nie brak błędów w logach.

## 6. Stream

- Nowa kolejka `search.analytics.stream` (`x-queue-type: stream`, `x-max-age: 7D`),
  podpięta pod `marketplace.events` z kluczem `user.#`, obok dzisiejszej
  `search.analytics.ingest`.
- Nikt w sklepie nie publikuje dziś zdarzeń `user.*`, więc demo publikuje je samo
  (`perf-test` z kluczem `user.searched` przez exchange).
- Demo (skrypt w `tools/k3d/`, kontener z `perf-test` albo małym klientem AMQP):
  dwóch konsumentów czyta te same zdarzenia niezależnie; trzeci startuje z
  `x-stream-offset: first` i znacznikiem czasu i odtwarza historię. Kontrast z kolejką:
  wiadomość ze streamu nie znika po odczycie.
- Wpięcie prawdziwego konsumenta analityki w aplikację search **nie** wchodzi w G5.

## 7. Pomiary

Wszystkie przez `pivotalrabbitmq/perf-test` (wersja przypięta) jako Job na k3d.
Wyniki w `docs/POMIARY.md` (nowa sekcja G5) z konfiguracją i liczbą powtórzeń
(każdy pomiar 3 razy, mediana, rozrzut podany).

- **Quorum vs classic**: ta sama wiadomość (1 KB), te same producenci/konsumenci,
  publisher confirms; przepustowość i opóźnienie p50/p99.
- **Failover**: pod obciążeniem z confirmami zabijamy pod lidera kolejki (i osobno
  agent k3d); liczymy potwierdzone, a nieodebrane wiadomości (oczekiwane 0) i czas
  do nowego lidera.
- **Prefetch**: `search-consumer` z `prefetch_count` 1/10/50 (opcja transportu
  Messengera), czas przetworzenia 1500 zdarzeń reindeksu.

Maszyna jest współdzielona z innymi projektami: wyniki są orientacyjne, raport podaje
load average w czasie pomiaru.

## 8. Budżet pamięci

| Składnik | Limit |
|---|---|
| k3d (server + 2 agenty, k3s) | ~1.2 GB |
| RabbitMQ 3 × 512 Mi | 1.5 GB |
| ES (1 węzeł, heap 512m, limit 1.5 Gi) | 1.5 GB |
| Postgres, outbox-publisher, search-consumer, Job-y | ~0.8 GB |
| ECK, oba operatory RabbitMQ, cert-manager | ~0.7 GB |
| **Razem** | **~5.7 GB** |

ES ma limit 1.5 Gi przy heapie 512m: dziś lokalnie przy 1 Gi zabijał go cgroup OOM
(sam proces Javy zajmuje > 1 GB).

## 9. Obserwowalność: świadome odstępstwo

kube-prometheus-stack z G1 nie mieści się lokalnie (~1.5 GB). G5 go nie instaluje.
Metryki RabbitMQ czyta `verify` bezpośrednio z endpointu `rabbitmq_prometheus` (15692),
a przewodnik pokazuje management UI. Plan G zakładał porównania „z pełną
obserwowalnością”, więc przewodnik nazywa to odstępstwo wprost.

## 10. Weryfikacja i testy

- **Bez klastra (CI)**: `kubectl kustomize deploy/k8s/overlays/g5-local` + asercje
  (Ruby, jak `manifests-check`/`ckad-check`): 3 repliki z anti-affinity, uid 999,
  komplet CR-ów topologii zgodny z `definitions.template.json`, trasy NetworkPolicy,
  limity w budżecie.
- **Na k3d**: faza `g5` w `tools/k3s/verify.sh` (albo osobny `tools/k3d/verify.sh`
  dzielący funkcje) w trybie lokalnym: kontekst `k3d-elastic-g5`, bez tunelu SSH
  i bez `remote`. Sprawdza kryteria 1–5 z sekcji 1. Pomiary (kryterium 6) to osobne
  cele `make g5-measure-*`, nie część szybkiej weryfikacji.
- **Dokumenty**: RUNBOOK (każdy błąd po drodze), POMIARY, `docs/07-WERSJE.md`,
  przewodnik, wiersz G w README i stan w `docs/13-PLAN-ETAP-G-K8S.md`.

## 11. Poza zakresem

- Wdrożenie na VPS i migracja danych produkcyjnych (nie ma serwera).
- Konsument analityki w aplikacji search (stream zostaje jako infrastruktura i demo).
- kube-prometheus-stack na k3d (sekcja 9).
- Zmiana Compose: lokalny stack dalej używa jednego RabbitMQ i `definitions.json`.
