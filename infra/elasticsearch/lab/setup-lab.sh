#!/usr/bin/env bash
# ============================================================================
#  setup-lab.sh [schema|load|all] — konfiguracja es-lab (ETAP 14); idempotentna
#  Fazy: schema = trial, endpoint E5 `lab-e5`, pipeline `lab-embed`, indeks `products-lab-v1`.
#          load   = próbka z `make lab-export` → _bulk do products-lab-v1 (stałe _id: powtórka nadpisuje), refresh, forcemerge do 1 segmentu.
#          elser  = (ETAP 14b) endpoint `lab-elser`, indeks `products-lab-elser` (semantic_text na ELSER) z `_reindex` z products-lab-v1;
#                   products-lab-v1 zostaje nietknięty. NIE wchodzi do `all` (make lab-setup-elser).
#  Dotyka WYŁĄCZNIE es-lab (LAB_URL), nigdy głównego klastra.
# ============================================================================
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../../.." || exit 1
# shellcheck source=tools/lab/lib.sh
source tools/lab/lib.sh
command -v jq >/dev/null || die "brak jq"

DIR=infra/elasticsearch/lab
MODEL_ID=".multilingual-e5-small"
WAIT_MAX_S=900

# Prefiksy E5 (sprawdzone na ES 9.5.1, dokumentacja o nich milczy): endpoint dokleja `passage: ` przy input_type=ingest
# (to robi procesor inference w pipeline) i `query: ` przy input_type=search (to robi query_vector_builder);
# wejście bez input_type idzie bez prefiksu. Dlatego pipeline NIE dodaje prefiksu sam, a ręcznie liczony wektor
# zapytania trzeba brać z _inference z "input_type":"search".

es_code() { lab_es -o /dev/null -w '%{http_code}' "$@"; }

# stan wdrożenia modelu (domyślnie E5; pusty, gdy brak wdrożenia)
model_state() {
  lab_es "${LAB_URL}/_ml/trained_models/${1:-${MODEL_ID}}/_stats" 2>/dev/null \
    | jq -r '[.trained_model_stats[]?.deployment_stats.state // empty][0] // empty' 2>/dev/null || true
}

# wait_deployed <model_id> — czeka, aż wdrożenie modelu ma stan started
wait_deployed() {
  local model="$1" state="" waited=0
  while [ "${waited}" -lt "${WAIT_MAX_S}" ]; do
    state="$(model_state "${model}")"
    [ "${state}" = started ] && break
    sleep 5; waited=$((waited + 5))
  done
  [ "${state}" = started ] || die "model nie wystartował w ${WAIT_MAX_S} s (stan: ${state:-brak}); model jeszcze się pobiera/ładuje — sprawdź: docker compose logs es-lab, GET _ml/trained_models/${model}/_stats"
  echo "  stan wdrożenia: started"
}

create_endpoint() {
  echo "  tworzę; pierwszy raz ES pobiera model z serwerów Elastic (może potrwać kilka minut)"
  local body='{"service":"elasticsearch","service_settings":{"model_id":"'"${MODEL_ID}"'","num_allocations":1,"num_threads":1}}'
  local out code
  out="$(mktemp)"
  code="$(lab_es -o "${out}" -w '%{http_code}' -X PUT "${LAB_URL}/_inference/text_embedding/lab-e5?timeout=${WAIT_MAX_S}s" \
    -H 'Content-Type: application/json' -d "${body}")"
  if [ "${code}" != 200 ]; then
    cat "${out}" >&2; rm -f "${out}"
    die "utworzenie endpointu lab-e5 zwróciło HTTP ${code}"
  fi
  rm -f "${out}"
}

# Trial wygasa po 30 dniach: typ zostaje `trial`, status `expired`, a endpointy ML odpowiadają 403. Bez tej kontroli
# skrypt uznałby model za niewdrożony, skasował endpoint i padł na surowym 403. Drugi trial na tym samym wolumenie
# ES odrzuca (eligible_to_start_trial=false). W obu przypadkach jedyna droga to nowy wolumen.
LICENSE_HINT="trial es-lab wygasł albo został już wykorzystany; make lab-reset CONFIRM=1 i od nowa make lab-up && make lab-setup (RUNBOOK #152)"
require_trial() {
  local lic type status
  lic="$(lab_es "${LAB_URL}/_license")"
  type="$(jq -r '.license.type // empty' <<<"${lic}")"
  status="$(jq -r '.license.status // empty' <<<"${lic}")"
  if [ "${type}" = basic ]; then
    [ "$(lab_es "${LAB_URL}/_license/trial_status" | jq -r '.eligible_to_start_trial')" = true ] \
      || die "licencja basic, a start_trial niedostępny: ${LICENSE_HINT}"
    lab_es -X POST "${LAB_URL}/_license/start_trial?acknowledge=true" | jq -e -c '{trial_was_started, acknowledged} | select(.trial_was_started == true)' \
      || die "start_trial nie uruchomił triala: ${LICENSE_HINT}"
  elif [ "${status}" != active ]; then
    die "licencja ${type:-?} ma status ${status:-?}: ${LICENSE_HINT}"
  else
    echo "  licencja: ${type}, ${status} (bez zmian)"
  fi
}

phase_schema() {
  step "Licencja (trial tylko na es-lab)"
  require_trial

  step "Endpoint inference lab-e5 (${MODEL_ID})"
  # model niedokończony (import przerwany, np. OOM-kill): ES odpowiada „Model definition truncated"; usuwamy go i endpoint
  if [ "$(lab_es "${LAB_URL}/_ml/trained_models/${MODEL_ID}?include=definition_status" | jq -r '.trained_model_configs[0].fully_defined | tostring' 2>/dev/null)" = false ]; then
    echo "  model ${MODEL_ID} jest niekompletny: usuwam go razem z endpointem, import pójdzie od nowa"
    lab_es -X DELETE "${LAB_URL}/_inference/text_embedding/lab-e5?force=true" >/dev/null || true
    lab_es -X DELETE "${LAB_URL}/_ml/trained_models/${MODEL_ID}?force=true" | jq -c .
  fi
  if [ "$(es_code "${LAB_URL}/_inference/text_embedding/lab-e5")" = 200 ]; then
    # endpoint bez wdrożenia (np. węzeł zabity przez OOM w trakcie tworzenia): kasujemy i tworzymy od nowa
    if [ -z "$(model_state)" ]; then
      echo "  endpoint istnieje, ale model nie jest wdrożony: kasuję i tworzę ponownie"
      lab_es -X DELETE "${LAB_URL}/_inference/text_embedding/lab-e5?force=true" | jq -c .
      create_endpoint
    else
      echo "  istnieje (bez zmian)"
    fi
  else
    create_endpoint
  fi

  step "Czekanie na wdrożenie modelu (do $((WAIT_MAX_S / 60)) min)"
  wait_deployed "${MODEL_ID}"

  step "Pipeline lab-embed"
  lab_es -X PUT "${LAB_URL}/_ingest/pipeline/lab-embed" -H 'Content-Type: application/json' \
    --data-binary "@${DIR}/lab-embed-pipeline.json" | jq -c .

  step "Indeks products-lab-v1"
  if [ "$(es_code "${LAB_URL}/products-lab-v1")" = 200 ]; then
    echo "  istnieje (bez zmian)"
  else
    lab_es -X PUT "${LAB_URL}/products-lab-v1" -H 'Content-Type: application/json' \
      --data-binary "@${DIR}/products-lab-v1.json" | jq -c .
  fi
}

SAMPLE=apps/catalog/storage/app/private/lab/products.ndjson
SAMPLE_MODULO=15   # musi równać się LabExportCommand::MODULO
BULK_CHUNK=500
BULK_TIMEOUT_S=1800   # każdy dokument jest embeddowany dwa razy (pipeline + semantic_text), na CPU to potrwa

phase_load() {
  step "Próbka produktów → products-lab-v1"
  [ -f "${SAMPLE}" ] || die "brak ${SAMPLE}; uruchom make lab-export (w trybie normalnym, przed make lab-mode-on)"
  local modulo expected
  modulo="$(head -1 "${SAMPLE}" | jq -r '._lab_export.modulo // empty')"
  expected="$(head -1 "${SAMPLE}" | jq -r '._lab_export.count // empty')"
  [ "${modulo}" = "${SAMPLE_MODULO}" ] || die "nagłówek próbki ma modulo='${modulo}', oczekiwano ${SAMPLE_MODULO}; uruchom make lab-export"
  [ -n "${expected}" ] || die "nagłówek próbki bez count; uruchom make lab-export"
  [ "$(es_code "${LAB_URL}/products-lab-v1")" = 200 ] || die "brak indeksu products-lab-v1; najpierw make lab-setup (faza schema)"
  echo "  dokumentów w próbce: ${expected}"
  # indeks z innej próbki zostawiłby obce dokumenty (stałe _id nie wystarczą); modulo zapisujemy w _meta indeksu
  local idx_modulo idx_count
  idx_modulo="$(lab_es "${LAB_URL}/products-lab-v1/_mapping" | jq -r '.["products-lab-v1"].mappings._meta.lab_modulo // empty')"
  idx_count="$(lab_es "${LAB_URL}/products-lab-v1/_count" | jq -r .count)"
  if [ "${idx_modulo}" != "${modulo}" ] && { [ -n "${idx_modulo}" ] || [ "${idx_count}" != 0 ]; }; then
    die "indeks products-lab-v1 pochodzi z innej próbki (_meta.lab_modulo='${idx_modulo}', dokumentów: ${idx_count}); usuń indeks (DELETE products-lab-v1 na es-lab) albo make lab-reset i powtórz make lab-setup"
  fi
  # znacznik PRZED ładowaniem: przerwane ładowanie (stałe _id) da się wznowić tym samym modulo, a inne modulo nadal jest odrzucane
  lab_es -X PUT "${LAB_URL}/products-lab-v1/_mapping" -H 'Content-Type: application/json' \
    -d '{"_meta":{"lab_modulo":'"${modulo}"'}}' | jq -c .

  local work started sent=0 n
  work="$(mktemp -d)"
  # `id` idzie do _id, nie do _source (mapowanie jest strict); pierwsza linia to nagłówek
  tail -n +2 "${SAMPLE}" | jq -c '{index: {_id: .id}}, del(.id)' | split -l $((BULK_CHUNK * 2)) - "${work}/chunk."
  started="$(date +%s)"
  for f in "${work}"/chunk.*; do
    n=$(( $(wc -l < "${f}") / 2 ))
    lab_es --max-time "${BULK_TIMEOUT_S}" -X POST "${LAB_URL}/products-lab-v1/_bulk?refresh=false" \
      -H 'Content-Type: application/x-ndjson' --data-binary "@${f}" > "${work}/resp.json" || { rm -rf "${work}"; die "żądanie _bulk nie powiodło się (po ${sent} dokumentach)"; }
    if [ "$(jq -r '.errors' "${work}/resp.json")" != false ]; then
      jq -c '[.items[] | select(.index.error) | .index.error.reason][0:3]' "${work}/resp.json" >&2
      rm -rf "${work}"; die "_bulk zgłosił błędy (po ${sent} dokumentach)"
    fi
    sent=$((sent + n))
    printf '  %s/%s (%s s)\n' "${sent}" "${expected}" "$(( $(date +%s) - started ))"
  done
  rm -rf "${work}"
  echo "  load_seconds=$(( $(date +%s) - started ))"

  step "Refresh i forcemerge do 1 segmentu"
  lab_es -X POST "${LAB_URL}/products-lab-v1/_refresh" | jq -c ._shards
  lab_es --max-time 1800 -X POST "${LAB_URL}/products-lab-v1/_forcemerge?max_num_segments=1" | jq -c ._shards

  step "Kontrola liczby dokumentów"
  local count
  count="$(lab_es "${LAB_URL}/products-lab-v1/_count" | jq -r .count)"
  echo "  _count=${count}, nagłówek=${expected}"
  [ "${count}" = "${expected}" ] || die "_count (${count}) różni się od liczby z nagłówka (${expected})"
}

# ELSER v2 w wariancie niezależnym od platformy (`.elser_model_2`); wersja `_linux-x86_64` jest zoptymalizowana
# tylko pod x86, a VM Dockera na Apple Silicon to linux/arm64.
ELSER_MODEL=".elser_model_2"
ELSER_INDEX="products-lab-elser"
# Jeden wątek pytorch_inference to ~0,65 s na fragment tekstu i nie skaluje się wątkami jednej alokacji (sprawdzone:
# 8 wątków = nadal 1 rdzeń), więc na czas _reindex wdrożenie ma kilka alokacji (po jednym wątku); potem wracamy do 1.
ELSER_INGEST_ALLOCATIONS=6
# ES szacuje pamięć wdrożenia ostrożnie (ELSER ~2 GB, E5 ~1,3 GB; faktycznie pytorch_inference zajmuje <1 GB), a domyślne
# 60% z 4 GB nie pozwala trzymać obu modeli naraz — zapytania knn i elser są w jednym przebiegu. Podnosimy bramkę do 90%;
# realne zużycie kontroluj przez docker stats (limit kontenera 4 GB, sterta 1,5 GB).
ML_MEMORY_PERCENT=90
ELSER_FIELDS='["name","description","brand","category","category_leaf","category_root","in_stock","price_min","lab_text"]'


# przywraca wdrożenie E5 (endpoint lab-e5), jeśli faza elser je zatrzymała albo ktoś zostawił zatrzymane
restore_e5() {
  trap - EXIT
  [ -z "$(model_state)" ] || return 0
  echo "  przywracam wdrożenie E5 (lab-e5)"
  lab_es --max-time "${WAIT_MAX_S}" -X POST "${LAB_URL}/_ml/trained_models/${MODEL_ID}/deployment/_start?deployment_id=lab-e5&number_of_allocations=1&threads_per_allocation=1&wait_for=started&timeout=${WAIT_MAX_S}s" | jq -c .
  wait_deployed "${MODEL_ID}"
}

# elser_complete — 0, gdy products-lab-elser istnieje i ma tyle dokumentów co products-lab-v1 (_reindex niepotrzebny)
elser_complete() {
  [ "$(es_code "${LAB_URL}/${ELSER_INDEX}")" = 200 ] || return 1
  [ "$(lab_es "${LAB_URL}/${ELSER_INDEX}/_count" | jq -r .count)" = "$(lab_es "${LAB_URL}/products-lab-v1/_count" | jq -r .count)" ]
}

# elser_scale_to_one — wdrożenie ELSER na 1 alokację (6 alokacji przy bramce 90% grozi OOM-kill kontenera, RUNBOOK #156)
elser_scale_to_one() {
  local alloc
  alloc="$(lab_es "${LAB_URL}/_ml/trained_models/${ELSER_MODEL}/_stats" | jq -r '[.trained_model_stats[]?.deployment_stats.number_of_allocations // empty][0] // empty')"
  if [ -n "${alloc}" ] && [ "${alloc}" != 1 ]; then
    echo "  ELSER ma ${alloc} alokacji: zmniejszam do 1"
    lab_es -X PUT "${LAB_URL}/_inference/sparse_embedding/lab-elser/_update" -H 'Content-Type: application/json' \
      -d '{"service_settings":{"num_allocations":1}}' | jq -c .service_settings
    wait_deployed "${ELSER_MODEL}"
  fi
}

phase_elser() {
  step "Licencja"
  require_trial

  step "Pamięć: es-lab i zapas Dockera"
  [ "$(es_code "${LAB_URL}/products-lab-v1")" = 200 ] || die "brak products-lab-v1; najpierw make lab-setup (fazy schema i load)"
  need_free_mb 300
  lab_es "${LAB_URL}/_ml/info" | jq -r '"  pamięć ML węzła: \(.limits.total_ml_memory), efektywny limit modelu: \(.limits.effective_max_model_memory_limit)"'

  step "Bramka pamięci ML (xpack.ml.max_machine_memory_percent=${ML_MEMORY_PERCENT}) i endpoint inference lab-elser (${ELSER_MODEL})"
  lab_es -X PUT "${LAB_URL}/_cluster/settings" -H 'Content-Type: application/json' \
    -d '{"persistent":{"xpack.ml.max_machine_memory_percent":'"${ML_MEMORY_PERCENT}"'}}' | jq -c .acknowledged
  trap restore_e5 EXIT
  if [ "$(es_code "${LAB_URL}/_inference/sparse_embedding/lab-elser")" = 200 ] && [ -n "$(model_state "${ELSER_MODEL}")" ]; then
    echo "  istnieje i jest wdrożony (bez zmian)"
  else
    # Pamięć ML węzła (60% z 4 GB) nie pomieści obu wdrożeń naraz na czas startu ELSER (ES odpowiada 429 „insufficient
    # available memory"). Na czas tworzenia endpointu i indeksacji zatrzymujemy więc wdrożenie E5; trap przywraca je na końcu.
    if [ -n "$(model_state)" ]; then
      echo "  UWAGA: zatrzymuję wdrożenie E5 (lab-e5) na czas wdrażania ELSER; przywrócę je na końcu fazy"
      lab_es -X POST "${LAB_URL}/_ml/trained_models/lab-e5/deployment/_stop?force=true" | jq -c .
    fi
    if [ "$(es_code "${LAB_URL}/_inference/sparse_embedding/lab-elser")" = 200 ]; then
      echo "  endpoint bez wdrożenia: kasuję i tworzę ponownie"
      lab_es -X DELETE "${LAB_URL}/_inference/sparse_embedding/lab-elser?force=true" | jq -c .
    fi
    # kilka alokacji tylko wtedy, gdy naprawdę pójdzie _reindex; przy kompletnym indeksie (np. po zatrzymaniu
    # wdrożenia ELSER) od razu 1, bo przy bramce 90% sześć alokacji obok E5 grozi OOM-kill
    local allocations=1 body out code
    elser_complete || allocations="${ELSER_INGEST_ALLOCATIONS}"
    echo "  tworzę z ${allocations} alokacjami; pierwszy raz ES pobiera model z serwerów Elastic (może potrwać kilka minut)"
    body='{"service":"elasticsearch","service_settings":{"model_id":"'"${ELSER_MODEL}"'","num_allocations":'"${allocations}"',"num_threads":1}}'
    out="$(mktemp)"
    code="$(lab_es -o "${out}" -w '%{http_code}' -X PUT "${LAB_URL}/_inference/sparse_embedding/lab-elser?timeout=${WAIT_MAX_S}s" \
      -H 'Content-Type: application/json' -d "${body}")"
    if [ "${code}" != 200 ]; then
      cat "${out}" >&2; rm -f "${out}"
      die "utworzenie endpointu lab-elser zwróciło HTTP ${code}"
    fi
    rm -f "${out}"
  fi
  wait_deployed "${ELSER_MODEL}"

  step "Indeks ${ELSER_INDEX}"
  local modulo
  modulo="$(lab_es "${LAB_URL}/products-lab-v1/_mapping" | jq -r '.["products-lab-v1"].mappings._meta.lab_modulo // empty')"
  [ -n "${modulo}" ] || die "products-lab-v1 nie ma _meta.lab_modulo; najpierw załaduj próbkę: bash infra/elasticsearch/lab/setup-lab.sh load"
  if [ "$(es_code "${LAB_URL}/${ELSER_INDEX}")" = 200 ]; then
    echo "  istnieje (bez zmian)"
  else
    # te same ustawienia i analiza co products-lab-v1, ale bez domyślnego pipeline'u (E5) i bez pól wektorowych
    jq --argjson m "${modulo}" '
      .settings.index |= del(.default_pipeline)
      | .mappings.properties |= (
          {name, description, brand, category, category_leaf, category_root, in_stock, price_min, lab_text}
          | .lab_text.copy_to = ["semantic_elser"]
          | . + {semantic_elser: {type: "semantic_text", inference_id: "lab-elser"}})
      | .mappings._meta = {lab_modulo: $m}' "${DIR}/products-lab-v1.json" \
      | lab_es -X PUT "${LAB_URL}/${ELSER_INDEX}" -H 'Content-Type: application/json' --data-binary @- | jq -c .
  fi

  local expected count
  expected="$(lab_es "${LAB_URL}/products-lab-v1/_count" | jq -r .count)"
  count="$(lab_es "${LAB_URL}/${ELSER_INDEX}/_count" | jq -r .count)"
  if [ "${count}" = "${expected}" ]; then
    echo "  dokumentów: ${count} (komplet, pomijam _reindex)"
    elser_scale_to_one
    restore_e5
    [ "$(model_state)" = started ] || die "wdrożenie E5 nie działa (zapytania knn go potrzebują)"
    return
  fi

  step "_reindex products-lab-v1 → ${ELSER_INDEX} (inferencja ELSER, na CPU potrwa)"
  local task started
  started="$(date +%s)"
  task="$(lab_es -X POST "${LAB_URL}/_reindex?wait_for_completion=false&requests_per_second=-1" -H 'Content-Type: application/json' \
    -d '{"source":{"index":"products-lab-v1","size":100,"_source":'"${ELSER_FIELDS}"'},"dest":{"index":"'"${ELSER_INDEX}"'","op_type":"create"},"conflicts":"proceed"}' | jq -r .task)"
  [ -n "${task}" ] && [ "${task}" != null ] || die "_reindex nie zwrócił zadania"
  echo "  zadanie: ${task}"
  local resp
  while :; do
    resp="$(lab_es "${LAB_URL}/_tasks/${task}")"
    [ "$(jq -r .completed <<<"${resp}")" = true ] && break
    printf '  %s s: utworzono %s\n' "$(( $(date +%s) - started ))" "$(jq -r '.task.status.created // 0' <<<"${resp}")"
    sleep 30
  done
  echo "  elser_index_seconds=$(( $(date +%s) - started ))"
  jq -c '{created: .response.created, failures: (.response.failures | length), error: .error}' <<<"${resp}"
  [ "$(jq -r '(.response.failures // []) | length' <<<"${resp}")" = 0 ] && [ "$(jq -r '.error // empty' <<<"${resp}")" = "" ] \
    || { jq -c '.response.failures[0:3], .error' <<<"${resp}" >&2; die "_reindex zakończył się błędami; ponów make lab-setup-elser (dokumenty trafiają pod stałymi _id)"; }

  step "Refresh i forcemerge do 1 segmentu"
  lab_es -X POST "${LAB_URL}/${ELSER_INDEX}/_refresh" | jq -c ._shards
  lab_es --max-time 1800 -X POST "${LAB_URL}/${ELSER_INDEX}/_forcemerge?max_num_segments=1" | jq -c ._shards
  count="$(lab_es "${LAB_URL}/${ELSER_INDEX}/_count" | jq -r .count)"
  echo "  _count=${count}, products-lab-v1=${expected}"
  [ "${count}" = "${expected}" ] || die "_count (${count}) różni się od products-lab-v1 (${expected})"

  step "ELSER: powrót do 1 alokacji; przywracanie E5 i pamięć"
  elser_scale_to_one
  restore_e5
  docker stats --no-stream --format '  {{.Name}}: {{.MemUsage}}' "$(docker compose --profile ml ps -q es-lab)"
  [ "$(model_state)" = started ] || die "wdrożenie E5 nie działa (zapytania knn go potrzebują)"
  [ "$(model_state "${ELSER_MODEL}")" = started ] || die "wdrożenie ELSER nie działa (zapytania elser go potrzebują)"
}

case "${1:-all}" in
  schema) phase_schema ;;
  load) phase_load ;;
  elser) phase_elser ;;
  all) phase_schema; phase_load ;;
  *) die "użycie: setup-lab.sh [schema|load|elser|all]" ;;
esac
step "Gotowe"
