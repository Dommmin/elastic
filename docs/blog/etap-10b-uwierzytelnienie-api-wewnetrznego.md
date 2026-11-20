# Uwierzytelnienie `/api/internal`, krok po kroku: wspólny sekret zamiast zaufania do nagłówka Host

*Dopinka do ETAPU 10. search-service czyta projekcje produktów z catalogu przez `/api/internal/*`. Od ETAPU 6 te trasy
nie miały uwierzytelnienia, a ETAP 10 dołożył do nich endpointy wsadowe (`count`, `ids`, `changed`, `projections` do
500 naraz). Ten krok zamyka je wspólnym sekretem i dodatkowo chowa je przed publicznym hostem.*

> **Stan:** gałąź `internal-api-auth`, sprawdzone na lokalnym stosie Docker 2026-11-20. Nie wdrożone na k3s (brak
> serwera). Błędy i diagnoza: [RUNBOOK #127](../RUNBOOK.md#127).

---

## 1. Problem: kto mógł wywołać `/api/internal`

Przed zmianą oba poniższe żądania z hosta dostawały liczbę produktów: grupa `internal` nie miała middleware'u,
a żaden blok Caddy'ego jej nie zatrzymywał.

```bash
curl -sk https://catalog.localhost:8443/api/internal/products/count
curl -s -H 'Host: catalog-app' http://127.0.0.1:18080/api/internal/products/count
```

Są dwie drogi wejścia:

1. **Publiczny blok Caddy'ego** (`catalog.localhost`) przepuszczał całe `/api/*` do Laravela, więc trasy wewnętrzne też.
2. **Blok `http://catalog-app`** (port 80) jest przeznaczony dla ruchu w sieci dockerowej. Port 80 kontenera jest jednak
   opublikowany na hoście, a Caddy wybiera blok po nagłówku `Host`. Ktoś, kto wyśle `Host: catalog-app`, trafi do bloku
   „wewnętrznego” z zewnątrz.

Wniosek: nazwa hosta i ścieżka to coś, co klient **deklaruje**. Granicą zaufania musi być coś, co klient **wie**.

## 2. Decyzja

| Wariant | Co daje | Dlaczego nie / dlaczego tak |
|---|---|---|
| Tylko blokada ścieżki w Caddy | zamyka drogę 1 | droga 2 (sfałszowany Host) zostaje otwarta |
| Osobny, nieopublikowany port dla API wewnętrznego | zamyka obie drogi w Compose | na k3s Service i NetworkPolicy wymagają osobnego portu i zmian w manifestach; to więcej ruchomych części niż token |
| mTLS | silna tożsamość serwisu | CA, rotacja certyfikatów, konfiguracja klienta: za dużo jak na jeden zaufany klient |
| **Wspólny sekret w nagłówku + blokada ścieżki** | zamyka obie drogi, działa tak samo w Compose i na k3s | **wybrane**: jeden klient (search), jedna zmienna, wzorzec już znany z webhooka bramki (HMAC) |

## 3. Catalog: middleware `RequireInternalToken`

`apps/catalog/app/Http/Middleware/RequireInternalToken.php`, podpięty w `routes/api.php` na grupie `internal`:

- sekret z `config('services.internal_api.token')`, czyli zmiennej `INTERNAL_API_TOKEN`;
- **pusty sekret w konfiguracji = 503 dla każdego** (fail closed). Bez tego pusty nagłówek przeszedłby porównanie dwóch
  pustych napisów;
- zły lub brakujący nagłówek `X-Internal-Token` = 401;
- `hash_equals` zamiast `===`, bo porównuje w czasie stałym i czas odpowiedzi nie zdradza, ile znaków się zgadza.

Testy: `tests/Feature/Internal/InternalApiTokenTest.php` (401 bez tokenu i ze złym tokenem dla wszystkich pięciu tras,
przepuszczenie z poprawnym, 503 przy pustym sekrecie). Bazowy `tests/TestCase.php` dokleja poprawny token do każdego
żądania, więc istniejące testy endpointów wewnętrznych przechodzą przez prawdziwy middleware.

```bash
docker compose exec catalog-app php artisan test --compact tests/Feature/Internal/InternalApiTokenTest.php
```

## 4. Search: nagłówek w `CatalogProjectionClient`

Token trafia do opcji domyślnych klienta HTTP (`withOptions(['headers' => …])`), więc dostaje go każde żądanie:
pojedyncza projekcja w consumerze i wszystkie ścieżki wsadowe narzędzi z ETAPU 10. Wartość wstrzykuje
`config/services.yaml` (`$internalApiToken: '%env(INTERNAL_API_TOKEN)%'`, z domyślną pustą, żeby testy
i `cache:clear` nie wymagały zmiennej). Pusty token oznacza brak nagłówka. Catalog odpowie wtedy 401, a klient rzuci
`ProjectionUnavailableException`, czyli głośny błąd z ponowieniem, a nie cichą zgadywankę.

Test: `tests/Unit/Service/CatalogProjectionClientTokenTest.php`.

## 5. Caddy: 404 na publicznym hoście

W publicznym bloku `infra/caddy/Caddyfile`:

```caddy
@internalApi path /api/internal/* /index.php/api/internal/*
respond @internalApi 404
```

Wariant `/index.php/…` jest potrzebny z tego samego powodu co przy `/prometheus`: `php_server` dzieli ścieżkę na `.php`,
a Laravel dostaje `PATH_INFO=/api/internal/…`. Caddyfile jest wkopiowany do obrazu, więc po zmianie trzeba go przebudować:

```bash
docker compose build catalog-app && docker compose up -d --no-deps catalog-app
```

## 6. Sekret: gdzie mieszka

| Środowisko | Źródło | Brak wartości |
|---|---|---|
| Compose dev | `compose.yaml`: `${INTERNAL_API_TOKEN:-dev-only-internal-api-token}` w `catalog-app` i `search-consumer` | wartość dev |
| Compose prod (VPS) | `compose.prod.yaml`: `${INTERNAL_API_TOKEN:?…}` | `docker compose` odmawia startu |
| k3s | `marketplace-secrets`, klucz `INTERNAL_API_TOKEN` (nieopcjonalny) w obu Deploymentach | pod w `CreateContainerConfigError`; `tools/k3s/secrets.sh` odmawia wcześniej |
| k3d | `tools/k3d/secrets.sh` generuje losowy i zachowuje przy kolejnych uruchomieniach | - |

Prawdziwa wartość jest tylko w nieśledzonym `.env` na serwerze. W repo jest wyłącznie pusty wpis w `.env.example`:

```bash
echo "INTERNAL_API_TOKEN=$(openssl rand -hex 32)" >> /opt/marketplace/.env
```

## 7. Weryfikacja na żywo (2026-11-20)

```bash
docker compose up -d --no-deps catalog-app search-consumer
curl -sk -o /dev/null -w '%{http_code}\n' https://catalog.localhost:8443/api/internal/products/count             # 404
curl -sk -o /dev/null -w '%{http_code}\n' https://catalog.localhost:8443/index.php/api/internal/products/count   # 404
curl -sk -o /dev/null -w '%{http_code}\n' 'https://catalog.localhost:8443/api/search?q=buty'                     # 200
curl -s  -o /dev/null -w '%{http_code}\n' -H 'Host: catalog-app' http://127.0.0.1:18080/api/internal/products/count  # 401
docker compose exec search-consumer sh -c \
  'curl -s -o /dev/null -w "%{http_code}\n" -H "X-Internal-Token: $INTERNAL_API_TOKEN" http://catalog-app/api/internal/products/count'  # 200
```

Pełna ścieżka zdarzeń (catalog → outbox → RabbitMQ → search-consumer → projekcja z tokenem → ES):

```bash
make churn rate=10 duration=10        # updated=94 deleted=6 failed=0 since=2026-11-20T09:10:20+00:00
docker compose exec catalog-app php artisan search:verify-convergence --since=2026-11-20T09:10:20+00:00
# checked=91 mismatches=0
```

W logach search-consumera z tego okna: zero wpisów `401` i zero `ProjectionUnavailable`.

Testy: catalog 924/924. W search przechodzi wszystko poza 8 testami `ErrorTracking`, które padały już przed ETAPEM 10.

## 8. Czego to nie załatwia

- **Jeden wspólny sekret** nie mówi, *który* serwis woła. Przy drugim kliencie: osobny token na klienta albo mTLS.
- **Rotacja** wymaga dziś krótkiego okna, w którym catalog i search mają różne wartości (401 i ponowienia w consumerze).
  Zadanie domowe: catalog akceptuje listę tokenów (`INTERNAL_API_TOKENS=nowy,stary`), najpierw zmiana catalogu,
  potem search, na końcu usunięcie starego.
- **Ruch w sieci dockerowej jest jawnym HTTP**, więc token da się podsłuchać z wnętrza sieci `marketplace`. Kto tam jest,
  ma i tak dostęp do Postgresa i RabbitMQ, więc to nie zmienia modelu zagrożeń.

## Ściąga

```bash
docker compose exec catalog-app php artisan test --compact tests/Feature/Internal
docker compose exec search-consumer php bin/phpunit tests/Unit/Service/CatalogProjectionClientTokenTest.php
curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: catalog-app' http://127.0.0.1:18080/api/internal/products/count   # 401
```
