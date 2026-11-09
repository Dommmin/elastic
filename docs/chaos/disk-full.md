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

- Alertmanager/Grafana: `OutboxStale` (pojawi się po 2–3 min; nie czekaj na niego).
- Pierwsze polecenia:

```bash
kubectl -n marketplace get pods -l app=outbox-publisher
kubectl -n marketplace logs deploy/outbox-publisher --tail=30
kubectl -n marketplace exec rabbitmq-0 -c rabbitmq -- rabbitmqctl -q list_connections name state
```

## Pytania prowadzące

1. Co dokładnie kończy się w logu `outbox-publisher` i na jakim kroku (połączenie,
   publikacja, czekanie na potwierdzenie)? Czy publisher widzi brak brokera, czy broker,
   który z nim rozmawia, ale nie przyjmuje wiadomości?
2. Jaki `state` mają połączenia w `rabbitmqctl list_connections` i co znaczą wartości
   `blocked` oraz `blocking`? Które połączenia są dotknięte: tylko publikujące, czy też
   konsumujące?
3. Co zwraca `kubectl -n marketplace exec rabbitmq-0 -c rabbitmq -- rabbitmq-diagnostics -q alarms`?
   Czym jest alarm w RabbitMQ i czym różni się od błędu kolejki?
4. Co mówi log brokera (`kubectl -n marketplace logs rabbitmq-0 --tail=30`): jakie dwie
   liczby porównuje i która z nich jest nierealna dla tego węzła? Jak sprawdzić, ile
   miejsca naprawdę ma wolumen (`df -h` w kontenerze albo metryki węzła)?
5. Dlaczego zdarzenia nie giną, tylko czekają? Co w `PublishOutboxCommand` decyduje,
   że wiersz outboxa zostaje nieopublikowany, i co się stanie z kolejką zdarzeń
   po naprawie?

## Po co to w życiu

RabbitMQ chroni się przed pełnym dyskiem: gdy wolne miejsce spada poniżej
`disk_free_limit` (u nas `disk_free_limit.absolute = 1GB` w
[`infra/rabbitmq/rabbitmq.conf`](../../infra/rabbitmq/rabbitmq.conf)), zgłasza alarm
i blokuje połączenia wydawców, żeby nie zapisać więcej, niż mieści dysk. Konsumenci
dalej pracują. To zachowanie jest celowe, a objaw w aplikacji wygląda jak „broker zawiesił
się”: pody są zdrowe, sondy zielone, tylko publikacja stoi. Wolumen brokera
(`volumeClaimTemplates` w [`deploy/k8s/rabbitmq.yaml`](../../deploy/k8s/rabbitmq.yaml))
ma 2Gi, ale StorageClass `local-path` nie egzekwuje rozmiaru PVC: to zwykły katalog
na dysku węzła, współdzielonym z Elasticsearchem i Postgresem. Ten scenariusz nie
zapełnia więc dysku (zapchałby cały węzeł ze sklepem), tylko podnosi próg alarmu ponad
wolne miejsce. Dla brokera to nie do odróżnienia od prawdziwego niedoboru, więc diagnoza
i skutki są takie same. W prawdziwej awarii naprawa polega na zwolnieniu miejsca, a nie
na zmianie progu.

## Rozwiązanie

<details>
<summary>Pokaż rozwiązanie</summary>

**Przyczyna:** `disk_free_limit` brokera ustawiono na `1000GB`, a węzeł ma dziesiątki
gigabajtów wolnego miejsca. RabbitMQ uznaje, że „dysk jest prawie pełny”, zgłasza alarm
`Free disk space alarm on node rabbit@rabbitmq-0` i blokuje połączenia publikujące
(`blocked`/`blocking`). `outbox-publisher` kończy z `Connection is blocked due to low
resources` albo `Timeout oczekiwania na potwierdzenia`, więc wiersze outboxa zostają
nieopublikowane, a wiek najstarszego rośnie. Dowód:

```bash
kubectl -n marketplace exec rabbitmq-0 -c rabbitmq -- rabbitmq-diagnostics -q alarms
kubectl -n marketplace exec rabbitmq-0 -c rabbitmq -- rabbitmqctl -q eval 'rabbit_disk_monitor:get_disk_free_limit().'
kubectl -n marketplace logs rabbitmq-0 --tail=30 | grep -i 'disk'
```

**Naprawa** (przywróć wartość z konfiguracji, 1 GB):

```bash
kubectl -n marketplace exec rabbitmq-0 -c rabbitmq -- rabbitmqctl -q set_disk_free_limit 1000000000
kubectl -n marketplace exec rabbitmq-0 -c rabbitmq -- rabbitmq-diagnostics -q alarms
make chaos-check s=disk-full
```

Limit ustawiony `rabbitmqctl` żyje do restartu poda: po restarcie `rabbitmq-0`
wraca wartość z `rabbitmq.conf`, więc `kubectl -n marketplace delete pod rabbitmq-0`
też naprawia (kosztem chwilowej niedostępności brokera). `check` czeka do 60 s, aż alarm
zniknie, i do 90 s, aż outbox się opróżni. Po zdjęciu alarmu zaległe wiersze
wychodzą bez żadnej akcji na publisherze (w teście wiek najstarszego wpisu wrócił do 0).

**Jak temu zapobiec:** alert `OutboxStale` łapie skutek; alert na wolne miejsce
na węźle i na `rabbitmq_alarms_free_disk_space_watermark` łapie przyczynę wcześniej. Limit RabbitMQ zmienia się
tylko w `rabbitmq.conf` w repo, a w prawdziwej awarii zwalnia się miejsce (logi,
stare obrazy, zbędne indeksy), zamiast podnosić próg.

</details>
