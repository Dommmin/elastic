# Worker, który nie przeżywa własnego startu

**Oczekiwany alert:** `KubePodCrashLooping` (po kilku minutach) · **Limit:** 8 min

## Objaw

Sprzedawca zgłasza, że zablokowane konto nadal ma widoczne oferty, a zamówienia
„wiszą” w kroku, który zwykle trwa sekundy. Strony sklepu ładują się normalnie.
W `kubectl get pods` pod `catalog-worker` ma rosnący licznik restartów i na zmianę
`Error` oraz `CrashLoopBackOff`; w tabeli `jobs` w Postgresie przybywa wierszy.
Ktoś wczoraj „zaoszczędził pamięci na węźle”.

## Zacznij od

- Alertmanager/Grafana: `KubePodCrashLooping` (pojawi się po kilku minutach; nie czekaj na niego).
- Pierwsze polecenia:

```bash
kubectl -n marketplace get pods -l app=catalog-worker
kubectl -n marketplace describe pod -l app=catalog-worker
kubectl -n marketplace logs deploy/catalog-worker --previous
```

## Pytania prowadzące

1. Co mówi `Last State` kontenera w `describe pod`: jaki `Reason` i jaki `Exit Code`?
   Co oznacza kod 137 i kto wysyła ten sygnał?
2. Dlaczego `logs --previous` jest prawie pusty? Czy proces miał szansę coś zalogować?
3. Czym różni się `requests.memory` od `limits.memory`? Który z nich decyduje
   o zabiciu kontenera, a który o wyborze węzła przez scheduler?
4. Jak odróżnić limit pamięci kontenera od braku pamięci na węźle (`kubectl describe node`,
   `kubectl top pod`)? Dlaczego tu winny jest limit?
5. Pod ma strategię `Recreate`. Co to zmienia w trakcie naprawy: czy przez chwilę
   będzie działał stary pod razem z nowym?

## Po co to w życiu

Limity workera żyją w [`deploy/k8s/catalog.yaml`](../../deploy/k8s/catalog.yaml)
(kontener `worker`: `requests.memory: 64Mi`, `limits.memory: 256Mi`). Limit pamięci
to twarda granica cgroup: po jej przekroczeniu jądro zabija proces bez ostrzeżenia
i bez `SIGTERM`, więc aplikacja nie zdąży posprzątać ani zalogować przyczyny. Limit
ustawiony „na oko” albo skopiowany z lżejszej usługi to klasyczna awaria po wdrożeniu:
działa na testach z małą próbką, pada pod prawdziwym frameworkiem albo większym jobem.
Dlatego limit wyznacza się z pomiaru (`kubectl top pod`, wykresy `container_memory_working_set_bytes`)
z zapasem, a alert na restarty jest ważniejszy niż sam limit.

## Rozwiązanie

<details>
<summary>Pokaż rozwiązanie</summary>

**Przyczyna:** limit pamięci kontenera `worker` wynosi `40Mi`. `php artisan queue:work`
potrzebuje więcej już po załadowaniu frameworka, więc jądro zabija kontener
(`Reason: OOMKilled`, `Exit Code: 137`), kubelet restartuje go z rosnącym opóźnieniem
(`CrashLoopBackOff`), a kolejka jobów stoi. Dowód:

```bash
kubectl -n marketplace get pod -l app=catalog-worker \
  -o jsonpath='{.items[0].status.containerStatuses[0].lastState.terminated}{"\n"}'
kubectl -n marketplace get deploy catalog-worker \
  -o jsonpath='{.spec.template.spec.containers[0].resources}{"\n"}'
```

**Naprawa** (przywróć wartości z manifestu: request 64Mi, limit 256Mi):

```bash
kubectl -n marketplace set resources deploy/catalog-worker -c worker \
  --requests=memory=64Mi --limits=memory=256Mi
kubectl -n marketplace rollout status deploy/catalog-worker
make chaos-check s=oom
```

`check` wymaga też, żeby przez 2 minuty nie było żadnego restartu, więc zielony
wynik przychodzi po tym, jak nowy pod przeżyje start.

**Jak temu zapobiec:** limity z pomiaru, nie z intuicji; alert `KubePodCrashLooping`
oraz wykres pamięci workera w Grafanie; zmiana limitu przechodzi przez ten sam
przegląd co kod (manifest w repo, `deploy.sh`), a nie `kubectl edit` na produkcji.

</details>
