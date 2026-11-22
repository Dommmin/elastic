#!/usr/bin/env bash
# ============================================================================
#  filter-demo.sh — filtrowany kNN: filtr wewnątrz `knn` kontra `post_filter` (ETAP 14, Task 6); es-lab
#
#  Zapytanie „coś ciepłego na zimę”, trzy ciała wariantu `knn` (pole vec_float, k=10, num_candidates=100, size=10):
#    a) bez filtra,
#    b) `filter: {term: {in_stock: true}}` WEWNĄTRZ knn  — filtr przed wyborem k sąsiadów, zawsze 10 dostępnych,
#    c) `post_filter` in_stock                           — filtr po wyborze 10 sąsiadów, wyników bywa mniej niż 10.
#  Wyniki (id, liczba trafień, nDCG@10) bierze `search:eval:lab --knn-filter=…` (ten sam licznik nDCG co w tabelach
#  laboratorium; ciało zapytania buduje LabQueryBuilder::knnBody, więc skrypt go nie duplikuje). Niedostępne w wynikach
#  liczy osobne zapytanie `ids` + `in_stock:false` do es-lab. Oceny trafności NIE zależą od dostępności (reguły z
#  semantic-queries.yaml), więc nDCG pokazuje koszt filtra dla trafności, a nie „karę za brak towaru”.
#
#  DANE: w próbce jest mało produktów `in_stock=false` (Task 3: 1), a products-lab-v1 ma zostać NIETKNIĘTY (każda
#  zmiana + merge przebudowuje graf HNSW i przesuwa nDCG wariantów knn). Skrypt buduje więc własny, jednorazowy
#  indeks `products-lab-filter` na es-lab: to samo mapowanie bez semantic_text i bez pipeline'u, `_reindex` z
#  products-lab-v1 z `pipeline: _none` (wektory vec_* jadą z _source, bez ponownej inferencji), oznacza tam
#  (id / M) % 3 = 0 jako in_stock=false (M = modulo próbki z _meta.lab_modulo; samo `id % 3` trafiłoby we wszystkie
#  dokumenty, bo id próbki to wielokrotności 15), forcemerge do 1 segmentu i usuwa indeks na wyjściu (trap).
#  Zapytania idą na polu `vec_float` (semantic_e5 nie istnieje w kopii); pole jest wypisywane w wynikach.
#  Kod wyjścia 0 tylko, gdy zachowanie zgadza się z oczekiwaniem (a ma niedostępne, b = 10 dostępnych, c < 10).
# ============================================================================
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 1
# shellcheck source=tools/lab/lib.sh
source tools/lab/lib.sh
command -v jq >/dev/null || die "brak jq"

MAPPING=infra/elasticsearch/lab/products-lab-v1.json
QUERY='coś ciepłego na zimę'
K=10
JSON_DIR=apps/catalog/storage/app/private/lab

SRC=products-lab-v1
IDX=products-lab-filter
FIELD=vec_float
cleanup() { lab_es -o /dev/null -X DELETE "${LAB_URL}/${IDX}?ignore_unavailable=true" >/dev/null 2>&1 || true; }
trap cleanup EXIT

[ "$(lab_es -o /dev/null -w '%{http_code}' "${LAB_URL}/${SRC}")" = 200 ] || die "brak indeksu ${SRC} na es-lab (make lab-mode-on, make lab-up, make lab-setup)"
count() { lab_es -X POST "${LAB_URL}/${IDX}/_count" -H 'Content-Type: application/json' -d "$1" | jq -r .count; }
MODULO="$(lab_es "${LAB_URL}/${SRC}/_mapping" | jq -r '.["'"${SRC}"'"].mappings._meta.lab_modulo // empty')"
[ -n "${MODULO}" ] || die "brak _meta.lab_modulo w ${SRC}"

step "Własny indeks ${IDX} (kopia ${SRC} bez semantic_text i pipeline'u; ${SRC} nie jest zmieniany)"
cleanup
jq 'del(.settings.index.default_pipeline) | del(.mappings.properties.semantic_e5) | del(.mappings.properties.lab_text.copy_to)' "${MAPPING}" \
  | lab_es -X PUT "${LAB_URL}/${IDX}" -H 'Content-Type: application/json' --data-binary @- | jq -c .
lab_es --max-time 900 -X POST "${LAB_URL}/_reindex?refresh=true" -H 'Content-Type: application/json' \
  -d '{"source":{"index":"'"${SRC}"'"},"dest":{"index":"'"${IDX}"'","pipeline":"_none"}}' \
  | jq -c '{total, created, failures: (.failures | length)}'
TOTAL="$(count '{"query":{"match_all":{}}}')"
SRC_TOTAL="$(lab_es "${LAB_URL}/${SRC}/_count" | jq -r .count)"
[ "${TOTAL}" = "${SRC_TOTAL}" ] || die "kopia ma ${TOTAL} dokumentów, źródło ${SRC_TOTAL}"
VEC="$(count '{"query":{"exists":{"field":"vec_float"}}}')"
[ "${VEC}" = "${TOTAL}" ] || die "wektory nie przeszły przez _reindex (vec_float: ${VEC} z ${TOTAL})"
echo "documents: ${TOTAL}"
echo "docs_with_vec_float: ${VEC}"
echo "out_of_stock_before: $(count '{"query":{"term":{"in_stock":false}}}')"
echo "Oznaczam (id / ${MODULO}) % 3 = 0 (co trzeci dokument próbki) jako in_stock=false, WYŁĄCZNIE w ${IDX}."
lab_es --max-time 900 -X POST "${LAB_URL}/${IDX}/_update_by_query?conflicts=proceed&refresh=true" \
  -H 'Content-Type: application/json' \
  -d '{"script":{"lang":"painless","params":{"m":'"${MODULO}"'},"source":"if ((Long.parseLong(ctx._id) / params.m) % 3 == 0 && ctx._source.in_stock != false) { ctx._source.in_stock = false } else { ctx.op = \"noop\" }"}}' \
  | jq -c '{updated, noops, version_conflicts, failures: (.failures | length)}'
lab_es --max-time 900 -X POST "${LAB_URL}/${IDX}/_forcemerge?max_num_segments=1" | jq -c ._shards
echo "out_of_stock: $(count '{"query":{"term":{"in_stock":false}}}')"
echo "field: ${FIELD}"

# wynik jednego trybu filtra: nDCG i id z search:eval:lab, niedostępne z es-lab
run_mode() {
  local mode="$1" file="lab/filter-demo-${1}.json" json ids out_of_stock
  docker compose exec -T catalog-app php artisan search:eval:lab --variant=knn --group=descriptive --k="${K}" \
    --vector-field="${FIELD}" --index="${IDX}" --knn-filter="${mode}" --json="${file}" >/dev/null || die "search:eval:lab --knn-filter=${mode} nie powiodło się"
  json="$(jq -c --arg q "${QUERY}" '.per_query[] | select(.query == $q)' "${JSON_DIR}/filter-demo-${mode}.json")"
  [ -n "${json}" ] || die "brak zapytania w wyniku (semantic-queries.yaml)"
  ids="$(jq -c '.top.knn' <<<"${json}")"
  out_of_stock="$(lab_es -X POST "${LAB_URL}/${IDX}/_search" -H 'Content-Type: application/json' \
    -d "$(jq -cn --argjson ids "${ids}" '{size: 0, track_total_hits: true, query: {bool: {filter: [{ids: {values: $ids}}, {term: {in_stock: false}}]}}}')" \
    | jq -r .hits.total.value)"
  HITS="$(jq 'length' <<<"${ids}")"
  OOS="${out_of_stock}"
  NDCG="$(jq -r '.ndcg.knn' <<<"${json}" | awk '{ printf "%.3f", $1 }')"
  rm -f "${JSON_DIR}/filter-demo-${mode}.json"
}

step "Zapytanie: '${QUERY}' (knn na ${FIELD}, k=${K}, num_candidates=100, size=${K})"
run_mode none;   HA="${HITS}"; OA="${OOS}"; NA="${NDCG}"
run_mode in-knn; HB="${HITS}"; OB="${OOS}"; NB="${NDCG}"
run_mode post;   HC="${HITS}"; OC="${OOS}"; NC="${NDCG}"
printf '\n%-34s %8s %14s %8s\n' wariant trafien niedostepnych nDCG@10
printf '%-34s %8s %14s %8s\n' "a) knn bez filtra" "${HA}" "${OA}" "${NA}"
printf '%-34s %8s %14s %8s\n' "b) filter wewnątrz knn" "${HB}" "${OB}" "${NB}"
printf '%-34s %8s %14s %8s\n' "c) post_filter" "${HC}" "${OC}" "${NC}"
echo
echo "a_hits: ${HA}"
echo "a_out_of_stock: ${OA}"
echo "a_ndcg: ${NA}"
echo "b_hits: ${HB}"
echo "b_out_of_stock: ${OB}"
echo "b_ndcg: ${NB}"
echo "c_hits: ${HC}"
echo "c_out_of_stock: ${OC}"
echo "c_ndcg: ${NC}"

step "Asercje"
fail=0
check() { if [ "$2" = ok ]; then echo "  OK: $1"; else echo "  BŁĄD: $1" >&2; fail=1; fi; }
check "(a) zawiera niedostępne (${OA} > 0)" "$([ "${OA}" -gt 0 ] && echo ok || echo no)"
check "(b) ${K} wyników, żaden niedostępny" "$([ "${HB}" = "${K}" ] && [ "${OB}" = 0 ] && echo ok || echo no)"
check "(c) mniej niż ${K} wyników, żaden niedostępny (${HC})" "$([ "${HC}" -lt "${K}" ] && [ "${OC}" = 0 ] && echo ok || echo no)"
[ "${fail}" = 0 ] || die "zachowanie filtrowanego kNN odbiega od oczekiwanego"
step "Gotowe"
