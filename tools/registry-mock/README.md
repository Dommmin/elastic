# Mock rejestru firm i kurierów (ETAP 13)

Udaje zewnętrzny rejestr, z którego `seller360:registry:sync` buduje sekcję `registry` w `seller-360`.
Dane są deterministyczne z NIP (crc32): ten sam NIP daje zawsze tę samą firmę i tego samego kuriera.

Start: `docker compose up -d registry-mock` (obraz search, `php -S` z 4 workerami, port 8080 tylko w sieci Compose).

| Endpoint | Odpowiedź |
|---|---|
| `GET /companies/{nip}` | `{nip, legal_name, vat_status: active\|inactive, registered_at}` |
| `GET /couriers/assignments/{nip}` | `{courier_id: "courier-1".."courier-8"}` |
| `GET /couriers/ratings?since=ISO` | `[{courier_id, on_time_rate, rated_at}]`, tylko oceny nowsze niż `since` |
| `GET /_chaos` | bieżący stan awarii |
| `POST /_chaos` | `{delay_ms, error_rate, outage, bump_courier, reset}` |

## Sterowanie awarią

Stan leży w `/tmp/registry-mock-chaos.json` wewnątrz kontenera (znika z kontenerem).

```bash
docker compose exec search-consumer curl -s -XPOST registry-mock:8080/_chaos -d '{"outage":true}'
docker compose exec search-consumer curl -s -XPOST registry-mock:8080/_chaos -d '{"outage":false}'
```

- `delay_ms`: stałe opóźnienie każdej odpowiedzi.
- `error_rate`: 0..1, prawdopodobieństwo odpowiedzi 503.
- `outage: true`: każde żądanie czeka 3 s (dłużej niż timeout klienta 2 s, więc ćwiczy ścieżkę timeoutu), potem 503.
- `bump_courier: "courier-N"`: zmienia ocenę kuriera i ustawia `rated_at` na teraz; odpytanie
  `/couriers/ratings?since=<chwila przed>` zwróci właśnie jego.
- `reset: true`: czyści wszystko, także zmienione oceny.

Endpoint `/_chaos` nie podlega awarii, więc zawsze da się ją wyłączyć.
