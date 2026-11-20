#!/usr/bin/env bash
#  tools/es/test.sh — testy skryptów reindeksu BEZ klastra (CI): składnia, shellcheck,
#  parsowanie wyjść komend (fixtury), preflight na stubie aliasów, cele Make.
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "${ROOT}" || exit 1
FAIL=0
ok()  { printf '  ✓ %s\n' "$1"; }
bad() { printf '  ✗ %s\n' "$1"; FAIL=1; }
eq()  { [[ "$2" == "$3" ]] && ok "$1" || bad "$1: oczekiwano '$3', jest '$2'"; }

echo "es: test offline"
# shellcheck source=tools/es/lib.sh
. tools/es/lib.sh

# --- składnia ---------------------------------------------------------------
files="$(find tools/es -name '*.sh' | sort)"
for f in ${files}; do bash -n "${f}" 2>/dev/null && ok "bash -n ${f}" || bad "bash -n ${f}"; done
if command -v shellcheck >/dev/null 2>&1; then
  # shellcheck disable=SC2086
  shellcheck -S warning -x ${files} && ok "shellcheck -S warning" || bad "shellcheck -S warning"
else
  echo "  - pomijam shellcheck (brak binarki; CI go ma)"
fi

# --- kv_get: TARGET= / TASK= / GATE= / REPROJECT_NEEDED_SINCE= -------------
prep=$'[OK] products-v2 gotowy: 0 replik.\nTARGET=products-v2\n'
eq "TARGET=" "$(kv_get TARGET <<<"${prep}")" "products-v2"
eq "TASK=" "$(kv_get TASK <<<$'TASK=abc123:4567\npostęp: created=1')" "abc123:4567"
eq "GATE=PASS" "$(kv_get GATE <<<$'Bramka products-v2: zdrowie=green\nGATE=PASS')" "PASS"
eq "GATE=FAIL" "$(kv_get GATE <<<$'NIESPEŁNIONE: liczba\nGATE=FAIL')" "FAIL"
eq "REPROJECT_NEEDED_SINCE=" "$(kv_get REPROJECT_NEEDED_SINCE <<<$'x\nREPROJECT_NEEDED_SINCE=2026-11-19T10:00:00+00:00')" "2026-11-19T10:00:00+00:00"
eq "brak klucza = pusto" "$(kv_get TARGET <<<'nic')" ""
eq "klucz musi być na początku linii" "$(kv_get GATE <<<'xGATE=PASS')" ""

# --- NDCG: 1, 0.973, 1.0E-5 --------------------------------------------------
eq "NDCG=1" "$(ndcg_value <<<'NDCG=1')" "1.000000"
eq "NDCG=0.973" "$(ndcg_value <<<$'szum\nNDCG=0.973')" "0.973000"
eq "NDCG=1.0E-5" "$(ndcg_value <<<'NDCG=1.0E-5')" "0.000010"
ndcg_value <<<'NDCG=abc' >/dev/null 2>&1 && bad "NDCG=abc powinno odmówić" || ok "NDCG=abc odrzucone"
ndcg_value <<<'brak' >/dev/null 2>&1 && bad "brak NDCG powinien odmówić" || ok "brak NDCG odrzucony"
ndcg_regressed 0.973 0.95 && ok "0.973 → 0.95 = regresja" || bad "0.973 → 0.95 powinno być regresją"
ndcg_regressed 0.973 0.965 && bad "0.973 → 0.965 w tolerancji" || ok "0.973 → 0.965 w tolerancji"
ndcg_regressed 0.973 0.973 && bad "równe nie jest regresją" || ok "równe nie jest regresją"
ndcg_regressed 1.000000 0.000010 && ok "1 → 1.0E-5 = regresja" || bad "1 → 1.0E-5 powinno być regresją"
ndcg_regressed 0.5 0.99 && bad "poprawa nie jest regresją" || ok "poprawa nie jest regresją"

# --- zadania _reindex: rodzic tak, dziecko slice'a nie, cudzy cel nie --------
tasks='{"nodes":{"n1":{"tasks":{"n1:10":{"description":"reindex from [products-v1] to [products-v2][_doc]"},
 "n1:11":{"parent_task_id":"n1:10","description":"reindex from [products-v1] to [products-v2][_doc]"},
 "n1:12":{"description":"reindex from [products-v1] to [products-v3][_doc]"}}}}}'
eq "zadanie rodzic do celu" "$(running_reindex_ids products-v2 <<<"${tasks}")" "n1:10"
eq "brak zadań do innego celu" "$(running_reindex_ids products-v9 <<<"${tasks}")" ""
eq "puste nodes" "$(running_reindex_ids products-v2 <<<'{"nodes":{}}')" ""

# --- listing cleanup ---------------------------------------------------------
listing=$' * products-v1\n * products-v2\n\n [NOTE] Podgląd — nic nie usunięto.'
eq "kandydaci cleanup" "$(cleanup_candidates <<<"${listing}" | tr '\n' ' ')" "products-v1 products-v2 "

# --- preflight na stubie aliasów -------------------------------------------
preflight_index_free products-v1 products-v2 products-v2 2>/dev/null && ok "preflight: wolny indeks przechodzi" || bad "preflight: wolny indeks odrzucony"
preflight_index_free products-v2 products-v1 products-v2 2>/dev/null && bad "preflight: cel products-write przeszedł" || ok "preflight odrzuca cel products-write"
preflight_index_free products-v1 products-v1 products-v1 2>/dev/null && bad "preflight: cel products-search przeszedł" || ok "preflight odrzuca cel products-search"
preflight_index_free "" products-v1 2>/dev/null && bad "preflight: pusta nazwa przeszła" || ok "preflight odrzuca pustą nazwę"
# preflight_live na stubie alias_targets (bez ES)
alias_targets() { printf 'products-v1\nproducts-v2\n'; }
preflight_live products-v2 2>/dev/null && bad "preflight_live: aliasowany przeszedł" || ok "preflight_live odrzuca aliasowany"
preflight_live products-break-1 2>/dev/null && ok "preflight_live: products-break-1 wolny" || bad "preflight_live odrzucił wolny"

# alias_targets fail-closed: błąd odczytu któregokolwiek aliasu => odmowa
. tools/es/lib.sh   # przywraca prawdziwe alias_targets (wyżej stub)
alias_target() { if [[ "$1" == "${READ_ALIAS}" ]]; then return 1; else echo products-v2; fi; }
alias_targets 2>/dev/null && bad "alias_targets: błąd odczytu read przeszedł" || ok "alias_targets: błąd odczytu read => kod 1"
preflight_live products-v1 2>/dev/null && bad "preflight_live: przeszedł mimo błędu odczytu aliasu" || ok "preflight_live odmawia przy błędzie odczytu aliasu"
alias_target() { if [[ "$1" == "${READ_ALIAS}" ]]; then echo products-v1; else return 1; fi; }
alias_targets 2>/dev/null && bad "alias_targets: błąd odczytu write przeszedł" || ok "alias_targets: błąd odczytu write => kod 1"
alias_target() { if [[ "$1" == "${READ_ALIAS}" ]]; then echo products-v1; fi; }
alias_targets 2>/dev/null && bad "alias_targets: pusty write przeszedł" || ok "alias_targets: brak celu write => kod 1"
# alias_target na prawdziwej funkcji: 404 = pusto/0, błąd = 1, sukces = lista
. tools/es/lib.sh
es() { echo '{"error":{"type":"aliases_not_found_exception"},"status":404}'; return 22; }
out="$(alias_target x)"; rc=$?; eq "alias_target 404 => pusto, kod 0" "${rc}:${out}" "0:"
es() { echo 'Bad Gateway'; return 22; }
alias_target x >/dev/null 2>&1 && bad "alias_target: błąd transportu przeszedł" || ok "alias_target: błąd transportu => kod 1"
es() { echo '{"error":{"type":"x"},"status":503}'; return 22; }
alias_target x >/dev/null 2>&1 && bad "alias_target: 503 przeszedł" || ok "alias_target: 503 => kod 1"
es() { echo '{"products-v1":{"aliases":{"products-search":{}}}}'; }
eq "alias_target sukces" "$(alias_target x)" "products-v1"

# reindeks z celem jako ŹRÓDŁEM nie jest dopasowany (kotwica " to [cel]")
src='{"nodes":{"n1":{"tasks":{"n1:20":{"description":"reindex from [products-v2] to [products-v3][_doc]"}}}}}'
eq "cel jako źródło nie pasuje" "$(running_reindex_ids products-v2 <<<"${src}")" ""
eq "cel jako cel pasuje" "$(running_reindex_ids products-v3 <<<"${src}")" "n1:20"

# --- snapshoty / drille restore (ETAP 10, Task 11) ---------------------------
# shellcheck source=tools/es/restore-drill.sh
. tools/es/restore-drill.sh
# nazwa kopii: tylko products-drill-<cyfry> / products-restored-<cyfry> wolno kasować
drill_name_ok products-drill-1763600000 && ok "nazwa kopii drilla products-drill-<ts> dozwolona" || bad "poprawna nazwa kopii odrzucona"
drill_name_ok products-restored-1763600000 && ok "nazwa products-restored-<ts> dozwolona" || bad "poprawna nazwa restore odrzucona"
for n in products-v1 products-search products-write 'products-*' products-drill- products-drill-12x '' products-drill-1,products-v1; do
  drill_name_ok "${n}" 2>/dev/null && bad "drill_name_ok przepuścił '${n}'" || ok "drill_name_ok odrzuca '${n}'"
done
# drill niszczący odmawia indeksu pod aliasem (stub aliasów) i błędu odczytu aliasów
alias_targets() { printf 'products-drill-1\nproducts-v1\n'; }
drill_destroy_guard products-drill-1 2>/dev/null && bad "drill: indeks pod aliasem przeszedł" || ok "drill odmawia indeksu pod aliasem"
drill_destroy_guard products-drill-2 2>/dev/null && ok "drill: wolna kopia przechodzi" || bad "drill: wolna kopia odrzucona"
drill_destroy_guard products-v1 2>/dev/null && bad "drill: products-v1 przeszedł (zła nazwa + alias)" || ok "drill odmawia nazwy spoza wzorca"
alias_targets() { return 1; }
drill_destroy_guard products-drill-2 2>/dev/null && bad "drill: przeszedł mimo błędu aliasów" || ok "drill odmawia przy błędzie odczytu aliasów (fail-closed)"
. tools/es/lib.sh
# bezpośrednio: skrypt bez argumentów / ze złym repo odmawia, zanim dotknie klastra
bash tools/es/restore-drill.sh 2>/dev/null && bad "restore-drill bez argumentów przeszedł" || ok "restore-drill bez argumentów odmawia"
bash tools/es/restore-drill.sh normal --repo='zle repo' 2>/dev/null && bad "restore-drill: złe repo przeszło" || ok "restore-drill odrzuca złą nazwę repo"
# SLM: nazwa snapshotu z odpowiedzi _execute
eq "slm_snapshot_name" "$(slm_snapshot_name <<<'{"snapshot_name":"local-nightly-2026.11.20-abc"}')" "local-nightly-2026.11.20-abc"
slm_snapshot_name <<<'{"error":{}}' >/dev/null 2>&1 && bad "slm_snapshot_name przepuścił błąd" || ok "slm_snapshot_name odrzuca błąd"
# stan snapshotu z odpowiedzi GET _snapshot
eq "snapshot_state SUCCESS" "$(snapshot_state <<<'{"snapshots":[{"snapshot":"a","state":"SUCCESS"}]}')" "SUCCESS"
eq "snapshot_state brak" "$(snapshot_state <<<'{"snapshots":[]}')" ""

# --- compose: profil snapshots, bez kolizji portów --------------------------
cfg="$(docker compose --env-file .env.example --profile snapshots config 2>&1)"
if command -v docker >/dev/null 2>&1 && [[ -n "${cfg}" ]] && ! grep -q 'no configuration file' <<<"${cfg}"; then
  grep -qE '^  minio:' <<<"${cfg}" && grep -qE '^  minio-init:' <<<"${cfg}" && ok "compose: profil snapshots ma minio i minio-init" || bad "compose: brak minio/minio-init w profilu snapshots"
  grep -q 'minio/minio:latest' <<<"${cfg}" && bad "compose: MinIO na :latest" || ok "compose: brak :latest"
  grep -E 'published: "?(19000|19001)' <<<"${cfg}" >/dev/null && ok "compose: porty MinIO 19000/19001" || bad "compose: porty MinIO inne niż 19000/19001"
  docker compose --env-file .env.example config --services 2>/dev/null | grep -q '^minio$' && bad "compose: minio działa bez profilu" || ok "compose: minio tylko w profilu snapshots"
else
  echo "  - pomijam testy compose (brak dockera)"
fi
# obrazy MinIO/mc przypięte digestem (tagi RELEASE nie są już serwowane przez rejestr)
bad_img="$(grep -E '^\s+image:.*minio/(minio|mc)' compose.yaml | grep -v '@sha256:' || true)"
[[ -z "${bad_img}" ]] && ok "compose: obrazy MinIO/mc przypięte @sha256" || bad "compose: obraz MinIO/mc bez @sha256: ${bad_img}"
grep -q -- '"-products-drill-\*"' infra/elasticsearch/setup-snapshots.sh && ok "SLM wyklucza kopie drilli" || bad "SLM nie wyklucza products-drill-*/products-restored-*"
# klucze: w .env.example tylko zastępcze, nigdy zapisane na sztywno w compose
grep -nE '^(MINIO_ROOT_PASSWORD|ES_S3_SECRET_KEY)=' .env.example | grep -qE '=zmien-mnie' && ok ".env.example: hasła MinIO zastępcze" || bad ".env.example: hasła MinIO nie wyglądają na zastępcze"
grep -nE '^ +ES_S3_(ACCESS|SECRET)_KEY: *[^$ ]' compose.yaml && bad "compose: klucz S3 zapisany na sztywno" || ok "compose: klucze S3 tylko ze zmiennych"

# --- cele Make ---------------------------------------------------------------
for t in reindex reindex-abort reindex-cleanup reindex-break es-snapshots-setup es-restore-drill es-restore-drill-destructive es-slm-run; do
  grep -qE "^${t}:" Makefile && ok "Makefile: cel ${t}" || bad "Makefile: brak celu ${t}"
done
# Kolejność kroków run: nDCG starego indeksu przed switch-write (potem stary indeks
# nie dostaje zapisów i strażnik remisów fałszywie odmawia — Task 12 ETAPU 10).
l_eval_old="$(grep -n 'search:eval "--index=${old}"' tools/es/reindex.sh | head -1 | cut -d: -f1)"
l_switch_write="$(grep -n '^ *step switch-write ' tools/es/reindex.sh | head -1 | cut -d: -f1)"
[[ -n "${l_eval_old}" && -n "${l_switch_write}" && ${l_eval_old} -lt ${l_switch_write} ]] \
  && ok "nDCG starego indeksu przed switch-write" || bad "nDCG starego indeksu musi być przed switch-write"
grep -q 'sc search:index:migrate cleanup </dev/null' tools/es/reindex.sh \
  && grep -q 'preflight_live "${idx}" </dev/null' tools/es/reindex.sh \
  && ok "cleanup: podgląd i preflight nie zjadają stdin (echo tak | make reindex-cleanup)" || bad "cleanup: podgląd i preflight muszą mieć </dev/null"
# migration_source: źródło migracji z _meta.migration celu (wznowienie po Ctrl-C)
eq "migration_source z _meta" "$(migration_source <<<'{"products-v3":{"mappings":{"_meta":{"migration":{"source":"products-v2","switch_write_at":"x"}}}}}')" "products-v2"
eq "migration_source bez _meta" "$(migration_source <<<'{"products-v3":{"mappings":{}}}')" ""
migration_source <<<'nie json' >/dev/null 2>&1 && bad "migration_source: śmieci powinny odmówić" || ok "migration_source: śmieci odrzucone (fail-closed)"
migration_source <<<'{"error":{"type":"x"},"status":500}' >/dev/null 2>&1 && bad "migration_source: błąd ES powinien odmówić" || ok "migration_source: błąd ES odrzucony"
# resume_decision STARY ZAPIS <_mapping celu zapisu — decyzja run przy read≠write
m_ok='{"products-v3":{"mappings":{"_meta":{"migration":{"source":"products-v2"}}}}}'
m_other='{"products-v3":{"mappings":{"_meta":{"migration":{"source":"products-v1"}}}}}'
eq "resume_decision: ten sam indeks = fresh" "$(resume_decision products-v2 products-v2 </dev/null)" "fresh"
eq "resume_decision: cel z prepare = resume" "$(resume_decision products-v2 products-v3 <<<"${m_ok}")" "resume"
out="$(resume_decision products-v2 products-v3 <<<"${m_other}")" && bad "resume_decision: obce źródło powinno odmówić" \
  || { grep -q 'nie jest celem migracji z products-v2' <<<"${out}" && ok "resume_decision: obce źródło = odmowa z powodem" || bad "resume_decision: brak powodu odmowy ('${out}')"; }
resume_decision products-v2 products-v3 <<<'{"products-v3":{"mappings":{}}}' >/dev/null && bad "resume_decision: cel bez _meta powinien odmówić" || ok "resume_decision: cel bez _meta = odmowa"
resume_decision products-v2 products-v3 <<<'nie json' >/dev/null && bad "resume_decision: śmieci powinny odmówić" || ok "resume_decision: śmieci = odmowa (fail-closed)"
resume_decision products-v2 products-v3 <<<'{"error":{"type":"x"},"status":500}' >/dev/null && bad "resume_decision: błąd ES powinien odmówić" || ok "resume_decision: błąd ES = odmowa"
grep -q 'resume_decision "${old}" "${write}"' tools/es/reindex.sh && ok "run używa resume_decision" || bad "run musi używać resume_decision"
# eval_frozen_ok KOD — na zamrożonym starym indeksie tolerujemy tylko 0 i remis (2)
eval_frozen_ok 0 && ok "eval_frozen_ok 0" || bad "eval_frozen_ok 0 powinno przejść"
eval_frozen_ok 2 && ok "eval_frozen_ok 2 (TIE_UNSTABLE)" || bad "eval_frozen_ok 2 powinno przejść"
for rc in 1 3 127 130 255; do eval_frozen_ok "${rc}" && bad "eval_frozen_ok ${rc} powinno odmówić" || ok "eval_frozen_ok ${rc} = awaria"; done
grep -q 'search:eval "--index=${old}" || true' tools/es/reindex.sh && bad "resume nie może połykać błędów eval (|| true)" || ok "resume nie połyka błędów eval"
# --- Task 12b: długie kroki przez `compose run`, abort ograniczony do okna ---
# `docker compose exec` ginie (137) przy godzinnym restarcie search-consumer
# (--time-limit=3600); `compose run --rm --no-deps` to osobny kontener.
grep -qE '^scr\(\) \{ \$\{DC\} run --rm --no-deps -T search-consumer php bin/console "\$@" </dev/null; \}' tools/es/lib.sh \
  && ok "lib: scr = compose run --rm --no-deps -T search-consumer, stdin z /dev/null" || bad "lib: brak scr (compose run --rm --no-deps … </dev/null)"
for pat in 'step reindex scr search:index:migrate reindex --wait' 'step backfill scr search:index:backfill' \
           'step finalize scr search:index:migrate finalize' 'step gate scr search:index:gate' 'step switch-read scr search:index:migrate switch-read'; do
  grep -qF "${pat}" tools/es/reindex.sh && ok "run: ${pat%% search:*} … przez compose run" || bad "run: brak '${pat}'"
done
grep -qE '(^|[^r])sc search:index:(backfill|gate)|sc search:index:migrate (reindex|finalize|switch-read)|sc search:reproject' tools/es/reindex.sh \
  && bad "długi krok nadal przez exec (sc)" || ok "żaden długi krok nie idzie przez exec"
grep -q 'search:reproject' tools/es/reindex.sh && bad "abort: zostały kroki ruling A (reproject z porzuconego)" || ok "abort: bez pełnego reproject z porzuconego indeksu"
# since_minus ISO SEKUNDY — początek okna nadrabiania (switch_write_at − margines)
eq "since_minus Z" "$(since_minus 2026-11-20T00:40:06Z 300)" "2026-11-20T00:35:06+00:00"
eq "since_minus +00:00 przez północ" "$(since_minus 2026-11-20T00:02:00+00:00 300)" "2026-11-19T23:57:00+00:00"
eq "since_minus margines 0" "$(since_minus 2026-11-20T00:40:06+00:00 0)" "2026-11-20T00:40:06+00:00"
since_minus 'wczoraj' 300 >/dev/null 2>&1 && bad "since_minus: śmieci powinny odmówić" || ok "since_minus: śmieci odrzucone"
since_minus 2026-11-20T00:40:06Z abc >/dev/null 2>&1 && bad "since_minus: margines nieliczbowy powinien odmówić" || ok "since_minus: zły margines odrzucony"
# migration_switch_write_at <_mapping — switch_write_at porzuconego indeksu
eq "switch_write_at z _meta" "$(migration_switch_write_at <<<'{"products-v3":{"mappings":{"_meta":{"migration":{"source":"products-v2","switch_write_at":"2026-11-20T00:40:06+00:00"}}}}}')" "2026-11-20T00:40:06+00:00"
eq "switch_write_at brak" "$(migration_switch_write_at <<<'{"products-v3":{"mappings":{}}}')" ""
migration_switch_write_at <<<'nie json' >/dev/null 2>&1 && bad "migration_switch_write_at: śmieci powinny odmówić" || ok "migration_switch_write_at: śmieci odrzucone"

# abort end-to-end na stubie `docker compose` (DC): loguje wywołania, odpowiada fixturami.
stub_dir="$(mktemp -d)"
cat >"${stub_dir}/dc" <<'STUB'
#!/usr/bin/env bash
printf '%s\n' "$*" >>"${STUB_LOG}"
case "$*" in
  *"sh -c"*" GET /_alias/products-write"*) echo "{\"${STUB_WRITE}\":{\"aliases\":{\"products-write\":{}}}}" ;;
  *"sh -c"*" GET /_alias/products-search"*) echo "{\"${STUB_WRITE}\":{\"aliases\":{\"products-search\":{}}}}" ;;
  *"sh -c"*" GET /products-v9/_mapping"*) echo '{"products-v9":{"mappings":{"_meta":{}}}}' ;;
  *"sh -c"*" GET /products-v3/_mapping"*) echo '{"products-v3":{"mappings":{"_meta":{"migration":{"source":"products-v2","switch_write_at":"2026-11-20T00:40:06+00:00"}}}}}' ;;
  *"sh -c"*" GET /_tasks"*) echo '{"nodes":{}}' ;;
  *"search:index:migrate abort"*) echo 'REPROJECT_NEEDED_SINCE=2026-11-20T00:40:06+00:00' ;;
  *"rabbitmq rabbitmqctl list_queues"*) printf 'search.product.sync.dlq\t2\nsearch.product.sync\t4321\n' ;;
  *) echo "ok" ;;
esac
STUB
chmod +x "${stub_dir}/dc"
STUB_LOG="${stub_dir}/log" STUB_WRITE=products-v3 DC="${stub_dir}/dc" bash tools/es/reindex.sh abort >"${stub_dir}/out" 2>&1; rc=$?
eq "abort (stub): kod 0" "${rc}" "0"
grep -qF 'run --rm --no-deps -T search-consumer php bin/console search:index:backfill --index=products-write --since=2026-11-20T00:35:06+00:00' "${stub_dir}/log" \
  && ok "abort: nadrabia --since=switch_write_at − 300 s przez compose run" || bad "abort: brak backfill --since z marginesem 300 s: $(cat "${stub_dir}/log")"
grep -q 'search:index:migrate abort' "${stub_dir}/log" && ok "abort: woła migrate abort" || bad "abort: nie wołał migrate abort"
grep -q 'kolejka search.product.sync: 4321' "${stub_dir}/out" && ok "abort: pokazuje głębokość kolejki (podpowiedź do margin=)" || bad "abort: brak głębokości kolejki: $(cat "${stub_dir}/out")"
grep -q 'rabbitmqadmin' tools/es/reindex.sh tools/es/lib.sh && bad "rabbitmqadmin get konsumuje wiadomości — tylko rabbitmqctl list_queues" || ok "kolejka czytana bez konsumowania (rabbitmqctl list_queues)"
grep -q 'bez outboxu' "${stub_dir}/out" && ok "abort: ostrzega, że zapisy bez outboxu (np. ręczny _update_by_query) nie są nadrabiane" || bad "abort: brak ostrzeżenia o zapisach bez outboxu"
grep -E 'search:index:backfill' "${stub_dir}/log" | grep -qv -- '--since=' && bad "abort: pełny backfill bez --since" || ok "abort: brak pełnego backfillu"
: >"${stub_dir}/log"
STUB_LOG="${stub_dir}/log" STUB_WRITE=products-v3 DC="${stub_dir}/dc" bash tools/es/reindex.sh abort --margin=60 >"${stub_dir}/out" 2>&1
grep -qF -- '--since=2026-11-20T00:39:06+00:00' "${stub_dir}/log" && ok "abort --margin=60" || bad "abort: margines 60 nieuwzględniony"
# ponowienie samego nadrabiania po udanym abort: aliasy już na v2, okno z _meta porzuconego v3
: >"${stub_dir}/log"
STUB_LOG="${stub_dir}/log" STUB_WRITE=products-v2 DC="${stub_dir}/dc" bash tools/es/reindex.sh abort --from=products-v3 >"${stub_dir}/out" 2>&1; rc=$?
eq "abort --from (stub): kod 0" "${rc}" "0"
grep -q 'search:index:migrate abort' "${stub_dir}/log" && bad "abort --from: nie powinien drugi raz przepinać aliasów" || ok "abort --from: aliasów nie rusza"
grep -qF -- 'search:index:backfill --index=products-write --since=2026-11-20T00:35:06+00:00' "${stub_dir}/log" && ok "abort --from: nadrabia z _meta porzuconego indeksu" || bad "abort --from: brak nadrabiania: $(cat "${stub_dir}/log")"
STUB_LOG="${stub_dir}/log" STUB_WRITE=products-v3 DC="${stub_dir}/dc" bash tools/es/reindex.sh abort --from=products-v3 >/dev/null 2>&1 \
  && bad "abort --from: przeszedł, choć porzucony indeks jest pod aliasem" || ok "abort --from odmawia indeksu pod aliasem"
STUB_LOG="${stub_dir}/log" STUB_WRITE=products-v3 DC="${stub_dir}/dc" bash tools/es/reindex.sh abort --margin=x >/dev/null 2>&1 \
  && bad "abort --margin=x przeszedł" || ok "abort odrzuca nieliczbowy margines"
# brak switch_write_at w _meta porzuconego indeksu = odmowa PRZED cancel/abort (aliasy nietknięte)
: >"${stub_dir}/log"
STUB_LOG="${stub_dir}/log" STUB_WRITE=products-v9 DC="${stub_dir}/dc" bash tools/es/reindex.sh abort >/dev/null 2>&1 \
  && bad "abort bez switch_write_at przeszedł" || ok "abort bez switch_write_at odmawia"
grep -q 'search:index:migrate abort' "${stub_dir}/log" && bad "abort bez switch_write_at przepiął aliasy" || ok "abort bez switch_write_at nie rusza aliasów"
grep -q 'REPROJECT_NEEDED_SINCE' tools/es/reindex.sh && bad "reindex.sh: martwy fallback REPROJECT_NEEDED_SINCE" || ok "reindex.sh: okno tylko z _meta (bez martwego fallbacku)"
rm -rf "${stub_dir}"
grep -qE '^reindex-abort:.*' Makefile && grep -A2 -E '^reindex-abort:' Makefile | grep -q -- '--margin=$(or $(margin),300)' \
  && ok "Makefile: reindex-abort margin= (domyślnie 300)" || bad "Makefile: reindex-abort bez margin="
grep -A2 -E '^reindex-abort:' Makefile | grep -q -- '--from=' && ok "Makefile: reindex-abort from=" || bad "Makefile: reindex-abort bez from="

# Skrypt nie może wypisywać danych dostępowych.
grep -nE 'echo[^|]*(PASSWORD|ELASTICSEARCH_USER)|printf[^|]*(PASSWORD|ELASTICSEARCH_USER)' tools/es/*.sh | grep -v '^tools/es/test.sh' \
  && bad "skrypt wypisuje dane dostępowe" || ok "brak wypisywania danych dostępowych"

echo
[[ ${FAIL} -eq 0 ]] && echo "es test: PASS" || echo "es test: FAIL"
exit "${FAIL}"
