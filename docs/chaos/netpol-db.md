# Worker, który nie wstaje po restarcie

**Oczekiwany alert:** `KubePodCrashLooping` (po ok. 15 min) · **Limit:** 8 min

## Objaw

Pod `catalog-worker` został odtworzony (zwykły restart) i od tej chwili nie wstaje:
w `kubectl get pods` ma `0/1`, na zmianę `Error` i `CrashLoopBackOff`, a licznik
restartów rośnie. Każda próba kończy się po około sekundzie z kodem wyjścia 1.
Nikt nie wykonuje jobów z kolejki (blokady sprzedawców, kroki zamówień). Obraz i
Deployment workera są te same co godzinę temu, a pozostałe pody sklepu, w tym
`catalog-app` i `postgres-0`, są `Running` i gotowe; strony sklepu się ładują.

## Zacznij od

- Alertmanager/Grafana: `KubePodCrashLooping` (pojawi się po ok. 15 min; nie czekaj na niego).
- Pierwsze polecenia:

```bash
kubectl -n marketplace get pods -l app=catalog-worker
kubectl -n marketplace describe pod -l app=catalog-worker
kubectl -n marketplace logs deploy/catalog-worker --previous --tail=40
```

## Pytania prowadzące

1. Co dokładnie mówi log workera? Czym różni się `Connection refused` od timeoutu
   i kto może odmówić połączenia: sam Postgres czy coś po drodze?
2. Czy Postgres przyjmuje połączenia (`kubectl -n marketplace exec postgres-0 -c postgres -- pg_isready`)?
   `catalog-app` używa tej samej bazy i działa. Co odróżnia workera od `catalog-app`
   z punktu widzenia sieci: hasło, adres, a może etykiety poda?
3. Które obiekty w namespace decydują, kto może połączyć się z podem `postgres`
   (`kubectl -n marketplace get networkpolicy`)? Jak czytać `podSelector`
   z `matchExpressions` i operatorem `In`?
4. Jak sprawdzić łączność z poda bez `psql`? (Worker ma PHP:
   `php -r 'var_dump(@fsockopen("postgres",5432,$e,$s,3));'`.)
5. Zmiana, która zepsuła workera, mogła wejść dużo wcześniej niż restart. Dlaczego
   działający worker mógł jej nie zauważyć aż do odtworzenia poda?

## Po co to w życiu

Kto z kim może rozmawiać w `marketplace`, opisuje
[`deploy/k8s/networkpolicies.yaml`](../../deploy/k8s/networkpolicies.yaml): `default-deny`
na wszystko, a każdy serwer (tu `postgres-ingress`) wymienia klientów po etykiecie `app`.
Zasada „kto ma hasło do usługi, ten ma do niej trasę” jest powtórzona w tabeli
`tools/k3s/ckad-check.rb` (CI bez klastra) i sprawdzana na żywych podach przez
`make k3s-verify faza=netpol`. Ten sam mechanizm dał prawdziwą awarię tej produkcji:
Joby `pg-backup` nie miały wpisu w tej liście i przez dwie doby kopie bazy padały
z `Connection refused` (spec G4, § 10). Polityka sieciowa nie zrywa nawiązanych
połączeń (kube-router przepuszcza ruch `ESTABLISHED`), więc jej skutek widać dopiero
przy nowym połączeniu: po restarcie poda, wdrożeniu albo planowym końcu workera
(`--max-time=3600`). Awaria ujawnia się wtedy daleko od zmiany, która ją wywołała.

## Rozwiązanie

<details>
<summary>Pokaż rozwiązanie</summary>

**Przyczyna:** z listy klientów w NetworkPolicy `postgres-ingress`
(`spec.ingress[0].from[0].podSelector.matchExpressions[0].values`) zniknął wpis
`catalog-worker`. Pod workera nie pasuje już do żadnej reguły wpuszczającej na 5432,
więc kube-router odrzuca nowe połączenia (`Connection refused`). Laravel przy starcie
`queue:work` odpytuje bazę (`select * from "cache" ...`), dostaje `SQLSTATE[08006]
[7] ... Connection refused`, proces kończy się kodem 1, kubelet restartuje go z rosnącym
opóźnieniem. Postgres działa, a `catalog-app` jest na liście, więc sklep tego nie widzi.
Dowód:

```bash
kubectl -n marketplace logs deploy/catalog-worker --previous | grep -m1 'Connection refused'
kubectl -n marketplace get networkpolicy postgres-ingress \
  -o jsonpath='{.spec.ingress[0].from[0].podSelector.matchExpressions[0].values}{"\n"}'
kubectl -n marketplace get pod -l app=catalog-worker --show-labels
```

**Naprawa:** dopisz workera z powrotem do listy, potem nie czekaj na backoff kubeleta.

```bash
kubectl -n marketplace patch networkpolicy postgres-ingress --type json -p '[
  {"op":"add","path":"/spec/ingress/0/from/0/podSelector/matchExpressions/0/values/-","value":"catalog-worker"}]'
kubectl -n marketplace delete pod -l app=catalog-worker   # nowy pod od razu, bez CrashLoopBackOff
kubectl -n marketplace rollout status deploy/catalog-worker
make chaos-check s=netpol-db
```

`kubectl apply -f deploy/k8s/networkpolicies.yaml` też naprawia (manifest w repo ma
wpis), ale nadpisuje wszystkie polityki naraz: na produkcji zmieniaj tylko to, co
zepsute. `check` czeka do 5 min, aż worker wstanie sam, jeśli nie skasujesz poda.

**Jak temu zapobiec:** polityki tylko z repo (`deploy.sh`), a zmiana listy klientów
przechodzi przez `ckad-check.rb` w CI; po zmianie polityk `make k3s-verify faza=netpol`
na żywym klastrze. Alert na restarty (`KubePodCrashLooping`) łapie skutek, ale dopiero
po kwadransie, a sama zmiana polityki może przeleżeć niezauważona aż do restartu.

</details>
