# 13 — ETAP G: Kubernetes na poziomie CKAD + produkcyjna obserwowalność

> **Stan (2026-11-06):** G1 wdrożony na k3s i zweryfikowany (`faza=monitoring` 16/16, `faza=exposure` 17/17), przewodnik [`blog/etap-g1-monitoring-k3s.md`](blog/etap-g1-monitoring-k3s.md). G2 wdrożony (obraz `2e5990c` po merge #12: `faza=g2` 68/69 tuż po rolloucie, powtórka `faza=ingress` 13/13), przewodnik [`blog/etap-g2-ckad-manifesty.md`](blog/etap-g2-ckad-manifesty.md). G3 zrobiony: 30 zadań CKAD z sprawdzarką, selftest 30/30 na klastrze, przewodnik [`blog/etap-g3-ckad-lab.md`](blog/etap-g3-ckad-lab.md). Następny: G4.

**Cel:** po ETAPIE D2 sklep działa na k3s, ale manifesty pokrywają mniej więcej połowę
zakresu CKAD, a Prometheus/Grafana/Alertmanager działają tylko lokalnie w Compose.
ETAP G domyka obie luki **na tym samym klastrze i tej samej aplikacji**, żeby każda
nowa rzecz z Kubernetesa miała prawdziwy powód istnienia, a nie była przykładem
z dokumentacji.

**Zasady wspólne dla wszystkich podetapów:**

- każdy podetap = osobna gałąź, PR, wdrożenie na k3s, wpisy w RUNBOOK i przewodnik
  w `docs/blog/` (bez przewodnika podetap nie jest skończony);
- repo jest źródłem prawdy: wersje chartów Helma przypięte, wartości w repo,
  nic nie jest „klikane” w klastrze;
- każda zmiana ma test bez klastra (render + asercje, uruchamiany w CI)
  i weryfikację na klastrze (`tools/k3s/verify.sh`, nowa faza na podetap);
- VPS ma 24 GB RAM i 4 vCPU; przed G1 zajęte ~11 GB. Każdy podetap podaje swój
  budżet pamięci w requests/limits.

## Kolejność i dlaczego taka

| # | Podetap | Zależy od | Dlaczego w tym miejscu |
|---|---------|-----------|------------------------|
| G1 | Obserwowalność na k3s (kube-prometheus-stack) | — | Alerty z ETAPU 11 na produkcji dziś nikogo nie pilnują. Do tego każdy następny podetap da się zweryfikować metrykami (HPA, NetworkPolicy, awarie z G4). |
| G2 | Brakujące tematy CKAD w manifestach | G1 | NetworkPolicy muszą przepuścić scrape z namespace `monitoring`, więc G1 musi już istnieć. HPA potrzebuje metryk (metrics-server jest w k3s). |
| G3 | Ćwiczenia CKAD (`docs/ckad/`) | G2 | Zadania odwołują się do obiektów z G1–G2 na Twoim klastrze. |
| G4 | „Zepsuj i napraw” | G1, G2 | Diagnoza zaczyna się od alertu i dashboardu z G1, a część awarii (NetworkPolicy, RBAC, securityContext) istnieje dopiero po G2. |
| G5 | RabbitMQ wyżej: operator, klaster 3 węzłów, Streams | G1 | Jedyny podetap, który rusza dane produkcyjne (migracja kolejek), więc na końcu, z pełną obserwowalnością do porównań. |

## G1 — obserwowalność na k3s

**Decyzje:**

- **kube-prometheus-stack przez Helm** (przypięta wersja, `deploy/monitoring/values.yaml`).
  To pierwszy własny chart Helma w projekcie (ECK instalujemy z manifestów). Helm tu,
  a nie ręczne manifesty, bo operator Prometheusa + CRD + kilkanaście dashboardów
  i reguł dla Kubernetesa to tysiące linii, które utrzymuje społeczność.
- **Reguły z ETAPU 11 bez kopiowania:** `PrometheusRule` jest generowany z
  `infra/obs/prometheus/rules/marketplace.yml` skryptem, a test w CI pilnuje, że
  plik wygenerowany jest aktualny. Te same 18 alertów i te same 54 testy `promtool`
  działają lokalnie i na klastrze.
- **ServiceMonitor zamiast `static_configs`:** cele odkrywane z obiektów Service.
  Etykieta `job` nadpisywana relabelingiem na nazwy z Compose (`catalog`, `caddy`,
  `rabbitmq`, `rabbitmq-queues`, `search`, `glitchtip`), żeby reguły pasowały bez zmian.
- **Eksporter Postgresa jako sidecar** w podzie `postgres-0` (wzorzec *adapter* z CKAD):
  łączy się po `localhost`, żyje i umiera razem z bazą. Kompromis: używa konta
  superużytkownika z istniejącego Secretu (osobna rola `pg_monitor` wymagałaby
  migracji na istniejącym PVC; zapisane jako dług w RUNBOOK).
- **search-metrics jako osobny Deployment** (jak w Compose), nie sidecar konsumenta:
  metryki opisują kolejkę i bazę, nie proces konsumenta, więc ich cykl życia nie
  powinien być z nim związany.
- **Alertmanager bez kanału powiadomień:** na serwerze nie ma SMTP. Alerty widać
  w UI Alertmanagera i w Grafanie; kanał (mail/Slack) to jedna sekcja w values.
- **Dostęp tylko przez tunel** (`make k3s-grafana`, `k3s-prometheus`,
  `k3s-alertmanager`). Ingress przychodzi w G2.
- **k3s ma kontroler, scheduler, proxy i etcd w jednym procesie**: ich scrape
  i reguły z chartu są wyłączone, inaczej `TargetDown` świeci od pierwszej minuty.

**Budżet:** ~1,5 GB requests (Prometheus 512Mi, Grafana 128Mi, reszta drobna),
PVC Prometheusa 5 Gi, retencja 7 dni / 4 GB.

**DoD:** wszystkie cele `up == 1`, 18 reguł marketplace załadowanych, testowy alert
przechodzi `pending → firing → resolved` na klastrze, 2 dashboardy z repo w Grafanie,
port 9100 (node-exporter) zamknięty z zewnątrz, przewodnik `docs/blog/etap-g1-monitoring-k3s.md`.

## G2 — CKAD w manifestach

Każdy element z realnym powodem w tej aplikacji:

- **Ingress** (ingress-nginx przez Helm) + TLS z cert-managera (wystawca self-signed,
  bo nie ma domeny); sklep, Grafana i Kibana pod hostami `*.marketplace.test`
  przez tunel na 443. Port 80/443 dalej zamknięty z zewnątrz.
- **NetworkPolicy:** domyślnie deny w `marketplace`, potem allow per przepływ
  (catalog → postgres/redis/rabbitmq/es, search → rabbitmq/es/catalog-app,
  monitoring → porty metryk). Wbudowany kontroler polityk k3s.
- **HPA** dla `catalog-app` (CPU) + test obciążeniowy, który widać na dashboardzie.
- **securityContext:** non-root, `readOnlyRootFilesystem`, `drop: [ALL]`,
  `seccompProfile: RuntimeDefault`, tam gdzie obraz na to pozwala (i opis, gdzie nie).
- **ServiceAccount + RBAC:** osobne SA per aplikacja, `automountServiceAccountToken: false`
  tam, gdzie API nie jest potrzebne; Role dla Joba, który go potrzebuje.
- **startupProbe** dla wolno startujących (catalog-app, glitchtip) zamiast
  `initialDelaySeconds`.
- **Canary** dla catalog-app (drugi Deployment z etykietą `track: canary`, ten sam Service).

**Odstępstwa od planu (decyzje przy realizacji, RUNBOOK #083–#087):**

- **Traefik zamiast ingress-nginx** — ingress-nginx wycofany (koniec utrzymania 03.2026).
  Standardowy obiekt Ingress, Service ClusterIP, dostęp tunelem SSH na `:8443` (443 na Macu zajęty).
- **NetworkPolicy:** egress „do całego namespace + DNS”, precyzja po stronie ingress serwera
  (zasada: kto ma hasło, ten ma trasę). Ewaluator polityk w CI (`tools/k3s/ckad-check.rb`).
- **securityContext:** profil restricted wszędzie poza rabbitmq (root w entrypoincie, G5)
  i pg-backup (root, hostPath); `NET_BIND_SERVICE` tylko dla frankenphp (RUNBOOK #086).
  PSA `warn`/`audit` restricted bez `enforce`.
- **RBAC:** żaden pod nie potrzebuje API, więc wszystkie konta bez tokena; Role ma konto
  `deployer` dla narzędzi wdrożeniowych (CI), nie Job.
- **Canary generowany z żywego Deploymentu** (`tools/k3s/canary-render.rb`), nie plik w repo.
- Przy okazji: webhook bramki płatności na k3s nie działał (RUNBOOK #085).

## G3 — ćwiczenia CKAD

`docs/ckad/`: ~30 zadań na czas w pięciu domenach egzaminu, każde z limitem minut,
sprawdzarką (`tools/ckad/check.sh <zadanie>`) i rozwiązaniem imperatywnym (`kubectl
create/run/expose/set`). Zadania wykonuje się w osobnym namespace `ckad-lab`,
żeby nie ruszać sklepu.

**Zrobione (2026-11-06):** [`docs/ckad/`](ckad/README.md) — 30 zadań w proporcjach wag
egzaminu, `make ckad-lab-up|start|check|selftest|exam|scores`. Lab w `ckad-lab` i
`ckad-lab-b` z kubeconfigiem ucznia (rola `admin` tylko w labie), ResourceQuota i
LimitRange jako strażnicy węzła. Selftest 30/30: nierozwiązane = FAIL, rozwiązanie
z dokumentu jako uczeń = PASS. Przewodnik: [`blog/etap-g3-ckad-lab.md`](blog/etap-g3-ckad-lab.md).

## G4 — „zepsuj i napraw”

`tools/chaos/<scenariusz>.sh break|check|reset`: OOMKilled, zły Secret, zablokowana
kolejka, padnięty węzeł ES, NetworkPolicy odcinająca bazę, zła sonda, pełny PVC.
Każdy scenariusz ma kartę w `docs/chaos/`: objaw, od którego alertu/dashboardu
zacząć, ścieżka diagnozy — bez rozwiązania na pierwszej stronie.

## G5 — RabbitMQ wyżej

Operator RabbitMQ (Cluster Operator + Messaging Topology Operator), klaster 3 węzłów
z kolejkami quorum (failover pod obciążeniem), Stream dla zdarzeń analitycznych,
porównanie quorum vs classic, strojenie prefetch. Migracja z obecnego StatefulSetu
przez Shovel, bez utraty wiadomości.
