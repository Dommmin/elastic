# Zepsuj i napraw (ETAP G4)

Ćwiczenie diagnozy awarii na **prawdziwym** sklepie: namespace `marketplace` na
produkcyjnym k3s. Każdy scenariusz to jedna realistyczna awaria z jednej linijki
konfiguracji. Zaczynasz od sygnału, który na produkcji przychodzi pierwszy (alert,
dashboard, objaw), znajdujesz przyczynę i naprawiasz ją sam w `kubectl`.
Scenariusze psują procesy w tle (wyszukiwarka, kolejka, joby, metryki), nigdy
`catalog-app` ani dane: sklep cały czas serwuje strony. Projekt i uzasadnienie:
[docs/superpowers/specs/2026-11-07-g4-chaos-design.md](../superpowers/specs/2026-11-07-g4-chaos-design.md).

## Jak grać

```bash
make k3s-tunnel                       # tunel do API k3s (127.0.0.1:26443)
make chaos-list                       # scenariusze i objawy (bez rozwiązań)
make chaos-break s=bad-probe          # awaria + karta: objaw i od czego zacząć
# ... diagnozujesz i naprawiasz w kubectl (kubeconfig ~/.kube/elastic-vps.yaml) ...
make chaos-check s=bad-probe          # ✓/✗ wymagań „zdrowy”; zielony kończy scenariusz
make chaos-status                     # co trwa, od kiedy, kiedy auto-wycofanie
make chaos-reset                      # poddajesz się: siłowe wycofanie
```

Zielony `check` to koniec scenariusza: zdejmuje blokadę i kasuje Job wycofania,
a czas od `break` trafia do `~/.chaos-scores.tsv` (`data  scenariusz  sekundy  ok|reset`).
Twoja naprawa jest naprawą — `reset` nie jest potrzebny. Karta scenariusza
(`docs/chaos/<s>.md`) ma pytania prowadzące, a rozwiązanie jest zwinięte na końcu:
otwieraj je dopiero po `check`.

`ttl` (minuty do auto-wycofania, domyślnie 15): `make chaos-break s=bad-probe ttl=30`.

## Bezpieczniki

| Mechanizm | Co chroni |
|---|---|
| Preflight `break`: ES `green`, każdy Deployment i StatefulSet gotowy, zero płonących alertów sklepu (grupa `marketplace` albo `namespace="marketplace"`; bez `Watchdog`, `InfoInhibitor`, `KubeJobFailed`) | awaria na już chorym systemie myli diagnozę i grozi kaskadą |
| Blokada: ConfigMap `chaos-lock` (`chaos.lab/lock=true`) zakładana atomowo (`kubectl create`) | dwa scenariusze naraz; `tools/k3s/deploy.sh` odmawia wdrożenia, gdy blokada istnieje (Job przywróciłby po chwili migawkę ze starym obrazem) |
| Migawka w blokadzie (`snapshot.yaml`): obiekty sprzed awarii z klastra, nie z repo | `undo` przywraca dokładnie stan sprzed `break` (obraz, repliki) surowym PUT (`kubectl replace --raw`): ten sam szablon = brak nowego rolloutu, a adnotacja `last-applied` (baza `kubectl apply` w `deploy.sh`) zostaje nietknięta |
| Job `chaos-undo` w klastrze: śpi do `deadline`, wywołuje `undo` scenariusza, zdejmuje blokadę | zamknięty laptop albo zerwany tunel nie zostawiają produkcji zepsutej; powstaje **przed** awarią |
| ServiceAccount + Role `chaos-undo` (`deploy/chaos/undo-rbac.yaml`), ConfigMapy i Secrety tylko po nazwie | Job nie przeczyta `marketplace-secrets` ani niczego poza tym, co cofa |
| NetworkPolicy `chaos-undo-egress` | `default-deny` z G2 zostaje; Job wychodzi tylko do API servera |
| `trap` w `break` | przerwany `break` (Ctrl-C, błąd kroku) wycofuje się sam: nigdy blokada bez Joba |
| Granica scenariuszy | żaden nie dotyka `catalog-app`, `marketplace-secrets`, PVC ani danych |

Job działa jako uid 65534, z `readOnlyRootFilesystem`, `drop: [ALL]`,
`seccompProfile: RuntimeDefault` (PSS `restricted`), 50m/64Mi, limit 128Mi.
Skrypty (`lib.sh`, `scenarios/<s>.sh`, `undo-entry.sh`) dostaje z ConfigMap
`chaos-scripts`, tworzonej przy `break` z tego samego kodu co lokalnie — jedna
implementacja `undo`.

Po auto-wycofaniu zakończony Job `chaos-undo` i ConfigMap `chaos-scripts` zostają
na godzinę (`ttlSecondsAfterFinished: 3600`), żeby dało się przeczytać log:
`kubectl -n marketplace logs job/chaos-undo`. `chaos-scripts` ma ownerReference na
Job, więc znika razem z nim. Od razu sprząta je `make chaos-reset` (bez trwającego
scenariusza tylko to robi) albo następny `break`. Czysty klaster: zero obiektów
`app.kubernetes.io/part-of=chaos`; statyczne RBAC i polityka mają `part-of=chaos-rbac`
i zostają.

## Co gdy Job nie zadziałał

```bash
make chaos-status                                  # stan Joba: active / succeeded / failed
kubectl -n marketplace logs job/chaos-undo         # co zrobił (albo dlaczego nie)
make chaos-reset                                   # undo z migawki, kasuje Job i blokadę
```

Gdy `reset` nie może przejść (np. migawka uszkodzona), napraw stan ręcznie wg karty
scenariusza (sekcja „Rozwiązanie”) i zdejmij blokadę:

```bash
kubectl -n marketplace delete cm chaos-lock
kubectl -n marketplace delete job chaos-undo --ignore-not-found
kubectl -n marketplace delete cm chaos-scripts --ignore-not-found
```

## Dla autora scenariusza

Plik `tools/chaos/scenarios/<s>.sh` (sourcowany przez `chaos.sh` i przez Job):
`SUMMARY`, `ALERT` (może być pusty), `LIMIT_MIN`, funkcje `targets` (`rodzaj/nazwa`
do migawki, może nic), `break`, `check` (asercje `expect`/`expect_match`), `undo`
(idempotentne; zwykle `snapshot_restore` plus reszta). Funkcja `break` przesłania
słowo kluczowe powłoki, więc w skryptach chaosu **nie używaj `break` w pętlach**
(`test.sh` to sprawdza). Do tego karta `docs/chaos/<s>.md` (sekcje jak w `bad-probe.md`),
potem `bash tools/chaos/index.sh`, `bash tools/chaos/test.sh` i
`make chaos-selftest s=<s>` (break → check FAIL → reset ×2 → check PASS).

## Scenariusze

<!-- spis generowany: tools/chaos/index.sh -->

| Scenariusz | Objaw | Alert | Limit |
|---|---|---|---|
| [bad-probe](bad-probe.md) | Rollout search-metrics stoi: nowy pod wisi 0/1, a kubectl rollout status nie kończy się | KubePodNotReady | 15 min |
