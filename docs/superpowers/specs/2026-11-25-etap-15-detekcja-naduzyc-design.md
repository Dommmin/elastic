# ETAP 15 — Security analytics lite: detekcja nadużyć sprzedawców (design)

Data: 2026-11-25. Gałąź: `etap-15-detekcja`. Tor 5 z `docs/04-ES-JAKO-PLATFORMA.md` (§ TOR 5),
ETAP 15 z `docs/06-PLAN-WDROZENIA.md`.

## 1. Po co

Projekt jest do nauki Elasticsearcha. Etapy 12–14 nauczyły analityki, agregatu i wektorów; zostało
myślenie **regułami detekcji**: jak opisać nadużycie tak, żeby ES je znalazł, i którym narzędziem ES
opisać który kształt nadużycia. Wynik ma być mierzalny: na danych z podłożonymi oszustami i bliskimi
pudłami każda reguła dostaje liczby (złapani, fałszywe alarmy).

**DoD etapu:** pięć reguł działa na żywym stosie Compose, `security:report` podaje dla każdej TP / FP / FN
na zbiorze z ziarnem, a przewodnik `docs/blog/etap-15-detekcja-naduzyc.md` tłumaczy, dlaczego dana
reguła trafiła do percolatora, Kibana Alerting, EQL czy agregacji. Dodatkowo: wiesz, czego reguła
nie złapie (bliskie pudła).

## 2. Cele, nie-cele, anty-cele

**Cele**
- Prawdziwy przepływ zdarzeń: sklep emituje sygnały bezpieczeństwa (recenzja z kontekstem klienta,
  zmiana ceny) przez istniejący outbox → RabbitMQ → konsument Symfony → data stream `events-security`.
- Symulator dokłada ruch z datą wsteczną i podłożonych oszustów tą samą drogą od RabbitMQ dalej
  (jak `seller360:simulate` w ETAPIE 13), plus prawda podstawowa w pliku.
- Pięć reguł, każda w narzędziu pasującym do jej kształtu (sekcja 6).
- Jeden indeks alertów `alerts-security` i raport skuteczności.

**Nie-cele**
- Elastic Security / SIEM / moduł detection engine (granica z `06`, część E).
- Percolator dla `saved_alerts` („powiadom mnie, gdy pojawi się X”) z ETAPU 7: zostaje niezrobiony,
  odnotowany jako follow-up.
- Panel moderatora w aplikacji, automatyczne blokowanie sprzedawców, powiadomienia mailowe.
- Domena zwrotów towaru: nie istnieje i nie powstaje. Trzecia reguła z toru 5 („skok zwrotów”)
  działa na **reklamacjach** z ETAPU 13 (`complaints`), które już są w ES.
- Wdrożenie na k3s (brak serwera); tylko Compose. Manifestów k3s ten etap nie dodaje.

**Anty-cele (porażka, nawet jeśli działa)**
- Surowe IP w ES, Postgresie albo outboxie. Repo jest publiczne, a IP to dane osobowe.
- Zepsucie istniejących dowodów: `make seller360-verify` musi dalej dawać 5/5, reguły ETAPU 12
  (`zero-results-spike`, `ctr-drop`) dalej działają, pełne suity testów zielone.
- Reguły, których skuteczności nie da się policzyć (alert bez klucza encji i okna).

## 3. Ograniczenia

- Repo `Dommmin/elastic` jest publiczne: sekret do haszowania IP (`SECURITY_IP_PEPPER`) tylko w `.env`
  i `apps/*/.env*`; `.env.example` ma pusty placeholder.
- Maszyna współdzielona (Docker VM 15,6 GB): etap działa na głównym klastrze es01–03, bez `es-lab`
  i bez trybu lab. Żadnego nowego długożyjącego kontenera poza tym, co da się upchnąć w istniejące
  procesy (konsument i scheduler Symfony).
- Licencja głównego klastra: basic. EQL, percolator, `significant_terms`, `moving_fn`, Kibana
  Alerting z regułą ES|QL i konektorem index działają na basic (konektor index był już w ETAPIE 12).
- Wzorce istniejące, których się trzymamy: `EmitsOutboxEvents` / `OfferIntegrationEvents` (catalog),
  `ExternalJsonEnvelopeSerializer` i batch handler jak `AnalyticsEventHandler` (search), definicje
  RabbitMQ w `infra/rabbitmq/definitions.template.json` (kolejka + DLQ), skrypty setupu w
  `infra/elasticsearch/` z idempotencją, reguły Kibany w `infra/kibana/rules/` ładowane po nazwie,
  `SimulatedEventPublisher` i plik prawdy jak `planted.json`.
- Strażnik ceny w domenie (`PriceChangePolicy`): zmiana > 30% wymaga akceptacji moderatora, limit
  5 zmian w kroczącej dobie. Reguły ceny celują dokładnie w to, czego strażnik nie widzi.

## 4. Podejścia (wybrane w rozmowie)

Źródło zdarzeń:
- A. tylko symulator, osobny strumień, sklep nietknięty — szybko, ale reguły patrzą na dane, których
  żadna aplikacja nie wytwarza;
- **B. prawdziwy przepływ + symulator (wybrane)** — sklep emituje brakujące sygnały, symulator dokłada
  ruch i oszustów od RabbitMQ dalej. Więcej pracy, ale jak w ETAPIE 13 i z mierzalnym wynikiem.

Gdzie działają reguły:
- 1. wszystko w Kibana Alerting (ES|QL) — ES|QL nie ma sekwencji, reguły Kibany nie używają
  `significant_terms` / `moving_fn`, percolator bez roli;
- 2. wszystko w kodzie PHP — uczy PHP, nie ES;
- **3. każda reguła w narzędziu pasującym do kształtu (wybrane)**: pojedyncze zdarzenie → percolator
  przy wejściu; seria z jednego IP → Kibana Alerting (ES|QL); sekwencja w czasie → EQL; skok
  względem historii → agregacje (`date_histogram` + `moving_fn`, `significant_terms`).
- Odrzucone: detection engine z Elastic Security (granica „bez SIEM”, robi za ucznia to, czego ma się
  nauczyć).

## 5. Przepływ danych

```
sklep (Laravel)                        RabbitMQ marketplace.events           search (Symfony)
 POST /products/{id}/reviews  ─┐
   ip → HMAC + prefiks          ├─ outbox ─▶ review.created ─┐
 ChangeOfferPrice (domena)    ─┘            offer.price_changed ─┼─▶ search.security.ingest ─▶ SecurityEventHandler
                                                                  │      (+ DLQ)                  │ _bulk create → events-security-default
 security:simulate (catalog) ── SimulatedEventPublisher ─────────┘                              │   (pipeline: events-ingested + popularność)
                              └─ ComplaintsCsvWriter → seller360 complaints import → `complaints` │ percolate zapisanych dokumentów
                                                                                                   ▼   względem `security-rules`
                                                                                             alerts-security ◀── security:detect (EQL, agregacje, backfill ES|QL)
                                                                                                   ▲
                                                                                Kibana Alerting (ES|QL, konektor index)
```

### 5.1 Sygnały ze sklepu

- **Recenzja ze sklepu (nowe).** Formularz na stronie produktu dla zalogowanego użytkownika,
  `POST /products/{product}/reviews` (ocena 1–5, opcjonalna treść, throttling, jedna recenzja
  użytkownika na produkt). Zapis domenową ścieżką `Review::createWithOutbox(..., 'review.created')`.
  Payload `review.created` dostaje nowe pola: `client_ip_hash`, `client_ip_prefix`,
  `client_user_agent` (obcięty do 256 znaków), `user_account_age_hours`. Konsument product-sync je
  ignoruje (ma tylko `product_id`), więc nic się dla niego nie zmienia. `SeedReviewsCommand` pola
  zostawia puste (null).
- **IP:** `client_ip_hash` = pierwsze 16 znaków hex z HMAC-SHA256(IP, `SECURITY_IP_PEPPER`);
  `client_ip_prefix` = sieć /24 dla IPv4 i /48 dla IPv6 w zapisie tekstowym (`203.0.113.0/24`).
  Surowe IP nie trafia do bazy, outboxu ani ES. Pusty pepper → pola IP są null, log ostrzeżenia,
  recenzja zapisuje się normalnie (brak sekretu nie psuje sklepu).
- **Prawdziwe IP (decyzja, poprawiona przy planie).** W Compose przed `catalog-app` nie ma osobnego
  proxy: Caddy działa w tym samym procesie FrankenPHP, więc `$request->ip()` to adres, z którego
  przyszło połączenie, i `TRUSTED_PROXIES` zostaje puste (na k3s jest już `10.42.0.0/16`). Na Macu
  cały ruch z hosta przychodzi przez bramę Docker Desktop, więc lokalne recenzje z UI mają jeden
  prefiks — to ograniczenie środowiska, opisane w przewodniku. DoD: jedna recenzja z UI ląduje w
  `events-security` z wypełnionym `client.ip_hash` / `client.ip_prefix` i bez surowego IP.
- **Zmiana ceny (istniejące).** `offer.price_changed` już płynie przez outbox i ma `product_id` oraz
  `seller_id` z `OfferIntegrationEvents::base()` (`apps/catalog/src/SellerPortal/Infrastructure/Persistence/`).
  Nie ma `change_pct` — liczy go handler: `(new − old) / old × 100`, ze znakiem, zaokrąglone do 2 miejsc.
  Reguły widzą **tylko** `offer.price_changed`; zmiany > 30% idą przez moderację
  (`offer.price_change_requested` / `_approved`) i są celowo poza regułami: przeszły przez człowieka.
- **Kliknięcia (istniejące).** `user.clicked_result` w `events-user-*` (`AnalyticsDocumentMapper`)
  ma `offer.id`, gdy payload niesie `offer_id` — źródło „widoczności” dla reguły EQL. Builder sprawdza
  testem, że prawdziwe kliknięcia ze sklepu niosą `offer_id`; symulator zawsze je ustawia.
- **Reklamacje (istniejące).** Indeks `complaints` z ETAPU 13 (`seller_id`, `created_at`, `category`).

### 5.2 `events-security`

- Data stream `events-security-default`, template `events-security`, mapowanie `dynamic: strict`,
  ILM `events-security` z usunięciem po 30 dniach (krótka retencja = mniej danych osobowych).
- Pola (ECS tam, gdzie się da): `@timestamp`, `event.{id,type,ingested}`, `offer.id`,
  `offer.old_price_cents`, `offer.new_price_cents`, `offer.change_pct` (liczone w handlerze,
  ze znakiem), `product.id`, `product.popularity` (z istniejącego enrich `product-popularity`),
  `seller.id`, `user.id`, `user.account_age_hours`, `review.id`, `review.rating`,
  `client.ip_hash`, `client.ip_prefix`, `user_agent.original`, `trace.id`, `labels.synthetic` (bool).
- Deduplikacja jak w ETAPIE 12: `_id` = `event.id`, `op_type: create`, 409 = duplikat = ack.

### 5.3 Konsument

`SecurityEventHandler` (batch, jak `AnalyticsEventHandler`), transport `security`, kolejka
`search.security.ingest` z bindingami `offer.price_changed` i `review.created` oraz DLQ
`search.security.ingest.dlq`. Po zapisie paczki percoluje zapisane dokumenty (po pipeline, więc reguły
widzą `product.popularity`) względem indeksu `security-rules` i zapisuje dopasowania do
`alerts-security`. Mechanika percolacji (np. `_msearch` percolate po `index`+`id` albo dokumenty
inline po wzbogaceniu) — decyzja buildera, warunek: reguła widzi pola z pipeline.

## 6. Reguły

Każdy alert ma klucz: `rule.id` + encja (`entity.type`, `entity.id`) + okno (`window.start`,
`window.end`). Raport liczy unikalne klucze.

| id | narzędzie | kształt | warunek | encja |
|---|---|---|---|---|
| `price-hug-threshold` | percolator | pojedyncze zdarzenie | podwyżka 25 ≤ `change_pct` ≤ 30 (strażnik łapie dopiero > 30) na ofercie z `product.popularity` ≥ p95 | oferta |
| `fresh-account-5star` | percolator | pojedyncze zdarzenie | ocena 5 od konta młodszego niż 24 h | użytkownik |
| `review-ip-burst` | Kibana Alerting, ES\|QL | seria z jednego źródła | ≥ 5 różnych użytkowników z jednym `client.ip_hash` w oknie 1 h | `ip_hash` |
| `price-bait-and-switch` | EQL | sekwencja w czasie | `sequence by offer.id with maxspan=48h`: obniżka ≥ 15% → kliknięcia (`with runs=`N, N w regule, start 20) → podwyżki; post-filtr w PHP: każdy krok < 30% (strażnik milczał), a cena końcowa ≥ 1,3 × cena sprzed obniżki | oferta |
| `complaints-spike` | agregacje | skok względem historii | per sprzedawca: `date_histogram` 1 d + `moving_fn` (średnia i odchylenie z 7 poprzednich dni, `shift` tak, żeby dzień nie liczył sam siebie); dzień ≥ średnia + 3σ i ≥ 5 reklamacji. `significant_terms` po `seller_id` (ostatnia doba vs tło 30 dni) jako drugi sygnał w dowodach alertu, `significant_terms` po `category` dla wskazanego sprzedawcy jako wyjaśnienie | sprzedawca |

- **Próg p95 (decyzja):** liczony z indeksu `product-popularity` (`percentiles`) przy ładowaniu reguł
  (`make detection-setup` / `security:rules:load`) i wpisany w zapytanie reguły oraz jej `params.p95`.
  Symulator czyta próg z dokumentu reguły, nie liczy go sam, więc pomiar nie dryfuje między przebiegami;
  raport wypisuje użyty próg. Odświeżenie popularności nie zmienia progu do następnego ładowania reguł.
- **Percolator:** reguły to dokumenty w `security-rules` (pole `query` typu `percolator` + `rule.id`,
  `severity`, `description`), źródłem są pliki `infra/elasticsearch/detection/rules/*.json`, ładowane
  idempotentnie. Dodanie reguły = zapis dokumentu, bez deployu (pokazane w przewodniku).
- **ES|QL w Kibanie:** plik `infra/kibana/rules/security-review-ip-burst.json` ładowany jak reguły
  ETAPU 12, akcja konektorem index do `alerts-security`. Ten sam tekst zapytania czyta
  `security:detect --rule=review-ip-burst --since=…` i puszcza go przez `_query` na historii
  (backfill do pomiaru; Kibana patrzy tylko na bieżące okno). Jedno źródło prawdy zapytania.
- **EQL i agregacje:** `security:detect [--rule=…] [--since=24h]` w aplikacji search; cyklicznie co
  5 min dopięte do istniejącego `seller360-scheduler` (decyzja: bez nowego kontenera), ręcznie z
  `--since` do backfillu. Zdarzenia w EQL: `user.clicked_result` i `offer.price_changed`.
- **Idempotencja:** zapisy z Symfony mają `_id` = sha1(`rule.id`|encja|`window.start`); ponowny
  `security:detect` nie dubluje. Konektor index z Kibany nie ustawia `_id` — raport deduplikuje po
  kluczu.

## 7. `alerts-security`

Zwykły indeks (nie data stream, bo zapisy z `_id` mają nadpisywać). Pola: `@timestamp`, `rule.id`,
`rule.kind` (`percolator`|`esql`|`eql`|`aggregation`), `severity`, `entity.type`, `entity.id`,
`window.start`, `window.end`, `message`, `evidence` (`flattened`), `labels.synthetic`.
Data view w Kibanie dla `alerts-security` i `events-security-*`.

## 8. Symulator i pomiar

- `security:simulate --seed=1 --days=14 --plant=5` w catalog (Laravel). Deterministyczny
  (`Mt19937($seed)`, stała kolejność, jak `seller360:simulate`). Publikuje koperty z datą wsteczną
  przez `SimulatedEventPublisher` (`offer.price_changed`, `review.created`, `user.clicked` dla ofert
  z sekwencji), reklamacje przez `ComplaintsCsvWriter` i istniejący import seller-360.
- Tło: zwykłe zmiany cen (w tym legalne podwyżki), recenzje z rozproszonych IP z puli TEST-NET
  (192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24 i dokumentacyjne IPv6), dzienny szum reklamacji.
  Encje tylko z istniejących danych (oferty, produkty, użytkownicy, sprzedawcy syntetyczni z ETAPU 13).
- **Reklamacje (decyzja):** trafiają na NIP-y istniejących sprzedawców syntetycznych z ETAPU 13
  (enrich `seller-by-nip` już je zna), z wyłączeniem sprzedawców z `planted.json` ETAPU 13 (zasadzeni i
  bliskie pudła), więc `seller360-verify` nie widzi zmian w swoich przypadkach. `ComplaintsCsvWriter`
  dostaje parametr prefiksu (domyślnie `SIM-`, tu `SEC-`); `seller360:purge-synthetic` dalej czyści
  tylko `SIM-` (builder potwierdza testem), `security:purge-synthetic` czyści `SEC-` i odświeża sekcję
  reklamacji seller-360 dla dotkniętych sprzedawców.
- Na każdą regułę `--plant` podłożonych przypadków plus bliskie pudła, których reguła **nie** ma złapać
  (np. podwyżka 24%; 4 użytkowników z jednego IP; przynęta z ceną końcową 1,28×; skok reklamacji 2σ).
- Prawda w `apps/catalog/storage/app/private/security/planted-security.json` (git-ignored): klucz
  encji, reguła, typ (`planted`|`near_miss`), okno.
- Znaczniki syntetyczności (`labels.synthetic`, prefiks `SEC-` w `ticket_id` reklamacji) i
  `security:purge-synthetic` sprzątający `events-security` i reklamacje `SEC-`. Purge nie rusza
  zbioru dowodowego ETAPU 13.
- `security:report` (search) czyta `alerts-security` i plik prawdy (ścieżka podana opcją) → tabela
  per reguła: TP, FP (w tym ile z bliskich pudeł), FN, precision, recall; `--json` dla POMIARY.
- Wymóg: po `security:simulate` i `security:detect --since=15d` `make seller360-verify` dalej 5/5.

## 9. Operacje

- `make detection-setup` → `infra/elasticsearch/setup-detection.sh` (template, ILM, data stream,
  `security-rules`, `alerts-security`, reguły percolatora, reguła Kibany, data view); idempotentny.
  Nazwa nie koliduje z istniejącym `setup-security.sh` (FLS/DLS z ETAPU 13).
- `make detection-simulate`, `make detection-detect`, `make detection-report`.
- RabbitMQ: kolejka i DLQ w `definitions.template.json` i wygenerowanym `definitions.json`.
- Compose (decyzja): transport `security` konsumuje istniejący kontener konsumenta analityki jednym
  procesem `messenger:consume analytics security` — bez nowego kontenera, budżet pamięci bez zmian.
- RUNBOOK: nowe wpisy (od #158), POMIARY §19, przewodnik `docs/blog/etap-15-detekcja-naduzyc.md`,
  aktualizacja ETAPU 14/15 w `06-PLAN-WDROZENIA.md` (w tym poprawka „niewypchnięta” przy ETAPIE 14).

## 10. Co zostaje builderowi

- Mechanika percolacji po wzbogaceniu (5.3) i dokładny kształt zapytań ES|QL / EQL / agregacji.
- N w `runs=` i progi startowe, jeśli dane pokażą, że podane nie mają sensu — zmiana z uzasadnieniem
  w przewodniku, nie po cichu.
- Gdzie dokładnie podpiąć cykliczne `security:detect` w istniejącym schedulerze.
- Wygląd formularza recenzji (prosty, istniejące komponenty UI).
