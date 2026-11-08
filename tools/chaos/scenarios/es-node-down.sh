# shellcheck shell=bash
# shellcheck disable=SC2034  # SUMMARY/ALERT/LIMIT_MIN czyta chaos.sh
#  es-node-down — operator ECK wstrzymany adnotacją, StatefulSet Elasticsearcha 3 -> 2.
#
#  `eck.k8s.elastic.co/pause-orchestration: "true"` (ECK 3.5; `managed=false` jest
#  deprecated) zatrzymuje zmiany sterowane spec, więc ECK nie przywraca `count: 3`.
#  Znika pod o najwyższym numerze, jego PVC zostaje. Klaster ma kworum (2 z 3 węzłów
#  master). Hook preStop ECK zgłasza węzeł jako restart (`_nodes/shutdown`, 5 min opóźnienia):
#  repliki z utraconego węzła czekają nieprzydzielone (`yellow`), po 5 min trafiają na dwa
#  pozostałe (`green` na 2 węzłach). Wyszukiwarka działa, o ile węzeł nie miał jedynej
#  kopii shardu (products-v1 ma 0 replik) — break to sprawdza i wtedy odmawia.
#  Nigdy nie kasujemy podów ani PVC i nie dotykamy indeksów.
#
#  Bez targets(): snapshot_restore robi PUT bez resourceVersion, a CR Elasticsearcha
#  (i STS, który ECK na bieżąco zmienia) go nie przyjmą. Stan odtwarzamy jawnie w undo.

SUMMARY="Elasticsearch widzi 2 węzły zamiast 3 i nie kończy ApplyingChanges, a wyszukiwarka działa"
# Pusty: KubeStatefulSetReplicasMismatch porównuje gotowe repliki STS z jego spec.replicas
# (obie = 2), a kube-state-metrics nie zna zasobu Elasticsearch. Sprawdzone na klastrze:
# wyrażenie reguły bez wyniku przez cały czas awarii.
ALERT=""
LIMIT_MIN=10

ES_CR="marketplace"
ES_STS="marketplace-es-default"
ES_NODESET="default"
ES_PAUSE="eck.k8s.elastic.co/pause-orchestration"
ES_MANAGED="eck.k8s.elastic.co/managed"
ES_PODS="elasticsearch.k8s.elastic.co/cluster-name=${ES_CR}"

targets() { :; }

es_health() { k -n "${NS}" get elasticsearch "${ES_CR}" -o jsonpath='{.status.health}' 2>/dev/null; }
es_ann() { # es_ann <klucz> -> wartość adnotacji (pusta, gdy brak)
  k -n "${NS}" get elasticsearch "${ES_CR}" -o json 2>/dev/null | jq -r --arg a "$1" '.metadata.annotations[$a] // empty'
}
sts_replicas() { k -n "${NS}" get statefulset "${ES_STS}" -o jsonpath='{.spec.replicas}' 2>/dev/null; }
# Docelowa liczba węzłów: count nodeSetu z CR (spec się nie zmienia), awaryjnie 3.
es_count() {
  local c
  c="$(k -n "${NS}" get elasticsearch "${ES_CR}" -o json 2>/dev/null \
    | jq -r --arg n "${ES_NODESET}" '.spec.nodeSets[] | select(.name == $n) | .count // empty')"
  [[ "${c}" =~ ^[0-9]+$ ]] && echo "${c}" || echo 3
}
es_paused() {
  k -n "${NS}" get elasticsearch "${ES_CR}" -o json 2>/dev/null | jq -e '
    any(.status.conditions[]?; .type == "OrchestrationPaused" and .status == "True")' >/dev/null
}
es_nodes() { k -n "${NS}" get elasticsearch "${ES_CR}" -o jsonpath='{.status.availableNodes}' 2>/dev/null; }
es_yellow() { [[ "$(es_health)" == yellow ]]; }
# Green to za mało: po ~5 min bez węzła ES przydziela repliki na pozostałe węzły i klaster
# wraca do green z 2 węzłami. Zdrowy = green i ECK widzi wszystkie węzły.
es_whole() { [[ "$(es_health)" == green && "$(es_nodes)" == "$(es_count)" ]]; }
sts_back() { [[ "$(sts_replicas)" == "$(es_count)" ]]; }
sts_full() { sts_back && [[ "$(sts_ready "${ES_STS}")" == tak ]]; }

# Gotowe pody ES (check działa lokalnie, z pełnym kubeconfigiem; Job nie ma `list` podów).
es_ready_pods() {
  k -n "${NS}" get pods -l "${ES_PODS}" -o json 2>/dev/null | jq '
    [.items[] | select(.metadata.deletionTimestamp == null)
      | select(any(.status.conditions[]?; .type == "Ready" and .status == "True"))] | length' 2>/dev/null || echo 0
}

# es_lonely_shards -> indeksy, których JEDYNA uruchomiona kopia shardu leży na węźle, który
# zniknie (STS skaluje w dół od najwyższego numeru). Odczyt _cat/shards z poda ${ES_STS}-0:
# hasło `elastic` idzie do curl przez stdin (-K -), nie trafia do argumentów ani na ekran.
# Kod 2 = nie udało się odczytać (wtedy break odmawia).
es_lonely_shards() {
  local victim out
  victim="${ES_STS}-$(( $(es_count) - 1 ))"
  out="$(k -n "${NS}" get secret "${ES_CR}-es-elastic-user" -o json 2>/dev/null \
    | jq -r '"user = \"elastic:" + (.data.elastic | @base64d) + "\""' 2>/dev/null \
    | k -n "${NS}" exec -i "${ES_STS}-0" -c elasticsearch -- \
        curl -sf -K - 'http://127.0.0.1:9200/_cat/shards?format=json&h=index,shard,prirep,state,node' 2>/dev/null)" || return 2
  jq -r --arg v "${victim}" '
    group_by(.index + "/" + .shard)[]
    | select(any(.[]; .node == $v) and (any(.[]; .state == "STARTED" and .node != $v) | not))
    | .[0].index' <<<"${out}" 2>/dev/null | sort -u || return 2
}

break() {
  [[ "$(sts_replicas)" == "$(es_count)" ]] || { echo "${ES_STS}: replicas ≠ count z CR, klaster nie jest w stanie wyjściowym" >&2; return 1; }
  # Bezpiecznik: węzeł, który zniknie, nie może mieć jedynej kopii shardu (indeks bez
  # repliki = red i niedziałające wyszukiwanie zamiast lekcji o replikach).
  local lonely rc
  lonely="$(es_lonely_shards)"; rc=$?
  [[ ${rc} -eq 0 ]] || { echo "nie odczytałem rozkładu shardów (_cat/shards) — nie ruszam klastra" >&2; return 1; }
  [[ -z "${lonely}" ]] || { echo "odmowa: jedyna kopia shardów na ${ES_STS}-$(( $(es_count) - 1 )): $(echo "${lonely}" | tr '\n' ' ')" >&2; return 1; }
  k -n "${NS}" annotate elasticsearch "${ES_CR}" "${ES_PAUSE}=true" --overwrite >/dev/null || return 1
  # ECK potwierdza pauzę warunkiem OrchestrationPaused; bez niego skalowanie mogłoby wrócić.
  wait_for 60 es_paused || { echo "ECK nie potwierdził pauzy (warunek OrchestrationPaused) w 60 s" >&2; return 1; }
  k -n "${NS}" scale statefulset "${ES_STS}" --replicas=$(( $(es_count) - 1 )) >/dev/null || return 1
  wait_for 300 es_yellow || { echo "Elasticsearch nie przeszedł w yellow w 5 min" >&2; return 1; }
  [[ "$(sts_replicas)" == "$(( $(es_count) - 1 ))" ]] || { echo "${ES_STS}: ktoś przywrócił replicas mimo pauzy" >&2; return 1; }
}

check() {
  wait_for 180 es_whole || true
  local want; want="$(es_count)"
  expect "${want}/${want} pody Elasticsearcha gotowe" "${want}" "$(es_ready_pods)"
  expect "Elasticsearch ${ES_CR}: ${want} węzły dostępne (availableNodes)" "${want}" "$(es_nodes)"
  expect "Elasticsearch ${ES_CR}: health green" "green" "$(es_health)"
  local p m
  p="$(es_ann "${ES_PAUSE}")"; m="$(es_ann "${ES_MANAGED}")"
  expect "ECK zarządza klastrem (${ES_PAUSE}: ${p:-brak}, ${ES_MANAGED}: ${m:-brak})" "tak" \
    "$([[ ( -z "${p}" || "${p}" == false ) && ( -z "${m}" || "${m}" == true ) ]] && echo tak || echo nie)"
}

# Idempotentne: na zdrowym klastrze (bez adnotacji, STS = count, green) tylko odczyty.
undo() {
  local a
  for a in "${ES_PAUSE}" "${ES_MANAGED}"; do
    if [[ -n "$(es_ann "${a}")" ]]; then
      k -n "${NS}" annotate elasticsearch "${ES_CR}" "${a}-" >/dev/null || return 1
    fi
  done
  # Po zdjęciu pauzy ECK sam wraca do count; po 60 s skalujemy sami.
  if ! sts_back; then
    if wait_for 60 sts_back; then
      echo "  ${ES_STS}: ECK przywrócił replicas=$(es_count)"
    else
      echo "  ${ES_STS}: po 60 s nadal $(sts_replicas) — scale --replicas=$(es_count)"
      k -n "${NS}" scale statefulset "${ES_STS}" --replicas="$(es_count)" >/dev/null || return 1
    fi
  fi
  wait_for 600 sts_full || { echo "${ES_STS}: nie wszystkie pody gotowe po 10 min" >&2; return 1; }
  wait_for 600 es_whole || { echo "Elasticsearch nie wrócił do green z $(es_count) węzłami w 10 min" >&2; return 1; }
}
