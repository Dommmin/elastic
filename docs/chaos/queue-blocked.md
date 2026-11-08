# Zdarzenia, które nie wychodzą z outboxa

**Oczekiwany alert:** `OutboxStale` (po 2–3 min) · **Limit:** 7 min

## Objaw

Redaktor zmienia nazwę produktu w panelu, a wyszukiwarka dalej pokazuje starą wartość.
Zmiana jest w Postgresie, ale zdarzenie nie dociera do indeksu. W Grafanie metryka
`catalog_outbox_oldest_pending_age_seconds` rośnie bez końca (o 15 s co scrape),
a po 2–3 minutach płonie `OutboxStale`. W `kubectl get pods` pod `outbox-publisher`
ma `0/1`, na zmianę `Error` i `CrashLoopBackOff`, a licznik restartów rośnie. Pody
`rabbitmq-0` i `search-consumer` są `Running` i gotowe. Wczoraj nikt nie wdrażał
niczego do publishera.

## Zacznij od

- Alertmanager/Grafana: `OutboxStale` (pojawi się po 2–3 min; nie czekaj na niego).
- Pierwsze polecenia:

```bash
kubectl -n marketplace get pods -l app=outbox-publisher
kubectl -n marketplace describe pod -l app=outbox-publisher
kubectl -n marketplace logs deploy/outbox-publisher --previous --tail=30
```

## Pytania prowadzące

1. Co mówi `Last State` kontenera `outbox-publisher` (`Reason`, `Exit Code`) i jaki błąd
   jest w `logs --previous`? Na którym kroku publikacji pada: połączenie z brokerem,
   uwierzytelnienie, a może sama publikacja?
2. Kod 403 w AMQP oznacza `ACCESS_REFUSED`. Czym różni się odmowa przy logowaniu
   od odmowy na konkretnym zasobie? Co w komunikacie wskazuje zasób, którego dotyczy odmowa?
3. Co o tym mówi log brokera (`kubectl -n marketplace logs rabbitmq-0 --tail=40`)?
   Jak sprawdzić, jakie uprawnienia (`configure`, `write`, `read`) ma użytkownik w vhoście
   (`rabbitmqctl list_permissions`, `list_user_permissions`)? Który z trzech regexów
   odpowiada za publikację na exchange?
4. `search-consumer` działa, mimo że używa tego samego użytkownika. Którego z trzech
   uprawnień potrzebuje pobieranie z kolejki (`basic.get`), a którego publikacja?
5. Dlaczego zdarzenia nie giną, tylko czekają w tabeli `outbox`? Co po naprawie
   zrobi publisher z nagromadzonymi wierszami i jakie zasady kolejności to gwarantują?

## Po co to w życiu

Aplikacje łączą się z RabbitMQ jednym użytkownikiem `marketplace`, a uprawnienia
w vhoście to trzy wyrażenia regularne: `configure`, `write` (publikacja na exchange)
i `read` (konsumpcja z kolejek i bindowanie). Publisher z
[`PublishOutboxCommand`](../../apps/catalog/app/Console/Commands/PublishOutboxCommand.php)
publikuje wyłącznie na exchange `marketplace.events`; odmowa kończy komendę wyjątkiem,
a `Deployment` restartuje ją w pętli (`CrashLoopBackOff` z rosnącym opóźnieniem).
Wzorzec Transactional Outbox sprawia, że nic nie ginie: wiersze czekają w Postgresie
(`published_at IS NULL`) i wychodzą po naprawie w kolejności `id`. Zmiany uprawnień
brokera (nowy użytkownik, zaostrzenie polityki, rotacja) to typowa przyczyna awarii,
która nie zostawia śladu w manifestach Kubernetesa: pody, obraz i Secret są te same,
zmienił się stan wewnątrz brokera. Użytkownik i jego uprawnienia (`.*` `.*` `.*`) powstają przy starcie brokera w
[`infra/rabbitmq/entrypoint.sh`](../../infra/rabbitmq/entrypoint.sh), a topologia żyje w
[`definitions.template.json`](../../infra/rabbitmq/definitions.template.json);
każda zmiana ręczna przez `rabbitmqctl` jest dryfem, który trzeba wykryć.

## Rozwiązanie

<details>
<summary>Pokaż rozwiązanie</summary>

**Przyczyna:** uprawnienie `write` użytkownika `marketplace` w vhoście `/` zostało
zawężone regexem `^(?!marketplace\.events$).*$`, który obejmuje wszystko poza exchange
`marketplace.events`. Publisher na pierwszej publikacji dostaje
`ACCESS_REFUSED - write access to exchange 'marketplace.events' ... refused for user
'marketplace'` (kod 403), pod kończy się błędem (`Last State: Terminated`, `Reason: Error`,
`Exit Code: 1`), kubelet restartuje go z rosnącym opóźnieniem, a wiersze outboxa zostają nieopublikowane.
Konsument potrzebuje tylko `read`, więc działa. Dowód:

```bash
kubectl -n marketplace logs deploy/outbox-publisher --previous | grep -m1 ACCESS_REFUSED
kubectl -n marketplace logs rabbitmq-0 --tail=100 | grep access_refused | tail -1
kubectl -n marketplace exec rabbitmq-0 -c rabbitmq -- rabbitmqctl -q list_user_permissions marketplace
```

**Naprawa** (przywróć pełne uprawnienia, jak w pierwotnej konfiguracji):

```bash
kubectl -n marketplace exec rabbitmq-0 -c rabbitmq -- \
  rabbitmqctl -q set_permissions -p / marketplace '.*' '.*' '.*'
kubectl -n marketplace delete pod -l app=outbox-publisher   # nowy pod od razu, bez czekania na backoff
make chaos-check s=queue-blocked
```

Zmiana uprawnień działa od razu na nowych kanałach, ale pod w `CrashLoopBackOff` czeka
do kilku minut na kolejną próbę, więc usunięcie poda skraca przestój. `check` czeka
do 4 minut, aż najstarszy wpis outboxa zejdzie poniżej minuty.

**Jak temu zapobiec:** uprawnienia użytkowników brokera tylko z `entrypoint.sh`
w repo (zmiana przez wdrożenie, nie przez `rabbitmqctl` na produkcji); osobny użytkownik
per aplikacja z minimalnym `write` (wtedy odmowa dotyczy jednej aplikacji i od razu
widać, której); alert `OutboxStale` łapie skutek, a alert na restarty
(`KubePodCrashLooping`) pokazuje, który komponent nie żyje.

</details>
