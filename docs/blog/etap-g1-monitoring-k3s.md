# Prometheus, Grafana i Alertmanager na k3s, krok po kroku

*ETAP G1 projektu Marketplace Search Platform. W ETAPIE 11 obserwowalność
działała w Docker Compose: Prometheus czytał listę celów z `prometheus.yml`,
Grafana miała dashboardy z plików, a 18 alertów z ich testami siedziało w
`infra/obs/prometheus/rules/`. Na k3s, gdzie od ETAPU D2 działa produkcja,
nie było z tego nic. Ten etap przenosi monitoring do klastra „po kubernetesowemu”:
operator, ServiceMonitory, PrometheusRule, sidecar w podzie bazy. Te same reguły
i dashboardy mają działać w obu środowiskach bez kopiowania.*

> **Stan:** gałąź `g1-monitoring`, PR [Dommmin/elastic#10](https://github.com/Dommmin/elastic/pull/10).
> Wdrożone na VPS 2026-11-05 (chart `kube-prometheus-stack` 92.17.1).
> Zweryfikowane na klastrze: `make k3s-verify faza=monitoring` 16/16 (wszystkie
> 7 jobów `up`, 18 reguł, `pg_up=1`, 2 dashboardy w Grafanie, testowy alert
> przez całą ścieżkę: dotarł w 75 s, rozwiązany w 70 s), `faza=exposure` 17/17
> (porty 9100, 9187, 15692 zamknięte z zewnątrz). Bez klastra:
> `make k3s-monitoring-check` i `make obs-check` zielone, oba w CI.
> **Nie sprawdzone na żywo:** nazwa serwera `metrics` w Caddy na k3s. Zmiana
> w Caddyfile wchodzi z nowym obrazem catalog, który CI zbuduje po merge
> (reguła działa już teraz, tylko liczy też scrape'y, patrz krok 7).
>
> Plan: [`13-PLAN-ETAP-G-K8S.md`](../13-PLAN-ETAP-G-K8S.md). Błędy:
> RUNBOOK [#080–#082](../RUNBOOK.md#080).

---

## Spis treści

1. [Co zmienia się względem Compose](#1-co-zmienia-się-względem-compose)
2. [Mapa: co powstało i gdzie](#2-mapa-co-powstało-i-gdzie)
3. [Krok 1 — chart i trzy decyzje w values.yaml](#krok-1--chart-i-trzy-decyzje-w-valuesyaml)
4. [Krok 2 — ServiceMonitor: cel scrape'owania jako obiekt](#krok-2--servicemonitor-cel-scrapeowania-jako-obiekt)
5. [Krok 3 — eksporter Postgresa jako sidecar](#krok-3--eksporter-postgresa-jako-sidecar)
6. [Krok 4 — te same reguły: generator i strażnik w CI](#krok-4--te-same-reguły-generator-i-strażnik-w-ci)
7. [Krok 5 — dashboardy z repo przez sidecar Grafany](#krok-5--dashboardy-z-repo-przez-sidecar-grafany)
8. [Krok 6 — instalacja i dostęp przez tunel](#krok-6--instalacja-i-dostęp-przez-tunel)
9. [Krok 7 — weryfikacja na klastrze i co wyszło](#krok-7--weryfikacja-na-klastrze-i-co-wyszło)
10. [Ile to kosztuje](#ile-to-kosztuje)
11. [Pułapki, które wybuchły po drodze](#pułapki-które-wybuchły-po-drodze)
12. [Co z tego na CKAD](#co-z-tego-na-ckad)
13. [Czego jeszcze nie ma](#czego-jeszcze-nie-ma)

---

## 1. Co zmienia się względem Compose

| | Compose (ETAP 11) | k3s (G1) |
|---|---|---|
| Kto uruchamia Prometheusa | `docker compose` z `prometheus.yml` | **Prometheus Operator** z obiektu `Prometheus` (CRD) |
| Lista celów | `static_configs` z nazwami kontenerów | **ServiceMonitor**: selektor etykiet Service'u + nazwa portu |
| Reguły alertów | plik montowany w kontener | **PrometheusRule** (CRD), operator składa z nich konfigurację |
| Dashboardy | provisioning z katalogu | ConfigMapy z etykietą, wczytywane przez **sidecar** Grafany |
| Metryki maszyny i obiektów | brak | node-exporter (DaemonSet) i kube-state-metrics |
| Dostęp | port na `localhost` | `kubectl port-forward` przez tunel SSH |

Największa różnica jest w kroku 2. W Compose cel to adres (`catalog-app:2020`),
a w Kubernetesie to **opis**: „Service'y z etykietą `app=catalog-app`, port o
nazwie `metrics`”. Pody się zmieniają, IP też, a opis zostaje.

## 2. Mapa: co powstało i gdzie

```
deploy/monitoring/
  values.yaml            ← różnice względem domyślnych wartości chartu
  servicemonitors.yaml   ← 5 ServiceMonitorów (catalog, rabbitmq, search, glitchtip, postgres)
  prometheus-rule.yaml   ← WYGENEROWANY z infra/obs/prometheus/rules/marketplace.yml
  kustomization.yaml     ← powyższe + ConfigMapy z dashboardami z infra/obs/grafana/
deploy/k8s/
  postgres.yaml          ← + kontener `exporter` (postgres-exporter) w podzie bazy
  search-consumer.yaml   ← + Deployment/Service `search-metrics` (endpoint /metrics)
  catalog.yaml, rabbitmq.yaml, glitchtip.yaml ← etykiety `app` i nazwane porty metryk
tools/k3s/
  install-monitoring.sh  ← make k3s-monitoring
  gen-prometheus-rule.rb ← make k3s-monitoring-rules (i --check w CI)
  monitoring-check.sh/.rb← make k3s-monitoring-check (bez klastra, w CI)
  verify.sh              ← + faza `monitoring`
```

Monitoring jest **osobnym** katalogiem kustomize, nie częścią `deploy/k8s`.
ServiceMonitor i PrometheusRule to typy z CRD instalowanych przez chart. Gdyby
leżały w `deploy/k8s`, `make k3s-deploy` na świeżym klastrze (bez chartu)
padałby na `no matches for kind "ServiceMonitor"`.

## Krok 1 — chart i trzy decyzje w values.yaml

`kube-prometheus-stack` to jeden chart Helma z operatorem, Prometheusem,
Alertmanagerem, Grafaną, node-exporterem i kube-state-metrics. Wersja jest
przypięta w `install-monitoring.sh` (`CHART_VERSION="92.17.1"`), a
`values.yaml` zawiera **tylko różnice** względem domyślnych wartości. Pełną
listę daje `helm show values prometheus-community/kube-prometheus-stack --version 92.17.1`.

Trzy decyzje, bez których nic by nie działało:

**1. Selektory bez etykiety `release`.**

```yaml
prometheus:
  prometheusSpec:
    serviceMonitorSelectorNilUsesHelmValues: false
    ruleSelectorNilUsesHelmValues: false
```

Domyślnie operator bierze tylko ServiceMonitory z etykietą `release: kps`
(nazwa releasu Helma). Nasze obiekty leżą w repo, poza chartem, i tej etykiety
nie mają. Bez tych flag Prometheus by ich nie widział i **nie zgłosiłby żadnego
błędu**: po prostu zero celów. To najczęstsze „dlaczego mój ServiceMonitor nic
nie robi”. Z flagami selektor w obiekcie `Prometheus` to `{}`, czyli wszystkie.

**2. Wyłączone komponenty, których k3s nie ma osobno.**

```yaml
kubeControllerManager: { enabled: false }
kubeScheduler: { enabled: false }
kubeProxy: { enabled: false }
kubeEtcd: { enabled: false }
```

W k3s kontroler, scheduler, proxy i etcd żyją w **jednym** procesie `k3s server`
i nie wystawiają endpointów metryk tam, gdzie chart ich szuka. Z domyślnymi
wartościami od pierwszej minuty byłyby 4 cele `down` i alerty
`KubeControllerManagerDown` itp. Wyłączone są też odpowiadające im grupy
`defaultRules`. Efekt widać po wdrożeniu: jedyny świecący alert to `Watchdog`,
który świeci zawsze (to test ścieżki alertów).

**3. 15 s jak w Compose.**

```yaml
    scrapeInterval: 15s
    evaluationInterval: 15s
```

Domyślne jest 30 s. Reguły z ETAPU 11 zakładają co najmniej 4 próbki w oknie
`rate(...[1m])` i 4 oceny na `for: 1m`. Przy 30 s byłyby 2 próbki, rate na
granicy, a alerty migałyby. Testy promtool przechodziłyby dalej, bo mają
własny `interval: 15s`. Tę zależność trzeba znać, test jej nie złapie.

Reszta: retencja `7d` **i** `retentionSize: 4GB` (dysk VPS dzieli się z
Elasticsearchem, a limit rozmiaru działa niezależnie od czasu), PVC 5Gi na
`local-path`, requesty i limity pamięci dla każdego komponentu, Grafana bez
trwałego dysku (wszystko jest w repo) i z hasłem admina z Secretu
`grafana-admin`. Ten Secret tworzy skrypt przy pierwszej instalacji. Bez
`existingSecret` chart generowałby nowe hasło przy każdym `helm upgrade`.

## Krok 2 — ServiceMonitor: cel scrape'owania jako obiekt

```yaml
apiVersion: monitoring.coreos.com/v1
kind: ServiceMonitor
metadata: { name: catalog, namespace: monitoring }
spec:
  namespaceSelector: { matchNames: [marketplace] }
  selector: { matchLabels: { app: catalog-app } }   # etykieta SERVICE'U
  endpoints:
    - port: metrics                                  # NAZWA portu w Service
      path: /metrics
      relabelings: [{ targetLabel: job, replacement: caddy }]
    - port: metrics
      path: /prometheus
      relabelings: [{ targetLabel: job, replacement: catalog }]
```

Trzy rzeczy muszą się zgadzać, a żadna niezgodność nie daje błędu:

1. **Etykieta na Service**, nie na podzie. Selektor ServiceMonitora patrzy na
   `metadata.labels` Service'u. Dlatego Service'y dostały `labels: { app: ... }`,
   choć wcześniej miały tylko `selector`.
2. **Nazwany port.** `port: metrics` to nazwa z `spec.ports[].name` Service'u.
   Numer by nie zadziałał. Stąd nowe porty `metrics: 2020` (catalog),
   `prometheus: 15692` (RabbitMQ), `metrics: 9187` (Postgres).
3. **`job` takie jak w Compose.** Operator nadaje domyślnie
   `job=<nazwa Service'u>` (`catalog-app`). Reguły z ETAPU 11 pytają o
   `job="caddy"`, `job="catalog"`, `job="rabbitmq-queues"`. `relabelings`
   nadpisuje etykietę, więc reguły działają bez zmian.

RabbitMQ ma dwa endpointy z jednego portu: `/metrics` (zagregowane) i
`/metrics/detailed` z parametrami `family` (per kolejka). To odpowiednik dwóch
jobów z `prometheus.yml`:

```yaml
    - port: prometheus
      path: /metrics/detailed
      params: { family: [queue_coarse_metrics, queue_consumer_count, queue_delivery_metrics] }
      relabelings: [{ targetLabel: job, replacement: rabbitmq-queues }]
```

**search-metrics.** W Compose metryki search wystawiał osobny kontener, bo
konsument Messengera to proces CLI bez HTTP. Na k3s jest tak samo: ten sam
obraz search uruchomiony jako `frankenphp php-server --root /app/public --listen :80`,
w osobnym Deploymencie `search-metrics` z Service'em. Konsument dalej robi
tylko jedno.

Jak sprawdzić, że cel jest, zanim zajrzysz do UI:

```bash
make k3s-prometheus     # http://localhost:39090/targets
```

albo bez port-forwardu, przez proxy API serwera (tak robi `verify.sh`):

```bash
kubectl get --raw '/api/v1/namespaces/monitoring/services/kps-prometheus:9090/proxy/api/v1/query?query=up' \
  | jq -r '.data.result[] | "\(.metric.job) \(.value[1])"' | sort | uniq -c
```

## Krok 3 — eksporter Postgresa jako sidecar

Postgres nie wystawia metryk HTTP. Potrzebny jest `postgres-exporter`, który
łączy się z bazą i tłumaczy `pg_stat_*` na format Prometheusa. Można go
wdrożyć jako osobny Deployment, ale tu siedzi **w tym samym podzie** co baza:

```yaml
      annotations:
        kubectl.kubernetes.io/default-container: postgres
      containers:
        - name: postgres
          # ...bez zmian
        - name: exporter
          image: quay.io/prometheuscommunity/postgres-exporter:v0.20.1
          env:
            - { name: DATA_SOURCE_URI, value: "localhost:5432/postgres?sslmode=disable" }
            - name: DATA_SOURCE_USER
              valueFrom: { configMapKeyRef: { name: marketplace-config, key: POSTGRES_USER } }
            - name: DATA_SOURCE_PASS
              valueFrom: { secretKeyRef: { name: marketplace-secrets, key: POSTGRES_PASSWORD } }
          ports: [{ name: metrics, containerPort: 9187 }]
```

Kontenery w podzie dzielą sieć, więc eksporter łączy się po `localhost` i
żyje dokładnie tyle co baza, na tym samym węźle. To wzorzec **adapter**
(odmiana sidecara): tłumaczy interfejs aplikacji na interfejs, którego
oczekuje świat.

Dwie rzeczy, które łatwo przeoczyć:

- **Adnotacja `default-container`.** Z dwoma kontenerami `kubectl exec postgres-0 -- psql`
  pyta, który kontener wybrać, albo bierze pierwszy. Adnotacja wskazuje bazę,
  więc istniejące skrypty (`backup`, `verify`) działają bez `-c postgres`.
- **Zmiana szablonu StatefulSetu restartuje pod.** Dodanie kontenera to nowy
  `template`, więc `postgres-0` zostaje usunięty i utworzony od nowa. Przy
  jednej replice oznacza to około pół minuty przerwy. Planuj to.

Eksporter łączy się jako superużytkownik. To świadomy dług, opisany w
[RUNBOOK #080](../RUNBOOK.md#080) z krokami spłaty (rola `pg_monitor`).

## Krok 4 — te same reguły: generator i strażnik w CI

PrometheusRule to po prostu reguły z `marketplace.yml` owinięte w obiekt
Kubernetesa (`spec.groups` = `groups` z pliku). Kopiowanie ręczne rozjechałoby
się przy pierwszej zmianie, więc plik dla klastra jest **generowany**:

```bash
make k3s-monitoring-rules     # ruby tools/k3s/gen-prometheus-rule.rb > deploy/monitoring/prometheus-rule.yaml
```

Na górze wygenerowanego pliku stoi `WYGENEROWANE … NIE EDYTUJ RĘCZNIE`, a CI
uruchamia generator z `--check`:

```
NIEAKTUALNY — uruchom: make k3s-monitoring-rules
```

Źródłem prawdy zostaje `infra/obs/prometheus/rules/marketplace.yml` razem z
testami promtool (`make obs-check`). Klaster dostaje dokładnie to, co
przetestowano.

`make k3s-monitoring-check` (też w CI, workflow `prod-compose.yml`) renderuje
`deploy/k8s`, `deploy/monitoring` i `helm template` chartu, a potem sprawdza
rzeczy, które na klastrze **nie dałyby żadnego błędu**:

```
ServiceMonitory -> Service w deploy/k8s
  ✓ catalog: selektor {"app"=>"catalog-app"} trafia dokładnie w 1 Service (["catalog-app"])
  ✓ catalog: port 'metrics' istnieje w Service
  ...
Reguły marketplace
  ✓ PrometheusRule marketplace ma 18 alertów (jest 18)
  ✓ job="rabbitmq-queues" z reguł ma cel (ServiceMonitor)
  ...
Chart kube-prometheus-stack (values.yaml)
  ✓ serviceMonitorSelector = {} (bez wymogu etykiety release; jest {})
  ✓ brak ServiceMonitora kube-etcd (k3s: jeden proces)
  ✓ Deployment kps-grafana: limity pamięci
OK
```

Najcenniejsza asercja: każdy `job="X"` użyty w wyrażeniach reguł ma
ServiceMonitor, który go nadaje. Literówka w nazwie portu albo etykiecie
oznacza zero celów. Alert `TargetDown` wtedy **milczy**, bo nie ma celu,
który mógłby uznać za down. Sprawdziłem, że test to łapie: zmiana nazwy portu
na `prometheuz` daje 2 × `✗`.

## Krok 5 — dashboardy z repo przez sidecar Grafany

Grafana w chartcie ma kontener `grafana-sc-dashboard` (k8s-sidecar). Obserwuje
ConfigMapy z etykietą `grafana_dashboard: "1"` i zapisuje ich zawartość jako
pliki dashboardów. Nasze dwa JSON-y z `infra/obs/grafana/dashboards/` zamienia
w ConfigMapy kustomize:

```yaml
generatorOptions:
  disableNameSuffixHash: true
  labels: { grafana_dashboard: "1" }
  annotations: { grafana_folder: Marketplace }
configMapGenerator:
  - name: dashboard-marketplace-overview
    files: [marketplace-overview.json=../../infra/obs/grafana/dashboards/marketplace-overview.json]
```

Pliki leżą poza katalogiem kustomize, więc `kubectl kustomize` potrzebuje
`--load-restrictor LoadRestrictionsNone`. Dashboardy wskazują źródło danych po
`uid: prometheus`, dlatego w values źródło dostało ten sam uid co w Compose.

Po instalacji w folderze „Marketplace” są dwa dashboardy z repo, a obok
kilkadziesiąt dashboardów Kubernetesa z chartu („Kubernetes / Compute
Resources / Namespace (Pods)”, „Node Exporter / Nodes”). To pierwsze miejsce,
w którym widać, ile pamięci naprawdę bierze Elasticsearch.

## Krok 6 — instalacja i dostęp przez tunel

```bash
make k3s-monitoring
```

Skrypt robi trzy rzeczy: namespace i Secret `grafana-admin` (tylko jeśli go
nie ma; hasło `openssl rand -hex 16`), `helm upgrade --install … --wait`, a
potem `kubectl apply` obiektów z `deploy/monitoring`. Jest idempotentny:
ponowne uruchomienie to `helm upgrade` z nową rewizją i `configured` /
`unchanged` dla obiektów.

```
Release "kps" does not exist. Installing it now.
STATUS: deployed
[k3s-monitoring] 3/3 obiekty marketplace: ServiceMonitory, PrometheusRule, dashboardy
prometheus-rule.yaml aktualny
configmap/dashboard-marketplace-overview created
prometheusrule.monitoring.coreos.com/marketplace created
servicemonitor.monitoring.coreos.com/catalog created
...
```

Potem `make k3s-deploy`, żeby wdrożyć zmiany w manifestach sklepu (etykiety,
porty, sidecar, `search-metrics`):

```
statefulset.apps/postgres  1/1  ghcr.io/dommmin/elastic-postgres:97dd…,quay.io/prometheuscommunity/postgres-exporter:v0.20.1
deployment.apps/search-metrics  1/1
```

Nic nie jest wystawione publicznie. Dostęp jest przez tunel SSH do API k3s i
`port-forward`, każde polecenie w osobnym terminalu:

```bash
make k3s-grafana        # http://localhost:33000 (login admin, hasło wypisuje make)
make k3s-prometheus     # http://localhost:39090
make k3s-alertmanager   # http://localhost:39093
```

**Powiadomienia.** Domyślna konfiguracja Alertmanagera z chartu kieruje
wszystko do odbiorcy `"null"`. Alerty widać w UI i w Grafanie, ale nikt nie
dostaje maila, bo na serwerze nie ma SMTP. Kanał to jedna sekcja w values:

```yaml
alertmanager:
  config:
    route:
      receiver: mail
      routes: [{ matchers: ['alertname = "Watchdog"'], receiver: "null" }]
    receivers:
      - name: "null"
      - name: mail
        email_configs: [{ to: ..., from: ..., smarthost: ..., auth_password_file: ... }]
```

## Krok 7 — weryfikacja na klastrze i co wyszło

```bash
make k3s-verify faza=monitoring
```

Faza sprawdza: pody Ready, każdy z 7 jobów ma cel i jest `up`, żaden cel w
klastrze nie jest down, 18 reguł załadowanych, `pg_up=1`, metryka domenowa
catalog, 2 dashboardy w API Grafany i **całą ścieżkę alertu**. Tymczasowy
PrometheusRule z `expr: vector(1)` ma dotrzeć do Alertmanagera, potem jego
wyrażenie zmienia się na puste i alert ma się rozwiązać. Na końcu reguła jest
usuwana.

Pierwsze uruchomienie dało 13/15. Obie czerwone pozycje były błędami testu,
a nie klastra, i obie czegoś uczą:

- **Grafana: `exec: "sh": executable file not found`.** Obraz
  `grafana:13.3.0-distroless` nie ma powłoki ani `wget`. Pytanie do API idzie
  teraz z kontenera sidecara, który ma Pythona i to samo hasło admina w
  `REQ_USERNAME`/`REQ_PASSWORD` (używa ich do przeładowania dashboardów).
- **Alert testowy „timeout”, choć ręcznie dochodził po 64 s.** Reguła nigdy
  nie powstała. `k apply -f - <<YAML` szło przez wrapper z timeoutem, który
  uruchamia kubectl w tle (`&`). Nieinteraktywny bash daje takim procesom
  stdin z `/dev/null`, więc heredoc nie dotarł, a stderr był schowany.
  Szczegóły w [RUNBOOK #082](../RUNBOOK.md#082). Teraz YAML idzie przez plik,
  a `check` sprawdza, że apply się udał.
- Po tej poprawce wyszła trzecia rzecz: **po skasowaniu reguły Prometheus nie
  wysyła „resolved”**. Alert znika z Prometheusa razem z regułą, a Alertmanager
  trzyma go do `endsAt`, czyli kilka minut. Poprawna kolejność to najpierw
  `expr: vector(1) unless vector(1)` (pusty wynik, Prometheus wysyła resolved),
  potem `delete`.

Wynik po poprawkach:

```
  ✓ cel job=caddy: up (min po celach = 1)
  ...
  ✓ 18 reguł alertów z grupy marketplace załadowanych
  ✓ eksporter Postgresa widzi bazę (pg_up)
  ✓ Grafana: dashboardy marketplace-overview i marketplace-ordering
  ✓ reguła testowa verify-g1-pipeline utworzona
  ✓ alert testowy dotarł do Alertmanagera (75 s)
  ✓ alert testowy rozwiązany, gdy warunek zniknął (70 s)
  alerty firing teraz: Watchdog
  PASS=16 FAIL=0
```

75 s to suma: operator przepisuje regułę do ConfigMapy, kubelet odświeża
zamontowany wolumen (do ~60 s), config-reloader przeładowuje Prometheusa,
potem jedna ocena. Zmiana reguły na klastrze **nie jest natychmiastowa**.

`make k3s-verify faza=exposure` sprawdza nowe porty z zewnątrz. node-exporter
działa w sieci hosta (`hostNetwork`), więc słucha na 9100 publicznego adresu
VPS i zamknięty jest tylko dzięki ufw:

```
  ✓ 9100/tcp zamknięty
  ✓ 9187/tcp zamknięty
  ✓ 15692/tcp zamknięty
  PASS=17 FAIL=0
```

**Najważniejsze znalezisko: wszystkie cele zielone, a jedna reguła ślepa.**
Panel „HTTP catalog: req/s per kod” był pusty. Zapytanie
`sum by (server,host,code) (rate(caddy_http_request_duration_seconds_count{job="caddy"}[5m]))`
pokazało tylko `host="_other"`. Na k3s `SERVER_NAME=:80` to blok Caddy bez
nazwy hosta, a `metrics { per_host }` daje wtedy `_other` całemu ruchowi,
dokładnie tak jak scrape'om na `:2020`. Reguła `CatalogHttpErrorRate`
filtrowała po `host=~"catalog\.localhost…"` i na k3s nie miała czego liczyć.
`up == 1` tego nie pokaże.

Naprawa ([RUNBOOK #081](../RUNBOOK.md#081)):

1. Stała nazwa serwera metryk w Caddyfile, zamiast polegać na `srv0`/`srv1`:
   ```
   servers :2020 {
       name metrics
   }
   ```
   Sprawdzone bez stacku przez `frankenphp adapt` dla obu wartości `SERVER_NAME`:
   `["metrics",":2020"]` jest w obu, aplikacja to `srv1`.
2. Reguła i panele wybierają `{job="caddy",server!="metrics"}`.
3. Najpierw testy promtool: przypadek k3s (`host="_other"`, ma odpalić) i
   ruch na serwerze `metrics` (ma milczeć). Oba czerwone na starej regule,
   zielone na nowej.

Do czasu wdrożenia nowego obrazu catalog serwer metryk na k3s nazywa się
jeszcze `srv0`, więc reguła liczy też scrape'y (0.13 req/s samych 200).
Udział 5xx jest przez to lekko rozwodniony, ale reguła już działa.

## Ile to kosztuje

Zmierzone `kubectl top` po instalacji, przy ruchu bliskim zera:

| Pod | Pamięć | CPU |
|---|---|---|
| Grafana (grafana 275Mi + 2 sidecary po ~78Mi) | 431Mi | 35m |
| Prometheus (+ config-reloader) | 409Mi | 59m |
| Alertmanager | 34Mi | 4m |
| operator | 24Mi | 8m |
| kube-state-metrics | 21Mi | 3m |
| node-exporter | 10Mi | 14m |
| **razem** | **~930Mi** | |

Prometheus ma ~92 tys. serii w głowie TSDB z 20 celów. Węzeł po instalacji
pokazuje 11.4 GiB z 23 GiB (47%).

Pomiar zmienił values. Grafana miała limit 384Mi przy zużyciu 275Mi (72%),
a oba sidecary nie miały **żadnych** requestów ani limitów. Scheduler nie
wiedział o ~155Mi. `monitoring-check.rb` je przepuszczał, bo miał wyjątek dla
nazw `sidecar|grafana-sc`. Wyjątek usunięty (check od razu się zaczerwienił),
Grafana dostała request 256Mi i limit 512Mi, sidecary 80Mi i 160Mi. Zasada:
request to typowe zużycie, limit to zapas.

## Pułapki, które wybuchły po drodze

| # | Objaw | Przyczyna |
|---|---|---|
| [080](../RUNBOOK.md#080) | eksporter jako superużytkownik (dług) | skrypty initdb działają tylko na pustym PVC |
| [081](../RUNBOOK.md#081) | cele `up`, a `CatalogHttpErrorRate` i panele HTTP ślepe | `per_host` na k3s daje wszędzie `host="_other"` |
| [082](../RUNBOOK.md#082) | `apply -f -` w verify.sh nic nie tworzy | proces w tle dostaje stdin z `/dev/null` |
| — | długie nazwy `kps-kube-prometheus-stack-prometheus` | `fullnameOverride: kps` w values |
| — | `exec: "sh": not found` w Grafanie | obraz distroless, pytamy z sidecara |

## Co z tego na CKAD

- **Wzorce wielokontenerowe:** sidecar/adapter (eksporter w podzie bazy),
  adnotacja `kubectl.kubernetes.io/default-container`, `kubectl top pod --containers`.
- **Etykiety i selektory:** ServiceMonitor wybiera Service'y po etykietach,
  Service wybiera pody po etykietach. To dwa różne miejsca na etykiety.
- **Nazwane porty:** `targetPort: metrics` w Service i `port: metrics` w
  ServiceMonitorze. Zmiana numeru nie wymaga zmian w trzech plikach.
- **Requesty i limity z pomiaru**, nie na oko. Kontener bez requestu jest
  dla schedulera niewidoczny.
- **ConfigMapa jako wolumen odświeża się z opóźnieniem** (do ~60 s): stąd
  75 s od `apply` reguły do alertu.
- **CRD:** obiekt typu, którego API serwer nie zna, odpada przy `apply`. Stąd
  osobny katalog `deploy/monitoring`.

## Czego jeszcze nie ma

- **Powiadomienia** idą do odbiorcy `"null"`. Brak SMTP na serwerze, a przykład
  konfiguracji jest wyżej.
- **Eksporter na najmniejszych uprawnieniach** ([#080](../RUNBOOK.md#080)).
- **Metryki Elasticsearcha** z ECK (`elasticsearch-exporter` albo ServiceMonitor
  na endpoint ECK). Dashboardy Kubernetesa pokazują na razie tylko pamięć i CPU podów ES.
- **Nazwa serwera `metrics` na k3s** wejdzie z obrazem catalog zbudowanym po
  merge. Potem `sum by (server) (rate(caddy_http_request_duration_seconds_count[5m]))`
  ma pokazać `metrics` i `srv1`.
- **Recording rules i SLO** (punkt 6 z oceny), jeśli wrócimy do Prometheusa
  głębiej.

Następny etap: **G2**, czyli tematy CKAD w manifestach (Ingress z TLS,
NetworkPolicy, HPA, securityContext, ServiceAccount/RBAC, startupProbe,
canary). Plan w [`13-PLAN-ETAP-G-K8S.md`](../13-PLAN-ETAP-G-K8S.md).
