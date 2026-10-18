#!/usr/bin/env bash
# ============================================================================
#  Obserwowalność, część Elasticsearch (kontener jednorazowy `obs-setup`)
#
#  CO ROBI (kolejność ma znaczenie — szablon odwołuje się do komponentów,
#  ILM i pipeline'u, a PUT szablonu waliduje, że istnieją):
#   1. komponenty mapowań i ustawień   (logs-app@mappings, logs-app@settings)
#   2. polityki ILM                    (logs-app, logs-ilmdemo)
#   3. pipeline ingest `logs-app`      (surowa linia JSON -> ECS)
#   4. szablony data streamów          (logs-app-*, logs-ilmdemo-*)
#   5. rola i użytkownik filebeat_writer, hasło remote_monitoring_user
#   6. data view `logs-app` w Kibanie
#   7. asercje: _simulate na próbkach z samples/ — kod != 0, gdy cokolwiek nie gra
#
#  Skrypt jest idempotentny: PUT nadpisuje definicje, a data view tworzymy
#  z `override: true`.
# ============================================================================
set -euo pipefail

ES="${ES_URL:-http://es01:9200}"
KIBANA="${KIBANA_URL:-http://kibana:5601}"
AUTH="elastic:${ELASTIC_PASSWORD}"
DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

log() { echo "[obs-setup] $*"; }

# put <ścieżka API> <plik JSON> — PUT z pliku. Przy błędzie ES pokazujemy jego
# treść (samo `curl -f` zgubiłoby powód) i przerywamy skrypt.
put() {
  local out
  if ! out="$(curl -sS -f -u "${AUTH}" -X PUT "${ES}/$1" \
        -H 'Content-Type: application/json' --data-binary "@${DIR}/$2" 2>&1)"; then
    log "BŁĄD: PUT $1 nie powiódł się: ${out}"
    exit 1
  fi
}

# --- czekaj na klaster -------------------------------------------------------
log "Czekam na Elasticsearch pod ${ES}..."
for i in $(seq 1 60); do
  if curl -fsS -u "${AUTH}" "${ES}/_cluster/health" >/dev/null 2>&1; then
    log "Elasticsearch odpowiada."
    break
  fi
  if [ "$i" = "60" ]; then
    log "BŁĄD: Elasticsearch nie odpowiedział w ciągu 60 prób."
    exit 1
  fi
  sleep 2
done

# --- 1. komponenty szablonu --------------------------------------------------
# Mapowania i ustawienia w osobnych komponentach, bo współdzielą je dwa szablony
# (logs-app-* i logs-ilmdemo-*). `labels` i `app.context` są typu `flattened`:
# kontekst logu to dowolny JSON, a zwykłe dynamic mapping zrobiłoby z każdego
# nowego klucza nowe pole i rozdęło mapowanie (limit 1000 pól).
# Ustawienie `index.default_pipeline` (a nie `pipeline:` w Filebeacie) sprawia,
# że ECS powstaje po stronie ES dla KAŻDEGO klienta — także przy ręcznym
# POST-cie z Kibany — i da się go przetestować przez _simulate.
# Polityki ILM NIE ma w komponencie, tylko w szablonie: demo ma inną politykę
# przy tych samych mapowaniach.
log "Komponenty szablonu..."
put "_component_template/logs-app@mappings" component-mappings.json
put "_component_template/logs-app@settings" component-settings.json

# --- 2. ILM ------------------------------------------------------------------
# logs-app: realna polityka (hot 1d -> warm 2d -> delete 7d). Maszyna jest
# współdzielona, 7 dni logów to dziesiątki MB.
# logs-ilmdemo: OSOBNA polityka w skali minut (2m/3m/8m), żeby przewodnik mógł
# na żywo pokazać rollover, warm i delete w `_ilm/explain`, bez czekania dni
# i bez majstrowania przy polityce, której używają prawdziwe logi.
log "Polityki ILM..."
put "_ilm/policy/logs-app" ilm-logs-app.json
put "_ilm/policy/logs-ilmdemo" ilm-logs-ilmdemo.json

# --- 3. pipeline ECS ---------------------------------------------------------
log "Pipeline ingest logs-app..."
put "_ingest/pipeline/logs-app" pipeline-logs-app.json

# --- 4. szablony data streamów -----------------------------------------------
# priority 200: wbudowany szablon `logs` (wzorzec logs-*-*) ma 100 i przy
# równych wzorcach wygrałby, narzucając swój ILM i pipeline. Dwa szablony
# o nakładających się wzorcach i TYM SAMYM priorytecie ES odrzuca, więc
# logs-app-* i logs-ilmdemo-* (rozłączne) mogą mieć oba 200.
log "Szablony data streamów..."
put "_index_template/logs-app" template-logs-app.json
put "_index_template/logs-ilmdemo" template-logs-ilmdemo.json

# --- 5. rola i użytkownicy ---------------------------------------------------
# filebeat_writer: tylko dopisywanie do logs-app-*/logs-ilmdemo-* (create_doc,
# bez index/delete) + auto_configure, żeby data stream założył się przy
# pierwszym dokumencie. Filebeat nie zakłada szablonów ani ILM (robi to ten
# skrypt), więc nie dajemy mu manage_*.
log "Rola i użytkownik filebeat_writer..."
put "_security/role/filebeat_writer" role-filebeat-writer.json
curl -fsS -u "${AUTH}" -X PUT "${ES}/_security/user/filebeat_writer" \
  -H 'Content-Type: application/json' \
  -d "{\"password\":\"${FILEBEAT_WRITER_PASSWORD}\",\"roles\":[\"filebeat_writer\"],\"full_name\":\"Filebeat (logi aplikacji)\"}" >/dev/null

# remote_monitoring_user to konto wbudowane — nie da się go utworzyć, można
# tylko ustawić hasło. Używa go Metricbeat (Stack Monitoring).
log "Hasło remote_monitoring_user..."
curl -fsS -u "${AUTH}" -X POST "${ES}/_security/user/remote_monitoring_user/_password" \
  -H 'Content-Type: application/json' \
  -d "{\"password\":\"${REMOTE_MONITORING_PASSWORD}\"}" >/dev/null

# --- 6. data view w Kibanie --------------------------------------------------
# Stałe id `logs-app` + override:true = idempotentnie (drugi przebieg nadpisuje).
log "Czekam na Kibanę pod ${KIBANA}..."
for i in $(seq 1 60); do
  if curl -fsS -u "${AUTH}" "${KIBANA}/api/status" >/dev/null 2>&1; then break; fi
  if [ "$i" = "60" ]; then log "BŁĄD: Kibana nie odpowiedziała."; exit 1; fi
  sleep 2
done
log "Data view logs-app..."
curl -fsS -u "${AUTH}" -X POST "${KIBANA}/api/data_views/data_view" \
  -H 'Content-Type: application/json' -H 'kbn-xsrf: true' \
  -d '{"override":true,"data_view":{"id":"logs-app","name":"logs-app","title":"logs-app-*","timeFieldName":"@timestamp"}}' >/dev/null


# --- 7. asercje na próbkach (_simulate) --------------------------------------
# Obraz ES nie ma jq, więc asercje to `grep -F` na zwartym JSON-ie. Każda
# asercja prosi _simulate o JEDNO pole (filter_path), dzięki czemu odpowiedź ma
# przewidywalny kształt i nie zależy od kolejności kluczy w dokumencie.
FAILED=0

# check <próbka> <filter_path> <oczekiwany fragment> <opis>
check() {
  local out
  out="$(curl -sS -u "${AUTH}" -X POST \
    "${ES}/_ingest/pipeline/logs-app/_simulate?filter_path=docs.doc._source.$2" \
    -H 'Content-Type: application/json' \
    --data-binary "@${DIR}/samples/$1.json" 2>&1 || true)"
  if printf '%s' "${out}" | grep -qF -- "$3"; then
    log "  OK    $1: $4"
  else
    log "  FAIL  $1: $4 (oczekiwano: $3; jest: ${out})"
    FAILED=$((FAILED + 1))
  fi
}

log "Asercje _simulate..."

# 1. Monolog z catalog: ECS z datetime/level_name/extra.trace_id/context
S=1-monolog-catalog
check $S '@timestamp'        '"@timestamp":"2026-10-17T20:00:00.123'          '@timestamp z datetime (ms; typ date nie trzyma mikrosekund)'
check $S 'trace.id'          '"id":"4bf92f3577b34da6a3ce929d0e0e4736"'        'trace.id z extra.trace_id'
check $S 'log.level'         '"level":"debug"'                                'log.level małymi literami'
check $S 'log.logger'        '"logger":"local"'                               'log.logger z channel'
check $S 'message'           '"message":"Opublikowano zdarzenie do RabbitMQ"' 'message z pola message Monologa'
check $S 'app.context.event_id' '"event_id":"01J8ZK3V0000000000000000"'       'reszta contextu w app.context'
check $S 'service.name'      '"name":"outbox-publisher"'                      'service.name z etykiety compose'
check $S 'event.dataset'     '"dataset":"catalog.outbox-publisher"'           'event.dataset = <app>.<serwis>'

# 2. Monolog z search: wyjątek i trace_id z context (extra jest pustą listą)
S=2-monolog-search-exception
check $S 'error.type'        'Exception\\HandlerFailedException"'             'error.type z context.exception.class'
check $S 'error.message'     '"message":"Elasticsearch jest niedostępny"'     'error.message z context.exception.message'
check $S 'error.stack_trace' '#0 /app/src/Indexer.php(42): index()\n#1 {main}' 'error.stack_trace sklejony z listy'
check $S 'trace.id'          '"id":"4bf92f3577b34da6a3ce929d0e0e4736"'        'trace.id z context.trace_id'
check $S 'log.level'         '"level":"error"'                                'log.level=error'
check $S 'app.context.offer_id' '"offer_id":42'                               'offer_id w app.context'

# 3. Access log Caddy
S=3-caddy-access
check $S '@timestamp'        '"@timestamp":"2026-10-17T20:00:00.123'          '@timestamp z ts (epoch)'
check $S 'http.response.status_code' '"status_code":200'                      'http.response.status_code'
check $S 'http.request.method' '"method":"GET"'                               'http.request.method'
check $S 'url.original'      '"original":"/api/products?page=2"'              'url.original z request.uri'
check $S 'url.path'          '"path":"/api/products"'                         'url.path bez query stringa'
check $S 'event.duration'    '"duration":12345000'                            'event.duration w nanosekundach'
check $S 'trace.id'          '"id":"4bf92f3577b34da6a3ce929d0e0e4736"'        'trace.id z resp_headers.X-Trace-Id'
check $S 'event.dataset'     '"dataset":"catalog.access"'                     'event.dataset=catalog.access'

# 4. Linia nie-JSON: dokument nie przepada
S=4-not-json
check $S 'tags'              'json_parse_failure'                             'tag json_parse_failure'
check $S 'event.original'    'PHP Warning:'                                   'event.original zachowany'

# 5. JSON bez pól Monologa/Caddy: bez błędu pipeline'u, treść zachowana
S=5-json-unknown
check $S 'app.context.foo'   '"foo":1'                                        'nieznany JSON w app.context'
check $S 'event.original'    '{\"foo\":1}'                                    'event.original zachowany'

# 6. Pozostałe logi Caddy (nie-access)
S=6-caddy-runtime
check $S 'log.level'         '"level":"warn"'                                 'log.level z level'
check $S 'log.logger'        '"logger":"tls"'                                 'log.logger z logger'
check $S 'message'           '"message":"stapling OCSP"'                      'message z msg'

if [ "${FAILED}" -ne 0 ]; then
  log "BŁĄD: ${FAILED} asercji nie przeszło."
  exit 1
fi
log "Wszystkie asercje przeszły."
