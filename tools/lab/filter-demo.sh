#!/usr/bin/env bash
# ============================================================================
#  filter-demo.sh — filtrowany kNN: filtr wewnątrz `knn` kontra `post_filter` (ETAP 14, Task 6); es-lab
#
#  Zapytanie „coś ciepłego na zimę”, trzy ciała wariantu `knn` (semantic_e5, k=10, num_candidates=100, size=10):
#    a) bez filtra,
#    b) `filter: {term: {in_stock: true}}` WEWNĄTRZ knn  — filtr przed wyborem k sąsiadów, zawsze 10 dostępnych,
#    c) `post_filter` in_stock                           — filtr po wyborze 10 sąsiadów, wyników bywa mniej niż 10.
#  Wyniki (id, liczba trafień, nDCG@10) bierze `search:eval:lab --knn-filter=…` (ten sam licznik nDCG co w tabelach
#  laboratorium; ciało zapytania buduje LabQueryBuilder::knnBody, więc skrypt go nie duplikuje). Niedostępne w wynikach
#  liczy osobne zapytanie `ids` + `in_stock:false` do es-lab. Oceny trafności NIE zależą od dostępności (reguły z
#  semantic-queries.yaml), więc nDCG pokazuje koszt filtra dla trafności, a nie „karę za brak towaru”.
#
#  DANE: w próbce jest mało produktów `in_stock=false` (Task 3: 1). Skrypt oznacza więc deterministyczny podzbiór
#  (id / M) % 3 = 0 jako in_stock=false, gdzie M to modulo próbki (id % M = 0, więc samo `id % 3` trafiłoby we wszystkie
#  dokumenty, bo M=15 jest wielokrotnością 3; M czytamy z _meta.lab_modulo indeksu), czyli co trzeci dokument próbki (`_update_by_query` z `pipeline=_none`, żeby NIE liczyć wektorów od nowa)
#  — WYŁĄCZNIE w products-lab-v1 na es-lab; główny klaster i baza catalogu nie są ruszane. Idempotentne:
#  dokument już oznaczony jest pomijany (noop). Pełny powrót do stanu z próbki: make lab-reset + make lab-setup
#  albo ponowne załadowanie próbki (_bulk z _id nadpisuje dokumenty).
#  Kod wyjścia 0 tylko, gdy zachowanie zgadza się z oczekiwaniem (a ma niedostępne, b = 10 dostępnych, c < 10).
# ============================================================================
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 1
# shellcheck source=tools/lab/lib.sh
source tools/lab/lib.sh
command -v jq >/dev/null || die "brak jq"

IDX=products-lab-v1
QUERY='coś ciepłego na zimę'
K=10
MIN_OUT_OF_STOCK=100   # próg „dość niedostępnych do demo”: poniżej niego (Task 3: 1) oznaczamy co trzeci dokument próbki
JSON_DIR=apps/catalog/storage/app/private/lab

[ "$(lab_es -o /dev/null -w '%{http_code}' "${LAB_URL}/${IDX}")" = 200 ] || die "brak indeksu ${IDX} na es-lab (make lab-mode-on, make lab-up, make lab-setup)"
count() { lab_es -X POST "${LAB_URL}/${IDX}/_count" -H 'Content-Type: application/json' -d "$1" | jq -r .count; }
TOTAL="$(count '{"query":{"match_all":{}}}')"
VEC_BEFORE="$(count '{"query":{"exists":{"field":"vec_float"}}}')"

step "Dane: dostępność w ${IDX}"
OUT="$(count '{"query":{"term":{"in_stock":false}}}')"
echo "documents: ${TOTAL}"
echo "out_of_stock_before: ${OUT}"
if [ "${OUT}" -lt "${MIN_OUT_OF_STOCK}" ]; then
  MODULO="$(lab_es "${LAB_URL}/${IDX}/_mapping" | jq -r '.["'"${IDX}"'"].mappings._meta.lab_modulo // empty')"
  [ -n "${MODULO}" ] || die "brak _meta.lab_modulo w ${IDX}"
  echo "W próbce jest za mało produktów niedostępnych (${OUT}); oznaczam (id / ${MODULO}) % 3 = 0 (co trzeci dokument próbki) jako in_stock=false."
  echo "Zmiana dotyczy WYŁĄCZNIE ${IDX} na es-lab (pipeline=_none: wektory zostają, bez ponownej inferencji)."
  started="$(date +%s)"
  lab_es --max-time 900 -X POST "${LAB_URL}/${IDX}/_update_by_query?pipeline=_none&conflicts=proceed&refresh=true" \
    -H 'Content-Type: application/json' \
    -d '{"script":{"lang":"painless","params":{"m":'"${MODULO}"'},"source":"if ((Long.parseLong(ctx._id) / params.m) % 3 == 0 && ctx._source.in_stock != false) { ctx._source.in_stock = false } else { ctx.op = \"noop\" }"}}' \
    | jq -c '{updated, noops, version_conflicts, failures: (.failures | length)}'
  echo "update_by_query_seconds: $(( $(date +%s) - started ))"
  # aktualizacja zostawia stare wersje jako usunięte w kilku segmentach (osobne grafy HNSW); wracamy do 1 segmentu jak po make lab-setup
  lab_es --max-time 900 -X POST "${LAB_URL}/${IDX}/_forcemerge?max_num_segments=1" | jq -c ._shards
  OUT="$(count '{"query":{"term":{"in_stock":false}}}')"
fi
echo "out_of_stock: ${OUT}"
VEC_AFTER="$(count '{"query":{"exists":{"field":"vec_float"}}}')"
echo "docs_with_vec_float: ${VEC_BEFORE} -> ${VEC_AFTER}"
[ "${VEC_BEFORE}" = "${VEC_AFTER}" ] || die "liczba dokumentów z vec_float zmieniła się (${VEC_BEFORE} -> ${VEC_AFTER})"

# wynik jednego trybu filtra: nDCG i id z search:eval:lab, niedostępne z es-lab
run_mode() {
  local mode="$1" file="lab/filter-demo-${1}.json" json ids out_of_stock
  docker compose exec -T catalog-app php artisan search:eval:lab --variant=knn --group=descriptive --k="${K}" \
    --knn-filter="${mode}" --json="${file}" >/dev/null || die "search:eval:lab --knn-filter=${mode} nie powiodło się"
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

step "Zapytanie: '${QUERY}' (knn, k=${K}, num_candidates=100, size=${K})"
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
