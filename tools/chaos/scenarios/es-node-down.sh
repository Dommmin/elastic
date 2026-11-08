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

# es_api <ścieżka> -> odpowiedź GET z ES (z poda ${ES_STS}-0). Hasło `elastic` idzie do curl
# przez stdin (-K -), nie trafia do argumentów ani na ekran. Kod ≠ 0 = brak odczytu.
es_api() {
  k -n "${NS}" get secret "${ES_CR}-es-elastic-user" -o json 2>/dev/null \
    | jq -r '"user = \"elastic:" + (.data.elastic | @base64d) + "\""' 2>/dev/null \
    | k -n "${NS}" exec -i "${ES_STS}-0" -c elasticsearch -- \
        curl -sf -K - "http://127.0.0.1:9200$1" 2>/dev/null
}

# es_lonely_shards -> powody odmowy (pusto = bezpiecznie): shardy w ruchu albo indeksy,
# których JEDYNA uruchomiona kopia shardu leży na węźle, który zniknie (STS skaluje w dół
# od najwyższego numeru). _cat/shards bez wzorca obejmuje też indeksy ukryte i systemowe.
# Pole node dla RELOCATING to „źródło -> ip id cel”: węzeł ofiary = pierwsze albo ostatnie
# słowo. Kod 2 = nie udało się odczytać (wtedy break odmawia).
es_lonely_shards() {
  local victim health shards moving
  victim="${ES_STS}-$(( $(es_count) - 1 ))"
  health="$(es_api '/_cluster/health?filter_path=relocating_shards,initializing_shards')" || return 2
  moving="$(jq -r 'if (.relocating_shards == 0 and .initializing_shards == 0) then "" else "shardy w ruchu (relocating \(.relocating_shards), initializing \(.initializing_shards))" end' <<<"${health}" 2>/dev/null)" || return 2
  [[ -n "${moving}" ]] && { echo "${moving}"; return 0; }
  shards="$(es_api '/_cat/shards?format=json&h=index,shard,prirep,state,node')" || return 2
  jq -r --arg v "${victim}" '
    def nodes: (.node // "" | split(" ") | map(select(length > 0))) as $w
      | if ($w | length) == 0 then [] else [$w[0], $w[-1]] end;
    group_by(.index + "/" + .shard)[]
    | select(any(.[]; nodes | index($v))
             and (any(.[]; .state == "STARTED" and ((nodes | index($v)) | not)) | not))
    | "jedyna kopia " + .[0].index + "/" + .[0].shard' <<<"${shards}" 2>/dev/null | sort -u || return 2
}

# es_guard — break odmawia (kod 1), gdy odczyt się nie udał albo strażnik coś znalazł.
es_guard() {
  local why rc
  why="$(es_lonely_shards)"; rc=$?
  [[ ${rc} -eq 0 ]] || { echo "nie odczytałem stanu shardów (_cluster/health, _cat/shards) — nie ruszam klastra" >&2; return 1; }
  [[ -z "${why}" ]] || { echo "odmowa (węzeł ${ES_STS}-$(( $(es_count) - 1 ))): $(echo "${why}" | tr '\n' ';' | sed 's/;$//')" >&2; return 1; }
}

# es_wait_yellow <sekundy> — 0 = yellow, 1 = red (od razu, bez czekania) albo limit czasu.
es_wait_yellow() {
  local deadline=$((SECONDS + $1)) h
  while :; do
    h="$(es_health)"
    [[ "${h}" == yellow ]] && return 0
    [[ "${h}" == red ]] && { echo "Elasticsearch RED po skalowaniu — natychmiastowe wycofanie" >&2; return 1; }
    (( SECONDS >= deadline )) && { echo "Elasticsearch nie przeszedł w yellow w $1 s" >&2; return 1; }
    sleep 2
  done
}

break() {
  [[ "$(sts_replicas)" == "$(es_count)" ]] || { echo "${ES_STS}: replicas ≠ count z CR, klaster nie jest w stanie wyjściowym" >&2; return 1; }
  # Bezpiecznik: węzeł, który zniknie, nie może mieć jedynej kopii shardu (indeks bez
  # repliki = red i niedziałające wyszukiwanie zamiast lekcji o replikach).
  es_guard || return 1
  k -n "${NS}" annotate elasticsearch "${ES_CR}" "${ES_PAUSE}=true" --overwrite >/dev/null || return 1
  # ECK potwierdza pauzę warunkiem OrchestrationPaused; bez niego skalowanie mogłoby wrócić.
  wait_for 60 es_paused || { echo "ECK nie potwierdził pauzy (warunek OrchestrationPaused) w 60 s" >&2; return 1; }
  # Drugi raz tuż przed skalowaniem: w czasie oczekiwania na pauzę ES mógł przenieść shard.
  es_guard || return 1
  k -n "${NS}" scale statefulset "${ES_STS}" --replicas=$(( $(es_count) - 1 )) >/dev/null || return 1
  # Red = strażnik coś przeoczył: kod 1 od razu, chaos.sh woła undo (scale + zdjęcie adnotacji).
  es_wait_yellow 300 || return 1
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
# Najpierw kroki zmieniające stan (sekundy): adnotacje i scale do count. Bez czekania na ECK:
# na klastrze po zdjęciu pauzy ECK nie przywrócił replicas sam w 60 s (managedFields: właścicielem
# spec.replicas jest kubectl), a scale do count z CR jest zgodny ze spec — ECK nie ma z czym walczyć.
# Potem weryfikacja: lokalnie (reset, abort_break) twarda, do 10 + 10 min. W Jobie
# (CHAOS_IN_CLUSTER=1) najwyżej ES_JOB_WAIT s i bez porażki: Job ma po przebudzeniu tylko
# ~600 s (activeDeadlineSeconds = ttl·60 + 600), a blokada ma zniknąć przed tym terminem.
# Klaster i tak dochodzi do green sam (pauza zdjęta, replicas = count); sprawdzi to check/preflight.
ES_JOB_WAIT=300
undo() {
  local a want
  for a in "${ES_PAUSE}" "${ES_MANAGED}"; do
    if [[ -n "$(es_ann "${a}")" ]]; then
      k -n "${NS}" annotate elasticsearch "${ES_CR}" "${a}-" >/dev/null || return 1
    fi
  done
  want="$(es_count)"
  if ! sts_back; then
    echo "  ${ES_STS}: replicas $(sts_replicas) -> ${want}"
    k -n "${NS}" scale statefulset "${ES_STS}" --replicas="${want}" >/dev/null || return 1
  fi
  if [[ "${CHAOS_IN_CLUSTER:-0}" == "1" ]]; then
    local end=$((SECONDS + ES_JOB_WAIT))
    wait_for "${ES_JOB_WAIT}" sts_full || true
    wait_for "$(( end > SECONDS ? end - SECONDS : 1 ))" es_whole \
      || echo "  uwaga: ES jeszcze nie green z ${want} węzłami (pauza zdjęta, replicas=${want}); dojdzie sam — make chaos-check s=es-node-down"
    return 0
  fi
  wait_for 600 sts_full || { echo "${ES_STS}: nie wszystkie pody gotowe po 10 min" >&2; return 1; }
  wait_for 600 es_whole || { echo "Elasticsearch nie wrócił do green z ${want} węzłami w 10 min" >&2; return 1; }
}
