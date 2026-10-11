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

Zmianę robimy w panelu edycji katalogu (przewodnik:
[panel-edycji.md](panel-edycji.md)). Panel zapisuje ją przez
`updateWithOutbox`, czyli zdarzenie trafia do outboxu w tej samej transakcji,
dokładnie jak w produkcji.

1. Jednorazowo załóż konto do panelu (hasło wypisze się raz):
   ```bash
   make admin email=ty@example.com
   ```
2. W drugim terminalu podglądaj consumer:
   ```bash
   docker compose logs -f search-consumer
   ```
3. Otwórz https://catalog.localhost:8443/panel/products, zaloguj się, wpisz
   `1` w wyszukiwanie i wejdź w produkt 1. Zapamiętaj cenę dowolnej oferty
   (najlepiej nie pierwszej) i zmień ją na `19,99` → „Zapisz”.
4. Pod ofertami, w sekcji „Droga zdarzenia”, pojawi się `offer.price_changed`.
   Lampki zapalają się po kolei: *zapisane* → *opublikowane* (outbox-publisher
   wysłał do RabbitMQ) → *w ES* (consumer zaindeksował), zwykle w 1–2 s.
   W logach consumera:
   ```
   [messenger] Received message App\Message\IntegrationEvent
   [app] Zaindeksowano produkt. ["product_id" => "1","event_type" => "offer.price_changed","sequence" => 2,"doc_version" => 1791730685612428]
   ... was handled successfully (acknowledging to transport)
   ```
   `sequence` to licznik tej jednej oferty, `doc_version` to wersja dokumentu
   w ES (czas odczytu projekcji w µs). Dlaczego to dwie różne liczby, opisuje
   przewodnik po panelu.
5. Wyszukiwarka widzi już nową cenę (`price_min` = 1999, czyli 19,99 zł),
   o ile to teraz najtańsza oferta produktu:
   ```bash
   curl -sk "https://catalog.localhost:8443/api/search?q=Batz-Beer%20Ultra%20Boost&per_page=1"
   ```
6. Przywróć starą cenę w panelu. To kolejne zdarzenie, które też przejdzie
   przez kolejkę.

Bez przeglądarki to samo zrobisz jedną linijką w tinkerze:

```bash
docker compose exec catalog-app php artisan tinker --execute 'App\Models\Offer::find(4)->updateWithOutbox(["price_cents" => 1999]);'
```

## Eksperyment 2: zatrzymany consumer, kolejka rośnie

Tu widać, po co jest kolejka: catalog działa dalej, a zdarzenia czekają.

```bash
docker compose stop search-consumer
```

Zmień w panelu cenę 2–3 razy. W drodze zdarzenia nowe wiersze stoją na
*opublikowane*, strona odpytuje co sekundę, a porównanie „Postgres a dokument
w ES” pokazuje „Najniższa cena (różni się)”. W panelu RabbitMQ:
Queues → `search.product.sync` → *Ready* rośnie (albo `make mq-status`).

```bash
docker compose start search-consumer
```

*Ready* spada do 0, lampki *w ES* się zapalają, odpytywanie staje, a ceny
w porównaniu się zgadzają.

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
   nie psuje? (podpowiedź: consumer nie bierze ceny z wiadomości, tylko
   pobiera aktualną projekcję, a `doc_version` w logu to chwila jej odczytu)
