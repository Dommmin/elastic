# Laboratorium CKAD na klastrze ze sklepem, krok po kroku

*ETAP G3 projektu Marketplace Search Platform. Po G2 manifesty sklepu pokrywają
cały program CKAD, ale egzamin nie sprawdza, czy umiesz przeczytać manifest.
Sprawdza, czy w 6–8 minut napiszesz poprawny zasób od zera, pod presją czasu,
w obcym klastrze. Tego nie da się nauczyć na własnych manifestach, które znasz
na pamięć. Ten etap dokłada 30 zadań na czas, sprawdzarkę i symulację egzaminu,
uruchamiane na tym samym k3s co sklep, ale tak, że pomyłka w zadaniu fizycznie
nie może go dotknąć.*

> **Stan:** gałąź `g3-ckad` (z `main` po PR [Dommmin/elastic#12](https://github.com/Dommmin/elastic/pull/12)).
> **Na klastrze (2026-11-06):** pełny selftest 30/30. Każde zadanie daje FAIL
> przed rozwiązaniem i PASS po rozwiązaniu z dokumentu wykonanym kubeconfigiem
> ucznia ([krok 5](#krok-5--selftest-czyli-dowód-że-zadania-nie-kłamią)).
> Tryb egzaminu sprawdzony na żywo (losowanie, przygotowanie, sprawdzenie, zapis wyniku).
> **Zadania:** [docs/ckad/README.md](../ckad/README.md) (spis, sposób pracy, nawyki egzaminacyjne).
> Plan: [`13-PLAN-ETAP-G-K8S.md`](../13-PLAN-ETAP-G-K8S.md).

---

## Spis treści

1. [Problem: ćwiczyć na produkcji, nie psując produkcji](#1-problem-ćwiczyć-na-produkcji-nie-psując-produkcji)
2. [Mapa plików](#2-mapa-plików)
3. [Krok 1 — kubeconfig ucznia zamiast cluster-admina](#krok-1--kubeconfig-ucznia-zamiast-cluster-admina)
4. [Krok 2 — strażnicy zasobów na wspólnym węźle](#krok-2--strażnicy-zasobów-na-wspólnym-węźle)
5. [Krok 3 — format zadania i sprawdzarka wymagań](#krok-3--format-zadania-i-sprawdzarka-wymagań)
6. [Krok 4 — 29 zadań w pięciu równoległych labach](#krok-4--29-zadań-w-pięciu-równoległych-labach)
7. [Krok 5 — selftest, czyli dowód, że zadania nie kłamią](#krok-5--selftest-czyli-dowód-że-zadania-nie-kłamią)
8. [Krok 6 — tryb egzaminu i zadania, które się gryzą](#krok-6--tryb-egzaminu-i-zadania-które-się-gryzą)
9. [Co wybuchło po drodze](#co-wybuchło-po-drodze)
10. [Bilans](#bilans)
11. [Ściąga](#ściąga)
12. [Twoja kolej](#twoja-kolej)

---

## 1. Problem: ćwiczyć na produkcji, nie psując produkcji

Do ćwiczeń są trzy oczywiste miejsca i każde ma wadę:

| Gdzie | Wada |
|---|---|
| Lokalny kind/k3d na Macu | Maszyna jest współdzielona z innymi projektami (RUNBOOK #022, #025): Docker VM ma 15,6 GB i już raz zabijał node'y ES. Kolejny klaster obok to proszenie się o OOM. |
| Płatny symulator (killer.sh) | Dwie sesje w cenie egzaminu, potem trzeba płacić. Świetny na finał, za drogi na codzienne powtórki. |
| Klaster sklepu kubeconfigiem operatora | Operator ma `cluster-admin`. Jedno `kubectl delete deploy --all` w złym kontekście i sklep leży. |

Wybór: klaster sklepu, ale **innym kubeconfigiem**. Węzeł ma 4 CPU i 24 GiB,
z czego sklep zużywa około połowy pamięci. Na kilka podów `nginx` i `busybox`
miejsca jest dużo, trzeba tylko zagwarantować, że lab nie wyjdzie poza swoje.

## 2. Mapa plików

```
docs/ckad/
  README.md                 sposób pracy, bezpieczeństwo labu, nawyki, spis (generowany)
  NN-nazwa.md               30 zadań: treść, „po co”, zwinięte rozwiązanie
tools/ckad/
  lib.sh                    wspólne funkcje: ko/kc, jp, expect, wait_for, lab_reset, task_solution
  lab.sh                    up | down | reset — namespace'y, RBAC, strażnicy, kubeconfig ucznia
  start.sh, check.sh        jedno zadanie: przygotowanie + stoper, sprawdzenie + zapis czasu
  selftest.sh               dowód zadań: nierozwiązane = FAIL, rozwiązanie z docs = PASS
  exam.sh, scores.sh        symulacja egzaminu, historia i lista do powtórki
  index.sh                  spis zadań w README
  tasks/NN.sh               check() i opcjonalnie setup() dla każdego zadania
  fixtures/NN/              chart Helma, overlay Kustomize, Dockerfile, stary manifest
Makefile                    ckad-lab-up, ckad-start, ckad-check, ckad-selftest, ckad-exam, ...
```

## Krok 1 — kubeconfig ucznia zamiast cluster-admina

`make ckad-lab-up` (`tools/ckad/lab.sh`) zakłada dwa namespace'y, `ckad-lab`
i `ckad-lab-b` (ten drugi do zadań o DNS i ruchu między namespace'ami),
ServiceAccount `student` i dwa RoleBindingi do wbudowanej ClusterRole `admin`,
po jednym w każdym namespace. Z tokena tego konta powstaje osobny plik
`~/.kube/ckad-lab.yaml`.

Dlaczego `admin`, a nie coś węższego: program CKAD jest w całości namespaced.
Deploymenty, Joby, Service, Ingress, NetworkPolicy, Role i RoleBinding,
ConfigMapy, sekrety, PVC — wszystko to `admin` w namespace może. Czego nie może:
niczego klastrowego (PV, StorageClass, ClusterRole, Namespace) i niczego
w innych namespace'ach. Weryfikacja zajmuje sekundę:

```bash
$ KUBECONFIG=~/.kube/ckad-lab.yaml kubectl auth can-i delete pods -n marketplace
no
$ KUBECONFIG=~/.kube/ckad-lab.yaml kubectl auth can-i create deployments
yes
```

Dwa szczegóły, które łatwo przeoczyć:

- **Token bez wygasania.** `kubectl create token` daje token na godzinę,
  a kubeconfig, który przestaje działać w połowie sesji, zabija naukę. Lab używa
  sekretu `kubernetes.io/service-account-token`, który znika razem z namespace'em.
- **Agregowane role CRD.** cert-manager instaluje ClusterRole z etykietą
  `aggregate-to-admin`, więc uczeń może tworzyć `Certificate` bez dodatkowych
  uprawnień. Na tym opiera się zadanie 24 („discover and use resources that
  extend Kubernetes”).

Kubeconfig operatora zostaje do dwóch rzeczy: zakładania labu i sprawdzeń, które
wymagają więcej niż `admin` (np. `auth can-i --as=system:serviceaccount:...`
w zadaniu 22 wymaga prawa do impersonacji).

## Krok 2 — strażnicy zasobów na wspólnym węźle

Izolacja uprawnień nie chroni przed jednym: zjedzeniem zasobów węzła. Pod
z `replicas: 50` albo kontener bez limitów w pętli to realne ryzyko dla Postgresa
i ES na tym samym węźle. Każdy namespace labu dostaje więc:

- **ResourceQuota `lab-guard`:** 1 CPU / 1,5 GiB requests, 2 CPU / 2 GiB limits,
  20 podów, 4 PVC, 0 LoadBalancerów, 2 NodePorty;
- **LimitRange `lab-guard`:** domyślnie 50m/64Mi request i 200m/256Mi limit,
  maksymalnie 1 CPU / 1 GiB na kontener.

Uprawnienie do tworzenia kwot (zadanie 23) uczeń ma tylko w `ckad-lab-b`. Rola
`admin` widzi kwoty wyłącznie do odczytu, więc lab dokłada osobną Role
`quota-editor`, ale nie w `ckad-lab`. Strażnik głównego namespace'u zostaje
nietykalny: `kubectl auth can-i delete resourcequota/lab-guard` → `no`.

Sieć nie wymagała nic nowego. Namespace `marketplace` od G2 ma `default-deny`
(RUNBOOK #084), więc pody z labu nie dosięgną bazy, kolejki ani ES. To dobra
ilustracja, po co polityka „domyślnie nic”: nowy sąsiad w klastrze jest
odcięty bez żadnej zmiany po stronie sklepu.

Wszystko, co należy do infrastruktury labu, ma etykietę `ckad.lab/infra=true`.
Reset między zadaniami kasuje wszystko, czego tej etykiety **nie ma**.

## Krok 3 — format zadania i sprawdzarka wymagań

Każde zadanie to dwa pliki. Dokument `docs/ckad/NN-nazwa.md` ma stały układ:

- nagłówek z domeną egzaminu i limitem minut (z niego czytają `start`, `check`
  i spis);
- „Zadanie” w stylu egzaminu: konkretne nazwy, obrazy z tagiem, mierzalne wymagania;
- „Po co to na egzaminie i w życiu” z odniesieniem do pliku w `deploy/` sklepu,
  który używa tego samego mechanizmu;
- zwinięte „Rozwiązanie”, imperatywne wszędzie, gdzie się da
  (`kubectl create/run/expose/set ... --dry-run=client -o yaml`).

Sprawdzarka `tools/ckad/tasks/NN.sh` ma `check()` i czasem `setup()` (zepsuty
Deployment do debugowania, pody o różnym zużyciu CPU, stary manifest). Zasada
pisania asercji: **jedno wymaganie z treści = jedna asercja**, sprawdzana na stanie
klastra, nie na tym, jak uczeń do niego doszedł. Zadanie 14 nie sprawdza, czy
poprawiłeś Deployment `kubectl edit`, czy `kubectl set env`. Sprawdza, że zmienna
pochodzi z ConfigMapy, że polecenie zostało nietknięte i że świeży pod jest
Ready bez restartów.

Rzeczy asynchroniczne (rollout, Job, endpointy, metryki) sprawdzarka odpytuje
przez `wait_for` z krótkim limitem. Wynik nie zależy więc od tego, czy klikniesz
`check` sekundę za wcześnie.

## Krok 4 — 29 zadań w pięciu równoległych labach

Zadanie 01 (natywny sidecar) powstało ręcznie jako wzorzec. Pozostałe 29 napisało
pięciu subagentów równolegle, po jednym na domenę egzaminu, według wspólnego
briefu. Wagi domen w programie CKAD wyznaczają liczbę zadań:

| Domena | Waga | Zadania |
|---|---|---|
| Application Design and Build | 20% | 01–06 |
| Application Deployment | 20% | 07–12 |
| Application Observability and Maintenance | 15% | 13–17 |
| Application Environment, Configuration and Security | 25% | 18–25 |
| Services and Networking | 20% | 26–30 |

Równoległość wymagała jednej zmiany w narzędziach: lab jest sparametryzowany
prefiksem `LAB`. `LAB=ckad-d3 make ckad-lab-up` zakłada `ckad-d3`, `ckad-d3-b`
i `~/.kube/ckad-d3.yaml`, a selftest podmienia nazwy `ckad-lab` w rozwiązaniach
na właściwe. Dzięki temu pięciu autorów resetowało własne laby, nie cudze.
Po pracy wszystkie laby deweloperskie usuwa `LAB=... lab.sh down`.

## Krok 5 — selftest, czyli dowód, że zadania nie kłamią

Sprawdzarka może kłamać na dwa sposoby: być zielona na nierozwiązanym zadaniu
(przepuści każdego) albo czerwona na poprawnym rozwiązaniu (nauczysz się, że
dobra odpowiedź jest zła). `make ckad-selftest` sprawdza oba kierunki dla
każdego zadania:

1. reset labu i `setup()`, potem sprawdzarka **musi** dać FAIL;
2. polecenia z bloków `bash` w zwiniętym „Rozwiązaniu” wykonane kubeconfigiem
   **ucznia**, potem sprawdzarka **musi** dać PASS.

Drugi punkt ma efekt uboczny, który jest ważniejszy niż wygląda: rozwiązania
w dokumentach są wykonywalne. Nie ma tam pseudokodu ani „i tak dalej”, bo
selftest by go nie przepuścił. Jeśli rozwiązanie przestanie działać po
aktualizacji Kubernetesa, selftest to pokaże.

Wynik pełnego przebiegu na `ckad-lab` (30 zadań, po kolei):

```
01 OK  02 OK  03 OK  04 OK  05 OK  06 OK  07 OK  08 OK  09 OK  10 OK
11 OK  12 OK  13 OK  14 OK  15 OK  16 OK  17 OK  18 OK  19 OK  20 OK
21 OK  22 OK  23 OK  24 OK  25 OK  26 OK  27 OK  28 OK  29 OK  30 OK
selftest: OK=30 BŁĘDY=0
```

Przebieg trwał około 45 minut (2026-11-06, k3s v1.36.5, jeden węzeł). Najwięcej
czasu zabierają zadania, które czekają na coś z zewnątrz: metrics-server
w zadaniu 16 (pierwsze próbki po ~60 s), testy łączności NetworkPolicy
z timeoutem w 28 i 30 oraz pobieranie obrazów przy pierwszym uruchomieniu.

## Krok 6 — tryb egzaminu i zadania, które się gryzą

`make ckad-exam` losuje 16 zadań, przygotowuje je **naraz** w jednym labie
(na egzaminie zadania też nie sprzątają po sobie nawzajem), drukuje treści bez
rozwiązań i liczy 120 minut. `bash tools/ckad/exam.sh check` sprawdza wszystkie
i podaje wynik na tle progu zdania (66%).

Tryb egzaminu ujawnił problem, którego pojedyncze zadania nie mają. Zadanie 23
zakłada w `ckad-lab-b` kwotę `pods: 3`, a zadania 26 i 28 uruchamiają w tym samym
namespace pody tymczasowe do testów łączności. Razem kwota odrzuciłaby pody
sprawdzarki i wynik byłby fałszywie czerwony. Losowanie pomija więc pary
z listy `CONFLICTS` w `exam.sh`. Nazwy zasobów w zadaniach są rozłączne, więc
pozostałe zadania współistnieją bez problemu.

## Co wybuchło po drodze

- **bash 3.2 na macOS.** Systemowy bash nie zna `mapfile`, więc pierwsza wersja
  losowania w `exam.sh` nie ruszyłaby. Zastąpiona pętlą `while read`. Cała reszta
  narzędzi jest pisana pod 3.2.
- **Selftest zgłaszał błąd setupu w zadaniu bez `setup()`.** Podpowłoka kończyła
  się na `declare -F setup && setup`, a brak funkcji to kod 1. Poprawka: `if`.
  Klasyczna pułapka `&&` na końcu bloku przy `set -e`.
- **Dwa selftesty na jednym labie.** Autor domeny Observability puścił drugi
  przebieg, zanim skończył się pierwszy. Reset jednego skasował pody drugiego
  w połowie rozwiązania. Wniosek wpisany w README: jeden selftest na jeden `LAB`.
- **`kubectl run` nie ma już `--requests`/`--limits`.** Flagi zniknęły z nowszych
  wersji kubectl. Pody z zasobami w zadaniu 23 idą przez YAML. Na egzaminie
  warto to wiedzieć, zanim straci się na tym minutę.
- **Traefik przekierowuje HTTP na HTTPS.** Żywy test Ingressu w zadaniu 29
  dostawał 301. Sprawdzarka łączy się więc po HTTPS z Service Traefika
  z nagłówkiem `Host`.
- **`nslookup` z busyboxa i krótkie nazwy.** Kończy się kodem 1 dla krótkiej
  nazwy, choć `wget` po tej samej nazwie działa. Test DNS w zadaniu 30 używa
  FQDN.
- **Kwota w trybie egzaminu.** Opisane w kroku 6.

## Bilans

| Co | Ile |
|---|---|
| Zadania | 30, w proporcjach wag egzaminu |
| Asercje sprawdzarek | jedno wymaganie = jedna asercja, czytelne opisy po polsku |
| Koszt labu dla klastra | najwyżej 1 CPU / 1,5 GiB requests; zero, gdy lab jest usunięty |
| Ryzyko dla sklepu | brak uprawnień poza labem, brak dostępu sieciowego, kwoty na zasobach |

Czego ten lab nie zastąpi: obcego klastra z wieloma kontekstami i nieznanym
nazewnictwem. Na ostatnie dwa tygodnie przed egzaminem sesje killer.sh dołączone
do egzaminu dalej są najlepszym testem.

## Ściąga

```bash
make k3s-tunnel && make ckad-lab-up && export KUBECONFIG=~/.kube/ckad-lab.yaml
make ckad-start t=14          # treść + stoper
make ckad-check t=14          # ✓/✗ + czas
make ckad-scores              # co powtórzyć
make ckad-exam                # 16 zadań, 120 min; koniec: bash tools/ckad/exam.sh check
make ckad-selftest t="14 15"  # po zmianie zadań
make ckad-lab-down            # sprzątanie
```

## Twoja kolej

1. Przejdź wszystkie 30 zadań po kolei bez zaglądania do rozwiązań. Każde, które
   przekroczy limit, trafi do `make ckad-scores`.
2. Po tygodniu powtórz tylko te z listy do powtórki. Cel: każde zadanie
   w limicie, bez `kubectl explain` w ponad połowie przypadków.
3. Zrób `make ckad-exam` raz w tygodniu. Kiedy trzy razy z rzędu przekroczysz 80%,
   kup egzamin.
4. Napisz zadanie 31 sam: wybierz mechanizm z `deploy/k8s/`, którego nie ma
   w spisie (np. PodDisruptionBudget albo `topologySpreadConstraints`), dopisz
   dokument, sprawdzarkę i puść `make ckad-selftest t=31`. Jeśli selftest jest
   zielony w obu kierunkach, zadanie jest gotowe.
