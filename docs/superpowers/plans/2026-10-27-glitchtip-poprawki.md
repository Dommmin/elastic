# GlitchTip: plan poprawy po wdrożeniu na VPS

> Stan wyjściowy: GlitchTip działa na VPS (Compose, 2026-10-26), `main` = `c9ec8f1` + PR #7
> (scheduler z obrazu CI). Poniżej to, co zostało po ETAPIE 11b: błędy znalezione przy
> wdrożeniu, niezweryfikowane założenia i odłożone drobiazgi. Kolejność = priorytet.

## Spec: `docs/superpowers/specs/2026-10-26-glitchtip-design.md`

## Zasady
- Każda poprawka kodu: test, który najpierw pada (RED), potem zielony; na koniec pełny zestaw
  (`tools/vps/compose-prod-check.sh`, Pest, PHPUnit, `node --test`).
- Osobna gałąź i PR na grupę; nic na serwerze bez jawnej zgody.

## Grupa A: operacje na VPS (bez zmian w kodzie)

### A1. Weryfikacja end-to-end na serwerze (wstrzymane: GlitchTip na VPS jest zatrzymany razem z Compose)
Teraz sprawdzono tylko healthcheck, bootstrap i pamięć (214 MiB z 512).
- [ ] `make vps-tunnel`, logowanie do `http://localhost:28000` (admin z `make vps-secret`).
- [ ] Wywołać błąd w prod przez tunel `/_errors` (kopertą z przeglądarki) i zobaczyć issue w projekcie `catalog-frontend`.
- [ ] Sprawdzić, że w issue jest `release` = SHA wdrożonego obrazu i brak danych osobowych.
- [ ] Mail o nowym issue widoczny w mailpicie (`http://localhost:28025`).

### A2. Klaster ES: kto zatrzymał es02/es03? (ZROBIONE 2026-10-27)
Ustalone: Compose zatrzymano celowo 11.10 (`docker compose stop`) przy przejściu na k3s (ETAP D2);
`unless-stopped` nie wznawia ręcznie zatrzymanych kontenerów. Wdrożenie z 26.10 przywróciło Compose
obok k3s: 20,4 z 24 GB RAM zajęte, timer `marketplace-pg-backup` włączony ponownie.
Decyzja: **k3s jest produkcją**. Wykonano `docker compose stop` (wolumeny zostają) oraz
`systemctl disable --now marketplace-pg-backup.timer`; zajęte 11 z 24 GB, k3s bez zmian.
- [x] Przyczyna ustalona, stan serwera naprawiony.
- [ ] **Nie uruchamiać `make prod-deploy`**, dopóki GlitchTip nie ma manifestów k3s: `deploy.sh` rusza Compose
      i włącza timer backupu przy każdym wdrożeniu. Docelowo `deploy.sh` ma odmawiać, gdy działa k3s.
- [ ] `deploy.sh`: pierwszy `up` rusza tylko `es01` bez `es02`/`es03`, co przy zatrzymanych węzłach zakleszcza deploy;
      dodać węzły klastra do pierwszego kroku (gdy `.env` ma profil `cluster`).
- [ ] GlitchTip na k3s (dawne E4) staje się następnym krokiem, bo Compose na VPS jest wyłączony.

## Grupa B: luki, które ujawnił deploy

### B1. Test, że nakładka prod nie buduje żadnej usługi (regresja #7)
`compose-prod-check.sh` już to sprawdza, ale dopiero od #7 przechodzi. Upewnić się, że CI go uruchamia
na każdym PR zmieniającym `compose*.yaml` (dziś jest tylko lokalnie).
- [ ] Dodać job w workflow (path filter `compose*.yaml`, `infra/**`, `tools/vps/**`).
- [ ] Test: dodanie usługi z `build:` bez `image:` w overlay ma wywalić job.

### B2. RUNBOOK #044 i komentarz o schedulerze
Komentarz „osobna sprawa” już usunięty w #7; dopisać w RUNBOOK wpis o deployu, który padał na buildzie.

## Grupa C: korekty dokumentacji (prawda o zachowaniu)

### C1. ADR-0009 (linia ~131) i RUNBOOK #073: crash konsumenta
Dokumenty mówią, że crash samego konsumenta Messengera nie trafia do GlitchTipa. Sprawdzić
w kodzie i smoke, co jest prawdą, i poprawić dokument albo kod.
- [ ] Test: `search-consumer` kończy się nieobsłużonym wyjątkiem poza handlerem; sprawdzić, czy zdarzenie dociera.
- [ ] Dostosować ADR i RUNBOOK do wyniku (bez zgadywania).

### C2. Nieaktualne komentarze
- [ ] `compose.yaml` i `apps/catalog/config/sentry.php`: komentarze opisujące stan sprzed zmian w 11b.

## Grupa D: odłożone drobiazgi z przeglądu (z oceną skutku)

| # | Co | Skutek, gdy zostanie | Test najpierw |
|---|----|----------------------|---------------|
| D1 | `trap` w `tools/errors-smoke.sh` zostawia GlitchTip spauzowany po przerwaniu (Ctrl-C) | kolejne uruchomienie smoke’a wisi, UI nie odpowiada | skrypt przerwany w połowie fazy pauzy → `docker inspect` nie pokazuje `Paused` |
| D2 | `outbox-publisher --loop` nie dodaje breadcrumbów | uboższy kontekst w issue z długo żyjącego procesu | test jednostkowy EnrichEvent dla `process=outbox` |
| D3 | Przekroczenie czasu joba (SIGALRM) nie jest zgłaszane | job zabity po timeoucie znika bez issue | Pest: job z timeoutem → fake transport dostaje zdarzenie |
| D4 | CI: `sentry-cli sourcemaps inject` brak w etapie assets obrazu | mapy z CI nie mają Debug ID, stack trace w prod bez źródeł | `tools/vps/prod-image-check.sh`: mapy w `/sourcemaps` zawierają `debugId` |

## Grupa E: przed wystawieniem poza tunel SSH
- [ ] Zmierzyć throttling GlitchTipa (limity zdarzeń na projekt) i ustawić świadomie.
- [ ] Retencja i limit rozmiaru bazy GlitchTipa (dziś nieograniczone); alert na wolne miejsce.
- [ ] Korelacja `trace.id` z Elasticsearch: uruchomić smoke z profilem `obs` i potwierdzić w Kibanie.
- [ ] GlitchTip na k3s (osobny etap, wymaga decyzji o storage dla Postgresa).

## Definicja ukończenia
Commit w repo, wpis w `docs/blog/` (przewodnik krok po kroku), zielone zestawy testów,
a zmiany na serwerze opisane w RUNBOOK z poleceniami weryfikacji.
