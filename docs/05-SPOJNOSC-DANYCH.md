# 05 — Spójność danych między aplikacjami

> „Wysyłka krytycznych danych, tak żeby mieć i w jednej, i w drugiej aplikacji spójność."

To jest najtrudniejszy problem w całym projekcie — trudniejszy niż sam Elasticsearch.
I jednocześnie ta wiedza jest najbardziej przenośna: dotyczy każdego systemu rozproszonego,
niezależnie od technologii.

Ten dokument jest kontraktem jakościowym projektu: **opisuje wszystkie sposoby, na jakie
dane mogą się rozjechać, i konkretną obronę przed każdym z nich.**

---

## 1. Najpierw prawda, która boli: spójności „jednoczesnej" NIE da się osiągnąć

Masz dwa systemy: Postgres (Laravel) i Elasticsearch (Symfony). Nie ma między nimi
transakcji rozproszonej. Nie ma commita, który obejmie oba. Więc:

**Nie da się mieć momentu, w którym oba systemy są identyczne. Nigdy.**
Zawsze istnieje okno czasu, w którym Postgres wie o zmianie, a ES jeszcze nie.

Próba obejścia tego (2PC, XA) jest w praktyce martwa — blokuje zasoby, nie skaluje się
i i tak ma przypadki brzegowe. Wszystkie duże firmy zaakceptowały ten fakt.

Więc pytanie nie brzmi „jak zapewnić spójność", tylko:

> **„Jaki jest gwarantowany czas zbieżności (convergence) i jak udowodnić, że dane
> zawsze do siebie dochodzą, nigdy trwale się nie rozjeżdżają?"**

To nazywa się **eventual consistency** i to jest realny cel. Definiujemy go liczbowo:

### SLO spójności w naszym projekcie

| Klasa danych | Docelowe okno zbieżności | Zachowanie przy przekroczeniu |
|---|---|---|
| Cena, stan magazynowy (**krytyczne**) | p99 < 2 s | alert + weryfikacja przy transakcji (sekcja 8) |
| Nazwa, opis, atrybuty produktu | p99 < 10 s | alert |
| Dane sprzedawcy, rating | p99 < 60 s | log |
| Agregaty analityczne (`seller-360`) | p99 < 5 min | dashboard |
| **Trwały rozjazd (dowolna klasa)** | **0** | to jest błąd krytyczny, nie do zaakceptowania |

Ostatni wiersz jest sednem: **opóźnienie jest OK, trwała różnica nie jest.**
Cała reszta dokumentu służy zagwarantowaniu tego wiersza.

---

## 2. Antywzorzec, od którego zaczyna 90 % programistów

```php
// ❌ NIGDY TAK NIE RÓB — "dual write"
DB::transaction(function () use ($offer) {
    $offer->save();
    $this->elasticsearch->index([...]);   // ← tu jest bomba
});
```

Co jest złego:
1. **ES nie uczestniczy w transakcji.** Jeśli transakcja SQL zrobi rollback po udanym
   zapisie do ES — ES ma dane, których nie ma w bazie. Nie do cofnięcia.
2. **Jeśli ES padnie — użytkownik nie może zapisać oferty.** Wyszukiwarka staje się
   krytyczną zależnością zapisu. Awaria komponentu pomocniczego zatrzymuje sprzedaż.
3. **Latencja użytkownika rośnie** o czas zapisu do ES.
4. **Brak retry.** Jeden timeout = trwale zgubiona zmiana, o której nikt się nie dowie.
5. **Brak kolejności** przy równoległych requestach — dwa zapisy tej samej oferty mogą
   trafić do ES w odwrotnej kolejności.

Punkt 5 jest szczególnie podstępny, bo objawia się losowo raz na tysiąc requestów i
„nie da się odtworzyć".

**Wniosek:** zapis do ES nigdy nie odbywa się w cyklu żądania HTTP użytkownika.

---

## 3. Pięć trybów awarii i obrona przed każdym

Cała ścieżka danych ma pięć miejsc, w których zmiana może zginąć lub się zdublować.
Każde wymaga innego mechanizmu. **To jest checklista, do której będziesz wracał.**

```
  [1]           [2]              [3]            [4]           [5]
Laravel ──► publikacja ──► RabbitMQ ──► konsumpcja ──► zapis do ES
  DB          do brokera      broker       Symfony        kolejność
```

### [1] Zgubienie przy publikacji → **Transactional Outbox**

**Scenariusz:** `COMMIT` w Postgresie się udał, aplikacja pada 5 ms później, przed
`basic_publish`. Zmiana istnieje w bazie, event nigdy nie powstał. **Rozjazd na zawsze** —
nikt się nie dowie, bo nie ma śladu po brakującym evencie.

**Obrona:** event zapisujemy do tabeli `outbox` **w tej samej transakcji SQL** co dane.
Skoro to jedna transakcja — albo są oba, albo żadne. Osobny proces publikuje z outboxu.

```sql
BEGIN;
  UPDATE offers SET price_cents = 289900, version = version + 1 WHERE id = 9312;
  INSERT INTO outbox (event_id, aggregate_type, aggregate_id, event_type,
                      payload, sequence, occurred_at)
       VALUES ('01J8Z...', 'offer', 9312, 'offer.price_changed', '{...}', 48, now());
COMMIT;
```

Proces publikujący (`catalog-outbox`):
```sql
SELECT * FROM outbox
 WHERE published_at IS NULL
 ORDER BY id
 LIMIT 500
   FOR UPDATE SKIP LOCKED;     -- pozwala uruchomić kilka publikatorów równolegle
```
→ `basic_publish` → **czekaj na publisher confirm** → `UPDATE outbox SET published_at = now()`.

**Kluczowa kolejność:** najpierw confirm od brokera, potem oznaczenie jako opublikowane.
Odwrotnie = możliwość zgubienia. Tak jak jest = możliwość duplikatu (akceptowalne,
patrz [4]).

**Wariant enterprise (wspomnimy, nie wdrażamy):** CDC przez **Debezium** — czyta WAL
Postgresa i publikuje zmiany bez tabeli outbox. Zaleta: zero kodu w aplikacji.
Wada: kontraktem staje się schemat bazy, potrzebny Kafka Connect.

**Bonus, który dostajesz gratis:** outbox to **dziennik zdarzeń**. Trzymając wiersze
30 dni, możesz w każdej chwili odtworzyć strumień (replay) — np. po tym, jak przez tydzień
konsument źle liczył pole. To ratuje życie częściej, niż myślisz.

### [2] Zgubienie w brokerze → **durable + persistent + confirms**

Trzy niezależne ustawienia, wszystkie potrzebne. Brak któregokolwiek = możliwa utrata:

| Ustawienie | Gdzie | Co gwarantuje | Bez tego |
|---|---|---|---|
| `durable: true` | deklaracja exchange i queue | struktury przeżyją restart brokera | kolejka znika po restarcie **razem z wiadomościami** |
| `delivery_mode: 2` | każda wiadomość | wiadomość zapisana na dysk | wiadomość żyje tylko w RAM |
| **publisher confirms** | kanał (`confirm_select`) | broker potwierdził przyjęcie | `basic_publish` jest „fire and forget" — nie wiesz, czy doszło |
| **quorum queues** | typ kolejki | replikacja Raft między node'ami | pojedynczy node = pojedynczy punkt awarii |

⚠️ Najczęstsze nieporozumienie: `durable` dotyczy **kolejki**, `persistent` dotyczy
**wiadomości**. Durable queue z non-persistent messages traci wiadomości przy restarcie.

### [3] Zgubienie przy konsumpcji → **ack dopiero po sukcesie**

**Scenariusz:** konsument pobiera wiadomość, potwierdza ją (`ack`), zaczyna przetwarzać,
pada. Wiadomość zniknęła z kolejki, praca nie została wykonana.

**Obrona:** `auto_ack: false` + ręczny `ack` **po** potwierdzonym zapisie do ES.
Padnięcie przed `ack` → RabbitMQ zwraca wiadomość do kolejki (redelivery) → przetwarzana
ponownie. Stąd duplikaty → patrz [4].

**Niuans przy batchowaniu (ważny!):** skoro indeksujemy przez `_bulk` w paczkach po 1000,
to trzymamy 1000 nieacknowledgowanych wiadomości i acknowledgujemy je dopiero po
udanym bulku. Trzeba:
- ustawić `prefetch` ≥ rozmiar batcha (inaczej zakleszczenie: czekasz na 1000 wiadomości,
  a broker wysłał Ci 100 i czeka na acki),
- mieć **timeout batcha** (nie czekaj w nieskończoność na 1000. wiadomość — flush co 200 ms),
- obsłużyć **częściowy sukces bulka** — o tym niżej, bo to pułapka.

### [4] Duplikaty → **idempotencja**

RabbitMQ gwarantuje **at-least-once**. Duplikaty nie są możliwością — są **pewnością**.
Wystąpią przy: redelivery po padzie konsumenta, retry publikatora, ponownym starcie po awarii.

„Exactly-once delivery" **nie istnieje** w systemach rozproszonych. Osiąga się
**effectively-once processing** = at-least-once + idempotentny konsument.

Dwa poziomy obrony:

**a) Idempotentna operacja (najlepsze — nie wymaga stanu):**
```
PUT products/_doc/118        ← ID dokumentu = ID encji
```
Wykonane 5 razy daje ten sam wynik. Dlatego **nigdy** nie używamy `POST /_doc`
(auto-generowane ID) dla danych domenowych — to zrobiłoby 5 kopii produktu.

**b) Deduplikacja po `event_id`** — potrzebna tam, gdzie operacja ma **skutki uboczne**
(wysłanie maila o alercie, inkrementacja licznika):
```sql
CREATE TABLE processed_events (
  event_id   TEXT PRIMARY KEY,
  handler    TEXT NOT NULL,
  processed_at TIMESTAMPTZ DEFAULT now()
);
-- INSERT ... ON CONFLICT DO NOTHING → jeśli 0 wierszy, to duplikat, pomiń
```
Klucz złożony `(event_id, handler)`, bo różni konsumenci przetwarzają ten sam event.
Czyszczenie po 7 dniach (dłużej niż maksymalny czas retry).

### [5] Zła kolejność → **wersjonowanie zewnętrzne**

**To jest najbardziej podstępny tryb awarii.** Objawia się jako „cena wróciła do starej".

**Scenariusz:** cena zmienia się dwa razy w ciągu sekundy: 300 → 280 → 250.
Trzech konsumentów działa równolegle. Konsument obsługujący „→ 250" trafia na chwilowy
timeout ES, ponawia po 2 s. W międzyczasie „→ 280" zdąża się zapisać.
**Efekt końcowy w ES: 280.** Prawidłowa wartość: 250. Rozjazd trwały, cichy.

Dlaczego to się dzieje: RabbitMQ gwarantuje kolejność **tylko w obrębie jednej kolejki
z jednym konsumentem**. Skalując konsumentów do N, świadomie rezygnujesz z kolejności.

**Obrona główna — external versioning w ES:**

Każdy agregat ma monotoniczny licznik (`version` w tabeli, inkrementowany przy każdej zmianie),
przenoszony w evencie jako `sequence`. Przy zapisie:

```
PUT products/_doc/118?version=48&version_type=external
```

Elasticsearch **odrzuci** zapis z wersją niższą lub równą już zapisanej —
`409 version_conflict_engine_exception`.

> **Zapamiętaj: ten błąd 409 to sukces, nie awaria.** To dowód, że mechanizm zadziałał
> i uchronił Cię przed cofnięciem danych. Logujemy go jako `debug`, ackujemy wiadomość
> i idziemy dalej. Traktowanie go jako błędu (i retry!) to klasyczna pomyłka —
> wpadasz w nieskończoną pętlę.

Ta obrona jest **odporna na dowolną kolejność** i nie wymaga koordynacji. Dlatego jest
podstawowa.

**Obrona uzupełniająca — partycjonowanie (gdy kolejność jest naprawdę wymagana):**
`rabbitmq_consistent_hash_exchange` kieruje wszystkie zdarzenia tego samego agregatu
(hash z `aggregate_id`) zawsze do tej samej kolejki → jeden konsument → kolejność zachowana.
To odpowiednik partycji w Kafce.
Koszt: gorsze rozłożenie obciążenia, trudniejsze skalowanie, „gorąca partycja" przy
nierównomiernym ruchu. **Wdrożymy to jako ćwiczenie porównawcze**, ale głównym mechanizmem
zostaje wersjonowanie.

---

## 4. Pułapka, o której nie wspomina żaden tutorial: częściowy sukces `_bulk`

```
HTTP 200 OK
{ "errors": true, "items": [ ...997 ok..., {"index": {"status": 400, "error": {...}}} ] }
```

**Bulk zwraca 200 nawet wtedy, gdy część dokumentów się nie zapisała.** Kod, który
sprawdza tylko status HTTP, **cicho gubi dane**. To jest jeden z najczęstszych realnych
powodów rozjazdu ES z bazą.

Obowiązkowa obsługa w handlerze:
```
for each item in response.items:
    if status in (200, 201):            → ack
    elif status == 409 (version conflict) → ack   (świadome odrzucenie, patrz [5])
    elif status == 429 (rejected)         → nack + retry z backoffem
    elif status in (400, 422) (mapping)   → NIE retry → DLQ + alert (błąd kontraktu)
    elif status >= 500                    → nack + retry
```

Zauważ: **acki są per wiadomość, a nie per batch**. Jedna wiadomość z błędnym mapowaniem
nie może zablokować 999 poprawnych.

---

## 5. DLQ to dziura w spójności — musi mieć właściciela i drogę powrotną

Wiadomość w Dead Letter Queue oznacza: **ta zmiana nigdy nie dotarła do ES**.
To jest trwały rozjazd — czyli dokładnie to, na co się nie zgadzamy w SLO z sekcji 1.

Dlatego DLQ nie jest „koszem", tylko kolejką roboczą z procedurą:

1. **Monitoring**: niepusta DLQ = alert. Zawsze. Nie „gdy przekroczy 100".
2. **Klasyfikacja błędu** przy trafieniu do DLQ — zapisujemy powód:
   błąd kontraktu / błąd mapowania / brakująca zależność / bug w kodzie.
3. **Droga powrotna**: komenda `search:dlq:replay --queue=... --since=...`, która po
   naprawie przyczyny wraca wiadomości do normalnej kolejki. Bez tego DLQ jest cmentarzem.
4. **Rozróżnienie błędów w kodzie** (Symfony Messenger):
   - `RecoverableMessageHandlingException` → ES zwrócił 503/429, sieć padła → retry ma sens
   - `UnrecoverableMessageHandlingException` → mapping error, zły format → retry nic nie da,
     do DLQ **natychmiast**, nie po 3 próbach
   
   Retry na błędzie nienaprawialnym to marnowanie czasu i zaciemnianie obrazu.
5. **Poison message** — wiadomość, która wywala konsumenta (np. OOM przy ogromnym payloadzie).
   Bez limitu prób zapętla się w nieskończoność i **zatrzymuje całą kolejkę**.
   Obrona: licznik `x-death`, twardy limit, potem DLQ.

---

## 6. Wykrywanie rozjazdu — bo obrona nigdy nie jest w 100 % skuteczna

Wszystko powyżej to prewencja. Ale system produkcyjny zakłada, że prewencja czasem zawiedzie
(bug w kodzie, ręczny `UPDATE` na bazie przez kogoś z zespołu, przywrócenie backupu Postgresa,
migracja, która ominęła outbox). Dlatego potrzebny jest **niezależny mechanizm wykrywania**.

To się nazywa **anti-entropy** / **reconciliation** i jest to element odróżniający system
produkcyjny od projektu studenckiego.

### Trzy poziomy weryfikacji (od taniego do dokładnego)

**Poziom 1 — porównanie liczności (co 5 min, bardzo tanie)**
```
SELECT count(*) FROM offers WHERE active = true;      -- Postgres
GET offers-search/_count                               -- ES
```
Wykrywa duże rozjazdy. Nie wykrywa różnic w treści. Uwaga na okno czasowe —
porównuj stan sprzed 60 s, żeby nie łapać normalnego opóźnienia indeksacji.

**Poziom 2 — porównanie po oknach czasowych (co godzinę)**
Dla każdej godziny z ostatniej doby porównaj liczbę encji o `updated_at` w tym oknie
z liczbą dokumentów o tym samym `updated_at` w ES. Rozjazd wskazuje **konkretne okno**,
w którym coś się zgubiło — od razu wiesz, gdzie szukać w logach.

**Poziom 3 — porównanie sum kontrolnych (co dobę, w tle, porcjami)**
```
Postgres:  SELECT id, version, md5(row_to_json(o)::text) FROM offers ORDER BY id
ES:        PIT + search_after po tym samym polu, pobierz id + version + checksum
```
Idziesz strumieniem po obu stronach, porównujesz. Wynik: dokładna lista rozjechanych ID.
Dla nich publikujesz zdarzenia naprawcze (`offer.resync`) → system sam się leczy.

To jest dokładnie to, co robią duże firmy, i to jest najmocniejsza gwarancja: **nawet jeśli
coś się zgubi, w ciągu 24 h zostanie wykryte i naprawione automatycznie.**

### Metryka świeżości (lag) — mierzymy stale

Każdy dokument dostaje `indexed_at`. Event ma `occurred_at`. Różnica = lag.
Publikujemy histogram do ES (`metrics-sync-lag`) i mamy na dashboardzie p50/p95/p99
per typ zdarzenia. Alert, gdy p99 przekroczy SLO z sekcji 1.

Dodatkowo: **głębokość kolejki** (`messages_ready` w RabbitMQ) jest wskaźnikiem
wyprzedzającym — rośnie, zanim lag stanie się widoczny.

---

## 7. Backfill i pełna odbudowa — ostateczna gwarancja

Zasada nadrzędna projektu:

> **W każdej chwili musi być możliwe odbudowanie całego Elasticsearcha od zera
> z Postgresa, bez utraty czegokolwiek.**

Jeśli to jest prawda, to żaden rozjazd nie jest katastrofą — jest niedogodnością.
Dlatego `make reindex` jest obywatelem pierwszej kategorii, a nie skryptem awaryjnym.

Trudność, którą trzeba rozwiązać: **backfill i strumień na żywo działają jednocześnie.**
Podczas 40-minutowego reindeksu przychodzą nowe zdarzenia. Bez ochrony backfill (czytający
starszy stan) nadpisze świeższe dane ze strumienia.

**Rozwiązanie: to samo wersjonowanie zewnętrzne.** Backfill zapisuje z wersją encji z bazy;
strumień z wersją z eventu. ES zawsze zachowa wyższą. Konflikty 409 podczas backfillu są
**oczekiwane i pożądane** — to znaczy, że strumień wyprzedził backfill.

To bardzo elegancki efekt uboczny: jeden mechanizm rozwiązuje trzy problemy naraz
(kolejność, duplikaty, kolizja backfill/stream).

---

## 8. Dane krytyczne: cena i stan magazynowy — wzorzec, który stosuje cały e-commerce

Pytałeś o „wysyłkę krytycznych danych". Tu jest odpowiedź, którą stosują wszystkie sklepy:

> **Wyszukiwarka może pokazywać dane nieaktualne o sekundy. Transakcja — nigdy.**

Podział na dwie ścieżki:

| Ścieżka | Źródło danych | Spójność |
|---|---|---|
| Lista wyników, facety, sortowanie po cenie | **Elasticsearch** | eventual, p99 < 2 s |
| Karta produktu (cena, dostępność) | **Postgres** | silna |
| Dodanie do koszyka | **Postgres** + walidacja | silna |
| Złożenie zamówienia | **Postgres** + blokada stanu w transakcji | silna, `SELECT ... FOR UPDATE` |

Czyli: ES odpowiada na pytanie **„które produkty?"**, Postgres na **„po ile dokładnie
i czy na pewno jest?"**.

Konsekwencja w UI, którą świadomie zaprojektujemy: użytkownik może zobaczyć na liście
cenę 289 zł, a na karcie produktu 299 zł, bo cena zmieniła się sekundę temu.
Sklepy rozwiązują to komunikatem „cena uległa zmianie" przy dodawaniu do koszyka.
**To nie jest bug — to jest świadomy kompromis architektoniczny** i będziesz umiał go obronić.

Dla stanu magazynowego dodatkowo: w ES trzymamy `in_stock` (boolean) zamiast dokładnej
liczby sztuk. Boolean zmienia się rzadko, więc rozjazd jest mniej prawdopodobny i mniej
szkodliwy niż przy liczbie „zostały 2 sztuki".
**To jest przykład na to, że modelowanie dokumentu jest decyzją o spójności, a nie tylko
o wyszukiwaniu.**

---

## 9. Wersjonowanie kontraktu — bo deploy nie może wysypać wszystkiego do DLQ

Sytuacja: dodajesz do eventu wymagane pole `currency`. Deployujesz Laravela.
Symfony (jeszcze stary) nie zna pola — walidacja odrzuca, **wszystko leci do DLQ**.

Zasady, których się trzymamy:
1. **Zmiany kompatybilne wstecz domyślnie**: nowe pola opcjonalne, nigdy nie usuwamy
   ani nie zmieniamy znaczenia istniejących.
2. **Konsument ignoruje nieznane pola** (tolerant reader). Nigdy „strict" na wejściu.
3. **Zmiana niekompatybilna = nowy `type` lub `version`**: `offer.price_changed` v2
   obok v1; konsument obsługuje oba przez okres przejściowy.
4. **Kolejność deployu**: najpierw konsument (umie nowe i stare), potem producent.
5. **JSON Schema w repo** + test kontraktowy po obu stronach — CI wyłapie złamanie.

---

## 10. Testowanie spójności: chaos, nie happy path

Testy jednostkowe niczego tu nie udowodnią. Potrzebne są testy, które **psują system
celowo**. Każdy z nich musi zakończyć się stwierdzeniem: *dane się zbiegły, rozjazdu brak*.

| Test | Jak wywołać | Oczekiwany wynik |
|---|---|---|
| Pad aplikacji po commicie | `kill -9` między COMMIT a publish | outbox publikuje po restarcie, zero strat |
| Pad brokera | `docker kill rabbitmq` w trakcie ruchu | po starcie wiadomości persistent są; brakujące dopublikowane z outboxu |
| Pad konsumenta w połowie batcha | `kill -9` konsumenta przy 500/1000 | redelivery, duplikaty odrzucone przez wersjonowanie |
| Duplikaty | ręcznie opublikuj ten sam event 3× | dokument identyczny, mail wysłany raz |
| Zła kolejność | opublikuj sequence 50, potem 49 | ES zostaje przy 50, 409 w logach |
| ES niedostępny 5 min | `docker stop elasticsearch` | kolejka rośnie, po starcie nadrabia, zero strat, apka nadal sprzedaje |
| Poison message | wyślij event z payloadem 50 MB | trafia do DLQ po N próbach, kolejka nie stoi |
| Ręczna zmiana w bazie (omija outbox) | `UPDATE offers SET price=1` w psql | **reconciliation wykrywa w ciągu doby i naprawia** |
| Backfill równolegle ze strumieniem | `make reindex` przy 1000 ev/s | zero cofniętych wartości, konflikty 409 obecne |
| Zmiana kontraktu | deploy producenta przed konsumentem | brak lawiny w DLQ (tolerant reader) |

Ostatnia kolumna ostatniego wiersza to powód, dla którego robimy to wszystko.

---

## 11. Podsumowanie: architektura spójności w jednym obrazku

```
Laravel                                                          Symfony
┌────────────────────────┐                                ┌──────────────────────────┐
│ TRANSAKCJA SQL         │                                │ konsument                │
│  UPDATE offers  ───┐   │                                │  ├ dedup (event_id)      │
│  INSERT outbox  ───┴───┼──[1] atomowo                   │  ├ batch + timeout       │
└────────────────────────┘                                │  ├ _bulk                 │
           │                                              │  ├ per-item error check  │──[4]
           ▼                                              │  ├ ack PO sukcesie       │──[3]
┌────────────────────────┐   [2] durable+persistent       │  └ 409 = OK, nie błąd    │──[5]
│ publikator outboxu     │──────► RabbitMQ ───────────────►                          │
│  FOR UPDATE SKIP LOCKED│        + confirms              └──────────┬───────────────┘
│  confirm → published_at│        + DLX/retry                        │
└────────────────────────┘                                          ▼
                                                        PUT /_doc/{id}?version_type=external
           ▲                                                        │
           │                                                        ▼
┌──────────┴─────────────────────────────────────────┐      ┌───────────────┐
│ RECONCILIATION (anti-entropy)                      │◄─────┤ Elasticsearch │
│  poziom 1: liczności (5 min)                       │      └───────────────┘
│  poziom 2: okna czasowe (1 h)                      │
│  poziom 3: sumy kontrolne (24 h) → resync          │      + metryka lag (indexed_at − occurred_at)
└────────────────────────────────────────────────────┘      + głębokość kolejki
```

Sześć warstw obrony, każda na inny tryb awarii, plus niezależna weryfikacja na końcu.
**To jest realny standard produkcyjny** — nie akademickie ozdobniki.

---

## 12. Pytania kontrolne

1. Dlaczego nie można zapisać do Postgresa i ES w jednej transakcji? Co robimy zamiast?
2. Co gwarantuje `durable`, co `delivery_mode: 2`, a co publisher confirms? Co się stanie
   przy braku każdego z nich osobno?
3. Bulk zwrócił HTTP 200. Dlaczego to nie znaczy, że dane są zapisane?
4. Dlaczego `409 version_conflict` bywa dobrą wiadomością? Kiedy jest złą?
5. Trzech konsumentów przetwarza zdarzenia tej samej oferty. Jak zapewniasz, że końcowa
   cena będzie poprawna, skoro kolejność nie jest gwarantowana? Podaj dwa rozwiązania.
6. Ktoś zrobił `UPDATE` bezpośrednio w bazie, z pominięciem aplikacji. Jak i kiedy
   system się o tym dowie?
7. Użytkownik widzi na liście 289 zł, a na karcie 299 zł. Bug czy nie? Uzasadnij.
8. ES jest niedostępny od 10 minut. Co widzi użytkownik? Co się dzieje z danymi?
9. Jak przeprowadzisz backfill 5 mln dokumentów, nie nadpisując świeższych danych
   przychodzących w tym czasie ze strumienia?
10. Dodajesz wymagane pole do eventu. Jaka jest bezpieczna kolejność deployu i dlaczego?
