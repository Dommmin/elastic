# Detekcja nadużyć sprzedawców, krok po kroku: percolator, ES|QL, EQL i agregacje na jednym strumieniu zdarzeń

*ETAP 15 projektu Marketplace Search Platform (tor 5 z `04-ES-JAKO-PLATFORMA.md`, „security analytics lite”).
Etapy 12–14 nauczyły analityki, agregatu i wektorów. Tu uczymy się myślenia **regułami detekcji**: jak opisać
nadużycie tak, żeby Elasticsearch je znalazł, i **którym narzędziem ES** opisać który kształt nadużycia. Pięć reguł,
cztery narzędzia, jeden indeks alertów i wynik w liczbach: na danych z podłożonymi oszustami i bliskimi pudłami każda
reguła dostaje TP / FP / FN.*

> **Stan:** gałąź `etap-15-detekcja`, lokalny stos Docker Compose (3 węzły ES 9.5.1, Kibana 9.5.1, licencja basic),
> sprawdzone 2026-11-25. **Na żywo:** `make detection-report` daje 5/5 TP, 0 FP, 0 FN dla każdej z pięciu reguł
> (25 alertów, ziarno 1), drugi `make detection-detect` nie zmienia liczby alertów (25 → 25), `make seller360-verify`
> dalej `DoD: OK`, recenzja wysłana przez HTTP ląduje w ES z haszem i prefiksem IP, bez surowego adresu.
> Projekt: [specyfikacja](../superpowers/specs/2026-11-25-etap-15-detekcja-naduzyc-design.md),
> [plan](../superpowers/plans/2026-11-25-etap-15-detekcja-naduzyc.md).
> Pomiary: [`POMIARY.md` §19](../POMIARY.md). Błędy z drogi: RUNBOOK [#158–#167](../RUNBOOK.md#158).
> Bez Elastic Security / SIEM i bez wdrożenia na k3s: tylko Compose.

---

## Spis treści

1. [Problem: nadużycia, których strażnik ceny nie widzi](#1-problem-nadużycia-których-strażnik-ceny-nie-widzi)
2. [Decyzje](#2-decyzje)
3. [Mapa plików](#3-mapa-plików)
4. [Cały tor 15 od zera](#4-cały-tor-15-od-zera)
5. [Krok 1 — kształt nadużycia wybiera narzędzie](#krok-1--kształt-nadużycia-wybiera-narzędzie)
6. [Krok 2 — sygnały ze sklepu: recenzja i odcisk IP](#krok-2--sygnały-ze-sklepu-recenzja-i-odcisk-ip)
7. [Krok 3 — data stream `events-security` i konsument](#krok-3--data-stream-events-security-i-konsument)
8. [Krok 4 — percolator: reguła jako dokument](#krok-4--percolator-reguła-jako-dokument)
9. [Krok 5 — nowa reguła percolatora bez deployu](#krok-5--nowa-reguła-percolatora-bez-deployu)
10. [Krok 6 — `review-ip-burst`: ES|QL w Kibanie i ten sam tekst w backfillu](#krok-6--review-ip-burst-esql-w-kibanie-i-ten-sam-tekst-w-backfillu)
11. [Krok 7 — `price-bait-and-switch`: sekwencja w EQL](#krok-7--price-bait-and-switch-sekwencja-w-eql)
12. [Krok 8 — `complaints-spike`: `moving_fn` i `significant_terms`](#krok-8--complaints-spike-moving_fn-i-significant_terms)
13. [Krok 9 — `alerts-security` i klucz alertu](#krok-9--alerts-security-i-klucz-alertu)
14. [Krok 10 — symulator i prawda podstawowa](#krok-10--symulator-i-prawda-podstawowa)
15. [Krok 11 — pomiar: `security:report`](#krok-11--pomiar-securityreport)
16. [Czego reguły nie złapią](#czego-reguły-nie-złapią)
17. [Liczby](#liczby)
18. [Ograniczenia](#ograniczenia)
19. [Co wybuchło po drodze](#co-wybuchło-po-drodze)
20. [Pytania kontrolne](#pytania-kontrolne)
21. [Powrót do normalnej pracy](#powrót-do-normalnej-pracy)
22. [Ściąga](#ściąga)

---

## 1. Problem: nadużycia, których strażnik ceny nie widzi

W portalu sprzedawcy (ETAP E) cenę oferty pilnuje domena: `PriceChangePolicy` puszcza bez moderatora zmianę
o najwyżej 30% i najwyżej 5 zmian w kroczącej dobie. Strażnik działa na **jednej** zmianie jednej oferty. Nie widzi:

- podwyżki o 29% na najpopularniejszym produkcie (tuż pod progiem, celowo),
- obniżki, która ściąga kliknięcia, i dwóch podwyżek po kilkunastu godzinach („wabik”),
- recenzji 5 gwiazdek od konta założonego godzinę temu,
- pięciu „różnych” klientów wystawiających recenzje z jednego adresu w ciągu godziny,
- sprzedawcy, który nagle dostaje trzy razy więcej reklamacji niż zwykle.

Każde z tych zjawisk ma inny **kształt w czasie**: pojedyncze zdarzenie, seria z jednego źródła, sekwencja kroków,
skok względem własnej historii. Elasticsearch ma na każdy kształt inne narzędzie. Ten etap to ćwiczenie z dobierania
narzędzia do kształtu, zrobione tak, żeby wynik dało się policzyć.

**DoD etapu:** pięć reguł działa na żywym stosie Compose; `security:report` podaje dla każdej TP / FP / FN na zbiorze
z ziarnem; przewodnik (ten) mówi, dlaczego reguła trafiła do percolatora, Kibana Alerting, EQL czy agregacji, i czego
**nie** złapie.

## 2. Decyzje

| Decyzja | Dlaczego |
|---|---|
| **Prawdziwy przepływ + symulator** (nie sam symulator) | Sklep emituje sygnały przez outbox jak w ETAPIE 13 (`review.created` z formularza, istniejące `offer.price_changed`); symulator dokłada ruch z datą wsteczną od RabbitMQ dalej. Reguły patrzą na dane, które aplikacja naprawdę wytwarza |
| **Każda reguła w narzędziu pasującym do kształtu** | Wszystko w Kibanie: ES\|QL nie ma sekwencji, reguły Kibany nie mają `moving_fn`. Wszystko w PHP: uczy PHP, nie ES. Elastic Security (detection engine): poza zakresem i robi za ucznia to, czego ma się nauczyć |
| Percolator **po zapisie**, po referencji do dokumentu w ES | Reguła `price-hug-threshold` potrzebuje `product.popularity`, które dokłada pipeline (enrich). Percolacja dokumentu z RAM-u konsumenta nie widziałaby tego pola |
| Próg p95 popularności **liczony przy ładowaniu reguł** i zapisany w regule | Symulator czyta próg z dokumentu reguły, więc pomiar nie dryfuje między przebiegami. Odświeżenie popularności nie zmienia progu do następnego `make detection-setup` |
| ES\|QL `review-ip-burst` w **jednym pliku** reguły Kibany, czytanym też przez backfill PHP | Kibana liczy tylko bieżące okno; do pomiaru historii `security:detect` puszcza ten sam tekst przez `_query`. Jedno źródło prawdy zapytania |
| EQL `sequence by offer.id` na **dwóch źródłach** (`events-security-*` + `events-user-*`) | Zmiany cen i kliknięcia leżą w różnych data streamach; EQL łączy je po `offer.id` |
| `complaints-spike` na **reklamacjach** z ETAPU 13 (`complaints`) | Domeny zwrotów nie ma; reklamacje już są w ES z `seller_id`, `created_at`, `category` |
| Hasz IP = **HMAC-SHA256 z tajnym pepperem**, 16 hex; do tego prefiks `/24` (IPv6 `/48`) | Repo jest publiczne, a IP to dane osobowe. Surowe IP nigdzie nie trafia (Postgres, outbox, RabbitMQ, ES). Uzasadnienie HMAC zamiast zwykłego haszu: krok 2 |
| ILM `events-security`: **usunięcie po 30 dniach** | Krótka retencja = mniej danych osobowych |
| **Bez nowych kontenerów** | Transport `security` konsumuje istniejący `analytics-consumer` (`messenger:consume analytics security`); `security:detect` to job istniejącego `seller360-scheduler` co 300 s. Maszyna jest współdzielona, budżet pamięci bez zmian |
| `alerts-security` to **zwykły indeks**, nie data stream; `_id` = sha1 klucza alertu | Ponowne wykrycie ma nadpisać ten sam dokument (idempotencja), a data stream przyjmuje tylko `create` |
| Symulator **nie tworzy użytkowników w bazie** | Identyfikatory od 920 000 000 i wiek konta wprost w zdarzeniu; zdarzenia trafiają tylko do ES, Postgres zostaje czysty |
| Symulowane zdarzenia niosą `data.simulation = 'security'` | Te same `offer.price_changed` / `review.created` trafiają też do kolejek `product.sync` i `search.seller360`. Te konsumenty potwierdzają i pomijają zdarzenia symulacji, więc symulowane ceny nie zmieniają produktów ani `seller-360` (i `seller360-verify` zostaje 5/5) |

## 3. Mapa plików

```
apps/catalog/
  app/Security/ClientFingerprint.php           HMAC IP (16 hex), prefiks /24 / /48, obcięcie User-Agent do 256
  app/Http/Controllers/Shop/ReviewController.php  POST /products/{id}/reviews: blokada wiersza użytkownika, outbox review.created
  app/Http/Requests/Shop/StoreReviewRequest.php    ocena 1–5, treść ≤ 2000, jedna recenzja na produkt
  resources/js/pages/shop/Product.vue          formularz „Oceń produkt”
  app/Console/Commands/SecuritySimulateCommand.php  security:simulate (ziarno, dni, --plant)
  app/Security/Simulation/SecurityScenario.php deterministyczny generator: tło, zasadzeni, bliskie pudła
  storage/app/private/security/                (ignorowane przez git) planted-security.json, report-15.json
apps/search/
  src/MessageHandler/SecurityEventHandler.php  batch (500), _bulk create → events-security-default, potem percolacja
  src/Security/SecurityDocumentMapper.php      koperta → dokument; change_pct liczone tutaj
  src/Security/RuleLoader.php                  security:rules:load: pliki reguł → security-rules, p95 z product-popularity
  src/Security/SecurityPercolator.php          _mget z indeksów zapasowych streamu + _msearch percolate
  src/Security/Detect/ReviewIpBurstDetector.php    backfill ES|QL z pliku reguły Kibany
  src/Security/Detect/PriceBaitAndSwitchDetector.php  EQL + post-filtr accept()
  src/Security/Detect/ComplaintsSpikeDetector.php     date_histogram + moving_fn + significant_terms
  src/Security/SecurityDetectJob.php           job seller360-scheduler co 300 s (okno 2 h) i security:detect
  src/Security/Alert.php, AlertWriter.php      klucz alertu, _id = sha1(rule|entity.type|entity.id|window.start)
  src/Security/DetectionScore.php              TP / FP / FN wobec prawdy (security:report)
  src/Security/SecuritySyntheticPurger.php     security:purge-synthetic
infra/elasticsearch/
  setup-detection.sh                           make detection-setup: sekcje [1] ES, [2] reguły percolatora, [3] Kibana
  detection/template-events-security.json      data stream, dynamic: strict
  detection/ilm-events-security.json           rollover 1d/1gb, delete po 30d
  detection/pipeline-events-security.json      event.ingested + enrich product.popularity (błąd nie odrzuca)
  detection/index-security-rules.json          pole query typu percolator + kopia mapowania zdarzeń
  detection/index-alerts-security.json         alerty, evidence: flattened
  detection/rules/price-hug-threshold.json     reguła percolatora (znacznik "{{p95}}")
  detection/rules/fresh-account-5star.json     reguła percolatora
  detection/eql/price-bait-and-switch.eql      zapytanie EQL
infra/kibana/rules/security-review-ip-burst.json   reguła Kibany ES|QL (i źródło backfillu)
infra/kibana/rules/connector-security-index.json   konektor index → alerts-security
infra/rabbitmq/definitions.template.json       kolejka search.security.ingest + DLQ, bindingi
Makefile                                       detection-setup / -simulate / -detect / -report / -purge
```

## 4. Cały tor 15 od zera

Wymagania: zrobione ETAPY 12 i 13 na tym stosie (data streamy `events-user-*`, polityka enrich
`product-popularity-policy` i indeks `product-popularity` z `make analytics-setup` + `make popularity-refresh`,
indeks `complaints` i syntetyczni sprzedawcy z `make seller360-*`), `jq`. Wszystko z katalogu głównego repo.

Pomocnik do zapytań, używany w całym przewodniku. Hasło `elastic` jest czytane **wewnątrz** kontenera `es01`, więc
nie pojawia się ani w argumentach, ani w historii powłoki. Ciało zapytania idzie ze standardowego wejścia; zapytanie
bez ciała kończ `</dev/null`. W zsh ścieżki z `*` bierz w apostrofy.

```bash
es() { docker compose exec -T es01 sh -c 'curl -sS -u "elastic:$ELASTIC_PASSWORD" -H "Content-Type: application/json" -X "$0" "http://localhost:9200$1" --data-binary @-' "$1" "$2"; }
```

```bash
# 0. sekret do haszu IP: tylko w .env (repo jest publiczne); .env.example ma pusty SECURITY_IP_PEPPER=
grep -c '^SECURITY_IP_PEPPER=.\+' .env        # 1 = ustawiony, 0 = brak; wartości nie wypisuje
grep -q '^SECURITY_IP_PEPPER=.\+' .env || { sed -i '' '/^SECURITY_IP_PEPPER=$/d' .env; echo "SECURITY_IP_PEPPER=$(openssl rand -hex 32)" >> .env; }
# ustawionego peppera NIE zmieniaj: stare hasze przestaną się łączyć z nowymi (krok 2)

# 1. kolejka search.security.ingest + DLQ (szablon → definitions.json → żywy broker)
python3 tools/render-rabbitmq-definitions.py
docker compose exec rabbitmq rabbitmqctl import_definitions /etc/rabbitmq/definitions.json

# 2. role ES: search_service dostaje security-*, catalog_app odczyt product-popularity* i security-rules
docker compose run --rm es-setup

# 3. procesy: nowe montaże i zmienne wymagają ODTWORZENIA kontenerów, nie restartu
docker compose --profile apps up -d --no-deps catalog-app search-consumer analytics-consumer seller360-scheduler
docker compose --profile apps up -d outbox-publisher seller360-consumer   # przekaźnik outboxu i import reklamacji

# 4. ES i Kibana: ILM, pipeline, data stream, security-rules, alerts-security, reguły, konektor, data views
make detection-setup          # ~4 s, idempotentne; wypisuje p95 popularności (u nas 0.678549)

# 5. symulacja → kolejki → ES (percolator działa przy ingeście)
make detection-simulate args="--seed=1 --plant=5"
docker compose exec rabbitmq rabbitmqctl list_queues name messages | grep security   # czekaj na 0 (~10 s)

# 6. backfill reguł wsadowych za 15 dni, potem ocena
make detection-detect         # security:detect --since=15d
make detection-report         # tabela TP / FP / FN; args="--json" dla POMIARY
```

Sprawdź, że nic nie zepsułeś: `make seller360-verify` (ETAP 13, ma dalej dać `DoD: OK`) i w Kibanie reguły ETAPU 12
(`Analityka: skok zero results`, `Analityka: spadek CTR`) ze statusem `ok`.

**Uwaga o kolejności (punkt 3).** `outbox-publisher` musi działać, inaczej każda prawdziwa recenzja i zmiana ceny
z UI leży w tabeli `outbox` i nigdy nie dociera do RabbitMQ. W naszym pomiarze nie działał (POMIARY §19.4) i jedno
zaległe zdarzenie trzeba było wypchnąć ręcznie: `docker compose exec catalog-app php artisan outbox:publish`.
Symulator go nie potrzebuje, bo publikuje prosto do brokera. `seller360-consumer` jest potrzebny w kroku 5, bo
`make detection-simulate` importuje w nim reklamacje (`seller360:complaints:import`). `seller360-scheduler` trzeba
odtworzyć, bo job `security-detect` czyta pliki reguł z montaży `infra/kibana/rules` i
`infra/elasticsearch/detection/eql` dodanych w tym etapie.

---

## Krok 1 — kształt nadużycia wybiera narzędzie

| Reguła | Kształt | Narzędzie | Dlaczego to, a nie inne |
|---|---|---|---|
| `price-hug-threshold` | **pojedyncze zdarzenie**: podwyżka 25–30% na produkcie z górnych 5% popularności | **percolator** przy ingeście | Odpowiedź „tak/nie” zna się w chwili zapisu zdarzenia. Odwrócone wyszukiwanie: reguły są dokumentami, a zdarzenie zapytaniem. Alarm bez czekania na job |
| `fresh-account-5star` | **pojedyncze zdarzenie**: ocena 5 od konta < 24 h | **percolator** | jak wyżej |
| `review-ip-burst` | **seria z jednego źródła**: ≥ 5 różnych użytkowników z jednym haszem IP w godzinie | **ES\|QL w Kibana Alerting** | `STATS COUNT_DISTINCT … BY hasz, BUCKET(1 hour)` to jedno zapytanie; Kibana uruchamia je co 5 min i pisze alert konektorem. Pojedyncze zdarzenie niczego tu nie mówi |
| `price-bait-and-switch` | **sekwencja w czasie**: obniżka → kliknięcia → podwyżki, w 48 h | **EQL** | Tylko EQL ma `sequence by … with maxspan` i łączy zdarzenia z różnych źródeł po kluczu. W ES\|QL i agregacjach kolejności kroków się nie wyrazi |
| `complaints-spike` | **skok względem własnej historii**: doba ≥ średnia + 3σ z 7 poprzednich dni | **agregacje**: `date_histogram` + `moving_fn`, `significant_terms` | Próg jest inny dla każdego sprzedawcy i liczony z jego historii. `moving_fn` robi to w ES, `significant_terms` wyjaśnia skok |

Przepływ (ze specyfikacji, §5):

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

Reguły ceny widzą **tylko** `offer.price_changed`. Zmiany powyżej 30% idą przez moderację
(`offer.price_change_requested` / `_approved`): przeszły przez człowieka, więc są celowo poza regułami.

## Krok 2 — sygnały ze sklepu: recenzja i odcisk IP

**Recenzja.** Na stronie produktu zalogowany użytkownik widzi formularz „Oceń produkt” (ocena 1–5, opcjonalna
treść). `POST /products/{product}/reviews`, `throttle:5,1` (szósta recenzja w minucie dostaje 429), jedna recenzja
użytkownika na produkt. Zapis idzie domenową ścieżką `Review::createWithOutbox(..., 'review.created')`, a payload
zdarzenia dostaje cztery pola: `client_ip_hash`, `client_ip_prefix`, `client_user_agent` (obcięty do 256 znaków,
niepoprawne bajty UTF-8 zamienione przez `mb_scrub`, żeby `json_encode` outboxu nie padł) i
`user_account_age_hours`. Nowych kolumn w tabeli `reviews` nie ma: pola żyją tylko w payloadzie.

**Wyścig o „jedną recenzję na produkt”.** FormRequest sprawdza duplikat, ale dwa równoległe żądania mogą oba
przejść to sprawdzenie (klasyczny TOCTOU: *time of check, time of use*). Unikalnego indeksu nie dodaliśmy (dane
z seedera mają duplikaty), więc kontroler w transakcji najpierw blokuje wiersz użytkownika
(`lockForUpdate()`), a dopiero pod blokadą sprawdza duplikat jeszcze raz. Drugie żądanie czeka na blokadę, widzi
pierwszą recenzję i dostaje błąd walidacji, bez wiersza i bez zdarzenia w outboksie. RUNBOOK [#167](../RUNBOOK.md#167).

**Odcisk IP: dlaczego HMAC z pepperem, a nie zwykły hasz.** Zapisujemy prefiks sieci (`203.0.113.0/24`), bo przydaje
się do analizy („wszystkie recenzje z jednej sieci”). Ale skoro prefiks jest jawny, to w jednej `/24` zostaje tylko
**256 kandydatów** na pełny adres. Zwykły `sha256(ip)` dałoby się odwrócić w mikrosekundę: policzyć 256 haszy
i porównać. Nawet bez prefiksu całe IPv4 to 2³², kilka minut na laptopie. Dlatego hasz to
`HMAC-SHA256(ip, SECURITY_IP_PEPPER)`, obcięty do 16 znaków hex. Bez znajomości peppera 256 kandydatów nic nie daje,
bo nie da się policzyć żadnego z nich.

Ochrona trzyma **tylko dopóki pepper jest tajny**. Dlatego leży wyłącznie w `.env` (ignorowanym przez git),
`.env.example` ma pusty `SECURITY_IP_PEPPER=`, a repo jest publiczne. Kto pozna pepper, odwróci każdy hasz w jego
`/24` w ułamku sekundy. Zmiana peppera zrywa ciągłość: te same adresy dostaną nowe hasze, a reguła `review-ip-burst`
przestanie łączyć stare recenzje z nowymi (to może być pożądane po wycieku). Trzecia warstwa to retencja: data stream
`events-security` kasuje dane po **30 dniach** (ILM), więc nawet przy wycieku peppera stare hasze same znikają.

16 znaków hex to 64 bity: kolizja dwóch różnych adresów jest praktycznie niemożliwa przy naszej skali, a krótszy
identyfikator jest czytelniejszy w alertach.

Szczegóły `ClientFingerprint`:
- adres IPv4 mapowany w IPv6 (`::ffff:203.0.113.7`) jest najpierw sprowadzany do IPv4, więc ma ten sam hasz i prefiks
  `/24`; IPv6 dostaje prefiks `/48`,
- hasz liczony z postaci kanonicznej (`inet_ntop`), więc `2001:DB8::1` i `2001:db8:0:0::1` dają to samo,
- **pusty pepper** nie psuje sklepu: hasz **i prefiks** są `null` (oba pola IP idą w parze, decyzja w jednym
  miejscu `ClientFingerprint`), log ostrzega raz na proces, recenzja się zapisuje. `review-ip-burst` takich recenzji
  nie widzi (`client.ip_hash IS NOT NULL`).

**Sprawdź sam** (konto testowe z fabryki projektu, logowanie przez UI; adres sklepu daje `make urls`): wyślij
recenzję z oceną **4** (konto założone przed chwilą z oceną 5 odpaliłoby `fresh-account-5star` jako alert spoza
symulacji). Potem:

```bash
es GET '/events-security-*/_search?filter_path=hits.hits._source' <<'J' | jq -c '.hits.hits[]._source | .client.ip_hash |= "\(length) znaków"'
{"size": 1, "sort": [{"@timestamp": "desc"}],
 "query": {"bool": {"must_not": {"exists": {"field": "labels.synthetic"}}}},
 "_source": ["event.type", "client", "user", "review.rating", "user_agent"]}
J
```

U nas (POMIARY §19.4): `client.ip_hash` 16 znaków, `client.ip_prefix` = `172.22.0.0/24`, `user.account_age_hours` 0,
brak `labels.synthetic`. Wyszukanie pełnego adresu bramy po wszystkich polach `events-security-*` dało 0 trafień,
payload outboxu nie zawiera adresu.

**Ograniczenie Docker Desktop.** W Compose przed `catalog-app` nie ma osobnego proxy (Caddy działa w tym samym
procesie FrankenPHP), więc `$request->ip()` to adres, z którego przyszło połączenie. Na Macu **cały** ruch z hosta
przychodzi z jednego adresu bramy sieci Dockera. Wszyscy lokalni klienci mają więc ten sam hasz i prefiks: pięć
recenzji z pięciu kont w przeglądarce w ciągu godziny **odpaliłoby** `review-ip-burst`. To cecha środowiska, nie
reguły. W produkcji za ingressem trzeba ustawić zaufane proxy (`TRUSTED_PROXIES`, na k3s `10.42.0.0/16`) i czytać
`X-Forwarded-For`, inaczej wszyscy klienci mają adres ingressu.

## Krok 3 — data stream `events-security` i konsument

**Data stream** `events-security-default` (szablon `events-security`, `dynamic: strict`, ILM rollover 1 d / 1 GB,
usunięcie po 30 dniach). Pola w stylu ECS: `@timestamp`, `event.{id,type,ingested}`, `offer.{id,old_price_cents,
new_price_cents,change_pct}`, `product.{id,popularity}`, `seller.id`, `user.{id,account_age_hours}`,
`review.{id,rating}`, `client.{ip_hash,ip_prefix}`, `user_agent.original`, `labels.synthetic`. `strict` oznacza, że
literówka w nazwie pola kończy się błędem 400, a nie cichym nowym polem.

```bash
es GET '/_data_stream/events-security-default?filter_path=data_streams.template,data_streams.ilm_policy,data_streams.indices.index_name' </dev/null
es GET '/_ilm/policy/events-security?filter_path=*.policy.phases.delete.min_age' </dev/null   # "30d"
```

**Pipeline** `events-security`: `event.ingested` z czasu ingestu i `product.popularity` z istniejącej polityki
enrich `product-popularity-policy` (ETAP 12). Błąd wzbogacenia (produkt spoza `product-popularity`) nie odrzuca
zdarzenia: dokument zapisuje się bez popularności, a `price-hug-threshold` po prostu go nie dopasuje. W naszym
pomiarze 45 z 1082 zdarzeń miało `product.popularity` (tylko zmiany cen na ofertach produktów znanych enrichowi;
recenzje symulatora nie mają `product.popularity` z tego samego powodu).

Enrich czyta **migawkę** (`.enrich-product-popularity-policy-*`), nie indeks. `make popularity-refresh` wykonuje
politykę na końcu, ale po ręcznej zmianie `product-popularity` migawka zostaje stara. Przed pomiarem porównaliśmy
oba: 7457 = 7457 dokumentów, ten sam p95 (POMIARY §19.1).

**Konsument.** `SecurityEventHandler` to handler partii jak `AnalyticsEventHandler` (500 zdarzeń albo przerwa),
transport `security`, kolejka `search.security.ingest` z bindingami `offer.price_changed` i `review.created` oraz DLQ
`search.security.ingest.dlq` (quorum, `delivery-limit` 5). Kolejka ma `x-dead-letter-routing-key:
search.security.ingest`, a DLQ jest związana z `marketplace.dlx` tylko tym kluczem: bez tego martwe
`offer.price_changed` / `review.created` trafiałyby też do `search.product.sync.dlq` i odwrotnie (RUNBOOK #140, #168).
Proces to istniejący `analytics-consumer`:

```
messenger:consume analytics security
```

Zapis: `_bulk` z `op_type: create` i `_id` = `event.id`, więc 409 = duplikat = potwierdzenie (jak w ETAPIE 12).
`change_pct = round((new − old) / old × 100, 2)` liczy mapper, ze znakiem. Przy `old_price_cents = 0` pola nie ma:
zdarzenie się zapisuje, żadna reguła ceny nie strzela, nie ma dzielenia przez zero.

Kolejki po symulacji (POMIARY §19.1): `search.security.ingest` 1082 → 82 → **0** w ~10 s (z percolacją),
`search.analytics.ingest` 292 → 0 w ~5 s, DLQ 0. `rabbitmqctl list_consumers` pokaże 0 konsumentów: Messenger
z AMQP pobiera wiadomości przez `basic.get`, nie subskrypcję, więc o życiu konsumenta świadczy opróżnianie kolejki.

## Krok 4 — percolator: reguła jako dokument

Zwykłe wyszukiwanie: zapisane dokumenty, zapytanie przychodzi. **Percolator** odwraca role: zapytania są zapisane
w indeksie (`security-rules`, pole `query` typu `percolator`), a przychodzi dokument z pytaniem „które zapytania do
mnie pasują?”. Dla reguł na pojedynczym zdarzeniu to idealne: dodanie reguły to zapis dokumentu, a sprawdzenie
zdarzenia to jedno `percolate`.

Plik reguły (`infra/elasticsearch/detection/rules/price-hug-threshold.json`, skrót):

```json
{
  "rule":   {"id": "price-hug-threshold", "kind": "percolator", "severity": "medium",
             "description": "Podwyżka ceny o 25-30% (tuż pod progiem 30%) na produkcie z górnego 5% popularności"},
  "params": {"p95_from": "product-popularity.popularity"},
  "query":  {"bool": {"filter": [
              {"term":  {"event.type": "offer.price_changed"}},
              {"range": {"offer.change_pct": {"gte": 25, "lte": 30}}},
              {"range": {"product.popularity": {"gte": "{{p95}}"}}}]}},
  "alert":  {"entity_type": "offer", "entity_field": "offer.id", "window": "event",
             "evidence_fields": ["event.id", "offer.change_pct", "product.popularity", "seller.id"]}
}
```

`security:rules:load` (sekcja [2] `make detection-setup`) liczy `percentiles` 95 z `product-popularity.popularity`,
podmienia znacznik `"{{p95}}"` razem z cudzysłowami na liczbę i zapisuje dokument pod `_id` = `rule.id` (ponowne
ładowanie nadpisuje regułę nowym progiem). Blok `alert` ląduje w `params` (typ `flattened`), bo mapowanie
`security-rules` jest ścisłe. Zobacz, co faktycznie leży w indeksie:

```bash
docker compose exec search-consumer php bin/console security:rules:load --dry-run   # p95 = 0.678549, nic nie zapisuje
es GET '/security-rules/_search?filter_path=hits.hits._id,hits.hits._source.query,hits.hits._source.params.p95' </dev/null | jq -c '.hits.hits[]'
```

Druga reguła, `fresh-account-5star`: `event.type = review.created`, `review.rating = 5`,
`user.account_age_hours < 24`, encja `user`.

**Ważne: `security-rules` ma w mapowaniu kopię pól zdarzeń** (`offer.change_pct` jako `scaled_float`,
`review.rating` jako `byte` itd.). Percolator musi wiedzieć, jak zinterpretować pola zapytania; bez tych pól
w mapowaniu indeks nie przyjmie reguły z `range` na nieznanym polu.

**Kiedy percolacja się dzieje.** Po zapisie partii handler bierze identyfikatory zapisanych dokumentów (także
duplikatów 409, żeby powtórka po błędzie odtworzyła alerty) i woła `SecurityPercolator`. Ten pobiera dokumenty
z ES (już po pipeline, więc z `product.popularity`) i puszcza `_msearch` z `percolate` **po referencji**
`index` + `id`. Wiadomości są potwierdzane (ack) **dopiero po udanej percolacji**: percolator to jedyny detektor
reguł `kind: percolator` (`security:detect` ich nie przelicza), więc gdy percolacja padnie (`_msearch` z błędem, element
`_mget` z `error`), zapisane wiadomości dostają nack Recoverable i wracają do kolejki. Powtórka dostaje 409, ref nadal
liczy się jako zapisany i percolacja idzie jeszcze raz; deterministyczne id alertu nie pozwala na duplikaty.
Tu był pierwszy zgrzyt: referencja do **nazwy data streamu** nie działa.

```bash
# ten sam dokument: po nazwie streamu → index_not_found_exception ("excluded_ds": "true")
es GET '/security-rules/_search' <<'J' | jq -c '.error.root_cause[0].type'
{"query": {"percolate": {"field": "query", "index": "events-security-default", "id": "01M86T3QTG4V5MZZ0NYSYPP1PA"}}}
J
# po indeksie zapasowym → trafia fresh-account-5star
es GET '/security-rules/_search?filter_path=hits.hits._id' <<'J'
{"query": {"percolate": {"field": "query", "index": ".ds-events-security-default-2026.11.25-000001", "id": "01M86T3QTG4V5MZZ0NYSYPP1PA"}}}
J
```

(Identyfikator i nazwa indeksu zapasowego są z naszego przebiegu; u Ciebie znajdź je zapytaniem
`{"size":1,"query":{"range":{"user.account_age_hours":{"lt":24}}},"_source":false}` na `events-security-default`.)
Percolate po referencji to w środku zwykły GET dokumentu, a GET nie działa na data streamie, bo nie wiadomo, w którym
indeksie zapasowym leży dokument. Dlatego `SecurityPercolator` rozwiązuje stream na indeksy zapasowe
(`GET _data_stream`, od najnowszego) i szuka dokumentów `_mget`-em. RUNBOOK [#160](../RUNBOOK.md#160).

Alert z percolatora ma okno punktowe (`window.start = window.end` = czas zdarzenia). Wynik: przed jakimkolwiek
backfillem w `alerts-security` było już **10 alertów** (5 `price-hug-threshold`, 5 `fresh-account-5star`),
powstałych w trakcie opróżniania kolejki (POMIARY §19.1). Przykładowy dowód z alertu:
`offer_change_pct 28.69`, `product_popularity 0.868`, `p95 0.6785`.

**Ograniczenie percolatora:** sprawdza zdarzenie **raz, przy ingeście**. Nowa reguła nie przejrzy historii, a reguła
z nowym progiem p95 nie przeliczy starych zdarzeń. Do historii służy zwykłe wyszukiwanie tym samym zapytaniem
(krok 5).

## Krok 5 — nowa reguła percolatora bez deployu

Reguła to dokument, więc nową dodaje się jednym `PUT`, bez builda, bez restartu konsumenta i bez zmiany kodu. Pokaz:
lustrzane odbicie `fresh-account-5star`, czyli **1 gwiazdka od świeżego konta** (konkurent niszczy ocenę produktu).
Dokument musi mieć te same części co reguły z plików: `rule` (id, kind, severity, description), `params` z opisem
encji i dowodów oraz `query`.

```bash
es PUT '/security-rules/_doc/fresh-account-1star?refresh=true' <<'J'
{
  "rule": {"id": "fresh-account-1star", "kind": "percolator", "severity": "low",
           "description": "Recenzja 1 gwiazdki od konta młodszego niż 24 godziny"},
  "params": {"entity_type": "user", "entity_field": "user.id", "window": "event",
             "evidence_fields": ["event.id", "review.id", "product.id", "user.account_age_hours"]},
  "query": {"bool": {"filter": [
    {"term": {"event.type": "review.created"}},
    {"term": {"review.rating": 1}},
    {"range": {"user.account_age_hours": {"lt": 24}}}
  ]}}
}
J
```

Sprawdzenie bez czekania na zdarzenie: `percolate` z dokumentami **w treści zapytania**. Dwa kandydaty naraz;
`_percolator_document_slot` mówi, który dokument pasuje:

```bash
es GET '/security-rules/_search' <<'J' | jq -c '.hits.hits[] | {id: ._id, slot: .fields._percolator_document_slot}'
{"_source": false, "query": {"percolate": {"field": "query", "documents": [
  {"event": {"type": "review.created"}, "review": {"rating": 1}, "user": {"id": "42", "account_age_hours": 3}},
  {"event": {"type": "review.created"}, "review": {"rating": 1}, "user": {"id": "43", "account_age_hours": 30}}
]}}}
J
# → {"id":"fresh-account-1star","slot":[0]}   (konto 3 h pasuje, 30 h nie)
```

(Sprawdzone 2026-11-25 na tymczasowej kopii indeksu z mapowaniem `index-security-rules.json`, żeby nie zmieniać
zbioru, na którym liczony jest pomiar.) Od tej chwili każda nowa recenzja 1 gwiazdki od świeżego konta da alert
w `alerts-security`: konsument czyta reguły przy każdym `percolate`, nie trzyma ich w pamięci.

Historia: percolator jej nie przejrzy, ale to samo `query` puszczone na zdarzenia odpowiada „ile by złapała”:

```bash
es GET '/events-security-*/_count' <<'J'
{"query": {"bool": {"filter": [{"term": {"event.type": "review.created"}}, {"term": {"review.rating": 1}},
  {"range": {"user.account_age_hours": {"lt": 24}}}]}}}
J
# u nas 0: symulator nie sadzi takich recenzji
```

Trzy rzeczy, o których trzeba pamiętać:
- reguła dodana ręcznie **nie ma pliku** w `detection/rules/`. `make detection-setup` jej nie skasuje (loader tylko
  nadpisuje reguły z plików), ale nowy klaster albo odtworzony indeks jej nie odtworzy. Reguła, która ma zostać,
  ląduje w pliku,
- usunięcie: `es DELETE '/security-rules/_doc/fresh-account-1star' </dev/null`,
- zła reguła (np. `range` na polu spoza mapowania `security-rules`) zostanie odrzucona przy `PUT`, a nie przy
  percolacji: to zaleta ścisłego mapowania.

## Krok 6 — `review-ip-burst`: ES|QL w Kibanie i ten sam tekst w backfillu

Reguła Kibany (`infra/kibana/rules/security-review-ip-burst.json`, typ `.es-query` w trybie ES|QL, co 5 min, okno
1 h):

```
FROM events-security-*
| WHERE event.type == "review.created" AND client.ip_hash IS NOT NULL
| STATS users = COUNT_DISTINCT(user.id), reviews = COUNT(*) BY client.ip_hash, hour = BUCKET(@timestamp, 1 hour)
| WHERE users >= 5
```

Akcja to konektor index (`connector-security-index.json`) zapisujący dokument do `alerts-security`. Konektor index
był już w ETAPIE 12, działa na licencji basic.

**Kibana patrzy tylko na bieżące okno.** Zasadzone przez symulator godziny leżą w przeszłości, więc reguła Kibany nie
wpisała nic (0 dokumentów, POMIARY §19.2). Do historii służy backfill: `ReviewIpBurstDetector` **czyta tekst
zapytania z tego samego pliku**, wstawia filtr czasu zaraz po `FROM` (początek zaokrąglony w dół do pełnej godziny,
żeby kubełek na brzegu nie był policzony od połowy) i puszcza całość przez `_query`. Live i historia liczą dokładnie
to samo. Plik jest zamontowany tylko do odczytu w `search-consumer` i `seller360-scheduler`.

Zobacz bliskie pudła, obniżając próg w tym samym zapytaniu (tylko odczyt):

```bash
es POST '/_query?format=txt' <<'J'
{"query": "FROM events-security-* | WHERE event.type == \"review.created\" AND client.ip_hash IS NOT NULL | STATS users = COUNT_DISTINCT(user.id), reviews = COUNT(*) BY client.ip_hash, hour = BUCKET(@timestamp, 1 hour) | WHERE users >= 4 | SORT users DESC, hour"}
J
```

Sprawdzone na żywo 2026-11-25: 5 wierszy z `users` 5–8 (zasadzeni) i 5 wierszy z `users` = 4 (bliskie pudła).
Reguła z progiem 5 bierze dokładnie pierwszą piątkę. Każdy hasz tła ma jednego użytkownika, więc tło nie wchodzi
nawet do listy z progiem 4.

**Dokumenty konektora Kibany są gorsze od dokumentów PHP** i raport ich nie ocenia. Szablon akcji wypełnia pola
wąsami `{{#context.hits}}…{{/context.hits}}`: przy kilku wierszach wyniku wartości się **sklejają** w jeden napis
(`entity.id` z dwóch haszy), `_id` jest losowy, `window.end` to czas przebiegu, a `labels.synthetic` zawsze `false`.
`security:report` ocenia tylko dokumenty, których `_id` zgadza się ze wzorem klucza alertu (krok 9); resztę liczy
osobno jako „Kibana live (unscored)”. W praktyce job `security-detect` co 5 min i tak zapisuje poprawny dokument dla
tego samego kubełka. RUNBOOK [#163](../RUNBOOK.md#163).

```bash
docker compose exec -T es01 sh -c 'curl -sS -u "elastic:$ELASTIC_PASSWORD" "http://kibana:5601/api/alerting/rules/_find?search=review-ip-burst&search_fields=tags"' \
  | jq -c '.data[] | {name, enabled, schedule, status: .execution_status.status, last: .last_run.outcome}'
# {"name":"Detekcja: review-ip-burst","enabled":true,"schedule":{"interval":"5m"},"status":"ok","last":"succeeded"}
```

## Krok 7 — `price-bait-and-switch`: sekwencja w EQL

Wzorzec „wabika”: sprzedawca obniża cenę o ≥ 15%, oferta zbiera kliknięcia, po czym cena rośnie dwiema podwyżkami
(każda pod progiem strażnika) do ≥ 1,3 × ceny sprzed obniżki. Wszystko w 48 h. Plik
`infra/elasticsearch/detection/eql/price-bait-and-switch.eql`:

```
sequence by offer.id with maxspan=48h
  ["offer.price_changed" where offer.change_pct <= -15]
  ["user.clicked_result" where true] with runs=20
  ["offer.price_changed" where offer.change_pct > 0]
  ["offer.price_changed" where offer.change_pct > 0]
```

Elementy po kolei:
- `sequence by offer.id`: kroki muszą mieć ten sam `offer.id` (klucz złączenia, w wyniku `join_keys`),
- `with maxspan=48h`: od pierwszego do ostatniego zdarzenia najwyżej 48 h,
- `with runs=20`: krok powtórzony 20 razy, czyli 20 kliknięć tej oferty między obniżką a podwyżkami,
- kategorie w **cudzysłowie**: nazwy typów zawierają kropkę, a EQL bez cudzysłowu czyta `offer.price_changed` jako
  `offer` i kropkę (`mismatched input '.' expecting 'where'`, RUNBOOK [#161](../RUNBOOK.md#161)),
- żądanie idzie na **dwa źródła**: `events-security-*` (zmiany cen) i `events-user-*` (kliknięcia z ETAPU 12),
  z `event_category_field: event.type` i `timestamp_field: @timestamp`.

To złączenie dwóch źródeł ujawniło brakujące pole: kliknięcia w `events-user` **nie miały `offer.id`**, bo mapper
analityki go nie przepisywał, choć szablon miał to pole. EQL nigdy nie złączyłby kliknięć ze zmianami cen.
Poprawka w `AnalyticsDocumentMapper` (`offer_id` → `offer.id`) i restart `analytics-consumer`.
RUNBOOK [#162](../RUNBOOK.md#162).

**Post-filtr w PHP.** EQL nie liczy stosunków między zdarzeniami sekwencji („cena końcowa / cena sprzed obniżki”).
`PriceBaitAndSwitchDetector::accept()` sprawdza po EQL: co najmniej dwie zmiany ceny, każda `|change_pct| ≤ 30`
(większe łapie strażnik i moderacja) i `cena końcowa × 10 ≥ cena sprzed obniżki × 13` na całkowitych groszach (bez
błędu zmiennoprzecinkowego).

Zobacz oba etapy na żywych danych (tylko odczyt):

```bash
es GET '/events-security-*,events-user-*/_eql/search' <<'J' | jq -c '.hits.sequences[] | [.events[]._source | select(.event.type=="offer.price_changed")] as $c | {offer: $c[0].offer.id, pct: [$c[].offer.change_pct], ratio: (($c[-1].offer.new_price_cents / $c[0].offer.old_price_cents)*100|round/100)}'
{"query": "sequence by offer.id with maxspan=48h [\"offer.price_changed\" where offer.change_pct <= -15] [\"user.clicked_result\" where true] with runs=20 [\"offer.price_changed\" where offer.change_pct > 0] [\"offer.price_changed\" where offer.change_pct > 0]",
 "timestamp_field": "@timestamp", "event_category_field": "event.type", "size": 100}
J
```

Sprawdzone 2026-11-25: EQL zwraca **8 sekwencji**, z czego 3 mają stosunek **1,28** (bliskie pudła), a 5 ma
1,31–1,36. Post-filtr odrzuca te trzy, zostaje 5 alertów. Dwa pozostałe bliskie pudła (1,31–1,38 ×, ale podwyżki po
52 h i 60 h) EQL odrzuca sam przez `maxspan`. Każda reguła ma więc dwie zapory: kształt w EQL i liczby w PHP.

Szczegóły, które łatwo przeoczyć:
- dowód alertu pokazuje `clicks: 20`, choć symulator dał 25–32 kliknięć: `runs=20` dopasowuje **dokładnie** 20
  zdarzeń, reszta nie wchodzi do sekwencji,
- EQL nie ma stronicowania, tylko `size`. Detektor używa `size = 2000` i rzuca wyjątek, gdy liczba sekwencji ją
  osiąga, zamiast po cichu uciąć wynik,
- zakres: zapytanie sięga 48 h przed początek okna (sekwencja mogła zacząć się wczoraj), a PHP zostawia tylko
  sekwencje, których **ostatnia** podwyżka wypada w `[from, to)`. Klucz alertu to `offer.id` + czas obniżki, więc
  job z oknem 2 h i backfill 15 dni nie dublują się.

## Krok 8 — `complaints-spike`: `moving_fn` i `significant_terms`

Reguła: liczba reklamacji sprzedawcy w dobie UTC ≥ średnia + 3σ z **7 poprzednich** dni i co najmniej 5 reklamacji.
Próg jest inny dla każdego sprzedawcy. Liczy go sam Elasticsearch:

```json
"terms": {"field": "seller_id"},
"aggs": {"days": {
  "date_histogram": {"field": "created_at", "calendar_interval": "1d", "time_zone": "UTC", "min_doc_count": 0,
                     "extended_bounds": {"min": "<pierwsza doba − 7 d>", "max": "<ostatnia doba>"}},
  "aggs": {
    "mean": {"moving_fn": {"buckets_path": "_count", "window": 7, "shift": 0,
                           "script": "MovingFunctions.unweightedAvg(values)"}},
    "std":  {"moving_fn": {"buckets_path": "_count", "window": 7, "shift": 0,
                           "script": "MovingFunctions.stdDev(values, MovingFunctions.unweightedAvg(values))"}}}}}
```

Trzy pułapki, każda sprawdzona eksperymentem:

1. **Dzień nie może liczyć sam siebie.** Z `shift: 0` okno 7 kubełków kończy się **przed** bieżącym kubełkiem
   (dzienne 1, 2, 3, 10 dają średnie null, 1,0, 1,5, 2,0). Gdyby skok wchodził do własnej średniej, podnosiłby swój
   próg.
2. **Werdykt nie może zależeć od zakresu zapytania.** Bez `extended_bounds` histogram zaczyna się od pierwszej
   reklamacji w zakresie, więc ten sam dzień miał inną średnią w jobie z oknem 2 h i w backfillu 15 dni. Z
   `extended_bounds` od 7 dni przed pierwszą ocenianą dobą i `min_doc_count: 0` każda doba ma pełne okno 7 dni,
   uzupełnione zerami.
3. **Brak historii to nie skok.** Zera z `extended_bounds` sprawiłyby, że nowy sprzedawca z 6 reklamacjami pierwszego
   dnia ma średnią 0 i alert. Dlatego osobne zapytanie (`min(created_at)` bez filtra czasu) daje dobę pierwszej
   reklamacji w ogóle, a dobę D oceniamy tylko, gdy ta pierwsza reklamacja jest co najmniej 7 dni przed D.

RUNBOOK [#164](../RUNBOOK.md#164). Lista sprzedawców z reklamacją w zakresie idzie agregacją `composite`
(stronicowanie `after_key`), histogramy w paczkach po 500 sprzedawców.

**`significant_terms` jako wyjaśnienie, nie warunek.** Dla sprzedawcy, który przekroczył próg, detektor dokłada dwa
dowody:
- `top_categories`: `significant_terms` po `category` (sprzedawca w dobie skoku kontra cały indeks), czyli które
  kategorie są u niego **nietypowo** częste. Przykład z alertu: `kontakt (2), dostawa (3), produkt (3)`,
- `seller_significant`: czy sprzedawca jest w `significant_terms` po `seller_id` (doba skoku kontra tło 30 dni,
  `include: [id]`). Drugi, niezależny sygnał, że to on się wyróżnia.

`significant_terms` mierzy odchylenie od tła (heurystyka JLH), nie liczność: kategoria częsta wszędzie nie wyjdzie,
nawet jeśli jest jej najwięcej. Na małej próbce lista bywa pusta.

Przykład alertu z naszego przebiegu: sprzedawca 5004517, 8 reklamacji w dobie 2026-11-20, historia `1,1,0,3,3,0,3`,
średnia 1,57, σ 1,29, próg 5,45. Alert ma okno całej doby `[D, D+1)`, encję `seller`. Job w schedulerze ocenia
bieżącą dobę „do teraz”, więc kolejne przebiegi tej doby nadpisują ten sam dokument.

## Krok 9 — `alerts-security` i klucz alertu

Jeden indeks dla wszystkich reguł. Pola: `@timestamp`, `rule.id`, `rule.kind` (`percolator` | `esql` | `eql` |
`aggregation`), `severity`, `entity.type`, `entity.id`, `window.start`, `window.end`, `message`, `evidence`
(`flattened`, więc każda reguła ma własne dowody bez rozdmuchiwania mapowania), `labels.synthetic`.

**Klucz alertu** = `rule.id` + `entity.type` + `entity.id` + `window.start`, a `_id` zapisu z Symfony to
`sha1("{rule.id}|{entity.type}|{entity.id}|{window.start}")`. Konsekwencje:
- ponowne wykrycie **nadpisuje** ten sam dokument: drugi `make detection-detect` → `_count` 25 → **25**, także po
  12 przebiegach pomiaru czasu i przebiegu w schedulerze (POMIARY §19.4),
- job co 5 min z oknem 2 h i backfill `--since=15d` mogą się nakładać bez dublowania,
- raport liczy unikalne klucze, a dokumenty bez takiego `_id` (konektor Kibany) rozpoznaje i odkłada.

```bash
es GET '/alerts-security/_search' <<'J' | jq -c '.aggregations.r.buckets[] | {rule: .key, n: .doc_count, kind: .k.buckets[0].key}'
{"size": 0, "aggs": {"r": {"terms": {"field": "rule.id"}, "aggs": {"k": {"terms": {"field": "rule.kind"}}}}}}
J
```

W Kibanie: data view **Alerty bezpieczeństwa** (`alerts-security`) i **Zdarzenia bezpieczeństwa**
(`events-security-*`), tworzone przez `make detection-setup`. W Discover filtr `rule.id : "price-bait-and-switch"`
i kolumny `entity.id`, `message`, `evidence.ratio`.

**Job cykliczny.** `SecurityDetectJob` (`security-detect`, co 300 s w `seller360-scheduler`) uruchamia trzy detektory
wsadowe za ostatnie 2 h. Każdy detektor biegnie we własnym `try/catch`: awaria jednego (np. ES|QL) jest logowana
z jego id (`Detektor <id> padł`) i nie odbiera przebiegu pozostałym; na końcu job rzuca, żeby scheduler zobaczył
błąd. Reguły percolatora nie mają detektora wsadowego: `security:detect --rule=price-hug-threshold` kończy się
`Brak detektora dla reguły price-hug-threshold.` (kod 1).

## Krok 10 — symulator i prawda podstawowa

`security:simulate --seed=1 --days=14 --plant=5` (catalog, `make detection-simulate`) jest deterministyczny
(`Mt19937($seed)`, stała kolejność) i publikuje koperty z datą wsteczną przez `SimulatedEventPublisher`, tą samą
drogą od RabbitMQ dalej co prawdziwe zdarzenia. Reklamacje pisze do CSV z prefiksem `SEC-` w `ticket_id`, a Makefile
importuje je istniejącym `seller360:complaints:import`.

Na każdą regułę 5 zasadzonych i 5 bliskich pudeł, zbudowanych **z zapasem od progu**:

| Reguła | Zasadzeni | Bliskie pudła |
|---|---|---|
| `price-hug-threshold` | podwyżka 25,5–29,5% na ofercie produktu z popularnością ≥ 1,05 × p95 | 24% na ofercie ≥ 1,05 × p95 albo 26–28% na ofercie < 0,5 × p95 |
| `fresh-account-5star` | ocena 5, konto 2–20 h | ocena 5, konto 30 h |
| `review-ip-burst` | 5–8 różnych użytkowników z jednego adresu z `192.0.2.0/24` w godzinie | 4 użytkowników |
| `price-bait-and-switch` | obniżka 15,5–17%, 25–32 kliknięcia, dwie podwyżki do 1,31–1,38 × w ≤ 40 h | cena końcowa 1,28 × albo podwyżki po 52 h i 60 h |
| `complaints-spike` | historia 0–3 dziennie, skok max(8, ⌈μ + 4σ⌉) | skok ≈ μ + 2σ, co najmniej 1 reklamację pod progiem 3σ |

Tło: 40 recenzji dziennie (każda od innego użytkownika, z innego adresu z puli TEST-NET `198.51.100.0/24`,
`203.0.113.0/24` i dokumentacyjnego IPv6, konto 48–20 000 h), 30 zmian cen dziennie ±1–15% na ofertach spoza
scenariuszy, szum reklamacji 0–3 dziennie. Reklamacje trafiają do syntetycznych sprzedawców z ETAPU 13, z pominięciem
zasadzonych i bliskich pudeł z `planted.json` ETAPU 13, więc `seller360-verify` nie widzi zmian w swoich przypadkach.
Uszkodzony `planted.json` ETAPU 13 kończy symulację błędem **przed** publikacją czegokolwiek.

Wynik przebiegu (POMIARY §19.1): 1374 koperty (460 `offer.price_changed`, 622 `review.created`, 292
`user.clicked_result`), 729 reklamacji `SEC-`, 50 przypadków, 2,5 s całość. Prawda podstawowa leży w
`apps/catalog/storage/app/private/security/planted-security.json` (ignorowany przez git):
`{seed, generated_at, days, p95, cases[{rule, type: planted|near_miss, entity_type, entity_id, window_start,
window_end}]}`.

Znaczniki syntetyczności: `labels.synthetic` w zdarzeniach (z `data.synthetic` koperty), `secsim-` w `session.id`
kliknięć, `SEC-` w reklamacjach. Na nich opiera się `security:purge-synthetic`.

## Krok 11 — pomiar: `security:report`

`make detection-report` kopiuje plik prawdy do `search-consumer` i woła `security:report --truth=…`. Dopasowanie:
ta sama reguła, typ i id encji, okna się przecinają (okna półotwarte; okno punktowe percolatora pasuje z brzegami).
Kilka alertów jednego zasadzonego = 1 TP; alert bez przypadku = FP; trafione bliskie pudło = FP i
„FP z bliskich pudeł”. Wynik (ziarno 1, P = 5, 14 dni; POMIARY §19.2, powtórzony 2026-11-25 przy pisaniu przewodnika):

```
| reguła                | TP | FP | FP z bliskich pudeł | FN | precision | recall |
| price-hug-threshold   | 5  | 0  | 0                   | 0  | 1.00      | 1.00   |
| fresh-account-5star   | 5  | 0  | 0                   | 0  | 1.00      | 1.00   |
| review-ip-burst       | 5  | 0  | 0                   | 0  | 1.00      | 1.00   |
| price-bait-and-switch | 5  | 0  | 0                   | 0  | 1.00      | 1.00   |
| complaints-spike      | 5  | 0  | 0                   | 0  | 1.00      | 1.00   |
Alertów: 25, ocenionych: 25, Kibana live (unscored): 0, prawdziwe spoza symulacji (unscored): 0,
stare syntetyczne spoza zakresu symulacji (unscored): 0
```

**Co ten wynik mierzy, a czego nie.** 5/5 i 0 FP to wynik **z konstrukcji**: generator projektuje przypadki
z zapasem od progów, a jego test sprawdza, że zbiór alarmów policzony od nowa jest dokładnie równy zasadzonym. Liczby
mierzą więc **poprawność całej ścieżki** (outbox → RabbitMQ → pipeline i enrich → ES → detektor → `alerts-security`
→ raport): że każda reguła robi to, co ma w definicji, i że nic po drodze nie gubi pól. **Nie** mierzą jakości reguł
na prawdziwym ruchu. Prawdziwi oszuści nie trzymają się zapasu od progu, a prawdziwi uczciwi sprzedawcy też
podnoszą ceny o 26% na popularnych produktach. Tego tu nie zmierzyliśmy (sekcja „Ograniczenia”).

Raport rozdziela też alerty, których nie da się ocenić: dokumenty Kibany, alerty niesyntetyczne spoza prawdy
(„prawdziwe spoza symulacji”) i alerty syntetyczne spoza zakresu `[generated_at − days, generated_at]` (po ponownej
symulacji bez purge, „stare syntetyczne”). Bez tego rozdziału stare przebiegi liczyłyby się jako FP.

Czas `security:detect --since=15d` w kontenerze (POMIARY §19.3, 3 powtórzenia, z rozruchem Symfony ~150 ms):
`complaints-spike` 620–779 ms, `price-bait-and-switch` 288–308 ms, `review-ip-burst` 157–179 ms, wszystkie trzy
764–820 ms.

## Czego reguły nie złapią

Bliskie pudło to przypadek, który **wygląda** jak nadużycie, ale reguła go celowo przepuszcza. Każda reguła ma
granicę, a oszust, który ją zna, staje tuż za nią.

| Reguła | Nie złapie | Liczby z naszych danych |
|---|---|---|
| `price-hug-threshold` | podwyżki 24% (albo dwóch po 15% w różne dni); podwyżki 28% na produkcie spoza górnych 5% popularności; produktu bez popularności w enrichu; produktu, który wszedł do górnych 5% po ostatnim `make detection-setup` (próg p95 jest zamrożony w regule) | trzy podwyżki 24,0% na ofertach z popularnością 0,72–1,05 bez alertu; podwyżki 26–28% na ofertach z popularnością ~0,2 bez alertu |
| `fresh-account-5star` | konta 25-godzinnego; konta założonego tydzień wcześniej „na zapas”; oceny 4 | 5 recenzji od kont 30 h bez alertu (zasadzone: 2, 6, 10, 11, 19 h) |
| `review-ip-burst` | 4 kont z jednego adresu; 5 kont rozłożonych na dwie godziny (granica kubełka `BUCKET(1 hour)`, np. 3 o 10:50 i 2 o 11:10); 5 kont z 5 adresów jednej sieci (reguła patrzy na hasz pełnego adresu, nie na prefiks); recenzji bez haszu i prefiksu (pusty pepper) | 5 haszy z 4 użytkownikami w godzinie bez alertu |
| `price-bait-and-switch` | podwyżki do 1,28 ×; tej samej sekwencji rozciągniętej na 52–60 h; wabika z 19 kliknięciami; jednej podwyżki o 31% (to już moderacja); kliknięć bez `offer.id` | 8 sekwencji z EQL → 5 alertów (3 odrzucone przy 1,28 ×); 2 sekwencje dłuższe niż 48 h EQL nie zwrócił |
| `complaints-spike` | skoku o 2σ; skoku do 4 reklamacji przy zerowej historii (minimum 5); sprzedawcy bez 7 dni historii; powolnego wzrostu (średnia rośnie razem z nim); skoku rozłożonego na dwie doby UTC | np. sprzedawca 5007368: historia `3,1,0,3,0,1,4`, 5 reklamacji, próg 6,17; sprzedawca 5007521: 6 reklamacji przy progu 7,61 — bez alertu |

(Liczby dla `review-ip-burst`, `price-bait-and-switch`, `fresh-account-5star` i `complaints-spike` w tej tabeli
policzone zapytaniami tylko do odczytu 2026-11-25 na danych symulacji z POMIARY §19.)

Lekcja: reguła progowa jest tak dobra, jak granica, której oszust nie zna. Dlatego warto mieć reguły różnych kształtów
na ten sam cel (wabik o 31% złapie moderacja, wabik do 1,28 × mógłby złapać `price-hug-threshold`, gdyby produkt był
popularny) i warto mierzyć bliskie pudła, a nie tylko trafienia.

## Liczby

| Co | Wartość |
|---|---|
| Reguły | 5: 2 percolator, 1 ES\|QL (Kibana + backfill), 1 EQL, 1 agregacje |
| p95 popularności (próg `price-hug-threshold`) | 0,678549 (7457 produktów w `product-popularity`) |
| Symulacja (ziarno 1, P = 5, 14 dni) | 1374 koperty, 729 reklamacji `SEC-`, 50 przypadków, 2,5 s |
| Ingest | 1082 zdarzenia w `events-security-default` w ~10 s (z percolacją); DLQ 0 |
| Alerty z percolatora przy ingeście | 10 |
| Alerty po backfillu | 25 (po 5 na regułę) |
| Skuteczność | każda reguła TP 5, FP 0, FN 0 (precision i recall 1,00) |
| Idempotencja | drugi `make detection-detect`: 25 → 25 |
| `security:detect --since=15d` | wszystkie trzy detektory 764–820 ms (z rozruchem ~150 ms) |
| Regresje | `make seller360-verify` `DoD: OK`; reguły ETAPU 12 `ok` |
| Prywatność | hasz 16 hex, prefiks `/24`; pełny adres w ES: 0 trafień; retencja 30 d |

Więcej: [`POMIARY.md` §19](../POMIARY.md).

## Ograniczenia

- **5/5 mierzy implementację, nie skuteczność na realnym ruchu.** Przypadki są zaprojektowane z zapasem od progów;
  prawdziwy ruch da fałszywe alarmy (uczciwe podwyżki 26%, rodzina z jednego adresu) i pudła (oszust tuż za
  granicą). Tego nie mierzyliśmy, bo nie mamy prawdziwego ruchu z etykietami.
- **Docker Desktop: jeden adres dla wszystkich lokalnych klientów** (krok 2). Recenzje z UI zawsze mają ten sam hasz;
  przy pięciu kontach w godzinie `review-ip-burst` strzeli.
- **Reguła Kibany oceniana tylko jakościowo.** Jej dokumenty mają losowy `_id`, sklejone wartości przy kilku wierszach
  i `synthetic: false`; raport ich nie liczy. Mierzony jest backfill PHP z tym samym ES|QL.
- **Percolator nie przegląda historii**, a p95 jest zamrożony w regule do następnego `make detection-setup`.
- **Pusty pepper** wyłącza oba pola IP (hasz i prefiks): takie recenzje są dla `review-ip-burst` niewidoczne.
- **Kliknięcia symulatora trafiają do `events-user`** i wchodzą do transformów ETAPU 12 (popularność, CTR); purge je
  usuwa, ale wyników transformów nie przelicza (sekcja „Powrót do normalnej pracy”).
- **Okno percolatora ma rozdzielczość sekundy**: dwie zmiany ceny tej samej oferty w tej samej sekundzie dają jeden
  alert.
- **Reguły usunięte z plików zostają w `security-rules`**: loader nie sprząta.
- **k3s nie dostał nowej kolejki ani polityki** (etap tylko na Compose); `tools/k3d/topology-parity.rb` będzie je
  zgłaszał do czasu dopisania manifestów.

## Co wybuchło po drodze

| RUNBOOK | Objaw |
|---|---|
| [#158](../RUNBOOK.md#158) | Zdarzenia bezpieczeństwa nie docierają do ES: kolejka rośnie, DLQ rośnie albo recenzja z UI leży w outboksie |
| [#159](../RUNBOOK.md#159) | `make detection-setup` / `security:rules:load` pada: brak polityki enrich albo danych do p95; `price-hug-threshold` nie strzela |
| [#160](../RUNBOOK.md#160) | `percolate` po referencji: `index_not_found_exception` dla data streamu |
| [#161](../RUNBOOK.md#161) | EQL: `mismatched input '.' expecting 'where'` |
| [#162](../RUNBOOK.md#162) | EQL nie znajduje sekwencji wabika: kliknięcia bez `offer.id` |
| [#163](../RUNBOOK.md#163) | Reguła Kibany `review-ip-burst` nie widzi starych danych albo pisze dokumenty ze sklejonymi wartościami |
| [#164](../RUNBOOK.md#164) | `complaints-spike`: inny werdykt w jobie i w backfillu, alert dla nowego sprzedawcy |
| [#165](../RUNBOOK.md#165) | Po ponownej symulacji raport pokazuje FP / stare alerty; purge i transformy ETAPU 12 |
| [#166](../RUNBOOK.md#166) | 403 `security_exception` na `security-rules` / `product-popularity` (role `search_service`, `catalog_app`) |
| [#167](../RUNBOOK.md#167) | Dwie recenzje tego samego użytkownika na ten sam produkt (wyścig, TOCTOU) |
| [#168](../RUNBOOK.md#168) | Martwe `offer.price_changed` / `review.created` w obcej DLQ (brak `x-dead-letter-routing-key`) |

## Pytania kontrolne

1. Dlaczego `fresh-account-5star` jest w percolatorze, a `review-ip-burst` w ES|QL? Co musiałoby się zmienić
   w regule, żeby percolator przestał wystarczać?
2. Percolator dostaje dokument po referencji. Dlaczego nie można podać nazwy data streamu i dlaczego percolujemy
   dokument z ES, a nie ten, który konsument ma w pamięci?
3. Masz prefiks `/24` i hasz `sha256(ip)` bez sekretu. Ile prób potrzebujesz, żeby odzyskać adres? Co zmienia HMAC
   z pepperem i kiedy ta ochrona przestaje działać?
4. Co robi `with runs=20` i dlaczego dowód alertu mówi `clicks: 20`, choć kliknięć było więcej?
5. Dlaczego post-filtr 1,3 × jest w PHP, a nie w EQL? Które bliskie pudła odrzuca EQL, a które PHP?
6. Co by się stało z `complaints-spike`, gdyby `moving_fn` miało `shift: 1` (okno obejmuje bieżący dzień)? A gdyby
   nie było reguły „pierwsza reklamacja ≥ 7 dni wcześniej”?
7. Dlaczego `alerts-security` jest zwykłym indeksem, a `events-security` data streamem?
8. Drugi `make detection-detect` nie zmienia liczby alertów. Z czego to wynika? Dlaczego dokumenty konektora Kibany
   tego nie mają?
9. Wynik 5/5 i 0 FP: co dokładnie udowadnia, a czego nie?
10. Chcesz złapać sprzedawcę, który podnosi cenę o 15% dwa razy w tygodniu na popularnym produkcie. Które narzędzie
    wybierzesz i jakie bliskie pudło zaprojektujesz?

## Powrót do normalnej pracy

Dane symulacji zostały w klastrze do pisania tego przewodnika. Sprzątanie, **po opróżnieniu kolejek**
`search.security.ingest` i `search.analytics.ingest`:

```bash
make detection-purge          # zdarzenia labels.synthetic, alerty syntetyczne i alerty Kibany na syntetycznych
                              # haszach, kliknięcia secsim-*, reklamacje SEC-, przeliczenie sekcji complaints seller-360
```

`make detection-purge` **nie przelicza transformów ETAPU 12**, do których weszły kliknięcia symulatora (292 w naszym
przebiegu):

```bash
make popularity-refresh       # product-popularity (transform wsadowy, liczy od nowa) + _execute polityki enrich
make detection-setup          # nowy p95 do reguły price-hug-threshold
# search-stats-daily (CTR, reguła ctr-drop) to transform ciągły: nie przelicza kubełków po usunięciu zdarzeń
es POST '/_transform/search-stats-daily/_stop?wait_for_completion=true' </dev/null
es POST '/_transform/search-stats-daily/_reset' </dev/null
es POST '/_transform/search-stats-daily/_start' </dev/null
```

Purge nie usuwa prawdziwych recenzji z UI (niesyntetyczne zdarzenia `review.created` zostają do wygaśnięcia ILM po
30 dniach) ani zbioru dowodowego ETAPU 13 (`SIM-` i `seller-360` syntetycznych sprzedawców). Ponowna symulacja **bez**
purge daje duplikaty zdarzeń z innymi `event.id` i stare alerty: kolejność to zawsze purge, potem simulate
(RUNBOOK [#165](../RUNBOOK.md#165)).

Kontenery zostają w normalnym trybie profilu `apps` (`outbox-publisher`, `seller360-consumer`, `seller360-scheduler`
z nowymi montażami); job `security-detect` co 5 min jest tani (setki milisekund).

## Ściąga

```bash
es() { docker compose exec -T es01 sh -c 'curl -sS -u "elastic:$ELASTIC_PASSWORD" -H "Content-Type: application/json" -X "$0" "http://localhost:9200$1" --data-binary @-' "$1" "$2"; }

# cały cykl
make detection-setup                                  # ES + reguły percolatora (p95) + Kibana; idempotentne
make detection-simulate args="--seed=1 --plant=5"     # symulacja + import reklamacji SEC- (wymaga seller360-consumer)
make detection-detect                                 # backfill 15 d (since=24h itd.), idempotentny
make detection-report                                 # TP / FP / FN; args="--json"
make detection-purge                                  # sprzątanie (potem popularity-refresh i reset search-stats-daily)

# komendy pojedynczo
docker compose exec search-consumer php bin/console security:rules:load --dry-run
docker compose exec search-consumer php bin/console security:detect --rule=price-bait-and-switch --since=48h
docker compose exec catalog-app php artisan outbox:publish        # jednorazowo, gdy outbox-publisher nie działa

# stan
docker compose exec rabbitmq rabbitmqctl list_queues name messages | grep security
es GET '/alerts-security/_count' </dev/null
es GET '/events-security-default/_count' </dev/null
es GET '/security-rules/_search?filter_path=hits.hits._id,hits.hits._source.params.p95' </dev/null

# reguła percolatora bez deployu
es PUT '/security-rules/_doc/<id>?refresh=true' < moja-regula.json
es GET '/security-rules/_search' <<'J'
{"query": {"percolate": {"field": "query", "document": {"event": {"type": "review.created"}, "review": {"rating": 1}, "user": {"id": "1", "account_age_hours": 3}}}}}
J
es DELETE '/security-rules/_doc/<id>' </dev/null
```
