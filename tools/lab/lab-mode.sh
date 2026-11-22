#!/usr/bin/env bash
# ============================================================================
#  lab-mode.sh on|off — tryb lab (ETAP 14): Docker VM nie mieści naraz głównego klastra i es-lab
#  on : zatrzymuje WŁASNE usługi z listy poniżej, ale tylko te, które faktycznie działały (zapisuje je do pliku stanu);
#       zostają postgres, redis, rabbitmq, minio, catalog-app, catalog-vite, registry-mock
#  off: zatrzymuje es-lab i przywraca dokładnie to, co zapisał `on` (węzły ES pierwsze, potem czekanie na zdrowie, potem reszta);
#       błąd pojedynczej usługi jest zgłaszany, ale nie przerywa przywracania.
#       Bez pliku stanu: ostrzeżenie i start usług z listy, których kontenery istnieją (nie tworzy nowych).
#  Nie dotyka kontenerów innych projektów.
# ============================================================================
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.." || exit 1

step() { printf '\n━━ %s\n' "$*"; }
die()  { printf 'BŁĄD: %s\n' "$*" >&2; exit 1; }

ES_NODES=(es01 es02 es03)
# procesy zależne od głównego ES (kolejność startu; stop w odwrotnej)
STATE=apps/catalog/storage/app/private/lab/lab-mode.state   # katalog w .gitignore
DEPENDENTS=(kibana search-consumer analytics-consumer seller360-consumer seller360-scheduler seller360-registry search-metrics outbox-publisher catalog-scheduler catalog-worker)

free_mb() {
  local total used
  total=$(( $(docker info --format '{{.MemTotal}}') / 1024 / 1024 ))
  used=$(docker stats --no-stream --format '{{.MemUsage}}' | awk '{
    u=$1; sub(/^[0-9.]+/, "", u); v=$1; sub(/[A-Za-z]+$/, "", v)
    if (u=="GiB") v*=1024; else if (u=="KiB"||u=="kB") v/=1024; else if (u=="B") v/=1048576
    s+=v } END { printf "%d", s }')
  echo "  Docker: ${total} MB, użyte: ${used} MB, zapas: $(( total - used )) MB"
}

main_health() {
  local pw
  pw="$(grep -E '^ELASTIC_PASSWORD=' .env | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' || true)"
  [ -n "${pw}" ] || die "brak ELASTIC_PASSWORD w .env"
  curl -sS -K <(printf 'user = "elastic:%s"\n' "${pw}") "http://localhost:19200/_cluster/health?wait_for_status=yellow&timeout=1s" 2>/dev/null \
    | grep -oE '"status":"[a-z]+"' || true
}

in_list() { local x="$1"; shift; local y; for y in "$@"; do [ "${x}" = "${y}" ] && return 0; done; return 1; }

case "${1:-}" in
  on)
    [ -f .env ] || die "brak .env"
    running="$(docker compose ps --status running --services)"
    todo=()
    for ((i=${#DEPENDENTS[@]}-1; i>=0; i--)); do
      if grep -qx "${DEPENDENTS[i]}" <<<"${running}"; then todo+=("${DEPENDENTS[i]}"); fi
    done
    for n in "${ES_NODES[@]}"; do
      if grep -qx "${n}" <<<"${running}"; then todo+=("${n}"); fi
    done
    if [ "${#todo[@]}" -eq 0 ]; then
      echo "  nic z listy nie działa (tryb lab już włączony?); plik stanu zostaje bez zmian"
    else
      mkdir -p "$(dirname "${STATE}")"
      printf '%s\n' "${todo[@]}" > "${STATE}"
      step "Zatrzymuję (zapisane w ${STATE}): ${todo[*]}"
      docker compose stop "${todo[@]}"
    fi
    step "Pamięć po zatrzymaniu"
    free_mb
    ;;
  off)
    [ -f .env ] || die "brak .env"
    step "Zatrzymuję es-lab"
    docker compose --profile ml stop es-lab
    if [ -f "${STATE}" ]; then
      restore=()
      while IFS= read -r line; do [ -n "${line}" ] && restore+=("${line}"); done < "${STATE}"
    else
      echo "OSTRZEŻENIE: brak ${STATE}; przywracam usługi z listy, których kontenery istnieją" >&2
      existing="$(docker compose ps -a --services)"
      restore=()
      for n in "${ES_NODES[@]}" "${DEPENDENTS[@]}"; do
        if grep -qx "${n}" <<<"${existing}"; then restore+=("${n}"); fi
      done
    fi
    [ "${#restore[@]}" -gt 0 ] || die "nie ma czego przywracać"
    failed=()
    start_one() { docker compose start "$1" || { echo "BŁĄD: nie udało się uruchomić $1" >&2; failed+=("$1"); }; }
    es_started=0
    for n in "${ES_NODES[@]}"; do
      if in_list "${n}" "${restore[@]}"; then step "Start ${n}"; start_one "${n}"; es_started=1; fi
    done
    if [ "${es_started}" = 1 ]; then
      step "Czekanie na zdrowie głównego klastra (do 360 s)"
      ok=0
      for _ in $(seq 1 90); do
        case "$(main_health)" in *yellow*|*green*) ok=1; break;; esac
        sleep 4
      done
      [ "${ok}" = 1 ] || { echo "OSTRZEŻENIE: główny klaster nie osiągnął yellow w 360 s; startuję resztę mimo to" >&2; failed+=("main-cluster-health"); }
    fi
    for n in "${DEPENDENTS[@]}"; do
      if in_list "${n}" "${restore[@]}"; then step "Start ${n}"; start_one "${n}"; fi
    done
    rm -f "${STATE}"
    step "Stan końcowy"
    echo "  przywrócone: ${restore[*]}"
    echo "  główny klaster: $(main_health)"
    free_mb
    if [ "${#failed[@]}" -gt 0 ]; then die "problemy przy przywracaniu: ${failed[*]}"; fi
    ;;
  *) die "użycie: lab-mode.sh on|off" ;;
esac
