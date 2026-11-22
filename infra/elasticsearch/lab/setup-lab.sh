#!/usr/bin/env bash
# ============================================================================
#  setup-lab.sh [schema|load|all] — konfiguracja es-lab (ETAP 14); idempotentna
#  Fazy: schema = trial, endpoint E5 `lab-e5`, pipeline `lab-embed`, indeks `products-lab-v1`.
#          load   = próbka z `make lab-export` → _bulk do products-lab-v1 (stałe _id: powtórka nadpisuje), refresh, forcemerge do 1 segmentu.
#  (faza elser dochodzi w następnym zadaniu; `all` = wszystkie istniejące)
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

# stan wdrożenia modelu E5 (pusty, gdy brak wdrożenia)
model_state() {
  lab_es "${LAB_URL}/_ml/trained_models/${MODEL_ID}/_stats" 2>/dev/null \
    | jq -r '[.trained_model_stats[]?.deployment_stats.state // empty][0] // empty' 2>/dev/null || true
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

phase_schema() {
  step "Licencja (trial tylko na es-lab)"
  local type
  type="$(lab_es "${LAB_URL}/_license" | jq -r '.license.type // empty')"
  if [ "${type}" = basic ]; then
    lab_es -X POST "${LAB_URL}/_license/start_trial?acknowledge=true" | jq -c '{trial_was_started, acknowledged}'
  else
    echo "  licencja: ${type} (bez zmian)"
  fi

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
  local state="" waited=0
  while [ "${waited}" -lt "${WAIT_MAX_S}" ]; do
    state="$(model_state)"
    [ "${state}" = started ] && break
    sleep 5; waited=$((waited + 5))
  done
  [ "${state}" = started ] || die "model nie wystartował w ${WAIT_MAX_S} s (stan: ${state:-brak}); model jeszcze się pobiera/ładuje — sprawdź: docker compose logs es-lab, GET _ml/trained_models/${MODEL_ID}/_stats"
  echo "  stan wdrożenia: started"

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

case "${1:-all}" in
  schema) phase_schema ;;
  load) phase_load ;;
  all) phase_schema; phase_load ;;
  *) die "użycie: setup-lab.sh [schema|load|all]" ;;
esac
step "Gotowe"
