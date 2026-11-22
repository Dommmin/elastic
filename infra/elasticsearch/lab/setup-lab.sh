#!/usr/bin/env bash
# ============================================================================
#  setup-lab.sh [schema|all] — konfiguracja es-lab (ETAP 14); idempotentna
#  Fazy: schema = trial, endpoint E5 `lab-e5`, pipeline `lab-embed`, indeks `products-lab-v1`.
#  (kolejne fazy — load, elser — dochodzą w następnych zadaniach; `all` = wszystkie istniejące)
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

case "${1:-all}" in
  schema|all) phase_schema ;;
  *) die "użycie: setup-lab.sh [schema|all]" ;;
esac
step "Gotowe"
