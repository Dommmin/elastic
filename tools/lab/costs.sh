#!/usr/bin/env bash
# ============================================================================
#  costs.sh — koszt wektorów w products-lab-v1 (ETAP 14a, przewodnik 14a, krok 12); dotyka WYŁĄCZNIE es-lab
#    1. rozmiar na dysku per pole (`_disk_usage?run_expensive_tasks=true`) i cały indeks,
#    2. pamięć kNN poza stertą (`_stats/dense_vector`, `_nodes/stats/indices/segments`) — co raportuje ES 9.5,
#    3. czas ładowania tej samej próbki BEZ wektorów: tymczasowy indeks `products-lab-novec`
#       (to samo mapowanie bez semantic_e5/vec_*, bez pipeline'u), usuwany na końcu (trap),
#    4. ekstrapolacja liniowa na 1 171 554 dokumentów — RACHUNEK, NIE POMIAR.
#  Wyjście: `klucz: wartość` + tabela. Czas „z wektorami” = pomiar z make lab-setup (przewodnik 14a, krok 3);
#  nadpisanie: LOAD_SECONDS_VECTORS=… Bez ładowania bez wektorów: SKIP_NOVEC=1.
# ============================================================================
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 1
# shellcheck source=tools/lab/lib.sh
source tools/lab/lib.sh
command -v jq >/dev/null || die "brak jq"

IDX=products-lab-v1
NOVEC=products-lab-novec
SAMPLE=apps/catalog/storage/app/private/lab/products.ndjson
MAPPING=infra/elasticsearch/lab/products-lab-v1.json
BULK_CHUNK=500
FULL_DOCS=1171554
LOAD_SECONDS_VECTORS="${LOAD_SECONDS_VECTORS:-896}"   # pomiar z make lab-setup (przewodnik 14a, krok 3): pełne ładowanie z pipeline'em lab-embed i semantic_text

cleanup() { lab_es -o /dev/null -X DELETE "${LAB_URL}/${NOVEC}?ignore_unavailable=true" >/dev/null 2>&1 || true; }
trap cleanup EXIT

mb() { awk -v b="$1" 'BEGIN { printf "%.1f", b / 1048576 }'; }

[ "$(lab_es -o /dev/null -w '%{http_code}' "${LAB_URL}/${IDX}")" = 200 ] || die "brak indeksu ${IDX} na es-lab (make lab-mode-on, make lab-up, make lab-setup)"
DOCS="$(lab_es "${LAB_URL}/${IDX}/_count" | jq -r .count)"
echo "docs: ${DOCS}"

step "Rozmiar na dysku per pole (_disk_usage, run_expensive_tasks=true)"
USAGE="$(lab_es -X POST "${LAB_URL}/${IDX}/_disk_usage?run_expensive_tasks=true")"
field_bytes() { jq -r --arg p "$1" '.["'"${IDX}"'"].fields | to_entries | map(select(.key | test($p))) | map(.value.total_in_bytes) | add // 0' <<<"${USAGE}"; }
SEM="$(field_bytes '^semantic_e5(\.|$)')"
VF="$(field_bytes '^vec_float$')"
VI="$(field_bytes '^vec_int8$')"
VB="$(field_bytes '^vec_bbq$')"
NAME="$(field_bytes '^name(\.|$)')"
ALL="$(jq -r '.["'"${IDX}"'"].all_fields.total_in_bytes' <<<"${USAGE}")"
KNN_ALL="$(jq -r '.["'"${IDX}"'"].all_fields.knn_vectors_in_bytes' <<<"${USAGE}")"
STORE="$(lab_es "${LAB_URL}/_cat/indices/${IDX}?h=pri.store.size&bytes=b" | tr -d ' ')"
for kv in "semantic_e5_bytes:${SEM}" "vec_float_bytes:${VF}" "vec_int8_bytes:${VI}" "vec_bbq_bytes:${VB}" "name_bytes:${NAME}" "all_fields_bytes:${ALL}" "knn_vectors_bytes:${KNN_ALL}" "store_size_bytes:${STORE}"; do
  echo "${kv%%:*}: ${kv#*:}"
done
printf '\n%-14s %12s %10s\n' pole bajty MB
for kv in "semantic_e5:${SEM}" "vec_float:${VF}" "vec_int8:${VI}" "vec_bbq:${VB}" "name(+pola):${NAME}" "cały indeks:${ALL}"; do
  printf '%-14s %12s %10s\n' "${kv%%:*}" "${kv#*:}" "$(mb "${kv#*:}")"
done

step "Pamięć kNN raportowana przez ES 9.5"
lab_es "${LAB_URL}/${IDX}/_stats/dense_vector" | jq -r '._all.primaries.dense_vector | "dense_vector_value_count: \(.value_count)", (.off_heap | to_entries[] | "dense_vector_off_heap_\(.key): \(.value)")'
lab_es "${LAB_URL}/_nodes/stats/indices/segments" | jq -r '.nodes[].indices.segments | "nodes_stats_segments_count: \(.count)", "nodes_stats_segments_memory_in_bytes: \(.memory_in_bytes)"'
echo "uwaga: _nodes/stats/segments nie liczy wektorów (memory_in_bytes=0); pamięć kNN daje _stats/dense_vector (off_heap, liczona w stronach mmap, nie na stercie), a podział na pola — _disk_usage powyżej"

LOAD_NOVEC=""
if [ "${SKIP_NOVEC:-0}" != 1 ]; then
  step "Ładowanie próbki bez wektorów (tymczasowy ${NOVEC})"
  [ -f "${SAMPLE}" ] || die "brak ${SAMPLE}; uruchom make lab-export"
  cleanup
  jq 'del(.settings.index.default_pipeline) | del(.mappings.properties.semantic_e5, .mappings.properties.vec_float, .mappings.properties.vec_int8, .mappings.properties.vec_bbq) | del(.mappings.properties.lab_text.copy_to)' "${MAPPING}" \
    | lab_es -X PUT "${LAB_URL}/${NOVEC}" -H 'Content-Type: application/json' --data-binary @- | jq -c .
  work="$(mktemp -d)"
  trap 'rm -rf "${work}"; cleanup' EXIT
  tail -n +2 "${SAMPLE}" | jq -c '{index: {_id: .id}}, del(.id)' | split -l $((BULK_CHUNK * 2)) - "${work}/chunk."
  started="$(date +%s)"
  for f in "${work}"/chunk.*; do
    lab_es --max-time 600 -X POST "${LAB_URL}/${NOVEC}/_bulk?refresh=false" -H 'Content-Type: application/x-ndjson' --data-binary "@${f}" > "${work}/resp.json" || die "_bulk nie powiodło się"
    [ "$(jq -r .errors "${work}/resp.json")" = false ] || { jq -c '[.items[] | select(.index.error) | .index.error.reason][0:3]' "${work}/resp.json" >&2; die "_bulk zgłosił błędy"; }
  done
  LOAD_NOVEC=$(( $(date +%s) - started ))
  echo "load_seconds_novec: ${LOAD_NOVEC}"
  lab_es -X POST "${LAB_URL}/${NOVEC}/_refresh" >/dev/null
  lab_es --max-time 600 -X POST "${LAB_URL}/${NOVEC}/_forcemerge?max_num_segments=1" >/dev/null
  NOVEC_DOCS="$(lab_es "${LAB_URL}/${NOVEC}/_count" | jq -r .count)"
  [ "${NOVEC_DOCS}" = "${DOCS}" ] || die "${NOVEC}: ${NOVEC_DOCS} dokumentów, oczekiwano ${DOCS}"
  NOVEC_BYTES="$(lab_es "${LAB_URL}/_cat/indices/${NOVEC}?h=pri.store.size&bytes=b" | tr -d ' ')"
  echo "novec_store_size_bytes: ${NOVEC_BYTES}"
  rm -rf "${work}"
  cleanup
  trap cleanup EXIT
  echo "tymczasowy indeks ${NOVEC} usunięty"
fi
echo "load_seconds_vectors: ${LOAD_SECONDS_VECTORS} (pomiar z make lab-setup, pipeline lab-embed + semantic_text: każdy dokument liczony dwa razy)"

step "Podsumowanie"
printf '%-30s %10s\n' "pole / wielkość" "MB"
printf '%-30s %10s\n' "indeks z wektorami (store)" "$(mb "${STORE}")"
[ -n "${LOAD_NOVEC}" ] && printf '%-30s %10s\n' "indeks bez wektorów (store)" "$(mb "${NOVEC_BYTES}")"
printf '%-30s %10s\n' "vec_float / int8 / bbq" "$(mb "${VF}") / $(mb "${VI}") / $(mb "${VB}")"
printf '%-30s %10s\n' "semantic_e5 (wektory chunków)" "$(mb "${SEM}")"
if [ -n "${LOAD_NOVEC}" ]; then
  echo "load_ratio: $(awk -v a="${LOAD_SECONDS_VECTORS}" -v b="${LOAD_NOVEC}" 'BEGIN { printf "%.0f", a / (b > 0 ? b : 1) }')x (z wektorami / bez; czas bez wektorów ${LOAD_NOVEC} s ma rozdzielczość 1 s)"
fi

step "Ekstrapolacja liniowa na ${FULL_DOCS} dokumentów — RACHUNEK, NIE POMIAR"
scale() { awk -v v="$1" -v d="${DOCS}" -v f="${FULL_DOCS}" 'BEGIN { printf "%.2f", v * f / d / 1073741824 }'; }
echo "mnożnik: ${FULL_DOCS} / ${DOCS} = $(awk -v d="${DOCS}" -v f="${FULL_DOCS}" 'BEGIN { printf "%.1f", f / d }')"
printf '%-30s %10s\n' "pole" "GB"
printf '%-30s %10s\n' "vec_float" "$(scale "${VF}")"
printf '%-30s %10s\n' "vec_int8" "$(scale "${VI}")"
printf '%-30s %10s\n' "vec_bbq" "$(scale "${VB}")"
printf '%-30s %10s\n' "semantic_e5" "$(scale "${SEM}")"
printf '%-30s %10s\n' "cały indeks (z wektorami)" "$(scale "${STORE}")"
echo "extrapolated_load_seconds_vectors: $(awk -v s="${LOAD_SECONDS_VECTORS}" -v d="${DOCS}" -v f="${FULL_DOCS}" 'BEGIN { printf "%.0f", s * f / d }') (rachunek: ten sam sprzęt CPU, jeden węzeł, bez zrównoleglenia)"
echo "uwaga: wzrost nieliniowy (HNSW, scalanie segmentów, cache) pomija ten rachunek; to rząd wielkości"
