# Seller 360 w skali i pod kluczem, krok po kroku: fan-out kuriera, przebudowa bez przestoju, widok sprzedawcy na basic i FLS/DLS na trialu

*ETAP 13b projektu Marketplace Search Platform. Część 13a
([`etap-13a-agregat-seller-360.md`](etap-13a-agregat-seller-360.md)) zbudowała read model `seller-360`: jeden
dokument na sprzedawcę z sześciu sekcji, każda z własną wersją. Ta część odpowiada na cztery pytania, które pojawiają
się zaraz potem. Co, gdy jedna zmiana u źródła dotyka tysięcy dokumentów (ocena kuriera)? Jak zmienić mapowanie
indeksu, w który cały czas piszą cztery procesy? Jak pokazać sprzedawcy jego statystyki, skoro licencja basic nie ma
zabezpieczeń na poziomie dokumentu i pola? I jak wyglądałoby to samo z prawdziwym DLS/FLS? Na koniec manifesty k3s
dla nowych procesów i plan wdrożenia.*

> **Stan:** gałąź `etap-13-seller-360` (niewypchnięta), lokalny stos Docker (3 węzły ES 9.5.1, RabbitMQ, Redis),
> sprawdzone 2026-11-22. **Na żywo:** fan-out oceny jednego kuriera to ~5 055 aktualizacji w obu indeksach w ~1,0 s
> (2,3 s z ponowieniami konfliktów), p99 zapytania z DoD w trakcie fan-outu 5 ms; przebudowa `v1 → v2` pod ruchem
> (churn 20/s + 1201 faktur) w 23 s, 0 rozjazdów w próbce 500 dokumentów i 0 rozjazdów billingu na 20 000 kont;
> demo DLS/FLS na jednorazowym węźle z trialem 17/17 asercji w 26 s.
> Projekt: [specyfikacja](../superpowers/specs/2026-11-21-etap-13-seller-360-design.md),
> [plan](../superpowers/plans/2026-11-21-etap-13-seller-360.md).
> Pomiary: [`POMIARY.md` §17.4–17.6](../POMIARY.md). Błędy i procedury: RUNBOOK [#146–#151](../RUNBOOK.md#146).
> **Nie wdrożone na k3s** (brak serwera): manifesty i sekrety są w repo i przechodzą kontrole bez klastra,
> plan wdrożenia jest [niżej](#krok-15--wdrożenie-na-k3s-na-papierze).

---

## Spis treści

1. [Problem: co zostało po 13a](#1-problem-co-zostało-po-13a)
2. [Mapa plików](#2-mapa-plików)
3. [Cały tor 13b od zera](#3-cały-tor-13b-od-zera)
4. [Krok 1 — fan-out a denormalizacja](#krok-1--fan-out-a-denormalizacja)
5. [Krok 2 — `_update_by_query` ze strażnikiem `rated_at`](#krok-2--_update_by_query-ze-strażnikiem-rated_at)
6. [Krok 3 — `conflicts: proceed` gubi aktualizację](#krok-3--conflicts-proceed-gubi-aktualizację)
7. [Krok 4 — koszt fan-outu w liczbach](#krok-4--koszt-fan-outu-w-liczbach)
8. [Krok 5 — przebudowa: dlaczego nie wystarczy `_reindex`](#krok-5--przebudowa-dlaczego-nie-wystarczy-_reindex)
9. [Krok 6 — podwójny zapis: 1 s cache i 2 s czekania](#krok-6--podwójny-zapis-1-s-cache-i-2-s-czekania)
10. [Krok 7 — backfill i weryfikacja](#krok-7--backfill-i-weryfikacja)
11. [Krok 8 — wyścig ze strażnikiem wersji](#krok-8--wyścig-ze-strażnikiem-wersji)
12. [Krok 9 — przebudowa pod ruchem](#krok-9--przebudowa-pod-ruchem)
13. [Krok 10 — widok sprzedawcy na licencji basic](#krok-10--widok-sprzedawcy-na-licencji-basic)
14. [Krok 11 — basic a Platinum: co gwarantuje ES, a co aplikacja](#krok-11--basic-a-platinum-co-gwarantuje-es-a-co-aplikacja)
15. [Krok 12 — demo DLS/FLS na węźle z trialem](#krok-12--demo-dlsfls-na-węźle-z-trialem)
16. [Krok 13 — lekcja z pustych asercji](#krok-13--lekcja-z-pustych-asercji)
17. [Krok 14 — manifesty k3s i sekrety](#krok-14--manifesty-k3s-i-sekrety)
18. [Krok 15 — wdrożenie na k3s (na papierze)](#krok-15--wdrożenie-na-k3s-na-papierze)
19. [Liczby z żywego stosu](#liczby-z-żywego-stosu)
20. [Ograniczenia](#ograniczenia)
21. [Co wybuchło po drodze](#co-wybuchło-po-drodze)
22. [Ściąga](#ściąga)

---

## 1. Problem: co zostało po 13a

| Temat | Stan po 13a | Co trzeba rozstrzygnąć |
|---|---|---|
| Ocena kuriera | sekcja `registry.courier` pisana przy pełnej synchronizacji sprzedawcy (pętla 20 tys. ~35 min) | jedna zmiana oceny dotyczy ~2,5 tys. sprzedawców na kuriera; czekanie na pętlę to pół godziny nieaktualnej oceny |
| Zmiana mapowania | jeden zestaw indeksów `seller-360-v1` / `seller-360-public-v1` za aliasami | czterech pisarzy (projektor, harmonogram, pętla rejestru, fan-out) pisze cały czas; prawdziwi sprzedawcy mają rzadkie karty (brak historycznych `seller.*`) |
| Widok sprzedawcy | brak; konsultant czyta wszystko kontem `catalog` | sprzedawca ma widzieć swoje statystyki, ale nie saldo zaległości ani treści reklamacji, a basic nie ma DLS/FLS |
| k3s | procesy tylko w `compose.yaml` | manifesty, polityki sieci, sekret nowego konta ES, kolejność wdrożenia |

## 2. Mapa plików

```
apps/search/
  src/Seller360/Registry/CourierFanOut.php          fan-out ocen: courier-ratings + _update_by_query, ponowienia, since
  src/Seller360/Rebuild/WriteTargets.php            lista par celów zapisu w Redisie (seller360:write-targets), cache 1 s
  src/Seller360/Rebuild/Seller360Rebuilder.php      przebudowa: create, dual, backfill, verify, switch, enrich
  src/Seller360/Rebuild/BillingParity.php           zgodność sekcji billing z catalogiem (wszystkie konta)
  src/Command/Seller360{CourierPoll,Rebuild,BillingParity}Command.php
apps/catalog/
  app/Seller360/SellerStatsReader.php               „Twoje statystyki”: GET seller-360-public/_doc/<id>, biała lista kluczy
  app/Http/Controllers/Seller/SellerDashboardController.php   deferred prop seller360, id z CurrentSeller
  resources/js/pages/seller/Dashboard.vue           blok „Twoje statystyki”
  app/Console/Commands/BillingChurnCommand.php      billing:churn — faktury pod ruchem (do pomiaru przebudowy)
  tests/Arch/SellerViewClientTest.php               tylko SellerStatsReader używa klienta es.seller_view
infra/elasticsearch/seller360/script-courier-rating.json   strażnik rated_at (noop przy starszej ocenie)
infra/elasticsearch/seller360/index-courier-ratings.json   _id = courier_id
infra/elasticsearch/setup-security.sh               rola seller_stats_view, użytkownik catalog_seller_view
tools/seller360/fanout-measure.sh                   koszt fan-outu dla zapytań z DoD (POMIARY §17.4)
tools/seller360/rebuild-under-load.sh               make seller360-rebuild-under-load (POMIARY §17.5)
tools/seller360/fls-demo.sh, fls-roles.json         make seller360-fls-demo (POMIARY §17.6)
deploy/k8s/seller360-{consumer,scheduler,registry}.yaml, registry-mock.yaml   manifesty k3s
deploy/k8s/{networkpolicies,catalog,elasticsearch,es-roles}.yaml, config.env  trasy, konto ES, rola
tools/k3s/secrets.sh, tools/k3d/secrets.sh          ES_CATALOG_SELLER_VIEW_PASSWORD, Secret es-user-catalog-seller-view
```

## 3. Cały tor 13b od zera

Zakłada działający tor 13a (`make seller360-setup`, procesy, dane z generatora). Potem:

```bash
# fan-out: zmiana oceny courier-3 w mocku i pomiar wpływu na zapytanie z DoD
RUNS=100 COURIER=courier-3 bash tools/seller360/fanout-measure.sh

# przebudowa v<N> -> v<N+1> pod ruchem, potem zgodność billingu
make seller360-rebuild-under-load
# albo sama przebudowa (bez ruchu), z usunięciem starych indeksów:
make seller360-rebuild args="--delete-old"

# widok sprzedawcy: hasło konta w .env, es-setup, odtworzenie catalog-app
echo "ES_CATALOG_SELLER_VIEW_PASSWORD=$(openssl rand -hex 24)" >> .env
docker compose run --rm es-setup && docker compose up -d --force-recreate catalog-app catalog-worker
open "https://catalog.localhost:${CATALOG_HTTPS_PORT:-8443}/seller"       # konto sprzedawcy

# DLS/FLS na jednorazowym węźle z trialem (sprząta po sobie)
make seller360-fls-demo

# manifesty k3s bez klastra
make k3s-manifests-check && make g5-check
```

---

## Krok 1 — fan-out a denormalizacja

Zapytanie konsultanta filtruje i sortuje po `registry.courier.on_time_rate`. ES nie ma joinów, więc ocena musi leżeć
**w dokumencie sprzedawcy**. To denormalizacja: jedna wartość (ocena kuriera) skopiowana do każdego dokumentu
sprzedawcy, którego ten kurier obsługuje. Cena przychodzi przy zapisie: zmiana jednej oceny to aktualizacja ~2,5 tys.
dokumentów w każdym z dwóch indeksów (`seller-360` i `seller-360-public`). To jest **fan-out**.

Alternatywa to trzymać ocenę tylko w małym indeksie `courier-ratings` (8 dokumentów) i łączyć przy odczycie.

| | Denormalizacja + fan-out (wybrane) | Normalizacja (ocena tylko w `courier-ratings`) |
|---|---|---|
| Zapis przy zmianie oceny | ~5 055 aktualizacji w obu indeksach, ~1,0 s (2,3 s z ponowieniami) | 1 dokument |
| Pierwsze zasilenie (8 kurierów) | 39 992 aktualizacje w 2 376 ms | 8 dokumentów |
| Filtr „kurier < 0,8” | zwykły `range` w tym samym zapytaniu | dwa kroki: kurierzy z oceną < 0,8, potem `terms` po `registry.courier.id` |
| Sort po ocenie kuriera | zwykły `sort` | niemożliwy w ES bez skryptu z mapą ocen w parametrach |
| Widok sprzedawcy | jedno `GET _doc` | `GET` + drugie zapytanie o kuriera |
| Wpływ na zapytania w trakcie zmiany | p99 z DoD 5 ms (wcześniej, przed poprawką, do 18 ms) przy bazie 1–3 ms | brak |

Fan-out opłaca się, bo oceny zmieniają się rzadko (rejestr ocenia kurierów raz na jakiś czas), a czytamy cały czas.
Gdyby ocena zmieniała się co sekundę, rachunek byłby odwrotny. To ta sama decyzja co w ofertach z 13a, tylko
z drugiej strony: tam wiele zdarzeń na jeden dokument (koalescencja), tu jedno zdarzenie na wiele dokumentów.

## Krok 2 — `_update_by_query` ze strażnikiem `rated_at`

Zadanie harmonogramu `courier:poll` (co 60 s, `seller360-scheduler`) robi `CourierFanOut::poll($since)`:

1. `GET /couriers/ratings?since=<since>` z rejestru — tylko oceny zmienione po `since`;
2. zapis każdej oceny do `courier-ratings` (`_id = courier_id`); stąd czyta ją pełna synchronizacja rejestru
   (`RegistrySync`), żeby pętla nie cofnęła oceny do wartości z cache;
3. dla każdego kuriera i każdego indeksu docelowego `_update_by_query` z `term registry.courier.id` i zapisanym
   skryptem `seller360-courier-rating`;
4. `slices: auto`, `conflicts: proceed`, `wait_for_completion: false` i odpytywanie `GET _tasks/<id>` co 1 s.

Skrypt to strażnik czasu, tak jak `seller360-apply-section` jest strażnikiem wersji:

```painless
Map courier = ctx._source.registry != null ? ctx._source.registry.courier : null;
if (courier == null) { ctx.op = 'noop'; return; }
if (courier.rated_at != null) {
  long stored = 0L;   // nieczytelne rated_at: fail-open, ocena z rejestru wygrywa
  try { stored = java.time.ZonedDateTime.parse(courier.rated_at.toString()).toInstant().toEpochMilli(); }
  catch (Exception e) { stored = 0L; }
  if (stored >= ((Number) params.rated_at_ms).longValue()) { ctx.op = 'noop'; return; }
}
courier.on_time_rate = params.rate;
courier.rated_at = params.rated_at;
```

Porównanie idzie w milisekundach, więc format strefy (`Z` czy `+00:00`) i kolejność nadejścia nie mają znaczenia.
`_meta` sekcji `registry` zostaje nietknięte: fan-out zmienia jedno pole, a nie synchronizuje rejestru, więc nie może
udawać świeżej synchronizacji (lag `registry` liczy się dalej od pętli).

Strażnik działa też na `seller-360-public`, bo publiczny dokument trzyma `registry.courier.{id, on_time_rate,
rated_at}` (decyzja projektowa: bez `rated_at` strażnik nie miałby czego porównać, a znacznik czasu nie jest
wrażliwy).

Cele zapisu to nie twarde aliasy, tylko `WriteTargets::indices()` (krok 6). W trakcie przebudowy fan-out pisze więc
także do nowych indeksów. Listę celów czyta **po** zapisie ocen do `courier-ratings`: indeks dopisany chwilę później
dostanie te oceny z backfillu (który czyta `courier-ratings`), a dopisany wcześniej jest już na liście.

## Krok 3 — `conflicts: proceed` gubi aktualizację

Pierwsza wersja zmierzyła się dobrze: `updated 5052–5055`, `took 1017–1019 ms`, `conflicts 1–4`. Te 1–4 konflikty
wyglądały na szum. Przegląd kodu pokazał, że to błąd.

`_update_by_query` robi snapshot dokumentów pasujących do zapytania i aktualizuje je po kolei z kontrolą wersji.
Jeśli w międzyczasie inny pisarz (projektor, pętla rejestru) zmienił dokument, wersja się nie zgadza. Przy
`conflicts: proceed` ES **pomija** ten dokument i liczy go w `version_conflicts`. Dokument zostaje ze starą oceną.
Do tego `since` przesuwało się na najnowsze `rated_at`, więc następny przebieg nie pytał już o tę ocenę. Dokument
zostawał z błędną oceną na zawsze (do następnej zmiany oceny tego kuriera albo pełnej synchronizacji).

Poprawka (commit `52aae65`) ma dwie części:

- **Ponowienie.** Każda para (kurier, indeks) z `version_conflicts > 0` idzie jeszcze raz, do 3 prób, po odświeżeniu
  indeksu i pauzie 200/400 ms. Skrypt jest idempotentny, więc dokumenty poprawione w pierwszej próbie stają się
  `noop`. `conflicts` w wyniku to konflikty **resztkowe** po ostatniej próbie.
- **`since` tylko po czystym przebiegu.** `pollFromRedis` przesuwa `seller360:courier:since` na największe
  `rated_at` tylko, gdy resztkowych konfliktów jest 0. Wyjątek albo konflikty zostawiają `since`, więc następny
  przebieg powtarza pracę (z ostrzeżeniem w logu, RUNBOOK [#146](../RUNBOOK.md#146)).

Trzecia, mniejsza zmiana: do rejestru idzie `since` **cofnięte o 1 s**. `GET /couriers/ratings?since=` jest
wyłączne (`rated_at > since`), a dwie oceny z tej samej sekundy, z których druga przyszła po pierwszym zapytaniu,
zginęłyby. Koszt: najnowsza ocena wraca w każdym przebiegu i daje tanie `noop`y (bezczynny przebieg to i tak
`_update_by_query` z samymi noopami i odświeżenie co 60 s, patrz [Ograniczenia](#ograniczenia)).

Odświeżenie indeksów idzie raz na cel po wszystkich kurierach i przed każdą rundą ponowień, a nie `refresh=true`
przy każdym `_update_by_query` — ponowienie musi widzieć wersje, które wygrały konflikt.

## Krok 4 — koszt fan-outu w liczbach

```bash
RUNS=100 COURIER=courier-3 bash tools/seller360/fanout-measure.sh
```

Skrypt mierzy p50/p99 `took` zapytania z DoD (`seller360:query:verify`) bez fan-outu, potem zmienia ocenę kuriera
w mocku (`POST /_chaos {"bump_courier":"courier-3"}`) i puszcza te same zapytania w tle **w trakcie**
`seller360:courier:poll --once`.

| Przebieg | updated | noops | conflicts | took | p50 / p99 DoD w trakcie | p99 bez fan-outu |
|---|---|---|---|---|---|---|
| pierwsza wersja, run 1 | 5052–5055 | 0 | 1–4 | 1017–1019 ms | 1 / **18 ms** | 1–3 ms |
| pierwsza wersja, run 2 | j.w. | 0 | j.w. | j.w. | 1 / 2 ms | 1–3 ms |
| po poprawce | 3595 | 5056 | **0** | 2300 ms | 1 / **5 ms** | 1 ms |
| bootstrap (`since` = epoka, 8 kurierów) | 39 992 | — | 10 | 2376 ms | — | — |

Jak czytać:

- **~5 055 dokumentów** to sprzedawcy kuriera 3 w obu indeksach (~2,5 tys. w każdym; 20 tys. sprzedawców na 8
  kurierów).
- **Po poprawce `updated + noops` > liczba dokumentów**, bo liczniki sumują się po próbach: w drugiej próbie
  dokumenty poprawione w pierwszej liczą się jako `noop`. Rozbicia na „naprawdę bez zmian” i „poprawione wcześniej”
  te liczniki nie dają (zob. [Ograniczenia](#ograniczenia)). Ważne jest `conflicts 0`: konflikt się zdarzył i został
  ponowiony.
- **Czas 2,3 s zamiast ~1,0 s** to koszt odświeżenia i pauzy przed ponowieniem.
- **p99 w trakcie** waha się między przebiegami (18 ms i 2 ms w pierwszej wersji), bo zależy od tego, ile zapytań
  trafi w okno zapisu. Rząd wielkości: kilka–kilkanaście ms przy bazie 1–3 ms, przez ~1–2 s.

Pełne liczby: [POMIARY §17.4](../POMIARY.md).

---

## Krok 5 — przebudowa: dlaczego nie wystarczy `_reindex`

W ETAPIE 10 migracja `products` szła przez `_reindex` z bramką i nadrabianiem z outboxu. Przy `seller-360` to nie
wystarcza z trzech powodów:

1. **Źródłem prawdy nie jest stary indeks.** Prawdziwi sprzedawcy nie mają `profile` i `nip`, bo nie mieli
   historycznych zdarzeń `seller.*` (13a, ograniczenia). `_reindex` skopiowałby tę dziurę. Przebudowa ma **dociągnąć** stan
   z catalogu (`/api/internal/sellers`, `/api/internal/billing/accounts`).
2. **Pisarzy jest czterech i nie wszyscy idą przez outbox.** Pętla rejestru, fan-out kuriera i odświeżanie sprzedaży
   piszą z własnego rytmu. Nadrabianie z outboxu (jak w ETAPIE 10) nie zobaczyłoby ich zapisów.
3. **Sekcje mają wersje.** Skoro każdy zapis jest strażnikowany (`noop` przy starszej wersji), można pisać do starego
   i nowego indeksu naraz bez koordynacji kolejności. To otwiera drogę do podwójnego zapisu.

Przebieg `Seller360Rebuilder::run` (`make seller360-rebuild`, w podzie/kontenerze `seller360-scheduler`):

| Faza | Co się dzieje |
|---|---|
| 1. create | odmowa, gdy podwójny zapis już trwa; nowe `seller-360-v<N+1>` i `seller-360-public-v<N+1>` z ustawieniami i mapowaniem z szablonu, jawnie w żądaniu |
| 2. dual | `WriteTargets::startDual` dopisuje nową parę; 2 s czekania (krok 6) |
| 3. backfill | sekcje do nowej pary przez ten sam `SectionWriter` (paczki po 500): profile i billing z catalogu, registry z cache Redisa / starego indeksu, offers, sales, complaints przeliczone |
| 4. verify | `_count` i próbka 500 losowych `_id` porównana ze starym indeksem, do 3 powtórek co 2 s, tolerancja 0 |
| 5. switch | jeden `POST _aliases` dla 4 aliasów (remove ze starego, add do nowego, `is_write_index` na `-write`), potem `finish` (koniec podwójnego zapisu) i `_enrich/policy/seller-by-nip/_execute` |
| 6. `--delete-old` | opcjonalnie usuwa stare indeksy |

Rozjazd ponad tolerancję albo wyjątek w fazach 2–4 kończy podwójny zapis, zostawia aliasy na starym indeksie, a nowe
indeksy do obejrzenia; status `aborted`, kod wyjścia 1.

## Krok 6 — podwójny zapis: 1 s cache i 2 s czekania

`WriteTargets` trzyma w Redisie (`seller360:write-targets`) listę par `{main, public}`. Brak klucza = jedna para
aliasów zapisu. `SectionWriter`, fan-out i `SyntheticPurger` piszą do **każdej** pary z listy (`_bulk` z wpisami dla
wszystkich par); wynik dla wołającego to wynik pierwszej pary (aliasy), a `error`, gdy zapis prywatny odrzuciła
którakolwiek para — wtedy wołający ponawia.

Każdy proces czyta listę co najwyżej co **1 s** (cache w pamięci), żeby nie pytać Redisa przy każdym zapisie.
Przebudowa po `startDual` czeka **2 s** przed backfillem. Rozumowanie:

- Pisarz, który odczytał listę tuż przed `startDual`, może jeszcze przez ≤ 1 s pisać tylko do starej pary.
- Po 2 s każdy proces ma już nową listę, a zapis, który zaczął się ze starą listą, zdążył się skończyć.
- Od tej chwili każda zmiana idzie do obu par. Backfill startuje **po** niej, więc nie ma okna, w którym zmiana
  trafia tylko do starego indeksu, a backfill już przeszedł obok tego dokumentu.

Kolejność backfillu i zapisów na żywo nie ma znaczenia dzięki strażnikowi wersji: jeśli podwójny zapis przyniósł
nowszą wersję, backfill ze starszą dostaje `noop`. W pomiarze pod ruchem billing dał 19 916 zapisów i 84 noopy —
dokładnie te konta, które zdążył już zapisać podwójny zapis.

Awaria Redisa nie może po cichu wyłączyć podwójnego zapisu: zostaje ostatnia znana lista, a gdy listy jeszcze nie
było — wyjątek (wiadomość wraca do ponowienia). Uszkodzony JSON w kluczu to też wyjątek.

**Ryzyko, które zostało:** blok sprzątający obejmuje wszystkie fazy od włączenia podwójnego zapisu do przełączenia
aliasów (pierwsza wersja zostawiała fazę switch poza nim, więc błąd `POST _aliases` zostawiał dwie pary; poprawione
po przeglądzie gałęzi). Nie obroni się przed zabiciem procesu: wtedy klucz `seller360:write-targets` zostaje z dwiema
parami, a następna przebudowa odmówi („podwójny zapis już trwa”). Ręczne sprzątanie: RUNBOOK [#147](../RUNBOOK.md#147).

## Krok 7 — backfill i weryfikacja

Backfill per sekcja i to, co weryfikacja z niego porównuje:

| Sekcja | Skąd backfill | `version` / `source_ts` | Weryfikacja próbki |
|---|---|---|---|
| profile | `/api/internal/sellers` (strony po 2000) | wersja z catalogu / chwila backfillu | w starym ⇒ w nowym i wersje **równe** (sekcja tylko w nowym = OK: prawdziwi sprzedawcy bez profilu w starym) |
| billing | `/api/internal/billing/accounts` | wersja konta / — (`last_invoice_id` = null) | jak profile |
| registry | cache rejestru w Redisie (`fetched_at` → wersja w ms, `ok` gdy < 3600 s, inaczej `stale`), kurier z `courier-ratings`; bez cache sekcja przeniesiona ze starego indeksu z jej `_meta` | j.w. | obecna i `source_ts` równe albo wersja nowego ≥ starej |
| offers | przeliczenie dla sprzedawców ze starego indeksu z `offers` ∪ prawdziwych z catalogu | chwila przeliczenia | **tylko obecność** |
| sales | `SalesSectionRefresher::refresh(now)` z `seller-sales-daily` | j.w. | tylko obecność |
| complaints | przeliczenie dla wszystkich z catalogu ∪ starego indeksu z `complaints` | j.w. | tylko obecność |

Dlaczego `offers`, `sales` i `complaints` tylko po obecności: to sekcje przeliczane, ich wersja to chwila
przeliczenia, a nie wersja u źródła. Stary i nowy indeks mają z definicji różne wersje, a porównanie treści
wymagałoby powtórzenia tych samych obliczeń w tej samej chwili. Do tego `_count` obu indeksów (abort tylko, gdy nowy
ma mniej; nadwyżka to sprzedawcy odzyskani z catalogu) i `nip` równy, gdy stary go miał.

Rozjazd jest ponawiany do 3 razy co 2 s: zdarzenie w locie trafia do obu par, ale z opóźnieniem kolejki.

Po przebudowie z 13a znikła dziura z prawdziwymi sprzedawcami: profil + NIP **61/61** (wcześniej 1/61). Region 0/61,
bo seeder dał im angielskie miasta fakera („North Chauncey”), których nie ma w `miasta-wojewodztwa.csv`. To dane,
nie błąd backfillu.

## Krok 8 — wyścig ze strażnikiem wersji

Test integracyjny `tests/Integration/Seller360/RebuildTest.php` (własna przestrzeń nazw `seller-360-rt<id>`) wymusza
najgorszą kolejność:

1. w starym indeksie billing sprzedawcy ma wersję 5 (saldo 500);
2. hook między fazą 2 (dual) a 3 (backfill) wysyła przez `SectionWriter` wersję 6 (saldo 600) — trafia do obu par;
3. backfill przychodzi z wersją 5 z „catalogu” (stub).

Asercja: nowy indeks ma wersję 6 i saldo 600, nie 5. Ten sam test sprawdza, że prawdziwy sprzedawca bez profilu
dostaje profil, NIP i region, oferty są przeliczone, publiczny indeks nie ma `billing`, stare indeksy zostają,
`WriteTargets` wraca do jednej pary, a zapis po przełączeniu trafia do v2. Trzy pozostałe testy: przerwanie przy
rozjeździe (zapis tylko do starego → `aborted`, alias na v1, v2 zostaje, podwójny zapis wyłączony), odmowa przy
trwającym podwójnym zapisie i `--delete-old`.

## Krok 9 — przebudowa pod ruchem

```bash
make seller360-rebuild-under-load
```

`tools/seller360/rebuild-under-load.sh`: w tle `make churn` 20/s i `billing:churn` 10/s przez 120 s, po 10 s ruchu
`seller360:rebuild`, koniec ruchu, 30 s na zdarzenia w locie, `seller360:billing:parity` (wszystkie konta z catalogu
vs sekcje `billing`: równa wersja i pola `balance_cents`, `overdue_cents`, `overdue_since`, `open_invoices`).

`billing:churn` powstał, bo `seller360:simulate --billing` przy istniejących syntetycznych sprzedawcach odmawia;
wystawia faktury losowym syntetycznym sprzedawcom przez `IssueInvoiceHandler`, czyli przez outbox.

```
  phases_ms={"create":296,"dual":2001,"backfill_profile":4160,"backfill_billing":3681,"backfill_registry":4918,
             "backfill_offers":1521,"backfill_sales":651,"backfill_complaints":3492,"backfill":18423,"verify":287,
             "switch":65,"enrich":1425}
  backfill={"profile":20061,"billing":19916,"registry":19866,"offers":61,"sales":2079,"complaints":4065}
  counts_old={"main":20061,"public":20061} counts_new={"main":20061,"public":20061} sampled=500 mismatches=0
  churn:   updated=2270 deleted=130 failed=0
  billing: issued=1201 failed=0
  accounts=20000 mismatches=0
  przebudowa: 23 s (zegar hosta), kod 0
```

Przebudowa skończyła się 87 s przed końcem ruchu, więc przełączenie aliasów nastąpiło pod pełnym obciążeniem. Faza
switch to 65 ms: jedno atomowe `_aliases`, czytelnik nie widzi chwili bez aliasu. 0 wpisów WARNING/ERROR w logach
komend. Po przebudowie `make seller360-verify` dalej 5/5 (precyzja i czułość 1,000, p50 0 / p99 3 ms). Stare
`seller-360-v1` i `seller-360-public-v1` zostały (bez `--delete-old`).

---

## Krok 10 — widok sprzedawcy na licencji basic

Sprzedawca ma na swoim pulpicie zobaczyć blok „Twoje statystyki”: profil, oferty, sprzedaż i ocenę kuriera. Nie
może zobaczyć `billing` (saldo, zaległości) ani `complaints.recent_texts` (treści reklamacji klientów), ani
dokumentów innych sprzedawców. Na Platinum to dwie linijki roli (DLS + FLS, krok 12). Na basic tych funkcji nie ma,
więc izolacja jest zbudowana z czterech warstw:

1. **Osobny indeks.** Projektor od 13a pisze drugi, okrojony dokument do `seller-360-public` (`PublicProjection`:
   `profile`, `offers`, `sales`, `registry.courier.{id, on_time_rate, rated_at}` i pola dokumentu). `billing`
   i `complaints` nie istnieją tam fizycznie.
2. **Rola na dokładną nazwę.** `seller_stats_view` ma `read` tylko na `seller-360-public` — nazwę aliasu, nie wzorzec
   (`seller-360*` dałby też prywatny). Bez `monitor` i bez `view_index_metadata`: konto nie wie nawet, że istnieje
   `seller-360`. Użytkownik ES `catalog_seller_view` ma tylko tę rolę (`setup-security.sh`, `deploy/k8s/es-roles.yml`).
3. **Biała lista w czytniku.** `SellerStatsReader` (jedyna klasa z klientem `es.seller_view`, pilnuje tego test
   architektury) zwraca tylko klucze `profile`, `offers`, `sales`, `courier_on_time_rate` i `freshness`. Nawet źle
   zasilony alias nie wypuści `nip`, `region`, `synthetic` ani sekcji spoza widoku.
4. **Id z sesji, nie z żądania.** `SellerDashboardController` bierze `seller_id` z `CurrentSeller` (zalogowany
   sprzedawca) i robi `GET seller-360-public/_doc/<id>` — bez wyszukiwania. Nie ma parametru, który użytkownik mógłby
   podmienić.

Czytnik zwraca `null` przy 404 (sprzedawca bez dokumentu) i przy błędzie transportu (`report()`), a strona pokazuje
„Statystyki chwilowo niedostępne” i działa dalej. Prop jest odroczony (`Inertia::defer`), więc wolny ES nie
spowalnia pulpitu.

Testy negatywne:

- `SellerViewRoleTest` (search, prawdziwy klaster, wymaga `ELASTIC_PASSWORD`, bez niego się pomija): konto
  `catalog_seller_view` dostaje 403 na `_search` i `GET _doc` w `seller-360` oraz na zapis do `seller-360-public`;
  czyta `seller-360-public/_doc`, a dokument nie ma `billing` ani `complaints`.
- `SellerDashboardSeller360Test` (catalog): sprzedawca A dostaje dokument A, nawet gdy żądanie podsuwa cudzy
  `seller_id`; props nie zawierają `billing`, `overdue`, `recent_texts`, `nip` ani `region`; przy awarii ES strona
  zwraca 200 z `seller360 = null`.
- `SellerViewClientTest` (arch): nic poza `SellerStatsReader` nie używa `es.seller_view`.

## Krok 11 — basic a Platinum: co gwarantuje ES, a co aplikacja

| Wymaganie | Basic (główny stos) | Platinum / trial (DLS + FLS) |
|---|---|---|
| Sprzedawca nie widzi `billing` ani treści reklamacji | **ES**: konto nie ma dostępu do `seller-360` (403), a `seller-360-public` tych pól nie zawiera | **ES**: FLS `except: ["billing.*", "complaints.recent_texts"]` na tym samym indeksie |
| Sprzedawca widzi tylko własny dokument | **aplikacja**: `GET _doc/<id>` z id z sesji, bez wyszukiwania; konto ES widzi publiczne dane **wszystkich** sprzedawców | **ES**: DLS, szablon `{"term": {"seller_id": "{{_user.metadata.seller_id}}"}}`; cudzy `_doc` daje 404 |
| Zapytanie po ukrytym polu (`range billing.overdue_cents > 0`) | nie ma pola, więc 0 trafień | 0 trafień (FLS działa też w zapytaniach) |
| Agregacja po ukrytym polu (`sum billing.overdue_cents`) | brak pola | 0 albo `null` |
| Pola dokumentu (`nip`, `profile.status_reason`) | nie trafiają do publicznego dokumentu (`PublicProjection::filterDoc` i filtr `profile`); `region` i `synthetic` są w nim, a odcina je **aplikacja** (biała lista czytnika) | odcina rola, jeśli dopiszesz je do `except` |
| Skutek błędu w catalogu (np. id z żądania) | wyciek publicznych statystyk innych sprzedawców, nadal **bez** billingu | ES dalej odmawia |
| Koszt | drugi indeks i drugi zapis każdej sekcji, `PublicProjection`, osobna rola i użytkownik, testy | licencja; jeden indeks; użytkownik ES (albo token) z `metadata.seller_id` per sprzedawca |

W skrócie: na basic **ES gwarantuje, czego sprzedawca nie zobaczy w ogóle** (billing, reklamacje), a **aplikacja
gwarantuje, czyj dokument zobaczy**. Na Platinum oba gwarantuje ES. Dlatego na basic konto ma uprawnienia do jednego
aliasu o dokładnej nazwie, a kod, który wybiera dokument, jest w jednej klasie pod testem architektury.

## Krok 12 — demo DLS/FLS na węźle z trialem

```bash
make seller360-fls-demo
```

`tools/seller360/fls-demo.sh`:

1. **Pamięć.** Maszyna jest współdzielona z innymi projektami. Skrypt liczy zapas w Docker VM (pamięć Dockera minus
   suma użycia kontenerów) i odmawia poniżej 2048 MB. Cudzych kontenerów nie zatrzymuje (RUNBOOK
   [#149](../RUNBOOK.md#149)).
2. **Węzeł.** `docker run` jednowęzłowego ES z obrazu projektu (`marketplace/elasticsearch:9.5.1`, bo szablon wymaga
   analizatorów `pl_*` i synonimów; nadpisanie `DEMO_IMAGE`), `-Xmx768m`, bez TLS, port losowy na `127.0.0.1`,
   bez wolumenu. `POST _license/start_trial`.
3. **Dane.** Szablon `seller-360` (repliki 0), indeks `seller-360-v1` z aliasem i próbka 200 dokumentów z głównego
   klastra: pierwsze 200 po `seller_id` spośród mających `billing` (sort po `_id` jest na głównym klastrze
   zablokowany, fielddata na `_id` wyłączone). Sprzedawca A: pierwszy z `overdue_cents > 0` i z `recent_texts`, B:
   inny.
4. **Role** z `tools/seller360/fls-roles.json`: `seller_self` (DLS z szablonem na `_user.metadata.seller_id`, FLS
   `grant: ["*"], except: ["billing.*", "complaints.recent_texts"]`) i `consultant` (pełny odczyt). Użytkownicy
   `seller_a` (metadata `seller_id` = A) i `consultant`.
5. **Asercje** (17): `seller_a` widzi 1 dokument i jest to A; dokument bez `billing` i bez
   `complaints.recent_texts`, ale z `profile` (FLS nie ucina reszty); `range billing.overdue_cents > 0` daje 0;
   `sum billing.overdue_cents` to 0 albo `null`; `_doc/B` daje 404, `_doc/A` 200; konsultant widzi 200 dokumentów
   z `billing`, dokument A ma `overdue_cents > 0`, dokument B ma `billing`. Przed każdą asercją sprawdzenie, że
   odpowiedź nie jest błędem (krok 13).
6. **Sprzątanie** (`trap EXIT`): kontener i plik próbki znikają także po błędzie i Ctrl-C.

Hasła demo są losowane w locie (`openssl rand`) i nie trafiają ani do plików, ani na wyjście, ani do argumentów
procesów: `docker run -e ELASTIC_PASSWORD` z eksportu, `jq` czyta `env.*`, `curl -K` z deskryptora. Hasło głównego
klastra z `.env` idzie tą samą drogą (z cudzysłowem lub ukośnikiem skrypt odmawia).

Wynik: kod 0, **26 s**, **17/17**, sprzedawca A = 5000025, po skrypcie `docker ps | grep seller360-trial` puste.
Zapas pamięci przy pierwszym przebiegu: 3685 MB (Docker 15 969 MB, kontenery 12 284 MB).

## Krok 13 — lekcja z pustych asercji

Pierwsza wersja demo dawała 12/12 w 23–25 s. Przegląd znalazł asercje, które **nie mogły się nie udać**:

- „suma `billing.overdue_cents` to 0 albo `null`” — `jq` na odpowiedzi z błędem (np. 403 albo zła nazwa indeksu)
  zwraca `null` dla brakującej agregacji, więc asercja przechodziła także wtedy, gdy zapytanie w ogóle nie zadziałało;
- „dokument bez klucza `billing`” — na odpowiedzi z błędem nie ma `_source`, więc nie ma też `billing`;
- „brak `complaints.recent_texts`” — przechodziło, jeśli A po prostu nie miał reklamacji.

Poprawka (commit `fa000f6`):

- przed każdą asercją `resp_ok`: brak `.error` i `_shards.failed == 0`;
- agregacja musi istnieć i mieć klucz `value`, dopiero wtedy 0 albo `null`;
- obecność `_source` sprawdzana jawnie;
- **kontrole dodatnie**: A wybrany z `overdue_cents > 0` **i** z `recent_texts`, a konsultant musi widzieć u A
  `overdue_cents > 0` — dane do ukrycia istnieją, więc ich brak u `seller_a` coś znaczy.

Dowód, że strażniki działają: pierwszy przebieg po poprawce miał brakujące eksporty haseł i zamiast fałszywej zieleni
dał **15 FAIL**. Po naprawie eksportów: 17/17.

Zasada: asercja „czegoś nie ma” jest warta tyle, co dowód, że zapytanie się wykonało i że w danych to coś jest.
Inaczej test bezpieczeństwa przechodzi najłatwiej wtedy, gdy wszystko jest zepsute.

---

## Krok 14 — manifesty k3s i sekrety

Cztery nowe pliki w `deploy/k8s`, wzorowane na `analytics-consumer.yaml` (profil `restricted`: uid 33,
`readOnlyRootFilesystem`, `drop: [ALL]`, `seccompProfile: RuntimeDefault`, zapisywalny tylko `/tmp`):

| Manifest | Komenda | Env ponad bazę | Limit | Sonda |
|---|---|---|---|---|
| `seller360-consumer.yaml` | `messenger:consume seller360 --time-limit=3600 --memory-limit=256M` | DSN-y Messengera i Postgresa, ES `searchsvc`, `REDIS_PASSWORD` | 512Mi | `pgrep -f messenger:consume` |
| `seller360-scheduler.yaml` | `seller360:scheduler` | ES, Redis, `INTERNAL_API_TOKEN`, `REGISTRY_BASE_URL`, `DATABASE_URL`; `strategy: Recreate`, 1 replika | 256Mi | `pgrep -f seller360:scheduler` |
| `seller360-registry.yaml` | `seller360:registry:sync --loop` | ES, Redis, `REGISTRY_BASE_URL`, `DATABASE_URL`; `Recreate`, 1 replika | 256Mi | `pgrep -f seller360:registry:sync` |
| `registry-mock.yaml` | `php -S 0.0.0.0:8080 /srv/registry-mock/index.php`, `PHP_CLI_SERVER_WORKERS=4` + Service ClusterIP 8080 | — (własny ServiceAccount) | 128Mi | TCP 8080 (sonda HTTP zabiłaby poda w zamierzonej awarii `outage`) |

`terminationGracePeriodSeconds: 30` odpowiada `stop_grace_period: 30s` z compose. Harmonogram i pętla rejestru mają
jedną replikę i `Recreate`: dwa harmonogramy liczyłyby te same zadania dwa razy, a dwie pętle dzieliłyby limit
20 żądań/s i bezpiecznik bez koordynacji.

**Plik miast i mock w obrazie, nie w ConfigMapie.** W repo dane referencyjne trafiają do podów przez obraz:
`infra/php/search.Dockerfile` kopiuje już `infra/elasticsearch/mappings`. Tak samo dochodzi teraz
`COPY infra/reference /infra/reference` (RegionResolver w projektorze i backfill w harmonogramie) i
`COPY tools/registry-mock /srv/registry-mock` (z wyjątkiem `!tools/registry-mock` w `.dockerignore`). Ścieżki są
te same, pod którymi compose montuje katalogi z repo, więc kod niczego nie rozróżnia. ConfigMap odpadał też
technicznie: `configMapGenerator` nie czyta plików spoza katalogu kustomizacji, a `deploy.sh` kopiuje sam
`deploy/k8s` do katalogu tymczasowego.

**Polityki sieci** — zasada z G2 „kto ma hasło do usługi, ten ma do niej trasę”:

| Klient | Trasy |
|---|---|
| `seller360-consumer` | RabbitMQ 5672, ES 9200, Redis 6379, Postgres 5432 (failure transport), GlitchTip |
| `seller360-scheduler` | ES, Redis, Postgres, `catalog-app:80` (wewnętrzne API), `registry-mock:8080`, GlitchTip |
| `seller360-registry` | ES, Redis, Postgres, `registry-mock:8080`, GlitchTip |
| `search-metrics` | dodatkowo Redis (gauge'e lagu seller-360 z klucza `seller360:lag`, jak w compose) |
| `registry-mock` | nic (nowa polityka `registry-mock-ingress`: przyjmuje tylko od dwóch powyższych) |

`tools/k3s/ckad-check.rb` dostał te trasy w `ALLOWED` i zakazy w `DENIED` (projektor i pętla bez trasy do catalogu,
harmonogram bez RabbitMQ, mock bez ES i Redisa, projektor i search-consumer bez rejestru).

**Konto `catalog_seller_view` na k3s.** ECK tworzy użytkowników z Secretów w `auth.fileRealm`, więc:

- `deploy/k8s/elasticsearch.yaml`: nowy wpis `es-user-catalog-seller-view`;
- `deploy/k8s/config.env`: `ES_CATALOG_SELLER_VIEW_USER=catalog_seller_view`;
- `deploy/k8s/catalog.yaml` (catalog-app): `ELASTICSEARCH_SELLER_VIEW_USER` z ConfigMapy i
  `ELASTICSEARCH_SELLER_VIEW_PASSWORD` z `marketplace-secrets`, **bez `optional`** — jak `INTERNAL_API_TOKEN`: brak
  klucza zatrzymuje poda zamiast dawać ciche 401 (dopisane na końcu listy env, bo overlay G5 łata `env/10`);
- `tools/k3s/secrets.sh`: `: "${ES_CATALOG_SELLER_VIEW_PASSWORD:?…}"` na wejściu (skrypt ma `set -u`, brak zmiennej
  kończy go czytelnym komunikatem z poleceniem dopisania), klucz w `marketplace-secrets` i Secret
  `es-user-catalog-seller-view` (`username=catalog_seller_view`, `roles=seller_stats_view`; nazwa Secretu z `-`
  zamiast `_`, bo nazwy obiektów k8s nie znają podkreślnika);
- `tools/k3d/secrets.sh`: ten sam klucz i Secret z hasłem losowym przy pierwszym uruchomieniu (`keep`);
- `.env.prod.example`: `ES_CATALOG_SELLER_VIEW_PASSWORD=__GENERATE__`.

Role w `deploy/k8s/es-roles.yml` były już zgodne 1:1 z `setup-security.sh` (`seller-sales-*`, `complaints*`,
`courier-ratings` z `read, write, view_index_metadata`, `seller_stats_view` z `read` na `seller-360-public`).

**Overlay G5.** `deploy/overlays/g5-base/delete-unneeded.yaml` kasuje cztery nowe Deploymenty, Service i ServiceAccount
mocka (jak `analytics-consumer`: poza mierzoną ścieżką i bez Redisa w overlayu). Przy okazji `make g5-check` znalazł
lukę z 13a: kolejka `search.seller360` (i `.dlq`) nie miała Shovela migracji — dopisane w
`deploy/k8s/rabbitmq-cluster/migration/shovels.yaml` i w `prune.txt`.

**Compose na VPS.** `compose.prod.yaml` dostał wpisy dla czterech usług (obraz z CI, bez `build:` i bez bind-mountów),
bo `tools/vps/compose-prod-check.sh` (CI) padał od 13a na `build:` i mountach. Produkcją jest k3s; to tylko parytet.

```bash
make k3s-manifests-check     # kustomize, ERROR_TRACKING_PROCESS, GlitchTip, CKAD (ckad-check.rb): OK
make g5-check                # overlaye G5, budżet pamięci, shovele, topology-parity: OK
bash tools/vps/compose-prod-check.sh   # PASS=55 FAIL=0
```

## Krok 15 — wdrożenie na k3s (na papierze)

Niczego tu nie uruchamiałem na klastrze (serwera nie ma). Kolejność wynika z zależności, które wyszły w 13a i 13b.

1. **Sekret na serwerze.** Dopisać hasło do `/opt/marketplace/.env` i uruchomić `tools/k3s/secrets.sh` (jak w jego
   nagłówku). Bez zmiennej skrypt kończy się komunikatem `brak ES_CATALOG_SELLER_VIEW_PASSWORD … (RUNBOOK #148)`.
   ```bash
   echo "ES_CATALOG_SELLER_VIEW_PASSWORD=$(openssl rand -hex 24)" >> /opt/marketplace/.env
   ```
2. **Obraz z CI** z nowym `search.Dockerfile` (plik miast i mock w obrazie) i obraz `rabbitmq` z kolejką
   `search.seller360`, DLQ i polityką `seller360-cap` w `definitions.template.json` (na `rabbitmq-ha` to samo jest
   w `deploy/k8s/rabbitmq-cluster/topology.yaml`).
3. **Setup ES przed procesami seller-360.** Pisarze piszą do aliasów `seller-360-write` / `seller-360-public-write`
   z `require_alias=true`, więc zapis przed setupem dostaje 404 `index_not_found_exception` zamiast **utworzyć zwykły
   indeks o nazwie aliasu** (tak było przed poprawką, RUNBOOK [#150](../RUNBOOK.md#150)). Projektor traktuje ten 404
   jako przejściowy: wiadomość wraca do retry co 30 s bez limitu prób i czeka na setup, zamiast trafić do `failed`.
   Harmonogram i pętla rejestru logują błąd i próbują w kolejnym przebiegu. Kolejność:
   `analytics-setup` (indeks `alerts-analytics`, connectory Kibany) → `seller360-setup` (szablony, skrypty
   `seller360-apply-section` i `seller360-courier-rating`, indeksy z aliasami, `courier-ratings`, enrich
   `seller-by-nip`, pipeline reklamacji, dashboard i reguły Kibany).
   **Otwarta praca:** oba skrypty działają dziś tylko na Compose (`docker compose exec es01 curl …`). Na k3s trzeba
   je uruchomić przez tunel do `marketplace-es-http:9200` i Kibany albo przerobić na Job. Najczystszy wariant
   pierwszego wdrożenia dalej: `replicas: 0` w czterech plikach `seller360-*.yaml` / `registry-mock.yaml`, setup,
   potem `replicas: 1` i ponowny `make k3s-deploy` (bez tego procesy kręcą się w retry i zaśmiecają logi). Zdarzenia `seller.*`/`billing.*`/`offer.*` czekają w tym czasie
   w `search.seller360` (limit 100 tys. z `drop-head`, więc nie w nieskończoność — #139, #143).
4. **`make k3s-deploy tag=<SHA>`.** Krok 1/3 nakłada role (`es-app-roles`) i nowy Secret w `fileRealm` (ECK, potem
   `deploy.sh` czeka na `observedGeneration` i green); krok 2/3 to Job `migrate` — **migracje catalogu przed nowym
   kodem**, w tym kolumna `outbox.version` i tabele Billing (nowy kod publikuje z wersją); krok 3/3 wdraża catalog
   i, tym samym selektorem `component=app,app!=search-consumer`, cztery nowe Deploymenty (jak `analytics-consumer`).
5. **Backfill.** Istniejący sprzedawcy nie mają historycznych zdarzeń, więc read model trzeba zasilić przebudową
   w podzie harmonogramu (nie w trakcie rolloutu: `seller360-scheduler` ma strategię `Recreate`, więc przebudowa
   przerwana restartem poda zostawia podwójny zapis — RUNBOOK [#147](../RUNBOOK.md#147)):
   ```bash
   make k3s k="-n marketplace exec deploy/seller360-scheduler -- php bin/console seller360:rebuild"
   make k3s k="-n marketplace exec deploy/seller360-scheduler -- php bin/console seller360:billing:parity"
   ```
6. **Sprawdzenie.** Logi czterech Deploymentów bez ERROR, `seller360:lag` w harmonogramie, pulpit sprzedawcy pokazuje
   „Twoje statystyki” (nie „chwilowo niedostępne”), `SellerViewRoleTest` przez tunel z `ELASTIC_PASSWORD`.

## Liczby z żywego stosu

| Co | Wartość |
|---|---|
| Fan-out jednego kuriera (pierwsza wersja) | ~5 055 dokumentów w obu indeksach, updated 5052–5055, 1017–1019 ms, conflicts 1–4 |
| Fan-out po poprawce | updated 3595, noops 5056, conflicts **0**, 2300 ms |
| p99 zapytania z DoD w trakcie fan-outu | 18 ms (najgorszy, pierwsza wersja), **5 ms** po poprawce; bez fan-outu 1–3 ms |
| Bootstrap fan-outu (8 kurierów) | 39 992 aktualizacje w 2 376 ms |
| Przebudowa pod ruchem | **23 s**: create 296 ms, dual 2001 ms, backfill 18 423 ms, verify 287 ms, switch 65 ms, enrich 1425 ms |
| Ruch w trakcie | churn 2270 zmian / 130 usunięć (20/s) + 1201 faktur (10/s), 0 błędów |
| Weryfikacja | `_count` 20 061 / 20 061 (main i public), próbka 500, **0 rozjazdów**; billing 20 000 kont, **0 rozjazdów** |
| Prawdziwi sprzedawcy po przebudowie | profil + NIP 61/61 (wcześniej 1/61) |
| Demo DLS/FLS | **17/17** w 26 s (pierwsza wersja 12/12 w 23–25 s), zapas pamięci 3685 MB przy progu 2048 MB |

Więcej: [`POMIARY.md` §17.4–17.6](../POMIARY.md).

## Ograniczenia

Fan-out:

- **Bezczynny przebieg nie jest darmowy.** Przez cofnięcie `since` o 1 s najnowsza ocena wraca co 60 s, więc każdy
  przebieg to `_update_by_query` z samymi noopami na ~5 tys. dokumentów i odświeżenie indeksów.
- **`noops` zawyżone przez ponowienia** (dokumenty poprawione we wcześniejszej próbie liczą się znowu).
- **`--once` w `seller360:courier:poll` nic nie zmienia** (komenda i tak robi jeden przebieg).

Przebudowa:

- **Przebudowa uruchomiona `exec` w podzie harmonogramu ginie razem z podem** (rollout `Recreate`, eviction).
  Zabity proces nie dochodzi do sprzątania, więc `seller360:write-targets` zostaje z dwiema parami, a następna
  przebudowa odmawia startu (RUNBOOK [#147](../RUNBOOK.md#147)). Błąd `POST _aliases` sam w sobie już wyłącza
  podwójny zapis (faza switch jest w bloku sprzątającym).
- **Ścieżka registry bez cache nie nadpisuje kuriera z `courier-ratings`** (przenosi sekcję ze starego indeksu
  z jej oceną).
- **Sekcja registry z cache dostaje `ok`, gdy cache jest młodszy niż 3600 s**, także jeśli trwa awaria rejestru
  (świeży cache ukrywa awarię do następnej synchronizacji).
- **Backfill profilu ma `source_ts` = chwila backfillu**, a billing `last_invoice_id` = null (snapshot API go nie
  ma; pole nieczytane). Przeniesiona sekcja registry dostaje nowe `updated_at` przy starym `source_ts`, więc lag registry jest chwilowo
  większy, do następnej synchronizacji.
- **Weryfikacja `offers`, `sales`, `complaints` tylko po obecności** (krok 7).
- **Sprzedawca z NIP-em bez sekcji registry w starym indeksie** dostanie ją dopiero z pętli rejestru.
- **Stare indeksy zostają bez `--delete-old`** — usunąć ręcznie po akceptacji (`DELETE seller-360-v1,seller-360-public-v1`).
- **`billing:churn` nie ma strażnika środowiska** (wystawia prawdziwe faktury syntetycznym sprzedawcom w każdej bazie).

Widok sprzedawcy:

- **401 (brak hasła lub roli) jest raportowany przy każdym wejściu na pulpit** — głośno w logach i GlitchTipie.
- **„Statystyki chwilowo niedostępne” także przy 404** (sprzedawca bez dokumentu): komunikat nie odróżnia „nie ma
  danych” od „ES nie działa”.
- **`nip` i `profile.status_reason` wypadły z publicznego dokumentu dopiero po przeglądzie gałęzi**
  (`PublicProjection::filterDoc`, filtr `profile`). Dokumenty zapisane wcześniej trzymają stare wartości, dopóki
  zapis tej samej sekcji ich nie nadpisze: skrypt nadpisuje sekcję w całości, więc nowy zapis `profile` usuwa
  `status_reason`, ale pole dokumentu `nip` zostaje, bo zapis publiczny go już nie wysyła. Czyści je dopiero
  przebudowa (`make seller360-rebuild`: nowy indeks z backfillu). `region` i `synthetic` zostają celowo (purge kasuje
  kopię publiczną po `synthetic:true`); odcina je biała lista czytnika.
- **Brak testu 403 na bezpośredni odczyt `seller-360-public-v1`** (rola jest na nazwę aliasu; indeks pod nim jest
  niedostępny, ale test tego nie przypina).

Demo trial: kolejność ról konsultanta B w wyjściu jest kosmetyczna, a wyniki `PUT` ról i użytkowników nie są
asertowane (błąd tworzenia roli wyszedłby dopiero jako FAIL asercji).

k3s:

- **Nie wdrożone**; setup ES (`analytics-setup`, `seller360-setup`) działa tylko na Compose (krok 15).
- **Zapis przed setupem nie tworzy już indeksu, ale też niczego nie zapisuje.** `require_alias=true` (alias
  poznajemy po sufiksie `-write`) daje 404, projektor czeka w retry, a harmonogram i pętla rejestru logują błąd co
  przebieg, aż setup założy aliasy (RUNBOOK [#150](../RUNBOOK.md#150)).

## Co wybuchło po drodze

| RUNBOOK | Objaw |
|---|---|
| [#146](../RUNBOOK.md#146) | fan-out z resztkowymi konfliktami, `seller360:courier:since` stoi |
| [#147](../RUNBOOK.md#147) | podwójny zapis nie wyłączył się po przerwanej przebudowie (`seller360:write-targets`) |
| [#148](../RUNBOOK.md#148) | pulpit sprzedawcy: „Statystyki chwilowo niedostępne” (konto `catalog_seller_view`, 401) |
| [#149](../RUNBOOK.md#149) | `make seller360-fls-demo` odmawia (pamięć) albo zostaje kontener `seller360-trial-*` |
| [#150](../RUNBOOK.md#150) | k3s: zwykły indeks `seller-360-write` zamiast aliasu (procesy przed setupem; zapobiegnięte `require_alias`) |
| [#151](../RUNBOOK.md#151) | wiadomości projektora w `failed` po długiej awarii ES (`messenger:failed:retry`) |

## Ściąga

```bash
# fan-out
docker compose exec -T seller360-scheduler php bin/console seller360:courier:poll --once
docker compose exec search-consumer curl -s -XPOST registry-mock:8080/_chaos -d '{"bump_courier":"courier-3"}'
RUNS=100 COURIER=courier-3 bash tools/seller360/fanout-measure.sh
docker compose logs --since 10m seller360-scheduler | grep -E 'Fan-out ocen kuriera'

# since i lista celów zapisu (odczyt z Redisa przez kontener harmonogramu)
docker compose exec -T seller360-scheduler php -r '$r = new Redis(); $r->connect(getenv("REDIS_HOST")); $r->auth(getenv("REDIS_PASSWORD")); var_dump($r->get("seller360:courier:since"), $r->get("seller360:write-targets"));'

# przebudowa
make seller360-rebuild                       # v<N> -> v<N+1>, stare zostają
make seller360-rebuild args="--delete-old"
make seller360-rebuild-under-load            # z ruchem i zgodnością billingu
docker compose exec -T seller360-scheduler php bin/console seller360:billing:parity
docker compose exec -T search-consumer sh -c 'curl -s -u "$ELASTICSEARCH_USER:$ELASTICSEARCH_PASSWORD" "$ELASTICSEARCH_HOST/_cat/aliases/seller-360*?v"'

# widok sprzedawcy
grep -q '^ES_CATALOG_SELLER_VIEW_PASSWORD=.\+' .env || echo "ES_CATALOG_SELLER_VIEW_PASSWORD=$(openssl rand -hex 24)" >> .env
docker compose run --rm es-setup && docker compose up -d --force-recreate catalog-app catalog-worker
docker compose exec -e ELASTIC_PASSWORD="$(grep '^ELASTIC_PASSWORD=' .env | cut -d= -f2-)" search-consumer \
  php bin/phpunit tests/Integration/Seller360/SellerViewRoleTest.php

# DLS/FLS na trialu
make seller360-fls-demo
docker ps --filter name=seller360-trial      # po skrypcie: pusto

# k3s bez klastra
make k3s-manifests-check && make g5-check && bash tools/vps/compose-prod-check.sh
```
