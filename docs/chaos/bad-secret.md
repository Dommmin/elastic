# Indeks wyszukiwania przestał się aktualizować

**Oczekiwany alert:** `SearchConsumerDown` (po ok. 3 min) · **Limit:** 8 min

## Objaw

Dodajesz nowy produkt, a wyszukiwarka go nie widzi: wynik w `/search` się nie zmienia,
choć w katalogu produkt jest od kwadransa. Pod `search-consumer` ma rosnący licznik
restartów, a kolejka `search.product.sync` w RabbitMQ rośnie i nikt jej nie opróżnia.
Nikt nie zmieniał obrazu ani kodu; ktoś tylko poprawił konfigurację Deploymentu.

## Zacznij od

- Alertmanager/Grafana: `SearchConsumerDown` (pojawi się po kilku minutach; nie czekaj na niego).
- Pierwsze polecenia:

```bash
kubectl -n marketplace get pods -l app=search-consumer
kubectl -n marketplace logs deploy/search-consumer --previous --tail=60
kubectl -n marketplace exec rabbitmq-0 -c rabbitmq -- rabbitmqctl -q list_queues name messages
```

## Pytania prowadzące

1. Co mówią logi konsumenta o przyczynie wywrotki? Wynik kończy się wydrukowanym
   opisem komendy (`messenger:consume [-l|--limit ...]`): przewiń wyżej, bo przyczyna
   jest nad nim. Czy to błąd aplikacji, czy odmowa po stronie brokera? Gdzie po stronie
   RabbitMQ szukać potwierdzenia: `rabbitmqctl` nie pokazuje nieudanych logowań,
   a logi `rabbitmq-0` tak.
2. Pod nie jest w `CreateContainerConfigError`, więc Secret i klucz istnieją.
   Skąd zatem wartość `MESSENGER_TRANSPORT_DSN` w tym podzie
   (`kubectl get deploy search-consumer -o yaml`)? Czym różni się od innych Deploymentów?
3. Dlaczego `search-metrics` ma ten sam klucz i działa dalej? Co z tego wynika
   dla zasięgu awarii?
4. Czemu `consumers` w `list_queues` pokazuje 0 nawet przy zdrowym konsumencie
   i jaki sygnał naprawdę mówi, że ktoś odpytuje kolejkę?
5. Skąd wartość `MESSENGER_TRANSPORT_DSN` w tym podzie i czym różni się od wartości
   w innych Deploymentach? Kto jest właścicielem prawdy o haśle: Secret, broker, `.env`?

## Po co to w życiu

Hasła aplikacji trafiają do klastra z [`tools/k3s/secrets.sh`](../../tools/k3s/secrets.sh)
(Secret `marketplace-secrets`), a konsument dostaje je przez `secretKeyRef`
w [`deploy/k8s/search-consumer.yaml`](../../deploy/k8s/search-consumer.yaml).
Pod czyta Secret przy starcie, więc zmiana hasła po jednej stronie (broker albo Secret)
daje awarię dopiero przy następnym restarcie poda, często długo po samej rotacji
i bez śladu w historii wdrożeń. Rotacja ma więc jedną kolejność (Secret, potem
restart, potem broker albo odwrotnie z oknem podwójnych haseł) i jedną weryfikację:
konsument znów odpytuje kolejkę. Messenger pobiera przez `basic.get`, dlatego alert
patrzy na tempo odpytywania, a nie na liczbę konsumentów.

## Rozwiązanie

<details>
<summary>Pokaż rozwiązanie</summary>

**Przyczyna:** `search-consumer` czyta `MESSENGER_TRANSPORT_DSN` z Secretu
`chaos-rotated-secrets`, w którym hasło RabbitMQ różni się o jeden znak od prawdziwego
(`marketplace-secrets`). Broker odrzuca logowanie, konsument kończy proces, kubelet go
restartuje, a nikt nie odpytuje kolejki. `search-metrics` czyta DSN z `marketplace-secrets`,
więc działa. Dowód: w `logs --previous` konsumenta jest
`Server connection error: 403, message: ACCESS_REFUSED - Login was refused using
authentication mechanism PLAIN` (pod nim Symfony drukuje opis komendy, co wygląda jak
błąd argumentów, ale nim nie jest: DSN jest poprawny składniowo), a log `rabbitmq-0`
ma `PLAIN login refused: user 'marketplace' - invalid credentials`:

```bash
kubectl -n marketplace logs deploy/search-consumer --previous --tail=60 | grep -E 'ACCESS_REFUSED|connect'
kubectl -n marketplace logs rabbitmq-0 -c rabbitmq | grep -i 'login refused' | tail -3
kubectl -n marketplace get deploy search-consumer \
  -o jsonpath='{.spec.template.spec.containers[0].env[?(@.name=="MESSENGER_TRANSPORT_DSN")].valueFrom}{"\n"}'
kubectl -n marketplace get secret --show-labels   # chaos-rotated-secrets obok marketplace-secrets
```

**Naprawa:** wskaż z powrotem Secret z manifestu (nie wypisuj wartości Secretów).

```bash
kubectl -n marketplace patch deploy/search-consumer --type json -p '[
  {"op":"replace","path":"/spec/template/spec/containers/0/env/<indeks>/valueFrom/secretKeyRef/name","value":"marketplace-secrets"}]'
kubectl -n marketplace rollout status deploy/search-consumer
make chaos-check s=bad-secret
```

Indeks zmiennej znajdziesz w `kubectl get deploy search-consumer -o yaml` (pozycja
`MESSENGER_TRANSPORT_DSN` w `env`). Secret `chaos-rotated-secrets` możesz zostawić:
gdy nic go już nie czyta, `check` sam go usuwa.
`check` czeka też, aż Prometheus zobaczy odpytywanie kolejki (do ok. 3 min).

**Jak temu zapobiec:** jedno źródło haseł (`secrets.sh` z `.env`), a Deployment nie
wskazuje Secretów spoza manifestu; po każdej rotacji restart konsumentów i sprawdzenie
`SearchConsumerDown`; ten sam alert patrzy na odpytywanie kolejki, więc łapie awarię
niezależnie od przyczyny.

</details>
