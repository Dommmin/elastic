# G4 — „zepsuj i napraw” na produkcji k3s (projekt)

Data: 2026-11-07 · Gałąź: `g4-chaos` · Plan nadrzędny: [`docs/13-PLAN-ETAP-G-K8S.md`](../../13-PLAN-ETAP-G-K8S.md) § G4

## 1. Cel i granica

Ćwiczenie diagnozy awarii na **prawdziwym** sklepie (namespace `marketplace` na k3s),
zaczynając od sygnału, który na produkcji przychodzi pierwszy: alertu z G1, dashboardu
Grafany albo objawu w sklepie. Każdy scenariusz to jedna realistyczna awaria
z jednej linijki konfiguracji, którą uczeń znajduje i naprawia sam w `kubectl`.

Decyzja użytkownika (2026-11-07): **produkcja, z bezpiecznikami** — nie kopia
w osobnym namespace i nie Compose. Uzasadnienie: tylko na produkcji działają naraz
alerty z G1, NetworkPolicy i RBAC z G2 oraz trzywęzłowy ES; kopia kosztowałaby
3–4 GB RAM i dawała gorsze sygnały.

**Granica (twarda):**

- żaden scenariusz nie dotyka `catalog-app` (Deployment, jego Secret, ConfigMap,
  NetworkPolicy `catalog-app-ingress`) — sklep cały czas serwuje strony; psują się
  procesy w tle: wyszukiwarka, kolejka, joby, metryki („degradacja, nie awaria”);
- żaden scenariusz nie zmienia danych: nie kasuje PVC, wiadomości, wierszy ani
  indeksów ES; wszystko jest zmianą konfiguracji odwracalną z migawki;
- dzielony Secret `marketplace-secrets` nie jest modyfikowany (zmiana dotknęłaby
  `catalog-app` przy najbliższym restarcie).

## 2. Interfejs

```text
make chaos-list                         # scenariusze + krótki objaw (bez rozwiązań)
make chaos-break s=<scenariusz> [ttl=15] # preflight → blokada → awaria → Job wycofania
make chaos-check s=<scenariusz>          # ✓/✗ wymagań „zdrowy”, czas od break, zapis wyniku
make chaos-reset                         # siłowe przywrócenie trwającego scenariusza
make chaos-status                        # co trwa, od kiedy, kiedy auto-wycofanie
make k3s-verify faza=chaos [alerts=1]    # selftest wszystkich scenariuszy
```

Pod spodem: `tools/chaos/chaos.sh list|break|check|reset|status <s>`.
Wynik zapisu (`~/.chaos-scores.tsv`): data, scenariusz, czas do zdrowego `check`,
czy był `reset` (naprawa siłowa) — analogicznie do `~/.ckad-scores.tsv`.

Przebieg ćwiczenia: `break` drukuje tylko pierwszą sekcję karty (objaw + od czego
zacząć). Uczeń diagnozuje, naprawia (`kubectl edit/patch/set/rollout`, `rabbitmqctl`),
uruchamia `check`. Zielony `check` zdejmuje blokadę i kasuje Job wycofania —
**naprawa ucznia jest naprawą**, nie wymaga `reset`.

## 3. Bezpieczniki

### 3.1 Preflight (`break` odmawia, gdy)

- trwa inny scenariusz (istnieje ConfigMap `chaos-lock`);
- ES nie jest `green`, którykolwiek Deployment/StatefulSet w `marketplace` nie ma
  pełnej gotowości, albo w Alertmanagerze płonie alert z grupy `marketplace`
  (awaria na już chorym systemie myli diagnozę i grozi kaskadą);
- tunel do API nie działa (komunikat z `make k3s-tunnel`).

### 3.2 Blokada i migawka

ConfigMap `chaos-lock` w `marketplace` z etykietą `chaos.lab/lock=true`:

| klucz | treść |
|---|---|
| `scenario` | nazwa scenariusza |
| `started` | epoch startu |
| `deadline` | epoch auto-wycofania |
| `snapshot.yaml` | oryginalne obiekty sprzed awarii (`kubectl get -o yaml`, oczyszczone z `status`, `resourceVersion`, `uid`, `managedFields`, adnotacji `last-applied`) |
| `extra` | dane scenariusza nie będące obiektem K8s (np. pierwotny `disk_free_limit`) |

Migawka z klastra, nie z repo: przywraca dokładnie stan sprzed awarii (obraz,
repliki z HPA), a nie stan z gałęzi, która może się różnić od wdrożonej.

### 3.3 Automatyczne wycofanie w klastrze

`break` tworzy Job `chaos-undo` z `activeDeadlineSeconds` = ttl + 10 min:
kontener `sleep ttl` → `undo` scenariusza → usunięcie blokady. Działa w klastrze,
więc zamknięty laptop albo zerwany tunel nie zostawiają produkcji zepsutej.

- Obraz: `alpine/k8s` (kubectl + bash; wersja przypięta w manifeście).
  Skrypty scenariuszy montowane z ConfigMap `chaos-scripts` generowanej przy `break`
  z `tools/chaos/` (ten sam kod co lokalnie — jedna implementacja `undo`).
- ServiceAccount `chaos-undo` + Role w `marketplace` (repo: `deploy/chaos/undo-rbac.yaml`,
  stosowane przez `break`, nie przez kustomization sklepu): `get/patch/update` na
  deployments, statefulsets, networkpolicies; `get/delete` na configmaps (tylko
  `chaos-lock`, przez `resourceNames`) i secrets (tylko `chaos-*` — patrz bad-secret);
  `create` na `pods/exec` (rabbitmqctl); `patch` na elasticsearches.
  Bez uprawnień do czegokolwiek poza `marketplace`.
- NetworkPolicy: Job potrzebuje tylko API servera (egress z namespace jest dozwolony
  w obrębie klastra; API po adresie usługi `kubernetes.default`). Sprawdzane
  w selftest — jeśli `default-deny` blokuje API, dochodzi polityka `chaos-undo-egress`.
- Budżet: requests 50m / 64Mi, limit 128Mi, jeden pod przez czas trwania scenariusza.

Zielony `check` albo `reset` kasuje Job (nie czeka na sleep).

### 3.4 Wycofanie jest idempotentne

`undo` każdego scenariusza da się wywołać dwukrotnie i na zdrowym klastrze
bez skutków ubocznych (`reset` po naprawie ucznia, Job po `reset`).

## 4. Scenariusze

Każdy plik `tools/chaos/scenarios/<s>.sh` definiuje: `targets()` (obiekty do migawki),
`break()`, `check()` (wymagania „zdrowy” jako asercje), `undo()`, oraz zmienne
`ALERT` (oczekiwany alert, może być pusty) i `SUMMARY` (jedno zdanie objawu do `list`).

| `s` | Awaria (jedna zmiana) | Pierwszy sygnał | `check` = zdrowy, gdy |
|---|---|---|---|
| `oom` | limit pamięci kontenera `catalog-worker` → 40Mi | `KubePodCrashLooping` (reguły chartu), restarty w Grafanie; `describe` → `OOMKilled` | Deployment ready, 0 restartów w ostatnich 2 min, limit ≥ 128Mi |
| `bad-secret` | `search-consumer` przepięty na nowy Secret `chaos-rotated-secrets` (kopia z literówką w haśle w `MESSENGER_TRANSPORT_DSN`) — historia „rotacja hasła się nie udała” | `SearchConsumerDown`; logi: `ACCESS_REFUSED` | konsument ready i pobiera z `search.product.sync`; Deployment nie odwołuje się do `chaos-rotated-secrets` |
| `queue-blocked` | polityka RabbitMQ `chaos-block` na `search.product.sync`: `max-length 0`, `overflow reject-publish` | `OutboxStale` (outbox nie może publikować) | brak polityki z `max-length` na kolejce; wiek najstarszego wpisu outbox < 60 s |
| `es-node-down` | ECK wstrzymany (`eck.k8s.elastic.co/managed=false`), StatefulSet `marketplace-es-default` 3→2 | `KubeStatefulSetReplicasMismatch`; ES `yellow`, nieprzydzielone repliki; wyszukiwarka działa — lekcja replik | 3/3 pody, ES `green`, ECK zarządza (`managed` nieobecne / `true`) |
| `netpol-db` | z `postgres-ingress` usunięty wpis wpuszczający `catalog-worker` | `CatalogFailedJobs`, timeouty w logach workera; sklep działa | worker łączy się z bazą (test połączenia z poda workera), polityka zawiera wpis dla workera |
| `bad-probe` | readiness `search-metrics` na ścieżkę `/healthz-old` | `SearchMetricsScrapeError` / `TargetDown`; pod 0/1, puste Endpoints | pod ready, Endpoints niepuste, cel `up == 1` |
| `disk-full` | `rabbitmqctl set_disk_free_limit` ponad wolne miejsce → alarm dysku | `OutboxStale`; RabbitMQ UI: „disk alarm”, połączenia `blocked` | brak alarmów (`rabbitmq-diagnostics alarms`), outbox świeży |

`disk-full` — dlaczego alarm, a nie zapełniony wolumen: StorageClass `local-path` nie
egzekwuje rozmiaru PVC (to katalog na dysku węzła), więc prawdziwe zapchanie
„wolumenu 2 Gi” zapchałoby cały dysk węzła ze sklepem i ES. Alarm `disk_free_limit`
jest dokładnie tym, co RabbitMQ robi przy pełnym dysku (blokuje wydawców), więc objaw
i diagnoza są takie same. Karta tłumaczy to jako osobną lekcję.

Szczegóły do potwierdzenia na klastrze w kroku 0 planu (§ 9).

## 5. Karty `docs/chaos/`

`docs/chaos/README.md` (jak grać, bezpieczniki, tabela scenariuszy generowana jak
spis CKAD) + `docs/chaos/<s>.md`:

1. `# <Tytuł>` + `**Oczekiwany alert:** … · **Limit:** N min`
2. `## Objaw` — co widzi użytkownik/operator (bez nazwy przyczyny)
3. `## Zacznij od` — alert, dashboard, pierwsze polecenie
4. `## Pytania prowadzące` — 3–5 pytań zawężających diagnozę
5. `## Po co to w życiu` — gdzie ten mechanizm żyje w tym projekcie (prawdziwy plik)
6. `## Rozwiązanie` w `<details>` — przyczyna, polecenie naprawy, jak temu zapobiec

`break` drukuje sekcje 1–3. Rozwiązanie nigdy nie jest na pierwszym ekranie.

## 6. Testy i weryfikacja

**Bez klastra (CI, `tools/chaos/test.sh`, w workflow z filtrem ścieżek
`tools/chaos/**`, `docs/chaos/**`):**

- `bash -n` i `shellcheck` dla `tools/chaos/**/*.sh`;
- każdy scenariusz definiuje `targets break check undo ALERT SUMMARY`;
- każdy scenariusz ma kartę z sekcjami 1–6, a `## Rozwiązanie` jest jedyną sekcją
  w `<details>` i ostatnią;
- `deploy/chaos/undo-rbac.yaml` przechodzi `kubectl apply --dry-run=client` (render);
- spis w `docs/chaos/README.md` jest aktualny.

**Na klastrze (`tools/k3s/verify.sh chaos`):** dla każdego scenariusza
preflight → `break` → `check` musi dać FAIL → `reset` → `check` musi dać PASS.
Dodatkowo raz: `break bad-probe ttl=1` i czekanie, aż Job `chaos-undo` sam
przywróci zdrowie (dowód bezpiecznika). `alerts=1`: po `break` czeka (max `for` + 3 min)
na `ALERT` w stanie `firing` w API Prometheusa.

Czas: ~25 min bez alertów, ~60 min z `alerts=1`.

## 7. Dokumentacja (definicja „etap skończony”)

- `docs/blog/etap-g4-chaos.md` — przewodnik krok po kroku: projekt bezpieczników,
  przebieg jednego scenariusza od alertu do naprawy, wyniki selftestu z klastra;
- RUNBOOK: wpis na scenariusz (objaw → przyczyna → naprawa) + wpis „chaos-undo nie
  zadziałał” (ręczne `make chaos-reset`, ręczne usunięcie blokady);
- `docs/13-PLAN-ETAP-G-K8S.md` (stan), `README.md` (tabela dokumentów).

## 8. Poza zakresem

- Chaos Mesh / Litmus (operator chaosu): dodatkowe CRD i ~300 MB RAM dla 7 scenariuszy,
  które robi kilka linii `kubectl`. Wspomniane w przewodniku jako następny krok.
- Awarie węzła/k3s, dysku hosta i sieci hosta — jeden węzeł, brak redundancji;
  to byłaby awaria całości, nie ćwiczenie diagnozy.
- Scenariusze dotykające `catalog-app` i danych (§ 1).
- Tryb „losowy scenariusz bez nazwy” — możliwy później (`s=random`), gdy karty
  okrzepną.

## 9. Krok 0 planu — fakty do potwierdzenia na klastrze

1. `KubePodCrashLooping` i `KubeStatefulSetReplicasMismatch` są w załadowanych regułach.
2. Job w `marketplace` dosięga API servera przy `default-deny` (albo potrzebna polityka).
3. `catalog-worker` ma osobny wpis/etykietę w `postgres-ingress` (inaczej `netpol-db`
   odcięłoby też `catalog-app` — wtedy scenariusz celuje w `outbox-publisher`).
4. Nazwa kolejki `search.product.sync` i vhost; czy polityka `chaos-block` nie koliduje
   z istniejącymi politykami (priorytet).
5. Ścieżka readiness `search-metrics` i jak szybko pada `SearchMetricsScrapeError`.
6. ECK 3.x: `managed=false` zatrzymuje rekoncyliację StatefulSetu; po zdjęciu ECK
   przywraca 3 repliki bez restartu pozostałych węzłów.
7. `rabbitmqctl` w podzie `rabbitmq-0` dostępny dla `pods/exec`; pierwotny
   `disk_free_limit` odczytywalny (`rabbitmq-diagnostics environment`).
