# Zepsuj i napraw na produkcyjnym k3s, krok po kroku

*ETAP G4 projektu Marketplace Search Platform. Po G1–G3 klaster ma alerty,
polityki sieciowe i RBAC, a Ty masz laboratorium CKAD. Brakuje jeszcze
umiejętności, której żaden zestaw zadań nie da: diagnozy awarii, której
nie wywołałeś sam, zaczynając od sygnału, który na produkcji przychodzi
pierwszy (alert, dashboard, dziwny objaw). Ten etap psuje prawdziwy sklep
na prawdziwym k3s, w siedmiu scenariuszach, tak że sklep cały czas serwuje
strony, a awaria zawsze sama się wycofa, nawet gdy zamkniesz laptopa.*

> **Stan:** gałąź `g4-chaos` (niewypchnięta).
> **Na klastrze (2026-11-07/08):** 6 z 7 scenariuszy przeszło selftest, fuse
> (bezpiecznik Joba) sprawdzony na 3 z 6, alert potwierdzony dla 5 z 6
> ([wyniki](#wyniki-z-klastra)); przebieg 1 na pierwszej wersji kodu dał PASS=10 FAIL=0,
> pełny czysty przebieg końcowego kodu czeka. Ostatni scenariusz (`es-node-down`)
> jest otwarty, czeka na decyzję ([Otwarte](#otwarte-es-node-down-i-repliki-spoiler)).
> **Scenariusze:** [docs/chaos/README.md](../chaos/README.md) (jak grać, bezpieczniki, spis).
> Projekt: [specyfikacja](../superpowers/specs/2026-11-07-g4-chaos-design.md).
> Plan: [`13-PLAN-ETAP-G-K8S.md`](../13-PLAN-ETAP-G-K8S.md).

---

## Spis treści

1. [Problem: ćwiczyć awarie tam, gdzie żyją alerty](#1-problem-ćwiczyć-awarie-tam-gdzie-żyją-alerty)
2. [Mapa plików](#2-mapa-plików)
3. [Krok 1 — preflight: nie psuj chorego systemu](#krok-1--preflight-nie-psuj-chorego-systemu)
4. [Krok 2 — blokada i migawka](#krok-2--blokada-i-migawka)
5. [Krok 3 — Job `chaos-undo`: bezpiecznik w klastrze](#krok-3--job-chaos-undo-bezpiecznik-w-klastrze)
6. [Krok 4 — uprawnienia Joba: RBAC i sieć](#krok-4--uprawnienia-joba-rbac-i-sieć)
7. [Krok 5 — scenariusz od alertu do naprawy (`disk-full`)](#krok-5--scenariusz-od-alertu-do-naprawy-disk-full)
8. [Krok 6 — selftest i dowody alertów](#krok-6--selftest-i-dowody-alertów)
9. [Wyniki z klastra](#wyniki-z-klastra)
10. [Co wybuchło po drodze](#co-wybuchło-po-drodze)
11. [Czego G4 nie robi](#czego-g4-nie-robi)
12. [Otwarte: `es-node-down` i repliki (spoiler)](#otwarte-es-node-down-i-repliki-spoiler)
13. [Ściąga](#ściąga)
14. [Twoja kolej](#twoja-kolej)

---

## 1. Problem: ćwiczyć awarie tam, gdzie żyją alerty

Są trzy miejsca na ćwiczenie awarii i każde ma wadę:

| Gdzie | Wada |
|---|---|
| Compose na Macu | Maszyna jest współdzielona z innymi projektami (RUNBOOK #022, #025). Poza tym nie ma tam NetworkPolicy, RBAC ani alertów z G1, czyli połowy rzeczy, po których awarię się poznaje. |
| Kopia sklepu w osobnym namespace | Kosztuje 3–4 GB RAM (trzywęzłowy ES, Postgres, RabbitMQ) i daje gorsze sygnały: puste dashboardy, brak historii. |
| Prawdziwy sklep | Awaria jest prawdziwa, więc istnieje ryzyko, że zostanie. |

Decyzja (2026-11-07): **produkcja, z bezpiecznikami**. Tylko tam działają naraz
alerty z G1, polityki i RBAC z G2 i ES na trzech węzłach, więc tylko tam
diagnoza wygląda tak, jak będzie wyglądać naprawdę: dostajesz alert, otwierasz
`kubectl`, a przyczyna jest gdzieś obok objawu.

Z tej decyzji wynika **twarda granica**, której pilnują scenariusze:

- żaden nie dotyka `catalog-app`, jego Secretu, ConfigMapy ani polityki
  `catalog-app-ingress`: sklep dalej serwuje strony. Psują się procesy w tle
  (wyszukiwarka, kolejka, joby, metryki), czyli „degradacja, nie awaria”;
- żaden nie zmienia danych: nie kasuje PVC, wiadomości, wierszy ani indeksów ES.
  Wszystko to zmiana konfiguracji odwracalna z migawki;
- dzielony Secret `marketplace-secrets` nie jest modyfikowany, bo zmiana
  dotknęłaby `catalog-app` przy najbliższym restarcie.

Cała reszta tego przewodnika to odpowiedź na pytanie: **jak sprawić, żeby
granica i powrót do zdrowia nie zależały od tego, że o nich pamiętasz.**

## 2. Mapa plików

```
docs/chaos/
  README.md                  jak grać, bezpieczniki, spis scenariuszy (generowany)
  <scenariusz>.md            karta: objaw, od czego zacząć, pytania, zwinięte rozwiązanie
tools/chaos/
  chaos.sh                   list | break | check | reset | status; tu też preflight
  lib.sh                     blokada, migawka, Job, wait_quiet, asercje; generuje undo-entry.sh
  scenarios/<s>.sh           SUMMARY, ALERT, LIMIT_MIN, targets, break, check, undo
  selftest.sh                break -> check FAIL -> reset x2 -> check PASS
  proof.sh                   fuse (Job wycofuje sam) i alert (alert naprawdę płonie)
  test.sh                    testy bez klastra (CI)
  index.sh                   spis scenariuszy w README
deploy/chaos/
  undo-rbac.yaml             ServiceAccount, Role, RoleBinding, NetworkPolicy dla Joba
Makefile                     chaos-list, chaos-break, chaos-check, chaos-reset, chaos-status, chaos-selftest
tools/k3s/verify.sh          faza chaos (poza `all`: psuje produkcję)
```

## Krok 1 — preflight: nie psuj chorego systemu

`make chaos-break s=<scenariusz>` zaczyna od odmowy. Awaria założona na systemie,
który już jest chory, myli diagnozę (nie wiesz, co jest czyje) i grozi kaskadą.
Preflight sprawdza, że:

- ES jest `green`,
- każdy Deployment i StatefulSet w `marketplace` jest gotowy,
- żaden alert sklepu nie płonie (grupa `marketplace` albo `namespace="marketplace"`;
  `Watchdog`, `InfoInhibitor` i `KubeJobFailed` pomijane),
- nie istnieje blokada innego scenariusza.

Ostatni punkt dotyczy pytania, które zadaje sobie każdy: „po co mi blokada, skoro
sam odpalam jeden scenariusz?”. Bo sesje się zdarzają równolegle (drugi terminal,
selftest w tle), a dwie awarie naraz to już nie ćwiczenie.

Jeden szczegół, który kosztował czas: błąd odczytu z Prometheusa albo pusta
odpowiedź **nie** oznaczają „zero alertów”. `wait_quiet` i preflight traktują je
jako „nie wiem”: preflight **odmawia** („Prometheus nie odpowiada”), a `wait_quiet`
(używany w selfteście między scenariuszami) **czeka**. Lepiej odmówić niż założyć
awarię w ciemno.

## Krok 2 — blokada i migawka

Blokada to ConfigMap `chaos-lock` z etykietą `chaos.lab/lock=true`, zakładana
atomowo przez `kubectl create` (drugi `create` kończy się błędem `AlreadyExists`,
więc nie ma okna na wyścig). Dwa skutki uboczne, oba zamierzone:

- `tools/k3s/deploy.sh` **odmawia wdrożenia**, gdy blokada istnieje. Gdyby
  wdrożenie przeszło w trakcie awarii, Job wycofujący przywróciłby po chwili
  migawkę ze starym obrazem i cofnął Ci wdrożenie.
- Blokada jest też miejscem na dane scenariusza (`extra`): to, co `undo` musi
  odtworzyć, a czego nie da się odczytać z migawki obiektów.

W blokadzie leży **migawka** (`snapshot.yaml`): obiekty, które scenariusz
zmieni, zapisane **z klastra, nie z repo**. To ważna różnica. Repo mówi, jak
sklep *powinien* wyglądać; migawka mówi, jak wyglądał przed awarią. Jeśli
ktoś ręcznie dołożył replikę albo HPA zmienił liczbę podów, powrót do wersji
z repo byłby zmianą, a powrót do migawki jest cofnięciem.

Przywracanie idzie surowym PUT (`kubectl replace --raw`) z dwóch powodów:

1. ten sam szablon poda co przed awarią znaczy, że Deployment **nie zrobi nowego
   rolloutu** (zwykły `apply` zmienia `metadata.generation` i rozpoczyna rollout);
2. adnotacja `kubectl.kubernetes.io/last-applied-configuration`, baza następnego
   `kubectl apply` w `deploy.sh`, zostaje nietknięta, więc trójstronne scalanie
   przy kolejnym wdrożeniu nie widzi żadnej różnicy.

Dowód w logu selftestu: po dwóch resetach pod rząd licznik `generation` nie rośnie
(`generation po reset 1: deployment/search-metrics=34`, `po reset 2: ...=34`).
Reset jest idempotentny, a sklep nie widzi żadnego rolloutu.

## Krok 3 — Job `chaos-undo`: bezpiecznik w klastrze

Najważniejsza decyzja etapu: **wycofanie żyje w klastrze, nie na Twoim laptopie.**
`break` zakłada Job `chaos-undo`, który śpi do `deadline` (domyślnie 15 minut,
`ttl=30` daje 30), po czym wywołuje `undo` scenariusza i zdejmuje blokadę.
Zamknięta klapa laptopa, zerwany tunel SSH albo zasypiająca sesja nie zostawiają
produkcji zepsutej.

Kolejność w `break` ma znaczenie:

> blokada → migawka → Job → awaria

Job powstaje **przed** awarią. Gdyby było odwrotnie, przerwanie między „psuje” a
„zakłada Job” zostawiłoby awarię bez zegara.

Dodatkowo `trap` w `break`: Ctrl-C albo błąd kroku wywołuje `undo` natychmiast.
Job i blokada znikają **dopiero po udanym undo**. Gdy undo padnie (w `break` albo
w `reset`), blokada zostaje, a Job jest zakładany od nowa z deadline za około 60 s.
Błąd API przy odczycie blokady nigdy nie jest brany za „blokady nie ma”.

Dlaczego Job, a nie CronJob albo `sleep` w osobnym podzie? `deadline` leży w
blokadzie (ConfigMap `chaos-lock`), więc pod go czyta i po restarcie wciąż wie, kiedy
wycofać; Job kończy się sam i ma `ttlSecondsAfterFinished: 3600`, więc log
da się przeczytać przez godzinę (`kubectl -n marketplace logs job/chaos-undo`),
a potem klaster sprząta za Ciebie. Skrypty (`lib.sh`, `scenarios/<s>.sh`,
`undo-entry.sh`, generowany w `lib.sh`) Job dostaje z ConfigMapy `chaos-scripts`
tworzonej przy `break` z **tego samego kodu**, który działa lokalnie. Jedna implementacja `undo`,
zero ryzyka, że lokalna i klastrowa się rozjadą.

Pod Joba spełnia profil `restricted` (uid 65534, `readOnlyRootFilesystem`,
`drop: [ALL]`, `seccompProfile: RuntimeDefault`, requests 50m/64Mi, limit 128Mi), tak jak reszta sklepu
po G2.

## Krok 4 — uprawnienia Joba: RBAC i sieć

Job w klastrze to zarazem konto z uprawnieniami do psucia i naprawiania, więc
granica „nie dotyka `catalog-app`” **nie może** zależeć od uczciwości skryptu.
`deploy/chaos/undo-rbac.yaml`:

- **Role `chaos-undo`:** każda reguła ma `resourceNames`, więc konto widzi tylko
  obiekty, które Job może przywrócić, a `list` nie ma wcale. Exec dostaje wyłącznie
  do jednego poda (brokera). Skutek: Job nie zmieni `catalog-app` i nie przeczyta
  `marketplace-secrets` ani Secretem, ani przez exec do poda, który ma go w env.
  Granicę egzekwuje API server. Konkretna lista obiektów zdradza, co psują
  scenariusze, więc jest w `deploy/chaos/undo-rbac.yaml`, a nie tutaj.
- **NetworkPolicy `chaos-undo-egress`:** `default-deny` z G2 zostaje w mocy. Job
  wychodzi tylko do API servera. To nie było oczywiste: przed napisaniem polityki
  sprawdziłem na klastrze (krok 0 planu), że pod z etykietą `app=chaos-undo`
  dostaje `Connection refused` zarówno na ClusterIP API (`10.43.0.1:443`), jak
  i na IP węzła. Odmowa jest natychmiastowa (REJECT), nie timeout, więc brak
  polityki wygląda jak „API nie istnieje”.

Nowy scenariusz oznacza dopisanie jego obiektu do Role. `test.sh` sprawdza, że
każde `targets()` jest pokryte (czerwony test przed pierwszym uruchomieniem na
klastrze, nie dopiero `Forbidden` o drugiej w nocy).

Statyczne RBAC ma etykietę `part-of=chaos-rbac` i zostaje w klastrze między
scenariuszami. Wszystko inne ma `part-of=chaos` i po scenariuszu znika. Czysty
klaster to zero obiektów `part-of=chaos`:

```bash
kubectl get all,cm,secret,netpol,sa,role,rolebinding,job -A -l app.kubernetes.io/part-of=chaos
```

Prawdziwe wyjście z klastra po zakończeniu przebiegów (2026-11-08, zapytanie tylko
do odczytu): `No resources found`. Tę samą kontrolę robi faza chaosu w `verify.sh`
(„po fazie: brak obiektów part-of=chaos poza RBAC”).

## Krok 5 — scenariusz od alertu do naprawy (`disk-full`)

Wybrałem `disk-full`, bo ma to, co w awarii na produkcji najcenniejsze: alert,
który mówi o skutku (outbox stoi), a przyczyna leży dwa komponenty dalej.

### 5.1 Zepsucie

```bash
make k3s-tunnel
make chaos-list                  # objawy bez rozwiązań
make chaos-break s=disk-full     # ttl domyślnie 15 min
```

`break` przechodzi preflight, zakłada blokadę, migawkę i Job, a potem psuje.
Na końcu drukuje **kartę**, czyli to, co dostałbyś od kolegi z dyżuru
(tekst karty `docs/chaos/disk-full.md` z wyjścia selftestu, skrócone: `...` to pominięte
sekcje karty; przebiegi 1 i 3a):

```
# Outbox, który się nie opróżnia

**Oczekiwany alert:** `OutboxStale` (po 2–3 min) · **Limit:** 8 min

## Objaw

Redaktor zmienia nazwę produktu w panelu, a wyszukiwarka dalej pokazuje starą wartość.
Zmiana jest zapisana w Postgresie, ale zdarzenie nie dociera do indeksu. W Grafanie
metryka `catalog_outbox_oldest_pending_age_seconds` rośnie o 15 s co scrape, bez końca,
a po 2–3 minutach płonie `OutboxStale`. Pod `outbox-publisher` jest `Running`,
`1/1`, z zerem restartów, a `rabbitmq-0` także. Wczoraj nikt nie wdrażał niczego
do tych dwóch komponentów.

## Zacznij od
...
Auto-wycofanie za 30 min (Job chaos-undo). Gdy naprawisz: make chaos-check s=disk-full
```

(w selfteście `ttl` wynosi 30 min, stąd „za 30 min”).

Zwróć uwagę na ostatnie zdanie objawu: „nikt niczego nie wdrażał”. Karta nigdy
nie zdradza przyczyny, tylko wyklucza najczęstsze fałszywe tropy. Taka jest
**zasada kart**: żadnego słowa o przyczynie w miejscach, które czytasz przed grą
(karta, `chaos-list`, ten przewodnik przed ostrzeżeniem poniżej). Rozwiązanie jest
zwinięte w `<details>` na samym końcu karty i `test.sh` pilnuje, że `<details>`
występuje tylko po nagłówku „Rozwiązanie”.

### 5.2 Sygnał

Alert `OutboxStale` płonie po 2–3 minutach od awarii. W teście na klastrze:
**138 s** (`for: 60 s`, a resztę robią okresy scrape'u i ewaluacji reguł).

Co alert mówi: „najstarsze zdarzenie w tabeli outbox czeka dłużej niż próg”. Czego
nie mówi: dlaczego.

### 5.3 Diagnoza

Pierwsze polecenia z karty to metoda „od zewnątrz do środka”:

```bash
kubectl -n marketplace get pods -l app=outbox-publisher        # żyje? restarty?
kubectl -n marketplace logs deploy/outbox-publisher --tail=30  # co mówi?
kubectl -n marketplace exec rabbitmq-0 -c rabbitmq -- rabbitmqctl -q list_connections name state
```

Karta zadaje pytania prowadzące (bez odpowiedzi): na jakim kroku kończy się log
publishera, co mówi stan połączeń z brokerem, co o brokerze mówi jego własna
diagnostyka i dlaczego zdarzenia nie giną, tylko czekają. Pytania są w
`docs/chaos/disk-full.md`.

Spróbuj sam, zanim przejdziesz dalej. Jeśli utkniesz, wróć do pytań.

### 5.4 Naprawa i `check`

Gdy uznasz, że naprawiłeś:

```bash
make chaos-check s=disk-full
```

`check` to lista wymagań „zdrowy” jako asercji. Dopóki awaria trwa, jest czerwony
i mówi dokładnie, czego brakuje (z logu selftestu, przebieg 3a):

```
=== check disk-full
  ✗ RabbitMQ bez alarmów  (oczekiwano: tak, otrzymano: nie)
  ✗ najstarszy wpis outbox młodszy niż 60 s (jest: 154.247092)  (oczekiwano: tak, otrzymano: nie)
CHORY — 2 z 2 wymagań niespełnionych
```

Po naprawie wygląda tak (z logu selftestu, przebieg 1, po `reset`, a nie po ręcznej
naprawie ucznia):

```
=== check disk-full
  ✓ RabbitMQ bez alarmów
  ✓ najstarszy wpis outbox młodszy niż 60 s (jest: 0)
ZDROWY — 2/2
```

Zielony `check` kończy scenariusz: zdejmuje blokadę, kasuje Job wycofania i
zapisuje wynik do `~/.chaos-scores.tsv` (`data scenariusz sekundy ok|reset`).
Twoja naprawa **jest** naprawą, `reset` nie jest potrzebny. Gdy się poddasz:
`make chaos-reset` cofa wszystko siłowo z migawki (i zapisuje `reset`, żebyś widział
w historii, które scenariusze Cię pokonały).

> **UWAGA: SPOILER.** Poniżej przyczyna i naprawa `disk-full`. Jeśli jeszcze nie
> grałeś w ten scenariusz, zrób to teraz (`make chaos-break s=disk-full`), a do tego
> miejsca wróć po zielonym `check`.

<details>
<summary>Pokaż przyczynę i naprawę disk-full</summary>

**Przyczyna.** Scenariusz podnosi `disk_free_limit` RabbitMQ (`rabbitmqctl
set_disk_free_limit`) ponad wolne miejsce na węźle. Broker uznaje, że „dysk jest prawie
pełny”, zgłasza alarm `Free disk space alarm` i **blokuje połączenia wydawców**
(stan `blocked`/`blocking`), żeby nie zapisać więcej, niż mieści dysk. Konsumenci
dalej pracują. `outbox-publisher` jest zdrowy z punktu widzenia Kubernetesa
(`Running`, `1/1`, zero restartów), bo przez blokadę połączenia po prostu czeka na
potwierdzenia, a wiersze outboxa zostają nieopublikowane. Dlatego żadna sonda nie
zapłonęła, a jedyny sygnał to wiek najstarszego wpisu.

**Dlaczego „alarm”, a nie zapełniony wolumen.** StorageClass `local-path` nie
egzekwuje rozmiaru PVC: to zwykły katalog na dysku węzła, dzielony z ES i
Postgresem. Prawdziwe zapchanie „wolumenu 2 Gi” zapchałoby cały dysk węzła ze
sklepem. Alarm `disk_free_limit` jest dokładnie tym, co RabbitMQ robi przy pełnym
dysku, więc objaw i diagnoza są takie same, a ryzyko dla reszty zerowe.

**Naprawa:**

```bash
kubectl -n marketplace exec rabbitmq-0 -c rabbitmq -- rabbitmq-diagnostics -q alarms
kubectl -n marketplace exec rabbitmq-0 -c rabbitmq -- rabbitmqctl -q set_disk_free_limit 1000000000
kubectl -n marketplace exec rabbitmq-0 -c rabbitmq -- rabbitmq-diagnostics -q alarms
make chaos-check s=disk-full
```

Limit ustawiony `rabbitmqctl` żyje do restartu poda: po restarcie wraca wartość
z `rabbitmq.conf`. Zaległe wiersze outboxa wychodzą same po zdjęciu alarmu.

**Wniosek ogólny:** w prawdziwej awarii (dysk faktycznie pełny) naprawa to
zwolnienie miejsca, a nie zmiana progu. Alert `OutboxStale` łapie skutek; przyczynę
wcześniej złapałby alert na wolne miejsce na węźle.

</details>

## Krok 6 — selftest i dowody alertów

Scenariusz, który nie był uruchomiony, jest hipotezą. Dlatego każdy przechodzi przez
trzy dowody (`make k3s-verify faza=chaos`, poza `all`, bo psuje produkcję):

1. **selftest** (`make chaos-selftest s=<s>`): `break` → `check` czerwony → `reset`
   dwa razy → `check` zielony. Dowodzi, że `check` rozróżnia stan zepsuty od
   zdrowego i że `reset` jest idempotentny. Zielony `check` na końcu jest ponawiany
   do 150 s, bo po resecie system potrzebuje chwili (np. outbox z `disk-full` miał
   188 s opóźnienia tuż po resecie i opróżnił się sam).
2. **fuse** (`proof.sh fuse <s>`): awaria z `ttl 1`, **żadnej akcji**. Dowodzi, że
   sam Job wycofuje awarię: blokada znika w 180 s, `check` zielony, Job `Succeeded`.
   To jest test bezpiecznika „zamknięty laptop”.
3. **alert** (`proof.sh alert <s>`, `alerts=1`): awaria założona, czekamy, aż
   **oczekiwany alert naprawdę zapłonie** w Prometheusie, w limicie `for + 3 min`.
   Wymaga też, żeby przed awarią żaden alert sklepu nie płonął (baseline), inaczej
   dowód nic by nie znaczył. Czas z przerw w dostępie do API jest odejmowany od limitu.

Czemu alert jest opcjonalny: `KubePodCrashLooping` ma w chartcie `for: 15m`, więc dowód
dla `oom` i `netpol-db` trwa po kwadransie każdy. Wyniki poniżej.

## Wyniki z klastra

`make k3s-verify faza=chaos` na 6 scenariuszach (bez `es-node-down`), przebieg 1
(na pierwszej wersji kodu, przed poprawkami): **PASS=10 FAIL=0**, 47 min 34 s.

| Scenariusz | Selftest | Fuse (ttl 1 min) |
|---|---|---|
| bad-probe | 131 s | zdrowo 53 s po powrocie `break`, Job Succeeded |
| bad-secret | 107 s | 54 s |
| disk-full | 147 s | 54 s |
| netpol-db | 1332 s (głównie oczekiwanie na ciszę) | nie sprawdzany |
| oom | 379 s | nie sprawdzany |
| queue-blocked | 424 s | nie sprawdzany |

Fuse (Job wycofuje sam) faza sprawdza na trzech reprezentatywnych scenariuszach
(`fuse_set` w `verify.sh`), selftest na wszystkich. Liczba 10 dotyczy
tamtej wersji kodu: obecna faza liczy więcej sprawdzeń (walidacja `s=`/`skip=`, dowody
alertów, końcowe czekanie na zdrowy sklep), więc nie jest do porównania. Pełny czysty
przebieg końcowego kodu czeka na `es-node-down` (po decyzji o replikach).

Alerty (`alerts=1`), czas od założenia awarii do `firing`:

| Scenariusz | Alert | `for` | Płonie po |
|---|---|---|---|
| queue-blocked | `OutboxStale` | 60 s | 77 s |
| bad-secret | `SearchConsumerDown` | 60 s | 108 s |
| disk-full | `OutboxStale` | 60 s | 138 s |
| netpol-db | `KubePodCrashLooping` | 900 s | 932 s |
| oom | `KubePodCrashLooping` | 900 s | 955 s |
| bad-probe | `KubePodNotReady` | 15 min | nie uruchomiony (`slow=1`) |

Wniosek praktyczny: **alerty o skutku płoną po 1–2 minutach, a alerty o zdrowiu
poda po kwadransie.** Awaria `oom` albo `netpol-db` na produkcji przeleży kwadrans
bez powiadomienia. To jest najcenniejsza informacja z całego etapu i powód, dla
którego karty każą zaczynać od `kubectl get pods`, a nie od czekania na alert.

Stan klastra po przebiegach: zero obiektów `part-of=chaos`, wszystkie 8 Deploymentów
i 4 StatefulSety gotowe, ES `green`, uprawnienia RabbitMQ `.* .* .*`, żadnych polityk
od chaosu, żadnych alarmów brokera. `make k3s-verify faza=apps` PASS=12 FAIL=0
(za pierwszym razem 11/12: `catalog-worker` tuż po `netpol-db` jeszcze siedział
w backoffie CrashLoopBackOff; wrócił po około 6 minutach, stąd końcowe czekanie
w fazie).

## Co wybuchło po drodze

> **UWAGA: SPOILER.** Ta sekcja opisuje mechanizmy awarii kilku scenariuszy.
> Jeśli zamierzasz grać bez podpowiedzi, zagraj najpierw i wróć tutaj.

- **Tunel padł w środku testu bezpiecznika.** W przebiegu 3b tunel do API był
  nieczynny przez cały czas oczekiwania na alert `netpol-db`. Skrypt widział „API
  nie odpowiada”, `reset` zwrócił błąd, a test padł (PASS=3 FAIL=2). Ale to, co się
  wydarzyło dalej, jest najlepszym dowodem całego projektu: **Job `chaos-undo` w
  klastrze sam przywrócił politykę i zdjął blokadę.** Produkcja była zepsuta tylko
  tyle, ile wynosił `ttl`. Wniosek: limity w `proof.sh` odejmują czas niedostępności
  API, a `reset` ponawia próbę 10 razy.
- **Pierwszy `queue-blocked` nie działał.** Plan zakładał politykę `max-length 0` plus
  `reject-publish`. Na tymczasowej kolejce okazało się, że kolejka quorum z
  `max-length 0` przyjmuje **jedną** wiadomość do pustej kolejki i odrzuca dopiero
  następne, a `search-consumer` opróżnia `search.product.sync` w około 100 ms.
  Publisher nigdy nie dostawał `nack`, outbox był pusty, wiek 0 przez minuty. Nowy
  mechanizm: odebranie użytkownikowi `marketplace` uprawnienia `write` do exchange
  `marketplace.events` (regex `^(?!marketplace\.events$).*$`). Publisher dostaje
  `ACCESS_REFUSED` (403) i wchodzi w `CrashLoopBackOff`, a `OutboxStale` płonie po
  77 s. Fakt z kroku 0 („nack”) dotyczył kolejki, która już coś zawierała. Dlatego
  zapis w specyfikacji ma sekcję „Zmiany względem projektu” (§ 3.5): projekt
  jest hipotezą, a klaster ją weryfikuje.
- **`break` przesłania słowo kluczowe powłoki.** Funkcja `break` w skryptach chaosu
  zastępuje wbudowane `break`, więc w pętli `for ... break` zachowałoby się
  nieoczekiwanie. `test.sh` sprawdza, że żaden skrypt nie używa `break` w pętli.
- **Pusta odpowiedź z Prometheusa to nie „cisza”.** W przebiegu 2 `wait_quiet` przez
  600 s widział „Prometheus nie odpowiada” i słusznie czekał, ale faza alertu
  wystartowała z nieznanym `for` (zapasowe 600 s zamiast prawdziwych 900 s). Od tej
  pory `proof.sh alert` wymaga baseline „nie płonie” i odmawia, gdy odpowiedź jest
  nieznana.
- **Recovery lag w `disk-full`.** Tuż po resecie outbox miał 188 s opóźnienia, więc
  końcowy `check` w selftestcie padł. To nie błąd `undo`, tylko czas, którego
  system potrzebuje na opróżnienie. Poprawka: ponawianie do 150 s.
- **Faza kończy się, zanim sklep wróci do formy.** Po `netpol-db` `catalog-worker`
  siedział w backoffie, a `faza=apps` dawała 11/12. Faza chaosu czeka teraz na zdrowy
  sklep na końcu, żeby kolejne polecenie nie zaczynało od fałszywej czerwieni.
- **Test w CI musi znać nowy scenariusz.** `.github/workflows/prod-compose.yml` ma
  dopisane ścieżki `tools/chaos/**`, `docs/chaos/**`, `deploy/chaos/**` i krok
  `bash tools/chaos/test.sh`. Bez tego zmiana karty albo Role przeszłaby zielona,
  choć rozjechałaby spis albo RBAC.

## Czego G4 nie robi

- **Chaos Mesh / Litmus (operator chaosu).** Dodatkowe CRD i około 300 MB RAM dla
  siedmiu scenariuszy, które robi kilka linii `kubectl`. Następny krok, gdy
  scenariuszy będzie kilkadziesiąt albo gdy trzeba wstrzykiwać opóźnienia sieci
  i utratę pakietów, czego `kubectl` nie zrobi.
- **Awarie węzła, k3s, dysku hosta i sieci hosta.** Jeden węzeł, brak redundancji.
  To byłaby awaria całości, nie ćwiczenie diagnozy.
- **Scenariusze dotykające `catalog-app` i danych.** Patrz granica z sekcji 1.
- **Tryb „losowy scenariusz bez nazwy”** (`s=random`). Możliwy później, gdy karty
  okrzepną. Dziś `chaos-list` pokazuje objawy, więc wiesz, w co grasz.

## Otwarte: `es-node-down` i repliki (spoiler)

> **UWAGA: SPOILER.** Ta sekcja zdradza lekcję scenariusza `es-node-down`.
> Jeśli chcesz go przejść bez podpowiedzi, przeskocz do [ściągi](#ściąga).

`es-node-down` jest jedynym scenariuszem, który **nie przeszedł** selftestu, fuse,
dowodu alertu ani `faza=chaos`. Powód jest ważniejszy niż samo uruchomienie:
framework pokazał lukę w odporności sklepu.

Indeks produktów `products-v1` ma `number_of_replicas: 0`
([`infra/elasticsearch/mappings/products-v1.json`](../../infra/elasticsearch/mappings/products-v1.json)).
Każdy shard ma więc **jedną** kopię. Scenariusz zdejmuje jeden z trzech węzłów ES.
Gdyby na tym węźle leżał jedyny shard, klaster poszedłby w `red`, a wyszukiwarka
przestałaby działać, czyli złamałaby granicę „degradacja, nie awaria”.

Jak to wyszło: jeden ręczny przebieg `break`/`reset` na produkcji, jeszcze **przed**
dodaniem bezpiecznika, nie dał `red` tylko dlatego, że shard akurat leżał na węźle,
który został; lukę zauważyłem, gdy ES przetasował shardy. Potem dodałem bezpiecznik
w `break`: **odmawia, gdy jedyna kopia jakiegokolwiek shardu leży na węźle, który
zniknie.** Na produkcji odmówił (`jedyna kopia products-v1/0`) i nic nie zostało
założone. Obecny `break es-node-down` więc odmawia, dopóki indeks nie ma replik.

Decyzja do podjęcia (czeka na użytkownika):

| Opcja | Skutek |
|---|---|
| `number_of_replicas: 1` dla `products-v1` | Każdy węzeł może zniknąć bez utraty dostępności. Koszt: dwa razy więcej miejsca na dane indeksu i więcej pracy przy indeksowaniu. `es-node-down` przechodzi bez zmian. |
| Zostawić 0 i zmienić scenariusz | Scenariusz celuje w indeks z replikami (np. systemowy) albo uczy czegoś innego. Luka zostaje. |

Gdy zapadnie decyzja, wynik zostanie dopisany do tej sekcji i do karty. Na razie
przebiegi na klastrze wyłączały go przez `s=` (domyślnie faza bierze wszystkie
scenariusze z `tools/chaos/scenarios`), a `tools/chaos/test.sh` sprawdza go wyłącznie
bez klastra. Pełny przebieg `make k3s-verify faza=chaos` po decyzji musi go objąć.

## Ściąga

```bash
make k3s-tunnel && export KUBECONFIG=~/.kube/elastic-vps.yaml
make chaos-list                       # scenariusze i objawy (bez rozwiązań)
make chaos-break s=oom [ttl=30]       # awaria + karta; auto-wycofanie po ttl min
make chaos-check s=oom                # ✓/✗ wymagań; zielony kończy i zapisuje czas
make chaos-status                     # co trwa, od kiedy, stan Joba chaos-undo
make chaos-reset                      # poddajesz się: siłowe wycofanie
cat ~/.chaos-scores.tsv               # historia: data scenariusz sekundy ok|reset

make chaos-selftest s="oom bad-secret"          # po zmianie scenariusza
bash tools/chaos/test.sh                        # testy bez klastra (CI)
make k3s-verify faza=chaos s="oom" alerts=1     # PSUJE produkcję; dowody + alert
make k3s-verify faza=chaos s="bad-probe" alerts=1 slow=1   # alert po 15 min (wolny)
make k3s-verify faza=chaos alerts=1 skip="selftest fuse"      # same alerty, bez powtórki dowodów
```

Gdy coś poszło nie tak: [RUNBOOK #095](../RUNBOOK.md#095) („chaos-undo nie zadziałał”).

## Twoja kolej

1. Przejdź po kolei sześć scenariuszy bez zaglądania do rozwiązań. Zacznij od
   `bad-probe` (alert po kwadransie, więc uczy zaczynania od `kubectl`, nie od
   alertu). Każdy, który zakończysz `reset`, trafi do `~/.chaos-scores.tsv` jako
   „do powtórki”.
2. Dla każdego scenariusza zapisz w jednym zdaniu: **jaki sygnał przyszedł pierwszy
   i jaki powinien przyjść**. Dla `oom` i `netpol-db` odpowiedź brzmi „alert po
   15 minutach, a powinien po jednej”. Zastanów się, jaką regułę dopisałbyś, i
   sprawdź ją w `infra/obs/prometheus/rules/marketplace.yml` (a `promtool` w CI
   powie, czy się zgadza).
3. Zdecyduj o replikach `products-v1` ([Otwarte](#otwarte-es-node-down-i-repliki-spoiler)).
   Po decyzji uruchom `es-node-down`, a wynik dopisz do przewodnika.
4. Napisz scenariusz 8 sam: wybierz coś, czego nie ma w spisie (np. zły `ConfigMap`
   workera, wygasły certyfikat cert-managera, przepełniony `emptyDir`). Plik
   `tools/chaos/scenarios/<s>.sh`, karta w `docs/chaos/`, wpis do Role
   `chaos-undo`, potem `bash tools/chaos/index.sh`, `bash tools/chaos/test.sh` i
   `make chaos-selftest s=<s>`. Jeśli selftest jest zielony w obu kierunkach, a
   `fuse` wycofuje sam, scenariusz jest gotowy.
