# Jak podejrzeć RabbitMQ w akcji

Wyszukiwarka nie czyta Postgresa. Każda zmiana w katalogu musi dojść do
Elasticsearcha przez kolejkę, i ten przewodnik pokazuje, jak to zobaczyć
na własne oczy: lokalnie (Compose) i na serwerze (k3s).

## Droga jednej zmiany

```
Offer::updateWithOutbox(...)          catalog (Laravel)
   │  ta sama transakcja co zmiana
   ▼
tabela outbox  ──►  outbox-publisher  (php artisan outbox:publish --loop)
                       │  routing key = typ zdarzenia, np. offer.price_changed
                       ▼
             exchange marketplace.events  (topic)
                       │  binding offer.# / product.# / seller.# / category.# / review.#
                       ▼
             queue search.product.sync  ──(reject)──►  marketplace.dlx ──► search.product.sync.dlq
                       │
                       ▼
             search-consumer (Symfony Messenger) ──► ES products-search
```

Pozostałe kolejki (`search.analytics.ingest` dla `user.#` i `notifications.alerts`
dla `alert.#`) w tej chwili stoją puste, bo nic jeszcze nie publikuje takich
zdarzeń.

## Gdzie jest panel

| Środowisko | Jak otworzyć | Adres | Login |
|---|---|---|---|
| Lokalnie (Compose) | stack musi działać (`make up`) | http://localhost:35672 | `RABBITMQ_USER` / `RABBITMQ_PASSWORD` z `.env` |
| Serwer (k3s) | `make k3s-ui` (Ctrl+C zamyka) | http://localhost:36672 | `marketplace` / `make vps-secret k=RABBITMQ_PASSWORD` |

### Na co patrzeć

- **Exchanges → `marketplace.events` → Bindings**: tu widać, który routing key
  trafia do której kolejki. To ta sama topologia co w `infra/rabbitmq/definitions.template.json`.
- **Queues → `search.product.sync`**: wykres *Message rates*. Gdy nikt nic
  nie zmienia, wszystko stoi na zerze. Po zmianie ceny przez chwilę widać
  *Publish* i *Get*.
- **Consumers: 0 to nie awaria.** Symfony Messenger z transportem AMQP pobiera
  wiadomości pollingiem (`basic.get`). Nie trzyma stałej subskrypcji
  (`basic.consume`), więc RabbitMQ nie liczy go jako konsumenta. Czy consumer
  żyje, sprawdzisz tak: kolejka się nie zapycha, a wykres pokazuje *Get*.
- **`search.product.sync.dlq`**: wiadomości, które consumer odrzucił. Każda
  ma nagłówek `x-death` z powodem (`rejected`) i kolejką źródłową.

### Podgląd wiadomości bez kasowania

W UI: Queues → kolejka → **Get messages** i koniecznie
**Ack mode: _Nack message requeue true_**. Każdy inny tryb zdejmuje
wiadomość z kolejki na stałe.

Z terminala (tylko Compose): `make mq-get q=search.product.sync.dlq` ma
`--ack-mode ack_requeue_true` wpisane na sztywno, więc niczego nie usuwa.

## Eksperyment 1: zmiana ceny przechodzi przez kolejkę (lokalnie)

Aplikacja nie ma panelu edycji, więc zmianę robimy przez tinker. Metoda
`updateWithOutbox` zapisuje zdarzenie do outboxu w tej samej transakcji.

1. W drugim terminalu podglądaj consumer:
   ```bash
   docker compose logs -f search-consumer
   ```
2. Zapamiętaj starą cenę i ją zmień:
   ```bash
   docker compose exec catalog-app php artisan tinker --execute 'echo App\Models\Offer::find(1)->price_cents;'
   docker compose exec catalog-app php artisan tinker --execute 'App\Models\Offer::find(1)->updateWithOutbox(["price_cents" => 1999]);'
   ```
3. W ciągu 1–2 sekund w logach consumera pojawi się:
   ```
   [messenger] Received message App\Message\IntegrationEvent
   [app] Zaindeksowano produkt. ["product_id" => "1","event_type" => "offer.price_changed","sequence" => 2]
   ... was handled successfully (acknowledging to transport)
   ```
4. Wyszukiwarka widzi już nową cenę (`price_min` = 1999, czyli 19,99 zł):
   ```bash
   curl -sk "https://catalog.localhost:8443/api/search?q=Batz-Beer%20Ultra%20Boost&per_page=1"
   ```
5. Przywróć starą cenę tą samą komendą z punktu 2. To kolejne zdarzenie,
   które też przejdzie przez kolejkę.

Tak wyglądało to przy pisaniu przewodnika: oferta 1 miała 477888, po zmianie
API zwróciło `price_min: 1999`, a po przywróceniu `87080`. To najtańsza
z pozostałych ofert tego produktu, bo oferta 1 nie była najtańsza.

## Eksperyment 2: zatrzymany consumer, kolejka rośnie

Tu widać, po co jest kolejka: catalog działa dalej, a zdarzenia czekają.

```bash
docker compose stop search-consumer
# zmień cenę 2-3 razy (jak wyżej) i odśwież panel: Queues → search.product.sync → Ready rośnie
docker compose start search-consumer
# Ready spada do 0, a ES dogania stan z Postgresa
```

## Na serwerze (k3s)

`make mq-*` i `docker compose` działają tylko lokalnie. Na k3s:

```bash
make k3s-mq-status                     # kolejki: głębokość i konsumenci
make k3s-ui                            # panel RabbitMQ na :36672
```

Consumer na k3s działa bez `-vv`, więc w `kubectl logs deploy/search-consumer`
nie zobaczysz pojedynczych wiadomości. Tam podglądem jest panel (wykres
*Message rates*) i `make k3s-mq-status`.

## Stare wiadomości w lokalnym DLQ

Lokalnie w `search.product.sync.dlq` leżą 2 wiadomości `offer.price_changed`
po 48 bajtów, z `x-death.reason = rejected`. Prawdziwe zdarzenie ma kilkaset
bajtów, więc to raczej ręczne testy z wcześniejszych etapów (np.
`make mq-publish`), a nie zgubione zmiany. Na k3s DLQ jest puste.

## Zadania domowe

1. Opublikuj ręcznie zepsutą wiadomość (`make mq-publish rk=offer.created`)
   i zobacz, czy ląduje w DLQ. W panelu znajdź nagłówek `x-death`.
2. W eksperymencie 2 zmień cenę tej samej oferty 3 razy przy zatrzymanym
   consumerze. W ES powinna wylądować ostatnia cena. Dlaczego kolejność się
   nie psuje? (podpowiedź: `sequence` w logu i `version` w payloadzie)
